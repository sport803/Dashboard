<?php
if (!defined('ABSPATH')) { exit; }

final class S803_Dashboard_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'styles'));
    }

    public static function menu() {
        add_menu_page(__('Sports 803 Autopilot', 'sports803-dashboard'), __('Sports 803', 'sports803-dashboard'), 'manage_options', 's803-dashboard', array(__CLASS__, 'render'), 'dashicons-awards', 25);
    }

    public static function register_settings() {
        register_setting('s803_autopilot_group', S803_Dashboard_Plugin::OPTION, array('type' => 'array', 'sanitize_callback' => array('S803_Dashboard_Plugin', 'sanitize_settings'), 'default' => S803_Dashboard_Plugin::defaults()));
    }

    public static function styles($hook) {
        if (strpos((string) $hook, 's803-dashboard') === false) { return; }
        wp_register_style('s803-dashboard-admin', false, array(), S803_PLUGIN_VERSION);
        wp_enqueue_style('s803-dashboard-admin');
        wp_add_inline_style('s803-dashboard-admin', '.s803-wrap{max-width:1360px;margin:24px 20px 40px 0;color:#172033}.s803-hero{padding:28px 32px;border-radius:18px;background:linear-gradient(125deg,#101827,#20364d 70%,#c71f39);color:#fff;box-shadow:0 12px 34px #0b12201c}.s803-hero h1{font-size:28px;color:#fff;margin:0 0 8px}.s803-hero p{max-width:760px;color:#e3e9f1;font-size:14px;margin:0}.s803-hero-top{display:flex;align-items:center;justify-content:space-between;gap:20px}.s803-brand{font-weight:800;letter-spacing:.12em;color:#fff;text-transform:uppercase}.s803-brand span{color:#ff6376}.s803-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 13px;border-radius:99px;font-size:12px;font-weight:700;letter-spacing:.04em;background:#e9f7ef;color:#147344}.s803-pill.off{background:#f2f3f6;color:#5b6472}.s803-pill.warn{background:#fff2df;color:#945400}.s803-led{width:8px;height:8px;border-radius:50%;background:currentColor}.s803-grid{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:14px;margin:20px 0}.s803-card{background:#fff;border:1px solid #e2e7ef;border-radius:14px;padding:18px;box-shadow:0 3px 10px #14233b08}.s803-card-label{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#667085;font-weight:700}.s803-card-value{font-size:28px;font-weight:800;color:#172033;margin-top:6px}.s803-card-note{font-size:12px;color:#667085;margin-top:6px}.s803-panel{background:#fff;border:1px solid #e2e7ef;border-radius:14px;padding:22px 24px;margin:16px 0;box-shadow:0 3px 10px #14233b08}.s803-panel h2{font-size:17px;margin:0 0 14px;color:#18243a}.s803-panel h3{font-size:14px;color:#344054;margin:18px 0 7px}.s803-columns{display:grid;grid-template-columns:1fr 1fr;gap:16px}.s803-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.s803-meta{color:#667085;font-size:13px}.s803-provider{display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid #edf0f5}.s803-provider:last-child{border:0}.s803-provider strong{color:#25334a}.s803-table{width:100%;border-collapse:collapse}.s803-table th,.s803-table td{padding:11px 12px;border-bottom:1px solid #edf0f5;text-align:left;vertical-align:top}.s803-table th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#667085}.s803-small{font-size:12px;color:#667085}.s803-shortcode{display:inline-block;padding:6px 10px;background:#f1f4f9;border:1px solid #e1e7ef;border-radius:6px;font-family:monospace}.s803-settings .form-table th{width:250px}.s803-settings input.regular-text{max-width:420px}.s803-settings input[type=number]{width:100px}.s803-setting-row{display:flex;gap:22px;flex-wrap:wrap}.s803-notice{margin:18px 0 0}.s803-empty{padding:22px;text-align:center;border:1px dashed #cfd6e1;border-radius:10px;color:#667085}.s803-footer{padding:8px 2px;color:#667085;font-size:12px}@media(max-width:900px){.s803-grid{grid-template-columns:repeat(2,1fr)}.s803-columns{grid-template-columns:1fr}}@media(max-width:560px){.s803-wrap{margin-right:10px}.s803-hero{padding:22px}.s803-hero-top{align-items:flex-start;flex-direction:column}.s803-grid{grid-template-columns:1fr 1fr}.s803-card{padding:14px}.s803-card-value{font-size:24px}.s803-table{display:block;overflow-x:auto}.s803-settings .form-table th{width:auto}}');
    }

    public static function render() {
        if (!current_user_can('manage_options')) { return; }
        $settings = S803_Dashboard_Plugin::settings();
        $last = get_option(S803_Dashboard_Plugin::LAST_RUN, array());
        $next = wp_next_scheduled(S803_Dashboard_Plugin::CRON_HOOK);
        $counts = S803_Autopilot_Engine::counts();
        $logs = get_option(S803_Dashboard_Plugin::LOG_OPTION, array());
        $review = S803_Autopilot_Engine::review_posts();
        $enabled = !empty($settings['autopilot_enabled']);
        $provider_states = isset($last['providers']) && is_array($last['providers']) ? $last['providers'] : array();
        ?>
        <div class="wrap s803-wrap">
            <header class="s803-hero">
                <div class="s803-hero-top">
                    <div><div class="s803-brand"><span>●</span> Sports 803</div><h1><?php echo esc_html__('Autopilot Control Center', 'sports803-dashboard'); ?></h1><p><?php echo esc_html__('A WordPress-native command center for fixture discovery, publishing, kickoff changes, live scores and verified post-match replays.', 'sports803-dashboard'); ?></p></div>
                    <span class="s803-pill <?php echo $enabled ? '' : 'off'; ?>"><span class="s803-led"></span><?php echo $enabled ? esc_html__('AUTOPILOT ON', 'sports803-dashboard') : esc_html__('AUTOPILOT OFF', 'sports803-dashboard'); ?></span>
                </div>
            </header>
            <?php if (isset($_GET['s803_run'])) : ?><div class="notice notice-<?php echo sanitize_key($_GET['s803_run']) === 'complete' ? 'success' : 'error'; ?> is-dismissible s803-notice"><p><?php echo sanitize_key($_GET['s803_run']) === 'complete' ? esc_html__('Autopilot check completed. Review the latest run and activity details below.', 'sports803-dashboard') : esc_html__('The run failed or another run is active. The activity log has the details.', 'sports803-dashboard'); ?></p></div><?php endif; ?>
            <?php if (isset($_GET['s803_retry'])) : ?><div class="notice notice-<?php echo sanitize_key($_GET['s803_retry']) === 'queued' ? 'success' : 'error'; ?> is-dismissible s803-notice"><p><?php echo sanitize_key($_GET['s803_retry']) === 'queued' ? esc_html__('Highlight retry queued for the next autopilot check.', 'sports803-dashboard') : esc_html__('Could not queue that highlight retry.', 'sports803-dashboard'); ?></p></div><?php endif; ?>

            <section class="s803-grid" aria-label="Autopilot metrics">
                <?php self::metric(__('Managed posts', 'sports803-dashboard'), $counts['managed'], __('Native WordPress event posts', 'sports803-dashboard')); ?>
                <?php self::metric(__('In progress', 'sports803-dashboard'), $counts['queued'] + $counts['retry'], __('Queued or retrying replays', 'sports803-dashboard')); ?>
                <?php self::metric(__('Replays verified', 'sports803-dashboard'), $counts['complete'], __('Existing posts upgraded', 'sports803-dashboard')); ?>
                <?php self::metric(__('Needs review', 'sports803-dashboard'), $counts['needs_review'], __('Manual review queue', 'sports803-dashboard')); ?>
            </section>

            <div class="s803-columns">
                <section class="s803-panel">
                    <h2><?php echo esc_html__('Run status', 'sports803-dashboard'); ?></h2>
                    <div class="s803-provider"><strong><?php echo esc_html__('Next scheduled check', 'sports803-dashboard'); ?></strong><span><?php echo $next ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next)) : esc_html__('Not scheduled', 'sports803-dashboard'); ?></span></div>
                    <div class="s803-provider"><strong><?php echo esc_html__('Schedule', 'sports803-dashboard'); ?></strong><span><?php echo esc_html__('Every five minutes', 'sports803-dashboard'); ?></span></div>
                    <div class="s803-provider"><strong><?php echo esc_html__('Last check', 'sports803-dashboard'); ?></strong><span><?php echo !empty($last['finished_at']) ? esc_html($last['finished_at']) : esc_html__('No completed run yet', 'sports803-dashboard'); ?></span></div>
                    <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) : ?><p class="s803-pill warn"><span class="s803-led"></span><?php echo esc_html__('WP-Cron disabled — configure server cron', 'sports803-dashboard'); ?></p><p class="s803-small"><?php echo esc_html__('Configure your host to request wp-cron.php every five minutes. WordPress cron is otherwise traffic-triggered and may run late on quiet sites.', 'sports803-dashboard'); ?></p><?php else : ?><p class="s803-small"><?php echo esc_html__('WordPress cron is traffic-triggered. For dependable five-minute timing on a quiet site, configure your host to request wp-cron.php every five minutes.', 'sports803-dashboard'); ?></p><?php endif; ?>
                    <div class="s803-actions">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="s803_run_now"><?php wp_nonce_field('s803_run_now'); ?><?php submit_button(__('Run autopilot now', 'sports803-dashboard'), 'primary', 'submit', false); ?></form>
                        <a class="button button-secondary" href="<?php echo esc_url(admin_url('edit.php?post_status=future&post_type=post')); ?>"><?php echo esc_html__('View scheduled posts', 'sports803-dashboard'); ?></a>
                    </div>
                </section>
                <section class="s803-panel">
                    <h2><?php echo esc_html__('Provider health', 'sports803-dashboard'); ?></h2>
                    <?php self::provider_row(__('ESPN fixtures & summaries', 'sports803-dashboard'), !empty($last['ok']) ? 'Last run OK' : (!empty($last) ? 'Check activity log' : 'Awaiting first run'), !empty($last['ok'])); ?>
                    <?php self::provider_row(__('OneBall live & replay', 'sports803-dashboard'), self::provider_message($provider_states, 'oneball', !empty($settings['oneball_provider'])), !empty($provider_states['oneball']['ok'])); ?>
                    <?php self::provider_row(__('Xoilac live streams', 'sports803-dashboard'), self::provider_message($provider_states, 'xoilac', !empty($settings['xoilac_provider'])), !empty($provider_states['xoilac']['ok'])); ?>
                    <?php self::provider_row(__('LiveTV replay fallback', 'sports803-dashboard'), !empty($settings['livetv_provider']) ? 'Enabled for verified replay searches' : 'Disabled', !empty($settings['livetv_provider'])); ?>
                    <p class="s803-small"><?php echo esc_html__('Source health reflects the latest scheduled run. External feeds can temporarily block automated requests; retry jobs use backoff and preserve existing posts.', 'sports803-dashboard'); ?></p>
                </section>
            </div>

            <section class="s803-panel">
                <h2><?php echo esc_html__('Latest run summary', 'sports803-dashboard'); ?></h2>
                <?php if (!empty($last)) : ?>
                    <p class="s803-meta"><?php echo esc_html(sprintf('Finished %s · %d seconds · %s', $last['finished_at'] ?? '', $last['duration_seconds'] ?? 0, !empty($last['ok']) ? 'completed successfully' : 'completed with warnings')); ?></p>
                    <div class="s803-setting-row"><span><strong><?php echo esc_html__('Fixtures found:', 'sports803-dashboard'); ?></strong> <?php echo esc_html((string) ($last['discovered'] ?? 0)); ?></span><span><strong><?php echo esc_html__('Created:', 'sports803-dashboard'); ?></strong> <?php echo esc_html((string) ($last['created'] ?? 0)); ?></span><span><strong><?php echo esc_html__('Updated:', 'sports803-dashboard'); ?></strong> <?php echo esc_html((string) ($last['updated'] ?? 0)); ?></span><span><strong><?php echo esc_html__('Drafted:', 'sports803-dashboard'); ?></strong> <?php echo esc_html((string) ($last['drafted'] ?? 0)); ?></span><span><strong><?php echo esc_html__('Score updates:', 'sports803-dashboard'); ?></strong> <?php echo esc_html((string) ($last['score_updates'] ?? 0)); ?></span><span><strong><?php echo esc_html__('Replays completed:', 'sports803-dashboard'); ?></strong> <?php echo esc_html((string) ($last['highlights_completed'] ?? 0)); ?></span><span><strong><?php echo esc_html__('Errors:', 'sports803-dashboard'); ?></strong> <?php echo esc_html((string) ($last['errors'] ?? 0)); ?></span></div>
                <?php else : ?><div class="s803-empty"><?php echo esc_html__('Your first scheduled check will populate run metrics and source health.', 'sports803-dashboard'); ?></div><?php endif; ?>
            </section>

            <section class="s803-panel s803-settings">
                <h2><?php echo esc_html__('Autopilot configuration', 'sports803-dashboard'); ?></h2>
                <form method="post" action="options.php">
                    <?php settings_fields('s803_autopilot_group'); ?>
                    <table class="form-table" role="presentation"><tbody>
                        <tr><th scope="row"><?php echo esc_html__('Engine', 'sports803-dashboard'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(S803_Dashboard_Plugin::OPTION); ?>[autopilot_enabled]" value="1" <?php checked($enabled); ?>> <?php echo esc_html__('Enable five-minute event discovery and post management', 'sports803-dashboard'); ?></label></td></tr>
                        <?php self::text_field('timezone', __('Event timezone', 'sports803-dashboard'), __('IANA timezone, e.g. Africa/Nairobi. Used for fixtures, schedules and event labels.', 'sports803-dashboard'), $settings, 'text'); ?>
                        <?php self::text_field('date_window_days', __('Upcoming date window (days)', 'sports803-dashboard'), __('Scan today and the following number of calendar days (1–14).', 'sports803-dashboard'), $settings, 'number', 1, 14); ?>
                        <?php self::text_field('league_filter', __('League filter', 'sports803-dashboard'), __('Comma-separated IDs, e.g. epl,nba,f1. Leave blank to scan the supported catalog.', 'sports803-dashboard'), $settings, 'text'); ?>
                        <?php self::text_field('leagues_per_run', __('Leagues scanned per check', 'sports803-dashboard'), __('Rotates through the catalog to keep cron work bounded (1–40).', 'sports803-dashboard'), $settings, 'number', 1, 40); ?>
                        <tr><th scope="row"><?php echo esc_html__('Publishing', 'sports803-dashboard'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(S803_Dashboard_Plugin::OPTION); ?>[schedule_at_kickoff]" value="1" <?php checked(!empty($settings['schedule_at_kickoff'])); ?>> <?php echo esc_html__('Schedule posts at kickoff and reschedule the same post if kickoff changes', 'sports803-dashboard'); ?></label></td></tr>
                        <tr><th scope="row"><?php echo esc_html__('Live score sync', 'sports803-dashboard'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(S803_Dashboard_Plugin::OPTION); ?>[live_score_sync]" value="1" <?php checked(!empty($settings['live_score_sync'])); ?>> <?php echo esc_html__('Update LIVE / FULL TIME banners, scores, clock and verified summary facts', 'sports803-dashboard'); ?></label></td></tr>
                        <tr><th scope="row"><?php echo esc_html__('Automatic highlights', 'sports803-dashboard'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(S803_Dashboard_Plugin::OPTION); ?>[auto_highlights]" value="1" <?php checked(!empty($settings['auto_highlights'])); ?>> <?php echo esc_html__('Queue finished matches and update the existing event post with verified replay content', 'sports803-dashboard'); ?></label></td></tr>
                        <tr><th scope="row"><?php echo esc_html__('Live-stream sources', 'sports803-dashboard'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(S803_Dashboard_Plugin::OPTION); ?>[oneball_provider]" value="1" <?php checked(!empty($settings['oneball_provider'])); ?>> <?php echo esc_html__('Match OneBall fixtures and replay pages', 'sports803-dashboard'); ?></label><br><label><input type="checkbox" name="<?php echo esc_attr(S803_Dashboard_Plugin::OPTION); ?>[xoilac_provider]" value="1" <?php checked(!empty($settings['xoilac_provider'])); ?>> <?php echo esc_html__('Match Xoilac live fixture pages', 'sports803-dashboard'); ?></label></td></tr>
                        <tr><th scope="row"><?php echo esc_html__('Replay providers', 'sports803-dashboard'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(S803_Dashboard_Plugin::OPTION); ?>[livetv_provider]" value="1" <?php checked(!empty($settings['livetv_provider'])); ?>> <?php echo esc_html__('Enable LiveTV verified replay fallback after OneBall', 'sports803-dashboard'); ?></label></td></tr>
                        <?php self::text_field('max_highlight_attempts', __('Attempts before review', 'sports803-dashboard'), __('After this many unsuccessful searches the job enters Needs review (1–10).', 'sports803-dashboard'), $settings, 'number', 1, 10); ?>
                        <?php self::text_field('jobs_per_run', __('Score/highlight jobs per check', 'sports803-dashboard'), __('Maximum score and highlight jobs handled in each cron request (1–50).', 'sports803-dashboard'), $settings, 'number', 1, 50); ?>
                    </tbody></table>
                    <?php submit_button(__('Save autopilot settings', 'sports803-dashboard')); ?>
                </form>
            </section>

            <section class="s803-panel">
                <h2><?php echo esc_html__('Highlight review queue', 'sports803-dashboard'); ?></h2>
                <?php if ($review) : ?><table class="s803-table"><thead><tr><th><?php echo esc_html__('Post', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Attempts', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Last provider error', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Action', 'sports803-dashboard'); ?></th></tr></thead><tbody>
                    <?php foreach ($review as $post) : ?><tr><td><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php echo esc_html(get_the_title($post)); ?></a></td><td><?php echo esc_html((string) get_post_meta($post->ID, '_s803_highlight_attempts', true)); ?></td><td><?php echo esc_html((string) get_post_meta($post->ID, '_s803_highlight_last_error', true)); ?></td><td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="s803_retry_highlight"><input type="hidden" name="post_id" value="<?php echo esc_attr((string) $post->ID); ?>"><?php wp_nonce_field('s803_retry_highlight_' . $post->ID); ?><button class="button" type="submit"><?php echo esc_html__('Queue retry', 'sports803-dashboard'); ?></button></form></td></tr><?php endforeach; ?>
                </tbody></table><?php else : ?><div class="s803-empty"><?php echo esc_html__('No replay jobs currently need manual review.', 'sports803-dashboard'); ?></div><?php endif; ?>
            </section>

            <section class="s803-panel">
                <h2><?php echo esc_html__('Recent activity', 'sports803-dashboard'); ?></h2>
                <?php if (is_array($logs) && $logs) : ?><table class="s803-table"><thead><tr><th><?php echo esc_html__('Time (UTC)', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Level', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Post', 'sports803-dashboard'); ?></th><th><?php echo esc_html__('Activity', 'sports803-dashboard'); ?></th></tr></thead><tbody>
                    <?php foreach (array_slice($logs, 0, 30) as $entry) : ?><tr><td><?php echo esc_html($entry['at'] ?? ''); ?></td><td><?php echo esc_html(strtoupper($entry['level'] ?? 'info')); ?></td><td><?php if (!empty($entry['post_id'])) : ?><a href="<?php echo esc_url(get_edit_post_link((int) $entry['post_id'])); ?>"><?php echo esc_html($entry['title'] ?: ('Post #' . $entry['post_id'])); ?></a><?php endif; ?></td><td><?php echo esc_html($entry['message'] ?? ''); ?></td></tr><?php endforeach; ?>
                </tbody></table><?php else : ?><div class="s803-empty"><?php echo esc_html__('No activity has been recorded yet.', 'sports803-dashboard'); ?></div><?php endif; ?>
            </section>

            <footer class="s803-footer"><?php echo esc_html__('Public fixture dashboard shortcode:', 'sports803-dashboard'); ?> <code class="s803-shortcode">[sports803_dashboard]</code> · <?php echo esc_html__('Posts, tags, thumbnails, score states and replay blocks are native WordPress content.', 'sports803-dashboard'); ?></footer>
        </div>
        <?php
    }

    private static function metric($label, $value, $note) {
        echo '<div class="s803-card"><div class="s803-card-label">' . esc_html($label) . '</div><div class="s803-card-value">' . esc_html((string) $value) . '</div><div class="s803-card-note">' . esc_html($note) . '</div></div>';
    }

    private static function provider_row($label, $message, $ok) {
        echo '<div class="s803-provider"><strong>' . esc_html($label) . '</strong><span class="s803-pill ' . ($ok ? '' : 'off') . '"><span class="s803-led"></span>' . esc_html($message) . '</span></div>';
    }

    private static function provider_message($states, $key, $enabled) {
        if (!$enabled) { return 'Disabled'; }
        if (!isset($states[$key])) { return 'Awaiting first run'; }
        if (!empty($states[$key]['ok'])) { return 'Available · ' . absint($states[$key]['matches'] ?? 0) . ' entries'; }
        return 'Unavailable in last run';
    }

    private static function text_field($key, $label, $description, $settings, $type = 'text', $min = null, $max = null) {
        $name = S803_Dashboard_Plugin::OPTION . '[' . $key . ']';
        echo '<tr><th scope="row"><label for="s803-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="s803-' . esc_attr($key) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $settings[$key]) . '"';
        if ($type === 'number') { echo ' min="' . esc_attr((string) $min) . '" max="' . esc_attr((string) $max) . '"'; }
        echo '><p class="description">' . esc_html($description) . '</p></td></tr>';
    }
}
