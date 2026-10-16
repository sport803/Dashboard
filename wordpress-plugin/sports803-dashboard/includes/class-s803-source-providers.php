<?php
if (!defined('ABSPATH')) { exit; }

/** Shared fuzzy matching used for live stream and replay source feeds. */
final class S803_Source_Matcher {
    public static function team_key($value) {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = function_exists('remove_accents') ? remove_accents(strtolower($value)) : strtolower($value);
        $value = preg_replace('/\b(fc|afc|rfc|sc|cf|ac|as|bsc|rcd|rc|vfb|vfl|real|deportivo|athletic|clube|club|city|united|town|rovers|wanderers|albion|hotspur|villa|forest|palace|wolves|celtic|rangers)\b/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', ' ', $value)));
    }

    public static function team_matches($left, $right) {
        $a = self::team_key($left); $b = self::team_key($right);
        if ($a === '' || $b === '') { return false; }
        if ($a === $b || strpos($a, $b) !== false || strpos($b, $a) !== false) { return true; }
        $a_tokens = array_values(array_filter(explode(' ', $a), function ($token) { return strlen($token) > 3; }));
        $b_tokens = array_values(array_filter(explode(' ', $b), function ($token) { return strlen($token) > 3; }));
        return ($a_tokens && count(array_filter($a_tokens, function ($token) use ($b) { return strpos($b, $token) !== false; })) === count($a_tokens))
            || ($b_tokens && count(array_filter($b_tokens, function ($token) use ($a) { return strpos($a, $token) !== false; })) === count($b_tokens));
    }

    public static function pair_matches($home, $away, $other_home, $other_away) {
        return (self::team_matches($home, $other_home) && self::team_matches($away, $other_away))
            || (self::team_matches($home, $other_away) && self::team_matches($away, $other_home));
    }
}

/** The Player app accepts `one`, `xoilac`, and `embed` query values. */
final class S803_Player_Link {
    const BASE_URL = 'https://sport803.github.io/player/';

    public static function build($oneball_id = '', $xoilac_url = '', $final = false) {
        $params = array();
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $oneball_id);
        if ($id !== '') {
            $kind = $final ? 'replay' : 'live';
            $params[] = 'one=https://oneball.live/' . $kind . '/' . rawurlencode($id) . '.htm';
        }
        $xoilac_url = esc_url_raw((string) $xoilac_url);
        if ($xoilac_url && preg_match('~^https://(?:www\.)?xoilaczzz\.pro/truc-tiep/~i', $xoilac_url)) {
            $params[] = 'xoilac=' . $xoilac_url;
        }
        return $params ? self::BASE_URL . '?' . implode('&', $params) : '';
    }

    public static function embed($url) {
        $url = esc_url_raw((string) $url);
        return $url ? self::BASE_URL . '?embed=' . rawurlencode($url) : '';
    }
}

/** Xoilac homepage feed parser; source URLs match the Dashboard’s canonical links. */
final class S803_Xoilac_Provider {
    const HOME_URL = 'https://xoilaczzz.pro/';

    public static function load_feed() {
        $key = 's803_xoilac_feed';
        $cached = get_transient($key);
        if (is_array($cached)) { return $cached; }
        $urls = array(
            self::HOME_URL,
            'https://api.codetabs.com/v1/proxy?quest=' . rawurlencode(self::HOME_URL),
            'https://api.allorigins.win/raw?url=' . rawurlencode(self::HOME_URL),
            'https://corsproxy.io/?url=' . rawurlencode(self::HOME_URL),
        );
        $last_error = '';
        foreach ($urls as $url) {
            $response = wp_remote_get($url, array('timeout' => 12, 'redirection' => 3, 'limit_response_size' => 1500000, 'headers' => array('Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0 (compatible; Sports803-Autopilot/' . S803_PLUGIN_VERSION . ')')));
            if (is_wp_error($response)) { $last_error = $response->get_error_message(); continue; }
            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status < 200 || $status >= 300) { $last_error = 'HTTP ' . $status; continue; }
            $matches = self::parse_homepage(wp_remote_retrieve_body($response));
            if ($matches) { set_transient($key, $matches, 240); return $matches; }
            $last_error = 'No valid Xoilac fixture links were found.';
        }
        return new WP_Error('s803_xoilac_unavailable', $last_error ?: 'Xoilac source feed is unavailable.');
    }

    public static function parse_homepage($html) {
        $found = array();
        preg_match_all('~<a\b[^>]*>[\s\S]*?</a>~i', (string) $html, $anchors);
        foreach ((array) $anchors[0] as $anchor) {
            if (!preg_match('~\bhref\s*=\s*(["\'])(.*?)\1~i', $anchor, $href_match)) { continue; }
            $href = html_entity_decode($href_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $parts = wp_parse_url($href);
            if (empty($parts['host'])) { $href = self::HOME_URL . ltrim($href, '/'); $parts = wp_parse_url($href); }
            $host = strtolower(isset($parts['host']) ? $parts['host'] : '');
            if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https' || !in_array($host, array('xoilaczzz.pro', 'www.xoilaczzz.pro'), true)) { continue; }
            $path = isset($parts['path']) ? $parts['path'] : '';
            $path = preg_replace('~/link/\d+/?$~i', '/', $path);
            $path = rtrim($path, '/');
            if (!preg_match('~^/truc-tiep/([^/]+)$~i', $path, $slug_match)) { continue; }
            $slug = preg_replace('/-luc-.*$/i', '', $slug_match[1]);
            $teams = preg_split('/-vs?-/i', $slug);
            if (count($teams) !== 2 || !$teams[0] || !$teams[1]) { continue; }
            $clean = function ($value) { return trim(ucwords(str_replace('-', ' ', rawurldecode($value)))); };
            $home = $clean($teams[0]); $away = $clean($teams[1]);
            if (preg_match('/\b(u\d{2}|women|youth|reserves|academy)\b/i', $home . ' ' . $away)) { continue; }
            $url = 'https://xoilaczzz.pro' . $path . '/';
            $entry = array('url' => $url, 'home' => $home, 'away' => $away, 'source' => 'xoilac', 'type' => 'xoilac');
            $found[md5(S803_Source_Matcher::team_key($home) . '|' . S803_Source_Matcher::team_key($away))] = $entry;
        }
        return array_values($found);
    }

    public static function find_match($event, $feed) {
        if (!is_array($feed) || empty($event['home']['name']) || empty($event['away']['name'])) { return null; }
        foreach ($feed as $entry) {
            if (!empty($entry['url']) && S803_Source_Matcher::pair_matches($event['home']['name'], $event['away']['name'], $entry['home'], $entry['away'])) { return $entry; }
        }
        return null;
    }
}

/** LiveTV archive replay fallback, matching the Dashboard archive/show-page workflow. */
final class S803_LiveTV_Provider implements S803_Highlight_Provider {
    const BASE_URL = 'https://livetv.sx';
    const ARCHIVE_URL = 'https://livetv.sx/video/';

    public function key() { return 'livetv'; }

    public function find_verified_replay($event, $feed = null) {
        if (empty($event['home']['name']) || empty($event['away']['name']) || $event['type'] === 'race') { return null; }
        $start = !empty($event['start_utc']) ? (int) $event['start_utc'] : time();
        $date = gmdate('Ymd', $start);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $archive_url = self::ARCHIVE_URL . $date . '/';
            $html = self::fetch_text($archive_url, 's803_livetv_archive_' . $date, HOUR_IN_SECONDS);
            if (is_wp_error($html)) { $date = gmdate('Ymd', strtotime($date . ' -1 day')); continue; }
            $entry = self::parse_archive($html, $event, $archive_url);
            if (!$entry) { $date = gmdate('Ymd', strtotime($date . ' -1 day')); continue; }
            $show = self::fetch_text($entry['show_url'], 's803_livetv_show_' . md5($entry['show_url']), 5 * MINUTE_IN_SECONDS);
            if (is_wp_error($show)) { return $show; }
            $raw = self::extract_iframe($show);
            if (!$raw) { return new WP_Error('s803_livetv_unverified', 'LiveTV match page did not expose a verified replay embed.'); }
            return array('provider' => $this->key(), 'id' => $entry['id'], 'url' => $entry['archive_url'], 'source_url' => $entry['show_url'], 'player_url' => S803_Player_Link::embed($raw));
        }
        return null;
    }

    public static function parse_archive($html, $event, $archive_url) {
        $visible = preg_replace('~<div\b[^>]*style\s*=\s*(["\'])[^"\']*display\s*:\s*none[^"\']*\1[^>]*>[\s\S]*?</div>~i', '', (string) $html);
        $pattern = '~<b>\s*([^<]+?)\s*(?:&ndash;|–|-)\s*([^<]+?)\s*</b>~i';
        if (!preg_match_all($pattern, $visible, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { return null; }
        foreach ($blocks as $block) {
            $segment_start = $block[0][1] + strlen($block[0][0]);
            $next_match = array();
            $has_next_header = preg_match('~<b\b~i', $visible, $next_match, PREG_OFFSET_CAPTURE, $segment_start);
            $next_header = $has_next_header ? $next_match[0][1] : false;
            $segment_length = $next_header === false ? 600 : min(600, max(0, $next_header - $segment_start));
            $segment = substr($visible, $segment_start, $segment_length);
            if (!preg_match('~<a\b[^>]*href\s*=\s*(["\'])([^"\']*/showvideo/(\d+)[^"\']*)\1[\s\S]*?<a\b[^>]*>\s*(?:Обзор матча|Подробный обзор матча)\s*</a>~iu', $segment, $href)) { continue; }
            $archive_home = self::translate_team(html_entity_decode($block[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $archive_away = self::translate_team(html_entity_decode($block[2][0], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (!S803_Source_Matcher::pair_matches($event['home']['name'], $event['away']['name'], $archive_home, $archive_away)) { continue; }
            $show_url = html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (strpos($show_url, '//') === 0) { $show_url = 'https:' . $show_url; }
            elseif (strpos($show_url, '/') === 0) { $show_url = self::BASE_URL . $show_url; }
            if (!preg_match('~^https://(?:www\.)?livetv\.sx/[^\s"\']*/showvideo/\d+~i', $show_url)) { continue; }
            return array('id' => $href[3], 'archive_url' => $archive_url, 'show_url' => $show_url);
        }
        return null;
    }

    private static function translate_team($name) {
        static $translations = null;
        if ($translations === null) { $translations = include __DIR__ . '/livetv-team-map.php'; }
        $name = trim((string) $name);
        if (isset($translations[$name])) { return $translations[$name]; }
        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        return isset($translations[$key]) ? $translations[$key] : $name;
    }

    public static function extract_iframe($html) {
        if (!preg_match_all('~<iframe\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1~i', (string) $html, $matches, PREG_SET_ORDER)) { return ''; }
        foreach ($matches as $match) {
            $src = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (strpos($src, '//') === 0) { $src = 'https:' . $src; }
            elseif (strpos($src, '/') === 0) { $src = self::BASE_URL . $src; }
            $host = strtolower((string) wp_parse_url($src, PHP_URL_HOST));
            $allowed_path = preg_match('~(^|\.)livetv\.sx$~i', $host) && preg_match('~player/video\.php\?id=~i', $src);
            $allowed_host = preg_match('~^emb\.[a-z0-9.-]+$~i', $host);
            if (($allowed_path || $allowed_host) && !preg_match('/ads\.livetv|getbanner|chateor|live-tv\.top/i', $src) && preg_match('~^https://~i', $src)) { return $src; }
        }
        return '';
    }

    private static function fetch_text($url, $cache_key, $ttl) {
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') { return $cached; }
        $urls = array(
            $url,
            'https://r.jina.ai/http://' . preg_replace('~^https?://~i', '', $url),
            'https://api.codetabs.com/v1/proxy?quest=' . rawurlencode($url),
            'https://api.allorigins.win/raw?url=' . rawurlencode($url),
            'https://corsproxy.io/?url=' . rawurlencode($url),
        );
        $last_error = '';
        foreach ($urls as $candidate) {
            $response = wp_remote_get($candidate, array('timeout' => 12, 'redirection' => 3, 'limit_response_size' => 1500000, 'headers' => array('Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0 (compatible; Sports803-Autopilot/' . S803_PLUGIN_VERSION . ')')));
            if (is_wp_error($response)) { $last_error = $response->get_error_message(); continue; }
            $status = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            if ($status >= 200 && $status < 300 && $body !== '') { set_transient($cache_key, $body, $ttl); return $body; }
            $last_error = 'HTTP ' . $status;
        }
        return new WP_Error('s803_livetv_fetch', $last_error ?: 'LiveTV page unavailable.');
    }
}
