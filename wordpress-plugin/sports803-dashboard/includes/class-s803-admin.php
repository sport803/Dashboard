<?php
if (!defined('ABSPATH')) { exit; }

final class S803_Dashboard_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
    }

    public static function menu() {
        add_options_page(__('Sports 803 Autopilot', 'sports803-dashboard'), __('Sports 803 Autopilot', 'sports803-dashboard'), 'manage_options', 's803-dashboard', array(__CLASS__, 'render'));
    }

    public static function register_settings() {
        register_setting('s803_autopilot_group', S803_Dashboard_Plugin::OPTION, array('type' => 'array', 'sanitize_callback' => array('S803_Dashboard_Plugin', 'sanitize_settings'), 'default' => S803_Dashboard_Plugin::defaults()));
    }

    public static function render() {
        if (!current_user_can('manage_options')) { return; }
        $settings = S803_Dashboard_Plugin::settings();
        $last = get_option(S803_Dashboard_Plugin::LAST_RUN, array());
        $next = wp_next_scheduled(S803_Dashboard_Plugin::CRON_HOOK);
        $counts = S803_Autopilot_Engine::counts();
        $logs = get_option(S803_Dashboard_Plugin::LOG_OPTION, array());
        $review = S803_Autopilot_Engine::review_posts();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Sports 803 Dashboard & Autopilot', 'sports803-dashboard'); ?></h1>
            <p><?php echo esc_html__('Autopilot creates, publishes, and manages native WordPress posts.', 'sports803-dashboard'); ?></p>
            <?php if (isset($_GET['s803_run'])) : ?>
                <div class="notice notice-<?php echo sanitize_key($_GET['s803_run']) === 'complete' ? 'success' : 'error'; ?> is-dismissible"><p><?php echo sanitize_key($_GET['s803_run']) === 'complete' ? esc_html__('Autopilot run completed; details are below.', 'sports803-dashboard') : esc_html__('Autopilot run failed or another run is active; see the latest run details.', 'sports803-dashboard'); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['s803_retry'])) : ?>
                <div class="notice notice-<?php echo sanitize_key($_GET['s803_retry']) === 'queued' ? 'success' : 'error'; ?> is-dismissible"><p><?php echo sanitize_key($_GET['s803_retry']) === 'queued' ? esc_html__('Highlight retry queued.', 'sports803-dashboard') : esc_html__('Could not queue that retry.', 'sports803-dashboard'); ?></p></div>
            <?php endif; ?>
            <h2><?php echo esc_html__('Autopilot status', 'sports803-dashboard'); ?></h2>
            <p><strong><?php echo esc_html__('Mode:', 'sports803-dashboard'); ?></strong> <?php echo !empty($settings['autopilot_enabled']) && $next ? esc_html__('Enabled — WordPress checks every five minutes', 'sports803-dashboard') : esc_html__('Disabled', 'sports803-dashboard'); ?></p>
            <p><strong><?php echo esc_html__('Next check:', 'sports803-dashboard'); ?></strong> <?php echo $next ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next)) : esc_html__('Not scheduled', 'sports803-dashboard'); ?></p>
            <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html__('DISABLE_WP_CRON is enabled. Configure your hosting/server cron to request wp-cron.php every five minutes.', 'sports803-dashboard'); ?></p></div>
            <?php else : ?>
                <p class="description"><?php echo esc_html__('WP-Cron is traffic-triggered and can run late on a quiet site. For dependable five-minute timing, configure a hosting/server cron to request wp-cron.php every five minutes.', 'sports803-dashboard'); ?></p>
            <?php endif; ?>
            <div style="display:flex;gap:18px;flex-wrap:wrap;margin:12px 0">
                <span><strong><?php echo esc_html__('Managed posts:', 'sports803-dashboard'); ?></strong> <?php echo esc_html((string) $counts['managed']); ?></span>
                <span><strong><?php echo esc_html__('Highlights queued:', 'sports803-dashboard'); ?></strong> <?php echo esc_html((string) ($counts['queued'] + $counts['retry'])); ?></span>
                <span><strong><?php echo esc_html__('Completed:', 'sports803-dashboard'); ?></strong> <?php echo esc_html((string) $counts['complete']); ?></span>
                <span><strong><?php echo esc_html__('Needs review:', 'sports803-dashboard'); ?></strong> <?php echo esc_html((string) $counts['needs_review']); ?></span>
            </div>
            <form method="post" action="options.php">
                <?php settings_fields('s803_autopilot_group'); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row"><?php echo esc_html__('Autopilot', 'sports803-dashboard'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(S803_Dashboard_Plugin::OPTION); ?>[autopilot_enabled]" value="1" <?php checked(!empty($settings['autopilot_enabled'])); ?>> <?php echo esc_html__('Enable the five-minute event manager', 'sports803-dashboard'); ?></label></td></tr>
                    <?php self::text_field('timezone', __('Event timezone', 'sports803-dashboard'), __('IANA timezone, for example Africa/Nairobi.', 'sports803-dashboard'), $settings, 'text'); ?>
                    <?php self::text_field('date_window_days', __('Upcoming date window (days)', 'sports803-dashboard'), __('Discovery includes today and the following configured number of calendar days (1–14).', 'sports803-dashboard'), $settings, 'number', 1, 14); ?>
                    <?php self::text_field('league_filter', __('League filter', 'sports803-dashboard'), __('Comma-separated league IDs, e.g. epl,nba,f1. Blank means all supported leagues.', 'sports803-dashboard'), $settings, 'text'); ?>
                    <?php self::text_field('leagues_per_run', __('Leagues scanned per check', 'sports803-dashboard'), __('The catalog rotates across five-minute checks to keep each WordPress Cron request bounded (1–40).', 'sports803-dashboard'), $settings, 'number', 1, 40); ?>
                    <tr><th scope="row"><?php echo esc_html__('Publish timing', 'sports803-dashboard'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(S803_Dashboard_Plugin::OPTION); ?>[schedule_at_kickoff]" value="1" <?php checked(!empty($settings['schedule_at_kickoff'])); ?>> <?php echo esc_html__('Schedule new posts for kickoff; reschedule the same post if kickoff changes', 'sports803-dashboard'); ?></label></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Live scores', 'sports803-dashboard'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(S803_Dashboard_Plugin::OPTION); ?>[live_score_sync]" value="1" <?php checked(!empty($settings['live_score_sync'])); ?>> <?php echo esc_html__('Check ESPN summaries and update LIVE / FULL TIME score banners', 'sports803-dashboard'); ?></label></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Automatic highlights', 'sports803-dashboard'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(S803_Dashboard_Plugin::OPTION); ?>[auto_highlights]" value="1" <?php checked(!empty($settings['auto_highlights'])); ?>> <?php echo esc_html__('Queue finished matches and update the existing post when a replay is verified', 'sports803-dashboard'); ?></label></td></tr>
                    <tr><th scope="row"><?php echo esc_html__('Highlight providers', 'sports803-dashboard'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(S803_Dashboard_Plugin::OPTION); ?>[oneball_provider]" value="1" <?php checked(!empty($settings['oneball_provider'])); ?>> <?php echo esc_html__('OneBall replay provider', 'sports803-dashboard'); ?></label><p class="description"><?php echo esc_html__('Provider interface supports additional highlight sources; OneBall is the provider currently configured.', 'sports803-dashboard'); ?></p></td></tr>
                    <?php self::text_field('max_highlight_attempts', __('Highlight attempts before review', 'sports803-dashboard'), __('After this many unsuccessful searches, the job is marked Needs review (1–10).', 'sports803-dashboard'), $settings, 'number', 1, 10); ?>
                    <?php self::text_field('jobs_per_run', __('Score/highlight jobs per check', 'sports803-dashboard'), __('Upper bound for summary checks and highlight jobs per cron run (1–50).', 'sports803-dashboard'), $settings, 'number', 1, 50); ?>
                </tbody></table>
                <?php submit_button(__('Save settings', 'sports803-dashboard')); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="s803_run_now"><?php wp_nonce_field('s803_run_now'); ?>
                <?php submit_button(__('Run autopilot now', 'sports803-dashboard'), 'secondary', 'submit', false); ?>
            </form>
            <h2><?php echo esc_html__('Latest run', 'sports803-dashboard'); ?></h2>
            <?php if (!empty($last)) : ?>
                <p><?php echo esc_html(isset($last['finished_at']) ? $last['finished_at'] : ''); ?> — <?php echo !empty($last['ok']) ? esc_html__('completed', 'sports803-dashboard') : esc_html__('completed with errors', 'sports803-dashboard'); ?></p>
                <p><?php echo esc_html(sprintf('Discovered %d · created %d · updated %d · drafted %d · score updates %d · highlights %d · retries %d · needs review %d · errors %d', $last['discovered'] ?? 0, $last['created'] ?? 0, $last['updated'] ?? 0, $last['drafted'] ?? 0, $last['score_updates'] ?? 0, $last['highlights_completed'] ?? 0, $last['highlight_retries'] ?? 0, $last['needs_review'] ?? 0, $last['errors'] ?? 0)); ?></p>
                <?php if (!empty($last['error'])) : ?><p><code><?php echo esc_html($last['error']); ?></code></p><?php endif; ?>
            <?php else : ?><p><?php echo esc_html__('No autopilot run has completed yet.', 'sports803-dashboard'); ?></p><?php endif; ?>
            <h2><?php echo esc_html__('Highlight jobs needing review', 'sports803-dashboard'); ?></h2>
            <?php if ($review) : ?><table class="widefat striped" style="max-width:1100px"><thead><tr><th><?php echo esc_html__('Post', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Attempts', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Last error', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Action', 'sports803-dashboard'); ?></th></tr></thead><tbody>
                <?php foreach ($review as $post) : ?><tr><td><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php echo esc_html(get_the_title($post)); ?></a></td><td><?php echo esc_html((string) get_post_meta($post->ID, '_s803_highlight_attempts', true)); ?></td><td><?php echo esc_html((string) get_post_meta($post->ID, '_s803_highlight_last_error', true)); ?></td><td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="s803_retry_highlight"><input type="hidden" name="post_id" value="<?php echo esc_attr((string) $post->ID); ?>"><?php wp_nonce_field('s803_retry_highlight_' . $post->ID); ?><button class="button" type="submit"><?php echo esc_html__('Retry', 'sports803-dashboard'); ?></button></form></td></tr><?php endforeach; ?>
            </tbody></table><?php else : ?><p><?php echo esc_html__('No highlight jobs currently need review.', 'sports803-dashboard'); ?></p><?php endif; ?>
            <h2><?php echo esc_html__('Recent activity', 'sports803-dashboard'); ?></h2>
            <?php if (is_array($logs) && $logs) : ?><table class="widefat striped" style="max-width:1100px"><thead><tr><th><?php echo esc_html__('Time (UTC)', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Level', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Post', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Activity', 'sports803-dashboard'); ?></th></tr></thead><tbody>
                <?php foreach (array_slice($logs, 0, 40) as $entry) : ?><tr><td><?php echo esc_html($entry['at'] ?? ''); ?></td><td><?php echo esc_html(strtoupper($entry['level'] ?? 'info')); ?></td><td><?php if (!empty($entry['post_id'])) : ?><a href="<?php echo esc_url(get_edit_post_link((int) $entry['post_id'])); ?>"><?php echo esc_html($entry['title'] ?: ('Post #' . $entry['post_id'])); ?></a><?php endif; ?></td><td><?php echo esc_html($entry['message'] ?? ''); ?></td></tr><?php endforeach; ?>
            </tbody></table><?php else : ?><p><?php echo esc_html__('No activity yet.', 'sports803-dashboard'); ?></p><?php endif; ?>
            <h2><?php echo esc_html__('Dashboard shortcode', 'sports803-dashboard'); ?></h2><p><code>[sports803_dashboard]</code> <?php echo esc_html__('embeds the existing Dashboard UI. Autopilot event articles are native WordPress posts.', 'sports803-dashboard'); ?></p>
        </div>
        <?php
    }

    private static function text_field($key, $label, $description, $settings, $type = 'text', $min = null, $max = null) {
        $name = S803_Dashboard_Plugin::OPTION . '[' . $key . ']';
        echo '<tr><th scope="row"><label for="s803-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="s803-' . esc_attr($key) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $settings[$key]) . '"';
        if ($type === 'number') { echo ' min="' . esc_attr((string) $min) . '" max="' . esc_attr((string) $max) . '"'; }
        echo '><p class="description">' . esc_html($description) . '</p></td></tr>';
    }
}
