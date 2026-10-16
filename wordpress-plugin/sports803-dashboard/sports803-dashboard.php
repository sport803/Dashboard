<?php
/**
 * Plugin Name: Sports 803 Dashboard & Autopilot
 * Description: Professional Sports 803 event dashboard and native WordPress autopilot for live events, scores, thumbnails and verified replays.
 * Version: 2.2.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Sports 803
 * License: GPL-2.0-or-later
 * Text Domain: sports803-dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}

define('S803_PLUGIN_VERSION', '2.2.1');
define('S803_PLUGIN_FILE', __FILE__);
define('S803_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once S803_PLUGIN_DIR . 'includes/class-s803-providers.php';
require_once S803_PLUGIN_DIR . 'includes/class-s803-source-providers.php';
require_once S803_PLUGIN_DIR . 'includes/class-s803-thumbnail.php';
require_once S803_PLUGIN_DIR . 'includes/class-s803-engine.php';
require_once S803_PLUGIN_DIR . 'includes/class-s803-admin.php';

final class S803_Dashboard_Plugin {
    const OPTION = 's803_autopilot_settings';
    const LAST_RUN = 's803_autopilot_last_run';
    const LOG_OPTION = 's803_autopilot_activity';
    const CRON_HOOK = 's803_autopilot_tick';
    const CRON_INTERVAL = 's803_every_five_minutes';

    public static function defaults() {
        return array(
            'autopilot_enabled' => 0,
            'timezone' => 'Africa/Nairobi',
            'date_window_days' => 3,
            'league_filter' => '',
            'leagues_per_run' => 10,
            'schedule_at_kickoff' => 1,
            'live_score_sync' => 1,
            'auto_highlights' => 1,
            'oneball_provider' => 1,
            'xoilac_provider' => 1,
            'livetv_provider' => 1,
            'max_highlight_attempts' => 5,
            'jobs_per_run' => 10,
        );
    }

    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action('init', array(__CLASS__, 'load_textdomain'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'scheduled_run'));
        add_action('update_option_' . self::OPTION, array(__CLASS__, 'settings_changed'), 10, 2);
        add_action('add_option_' . self::OPTION, array(__CLASS__, 'settings_added'), 10, 2);
        add_action('admin_post_s803_run_now', array(__CLASS__, 'manual_run'));
        add_action('admin_post_s803_retry_highlight', array(__CLASS__, 'retry_highlight'));
        add_shortcode('sports803_dashboard', array(__CLASS__, 'dashboard_shortcode'));
        S803_Dashboard_Admin::init();
    }

    public static function load_textdomain() {
        load_plugin_textdomain('sports803-dashboard', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public static function defaults_for_cron() {
        return self::defaults();
    }

    public static function settings() {
        return wp_parse_args(get_option(self::OPTION, array()), self::defaults());
    }

    public static function sanitize_settings($input) {
        $old = self::settings();
        $input = is_array($input) ? $input : array();
        $timezone = isset($input['timezone']) ? sanitize_text_field($input['timezone']) : $old['timezone'];
        try {
            new DateTimeZone($timezone);
        } catch (Exception $error) {
            $timezone = 'Africa/Nairobi';
        }
        return array(
            'autopilot_enabled' => !empty($input['autopilot_enabled']) ? 1 : 0,
            'timezone' => $timezone,
            'date_window_days' => max(1, min(14, absint(isset($input['date_window_days']) ? $input['date_window_days'] : $old['date_window_days']))),
            'league_filter' => isset($input['league_filter']) ? sanitize_text_field($input['league_filter']) : $old['league_filter'],
            'leagues_per_run' => max(1, min(40, absint(isset($input['leagues_per_run']) ? $input['leagues_per_run'] : $old['leagues_per_run']))),
            'schedule_at_kickoff' => !empty($input['schedule_at_kickoff']) ? 1 : 0,
            'live_score_sync' => !empty($input['live_score_sync']) ? 1 : 0,
            'auto_highlights' => !empty($input['auto_highlights']) ? 1 : 0,
            'oneball_provider' => !empty($input['oneball_provider']) ? 1 : 0,
            'xoilac_provider' => !empty($input['xoilac_provider']) ? 1 : 0,
            'livetv_provider' => !empty($input['livetv_provider']) ? 1 : 0,
            'max_highlight_attempts' => max(1, min(10, absint(isset($input['max_highlight_attempts']) ? $input['max_highlight_attempts'] : $old['max_highlight_attempts']))),
            'jobs_per_run' => max(1, min(50, absint(isset($input['jobs_per_run']) ? $input['jobs_per_run'] : $old['jobs_per_run']))),
        );
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

    public static function settings_changed($old_value, $new_value) {
        self::sync_schedule();
    }

    public static function settings_added($option, $value) {
        self::sync_schedule();
    }

    private static function sync_schedule() {
        $settings = self::settings();
        if (!empty($settings['autopilot_enabled'])) {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + 60, self::CRON_INTERVAL, self::CRON_HOOK);
            }
        } else {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    public static function scheduled_run() {
        if (!empty(self::settings()['autopilot_enabled'])) {
            self::run('cron');
        }
    }

    public static function run($source) {
        try {
            $result = S803_Autopilot_Engine::run(self::settings());
            if (is_wp_error($result)) { return $result; }
            $result['source'] = $source;
            $result['finished_at'] = gmdate('c');
            update_option(self::LAST_RUN, $result, false);
            return $result;
        } catch (Throwable $error) {
            $result = array('ok' => false, 'source' => $source, 'finished_at' => gmdate('c'), 'error' => substr($error->getMessage(), 0, 700));
            update_option(self::LAST_RUN, $result, false);
            S803_Autopilot_Engine::log('error', 0, 'Autopilot run failed: ' . $result['error']);
            return new WP_Error('s803_run_failed', $result['error']);
        }
    }

    public static function manual_run() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to run this task.', 'sports803-dashboard'));
        }
        check_admin_referer('s803_run_now');
        $result = self::run('manual');
        $state = is_wp_error($result) || empty($result['ok']) ? 'failed' : 'complete';
        wp_safe_redirect(add_query_arg(array('page' => 's803-dashboard', 's803_run' => $state), admin_url('options-general.php')));
        exit;
    }

    public static function retry_highlight() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to retry this task.', 'sports803-dashboard'));
        }
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        check_admin_referer('s803_retry_highlight_' . $post_id);
        $ok = S803_Autopilot_Engine::retry_highlight($post_id);
        wp_safe_redirect(add_query_arg(array('page' => 's803-dashboard', 's803_retry' => $ok ? 'queued' : 'failed'), admin_url('options-general.php')));
        exit;
    }

    public static function dashboard_shortcode($attributes) {
        $attributes = shortcode_atts(array('limit' => '50'), $attributes, 'sports803_dashboard');
        return S803_Autopilot_Engine::public_dashboard(max(1, min(100, absint($attributes['limit']) ?: 50)));
    }
}

S803_Dashboard_Plugin::init();
register_activation_hook(__FILE__, array('S803_Dashboard_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('S803_Dashboard_Plugin', 'deactivate'));
