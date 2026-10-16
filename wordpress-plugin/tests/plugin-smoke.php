<?php
// Minimal CLI smoke test. WordPress APIs are stubbed because this runs outside WordPress.
define('ABSPATH', __DIR__ . '/');
function plugin_dir_path($file) { return dirname($file) . '/'; }
function add_filter() {}
function add_action() {}
function add_shortcode() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
require __DIR__ . '/../sports803-dashboard/sports803-dashboard.php';

function check($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

check(S803_Autopilot_Engine::status_kind('Postponed') === 'postponed', 'postponed status classification');
check(S803_Autopilot_Engine::status_kind('Canceled') === 'cancelled', 'cancelled status classification');
check(S803_Autopilot_Engine::status_kind('In Progress', 'in') === 'live', 'live status classification');
check(S803_Autopilot_Engine::status_kind('Final', 'post', true) === 'final', 'final status classification');
check(S803_Autopilot_Engine::retry_delay_minutes(1) === 5, 'first retry delay');
check(S803_Autopilot_Engine::retry_delay_minutes(3) === 20, 'exponential retry delay');
check(S803_Autopilot_Engine::retry_delay_minutes(10) === 240, 'retry delay upper bound');

$banner = S803_Autopilot_Engine::score_banner_html('Home <Team>', 'Away', '2', '1', '67′', 'live');
check(strpos($banner, 'LIVE') !== false && strpos($banner, '&lt;Team&gt;') !== false && strpos($banner, '67′') !== false, 'escaped LIVE score banner with clock');
$final_banner = S803_Autopilot_Engine::score_banner_html('Home', 'Away', '2', '1', '', 'final');
check(strpos($final_banner, 'FULL TIME') !== false, 'FULL TIME score banner');

$begin = '<!-- S803_SCORE_BEGIN -->';
$end = '<!-- S803_SCORE_END -->';
$content = S803_Autopilot_Engine::replace_managed_block('Article body', $begin, $end, '<div>LIVE</div>');
$content = S803_Autopilot_Engine::replace_managed_block($content, $begin, $end, '<div>FULL TIME</div>');
check(substr_count($content, $begin) === 1 && strpos($content, 'FULL TIME') !== false && strpos($content, 'LIVE</div>') === false, 'managed banner replacement is idempotent');

check(S803_OneBall_Provider::replay_url('4628379') === 'https://oneball.live/replay/4628379.htm', 'OneBall .htm replay URL');
check(S803_OneBall_Provider::player_url('4628379') === 'https://www.sport803.online/p/player.html?one=https://oneball.live/replay/4628379.htm', 'Sports 803 player URL uses OneBall replay .htm');
$match = S803_OneBall_Provider::find_match(
    array('home' => array('name' => 'Arsenal FC'), 'away' => array('name' => 'Chelsea FC')),
    array(array('id' => '4628379', 'home' => 'Arsenal', 'away' => 'Chelsea'))
);
check($match && $match['id'] === '4628379', 'OneBall provider matches fixture teams');

$parsed = S803_ESPN_Provider::parse_event(array(
    'id' => '12345', 'date' => '2026-10-15T16:00:00Z', 'name' => 'Arsenal vs Chelsea',
    'status' => array('type' => array('description' => 'Scheduled', 'state' => 'pre', 'completed' => false)),
    'competitions' => array(array('competitors' => array(
        array('homeAway' => 'home', 'score' => '0', 'team' => array('displayName' => 'Arsenal')),
        array('homeAway' => 'away', 'score' => '0', 'team' => array('displayName' => 'Chelsea')),
    ))),
), array('id' => 'epl', 'name' => 'Premier League', 'sport' => 'soccer', 'slug' => 'eng.1', 'type' => 'match'));
check($parsed && $parsed['key'] === 'epl|12345' && $parsed['home']['name'] === 'Arsenal', 'ESPN parser creates stable WordPress event key');
check(S803_Dashboard_Plugin::defaults()['autopilot_enabled'] === 0, 'Autopilot defaults off until configured on the WordPress site');

echo "WordPress autopilot smoke tests passed.\n";
