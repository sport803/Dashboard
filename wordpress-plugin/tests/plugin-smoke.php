<?php
// CLI smoke tests run without WordPress; stub only the APIs these pure helpers use.
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
$GLOBALS['s803_test_meta'] = array();
$GLOBALS['s803_test_upload'] = '';
$GLOBALS['s803_test_thumbnail'] = 0;
function plugin_dir_path($file) { return dirname($file) . '/'; }
function add_filter() {}
function remove_filter() {}
function add_action() {}
function add_shortcode() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_url_raw($url) { return (string) $url; }
function esc_url($url) { return (string) $url; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_file_name($value) { return preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $value); }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function wp_json_encode($value) { return json_encode($value); }
function wp_parse_args($args, $defaults = array()) { return array_merge((array) $defaults, (array) $args); }
function get_option($key, $default = false) { return $default; }
function get_transient($key) { return false; }
function set_transient($key, $value, $expiration = 0) { return true; }
function wp_remote_get($url, $args = array()) { return new WP_Error('s803_test_network', 'Network is disabled in the smoke test.'); }
function wp_remote_retrieve_response_code($response) { return 0; }
function wp_remote_retrieve_body($response) { return ''; }
function wp_date($format, $timestamp = null, $timezone = null) { $date = new DateTimeImmutable('@' . (int) ($timestamp === null ? time() : $timestamp)); if ($timezone) { $date = $date->setTimezone($timezone); } return $date->format($format); }
function get_post_meta($post_id, $key, $single = false) { return isset($GLOBALS['s803_test_meta'][$post_id][$key]) ? $GLOBALS['s803_test_meta'][$post_id][$key] : ''; }
function update_post_meta($post_id, $key, $value) { $GLOBALS['s803_test_meta'][$post_id][$key] = $value; return true; }
function delete_post_meta($post_id, $key) { unset($GLOBALS['s803_test_meta'][$post_id][$key]); return true; }
function get_post_thumbnail_id($post_id) { return (int) $GLOBALS['s803_test_thumbnail']; }
function get_the_post_thumbnail_url($post_id, $size = 'post-thumbnail') { return $GLOBALS['s803_test_thumbnail'] ? 'https://example.test/generated.png' : false; }
function wp_tempnam($name = '') { return tempnam(sys_get_temp_dir(), 's803-'); }
function wp_upload_bits($name, $time, $bits) { $path = sys_get_temp_dir() . '/s803-' . uniqid('', true) . '-' . basename($name); file_put_contents($path, $bits); $GLOBALS['s803_test_upload'] = $path; return array('file' => $path, 'url' => 'https://example.test/' . basename($path), 'error' => false); }
function wp_insert_attachment($data, $file, $parent = 0, $wp_error = false) { return 321; }
function wp_generate_attachment_metadata($attachment_id, $file) { return array(); }
function wp_update_attachment_metadata($attachment_id, $data) { return true; }
function set_post_thumbnail($post_id, $attachment_id) { $GLOBALS['s803_test_thumbnail'] = (int) $attachment_id; return true; }
function wp_get_attachment_image_url($attachment_id, $size = 'thumbnail') { return 'https://example.test/generated.png'; }
function wp_delete_file($file) { if (is_file($file)) { unlink($file); } }
function get_post($post_id) { return false; }
class WP_Error { private $message; public function __construct($code, $message = '') { $this->message = $message; } public function get_error_message() { return $this->message; } }
function is_wp_error($value) { return $value instanceof WP_Error; }
require __DIR__ . '/../sports803-dashboard/sports803-dashboard.php';

function check($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
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
check(S803_OneBall_Provider::player_url('4628379') === 'https://sport803.github.io/player/?one=https://oneball.live/replay/4628379.htm', 'new Player base and OneBall replay query');
$player_url = S803_Player_Link::build('4628379', 'https://xoilaczzz.pro/truc-tiep/arsenal-vs-chelsea/', false);
check($player_url === 'https://sport803.github.io/player/?one=https://oneball.live/live/4628379.htm&xoilac=https://xoilaczzz.pro/truc-tiep/arsenal-vs-chelsea/', 'composed OneBall + Xoilac player URL');
check(S803_Player_Link::build('', 'https://evil.example/live', false) === '', 'player link rejects untrusted Xoilac hosts');
$match = S803_OneBall_Provider::find_match(array('home' => array('name' => 'Arsenal FC'), 'away' => array('name' => 'Chelsea FC')), array(array('id' => '4628379', 'home' => 'Arsenal', 'away' => 'Chelsea')));
check($match && $match['id'] === '4628379', 'OneBall provider matches fixture teams');

$xoilac = S803_Xoilac_Provider::parse_homepage('<a href="https://xoilaczzz.pro/truc-tiep/arsenal-vs-chelsea-luc-19h/">Arsenal vs Chelsea</a>');
check(count($xoilac) === 1 && $xoilac[0]['home'] === 'Arsenal' && $xoilac[0]['away'] === 'Chelsea', 'Xoilac live fixture page parser');
check(S803_Xoilac_Provider::find_match(array('home' => array('name' => 'Arsenal FC'), 'away' => array('name' => 'Chelsea FC')), $xoilac) !== null, 'Xoilac team matching');
$archive = S803_LiveTV_Provider::parse_archive('<b>Arsenal – Chelsea</b><a href="/enx/showvideo/12345">Video</a><a href="/enx/review/12345">Обзор матча</a>', array('home' => array('name' => 'Arsenal FC'), 'away' => array('name' => 'Chelsea')), 'https://livetv.sx/video/20261015/');
check($archive && $archive['id'] === '12345', 'LiveTV archive fixture/replay match');
check(S803_LiveTV_Provider::parse_archive('<b>Arsenal – Chelsea</b><a href="/enx/showvideo/12345">Video</a>', array('home' => array('name' => 'Arsenal'), 'away' => array('name' => 'Chelsea')), 'https://livetv.sx/video/20261015/') === null, 'LiveTV requires the match-review link before queuing a replay');
$russian_archive = S803_LiveTV_Provider::parse_archive('<b>Ливерпуль – Челси</b><a href="/enx/showvideo/23456">Video</a><a href="/enx/review/23456">Подробный обзор матча</a>', array('home' => array('name' => 'Liverpool'), 'away' => array('name' => 'Chelsea')), 'https://livetv.sx/video/20261015/');
check($russian_archive && $russian_archive['id'] === '23456', 'LiveTV Russian fixture names translate before matching');
check(S803_LiveTV_Provider::extract_iframe('<iframe src="https://livetv.sx/player/video.php?id=12345"></iframe>') === 'https://livetv.sx/player/video.php?id=12345', 'LiveTV verified player iframe extraction');
check(S803_LiveTV_Provider::extract_iframe('<iframe src="https://evil.example/player/video.php?id=12345"></iframe>') === '', 'LiveTV rejects unrelated iframe hosts');

$summary = array(
    'header' => array('competitions' => array(array('competitors' => array(array('id' => '10', 'homeAway' => 'home'), array('id' => '20', 'homeAway' => 'away'))))),
    'rosters' => array(array('team' => array('id' => '10'), 'roster' => array(array('starter' => true, 'athlete' => array('displayName' => 'Home Striker'))))),
    'keyEvents' => array(array('team' => array('id' => '10'), 'type' => array('text' => 'Goal'), 'clock' => array('displayValue' => '42′'), 'participants' => array(array('athlete' => array('displayName' => 'Home Striker')), array('athlete' => array('displayName' => 'Home Midfielder'))))),
);
$facts = S803_ESPN_Provider::player_facts($summary, array('home' => array('name' => 'Home'), 'away' => array('name' => 'Away')));
check(!empty($facts['home']['starters'][0]['name']) && !empty($facts['home']['scorers'][0]['assist']), 'ESPN lineup, scorer and assist extraction');

$parsed = S803_ESPN_Provider::parse_event(array(
    'id' => '12345', 'date' => '2026-10-15T16:00:00Z', 'name' => 'Arsenal vs Chelsea',
    'status' => array('type' => array('description' => 'Scheduled', 'state' => 'pre', 'completed' => false)),
    'competitions' => array(array('competitors' => array(
        array('homeAway' => 'home', 'score' => '0', 'team' => array('id' => '1', 'displayName' => 'Arsenal')),
        array('homeAway' => 'away', 'score' => '0', 'team' => array('id' => '2', 'displayName' => 'Chelsea')),
    ))),
), array('id' => 'epl', 'name' => 'Premier League', 'sport' => 'soccer', 'slug' => 'eng.1', 'type' => 'match'));
check($parsed && $parsed['key'] === 'epl|12345' && $parsed['home']['name'] === 'Arsenal', 'ESPN parser creates stable WordPress event key');
check(S803_Dashboard_Plugin::defaults()['autopilot_enabled'] === 0, 'Autopilot defaults off until configured on the WordPress site');
check(S803_Dashboard_Plugin::defaults()['xoilac_provider'] === 1 && S803_Dashboard_Plugin::defaults()['livetv_provider'] === 1, 'Xoilac and LiveTV providers enabled by default');

if (function_exists('imagecreatetruecolor') && function_exists('imagettftext')) {
    $image = S803_Thumbnail_Generator::ensure(5, array('key' => 'epl|12345', 'type' => 'match', 'home' => array('name' => 'Arsenal'), 'away' => array('name' => 'Chelsea'), 'league_name' => 'Premier League', 'start_utc' => time()), false);
    $dimensions = $GLOBALS['s803_test_upload'] ? @getimagesize($GLOBALS['s803_test_upload']) : false;
    check(!is_wp_error($image) && !empty($image['url']) && $dimensions && $dimensions[0] === 1200 && $dimensions[1] === 675, 'GD featured thumbnail renders a valid 1200×675 PNG');
    if ($GLOBALS['s803_test_upload'] && is_file($GLOBALS['s803_test_upload'])) { unlink($GLOBALS['s803_test_upload']); }
    $normalizer = new ReflectionMethod('S803_Thumbnail_Generator', 'gd_safe_text');
    $normalizer->setAccessible(true);
    $safe_text = $normalizer->invoke(null, 'São Paulo — 東京 ⚽');
    check($safe_text !== '' && !preg_match('/[^\x20-\x7E]/', $safe_text), 'thumbnail labels are converted to FreeType-safe ASCII');
    $gd_warnings = array();
    set_error_handler(function ($severity, $message) use (&$gd_warnings) { $gd_warnings[] = $message; return true; }, E_WARNING);
    $unicode_image = S803_Thumbnail_Generator::ensure(6, array('key' => 'unicode|12345', 'type' => 'match', 'home' => array('name' => 'São Paulo — 東京'), 'away' => array('name' => 'München ⚽'), 'league_name' => 'Liga de Campeões — 北京', 'start_utc' => time()), false);
    restore_error_handler();
    check(!is_wp_error($unicode_image) && !$gd_warnings, 'Unicode event text generates a thumbnail without GD warnings');
    if ($GLOBALS['s803_test_upload'] && is_file($GLOBALS['s803_test_upload'])) { unlink($GLOBALS['s803_test_upload']); }
} else { echo "GD/FreeType unavailable; thumbnail rendering skipped.\n"; }

echo "WordPress autopilot smoke tests passed.\n";
