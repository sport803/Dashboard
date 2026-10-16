<?php
/**
 * Plugin Name: Sports 803 Dashboard & Blogger Autopilot
 * Description: Embeds the Sports 803 dashboard and runs the multi-blog Blogger autoposter from WordPress Cron.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Sports 803
 * License: GPL-2.0-or-later
 * Text Domain: sports803-dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}

final class S803_Dashboard_Plugin {
    const OPTION = 's803_dashboard_settings';
    const LAST_RUN = 's803_dashboard_last_run';
    const CRON_HOOK = 's803_dashboard_autopilot_tick';
    const CRON_INTERVAL = 's803_every_five_minutes';

    public static function defaults() {
        return array(
            'autopilot_enabled' => 0,
            'blog_ids' => '',
            'google_client_id' => '',
            'google_client_secret' => '',
            'refresh_token' => '',
            'imgbb_api_key' => '',
            'timezone' => 'Africa/Nairobi',
            'league_filter' => '',
            'max_writes' => 10,
        );
    }

    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('update_option_' . self::OPTION, array(__CLASS__, 'settings_changed'), 10, 2);
        add_action('add_option_' . self::OPTION, array(__CLASS__, 'settings_added'), 10, 2);
        add_action(self::CRON_HOOK, array(__CLASS__, 'scheduled_run'));
        add_action('admin_post_s803_run_now', array(__CLASS__, 'manual_run'));
        add_shortcode('sports803_dashboard', array(__CLASS__, 'dashboard_shortcode'));
    }

    public static function activate() {
        if (get_option(self::OPTION, false) === false) {
            add_option(self::OPTION, self::defaults(), '', false);
        }
        self::sync_schedule();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        delete_transient('s803_autopilot_lock');
    }

    public static function cron_schedules($schedules) {
        $schedules[self::CRON_INTERVAL] = array(
            'interval' => 300,
            'display' => __('Every five minutes', 'sports803-dashboard'),
        );
        return $schedules;
    }

    public static function admin_menu() {
        add_options_page(
            __('Sports 803 Autopilot', 'sports803-dashboard'),
            __('Sports 803 Autopilot', 'sports803-dashboard'),
            'manage_options',
            's803-dashboard',
            array(__CLASS__, 'render_settings_page')
        );
    }

    public static function register_settings() {
        register_setting('s803_dashboard_group', self::OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize_settings'),
            'default' => self::defaults(),
        ));
    }

    public static function sanitize_settings($input) {
        $old = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        $input = is_array($input) ? $input : array();
        $ids = preg_split('/[\s,]+/', isset($input['blog_ids']) ? sanitize_text_field($input['blog_ids']) : '');
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids), 'ctype_digit')));
        $timezone = isset($input['timezone']) ? sanitize_text_field($input['timezone']) : 'Africa/Nairobi';
        try {
            new DateTimeZone($timezone);
        } catch (Exception $e) {
            $timezone = 'Africa/Nairobi';
        }
        $max = isset($input['max_writes']) ? absint($input['max_writes']) : 10;
        $settings = array(
            'autopilot_enabled' => !empty($input['autopilot_enabled']) ? 1 : 0,
            'blog_ids' => implode(',', $ids),
            'google_client_id' => isset($input['google_client_id']) && $input['google_client_id'] !== '' ? sanitize_text_field($input['google_client_id']) : $old['google_client_id'],
            'google_client_secret' => isset($input['google_client_secret']) && $input['google_client_secret'] !== '' ? sanitize_text_field($input['google_client_secret']) : $old['google_client_secret'],
            'refresh_token' => isset($input['refresh_token']) && $input['refresh_token'] !== '' ? sanitize_text_field($input['refresh_token']) : $old['refresh_token'],
            'imgbb_api_key' => isset($input['imgbb_api_key']) && $input['imgbb_api_key'] !== '' ? sanitize_text_field($input['imgbb_api_key']) : $old['imgbb_api_key'],
            'timezone' => $timezone,
            'league_filter' => isset($input['league_filter']) ? sanitize_text_field($input['league_filter']) : '',
            'max_writes' => max(1, min(25, $max ?: 10)),
        );
        return $settings;
    }

    public static function settings_changed($old, $new) {
        self::sync_schedule();
    }

    public static function settings_added($option, $value) {
        self::sync_schedule();
    }

    private static function settings() {
        return wp_parse_args(get_option(self::OPTION, array()), self::defaults());
    }

    private static function is_configured($settings) {
        return !empty($settings['blog_ids']) && !empty($settings['google_client_id']) && !empty($settings['google_client_secret']) && !empty($settings['refresh_token']);
    }

    private static function sync_schedule() {
        $settings = self::settings();
        if (!empty($settings['autopilot_enabled']) && self::is_configured($settings)) {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + 60, self::CRON_INTERVAL, self::CRON_HOOK);
            }
        } else {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    public static function scheduled_run() {
        $settings = self::settings();
        if (empty($settings['autopilot_enabled']) || !self::is_configured($settings)) {
            return;
        }
        self::run_autoposter($settings, 'cron');
    }

    private static function run_autoposter($settings, $source) {
        if (get_transient('s803_autopilot_lock')) {
            return new WP_Error('s803_busy', __('An autopilot run is already in progress.', 'sports803-dashboard'));
        }
        set_transient('s803_autopilot_lock', 1, 600);
        $start = time();
        try {
            $result = S803_Blogger_Autoposter::run($settings);
            $result['source'] = $source;
            $result['started_at'] = gmdate('c', $start);
            $result['finished_at'] = gmdate('c');
            update_option(self::LAST_RUN, $result, false);
            return $result;
        } catch (Throwable $error) {
            $result = array(
                'ok' => false,
                'source' => $source,
                'started_at' => gmdate('c', $start),
                'finished_at' => gmdate('c'),
                'error' => substr($error->getMessage(), 0, 700),
            );
            update_option(self::LAST_RUN, $result, false);
            return new WP_Error('s803_run_failed', $result['error']);
        } finally {
            delete_transient('s803_autopilot_lock');
        }
    }

    public static function manual_run() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to run this task.', 'sports803-dashboard'));
        }
        check_admin_referer('s803_run_now');
        $result = self::run_autoposter(self::settings(), 'manual');
        $status = is_wp_error($result) ? 'failed' : 'complete';
        wp_safe_redirect(add_query_arg(array('page' => 's803-dashboard', 's803_run' => $status), admin_url('options-general.php')));
        exit;
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = self::settings();
        $last = get_option(self::LAST_RUN, array());
        $next = wp_next_scheduled(self::CRON_HOOK);
        $fields = array(
            'blog_ids' => array(__('Blogger blog IDs', 'sports803-dashboard'), 'text', __('Comma-separated numeric IDs. The Google OAuth account must be an editor on each blog.', 'sports803-dashboard')),
            'google_client_id' => array(__('Google OAuth client ID', 'sports803-dashboard'), 'password', __('Use the same OAuth client that issued the refresh token.', 'sports803-dashboard')),
            'google_client_secret' => array(__('Google OAuth client secret', 'sports803-dashboard'), 'password', ''),
            'refresh_token' => array(__('Blogger refresh token', 'sports803-dashboard'), 'password', __('Keep this secret. A blank field leaves the saved value unchanged.', 'sports803-dashboard')),
            'imgbb_api_key' => array(__('ImgBB API key (optional)', 'sports803-dashboard'), 'password', __('Used for generated article thumbnails when the PHP GD extension is available.', 'sports803-dashboard')),
            'timezone' => array(__('Event timezone', 'sports803-dashboard'), 'text', __('IANA timezone, e.g. Africa/Nairobi.', 'sports803-dashboard')),
            'league_filter' => array(__('League filter', 'sports803-dashboard'), 'text', __('Comma-separated league IDs such as epl,nba,f1. Leave blank for all supported leagues.', 'sports803-dashboard')),
            'max_writes' => array(__('Maximum writes per blog per run', 'sports803-dashboard'), 'number', __('Defaults to 10. Unposted events resume on the next run; valid range is 1–25.', 'sports803-dashboard')),
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Sports 803 Blogger Autopilot', 'sports803-dashboard'); ?></h1>
            <p><?php echo esc_html__('This plugin keeps publishing to the configured Blogger blogs; it does not switch the destination to WordPress posts. Before enabling this autopilot, disable the GitHub Actions schedule to avoid concurrent publishers.', 'sports803-dashboard'); ?></p>
            <?php if (isset($_GET['s803_run'])) : ?>
                <div class="notice notice-<?php echo $_GET['s803_run'] === 'complete' ? 'success' : 'error'; ?> is-dismissible"><p><?php echo $_GET['s803_run'] === 'complete' ? esc_html__('Manual autopilot run finished; see the latest run summary below.', 'sports803-dashboard') : esc_html__('Manual run failed or another run is active; see the latest run summary below.', 'sports803-dashboard'); ?></p></div>
            <?php endif; ?>
            <h2><?php echo esc_html__('Autopilot status', 'sports803-dashboard'); ?></h2>
            <p><strong><?php echo esc_html__('Mode:', 'sports803-dashboard'); ?></strong> <?php echo !empty($settings['autopilot_enabled']) && $next ? esc_html__('Enabled — WordPress Cron checks every five minutes', 'sports803-dashboard') : esc_html__('Disabled', 'sports803-dashboard'); ?></p>
            <p><strong><?php echo esc_html__('Next scheduled check:', 'sports803-dashboard'); ?></strong> <?php echo $next ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next)) : esc_html__('Not scheduled', 'sports803-dashboard'); ?></p>
            <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html__('DISABLE_WP_CRON is enabled. Configure a server cron to request wp-cron.php every five minutes or this autopilot will not run on schedule.', 'sports803-dashboard'); ?></p></div>
            <?php else : ?>
                <p class="description"><?php echo esc_html__('WordPress Cron is traffic-triggered, so quiet sites may run late. For dependable five-minute timing, configure your hosting control panel/server cron to request wp-cron.php every five minutes.', 'sports803-dashboard'); ?></p>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields('s803_dashboard_group'); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row"><?php echo esc_html__('Autopilot', 'sports803-dashboard'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[autopilot_enabled]" value="1" <?php checked(!empty($settings['autopilot_enabled'])); ?>> <?php echo esc_html__('Enable automatic Blogger posting every five minutes', 'sports803-dashboard'); ?></label></td></tr>
                    <?php foreach ($fields as $key => $field) : ?>
                        <tr><th scope="row"><label for="s803-<?php echo esc_attr($key); ?>"><?php echo esc_html($field[0]); ?></label></th><td>
                            <input class="regular-text" id="s803-<?php echo esc_attr($key); ?>" type="<?php echo esc_attr($field[1]); ?>" name="<?php echo esc_attr(self::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo $field[1] === 'password' ? '' : esc_attr((string) $settings[$key]); ?>" <?php echo $field[1] === 'number' ? 'min="1" max="25"' : ''; ?> <?php echo $field[1] === 'password' && !empty($settings[$key]) ? 'autocomplete="new-password" placeholder="Saved — leave blank to keep"' : ''; ?>>
                            <?php if (!empty($field[2])) : ?><p class="description"><?php echo esc_html($field[2]); ?></p><?php endif; ?>
                        </td></tr>
                    <?php endforeach; ?>
                </tbody></table>
                <?php submit_button(__('Save settings', 'sports803-dashboard')); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="s803_run_now">
                <?php wp_nonce_field('s803_run_now'); ?>
                <?php submit_button(__('Run autopilot now', 'sports803-dashboard'), 'secondary', 'submit', false); ?>
            </form>
            <h2><?php echo esc_html__('Latest run', 'sports803-dashboard'); ?></h2>
            <?php if (!empty($last)) : ?>
                <p><?php echo esc_html(isset($last['finished_at']) ? $last['finished_at'] : ''); ?> — <?php echo !empty($last['ok']) ? esc_html__('completed', 'sports803-dashboard') : esc_html__('failed', 'sports803-dashboard'); ?></p>
                <?php if (!empty($last['error'])) : ?><p class="notice notice-error inline"><code><?php echo esc_html($last['error']); ?></code></p><?php endif; ?>
                <?php if (!empty($last['blogs']) && is_array($last['blogs'])) : ?><table class="widefat striped" style="max-width:900px"><thead><tr><th><?php echo esc_html__('Blog ID', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Created', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Updated', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Skipped', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Failed', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Deferred', 'sports803-dashboard'); ?></th></tr></thead><tbody>
                    <?php foreach ($last['blogs'] as $blog) : ?><tr><td><?php echo esc_html($blog['blog_id']); ?></td><td><?php echo esc_html($blog['created']); ?></td><td><?php echo esc_html($blog['updated']); ?></td><td><?php echo esc_html($blog['skipped']); ?></td><td><?php echo esc_html($blog['failed']); ?></td><td><?php echo esc_html($blog['deferred']); ?></td></tr><?php endforeach; ?>
                </tbody></table><?php endif; ?>
            <?php else : ?><p><?php echo esc_html__('No autopilot run has completed yet.', 'sports803-dashboard'); ?></p><?php endif; ?>
            <h2><?php echo esc_html__('Dashboard shortcode', 'sports803-dashboard'); ?></h2>
            <p><code>[sports803_dashboard]</code> <?php echo esc_html__('embeds the current Dashboard in a responsive iframe.', 'sports803-dashboard'); ?></p>
        </div>
        <?php
    }

    public static function dashboard_shortcode($attributes) {
        $attributes = shortcode_atts(array('height' => '900'), $attributes, 'sports803_dashboard');
        $height = absint($attributes['height']);
        $height = max(500, min(2400, $height ?: 900));
        $src = plugins_url('assets/dashboard.html', __FILE__);
        return '<div class="s803-dashboard-embed" style="width:100%;overflow:hidden"><iframe title="Sports 803 Dashboard" loading="lazy" src="' . esc_url($src) . '" style="width:100%;height:' . esc_attr((string) $height) . 'px;border:0;display:block" allow="clipboard-read; clipboard-write; fullscreen" referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
    }
}

final class S803_Blogger_Autoposter {
    const BLOGGER_API = 'https://www.googleapis.com/blogger/v3';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const ESPN_API = 'https://site.api.espn.com/apis/site/v2/sports';
    const PLAYER_BASE = 'https://www.sport803.online/p/player.html';
    const ONEBALL_URL = 'https://oneball.live/list.json';
    const XOILAC_URL = 'https://xoilaczzz.pro/';

    private static function leagues() {
        return array(
            array('ucl','soccer','uefa.champions','Champions League','match'), array('uwcl','soccer','uefa.wchampions',"UEFA Women's Champions League",'match'),
            array('uel','soccer','uefa.europa','Europa League','match'), array('uecl','soccer','uefa.europa.conf','UEFA Conference League','match'),
            array('epl','soccer','eng.1','Premier League','match'), array('laliga','soccer','esp.1','La Liga','match'), array('seriea','soccer','ita.1','Serie A','match'),
            array('bundesliga','soccer','ger.1','Bundesliga','match'), array('ligue1','soccer','fra.1','Ligue 1','match'), array('mls','soccer','usa.1','MLS','match'),
            array('worldcup','soccer','fifa.world','FIFA World Cup','match'), array('euro','soccer','uefa.euro','UEFA Euro','match'), array('afconqual','soccer','caf.nations_qual','Africa Cup of Nations Qualifiers','match'),
            array('euroqual','soccer','uefa.euroq','UEFA Euro Qualifiers','match'), array('concacafnl','soccer','concacaf.nations.league','Concacaf Nations League','match'), array('nations','soccer','uefa.nations','UEFA Nations League','match'),
            array('intlfriendly','soccer','fifa.friendly','Intl Friendlies','match'), array('nba','basketball','nba','NBA','match'), array('wnba','basketball','wnba',"Women's National Basketball Association",'match'),
            array('nfl','football','nfl','NFL','match'), array('nhl','hockey','nhl','NHL','match'), array('f1','racing','f1','Formula 1','race'), array('nascar','racing','nascar-cup-series','NASCAR','race'),
            array('wrc','racing','wrc','WRC','race'), array('imsa','racing','imsa','IMSA','race'), array('porsche-carrera-cup','racing','porsche-carrera-cup','Porsche Carrera Cup','race'),
            array('pga','golf','pga','PGA Tour','race'), array('ufc','mma','ufc','UFC / MMA','match'), array('atp','tennis','atp','ATP Tennis','match'), array('wta','tennis','wta','WTA Tennis','match'),
            array('efl','soccer','eng.2','Championship','match'), array('efltrophy','soccer','eng.trophy','English Football League Trophy','match'), array('spl','soccer','ksa.1','Saudi Pro League','match'),
            array('eredivisie','soccer','ned.1','Eredivisie','match'), array('primeira','soccer','por.1','Primeira Liga','match'), array('facup','soccer','eng.fa','FA Cup','match'),
            array('motogp','racing','motogp','MotoGP','race'), array('cycling','cycling','world','Cycling','race'), array('mlb','baseball','mlb','MLB','match')
        );
    }

    public static function run($settings) {
        $ids = array_values(array_filter(array_map('trim', explode(',', $settings['blog_ids']))));
        if (!$ids || empty($settings['google_client_id']) || empty($settings['google_client_secret']) || empty($settings['refresh_token'])) {
            throw new RuntimeException('Configure Blogger blog IDs and all three Google OAuth fields before enabling autopilot.');
        }
        $token = self::access_token($settings);
        $events = self::fetch_events($settings);
        $feeds = self::load_stream_feeds();
        $events = self::attach_streams($events, $feeds);
        $result = array('ok' => true, 'detected' => count($events), 'blogs' => array());
        foreach ($ids as $blog_id) {
            $posts = self::list_posts($token, $blog_id);
            $by_marker = array();
            foreach ($posts as $post) {
                foreach ((array) (isset($post['labels']) ? $post['labels'] : array()) as $label) {
                    if (strpos($label, 's803:event:') === 0) {
                        $by_marker[$label] = $post;
                    }
                }
            }
            $result['blogs'][] = array_merge(array('blog_id' => $blog_id), self::process_blog($events, $blog_id, $token, $by_marker, $settings));
        }
        foreach ($result['blogs'] as $blog_result) {
            if (!empty($blog_result['failed'])) {
                $result['ok'] = false;
                break;
            }
        }
        return $result;
    }

    private static function access_token($settings) {
        $response = wp_remote_post(self::TOKEN_URL, array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => array('client_id' => $settings['google_client_id'], 'client_secret' => $settings['google_client_secret'], 'refresh_token' => $settings['refresh_token'], 'grant_type' => 'refresh_token'),
        ));
        $body = self::response_json($response, 'Google OAuth token refresh');
        if (empty($body['access_token'])) {
            throw new RuntimeException('Google OAuth returned no access token. Check that the client ID, client secret, and refresh token belong together.');
        }
        return $body['access_token'];
    }

    private static function list_posts($token, $blog_id) {
        $posts = array();
        $page = '';
        do {
            $query = array('maxResults' => 500, 'fetchBodies' => 'false', 'fields' => 'items(id,labels,published),nextPageToken');
            if ($page !== '') { $query['pageToken'] = $page; }
            $url = add_query_arg($query, self::BLOGGER_API . '/blogs/' . rawurlencode($blog_id) . '/posts');
            $data = self::api_json($url, $token, 'GET');
            if (is_wp_error($data)) {
                throw new RuntimeException('Blogger post listing failed for blog ' . $blog_id . ': ' . $data->get_error_message());
            }
            if (!empty($data['items']) && is_array($data['items'])) {
                $posts = array_merge($posts, $data['items']);
            }
            $page = isset($data['nextPageToken']) ? (string) $data['nextPageToken'] : '';
        } while ($page !== '');
        return $posts;
    }

    private static function write_post($token, $blog_id, $existing, $payload) {
        $url = self::BLOGGER_API . '/blogs/' . rawurlencode($blog_id) . '/posts' . ($existing ? '/' . rawurlencode($existing['id']) : '');
        $method = $existing ? 'PUT' : 'POST';
        return self::api_json($url, $token, $method, $payload);
    }

    private static function api_json($url, $token, $method, $body = null) {
        $args = array('method' => $method, 'timeout' => 15, 'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'));
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            $message = !empty($data['error']['message']) ? $data['error']['message'] : 'HTTP ' . $status;
            return new WP_Error($status === 429 ? 's803_rate_limit' : 's803_http_error', substr($message, 0, 500), array('status' => $status));
        }
        return is_array($data) ? $data : array();
    }

    private static function response_json($response, $label) {
        if (is_wp_error($response)) {
            throw new RuntimeException($label . ' failed: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($data) && !empty($data['error_description']) ? $data['error_description'] : 'HTTP ' . $status;
            throw new RuntimeException($label . ' failed: ' . substr($detail, 0, 400));
        }
        return is_array($data) ? $data : array();
    }

    private static function fetch_events($settings) {
        $timezone = new DateTimeZone($settings['timezone'] ?: 'Africa/Nairobi');
        $today = new DateTimeImmutable('now', $timezone);
        $dates = array($today->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d'));
        $filter = array_filter(array_map('strtolower', array_map('trim', explode(',', $settings['league_filter']))));
        $events = array();
        foreach (self::leagues() as $row) {
            list($id, $sport, $slug, $name, $type) = $row;
            if ($filter && !in_array(strtolower($id), $filter, true) && !in_array(strtolower($name), $filter, true)) {
                continue;
            }
            $league = array('id' => $id, 'sport' => $sport, 'slug' => $slug, 'name' => $name, 'type' => $type);
            $league_events = array();
            foreach ($dates as $date) {
                $url = self::ESPN_API . '/' . rawurlencode($sport) . '/' . rawurlencode($slug) . '/scoreboard?dates=' . str_replace('-', '', $date);
                $data = self::public_json($url, 8);
                if (is_wp_error($data) || empty($data['events']) || !is_array($data['events'])) {
                    continue;
                }
                foreach ($data['events'] as $raw) {
                    $event = self::parse_espn_event($raw, $league);
                    if (!$event || !self::eligible($event, $dates, $timezone)) {
                        continue;
                    }
                    $league_events[$event['key']] = $event;
                }
            }
            if (!$league_events && $type === 'race') {
                foreach ($dates as $date) {
                    $data = self::public_json('https://www.thesportsdb.com/api/v1/json/3/eventsday.php?d=' . rawurlencode($date) . '&s=Motorsport', 8);
                    if (is_wp_error($data) || empty($data['events']) || !is_array($data['events'])) {
                        continue;
                    }
                    foreach ($data['events'] as $raw) {
                        $league_name = isset($raw['strLeague']) ? strtolower($raw['strLeague']) : '';
                        if (strpos($league_name, strtolower($name)) === false && !($id === 'wrc' && strpos($league_name, 'rally') !== false)) {
                            continue;
                        }
                        $event = self::parse_tsdb_race($raw, $league);
                        if ($event && self::eligible($event, $dates, $timezone)) {
                            $league_events[$event['key']] = $event;
                        }
                    }
                }
            }
            $events = array_merge($events, array_values($league_events));
        }
        usort($events, function ($a, $b) { return strcmp($a['start_time'], $b['start_time']); });
        return $events;
    }

    private static function public_json($url, $timeout) {
        $response = wp_remote_get($url, array('timeout' => $timeout, 'redirection' => 3, 'headers' => array('Accept' => 'application/json', 'User-Agent' => 'Sports803-WordPress-Autopilot/1.0')));
        if (is_wp_error($response)) {
            return $response;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return new WP_Error('s803_feed_http', 'Feed returned HTTP ' . $status);
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : array();
    }

    private static function parse_espn_event($raw, $league) {
        if (empty($raw['id']) || empty($raw['date'])) {
            return null;
        }
        $competition = !empty($raw['competitions'][0]) ? $raw['competitions'][0] : array();
        $competitors = !empty($competition['competitors']) && is_array($competition['competitors']) ? $competition['competitors'] : array();
        $home = array(); $away = array();
        foreach ($competitors as $competitor) {
            if (isset($competitor['homeAway']) && $competitor['homeAway'] === 'home') { $home = $competitor; }
            if (isset($competitor['homeAway']) && $competitor['homeAway'] === 'away') { $away = $competitor; }
        }
        if (!$home && isset($competitors[0])) { $home = $competitors[0]; }
        if (!$away && isset($competitors[1])) { $away = $competitors[1]; }
        $team = function ($item) {
            $data = isset($item['team']) && is_array($item['team']) ? $item['team'] : array();
            return array('name' => isset($data['displayName']) ? $data['displayName'] : (isset($data['shortDisplayName']) ? $data['shortDisplayName'] : 'TBD'), 'logo' => isset($data['logo']) ? $data['logo'] : '', 'score' => isset($item['score']) ? (string) $item['score'] : '');
        };
        $status = isset($raw['status']['type']['description']) ? $raw['status']['type']['description'] : 'Scheduled';
        $home_team = $team($home); $away_team = $team($away);
        $name = isset($raw['name']) ? $raw['name'] : (isset($raw['shortName']) ? $raw['shortName'] : $league['name'] . ' event');
        $key = $league['id'] . '|' . (string) $raw['id'];
        return array('id' => (string) $raw['id'], 'key' => $key, 'league' => $league, 'type' => $league['type'], 'name' => $name, 'home' => $home_team, 'away' => $away_team, 'status' => $status, 'start_time' => $raw['date'], 'end_time' => isset($raw['endDate']) ? $raw['endDate'] : '', 'score' => trim($home_team['score'] . ' - ' . $away_team['score'], ' -'), 'source_url' => self::ESPN_API . '/' . $league['sport'] . '/' . $league['slug'] . '/summary?event=' . rawurlencode((string) $raw['id']));
    }

    private static function parse_tsdb_race($raw, $league) {
        $id = isset($raw['idEvent']) ? (string) $raw['idEvent'] : '';
        if (!$id) { return null; }
        $date = isset($raw['strTimestamp']) && $raw['strTimestamp'] ? gmdate('c', strtotime($raw['strTimestamp'])) : (isset($raw['dateEvent']) ? $raw['dateEvent'] . 'T' . (isset($raw['strTime']) ? $raw['strTime'] : '12:00:00') . 'Z' : '');
        return array('id' => $id, 'key' => $league['id'] . '|' . $id, 'league' => $league, 'type' => 'race', 'name' => isset($raw['strEvent']) ? $raw['strEvent'] : $league['name'] . ' event', 'home' => array('name' => '', 'logo' => '', 'score' => ''), 'away' => array('name' => '', 'logo' => '', 'score' => ''), 'status' => isset($raw['strStatus']) ? $raw['strStatus'] : 'Scheduled', 'start_time' => $date, 'end_time' => '', 'score' => '', 'source_url' => '');
    }

    private static function eligible($event, $dates, $timezone) {
        if (empty($event['start_time'])) { return false; }
        if (preg_match('/cancelled|canceled|postponed/i', $event['status'])) { return false; }
        try {
            $date = (new DateTimeImmutable($event['start_time']))->setTimezone($timezone)->format('Y-m-d');
            return in_array($date, $dates, true);
        } catch (Exception $e) {
            return false;
        }
    }

    private static function load_stream_feeds() {
        $feeds = array('oneball' => array(), 'xoilac' => array());
        $one = self::public_json(self::ONEBALL_URL, 8);
        if (!is_wp_error($one)) {
            $entries = isset($one['matches']) ? $one['matches'] : (isset($one['data']) ? $one['data'] : (isset($one['list']) ? $one['list'] : $one));
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if (!is_array($entry)) { continue; }
                    $id = isset($entry['nami_id']) ? $entry['nami_id'] : (isset($entry['id']) ? $entry['id'] : '');
                    $home = isset($entry['home_team']) ? $entry['home_team'] : (isset($entry['home_team_name']) ? $entry['home_team_name'] : (isset($entry['home']['name']) ? $entry['home']['name'] : (isset($entry['home']) ? $entry['home'] : '')));
                    $away = isset($entry['away_team']) ? $entry['away_team'] : (isset($entry['away_team_name']) ? $entry['away_team_name'] : (isset($entry['away']['name']) ? $entry['away']['name'] : (isset($entry['away']) ? $entry['away'] : '')));
                    if ($id && is_string($home) && is_string($away)) { $feeds['oneball'][] = array('id' => (string) $id, 'home' => $home, 'away' => $away); }
                }
            }
        }
        $html = self::public_text(self::XOILAC_URL, 8);
        if (!is_wp_error($html) && preg_match_all('/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $parts = wp_parse_url($url);
                if (!$parts || (isset($parts['host']) && !in_array(strtolower($parts['host']), array('xoilaczzz.pro', 'www.xoilaczzz.pro'), true))) { continue; }
                $path = isset($parts['path']) ? $parts['path'] : '';
                if (!preg_match('~^/truc-tiep/([^/]*-vs?-.*)/?$~i', $path, $slug)) { continue; }
                $teams = preg_split('/-vs?-/', preg_replace('/-luc-.*$/i', '', $slug[1]));
                if (count($teams) !== 2) { continue; }
                $feeds['xoilac'][] = array('url' => 'https://xoilaczzz.pro' . rtrim($path, '/') . '/', 'home' => ucwords(str_replace('-', ' ', $teams[0])), 'away' => ucwords(str_replace('-', ' ', $teams[1])));
            }
        }
        return $feeds;
    }

    private static function public_text($url, $timeout) {
        $response = wp_remote_get($url, array('timeout' => $timeout, 'redirection' => 3, 'headers' => array('Accept' => 'text/html', 'User-Agent' => 'Sports803-WordPress-Autopilot/1.0')));
        if (is_wp_error($response)) { return $response; }
        $status = (int) wp_remote_retrieve_response_code($response);
        return ($status >= 200 && $status < 300) ? wp_remote_retrieve_body($response) : new WP_Error('s803_feed_http', 'Stream source returned HTTP ' . $status);
    }

    private static function attach_streams($events, $feeds) {
        foreach ($events as &$event) {
            $event['oneball_id'] = '';
            $event['xoilac_url'] = '';
            if ($event['type'] !== 'match') { continue; }
            foreach ($feeds['oneball'] as $entry) {
                if (self::pair_match($event, $entry)) { $event['oneball_id'] = $entry['id']; break; }
            }
            foreach ($feeds['xoilac'] as $entry) {
                if (self::pair_match($event, $entry)) { $event['xoilac_url'] = $entry['url']; break; }
            }
        }
        unset($event);
        return $events;
    }

    private static function team_key($value) {
        $value = remove_accents(strtolower((string) $value));
        $value = preg_replace('/\b(fc|afc|rfc|sc|cf|ac|as|bsc|rcd|rc|vfb|vfl|real|deportivo|athletic|clube|club|city|united|town|rovers|wanderers|albion|hotspur|villa|forest|palace|wolves|celtic|rangers)\b/', '', $value);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', ' ', $value)));
    }

    private static function team_match($left, $right) {
        $a = self::team_key($left); $b = self::team_key($right);
        if (!$a || !$b) { return false; }
        if ($a === $b || strpos($a, $b) !== false || strpos($b, $a) !== false) { return true; }
        $at = array_filter(explode(' ', $a), function ($token) { return strlen($token) > 3; });
        $bt = array_filter(explode(' ', $b), function ($token) { return strlen($token) > 3; });
        return ($at && count(array_filter($at, function ($token) use ($b) { return strpos($b, $token) !== false; })) === count($at)) || ($bt && count(array_filter($bt, function ($token) use ($a) { return strpos($a, $token) !== false; })) === count($bt));
    }

    private static function pair_match($event, $feed) {
        $home = isset($event['home']['name']) ? $event['home']['name'] : '';
        $away = isset($event['away']['name']) ? $event['away']['name'] : '';
        return (self::team_match($home, $feed['home']) && self::team_match($away, $feed['away'])) || (self::team_match($home, $feed['away']) && self::team_match($away, $feed['home']));
    }

    private static function process_blog($events, $blog_id, $token, $by_marker, $settings) {
        $counts = array('created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'deferred' => 0);
        $limit = max(1, min(25, absint($settings['max_writes'])));
        foreach ($events as $index => $event) {
            if ($counts['created'] + $counts['updated'] >= $limit) {
                $counts['deferred'] += count($events) - $index;
                break;
            }
            $marker = 's803:event:' . self::stable_hash($event['key']);
            $existing = isset($by_marker[$marker]) ? $by_marker[$marker] : null;
            $final = self::is_final($event);
            $labels = $existing && isset($existing['labels']) ? (array) $existing['labels'] : array();
            if ($existing && (!$final || in_array('Highlights', $labels, true))) {
                $counts['skipped']++;
                continue;
            }
            $article = self::article($event, $final);
            $thumbnail = self::upload_thumbnail($event, $settings);
            if ($thumbnail) {
                $article = '<div><img src="' . esc_url($thumbnail) . '" style="max-width:100%;height:auto;border-radius:10px;margin-bottom:18px" alt="' . esc_attr(self::title($event, $final)) . '"></div>' . $article;
            }
            $post_labels = array($marker, $final ? 'Highlights' : 'Event Stream');
            if ($thumbnail) { $post_labels[] = 's803:thumbnail'; }
            $post_labels[] = $event['league']['name'];
            $post_labels[] = $event['league']['id'];
            $post_labels[] = 'Sports 803';
            $post_labels = array_slice(array_values(array_unique($post_labels)), 0, 20);
            $payload = array(
                'kind' => 'blogger#post',
                'blog' => array('id' => $blog_id),
                'title' => self::title($event, $final),
                'content' => $article,
                'labels' => $post_labels,
                'searchDescription' => substr(self::event_name($event) . ($final ? ' highlights and replay' : ' live stream') . ' on Sports 803', 0, 150),
            );
            if ($existing) {
                $payload['id'] = $existing['id'];
                if (!empty($existing['published'])) { $payload['published'] = $existing['published']; }
            }
            $written = self::write_post($token, $blog_id, $existing, $payload);
            if (is_wp_error($written)) {
                $data = $written->get_error_data();
                $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 0;
                if ($status === 429 || $written->get_error_code() === 's803_rate_limit') {
                    $counts['deferred'] += count($events) - $index;
                    break;
                }
                $counts['failed']++;
                continue;
            }
            if ($existing) { $counts['updated']++; } else { $counts['created']++; }
            $by_marker[$marker] = $written;
            usleep(200000);
        }
        return $counts;
    }

    private static function is_final($event) {
        return (bool) preg_match('/final|finished|completed|ended|full.?time|\bft\b|post.?match/i', (string) $event['status']);
    }

    private static function title($event, $final) {
        if ($event['type'] === 'race') {
            return $event['league']['name'] . ($final ? ' – Full Race Replay | Sports 803' : ' – Race Preview & Live Stream | Sports 803');
        }
        $fixture = self::event_name($event);
        return $fixture . ($final ? ' – Highlights & Replay | Sports 803' : ' – ' . $event['league']['name'] . ' Live Stream | Sports 803');
    }

    private static function event_name($event) {
        if ($event['type'] === 'race') { return $event['name']; }
        return $event['home']['name'] . ' vs ' . $event['away']['name'];
    }

    private static function article($event, $final) {
        $esc = function ($value) { return esc_html((string) $value); };
        $name = $esc(self::event_name($event));
        $league = $esc($event['league']['name']);
        $kickoff = '';
        try { $kickoff = $esc(wp_date('D, M j, Y H:i T', strtotime($event['start_time']))); } catch (Exception $e) { $kickoff = $esc($event['start_time']); }
        if ($event['type'] === 'race') {
            $body = '<h2>' . $league . ' ' . ($final ? 'Race Report' : 'Race Preview') . '</h2><p>This article records the available event context and keeps verified race details separate from speculation.</p><h2>Race Details</h2><p><strong>Event:</strong> ' . $name . '<br><strong>Scheduled:</strong> ' . $kickoff . '</p><h2>How to Watch</h2><p>Use an authorized broadcaster or licensed replay in your region.</p>';
        } elseif ($final) {
            $score = $esc($event['score']);
            $body = '<h2>Match Report</h2><p>' . $name . ' finished in ' . $league . ($score ? ' with a recorded score of <strong>' . $score . '</strong>.' : '. The match report will be updated when verified details are available.') . '</p><h2>Match Details</h2><p><strong>Teams:</strong> ' . $name . '<br><strong>Competition:</strong> ' . $league . '<br><strong>Scheduled:</strong> ' . $kickoff . '</p><h2>How to Watch</h2><p>Use an authorized broadcaster or licensed replay in your region.</p>';
        } else {
            $body = '<h2>Match Preview</h2><p>' . $name . ' meet in ' . $league . '. This preview records the scheduled context and avoids presenting unverified team news as fact.</p><h2>Match Details</h2><p><strong>Teams:</strong> ' . $name . '<br><strong>Competition:</strong> ' . $league . '<br><strong>Kickoff:</strong> ' . $kickoff . '</p><h2>How to Watch</h2><p>Check an authorized broadcaster or licensed player in your region.</p>';
        }
        $player = self::player_url($event, $final);
        if ($player) {
            $body .= '<div style="margin:18px 0;position:relative;padding-bottom:56.25%;height:0;overflow:hidden"><iframe loading="lazy" allow="encrypted-media" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" src="' . esc_url($player) . '" frameborder="0" scrolling="no" allowfullscreen></iframe></div>';
        }
        return $body;
    }

    private static function player_url($event, $final) {
        $params = array();
        if (!empty($event['oneball_id'])) {
            $route = $final ? 'replay' : 'live';
            $params[] = 'one=https://oneball.live/' . $route . '/' . rawurlencode((string) preg_replace('/\.html?$/i', '', $event['oneball_id'])) . '.htm';
        }
        if (!empty($event['xoilac_url'])) {
            $params[] = 'xoilac=' . $event['xoilac_url'];
        }
        return $params ? self::PLAYER_BASE . '?' . implode('&', $params) : '';
    }

    private static function upload_thumbnail($event, $settings) {
        if (empty($settings['imgbb_api_key']) || !function_exists('imagecreatetruecolor') || !function_exists('imagepng')) { return ''; }
        $image = imagecreatetruecolor(1200, 630);
        if (!$image) { return ''; }
        $background = imagecolorallocate($image, 7, 8, 12);
        $accent = imagecolorallocate($image, 230, 57, 70);
        $text = imagecolorallocate($image, 226, 232, 244);
        imagefill($image, 0, 0, $background);
        imagefilledrectangle($image, 0, 0, 1199, 14, $accent);
        imagestring($image, 5, 48, 55, 'SPORTS 803', $accent);
        $title = substr(preg_replace('/[^\x20-\x7E]/', ' ', self::event_name($event)), 0, 58);
        imagestring($image, 5, 48, 245, $title, $text);
        imagestring($image, 4, 48, 315, strtoupper(substr($event['league']['name'], 0, 40)), $accent);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        if (!$png) { return ''; }
        $response = wp_remote_post('https://api.imgbb.com/1/upload', array('timeout' => 15, 'body' => array('key' => $settings['imgbb_api_key'], 'image' => base64_encode($png), 'type' => 'base64')));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 300) { return ''; }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return !empty($data['data']['url']) ? esc_url_raw($data['data']['url']) : '';
    }

    private static function stable_hash($value) {
        // FNV-1a 32-bit, matching the existing Node worker's marker format for ASCII event keys.
        $hash = 2166136261;
        $bytes = unpack('C*', (string) $value);
        foreach ($bytes as $byte) {
            $hash = (($hash ^ $byte) * 16777619) & 0xFFFFFFFF;
        }
        return sprintf('%08x', $hash);
    }
}

S803_Dashboard_Plugin::init();
register_activation_hook(__FILE__, array('S803_Dashboard_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('S803_Dashboard_Plugin', 'deactivate'));
