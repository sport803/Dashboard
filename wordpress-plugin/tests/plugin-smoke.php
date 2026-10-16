<?php
// Minimal CLI smoke test; WordPress functions are stubbed because this runs outside WordPress.
define('ABSPATH', __DIR__ . '/');
function add_filter() {}
function add_action() {}
function add_shortcode() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
require __DIR__ . '/../sports803-dashboard/sports803-dashboard.php';
$method = new ReflectionMethod('S803_Blogger_Autoposter', 'stable_hash');
$method->setAccessible(true);
$actual = $method->invoke(null, 'epl|401878771');
if ($actual !== '490c34cb') {
    fwrite(STDERR, "Marker hash mismatch: {$actual}\n");
    exit(1);
}
echo "WordPress plugin smoke test passed; marker hash matches the Node worker.\n";
