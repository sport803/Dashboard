<?php
if (!defined('ABSPATH')) { exit; }

final class S803_Autopilot_Engine {
    const MANAGED = '_s803_managed';
    const EVENT_KEY = '_s803_event_key';
    const META_PREFIX = '_s803_';
    const HIGHLIGHT_STATE = '_s803_highlight_status';
    const HIGHLIGHT_ATTEMPTS = '_s803_highlight_attempts';
    const HIGHLIGHT_RETRY_AT = '_s803_highlight_retry_at';
    const HIGHLIGHT_STARTED_AT = '_s803_highlight_started_at';
    const SCORE_BEGIN = '<!-- S803_SCORE_BEGIN -->';
    const SCORE_END = '<!-- S803_SCORE_END -->';
    const HIGHLIGHTS_BEGIN = '<!-- S803_HIGHLIGHTS_BEGIN -->';
    const HIGHLIGHTS_END = '<!-- S803_HIGHLIGHTS_END -->';

    public static function run($settings) {
        if (get_transient('s803_autopilot_lock')) {
            return new WP_Error('s803_busy', __('Another autopilot run is already active.', 'sports803-dashboard'));
        }
        set_transient('s803_autopilot_lock', 1, 600);
        $started = time();
        $result = array('ok' => true, 'discovered' => 0, 'created' => 0, 'updated' => 0, 'drafted' => 0, 'score_checks' => 0, 'score_updates' => 0, 'highlights_completed' => 0, 'highlight_retries' => 0, 'needs_review' => 0, 'errors' => 0);
        self::log('info', 0, 'Autopilot run started.');
        try {
            $discovery = S803_ESPN_Provider::discover($settings);
            $result['discovered'] = count($discovery['events']);
            foreach ($discovery['errors'] as $message) {
                $result['errors']++;
                self::log('warning', 0, 'Event feed: ' . $message);
            }

            $oneball_feed = array();
            if (!empty($settings['oneball_provider'])) {
                $oneball_feed = S803_OneBall_Provider::load_feed();
                if (is_wp_error($oneball_feed)) {
                    $result['errors']++;
                    self::log('warning', 0, 'OneBall provider feed unavailable: ' . $oneball_feed->get_error_message());
                }
            }

            foreach ($discovery['events'] as $event) {
                if (!empty($event['home']['name']) && !empty($event['away']['name']) && is_array($oneball_feed)) {
                    $live = S803_OneBall_Provider::find_match($event, $oneball_feed);
                    if ($live) {
                        $event['oneball_id'] = $live['id'];
                        $event['live_player_url'] = S803_OneBall_Provider::live_player_url($live['id']);
                    }
                }
                if (empty($event['oneball_id'])) {
                    $existing_post = self::find_event_post($event['key']);
                    if ($existing_post) {
                        $saved_id = get_post_meta($existing_post->ID, '_s803_oneball_id', true);
                        if ($saved_id !== '') {
                            $event['oneball_id'] = (string) $saved_id;
                            if (self::status_kind($event['status'], $event['state'], $event['completed']) !== 'final') {
                                $event['live_player_url'] = S803_OneBall_Provider::live_player_url($saved_id);
                            }
                        }
                    }
                }
                try {
                    $change = self::upsert_event($event, $settings);
                    if ($change === 'created') { $result['created']++; }
                    elseif ($change === 'updated') { $result['updated']++; }
                    elseif ($change === 'drafted') { $result['drafted']++; }
                } catch (Throwable $error) {
                    $result['errors']++;
                    self::log('error', 0, 'Could not process ' . $event['key'] . ': ' . $error->getMessage());
                }
            }

            $posts = self::managed_posts();
            self::recover_stale_jobs($posts, $settings);
            $score_limit = max(1, min(50, absint($settings['jobs_per_run'])));
            $highlight_limit = max(1, min(50, absint($settings['jobs_per_run'])));
            $highlight_jobs = 0;
            foreach ($posts as $post) {
                $event = self::event_from_post($post->ID);
                if (!$event || in_array($event['lifecycle'], array('cancelled', 'postponed'), true)) { continue; }
                if (!empty($settings['live_score_sync']) && $event['type'] === 'match' && $result['score_checks'] < $score_limit && self::should_check_score($event)) {
                    $result['score_checks']++;
                    $score = S803_ESPN_Provider::fetch_summary($event);
                    if (is_wp_error($score)) {
                        update_post_meta($post->ID, '_s803_score_last_error', substr($score->get_error_message(), 0, 400));
                        $result['errors']++;
                        self::log('warning', $post->ID, 'ESPN summary request failed: ' . $score->get_error_message());
                    } else {
                        $snapshot = S803_ESPN_Provider::summary_snapshot($score);
                        $state = self::status_kind($snapshot['status'], $snapshot['state'], $snapshot['completed']);
                        if (in_array($state, array('cancelled', 'postponed'), true)) {
                            self::draft_event($post->ID, $state, $snapshot['status']);
                            $result['drafted']++;
                            continue;
                        }
                        if ($state === 'live' || $state === 'final') {
                            if (self::sync_score($post->ID, $event, $snapshot, $state)) { $result['score_updates']++; }
                            if ($state === 'final') { self::queue_highlights($post->ID); }
                        }
                    }
                }
                if (!empty($settings['auto_highlights']) && $event['type'] === 'match' && self::is_final_event($post->ID, $event)) {
                    self::queue_highlights($post->ID);
                    $job_state = get_post_meta($post->ID, self::HIGHLIGHT_STATE, true);
                    if (in_array($job_state, array('queued', 'retry'), true) && self::retry_is_due($post->ID) && $highlight_jobs < $highlight_limit) {
                        $highlight_jobs++;
                        if (self::process_highlight_job($post->ID, $event, $settings, $oneball_feed)) { $result['highlights_completed']++; }
                        else {
                            $new_state = get_post_meta($post->ID, self::HIGHLIGHT_STATE, true);
                            if ($new_state === 'needs_review') { $result['needs_review']++; }
                            else { $result['highlight_retries']++; }
                        }
                    }
                }
            }
            $result['finished_at'] = gmdate('c');
            $result['duration_seconds'] = max(0, time() - $started);
            $result['ok'] = $result['errors'] === 0;
            update_option(S803_Dashboard_Plugin::LAST_RUN, $result, false);
            self::log('info', 0, sprintf('Run finished: %d discovered, %d created, %d updated, %d score updates, %d highlights completed.', $result['discovered'], $result['created'], $result['updated'], $result['score_updates'], $result['highlights_completed']));
            return $result;
        } finally {
            delete_transient('s803_autopilot_lock');
        }
    }

    public static function status_kind($description, $state = '', $completed = false) {
        $description = strtolower((string) $description);
        $state = strtolower((string) $state);
        if (preg_match('/cancelled|canceled|abandoned/', $description)) { return 'cancelled'; }
        if (preg_match('/postponed/', $description)) { return 'postponed'; }
        if ($completed || $state === 'post' || preg_match('/final|full.?time|finished|completed|ended/', $description)) { return 'final'; }
        if ($state === 'in' || preg_match('/in progress|live|1st half|2nd half|quarter|period|halftime|half time|overtime|extra time/', $description)) { return 'live'; }
        return 'scheduled';
    }

    public static function retry_delay_minutes($attempt) {
        $attempt = max(1, min(10, (int) $attempt));
        return min(240, 5 * (2 ** ($attempt - 1)));
    }

    public static function score_banner_html($home, $away, $home_score, $away_score, $clock, $state) {
        $title = $state === 'final' ? 'FULL TIME' : 'LIVE';
        $escape = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $score = trim((string) $home_score) . ' – ' . trim((string) $away_score);
        $clock_html = trim((string) $clock) !== '' ? '<span class="s803-score-clock">' . $escape($clock) . '</span>' : '';
        $class = $state === 'final' ? 'full-time' : 'live';
        return '<div class="s803-score-banner s803-score-' . $class . '" data-s803-score-banner="1"><strong class="s803-score-status">' . $title . '</strong><span class="s803-score-teams">' . $escape($home) . ' <b>' . $escape($home_score) . '</b> – <b>' . $escape($away_score) . '</b> ' . $escape($away) . '</span>' . $clock_html . '</div>';
    }

    public static function replace_managed_block($content, $begin, $end, $html) {
        $pattern = '~' . preg_quote($begin, '~') . '.*?' . preg_quote($end, '~') . '~s';
        $block = $begin . $html . $end;
        if (preg_match($pattern, (string) $content)) { return preg_replace($pattern, $block, (string) $content, 1); }
        return $block . "\n" . (string) $content;
    }

    private static function upsert_event($event, $settings) {
        $post = self::find_event_post($event['key']);
        $kind = self::status_kind($event['status'], $event['state'], $event['completed']);
        if (in_array($kind, array('cancelled', 'postponed'), true)) {
            if (!$post) { return 'ignored'; }
            self::draft_event($post->ID, $kind, $event['status']);
            $old_start = (int) get_post_meta($post->ID, '_s803_start_utc', true);
            if ($old_start !== (int) $event['start_utc']) {
                $date = self::wp_local_post_date($event['start_utc']);
                wp_update_post(wp_slash(array('ID' => $post->ID, 'post_date' => $date['local'], 'post_date_gmt' => $date['gmt'])));
            }
            self::save_event_meta($post->ID, $event, $kind);
            $content = self::replace_event_details((string) $post->post_content, self::event_details_html($event));
            if ($content !== $post->post_content) { wp_update_post(wp_slash(array('ID' => $post->ID, 'post_content' => $content))); }
            return 'drafted';
        }
        $title = self::post_title($event);
        $content = self::event_details_html($event);
        $now = time();
        if (!$post) {
            $status = !empty($settings['schedule_at_kickoff']) && $event['start_utc'] > $now ? 'future' : 'publish';
            $date = self::wp_local_post_date($event['start_utc']);
            $post_id = wp_insert_post(wp_slash(array(
                'post_type' => 'post', 'post_status' => $status, 'post_title' => $title, 'post_content' => $content,
                'post_date' => $date['local'], 'post_date_gmt' => $date['gmt'], 'post_category' => array((int) get_option('default_category')),
            )), true);
            if (is_wp_error($post_id)) { self::log('error', 0, 'Could not create ' . $title . ': ' . $post_id->get_error_message()); throw new RuntimeException($post_id->get_error_message()); }
            self::save_event_meta($post_id, $event, $kind);
            update_post_meta($post_id, self::MANAGED, '1');
            update_post_meta($post_id, '_s803_created_at', time());
            self::sync_discovered_score($post_id, $event, $kind);
            self::log('success', $post_id, 'Created WordPress event post: ' . $title . ' (' . $status . ').');
            if ($kind === 'final') { self::queue_highlights($post_id); }
            return 'created';
        }

        $current_status = $post->post_status;
        $was_managed_draft = $current_status === 'draft' && get_post_meta($post->ID, '_s803_draft_reason', true) !== '';
        if (get_post_meta($post->ID, self::HIGHLIGHT_STATE, true) === 'complete') { $title = $post->post_title; }
        $old_start = (int) get_post_meta($post->ID, '_s803_start_utc', true);
        $post_data = array('ID' => $post->ID, 'post_title' => $title);
        $changed = $old_start !== (int) $event['start_utc'];
        if ($changed) {
            if ($current_status !== 'publish') {
                $date = self::wp_local_post_date($event['start_utc']);
                $post_data['post_date'] = $date['local'];
                $post_data['post_date_gmt'] = $date['gmt'];
            }
            if ($current_status === 'future') {
                $post_data['post_status'] = !empty($settings['schedule_at_kickoff']) && $event['start_utc'] > $now ? 'future' : 'publish';
            } elseif ($was_managed_draft) {
                $post_data['post_status'] = self::restored_status($post->ID, $event, $settings);
            }
        } elseif ($was_managed_draft) {
            $post_data['post_status'] = self::restored_status($post->ID, $event, $settings);
        }
        if ($was_managed_draft && in_array($kind, array('live', 'final'), true)) { $post_data['post_status'] = 'publish'; }
        if (in_array($kind, array('live', 'final'), true) && $current_status === 'future') { $post_data['post_status'] = 'publish'; }
        $existing_content = (string) $post->post_content;
        $updated_content = self::replace_event_details($existing_content, $content);
        if ($updated_content !== $existing_content) { $post_data['post_content'] = $updated_content; }
        if (count($post_data) > 2) {
            $updated = wp_update_post(wp_slash($post_data), true);
            if (is_wp_error($updated)) { self::log('error', $post->ID, 'Could not update event post: ' . $updated->get_error_message()); throw new RuntimeException($updated->get_error_message()); }
        }
        if ($changed) {
            self::log('info', $post->ID, 'Rescheduled existing post to ' . gmdate('c', $event['start_utc']) . '.');
            update_post_meta($post->ID, '_s803_rescheduled_at', time());
        }
        if ($was_managed_draft) {
            delete_post_meta($post->ID, '_s803_draft_reason');
            delete_post_meta($post->ID, '_s803_pre_cancel_status');
            self::log('info', $post->ID, 'Restored the event post after the fixture returned to the schedule.');
        }
        self::save_event_meta($post->ID, $event, $kind);
        self::sync_discovered_score($post->ID, $event, $kind);
        if ($kind === 'final') { self::queue_highlights($post->ID); }
        return ($changed || $updated_content !== $existing_content || $was_managed_draft) ? 'updated' : 'unchanged';
    }

    private static function find_event_post($key) {
        $query = new WP_Query(array(
            'post_type' => 'post', 'post_status' => array('publish', 'future', 'draft', 'pending', 'private'), 'posts_per_page' => 1,
            'fields' => 'all', 'meta_query' => array(array('key' => self::EVENT_KEY, 'value' => $key, 'compare' => '=')),
            'no_found_rows' => true, 'ignore_sticky_posts' => true,
        ));
        return !empty($query->posts[0]) ? $query->posts[0] : null;
    }

    private static function save_event_meta($post_id, $event, $kind) {
        $values = array(
            self::EVENT_KEY => $event['key'], '_s803_event_id' => $event['event_id'], '_s803_league_id' => $event['league_id'],
            '_s803_league_name' => $event['league_name'], '_s803_sport' => $event['sport'], '_s803_slug' => $event['slug'],
            '_s803_type' => $event['type'], '_s803_start_utc' => (int) $event['start_utc'], '_s803_event_status' => $event['status'],
            '_s803_event_state' => $kind, '_s803_home' => isset($event['home']['name']) ? $event['home']['name'] : '',
            '_s803_away' => isset($event['away']['name']) ? $event['away']['name'] : '',
        );
        foreach ($values as $key => $value) { update_post_meta($post_id, $key, $value); }
        if (isset($event['live_player_url'])) { update_post_meta($post_id, '_s803_live_player_url', esc_url_raw($event['live_player_url'])); }
        if (!empty($event['oneball_id'])) { update_post_meta($post_id, '_s803_oneball_id', sanitize_text_field($event['oneball_id'])); }
    }

    private static function wp_local_post_date($timestamp) {
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(wp_timezone_string());
        $local = wp_date('Y-m-d H:i:s', (int) $timestamp, $timezone);
        return array('local' => $local, 'gmt' => gmdate('Y-m-d H:i:s', (int) $timestamp));
    }

    private static function restored_status($post_id, $event, $settings) {
        $previous = get_post_meta($post_id, '_s803_pre_cancel_status', true);
        if (in_array($previous, array('publish', 'future', 'pending', 'private'), true)) { return $previous; }
        return !empty($settings['schedule_at_kickoff']) && $event['start_utc'] > time() ? 'future' : 'publish';
    }

    private static function draft_event($post_id, $state, $reason) {
        $post = get_post($post_id);
        if (!$post) { return; }
        $saved_reason = get_post_meta($post_id, '_s803_draft_reason', true);
        $already_drafted = $post->post_status === 'draft' && $saved_reason === $state;
        $engine_managed_draft = $saved_reason !== '';
        if ($post->post_status !== 'draft') {
            update_post_meta($post_id, '_s803_pre_cancel_status', $post->post_status);
            wp_update_post(wp_slash(array('ID' => $post_id, 'post_status' => 'draft')));
            $engine_managed_draft = true;
        }
        update_post_meta($post_id, '_s803_event_state', $state);
        update_post_meta($post_id, '_s803_event_status', sanitize_text_field($reason));
        if ($engine_managed_draft) { update_post_meta($post_id, '_s803_draft_reason', $state); }
        if (!$already_drafted && $engine_managed_draft) { self::log('warning', $post_id, 'Moved event post to Draft because the fixture was ' . $state . '.'); }
    }

    private static function replace_event_details($content, $html) {
        $pattern = '~<section\b[^>]*data-s803-event-details="1"[^>]*>.*?</section>~s';
        if (preg_match($pattern, $content)) { return preg_replace($pattern, $html, $content, 1); }
        return $html . "\n" . $content;
    }

    private static function post_title($event) {
        $fixture = $event['type'] === 'race' ? $event['name'] : $event['home']['name'] . ' vs ' . $event['away']['name'];
        return wp_strip_all_tags($fixture . ' – ' . $event['league_name'] . ' | Sports 803');
    }

    private static function event_details_html($event) {
        $escape = function ($value) { return esc_html((string) $value); };
        $fixture = $event['type'] === 'race' ? $event['name'] : $event['home']['name'] . ' vs ' . $event['away']['name'];
        $date = wp_date('D, M j, Y H:i T', (int) $event['start_utc'], new DateTimeZone(S803_Dashboard_Plugin::settings()['timezone']));
        $html = '<section data-s803-event-details="1"><h2>' . $escape($fixture) . '</h2><p><strong>Competition:</strong> ' . $escape($event['league_name']) . '<br><strong>Kickoff:</strong> ' . $escape($date) . '</p>';
        if (!empty($event['live_player_url']) && $event['type'] === 'match') {
            $html .= '<div class="s803-live-player" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden"><iframe loading="lazy" allow="encrypted-media" src="' . esc_url($event['live_player_url']) . '" style="position:absolute;inset:0;width:100%;height:100%;border:0" allowfullscreen></iframe></div>';
        }
        $html .= '<p>Event information is updated automatically by Sports 803 Autopilot.</p></section>';
        return $html;
    }

    private static function managed_posts() {
        return get_posts(array(
            'post_type' => 'post', 'post_status' => array('publish', 'future', 'draft'), 'numberposts' => 500,
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => self::MANAGED, 'value' => '1', 'compare' => '='),
                array(
                    'relation' => 'OR',
                    array('key' => '_s803_start_utc', 'value' => time() - 12 * HOUR_IN_SECONDS, 'compare' => '>=', 'type' => 'NUMERIC'),
                    array('key' => self::HIGHLIGHT_STATE, 'value' => array('queued', 'retry', 'processing', 'needs_review'), 'compare' => 'IN'),
                ),
            ),
            'orderby' => 'meta_value_num', 'meta_key' => '_s803_start_utc', 'order' => 'ASC',
            'suppress_filters' => true,
        ));
    }

    private static function event_from_post($post_id) {
        $event_id = get_post_meta($post_id, '_s803_event_id', true);
        if (!$event_id) { return null; }
        return array(
            'event_id' => (string) $event_id, 'key' => (string) get_post_meta($post_id, self::EVENT_KEY, true),
            'league_id' => (string) get_post_meta($post_id, '_s803_league_id', true), 'league_name' => (string) get_post_meta($post_id, '_s803_league_name', true),
            'sport' => (string) get_post_meta($post_id, '_s803_sport', true), 'slug' => (string) get_post_meta($post_id, '_s803_slug', true),
            'type' => (string) get_post_meta($post_id, '_s803_type', true), 'start_utc' => (int) get_post_meta($post_id, '_s803_start_utc', true),
            'home' => array('name' => (string) get_post_meta($post_id, '_s803_home', true)), 'away' => array('name' => (string) get_post_meta($post_id, '_s803_away', true)),
            'status' => (string) get_post_meta($post_id, '_s803_event_status', true), 'lifecycle' => (string) get_post_meta($post_id, '_s803_event_state', true),
            'oneball_id' => (string) get_post_meta($post_id, '_s803_oneball_id', true),
        );
    }

    private static function should_check_score($event) {
        $now = time();
        return $event['start_utc'] >= $now - 12 * HOUR_IN_SECONDS && $event['start_utc'] <= $now + 60 * MINUTE_IN_SECONDS && $event['lifecycle'] !== 'final';
    }

    private static function sync_score($post_id, $event, $snapshot, $state) {
        $home_score = (string) $snapshot['home_score']; $away_score = (string) $snapshot['away_score']; $clock = (string) $snapshot['clock'];
        $previous = array(get_post_meta($post_id, '_s803_home_score', true), get_post_meta($post_id, '_s803_away_score', true), get_post_meta($post_id, '_s803_clock', true), get_post_meta($post_id, '_s803_event_state', true));
        $current = array($home_score, $away_score, $clock, $state);
        if ($previous === $current && get_post_meta($post_id, '_s803_score_banner_initialized', true)) { return false; }
        $banner = self::score_banner_html($event['home']['name'], $event['away']['name'], $home_score, $away_score, $clock, $state);
        $post = get_post($post_id);
        if (!$post) { return false; }
        $content = self::replace_managed_block($post->post_content, self::SCORE_BEGIN, self::SCORE_END, $banner);
        $data = array('ID' => $post_id, 'post_content' => $content);
        if (in_array($state, array('live', 'final'), true) && $post->post_status === 'future') { $data['post_status'] = 'publish'; }
        $updated = wp_update_post(wp_slash($data), true);
        if (is_wp_error($updated)) { self::log('error', $post_id, 'Could not update score banner: ' . $updated->get_error_message()); return false; }
        update_post_meta($post_id, '_s803_home_score', $home_score);
        update_post_meta($post_id, '_s803_away_score', $away_score);
        update_post_meta($post_id, '_s803_clock', $clock);
        update_post_meta($post_id, '_s803_event_state', $state);
        update_post_meta($post_id, '_s803_event_status', sanitize_text_field($snapshot['status']));
        update_post_meta($post_id, '_s803_last_score_sync', time());
        update_post_meta($post_id, '_s803_score_banner_initialized', 1);
        delete_post_meta($post_id, '_s803_score_last_error');
        self::log('info', $post_id, ($state === 'final' ? 'FULL TIME' : 'LIVE') . ' score updated to ' . $home_score . '–' . $away_score . ($clock ? ' (' . $clock . ')' : '') . '.');
        return true;
    }

    private static function sync_discovered_score($post_id, $event, $kind) {
        if (!in_array($kind, array('live', 'final'), true)) { return; }
        self::sync_score($post_id, $event, array(
            'home_score' => isset($event['score_home']) ? $event['score_home'] : '',
            'away_score' => isset($event['score_away']) ? $event['score_away'] : '',
            'clock' => isset($event['clock']) ? $event['clock'] : '',
            'status' => isset($event['status']) ? $event['status'] : $kind,
        ), $kind);
    }

    private static function is_final_event($post_id, $event) {
        return get_post_meta($post_id, '_s803_event_state', true) === 'final' || self::status_kind($event['status']) === 'final';
    }

    private static function queue_highlights($post_id) {
        $state = get_post_meta($post_id, self::HIGHLIGHT_STATE, true);
        if ($state === 'complete' || $state === 'needs_review' || $state === 'processing' || $state === 'queued' || $state === 'retry') { return; }
        update_post_meta($post_id, self::HIGHLIGHT_STATE, 'queued');
        update_post_meta($post_id, self::HIGHLIGHT_RETRY_AT, time());
        self::log('info', $post_id, 'Queued the finished event for a verified highlight search.');
    }

    private static function retry_is_due($post_id) {
        return (int) get_post_meta($post_id, self::HIGHLIGHT_RETRY_AT, true) <= time();
    }

    private static function process_highlight_job($post_id, $event, $settings, $feed) {
        $attempts = (int) get_post_meta($post_id, self::HIGHLIGHT_ATTEMPTS, true);
        $max = max(1, min(10, absint($settings['max_highlight_attempts'])));
        if ($attempts >= $max) {
            update_post_meta($post_id, self::HIGHLIGHT_STATE, 'needs_review');
            self::log('warning', $post_id, 'Highlight search reached the attempt limit and needs review.');
            return false;
        }
        $attempts++;
        update_post_meta($post_id, self::HIGHLIGHT_STATE, 'processing');
        update_post_meta($post_id, self::HIGHLIGHT_STARTED_AT, time());
        update_post_meta($post_id, self::HIGHLIGHT_ATTEMPTS, $attempts);
        $provider = new S803_OneBall_Provider();
        try {
            if (empty($settings['oneball_provider'])) { throw new RuntimeException('No highlight provider is enabled.'); }
            $replay = $provider->find_verified_replay($event, $feed);
            if (is_wp_error($replay)) { throw new RuntimeException($replay->get_error_message()); }
            if (!$replay) { throw new RuntimeException('No matching OneBall replay is available yet.'); }
            $post = get_post($post_id);
            if (!$post) { throw new RuntimeException('Managed WordPress post no longer exists.'); }
            $escape = function ($value) { return esc_html((string) $value); };
            $fixture = $event['home']['name'] . ' vs ' . $event['away']['name'];
            $html = '<section class="s803-verified-highlights"><h2>Verified Highlights &amp; Replay</h2><p><strong>' . $escape($fixture) . '</strong> — ' . $escape($event['league_name']) . '</p><p>Replay source: ' . $escape($replay['provider']) . '</p><div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden"><iframe loading="lazy" allow="encrypted-media" src="' . esc_url($replay['player_url']) . '" style="position:absolute;inset:0;width:100%;height:100%;border:0" allowfullscreen></iframe></div></section>';
            $content = self::replace_managed_block($post->post_content, self::HIGHLIGHTS_BEGIN, self::HIGHLIGHTS_END, $html);
            $update = wp_update_post(wp_slash(array('ID' => $post_id, 'post_content' => $content, 'post_status' => 'publish', 'post_title' => wp_strip_all_tags($fixture . ' – Highlights & Replay | Sports 803'))), true);
            if (is_wp_error($update)) { throw new RuntimeException($update->get_error_message()); }
            update_post_meta($post_id, self::HIGHLIGHT_STATE, 'complete');
            update_post_meta($post_id, '_s803_highlight_provider', $replay['provider']);
            update_post_meta($post_id, '_s803_highlight_url', esc_url_raw($replay['url']));
            update_post_meta($post_id, '_s803_highlight_completed_at', time());
            delete_post_meta($post_id, self::HIGHLIGHT_RETRY_AT);
            delete_post_meta($post_id, '_s803_highlight_last_error');
            self::log('success', $post_id, 'Verified ' . $replay['provider'] . ' replay added to the existing WordPress post.');
            return true;
        } catch (Throwable $error) {
            $delay = self::retry_delay_minutes($attempts);
            update_post_meta($post_id, '_s803_highlight_last_error', substr($error->getMessage(), 0, 400));
            delete_post_meta($post_id, self::HIGHLIGHT_STARTED_AT);
            if ($attempts >= $max) {
                update_post_meta($post_id, self::HIGHLIGHT_STATE, 'needs_review');
                delete_post_meta($post_id, self::HIGHLIGHT_RETRY_AT);
                self::log('warning', $post_id, 'Highlight attempt ' . $attempts . '/' . $max . ' failed; marked needs review: ' . $error->getMessage());
            } else {
                update_post_meta($post_id, self::HIGHLIGHT_STATE, 'retry');
                update_post_meta($post_id, self::HIGHLIGHT_RETRY_AT, time() + $delay * MINUTE_IN_SECONDS);
                self::log('warning', $post_id, 'Highlight attempt ' . $attempts . '/' . $max . ' failed; retry scheduled in ' . $delay . ' minutes: ' . $error->getMessage());
            }
            return false;
        }
    }

    private static function recover_stale_jobs($posts, $settings) {
        $max = max(1, min(10, absint($settings['max_highlight_attempts'])));
        $cutoff = time() - 20 * MINUTE_IN_SECONDS;
        foreach ($posts as $post) {
            if (get_post_meta($post->ID, self::HIGHLIGHT_STATE, true) !== 'processing') { continue; }
            $started = (int) get_post_meta($post->ID, self::HIGHLIGHT_STARTED_AT, true);
            if ($started > $cutoff) { continue; }
            $attempts = (int) get_post_meta($post->ID, self::HIGHLIGHT_ATTEMPTS, true);
            delete_post_meta($post->ID, self::HIGHLIGHT_STARTED_AT);
            if ($attempts >= $max) {
                update_post_meta($post->ID, self::HIGHLIGHT_STATE, 'needs_review');
                self::log('warning', $post->ID, 'Recovered a stale highlight job and marked it needs review after max attempts.');
            } else {
                update_post_meta($post->ID, self::HIGHLIGHT_STATE, 'retry');
                update_post_meta($post->ID, self::HIGHLIGHT_RETRY_AT, time());
                self::log('warning', $post->ID, 'Recovered a stale highlight job; queued it for retry.');
            }
        }
    }

    public static function retry_highlight($post_id) {
        $post_id = absint($post_id);
        if (!$post_id || get_post_meta($post_id, self::MANAGED, true) !== '1') { return false; }
        update_post_meta($post_id, self::HIGHLIGHT_STATE, 'queued');
        update_post_meta($post_id, self::HIGHLIGHT_ATTEMPTS, 0);
        update_post_meta($post_id, self::HIGHLIGHT_RETRY_AT, time());
        delete_post_meta($post_id, '_s803_highlight_last_error');
        self::log('info', $post_id, 'Administrator queued a manual highlight retry.');
        return true;
    }

    public static function log($level, $post_id, $message) {
        $entries = get_option(S803_Dashboard_Plugin::LOG_OPTION, array());
        if (!is_array($entries)) { $entries = array(); }
        array_unshift($entries, array('at' => gmdate('c'), 'level' => sanitize_key($level), 'post_id' => absint($post_id), 'title' => $post_id ? wp_strip_all_tags(get_the_title($post_id)) : '', 'message' => sanitize_text_field($message)));
        update_option(S803_Dashboard_Plugin::LOG_OPTION, array_slice($entries, 0, 200), false);
    }

    public static function review_posts() {
        return get_posts(array('post_type' => 'post', 'post_status' => array('publish', 'future', 'draft'), 'numberposts' => 30, 'meta_key' => self::HIGHLIGHT_STATE, 'meta_value' => 'needs_review', 'orderby' => 'modified', 'order' => 'DESC'));
    }

    public static function counts() {
        $posts = self::managed_posts();
        $counts = array('managed' => count($posts), 'queued' => 0, 'retry' => 0, 'needs_review' => 0, 'complete' => 0);
        foreach ($posts as $post) {
            $status = get_post_meta($post->ID, self::HIGHLIGHT_STATE, true);
            if (isset($counts[$status])) { $counts[$status]++; }
        }
        return $counts;
    }

    public static function public_dashboard($limit = 50) {
        $limit = max(1, min(100, absint($limit)));
        $posts = get_posts(array(
            'post_type' => 'post', 'post_status' => array('publish', 'future'), 'numberposts' => $limit,
            'meta_query' => array(
                array('key' => self::MANAGED, 'value' => '1', 'compare' => '='),
                array('key' => '_s803_start_utc', 'value' => time() - 48 * HOUR_IN_SECONDS, 'compare' => '>=', 'type' => 'NUMERIC'),
            ),
            'meta_key' => '_s803_start_utc', 'orderby' => 'meta_value_num', 'order' => 'ASC', 'suppress_filters' => true,
        ));
        $html = '<div class="s803-wp-events"><style>.s803-wp-events{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#172033}.s803-wp-events h2{margin:0 0 4px;font-size:1.15rem}.s803-wp-events .s803-event-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}.s803-wp-events .s803-event-card{border:1px solid #d9dfeb;border-radius:12px;padding:16px;background:#fff;box-shadow:0 2px 8px #1720330d}.s803-wp-events .s803-event-card a{color:#173a70;text-decoration:none}.s803-wp-events .s803-event-league{color:#5c6678;font-size:.9rem}.s803-wp-events .s803-event-state{display:inline-block;margin:9px 0;padding:3px 9px;border-radius:999px;background:#eef2f8;font-size:.8rem;font-weight:700}.s803-wp-events .s803-live{background:#fee2e2;color:#991b1b}.s803-wp-events .s803-final{background:#e5e7eb;color:#374151}.s803-wp-events .s803-score{font-size:1.1rem;font-weight:700;margin:4px 0}.s803-wp-events time{color:#5c6678;font-size:.9rem}</style>';
        $html .= '<div class="s803-event-list">';
        if (!$posts) {
            $html .= '<p>' . esc_html__('No upcoming or recent managed events are available yet.', 'sports803-dashboard') . '</p>';
        }
        foreach ($posts as $post) {
            $title = get_the_title($post);
            $state = (string) get_post_meta($post->ID, '_s803_event_state', true);
            $league = (string) get_post_meta($post->ID, '_s803_league_name', true);
            $kickoff = (int) get_post_meta($post->ID, '_s803_start_utc', true);
            $home = (string) get_post_meta($post->ID, '_s803_home', true);
            $away = (string) get_post_meta($post->ID, '_s803_away', true);
            $home_score = (string) get_post_meta($post->ID, '_s803_home_score', true);
            $away_score = (string) get_post_meta($post->ID, '_s803_away_score', true);
            $clock = (string) get_post_meta($post->ID, '_s803_clock', true);
            $status_label = $state === 'live' ? __('LIVE', 'sports803-dashboard') : ($state === 'final' ? __('FULL TIME', 'sports803-dashboard') : ($post->post_status === 'future' ? __('Scheduled', 'sports803-dashboard') : __('Upcoming', 'sports803-dashboard')));
            $state_class = $state === 'live' ? 's803-live' : ($state === 'final' ? 's803-final' : '');
            $html .= '<article class="s803-event-card"><p class="s803-event-league">' . esc_html($league) . '</p><h2>';
            if ($post->post_status === 'publish') { $html .= '<a href="' . esc_url(get_permalink($post)) . '">' . esc_html($title) . '</a>'; }
            else { $html .= esc_html($title); }
            $html .= '</h2><span class="s803-event-state ' . esc_attr($state_class) . '">' . esc_html($status_label) . '</span>';
            if ($home !== '' && $away !== '' && ($home_score !== '' || $away_score !== '')) {
                $html .= '<p class="s803-score">' . esc_html($home . ' ' . $home_score . ' – ' . $away_score . ' ' . $away) . '</p>';
                if ($clock !== '') { $html .= '<p>' . esc_html($clock) . '</p>'; }
            }
            if ($kickoff) { $html .= '<time datetime="' . esc_attr(gmdate('c', $kickoff)) . '">' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $kickoff, new DateTimeZone(S803_Dashboard_Plugin::settings()['timezone']))) . '</time>'; }
            $html .= '</article>';
        }
        $html .= '</div></div>';
        return $html;
    }

}
