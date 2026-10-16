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
    const ARTICLE_BEGIN = '<!-- S803_ARTICLE_BEGIN -->';
    const ARTICLE_END = '<!-- S803_ARTICLE_END -->';

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
            $xoilac_feed = array();
            $result['providers'] = array();
            if (!empty($settings['oneball_provider'])) {
                $oneball_feed = S803_OneBall_Provider::load_feed();
                if (is_wp_error($oneball_feed)) {
                    $result['providers']['oneball'] = array('ok' => false, 'message' => $oneball_feed->get_error_message());
                    self::log('warning', 0, 'OneBall live feed unavailable; continuing with other sources: ' . $oneball_feed->get_error_message());
                } else { $result['providers']['oneball'] = array('ok' => true, 'matches' => count($oneball_feed)); }
            }
            if (!empty($settings['xoilac_provider'])) {
                $xoilac_feed = S803_Xoilac_Provider::load_feed();
                if (is_wp_error($xoilac_feed)) {
                    $result['providers']['xoilac'] = array('ok' => false, 'message' => $xoilac_feed->get_error_message());
                    self::log('warning', 0, 'Xoilac live feed unavailable; continuing without that source: ' . $xoilac_feed->get_error_message());
                } else { $result['providers']['xoilac'] = array('ok' => true, 'matches' => count($xoilac_feed)); }
            }

            foreach ($discovery['events'] as $event) {
                $existing_post = self::find_event_post($event['key']);
                if (!empty($event['home']['name']) && !empty($event['away']['name']) && is_array($oneball_feed)) {
                    $live = S803_OneBall_Provider::find_match($event, $oneball_feed);
                    if ($live) { $event['oneball_id'] = $live['id']; }
                }
                if (!empty($event['home']['name']) && !empty($event['away']['name']) && is_array($xoilac_feed)) {
                    $source = S803_Xoilac_Provider::find_match($event, $xoilac_feed);
                    if ($source) { $event['xoilac_url'] = $source['url']; }
                }
                if ($existing_post) {
                    if (empty($event['oneball_id'])) { $event['oneball_id'] = (string) get_post_meta($existing_post->ID, '_s803_oneball_id', true); }
                    if (empty($event['xoilac_url'])) { $event['xoilac_url'] = (string) get_post_meta($existing_post->ID, '_s803_xoilac_url', true); }
                }
                if ($event['type'] === 'match') {
                    $kind = self::status_kind($event['status'], $event['state'], $event['completed']);
                    $event['live_player_url'] = in_array($kind, array('cancelled', 'postponed'), true) ? '' : S803_Player_Link::build(isset($event['oneball_id']) ? $event['oneball_id'] : '', isset($event['xoilac_url']) ? $event['xoilac_url'] : '', $kind === 'final');
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
                $facts_checked_at = (int) get_post_meta($post->ID, '_s803_player_facts_fetched_at', true);
                $facts_due = $event['type'] === 'match' && empty($event['player_data']) && (!$facts_checked_at || time() - $facts_checked_at >= 30 * MINUTE_IN_SECONDS);
                if (!empty($settings['live_score_sync']) && $event['type'] === 'match' && $result['score_checks'] < $score_limit && (self::should_check_score($event) || $facts_due)) {
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
                        $event['status'] = $snapshot['status'];
                        $event['state'] = $snapshot['state'];
                        $event['completed'] = $snapshot['completed'];
                        $event['lifecycle'] = $state;
                        $event['score_home'] = $snapshot['home_score'];
                        $event['score_away'] = $snapshot['away_score'];
                        $event['clock'] = $snapshot['clock'];
                        $event['home']['score'] = $snapshot['home_score'];
                        $event['away']['score'] = $snapshot['away_score'];
                        $facts = S803_ESPN_Provider::player_facts($score, $event);
                        update_post_meta($post->ID, '_s803_player_facts_fetched_at', time());
                        if ($facts) { $event['player_data'] = $facts; update_post_meta($post->ID, '_s803_player_data', $facts); }
                        if ($state === 'final' && get_post_meta($post->ID, self::HIGHLIGHT_STATE, true) !== 'complete') {
                            $title = self::post_title($event);
                            if ($title !== get_the_title($post->ID)) { wp_update_post(wp_slash(array('ID' => $post->ID, 'post_title' => $title))); }
                        }
                        if ($state === 'live' || $state === 'final') {
                            if (self::sync_score($post->ID, $event, $snapshot, $state)) { $result['score_updates']++; }
                            if ($state === 'final') { self::queue_highlights($post->ID); }
                        }
                        if ($facts || $state === 'live' || $state === 'final') { self::refresh_article($post->ID, $event, $state); }
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

    private static function persist_post($data, $update = true) {
        $allow_plugin_markup = function ($allowed, $context) {
            if ($context !== 'post') { return $allowed; }
            $allowed['article'] = array('class' => true, 'style' => true);
            $allowed['section'] = array('class' => true, 'style' => true, 'data-s803-event-details' => true);
            $allowed['div'] = array('class' => true, 'style' => true);
            $allowed['iframe'] = array('src' => true, 'title' => true, 'allow' => true, 'allowfullscreen' => true, 'loading' => true, 'referrerpolicy' => true, 'width' => true, 'height' => true, 'style' => true, 'class' => true);
            return $allowed;
        };
        add_filter('wp_kses_allowed_html', $allow_plugin_markup, 10, 2);
        try {
            return $update ? wp_update_post(wp_slash($data), true) : wp_insert_post(wp_slash($data), true);
        } finally {
            remove_filter('wp_kses_allowed_html', $allow_plugin_markup, 10);
        }
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
            $event['lifecycle'] = $kind;
            self::refresh_article($post->ID, $event, $kind);
            return 'drafted';
        }
        $title = self::post_title($event);
        $content = self::ARTICLE_BEGIN . self::event_details_html($event) . self::ARTICLE_END;
        $now = time();
        if (!$post) {
            $status = !empty($settings['schedule_at_kickoff']) && $event['start_utc'] > $now ? 'future' : 'publish';
            $date = self::wp_local_post_date($event['start_utc']);
            $post_id = self::persist_post(array(
                'post_type' => 'post', 'post_status' => $status, 'post_title' => $title, 'post_content' => $content,
                'post_date' => $date['local'], 'post_date_gmt' => $date['gmt'], 'post_category' => array((int) get_option('default_category')),
            ), false);
            if (is_wp_error($post_id)) { self::log('error', 0, 'Could not create ' . $title . ': ' . $post_id->get_error_message()); throw new RuntimeException($post_id->get_error_message()); }
            self::save_event_meta($post_id, $event, $kind);
            update_post_meta($post_id, self::MANAGED, '1');
            update_post_meta($post_id, '_s803_created_at', time());
            self::sync_discovered_score($post_id, $event, $kind);
            self::refresh_article($post_id, $event, $kind);
            self::log('success', $post_id, 'Created WordPress event post: ' . $title . ' (' . $status . ').');
            if ($kind === 'final') { self::queue_highlights($post_id); }
            return 'created';
        }

        $current_status = $post->post_status;
        $old_title = (string) $post->post_title;
        $old_content = (string) $post->post_content;
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
        if (count($post_data) > 1) {
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
        self::refresh_article($post->ID, $event, $kind);
        if ($kind === 'final') { self::queue_highlights($post->ID); }
        $fresh = get_post($post->ID);
        return ($changed || $old_title !== (string) $fresh->post_title || $old_content !== (string) $fresh->post_content || $was_managed_draft) ? 'updated' : 'unchanged';
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
            '_s803_home_logo' => isset($event['home']['logo']) ? esc_url_raw($event['home']['logo']) : '',
            '_s803_away_logo' => isset($event['away']['logo']) ? esc_url_raw($event['away']['logo']) : '',
        );
        foreach ($values as $key => $value) { update_post_meta($post_id, $key, $value); }
        if (!empty($event['live_player_url'])) { update_post_meta($post_id, '_s803_live_player_url', esc_url_raw($event['live_player_url'])); } else { delete_post_meta($post_id, '_s803_live_player_url'); }
        if (!empty($event['oneball_id'])) { update_post_meta($post_id, '_s803_oneball_id', sanitize_text_field($event['oneball_id'])); } else { delete_post_meta($post_id, '_s803_oneball_id'); }
        if (!empty($event['xoilac_url'])) { update_post_meta($post_id, '_s803_xoilac_url', esc_url_raw($event['xoilac_url'])); } else { delete_post_meta($post_id, '_s803_xoilac_url'); }
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
        $final = self::status_kind($event['status'], isset($event['state']) ? $event['state'] : '', !empty($event['completed'])) === 'final';
        if ($event['type'] === 'race') {
            return wp_strip_all_tags($event['name'] . ($final ? ' – Full Race Replay | Sports 803' : ' – Race Preview & Live Stream | Sports 803'));
        }
        $fixture = $event['home']['name'] . ' vs ' . $event['away']['name'];
        return wp_strip_all_tags($fixture . ($final ? ' – Highlights & Replay | Sports 803' : ' – ' . $event['league_name'] . ' Live Stream | Sports 803'));
    }

    private static function event_details_html($event, $thumbnail_url = '') {
        $escape = function ($value) { return esc_html((string) $value); };
        $final = self::status_kind($event['status'], isset($event['state']) ? $event['state'] : '', !empty($event['completed'])) === 'final' || (isset($event['lifecycle']) && $event['lifecycle'] === 'final');
        $race = isset($event['type']) && $event['type'] === 'race';
        $home = isset($event['home']['name']) ? (string) $event['home']['name'] : '';
        $away = isset($event['away']['name']) ? (string) $event['away']['name'] : '';
        $fixture = $race ? (string) $event['name'] : trim($home . ' vs ' . $away);
        $date = wp_date('l, F j, Y \a\t H:i T', (int) $event['start_utc'], new DateTimeZone(S803_Dashboard_Plugin::settings()['timezone']));
        $home_score = isset($event['score_home']) ? (string) $event['score_home'] : (isset($event['home']['score']) ? (string) $event['home']['score'] : '');
        $away_score = isset($event['score_away']) ? (string) $event['score_away'] : (isset($event['away']['score']) ? (string) $event['away']['score'] : '');
        $score = $home_score !== '' || $away_score !== '' ? $home_score . '–' . $away_score : '';
        $pd = isset($event['player_data']) && is_array($event['player_data']) ? $event['player_data'] : array();
        $html = '<article class="s803-match-article">';
        if ($thumbnail_url) { $html .= '<p class="s803-article-thumbnail"><img src="' . esc_url($thumbnail_url) . '" alt="' . esc_attr($fixture . ' – Sports 803 match graphic') . '" loading="lazy" style="display:block;width:100%;height:auto;border-radius:12px;margin:0 0 22px"></p>'; }
        $html .= '<section data-s803-event-details="1"><p><strong>Competition:</strong> ' . $escape($event['league_name']) . '<br><strong>' . ($final ? 'Scheduled:' : 'Kickoff:') . '</strong> ' . $escape($date);
        if ($score !== '') { $html .= '<br><strong>' . ($final ? 'Final score:' : 'Current score:') . '</strong> ' . $escape($score); }
        $html .= '</p>';
        if (!$race && !empty($event['live_player_url'])) {
            $html .= '<div class="s803-live-player" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;margin:22px 0"><iframe loading="lazy" allow="encrypted-media; picture-in-picture" src="' . esc_url($event['live_player_url']) . '" title="' . esc_attr($fixture . ' player') . '" style="position:absolute;inset:0;width:100%;height:100%;border:0;border-radius:10px" allowfullscreen></iframe></div>';
        }
        $html .= '</section><p><strong>By Sports 803 Match Desk</strong><br>Event details and score updates are refreshed automatically when verified source data changes.</p>';
        if (!empty($pd['home']['starters']) || !empty($pd['away']['starters'])) {
            $heading = $final && !empty($pd['complete']) ? 'Confirmed Starting XIs' : 'Probable XIs';
            $home_xi = array_map(function ($item) { return isset($item['name']) ? $item['name'] : ''; }, (array) $pd['home']['starters']);
            $away_xi = array_map(function ($item) { return isset($item['name']) ? $item['name'] : ''; }, (array) $pd['away']['starters']);
            $html .= '<h2>' . $escape($heading) . '</h2><p><strong>' . $escape($home) . ':</strong> ' . $escape(implode(', ', array_filter($home_xi))) . '<br><strong>' . $escape($away) . ':</strong> ' . $escape(implode(', ', array_filter($away_xi))) . '</p>';
        }
        $goal_lines = array();
        foreach (array('home', 'away') as $side) {
            foreach ((array) (isset($pd[$side]['scorers']) ? $pd[$side]['scorers'] : array()) as $goal) {
                $line = trim((isset($goal['player']) ? $goal['player'] : '') . (empty($goal['minute']) ? '' : ' ' . $goal['minute']) . (empty($goal['assist']) ? '' : ' (assist: ' . $goal['assist'] . ')'));
                if ($line !== '') { $goal_lines[] = '<strong>' . $escape(isset($pd[$side]['name']) ? $pd[$side]['name'] : ($side === 'home' ? $home : $away)) . ':</strong> ' . $escape($line); }
            }
        }
        if ($goal_lines) { $html .= '<h2>Goal Scorers &amp; Key Moments</h2><p>' . implode('<br>', $goal_lines) . '</p>'; }
        $timeline = array();
        foreach (array('home', 'away') as $side) {
            foreach (array('cards' => 'Booked', 'substitutions' => 'Substitution') as $field => $label) {
                foreach ((array) (isset($pd[$side][$field]) ? $pd[$side][$field] : array()) as $item) {
                    $timeline[] = $escape(isset($item['minute']) ? $item['minute'] : 'Match event') . ' — ' . $escape(isset($item['player']) ? $item['player'] : '') . ' · ' . $label . ' (' . $escape($side === 'home' ? $home : $away) . ')';
                }
            }
        }
        if ($timeline) { $html .= '<h2>Bookings &amp; Substitutions</h2><ul><li>' . implode('</li><li>', $timeline) . '</li></ul>'; }

        if ($race) {
            $html .= '<h2>' . $escape($event['league_name']) . ' Race Report</h2><p>This article records the available event context and separates verified race details from editorial analysis.</p><h2>Race Overview</h2><p>Follow the pace, decisive moves and turning points using verified race information as it becomes available.</p><h2>How to Watch</h2><p>Use an authorized broadcaster or licensed replay in your region. A Sports 803 player is included only when a verified source is available.</p>';
        } elseif ($final) {
            $html .= '<h2>Match Report</h2><p>' . $escape($fixture) . ' finished ' . ($score ? '<strong>' . $escape($score) . '</strong>' : 'with the final result recorded in the match details') . ' in ' . $escape($event['league_name']) . '. This report separates verified match events from editorial context.</p><h2>Match Details</h2><p><strong>Teams:</strong> ' . $escape($fixture) . '<br><strong>Competition:</strong> ' . $escape($event['league_name']) . '<br><strong>Scheduled:</strong> ' . $escape($date) . ($score ? '<br><strong>Score:</strong> ' . $escape($score) : '') . '</p><h2>Tactical Review</h2><p>The analysis is limited to the match timeline and verified information available for this fixture; unavailable statistics are not presented as fact.</p><h2>How to Watch</h2><p>Use an authorized broadcaster or licensed replay in your region. The Sports 803 player is shown only when a provider source is available and verified.</p><h2>Frequently Asked Questions</h2><p><strong>What was the result?</strong><br>' . $escape($fixture) . ' — ' . $escape($score ?: 'see the latest verified match data') . '.</p>';
        } else {
            $html .= '<h2>Match Preview</h2><p>' . $escape($fixture) . ' meet in ' . $escape($event['league_name']) . '. This preview records the scheduled context and the questions the match will answer, without presenting generic claims as verified analysis.</p><h2>Match Details</h2><p><strong>Teams:</strong> ' . $escape($fixture) . '<br><strong>Competition:</strong> ' . $escape($event['league_name']) . '<br><strong>Kickoff:</strong> ' . $escape($date) . '</p><h2>What to Watch</h2><p>Confirmed team news, tactical adjustments, set pieces and the quality of chances created will shape the contest. Starting XIs are labelled as confirmed only after a verified match-data release.</p><h2>How to Watch</h2><p>Check an authorized broadcaster or licensed player in your region. The live player is included when an available source has been matched to this fixture.</p><h2>Frequently Asked Questions</h2><p><strong>When is the match?</strong><br>The latest scheduled time is listed above. <strong>Where can I watch?</strong><br>Use the Sports 803 player and stream information attached to this article.</p>';
        }
        $summary_url = !empty($event['sport']) && !empty($event['slug']) && !empty($event['event_id']) ? S803_ESPN_Provider::API . '/' . rawurlencode($event['sport']) . '/' . rawurlencode($event['slug']) . '/summary?event=' . rawurlencode($event['event_id']) : '';
        $html .= '<h2>Reporting Notes</h2><p>This report distinguishes verified match data from editorial analysis. Scorers, lineups, cards and substitutions appear only when returned by available match data. ' . ($summary_url ? '<a href="' . esc_url($summary_url) . '" target="_blank" rel="nofollow noopener">Review the match-data source</a>.' : 'Check the competition organiser or an authorized broadcaster for official details.') . '</p>';
        if (!$final && !$race) { $html .= '<p><strong>Stream CTA:</strong> Check back here for kickoff changes, live scores and verified event updates.</p>'; }
        $html .= '</article>';
        return $html;
    }

    private static function refresh_article($post_id, $event, $kind) {
        $thumb = array();
        $last_error = (int) get_post_meta($post_id, '_s803_thumbnail_error_at', true);
        if (!$last_error || time() - $last_error > 12 * HOUR_IN_SECONDS) {
            $generated = S803_Thumbnail_Generator::ensure($post_id, $event, $kind === 'final');
            if (is_wp_error($generated)) {
                update_post_meta($post_id, '_s803_thumbnail_error_at', time());
                update_post_meta($post_id, '_s803_thumbnail_last_error', substr($generated->get_error_message(), 0, 300));
                self::log('warning', $post_id, 'Featured thumbnail could not be generated: ' . $generated->get_error_message());
            } else { $thumb = $generated; delete_post_meta($post_id, '_s803_thumbnail_error_at'); delete_post_meta($post_id, '_s803_thumbnail_last_error'); }
        } else {
            $attachment_id = (int) get_post_thumbnail_id($post_id);
            if ($attachment_id) { $thumb = array('attachment_id' => $attachment_id, 'url' => (string) get_the_post_thumbnail_url($post_id, 'full')); }
        }
        $post = get_post($post_id);
        if (!$post) { return false; }
        $content = (string) $post->post_content;
        $content = preg_replace('~<section\b[^>]*data-s803-event-details="1"[^>]*>.*?</section>~s', '', $content, 1);
        $updated_content = self::replace_managed_block($content, self::ARTICLE_BEGIN, self::ARTICLE_END, self::event_details_html($event, isset($thumb['url']) ? $thumb['url'] : ''));
        $update = array('ID' => $post_id);
        if ($updated_content !== (string) $post->post_content) { $update['post_content'] = $updated_content; }
        $fixture_for_excerpt = !empty($event['home']['name']) && !empty($event['away']['name']) ? $event['home']['name'] . ' vs ' . $event['away']['name'] : (isset($event['name']) ? $event['name'] : 'Sports event');
        $description = substr(wp_strip_all_tags($fixture_for_excerpt . ' ' . ($kind === 'final' ? 'highlights and replay' : 'live stream') . ' on Sports 803'), 0, 150);
        $old_generated = (string) get_post_meta($post_id, '_s803_auto_excerpt', true);
        if ($post->post_excerpt === '' || $post->post_excerpt === $old_generated) {
            if ($post->post_excerpt !== $description) { $update['post_excerpt'] = $description; }
            update_post_meta($post_id, '_s803_auto_excerpt', $description);
        }
        if (count($update) > 1) {
            $saved = self::persist_post($update, true);
            if (is_wp_error($saved)) { self::log('warning', $post_id, 'Could not refresh generated article content: ' . $saved->get_error_message()); }
        }
        self::sync_post_tags($post_id, $event, $kind === 'final', !empty($thumb['url']));
        return true;
    }

    private static function sync_post_tags($post_id, $event, $final, $has_thumbnail) {
        $key = (string) $event['key'];
        $hash = 2166136261;
        for ($i = 0, $length = strlen($key); $i < $length; $i++) { $hash = (($hash ^ ord($key[$i])) * 16777619) & 0xffffffff; }
        $sport = strtolower((string) $event['sport']);
        $sport_tags = array(
            'soccer' => array('Football', 'Football Live Stream'), 'basketball' => array('Basketball', 'Basketball Live Stream'),
            'football' => array('American Football', 'Football Live Stream'), 'hockey' => array('Ice Hockey', 'Hockey Live Stream'),
            'tennis' => array('Tennis', 'Tennis Live Stream'), 'baseball' => array('Baseball', 'Baseball Live Stream'),
            'racing' => array('Motorsport', 'Live Racing'), 'golf' => array('Golf', 'Golf Live Stream'),
            'mma' => array('MMA', 'Combat Sports'), 'cycling' => array('Cycling', 'Cycling Live Stream'),
        );
        $labels = array('s803:event:' . sprintf('%08x', $hash), $final ? 'Highlights' : 'Event Stream');
        if ($has_thumbnail) { $labels[] = 's803:thumbnail'; }
        $labels = array_merge($labels, array((string) $event['league_name'], (string) $event['league_id'], $sport, $event['type'] === 'race' ? 'Motorsport' : 'Sports', 'Sports 803'));
        $labels = array_merge($labels, isset($sport_tags[$sport]) ? $sport_tags[$sport] : array('Live Sports'));
        if (!empty($event['home']['name'])) { $labels[] = (string) $event['home']['name']; }
        if (!empty($event['away']['name'])) { $labels[] = (string) $event['away']['name']; }
        $labels = array_slice(array_values(array_unique(array_filter(array_map('sanitize_text_field', $labels)))), 0, 20);
        $previous = get_post_meta($post_id, '_s803_managed_tag_names', true);
        if (!is_array($previous)) { $previous = array(); }
        $existing = wp_get_post_tags($post_id, array('fields' => 'names'));
        if (is_wp_error($existing)) { $existing = array(); }
        $custom = array_values(array_diff((array) $existing, $previous));
        $all = array_values(array_unique(array_merge($custom, $labels)));
        wp_set_post_tags($post_id, $all, false);
        update_post_meta($post_id, '_s803_managed_tag_names', $labels);
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
            'home' => array('name' => (string) get_post_meta($post_id, '_s803_home', true), 'score' => (string) get_post_meta($post_id, '_s803_home_score', true), 'logo' => (string) get_post_meta($post_id, '_s803_home_logo', true)),
            'away' => array('name' => (string) get_post_meta($post_id, '_s803_away', true), 'score' => (string) get_post_meta($post_id, '_s803_away_score', true), 'logo' => (string) get_post_meta($post_id, '_s803_away_logo', true)),
            'status' => (string) get_post_meta($post_id, '_s803_event_status', true), 'lifecycle' => (string) get_post_meta($post_id, '_s803_event_state', true),
            'score_home' => (string) get_post_meta($post_id, '_s803_home_score', true), 'score_away' => (string) get_post_meta($post_id, '_s803_away_score', true),
            'oneball_id' => (string) get_post_meta($post_id, '_s803_oneball_id', true), 'xoilac_url' => (string) get_post_meta($post_id, '_s803_xoilac_url', true),
            'live_player_url' => (string) get_post_meta($post_id, '_s803_live_player_url', true), 'player_data' => get_post_meta($post_id, '_s803_player_data', true),
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
        $updated = self::persist_post($data, true);
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
        try {
            $replay = null;
            $provider_errors = array();
            if (!empty($settings['oneball_provider'])) {
                $oneball = (new S803_OneBall_Provider())->find_verified_replay($event, $feed);
                if (is_wp_error($oneball)) { $provider_errors[] = 'OneBall: ' . $oneball->get_error_message(); }
                elseif ($oneball) { $replay = $oneball; }
            }
            if (!$replay && !empty($settings['livetv_provider'])) {
                $livetv = (new S803_LiveTV_Provider())->find_verified_replay($event, $feed);
                if (is_wp_error($livetv)) { $provider_errors[] = 'LiveTV: ' . $livetv->get_error_message(); }
                elseif ($livetv) { $replay = $livetv; }
            }
            if (!$replay) { throw new RuntimeException($provider_errors ? implode(' · ', $provider_errors) : 'No verified OneBall or LiveTV replay is available yet.'); }
            $post = get_post($post_id);
            if (!$post) { throw new RuntimeException('Managed WordPress post no longer exists.'); }
            $escape = function ($value) { return esc_html((string) $value); };
            $fixture = $event['home']['name'] . ' vs ' . $event['away']['name'];
            $html = '<section class="s803-verified-highlights"><h2>Verified Highlights &amp; Replay</h2><p><strong>' . $escape($fixture) . '</strong> — ' . $escape($event['league_name']) . '</p><p>Replay source: ' . $escape($replay['provider']) . '</p><div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden"><iframe loading="lazy" allow="encrypted-media" src="' . esc_url($replay['player_url']) . '" style="position:absolute;inset:0;width:100%;height:100%;border:0" allowfullscreen></iframe></div></section>';
            $content = self::replace_managed_block($post->post_content, self::HIGHLIGHTS_BEGIN, self::HIGHLIGHTS_END, $html);
            $update = self::persist_post(array('ID' => $post_id, 'post_content' => $content, 'post_status' => 'publish', 'post_title' => wp_strip_all_tags($fixture . ' – Highlights & Replay | Sports 803')), true);
            if (is_wp_error($update)) { throw new RuntimeException($update->get_error_message()); }
            update_post_meta($post_id, self::HIGHLIGHT_STATE, 'complete');
            update_post_meta($post_id, '_s803_highlight_provider', $replay['provider']);
            update_post_meta($post_id, '_s803_highlight_url', esc_url_raw($replay['url']));
            update_post_meta($post_id, '_s803_highlight_completed_at', time());
            delete_post_meta($post_id, self::HIGHLIGHT_RETRY_AT);
            delete_post_meta($post_id, '_s803_highlight_last_error');
            update_post_meta($post_id, '_s803_highlight_source_url', esc_url_raw(isset($replay['source_url']) ? $replay['source_url'] : $replay['url']));
            $event['lifecycle'] = 'final';
            self::refresh_article($post_id, $event, 'final');
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
        $live_count = 0; $final_count = 0;
        foreach ($posts as $post) { $state = (string) get_post_meta($post->ID, '_s803_event_state', true); if ($state === 'live') { $live_count++; } if ($state === 'final') { $final_count++; } }
        $html = '<div class="s803-wp-events"><style>.s803-wp-events{font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif;color:#172033;--s803-red:#df2947;--s803-blue:#183652}.s803-wp-events *{box-sizing:border-box}.s803-wp-events .s803-board-hero{padding:28px 30px;border-radius:18px;background:linear-gradient(120deg,#101827,#203c55 72%,#a31835);color:#fff;margin:0 0 20px}.s803-wp-events .s803-brand{font-weight:900;letter-spacing:.12em;text-transform:uppercase;font-size:.82rem;color:#fff}.s803-wp-events .s803-brand b{color:#ff596e}.s803-wp-events .s803-board-hero h2{font-size:clamp(1.55rem,3vw,2.15rem);line-height:1.2;color:#fff;margin:8px 0}.s803-wp-events .s803-board-hero p{color:#e1e9f0;margin:0}.s803-wp-events .s803-board-tools{display:flex;justify-content:space-between;align-items:center;gap:14px;margin:18px 0;flex-wrap:wrap}.s803-wp-events .s803-board-tools input{min-width:min(100%,320px);border:1px solid #d7deea;border-radius:10px;padding:11px 14px;font:inherit}.s803-wp-events .s803-board-stats{display:flex;gap:8px;flex-wrap:wrap}.s803-wp-events .s803-board-stats span{padding:7px 10px;border-radius:99px;background:#f0f3f7;color:#38465a;font-size:.82rem;font-weight:700}.s803-wp-events .s803-board-stats .s803-live{background:#ffe6e9;color:#a1122f}.s803-wp-events .s803-event-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px}.s803-wp-events .s803-event-card{border:1px solid #e0e5ed;border-radius:15px;overflow:hidden;background:#fff;box-shadow:0 7px 24px #10243b0c;transition:transform .18s ease,box-shadow .18s ease}.s803-wp-events .s803-event-card:hover{transform:translateY(-2px);box-shadow:0 12px 30px #10243b18}.s803-wp-events .s803-card-image{display:block;width:100%;height:auto;aspect-ratio:16/9;object-fit:cover;background:#101827}.s803-wp-events .s803-card-body{padding:16px}.s803-wp-events .s803-event-card h3{font-size:1.12rem;line-height:1.35;margin:4px 0 10px;color:#172033}.s803-wp-events .s803-event-card a{color:inherit;text-decoration:none}.s803-wp-events .s803-event-card a:hover{color:var(--s803-red)}.s803-wp-events .s803-event-league{color:#687487;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin:0}.s803-wp-events .s803-event-state{display:inline-block;margin:6px 0 10px;padding:5px 10px;border-radius:999px;background:#eef2f8;color:#364254;font-size:.74rem;font-weight:800;letter-spacing:.04em}.s803-wp-events .s803-live{background:#ffe4e8;color:#a1122f}.s803-wp-events .s803-final{background:#e8ebf0;color:#374151}.s803-wp-events .s803-score{font-size:1.12rem;font-weight:800;margin:5px 0;color:#172033}.s803-wp-events time{color:#687487;font-size:.9rem}.s803-wp-events .s803-event-cta{display:flex;gap:8px;margin-top:15px}.s803-wp-events .s803-event-cta a{display:inline-flex;padding:9px 12px;border-radius:8px;background:var(--s803-blue);color:#fff;font-weight:750;font-size:.84rem}.s803-wp-events .s803-event-cta a:hover{background:var(--s803-red);color:#fff}.s803-wp-events .s803-board-empty{padding:28px;text-align:center;border:1px dashed #cbd5e1;border-radius:12px;color:#64748b;grid-column:1/-1}@media(max-width:600px){.s803-wp-events .s803-board-hero{padding:22px}.s803-wp-events .s803-event-list{grid-template-columns:1fr}}</style>';
        $html .= '<header class="s803-board-hero"><div class="s803-brand"><b>●</b> Sports 803</div><h2>' . esc_html__('Matchday Dashboard', 'sports803-dashboard') . '</h2><p>' . esc_html__('Fixtures, live score updates and verified replay posts — managed automatically in WordPress.', 'sports803-dashboard') . '</p></header><div class="s803-board-tools"><label><span class="screen-reader-text">' . esc_html__('Search matches', 'sports803-dashboard') . '</span><input type="search" data-s803-filter placeholder="' . esc_attr__('Search teams or competitions…', 'sports803-dashboard') . '"></label><div class="s803-board-stats"><span>' . esc_html((string) count($posts)) . ' ' . esc_html__('events', 'sports803-dashboard') . '</span><span class="s803-live">' . esc_html((string) $live_count) . ' ' . esc_html__('live', 'sports803-dashboard') . '</span><span>' . esc_html((string) $final_count) . ' ' . esc_html__('full time', 'sports803-dashboard') . '</span></div></div><div class="s803-event-list">';
        if (!$posts) {
            $html .= '<p class="s803-board-empty">' . esc_html__('No upcoming or recent managed events are available yet.', 'sports803-dashboard') . '</p>';
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
            $search = strtolower($title . ' ' . $league . ' ' . $home . ' ' . $away);
            $html .= '<article class="s803-event-card" data-search="' . esc_attr($search) . '">';
            if (has_post_thumbnail($post->ID)) { $html .= get_the_post_thumbnail($post->ID, 'medium_large', array('class' => 's803-card-image', 'loading' => 'lazy', 'alt' => $title)); }
            $html .= '<div class="s803-card-body"><p class="s803-event-league">' . esc_html($league) . '</p><h3>';
            if ($post->post_status === 'publish') { $html .= '<a href="' . esc_url(get_permalink($post)) . '">' . esc_html($title) . '</a>'; }
            else { $html .= esc_html($title); }
            $html .= '</h3><span class="s803-event-state ' . esc_attr($state_class) . '">' . esc_html($status_label) . '</span>';
            if ($home !== '' && $away !== '' && ($home_score !== '' || $away_score !== '')) {
                $html .= '<p class="s803-score">' . esc_html($home . ' ' . $home_score . ' – ' . $away_score . ' ' . $away) . '</p>';
                if ($clock !== '') { $html .= '<p>' . esc_html($clock) . '</p>'; }
            }
            if ($kickoff) { $html .= '<time datetime="' . esc_attr(gmdate('c', $kickoff)) . '">' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $kickoff, new DateTimeZone(S803_Dashboard_Plugin::settings()['timezone']))) . '</time>'; }
            if ($post->post_status === 'publish') {
                $player_url = (string) get_post_meta($post->ID, '_s803_live_player_url', true);
                $target_url = $player_url && in_array($state, array('live', 'final'), true) ? $player_url : get_permalink($post);
                $cta = $player_url && in_array($state, array('live', 'final'), true) ? __('Open player', 'sports803-dashboard') : __('View match', 'sports803-dashboard');
                $html .= '<div class="s803-event-cta"><a href="' . esc_url($target_url) . '"' . ($target_url === $player_url ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . esc_html($cta) . '</a></div>';
            }
            $html .= '</div></article>';
        }
        $html .= '</div><script>(function(){var r=document.currentScript.parentNode;var f=r.querySelector("[data-s803-filter]");if(!f)return;f.addEventListener("input",function(){var q=f.value.toLowerCase().trim();r.querySelectorAll(".s803-event-card").forEach(function(c){c.hidden=q!==""&&c.getAttribute("data-search").indexOf(q)===-1})})})()</script></div>';
        return $html;
    }

}
