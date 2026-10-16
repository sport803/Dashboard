<?php
if (!defined('ABSPATH')) { exit; }

interface S803_Highlight_Provider {
    public function key();
    public function find_verified_replay($event, $feed);
}

final class S803_ESPN_Provider {
    const API = 'https://site.api.espn.com/apis/site/v2/sports';
    const CURSOR_OPTION = 's803_discovery_league_cursor';

    public static function leagues() {
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

    public static function discover($settings) {
        $timezone = new DateTimeZone($settings['timezone'] ?: 'Africa/Nairobi');
        $today = new DateTimeImmutable('today', $timezone);
        $days = max(1, min(14, absint($settings['date_window_days'])));
        $dates = array();
        for ($offset = 0; $offset < $days; $offset++) {
            $dates[] = $today->modify('+' . $offset . ' days')->format('Y-m-d');
        }
        // ESPN's date filter is UTC-oriented; fetch the adjacent UTC day needed
        // when the configured local window lies east or west of UTC.
        $query_dates = $dates;
        if ($today->getOffset() > 0) { array_unshift($query_dates, $today->modify('-1 day')->format('Y-m-d')); }
        $last_day = $today->modify('+' . ($days - 1) . ' days');
        if ($last_day->getOffset() < 0) { $query_dates[] = $last_day->modify('+1 day')->format('Y-m-d'); }
        $all = self::leagues();
        $filter = array_filter(array_map('strtolower', array_map('trim', explode(',', (string) $settings['league_filter']))));
        if ($filter) {
            $all = array_values(array_filter($all, function ($league) use ($filter) {
                return in_array(strtolower($league[0]), $filter, true) || in_array(strtolower($league[3]), $filter, true);
            }));
        }
        if (!$all) { return array('events' => array(), 'errors' => array(), 'dates' => $dates, 'leagues_scanned' => 0); }
        $limit = max(1, min(count($all), absint($settings['leagues_per_run'])));
        $cursor = absint(get_option(self::CURSOR_OPTION, 0)) % count($all);
        $batch = array();
        for ($i = 0; $i < $limit; $i++) { $batch[] = $all[($cursor + $i) % count($all)]; }
        update_option(self::CURSOR_OPTION, ($cursor + $limit) % count($all), false);

        $events = array();
        $errors = array();
        foreach ($batch as $row) {
            $league = array('id' => $row[0], 'sport' => $row[1], 'slug' => $row[2], 'name' => $row[3], 'type' => $row[4]);
            $seen = array();
            foreach ($query_dates as $date) {
                $url = self::API . '/' . rawurlencode($league['sport']) . '/' . rawurlencode($league['slug']) . '/scoreboard?dates=' . str_replace('-', '', $date);
                $data = self::get_json($url, 8, 120);
                if (is_wp_error($data)) {
                    $errors[] = $league['name'] . ': ' . $data->get_error_message();
                    continue;
                }
                foreach ((array) (isset($data['events']) ? $data['events'] : array()) as $raw) {
                    $event = self::parse_event($raw, $league);
                    if ($event && self::in_window($event, $dates, $timezone)) { $seen[$event['key']] = $event; }
                }
            }
            if (!$seen && $league['type'] === 'race') {
                foreach ($dates as $date) {
                    $tsdb = self::get_json('https://www.thesportsdb.com/api/v1/json/3/eventsday.php?d=' . rawurlencode($date) . '&s=Motorsport', 8, 120);
                    if (is_wp_error($tsdb) || empty($tsdb['events'])) { continue; }
                    foreach ($tsdb['events'] as $raw) {
                        $league_name = strtolower(isset($raw['strLeague']) ? $raw['strLeague'] : '');
                        if (strpos($league_name, strtolower($league['name'])) === false && !($league['id'] === 'wrc' && strpos($league_name, 'rally') !== false)) { continue; }
                        $event = self::parse_race($raw, $league);
                        if ($event && self::in_window($event, $dates, $timezone)) { $seen[$event['key']] = $event; }
                    }
                }
            }
            foreach ($seen as $event) { $events[$event['key']] = $event; }
        }
        uasort($events, function ($left, $right) { return $left['start_utc'] <=> $right['start_utc']; });
        return array('events' => array_values($events), 'errors' => $errors, 'dates' => $dates, 'leagues_scanned' => count($batch));
    }

    public static function fetch_summary($event) {
        $url = self::API . '/' . rawurlencode($event['sport']) . '/' . rawurlencode($event['slug']) . '/summary?event=' . rawurlencode($event['event_id']);
        return self::get_json($url, 10, 30);
    }

    public static function summary_snapshot($summary) {
        $header = isset($summary['header']) ? $summary['header'] : array();
        $competition = isset($header['competitions'][0]) ? $header['competitions'][0] : array();
        $home = array(); $away = array();
        foreach ((array) (isset($competition['competitors']) ? $competition['competitors'] : array()) as $competitor) {
            if (isset($competitor['homeAway']) && $competitor['homeAway'] === 'home') { $home = $competitor; }
            if (isset($competitor['homeAway']) && $competitor['homeAway'] === 'away') { $away = $competitor; }
        }
        $status = isset($competition['status']) ? $competition['status'] : (isset($header['competitions'][0]['status']) ? $header['competitions'][0]['status'] : array());
        $type = isset($status['type']) ? $status['type'] : array();
        $description = isset($type['description']) ? $type['description'] : (isset($type['detail']) ? $type['detail'] : '');
        $clock = isset($status['displayClock']) ? $status['displayClock'] : '';
        $period = isset($status['period']) ? $status['period'] : '';
        $detail = isset($type['detail']) ? $type['detail'] : '';
        return array(
            'home_score' => isset($home['score']) ? (string) $home['score'] : '',
            'away_score' => isset($away['score']) ? (string) $away['score'] : '',
            'status' => (string) $description,
            'state' => isset($type['state']) ? (string) $type['state'] : '',
            'completed' => !empty($type['completed']),
            'clock' => trim($clock . ($period !== '' ? ' · P' . $period : '') . ($detail && $detail !== $description ? ' · ' . $detail : '')),
        );
    }

    public static function parse_event($raw, $league) {
        if (empty($raw['id']) || empty($raw['date'])) { return null; }
        $competition = !empty($raw['competitions'][0]) ? $raw['competitions'][0] : array();
        $home = array(); $away = array();
        foreach ((array) (isset($competition['competitors']) ? $competition['competitors'] : array()) as $competitor) {
            if (isset($competitor['homeAway']) && $competitor['homeAway'] === 'home') { $home = $competitor; }
            if (isset($competitor['homeAway']) && $competitor['homeAway'] === 'away') { $away = $competitor; }
        }
        $teams = (array) (isset($competition['competitors']) ? $competition['competitors'] : array());
        if (!$home && isset($teams[0])) { $home = $teams[0]; }
        if (!$away && isset($teams[1])) { $away = $teams[1]; }
        $team = function ($item) {
            $data = isset($item['team']) ? $item['team'] : array();
            return array('name' => isset($data['displayName']) ? $data['displayName'] : (isset($data['shortDisplayName']) ? $data['shortDisplayName'] : 'TBD'), 'score' => isset($item['score']) ? (string) $item['score'] : '', 'logo' => isset($data['logo']) ? $data['logo'] : '');
        };
        $status = isset($raw['status']['type']['description']) ? $raw['status']['type']['description'] : 'Scheduled';
        $start = strtotime($raw['date']);
        if (!$start) { return null; }
        $event_id = (string) $raw['id'];
        $event = array(
            'event_id' => $event_id,
            'key' => $league['id'] . '|' . $event_id,
            'league_id' => $league['id'], 'league_name' => $league['name'], 'sport' => $league['sport'], 'slug' => $league['slug'], 'type' => $league['type'],
            'name' => isset($raw['name']) ? $raw['name'] : (isset($raw['shortName']) ? $raw['shortName'] : $league['name'] . ' event'),
            'home' => $team($home), 'away' => $team($away), 'status' => $status, 'state' => isset($raw['status']['type']['state']) ? $raw['status']['type']['state'] : '',
            'completed' => !empty($raw['status']['type']['completed']), 'start_utc' => $start, 'score_home' => $team($home)['score'], 'score_away' => $team($away)['score'], 'clock' => isset($raw['status']['displayClock']) ? $raw['status']['displayClock'] : '',
        );
        return $event;
    }

    private static function parse_race($raw, $league) {
        $id = isset($raw['idEvent']) ? (string) $raw['idEvent'] : '';
        if (!$id) { return null; }
        $date = isset($raw['strTimestamp']) && $raw['strTimestamp'] ? strtotime($raw['strTimestamp']) : (isset($raw['dateEvent']) ? strtotime($raw['dateEvent'] . ' ' . (isset($raw['strTime']) ? $raw['strTime'] : '12:00:00') . ' UTC') : 0);
        if (!$date) { return null; }
        return array('event_id' => $id, 'key' => $league['id'] . '|' . $id, 'league_id' => $league['id'], 'league_name' => $league['name'], 'sport' => $league['sport'], 'slug' => $league['slug'], 'type' => 'race', 'name' => isset($raw['strEvent']) ? $raw['strEvent'] : $league['name'] . ' event', 'home' => array('name' => '', 'score' => ''), 'away' => array('name' => '', 'score' => ''), 'status' => isset($raw['strStatus']) ? $raw['strStatus'] : 'Scheduled', 'state' => '', 'completed' => false, 'start_utc' => $date, 'score_home' => '', 'score_away' => '', 'clock' => '');
    }

    private static function in_window($event, $dates, $timezone) {
        if (empty($event['start_utc'])) { return false; }
        $local_day = (new DateTimeImmutable('@' . $event['start_utc']))->setTimezone($timezone)->format('Y-m-d');
        return in_array($local_day, $dates, true);
    }

    private static function get_json($url, $timeout, $cache_seconds) {
        $cache_key = 's803_feed_' . md5($url);
        $cached = get_transient($cache_key);
        if (is_array($cached)) { return $cached; }
        $response = wp_remote_get($url, array('timeout' => $timeout, 'redirection' => 3, 'headers' => array('Accept' => 'application/json', 'User-Agent' => 'Sports803-WordPress-Autopilot/' . S803_PLUGIN_VERSION)));
        if (is_wp_error($response)) { return $response; }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) { return new WP_Error('s803_feed_http', 'Feed returned HTTP ' . $status, array('status' => $status)); }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) { return new WP_Error('s803_feed_json', 'Feed returned invalid JSON.'); }
        set_transient($cache_key, $data, $cache_seconds);
        return $data;
    }
}

final class S803_OneBall_Provider implements S803_Highlight_Provider {
    const FEED_URL = 'https://oneball.live/list.json';
    const PLAYER_URL = 'https://www.sport803.online/p/player.html';

    public function key() { return 'oneball'; }

    public static function load_feed() {
        $cache_key = 's803_oneball_feed';
        $cached = get_transient($cache_key);
        if (is_array($cached)) { return $cached; }
        $response = wp_remote_get(self::FEED_URL, array('timeout' => 10, 'redirection' => 3, 'headers' => array('Accept' => 'application/json', 'User-Agent' => 'Sports803-WordPress-Autopilot/' . S803_PLUGIN_VERSION)));
        if (is_wp_error($response)) { return $response; }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) { return new WP_Error('s803_oneball_http', 'OneBall feed returned HTTP ' . $status, array('status' => $status)); }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) { return new WP_Error('s803_oneball_json', 'OneBall feed returned invalid JSON.'); }
        $entries = isset($data['matches']) ? $data['matches'] : (isset($data['data']) ? $data['data'] : (isset($data['list']) ? $data['list'] : $data));
        if (!is_array($entries)) { $entries = array(); }
        $normalized = array();
        foreach ($entries as $entry) {
            if (!is_array($entry)) { continue; }
            $id = isset($entry['nami_id']) ? $entry['nami_id'] : (isset($entry['id']) ? $entry['id'] : '');
            $home = isset($entry['home_team']) ? $entry['home_team'] : (isset($entry['home_team_name']) ? $entry['home_team_name'] : (isset($entry['home']['name']) ? $entry['home']['name'] : (isset($entry['home']) ? $entry['home'] : '')));
            $away = isset($entry['away_team']) ? $entry['away_team'] : (isset($entry['away_team_name']) ? $entry['away_team_name'] : (isset($entry['away']['name']) ? $entry['away']['name'] : (isset($entry['away']) ? $entry['away'] : '')));
            if ($id !== '' && is_string($home) && is_string($away)) { $normalized[] = array('id' => (string) $id, 'home' => $home, 'away' => $away); }
        }
        set_transient($cache_key, $normalized, 240);
        return $normalized;
    }

    public function find_verified_replay($event, $feed) {
        if (is_wp_error($feed)) { return $feed; }
        $candidate = !empty($event['oneball_id']) ? array('id' => (string) $event['oneball_id']) : self::find_match($event, $feed);
        if (!$candidate) { return null; }
        $url = self::replay_url($candidate['id']);
        $response = wp_remote_get($url, array('timeout' => 12, 'redirection' => 3, 'limit_response_size' => 65536, 'headers' => array('User-Agent' => 'Sports803-WordPress-Autopilot/' . S803_PLUGIN_VERSION)));
        if (is_wp_error($response)) { return $response; }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status < 200 || $status >= 300 || $body === '' || !preg_match('/<video\b|<iframe\b|\.m3u8|\.mp4|jwplayer|videojs|hls\.js/i', $body)) {
            return new WP_Error('s803_oneball_unverified', 'OneBall replay page was not available (HTTP ' . $status . ').', array('status' => $status));
        }
        return array('provider' => $this->key(), 'id' => $candidate['id'], 'url' => $url, 'player_url' => self::player_url($candidate['id']));
    }

    public static function find_match($event, $feed) {
        if (!is_array($feed) || empty($event['home']['name']) || empty($event['away']['name'])) { return null; }
        foreach ($feed as $entry) {
            $same = (self::team_match($event['home']['name'], $entry['home']) && self::team_match($event['away']['name'], $entry['away'])) || (self::team_match($event['home']['name'], $entry['away']) && self::team_match($event['away']['name'], $entry['home']));
            if ($same && preg_match('/^[A-Za-z0-9_-]+$/', $entry['id'])) { return $entry; }
        }
        return null;
    }

    public static function replay_url($id) {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $id);
        return 'https://oneball.live/replay/' . rawurlencode($id) . '.htm';
    }

    public static function player_url($id) {
        return self::PLAYER_URL . '?one=' . self::replay_url($id);
    }

    public static function live_player_url($id) {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $id);
        return self::PLAYER_URL . '?one=https://oneball.live/live/' . rawurlencode($id) . '.htm';
    }

    private static function team_key($value) {
        $value = function_exists('remove_accents') ? remove_accents(strtolower((string) $value)) : strtolower((string) $value);
        $value = preg_replace('/\b(fc|afc|rfc|sc|cf|ac|as|bsc|rcd|rc|vfb|vfl|real|deportivo|athletic|clube|club|city|united|town|rovers|wanderers|albion|hotspur|villa|forest|palace|wolves|celtic|rangers)\b/', '', $value);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', ' ', $value)));
    }

    private static function team_match($left, $right) {
        $a = self::team_key($left); $b = self::team_key($right);
        if (!$a || !$b) { return false; }
        if ($a === $b || strpos($a, $b) !== false || strpos($b, $a) !== false) { return true; }
        $a_tokens = array_values(array_filter(explode(' ', $a), function ($token) { return strlen($token) > 3; }));
        $b_tokens = array_values(array_filter(explode(' ', $b), function ($token) { return strlen($token) > 3; }));
        return ($a_tokens && count(array_filter($a_tokens, function ($token) use ($b) { return strpos($b, $token) !== false; })) === count($a_tokens)) || ($b_tokens && count(array_filter($b_tokens, function ($token) use ($a) { return strpos($a, $token) !== false; })) === count($b_tokens));
    }
}
