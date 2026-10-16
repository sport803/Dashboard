<?php
if (!defined('ABSPATH')) { exit; }

/** Creates and attaches the Sports 803 match artwork as a native featured image. */
final class S803_Thumbnail_Generator {
    const WIDTH = 1200;
    const HEIGHT = 675;

    public static function ensure($post_id, $event, $final = false) {
        $current_id = (int) get_post_thumbnail_id($post_id);
        $owned_id = (int) get_post_meta($post_id, '_s803_thumbnail_attachment_id', true);
        if ($current_id && $owned_id && $current_id !== $owned_id) {
            return array('attachment_id' => $current_id, 'url' => (string) get_the_post_thumbnail_url($post_id, 'full'), 'owned' => false);
        }
        if ($current_id && !$owned_id) {
            return array('attachment_id' => $current_id, 'url' => (string) get_the_post_thumbnail_url($post_id, 'full'), 'owned' => false);
        }
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            return new WP_Error('s803_thumbnail_gd', 'The WordPress host must enable PHP GD with FreeType for automatic featured thumbnails.');
        }
        $home_name = isset($event['home']['name']) ? (string) $event['home']['name'] : (isset($event['name']) ? (string) $event['name'] : 'Sports 803');
        $away_name = isset($event['away']['name']) ? (string) $event['away']['name'] : '';
        if (empty($event['home']['logo']) && $home_name) { $event['home']['logo'] = self::lookup_team_logo($home_name); }
        if (empty($event['away']['logo']) && $away_name) { $event['away']['logo'] = self::lookup_team_logo($away_name); }
        $signature = md5(wp_json_encode(array(
            isset($event['key']) ? $event['key'] : '', (bool) $final,
            isset($event['home']['name']) ? $event['home']['name'] : '', isset($event['away']['name']) ? $event['away']['name'] : '',
            isset($event['home']['score']) ? $event['home']['score'] : (isset($event['score_home']) ? $event['score_home'] : ''),
            isset($event['away']['score']) ? $event['away']['score'] : (isset($event['score_away']) ? $event['score_away'] : ''),
            isset($event['league_name']) ? $event['league_name'] : '', isset($event['start_utc']) ? (int) $event['start_utc'] : 0,
            isset($event['home']['logo']) ? $event['home']['logo'] : '', isset($event['away']['logo']) ? $event['away']['logo'] : '',
        )));
        $old_signature = (string) get_post_meta($post_id, '_s803_thumbnail_signature', true);
        if ($owned_id && $signature === $old_signature && get_post($owned_id)) {
            $url = wp_get_attachment_image_url($owned_id, 'full');
            if ($url) { return array('attachment_id' => $owned_id, 'url' => $url, 'owned' => true); }
        }

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (!$image) { return new WP_Error('s803_thumbnail_create', 'Could not allocate the thumbnail canvas.'); }
        imagealphablending($image, true);
        imagesavealpha($image, true);
        self::draw_background($image, $final);

        $font = S803_PLUGIN_DIR . 'assets/fonts/DejaVuSans-Bold.ttf';
        if (!is_readable($font)) { imagedestroy($image); return new WP_Error('s803_thumbnail_font', 'The bundled Sports 803 thumbnail font is missing.'); }
        $league = isset($event['league_name']) ? (string) $event['league_name'] : 'Live Sports';
        $home = $home_name;
        $away = $away_name;
        $home_score = isset($event['score_home']) ? (string) $event['score_home'] : (isset($event['home']['score']) ? (string) $event['home']['score'] : '');
        $away_score = isset($event['score_away']) ? (string) $event['score_away'] : (isset($event['away']['score']) ? (string) $event['away']['score'] : '');
        $score = $home_score !== '' || $away_score !== '' ? trim($home_score) . '–' . trim($away_score) : ($final ? 'FT' : 'VS');
        $date = !empty($event['start_utc']) ? wp_date('D, M j · H:i T', (int) $event['start_utc'], new DateTimeZone(S803_Dashboard_Plugin::settings()['timezone'])) : '';

        self::pill($image, 350, 28, 500, 48, array(8, 12, 20, 18));
        self::center_text($image, $league, 40, 600, 70, $font, array(232, 237, 244));
        imagefilledellipse($image, 360, 270, 205, 205, imagecolorallocatealpha($image, 255, 255, 255, 112));
        imagefilledellipse($image, 840, 270, 205, 205, imagecolorallocatealpha($image, 255, 255, 255, 112));
        self::draw_team_logo($image, isset($event['home']['logo']) ? $event['home']['logo'] : '', $home, 360, 270, $font);
        if ($away !== '') { self::draw_team_logo($image, isset($event['away']['logo']) ? $event['away']['logo'] : '', $away, 840, 270, $font); }

        imagefilledellipse($image, 600, 270, 116, 116, imagecolorallocate($image, 225, 35, 55));
        self::center_text($image, $score, 31, 600, 280, $font, array(255, 255, 255));
        self::center_text($image, $home, 33, 360, 430, $font, array(255, 255, 255), 420);
        if ($away !== '') { self::center_text($image, $away, 33, 840, 430, $font, array(255, 255, 255), 420); }
        self::center_text($image, $date, 22, 600, 500, $font, array(190, 200, 214), 1050);

        $state = $final ? 'HIGHLIGHTS & REPLAY' : 'LIVE EVENT · SPORTS 803';
        self::pill($image, 390, 535, 420, 54, array(225, 35, 55, 4));
        self::center_text($image, $state, 20, 600, 570, $font, array(255, 255, 255), 390);
        imagefilledrectangle($image, 0, 640, self::WIDTH, self::HEIGHT, imagecolorallocate($image, 188, 23, 42));
        self::center_text($image, 'SPORTS 803 MATCH REPORT', 22, 600, 666, $font, array(255, 255, 255), 800);

        $temporary = wp_tempnam('s803-thumbnail.png');
        if (!$temporary || !imagepng($image, $temporary, 6)) {
            imagedestroy($image);
            return new WP_Error('s803_thumbnail_write', 'Could not render the featured thumbnail PNG.');
        }
        imagedestroy($image);
        $bytes = file_get_contents($temporary);
        @unlink($temporary);
        if (!$bytes) { return new WP_Error('s803_thumbnail_empty', 'The generated thumbnail file was empty.'); }
        $key = sanitize_file_name(substr((string) (isset($event['key']) ? $event['key'] : 'event'), 0, 48));
        $filename = 'sports803-' . ($key ?: 'event') . '.png';
        $upload = wp_upload_bits($filename, null, $bytes);
        if (!empty($upload['error'])) { return new WP_Error('s803_thumbnail_upload', $upload['error']); }

        if (!function_exists('wp_generate_attachment_metadata')) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
        $attachment = array(
            'post_mime_type' => 'image/png', 'post_title' => sanitize_text_field($home . ($away ? ' vs ' . $away : '') . ' – Sports 803'),
            'post_status' => 'inherit', 'post_parent' => (int) $post_id,
        );
        $attachment_id = wp_insert_attachment($attachment, $upload['file'], $post_id, true);
        if (is_wp_error($attachment_id)) { wp_delete_file($upload['file']); return $attachment_id; }
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        if (is_array($metadata)) { wp_update_attachment_metadata($attachment_id, $metadata); }
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($home . ($away ? ' vs ' . $away : '') . ' – Sports 803 match graphic'));
        set_post_thumbnail($post_id, $attachment_id);
        update_post_meta($post_id, '_s803_thumbnail_attachment_id', (int) $attachment_id);
        update_post_meta($post_id, '_s803_thumbnail_signature', $signature);
        update_post_meta($post_id, '_s803_thumbnail_generated_at', time());
        if ($owned_id && $owned_id !== (int) $attachment_id && get_post($owned_id)) { wp_delete_attachment($owned_id, true); }
        $url = wp_get_attachment_image_url($attachment_id, 'full');
        return array('attachment_id' => (int) $attachment_id, 'url' => $url ? $url : $upload['url'], 'owned' => true);
    }

    private static function draw_background($image, $final) {
        $top = $final ? array(45, 9, 45) : array(13, 27, 42);
        $bottom = $final ? array(15, 12, 30) : array(5, 12, 22);
        for ($y = 0; $y < self::HEIGHT; $y++) {
            $ratio = $y / max(1, self::HEIGHT - 1);
            $color = imagecolorallocate($image,
                (int) ($top[0] + ($bottom[0] - $top[0]) * $ratio),
                (int) ($top[1] + ($bottom[1] - $top[1]) * $ratio),
                (int) ($top[2] + ($bottom[2] - $top[2]) * $ratio));
            imageline($image, 0, $y, self::WIDTH, $y, $color);
        }
        imagefilledrectangle($image, 0, 0, 15, self::HEIGHT, imagecolorallocate($image, 225, 35, 55));
        imagefilledrectangle($image, self::WIDTH - 15, 0, self::WIDTH, self::HEIGHT, imagecolorallocate($image, 244, 162, 97));
        imagefilledrectangle($image, 610, 0, 1200, self::HEIGHT, imagecolorallocatealpha($image, 255, 255, 255, 125));
    }

    private static function pill($image, $x, $y, $w, $h, $rgba) {
        $color = imagecolorallocatealpha($image, $rgba[0], $rgba[1], $rgba[2], $rgba[3]);
        $radius = (int) ($h / 2);
        imagefilledrectangle($image, $x + $radius, $y, $x + $w - $radius, $y + $h, $color);
        imagefilledellipse($image, $x + $radius, $y + $radius, $h, $h, $color);
        imagefilledellipse($image, $x + $w - $radius, $y + $radius, $h, $h, $color);
    }

    private static function gd_safe_text($text) {
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $text)));
        if ($text === '') { return ''; }
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($ascii)) { $text = ($ascii === '' ? '?' : $ascii); }
        }
        // FreeType builds may reject otherwise valid UTF-8 characters during charset conversion.
        // Keep non-ASCII bytes away from both imagettfbbox() and imagettftext().
        $text = preg_replace('/[^\x20-\x7E]/', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private static function center_text($image, $text, $size, $center_x, $baseline, $font, $rgb, $max_width = 0) {
        $text = self::gd_safe_text($text);
        if ($text === '') { return; }
        $size = max(13, (int) $size);
        if ($max_width > 0) {
            while ($size > 13) {
                $box = imagettfbbox($size, 0, $font, $text);
                if (abs($box[2] - $box[0]) <= $max_width) { break; }
                $size--;
            }
        }
        $box = imagettfbbox($size, 0, $font, $text);
        $x = (int) ($center_x - abs($box[2] - $box[0]) / 2);
        $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        imagettftext($image, $size, 0, $x, $baseline, $color, $font, $text);
    }

    private static function draw_team_logo($image, $url, $name, $x, $y, $font) {
        $bytes = self::team_logo_bytes($url);
        $size = 154;
        if ($bytes) {
            $source = @imagecreatefromstring($bytes);
            if ($source) {
                $canvas = imagecreatetruecolor($size, $size);
                imagealphablending($canvas, false); imagesavealpha($canvas, true);
                $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
                imagefill($canvas, 0, 0, $transparent);
                imagealphablending($canvas, true);
                $sw = imagesx($source); $sh = imagesy($source); $crop = min($sw, $sh);
                imagecopyresampled($canvas, $source, 0, 0, (int) (($sw - $crop) / 2), (int) (($sh - $crop) / 2), $size, $size, $crop, $crop);
                imagealphablending($canvas, false);
                $radius = $size / 2;
                for ($py = 0; $py < $size; $py++) {
                    for ($px = 0; $px < $size; $px++) {
                        $dx = $px - $radius; $dy = $py - $radius;
                        if (($dx * $dx + $dy * $dy) > $radius * $radius) { imagesetpixel($canvas, $px, $py, $transparent); }
                    }
                }
                imagecopy($image, $canvas, (int) ($x - $radius), (int) ($y - $radius), 0, 0, $size, $size);
                imagedestroy($canvas); imagedestroy($source);
                return;
            }
        }
        $background = imagecolorallocate($image, 35, 47, 66);
        imagefilledellipse($image, $x, $y, $size, $size, $background);
        $initial = strtoupper(substr(trim($name), 0, 1));
        self::center_text($image, $initial ?: 'S', 62, $x, $y + 21, $font, array(235, 240, 248));
    }

    private static function team_logo_bytes($url) {
        $url = esc_url_raw((string) $url);
        if (!$url || strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') { return ''; }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (!preg_match('/(^|\.)espncdn\.com$/', $host) && !preg_match('/(^|\.)thesportsdb\.com$/', $host)) { return ''; }
        $key = 's803_team_logo_' . md5($url);
        $cached = get_transient($key);
        if (is_string($cached)) { return $cached; }
        $response = wp_remote_get($url, array('timeout' => 8, 'redirection' => 2, 'limit_response_size' => 1200000, 'headers' => array('Accept' => 'image/avif,image/webp,image/png,image/*;q=0.8')));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) >= 300) { return ''; }
        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '' || strlen($body) > 1200000) { return ''; }
        set_transient($key, $body, DAY_IN_SECONDS);
        return $body;
    }

    private static function lookup_team_logo($team) {
        $team = trim((string) $team);
        if ($team === '') { return ''; }
        $key = 's803_team_logo_lookup_' . md5(strtolower($team));
        $cached = get_transient($key);
        if (is_string($cached)) { return $cached === '__none__' || $cached === '__error__' ? '' : $cached; }
        $url = 'https://www.thesportsdb.com/api/v1/json/3/searchteams.php?t=' . rawurlencode($team);
        $response = wp_remote_get($url, array('timeout' => 8, 'redirection' => 2, 'limit_response_size' => 350000, 'headers' => array('Accept' => 'application/json')));
        if (is_wp_error($response)) { set_transient($key, '__error__', 5 * MINUTE_IN_SECONDS); return ''; }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) { set_transient($key, '__error__', 5 * MINUTE_IN_SECONDS); return ''; }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $logo = isset($data['teams'][0]['strTeamBadge']) ? esc_url_raw($data['teams'][0]['strTeamBadge']) : (isset($data['teams'][0]['strBadge']) ? esc_url_raw($data['teams'][0]['strBadge']) : '');
        if (!$logo || strtolower((string) wp_parse_url($logo, PHP_URL_SCHEME)) !== 'https' || !preg_match('/(^|\.)thesportsdb\.com$/', strtolower((string) wp_parse_url($logo, PHP_URL_HOST)))) { $logo = ''; }
        set_transient($key, $logo ?: '__none__', $logo ? 30 * DAY_IN_SECONDS : DAY_IN_SECONDS);
        return $logo;
    }
}
