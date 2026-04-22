<?php
if (!defined('ABSPATH')) exit;
if (!defined('SOD_WORLD_MONITOR_ADDON_ACTIVE')) define('SOD_WORLD_MONITOR_ADDON_ACTIVE', true);

if (!function_exists('sod_wm_table_exists')) {
function sod_wm_table_exists(string $table): bool {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
}
}

if (!function_exists('sod_wm_columns')) {
function sod_wm_columns(string $table): array {
    global $wpdb;
    $cols = [];
    $rows = (array) $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
    foreach ($rows as $row) {
        $field = (string) ($row['Field'] ?? '');
        if ($field !== '') $cols[$field] = true;
    }
    return $cols;
}
}

if (!function_exists('sod_wm_pick')) {
function sod_wm_pick(array $cols, array $candidates, string $default = "''"): string {
    foreach ($candidates as $candidate) {
        if (isset($cols[$candidate])) return $candidate;
    }
    return $default;
}
}

if (!function_exists('sod_wm_normalize_timestamp')) {
function sod_wm_normalize_timestamp($value): int {
    if (is_numeric($value)) {
        $ts = (int) $value;
        return $ts > 1000000000 ? $ts : time();
    }
    $text = trim((string) $value);
    if ($text === '') return time();
    $ts = strtotime($text);
    return $ts && $ts > 0 ? $ts : time();
}
}

if (!function_exists('sod_wm_contains')) {
function sod_wm_contains(string $haystack, string $needle): bool {
    if ($haystack === '' || $needle === '') return false;
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle) !== false;
    }
    return stripos($haystack, $needle) !== false;
}
}

if (!function_exists('sod_wm_known_coords')) {
function sod_wm_known_coords(): array {
    return [
        'لبنان' => [33.8547, 35.8623],
        'بيروت' => [33.8938, 35.5018],
        'الضاحية' => [33.8500, 35.5000],
        'الجنوب' => [33.2700, 35.3000],
        'فلسطين' => [31.9522, 35.2332],
        'غزة' => [31.3547, 34.3088],
        'الضفة الغربية' => [31.9000, 35.2000],
        'القدس' => [31.7683, 35.2137],
        'إسرائيل' => [31.0461, 34.8516],
        'الأراضي المحتلة' => [31.0461, 34.8516],
        'سوريا' => [34.8021, 38.9968],
        'دمشق' => [33.5138, 36.2765],
        'حلب' => [36.2021, 37.1343],
        'العراق' => [33.2232, 43.6793],
        'بغداد' => [33.3152, 44.3661],
        'إيران' => [32.4279, 53.6880],
        'طهران' => [35.6892, 51.3890],
        'اليمن' => [15.5527, 48.5164],
        'صنعاء' => [15.3694, 44.1910],
        'الأردن' => [30.5852, 36.2384],
        'مصر' => [26.8206, 30.8025],
        'سيناء' => [29.5000, 33.8000],
        'السعودية' => [23.8859, 45.0792],
        'تركيا' => [38.9637, 35.2433],
        'روسيا' => [61.5240, 105.3188],
        'أوكرانيا' => [48.3794, 31.1656],
        'الولايات المتحدة' => [39.8283, -98.5795],
        'أمريكا' => [39.8283, -98.5795],
        'الصين' => [35.8617, 104.1954],
        'تايوان' => [23.6978, 120.9605],
        'الهند' => [20.5937, 78.9629],
        'باكستان' => [30.3753, 69.3451],
        'أفغانستان' => [33.9391, 67.7100],
        'ليبيا' => [26.3351, 17.2283],
        'السودان' => [12.8628, 30.2176],
        'البحر الأحمر' => [20.0000, 38.0000],
        'البحر المتوسط' => [34.5000, 18.5000],
        'اليونان' => [39.0742, 21.8243],
        'قبرص' => [35.1264, 33.4299],
        'أوروبا' => [50.1109, 8.6821],
        'آسيا' => [34.0479, 100.6197],
        'أفريقيا' => [1.6508, 17.6791],
    ];
}
}

if (!function_exists('sod_wm_guess_coords')) {
function sod_wm_guess_coords(string $region): ?array {
    $region = trim(wp_strip_all_tags($region));
    if ($region === '') return null;
    $known = sod_wm_known_coords();
    if (isset($known[$region])) {
        return ['lat' => (float) $known[$region][0], 'lon' => (float) $known[$region][1], 'confidence' => 0.40];
    }
    foreach ($known as $name => $coords) {
        if (sod_wm_contains($region, $name) || sod_wm_contains($name, $region)) {
            return ['lat' => (float) $coords[0], 'lon' => (float) $coords[1], 'confidence' => 0.25];
        }
    }
    return null;
}
}

if (!function_exists('sod_wm_coords')) {
function sod_wm_coords(string $region): ?array {
    global $wpdb;
    $region = trim(wp_strip_all_tags($region));
    if ($region === '') return null;

    $geo = $wpdb->prefix . 'so_geo_cache';
    if (sod_wm_table_exists($geo)) {
        $hash = md5(function_exists('mb_strtolower') ? mb_strtolower($region, 'UTF-8') : strtolower($region));
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT lat, lon, confidence FROM {$geo} WHERE place_name=%s OR place_hash=%s ORDER BY confidence DESC LIMIT 1",
                $region,
                $hash
            ),
            ARRAY_A
        );
        if (!$row) {
            $like = '%' . $wpdb->esc_like($region) . '%';
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT lat, lon, confidence FROM {$geo} WHERE place_name LIKE %s ORDER BY confidence DESC LIMIT 1", $like),
                ARRAY_A
            );
        }
        if ($row && $row['lat'] !== null && $row['lon'] !== null) {
            return [
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'confidence' => (float) ($row['confidence'] ?? 0),
            ];
        }
    }

    return sod_wm_guess_coords($region);
}
}

if (!function_exists('sod_wm_severity_from_score')) {
function sod_wm_severity_from_score(int $score): string {
    if ($score >= 140) return 'critical';
    if ($score >= 100) return 'high';
    if ($score >= 60) return 'moderate';
    return 'low';
}
}

if (!function_exists('sod_wm_severity_label')) {
function sod_wm_severity_label(string $severity): string {
    switch ($severity) {
        case 'critical':
            return 'حرج';
        case 'high':
            return 'مرتفع';
        case 'moderate':
            return 'متوسط';
        default:
            return 'خفيف';
    }
}
}

if (!function_exists('sod_wm_time_ago')) {
function sod_wm_time_ago(int $timestamp): string {
    $diff = max(1, time() - $timestamp);
    if ($diff < MINUTE_IN_SECONDS) return 'الآن';
    if ($diff < HOUR_IN_SECONDS) return floor($diff / MINUTE_IN_SECONDS) . ' د';
    if ($diff < DAY_IN_SECONDS) return floor($diff / HOUR_IN_SECONDS) . ' س';
    return floor($diff / DAY_IN_SECONDS) . ' ي';
}
}

if (!function_exists('sod_wm_extract_layers')) {
function sod_wm_extract_layers(array $row): array {
    $layers = [];

    $raw_layers = (string) ($row['hybrid_layers'] ?? '');
    if ($raw_layers !== '') {
        $decoded_layers = json_decode($raw_layers, true);
        if (is_array($decoded_layers)) {
            $layers = $decoded_layers;
        } else {
            $layers = preg_split('/[,|،]+/u', $raw_layers) ?: [];
        }
    }

    if (!$layers) {
        foreach (['war_data', 'field_data'] as $field) {
            $decoded = json_decode((string) ($row[$field] ?? ''), true);
            if (!is_array($decoded)) continue;
            foreach (['hybrid_layers', 'active_layers'] as $key) {
                if (empty($decoded[$key])) continue;
                $candidate = $decoded[$key];
                if (is_string($candidate)) {
                    $candidate = preg_split('/[,|،]+/u', $candidate) ?: [];
                }
                if (is_array($candidate)) {
                    $layers = array_merge($layers, $candidate);
                }
            }
        }
    }

    $out = [];
    foreach ((array) $layers as $layer) {
        $layer = sanitize_text_field(trim((string) $layer));
        if ($layer !== '') $out[$layer] = true;
    }
    return array_keys($out);
}
}

if (!function_exists('sod_wm_stream_embed_url')) {
function sod_wm_stream_embed_url(string $url, string $type = 'live'): string {
    $url = esc_url_raw($url);
    if ($url === '') return '';
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    $query = (string) wp_parse_url($url, PHP_URL_QUERY);

    if (strpos($path, '/embed/') !== false || strpos($host, 'player.') !== false) {
        return $url;
    }

    if (strpos($host, 'youtube.com') !== false) {
        parse_str($query, $params);
        if (!empty($params['v'])) {
            return 'https://www.youtube.com/embed/' . rawurlencode((string) $params['v']) . '?rel=0&autoplay=0';
        }
    }
    if (strpos($host, 'youtu.be') !== false) {
        $video_id = trim($path, '/');
        if ($video_id !== '') {
            return 'https://www.youtube.com/embed/' . rawurlencode($video_id) . '?rel=0&autoplay=0';
        }
    }
    if (strpos($host, 'vimeo.com') !== false) {
        $video_id = trim($path, '/');
        if ($video_id !== '') {
            return 'https://player.vimeo.com/video/' . rawurlencode($video_id);
        }
    }

    return $type === 'embed' ? $url : '';
}
}

if (!function_exists('sod_wm_video_streams')) {
function sod_wm_video_streams(int $limit = 6): array {
    $limit = max(1, min(12, $limit));
    $streams = get_option('so_video_streams', []);
    if (!is_array($streams)) return [];

    $out = [];
    foreach ($streams as $stream) {
        if (!is_array($stream)) continue;
        $name = sanitize_text_field((string) ($stream['name'] ?? ''));
        $url = esc_url_raw((string) ($stream['url'] ?? ''));
        $type = sanitize_key((string) ($stream['type'] ?? 'live'));
        if ($name === '' || $url === '') continue;
        if (!in_array($type, ['live', 'camera', 'embed'], true)) $type = 'live';
        $out[] = [
            'name' => $name,
            'url' => $url,
            'type' => $type,
            'embed_url' => sod_wm_stream_embed_url($url, $type),
            'host' => (string) wp_parse_url($url, PHP_URL_HOST),
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}
}

if (!function_exists('sod_wm_fetch_rows')) {
function sod_wm_fetch_rows(int $days, int $limit): array {
    global $wpdb;

    $table = $wpdb->prefix . 'so_news_events';
    $rows = [];

    if (sod_wm_table_exists($table)) {
        $cols = sod_wm_columns($table);
        $title_col  = sod_wm_pick($cols, ['title', 'post_title'], "''");
        $region_col = sod_wm_pick($cols, ['region', 'agency_loc'], "''");
        $actor_col  = sod_wm_pick($cols, ['actor_v2', 'primary_actor'], "''");
        $type_col   = sod_wm_pick($cols, ['intel_type', 'event_type'], "''");
        $score_col  = sod_wm_pick($cols, ['score', 'threat_score'], '0');
        $ts_col     = sod_wm_pick($cols, ['event_timestamp', 'created_at'], 'UNIX_TIMESTAMP()');
        $status_col = sod_wm_pick($cols, ['status'], "''");
        $source_col = sod_wm_pick($cols, ['source_name', 'source'], "''");
        $link_col   = sod_wm_pick($cols, ['link'], "''");
        $field_col  = sod_wm_pick($cols, ['field_data'], "''");
        $hybrid_col = sod_wm_pick($cols, ['hybrid_layers'], "''");
        $war_col    = sod_wm_pick($cols, ['war_data'], "''");

        $where = [];
        if ($status_col !== "''") {
            $where[] = "({$status_col}='published' OR {$status_col}='publish' OR {$status_col}='approved' OR {$status_col}='active' OR {$status_col}='')";
        }
        if ($ts_col !== 'UNIX_TIMESTAMP()') {
            $cutoff = time() - ($days * DAY_IN_SECONDS);
            $where[] = $wpdb->prepare("{$ts_col} >= %d", $cutoff);
        }
        $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT id,
            {$title_col} AS title,
            {$region_col} AS region,
            {$actor_col} AS actor,
            {$type_col} AS intel_type,
            {$score_col} AS score,
            {$ts_col} AS event_timestamp,
            {$source_col} AS source_name,
            {$link_col} AS link,
            {$field_col} AS field_data,
            {$hybrid_col} AS hybrid_layers,
            {$war_col} AS war_data
            FROM {$table}
            {$where_sql}
            ORDER BY " . ($ts_col !== 'UNIX_TIMESTAMP()' ? $ts_col : 'id') . " DESC
            LIMIT " . intval($limit);

        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
    }

    if ($rows) return $rows;

    $posts = $wpdb->posts;
    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID AS id, post_title AS title, post_date_gmt, guid AS link
             FROM {$posts}
             WHERE post_status='publish' AND post_type='post'
             ORDER BY post_date_gmt DESC
             LIMIT %d",
            $limit
        ),
        ARRAY_A
    );

    foreach ($rows as &$row) {
        $id = (int) ($row['id'] ?? 0);
        $row['event_timestamp'] = sod_wm_normalize_timestamp($row['post_date_gmt'] ?? '');
        $row['region'] = get_post_meta($id, 'region', true) ?: get_post_meta($id, 'so_region', true) ?: 'عام';
        $row['actor'] = get_post_meta($id, 'actor_v2', true) ?: get_post_meta($id, 'primary_actor', true) ?: get_post_meta($id, 'so_actor', true) ?: 'غير محدد';
        $row['intel_type'] = get_post_meta($id, 'intel_type', true) ?: get_post_meta($id, 'event_type', true) ?: 'عام';
        $row['score'] = (int) (get_post_meta($id, 'score', true) ?: get_post_meta($id, 'threat_score', true) ?: get_post_meta($id, 'so_score', true) ?: 0);
        $row['source_name'] = 'WordPress';
        $row['field_data'] = get_post_meta($id, 'field_data', true) ?: '';
        $row['hybrid_layers'] = get_post_meta($id, 'hybrid_layers', true) ?: '';
        $row['war_data'] = get_post_meta($id, 'war_data', true) ?: '';
    }
    unset($row);

    return $rows;
}
}

if (!function_exists('sod_wm_build_overview')) {
function sod_wm_build_overview(array $rows): array {
    $now = time();
    $analytics_24h = [
        'total' => 0,
        'critical' => 0,
        'high' => 0,
        'avg_score' => 0,
        'hot_region' => '',
        'escalation_index' => 0,
        'deception_index' => 0,
        'gci' => 0,
        'truth_layers' => [],
        'war_directions' => [],
        'early_warnings' => [],
    ];
    $analytics_72h = [
        'total' => 0,
        'critical' => 0,
        'avg_score' => 0,
    ];

    $score_sum_24 = 0;
    $score_sum_72 = 0;
    $prev24_total = 0;
    $actor_scores = [];
    $actor_events = [];
    $region_scores = [];
    $type_counts = [];
    $layer_counts = [];
    $hourly = [];
    $warnings = [];

    foreach ($rows as $row) {
        $ts = sod_wm_normalize_timestamp($row['event_timestamp'] ?? 0);
        $score = (int) ($row['score'] ?? 0);
        $region = sanitize_text_field((string) ($row['region'] ?? 'عام'));
        $actor = sanitize_text_field((string) ($row['actor'] ?? 'غير محدد'));
        $type = sanitize_text_field((string) ($row['intel_type'] ?? 'عام'));
        $title = wp_strip_all_tags((string) ($row['title'] ?? ''));

        if ($ts >= ($now - (72 * HOUR_IN_SECONDS))) {
            $analytics_72h['total']++;
            $score_sum_72 += $score;
            if ($score >= 140) $analytics_72h['critical']++;
            $hour_key = gmdate('Y-m-d H:00', $ts);
            $hourly[$hour_key] = (int) ($hourly[$hour_key] ?? 0) + 1;
        }

        if ($ts >= ($now - DAY_IN_SECONDS)) {
            $analytics_24h['total']++;
            $score_sum_24 += $score;
            if ($score >= 140) $analytics_24h['critical']++;
            if ($score >= 100) $analytics_24h['high']++;
            $region_scores[$region] = (int) ($region_scores[$region] ?? 0) + max(1, $score);
            $actor_scores[$actor] = (int) ($actor_scores[$actor] ?? 0) + max(1, $score);
            $actor_events[$actor] = (int) ($actor_events[$actor] ?? 0) + 1;
            $type_counts[$type] = (int) ($type_counts[$type] ?? 0) + 1;
            foreach (sod_wm_extract_layers($row) as $layer) {
                $layer_counts[$layer] = (int) ($layer_counts[$layer] ?? 0) + 1;
            }
            if ($title !== '') {
                $warnings[] = ['title' => $title, 'score' => $score];
            }
        } elseif ($ts >= ($now - (2 * DAY_IN_SECONDS))) {
            $prev24_total++;
        }
    }

    $analytics_24h['avg_score'] = $analytics_24h['total'] ? (int) round($score_sum_24 / $analytics_24h['total']) : 0;
    $analytics_72h['avg_score'] = $analytics_72h['total'] ? (int) round($score_sum_72 / $analytics_72h['total']) : 0;

    arsort($region_scores);
    arsort($actor_scores);
    arsort($type_counts);
    arsort($layer_counts);
    usort($warnings, static function ($a, $b): int {
        return ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0));
    });

    $analytics_24h['hot_region'] = !empty($region_scores) ? (string) array_key_first($region_scores) : '';
    $analytics_24h['escalation_index'] = min(100, (int) round(($analytics_24h['critical'] * 16) + ($analytics_24h['high'] * 7) + ($analytics_24h['total'] * 1.5)));
    $analytics_24h['deception_index'] = min(100, (int) round((array_sum($layer_counts) / max(1, $analytics_24h['total'])) * 22));
    $analytics_24h['gci'] = min(100, (int) round(($analytics_24h['avg_score'] * 0.42) + ($analytics_24h['critical'] * 8) + (count($region_scores) * 2.4)));
    $analytics_24h['truth_layers'] = array_values(array_map(static function ($name, $count): array {
        return ['name' => (string) $name, 'count' => (int) $count];
    }, array_keys(array_slice($layer_counts, 0, 5, true)), array_values(array_slice($layer_counts, 0, 5, true))));
    $analytics_24h['war_directions'] = array_values(array_map(static function ($name, $count): array {
        return ['name' => (string) $name, 'count' => (int) $count];
    }, array_keys(array_slice($type_counts, 0, 5, true)), array_values(array_slice($type_counts, 0, 5, true))));
    $analytics_24h['early_warnings'] = array_values(array_map(static function ($item): string {
        return (string) ($item['title'] ?? '');
    }, array_slice($warnings, 0, 3)));

    $actor_threats = [];
    foreach (array_slice($actor_scores, 0, 8, true) as $actor => $score_total) {
        $actor_threats[] = [
            'actor' => (string) $actor,
            'score' => (int) $score_total,
            'events' => (int) ($actor_events[$actor] ?? 0),
        ];
    }

    $top_regions = [];
    foreach (array_slice($region_scores, 0, 8, true) as $region => $score_total) {
        $top_regions[] = ['name' => (string) $region, 'score' => (int) $score_total];
    }

    $trend_data = [];
    for ($i = 11; $i >= 0; $i--) {
        $slot = strtotime("-{$i} hours", $now);
        $key = gmdate('Y-m-d H:00', $slot);
        $trend_data[] = [
            'label' => wp_date('H:i', $slot, wp_timezone()),
            'value' => (int) ($hourly[$key] ?? 0),
        ];
    }

    $momentum_change = 0;
    if ($prev24_total > 0) {
        $momentum_change = (int) round((($analytics_24h['total'] - $prev24_total) / $prev24_total) * 100);
    } elseif ($analytics_24h['total'] > 0) {
        $momentum_change = 100;
    }

    return [
        'analytics_24h' => $analytics_24h,
        'analytics_72h' => $analytics_72h,
        'actor_threats' => $actor_threats,
        'top_regions' => $top_regions,
        'trend_data' => $trend_data,
        'momentum_change' => $momentum_change,
    ];
}
}

if (!function_exists('sod_wm_snapshot')) {
function sod_wm_snapshot(int $days = 7, int $limit = 250): array {
    $days = max(1, min(365, $days));
    $limit = max(20, min(500, $limit));
    $cache_key = 'sod_wm_snapshot_' . md5($days . '|' . $limit);
    $cached = get_transient($cache_key);
    if (is_array($cached)) return $cached;

    $rows = sod_wm_fetch_rows($days, $limit);
    $known = sod_wm_known_coords();

    $total = 0;
    $critical = 0;
    $high = 0;
    $moderate = 0;
    $low = 0;

    $regions = [];
    $actors = [];
    $types = [];
    $hybrid_layers = [];
    $trend = [];
    $markers = [];
    $feed = [];
    $source_names = [];

    foreach ($rows as $row) {
        $title = wp_strip_all_tags((string) ($row['title'] ?? ''));
        if ($title === '') continue;

        $score = (int) ($row['score'] ?? 0);
        $severity = sod_wm_severity_from_score($score);
        $region = sanitize_text_field((string) (($row['region'] ?? '') ?: 'عام'));
        $actor = sanitize_text_field((string) (($row['actor'] ?? '') ?: 'غير محدد'));
        $type = sanitize_text_field((string) (($row['intel_type'] ?? '') ?: 'عام'));
        $source = sanitize_text_field((string) ($row['source_name'] ?? 'النظام'));
        $ts = sod_wm_normalize_timestamp($row['event_timestamp'] ?? 0);
        $time_label = wp_date('H:i', $ts, wp_timezone());

        $total++;
        if ($severity === 'critical') $critical++;
        elseif ($severity === 'high') $high++;
        elseif ($severity === 'moderate') $moderate++;
        else $low++;

        $regions[$region] = (int) ($regions[$region] ?? 0) + 1;
        $actors[$actor] = (int) ($actors[$actor] ?? 0) + 1;
        $types[$type] = (int) ($types[$type] ?? 0) + 1;
        if ($source !== '') $source_names[$source] = true;

        foreach (sod_wm_extract_layers($row) as $layer) {
            $hybrid_layers[$layer] = (int) ($hybrid_layers[$layer] ?? 0) + 1;
        }

        $day_key = wp_date('Y-m-d', $ts, wp_timezone());
        if (!isset($trend[$day_key])) {
            $trend[$day_key] = ['total' => 0, 'critical' => 0, 'high' => 0, 'moderate' => 0, 'low' => 0];
        }
        $trend[$day_key]['total']++;
        $trend[$day_key][$severity] = (int) ($trend[$day_key][$severity] ?? 0) + 1;

        $coords = sod_wm_coords($region);
        if (!$coords && isset($known[$region])) {
            $coords = ['lat' => (float) $known[$region][0], 'lon' => (float) $known[$region][1]];
        }

        if ($coords && count($markers) < 180) {
            $markers[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => $title,
                'region' => $region,
                'actor' => $actor,
                'type' => $type,
                'score' => $score,
                'lat' => (float) ($coords['lat'] ?? 0),
                'lon' => (float) ($coords['lon'] ?? 0),
                'severity' => $severity,
                'severity_label' => sod_wm_severity_label($severity),
                'time' => $ts,
                'time_label' => $time_label,
                'link' => esc_url_raw((string) ($row['link'] ?? '')),
            ];
        }

        if (count($feed) < 40) {
            $feed[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => $title,
                'region' => $region,
                'actor' => $actor,
                'type' => $type,
                'score' => $score,
                'source' => $source,
                'severity' => $severity,
                'severity_label' => sod_wm_severity_label($severity),
                'time' => $ts,
                'time_label' => $time_label,
                'ago_label' => sod_wm_time_ago($ts),
                'link' => esc_url_raw((string) ($row['link'] ?? '')),
            ];
        }
    }

    arsort($regions);
    arsort($actors);
    arsort($types);
    arsort($hybrid_layers);
    ksort($trend);

    $risk_index = min(100, (int) round((($critical * 4) + ($high * 3) + ($moderate * 2) + $low) / max(1, $total * 4) * 100));
    $hybrid_intensity = min(100, (int) round((array_sum($hybrid_layers) / max(1, $total)) * 100));
    $top_hybrid_layer = !empty($hybrid_layers) ? (string) array_key_first($hybrid_layers) : '';

    $urgent = $feed;
    usort($urgent, static function ($a, $b): int {
        $score_cmp = ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0));
        if ($score_cmp !== 0) return $score_cmp;
        return ((int) ($b['time'] ?? 0)) <=> ((int) ($a['time'] ?? 0));
    });
    $urgent = array_values(array_slice($urgent, 0, 10));

    $ticker_items = array_values(array_map(static function ($item): array {
        return [
            'title' => (string) ($item['title'] ?? ''),
            'score' => (int) ($item['score'] ?? 0),
            'region' => (string) ($item['region'] ?? 'عام'),
            'type' => (string) ($item['type'] ?? 'عام'),
            'time' => (int) ($item['time'] ?? time()),
            'time_label' => (string) ($item['time_label'] ?? ''),
            'severity' => (string) ($item['severity'] ?? 'moderate'),
        ];
    }, array_slice($urgent, 0, 20)));

    $video_streams = sod_wm_video_streams(6);

    $payload = [
        'kpi' => [
            'total' => $total,
            'critical' => $critical,
            'high' => $high,
            'moderate' => $moderate,
            'low' => $low,
            'countries' => count($regions),
            'hotspots' => count(array_filter($regions, static function ($count): bool { return (int) $count >= 2; })),
            'risk_index' => $risk_index,
            'hybrid_intensity' => $hybrid_intensity,
            'top_hybrid_layer' => $top_hybrid_layer,
            'updated_at' => time(),
        ],
        'trend' => array_values(array_map(static function ($date, $bucket): array {
            return [
                'date' => (string) $date,
                'total' => (int) ($bucket['total'] ?? 0),
                'critical' => (int) ($bucket['critical'] ?? 0),
                'high' => (int) ($bucket['high'] ?? 0),
                'moderate' => (int) ($bucket['moderate'] ?? 0),
                'low' => (int) ($bucket['low'] ?? 0),
            ];
        }, array_keys($trend), array_values($trend))),
        'regions' => array_values(array_map(static function ($name, $count): array {
            return ['name' => (string) $name, 'count' => (int) $count];
        }, array_keys(array_slice($regions, 0, 10, true)), array_values(array_slice($regions, 0, 10, true)))),
        'actors' => array_values(array_map(static function ($name, $count): array {
            return ['name' => (string) $name, 'count' => (int) $count];
        }, array_keys(array_slice($actors, 0, 10, true)), array_values(array_slice($actors, 0, 10, true)))),
        'types' => array_values(array_map(static function ($name, $count): array {
            return ['name' => (string) $name, 'count' => (int) $count];
        }, array_keys(array_slice($types, 0, 10, true)), array_values(array_slice($types, 0, 10, true)))),
        'hybrid_layers' => array_values(array_map(static function ($name, $count): array {
            return ['name' => (string) $name, 'count' => (int) $count];
        }, array_keys(array_slice($hybrid_layers, 0, 10, true)), array_values(array_slice($hybrid_layers, 0, 10, true)))),
        'markers' => $markers,
        'feed' => $feed,
        'urgent' => $urgent,
        'ticker_items' => $ticker_items,
        'severity_breakdown' => [
            ['key' => 'critical', 'label' => 'حرج', 'count' => $critical, 'color' => '#ff4d5f'],
            ['key' => 'high', 'label' => 'مرتفع', 'count' => $high, 'color' => '#ff8a3d'],
            ['key' => 'moderate', 'label' => 'متوسط', 'count' => $moderate, 'color' => '#ffbf3c'],
            ['key' => 'low', 'label' => 'خفيف', 'count' => $low, 'color' => '#39d98a'],
        ],
        'threat_overview' => sod_wm_build_overview($rows),
        'video_streams' => $video_streams,
        'days' => $days,
        'source_count' => count($source_names),
        'video_count' => count($video_streams),
    ];

    set_transient($cache_key, $payload, 45);
    return $payload;
}
}

if (!function_exists('sod_wm_public_nonce_action')) {
function sod_wm_public_nonce_action(): string {
    return 'sod_wm_public_snapshot';
}
}

if (!function_exists('sod_wm_verify_request')) {
function sod_wm_verify_request(): void {
    $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['nonce'])) : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, sod_wm_public_nonce_action())) {
        wp_send_json_error(['message' => 'nonce_failed'], 403);
    }
}
}

if (!function_exists('sod_wm_ajax_snapshot')) {
function sod_wm_ajax_snapshot(): void {
    $days = isset($_REQUEST['days']) ? (int) $_REQUEST['days'] : 7;
    sod_wm_verify_request();
    wp_send_json_success(sod_wm_snapshot($days));
}
}

add_action('wp_ajax_sod_world_monitor_snapshot', 'sod_wm_ajax_snapshot');
add_action('wp_ajax_nopriv_sod_world_monitor_snapshot', 'sod_wm_ajax_snapshot');

if (!function_exists('sod_render_world_monitor_dashboard')) {
function sod_render_world_monitor_dashboard($atts = []): string {
    $atts = shortcode_atts([
        'default_days' => 7,
        'auto_refresh' => 45,
        'title' => 'World Monitor',
    ], (array) $atts, 'sod_world_monitor');

    $days = max(1, min(365, (int) $atts['default_days']));
    $refresh = max(15, (int) $atts['auto_refresh']);
    $data = sod_wm_snapshot($days);
    $uid = 'sodwm_' . wp_generate_uuid4();
    $nonce = wp_create_nonce(sod_wm_public_nonce_action());
    $json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    $updated_at = wp_date('Y-m-d H:i', (int) ($data['kpi']['updated_at'] ?? time()), wp_timezone());
    $risk_index = (int) ($data['kpi']['risk_index'] ?? 0);
    $risk_label = $risk_index >= 70 ? 'خطر مرتفع' : ($risk_index >= 45 ? 'مراقبة مشددة' : 'مستوى مستقر');
    $source_count = (int) ($data['source_count'] ?? 0);
    $video_count = (int) ($data['video_count'] ?? 0);
    $moderate_count = (int) ($data['kpi']['moderate'] ?? 0);
    $low_count = (int) ($data['kpi']['low'] ?? 0);
    $hotspots_count = (int) ($data['kpi']['hotspots'] ?? 0);
    $countries_count = (int) ($data['kpi']['countries'] ?? 0);
    $safe_count = max(0, $countries_count - $hotspots_count);
    $connection_quality = min(98, 74 + ($source_count * 2) + ($video_count * 4));
    $current_clock = wp_date('H:i:s', time(), wp_timezone());

    ob_start();
    ?>
    <div class="sod-wm" id="<?php echo esc_attr($uid); ?>" dir="rtl">
        <style>
            #<?php echo esc_html($uid); ?>{--bg:#061019;--bg2:#091521;--panel:#0d1824;--panel2:#111f2d;--line:rgba(125,161,198,.18);--text:#eef5ff;--muted:#8ea2bb;--green:#39d98a;--amber:#ffbf3c;--orange:#ff8a3d;--red:#ff4d5f;--blue:#33b4ff;background:radial-gradient(circle at top right,#12283b 0,#08111a 38%,#05080d 100%);color:var(--text);border:1px solid rgba(255,255,255,.07);border-radius:26px;padding:16px;font-family:Tajawal,Arial,sans-serif;box-shadow:0 28px 90px rgba(0,0,0,.35);overflow:hidden}
            #<?php echo esc_html($uid); ?> *{box-sizing:border-box;min-width:0}
            #<?php echo esc_html($uid); ?> a{color:inherit;text-decoration:none}
            #<?php echo esc_html($uid); ?> .sod-wm-headbar{display:grid;grid-template-columns:170px 170px 170px minmax(0,1fr) auto;gap:12px;align-items:stretch;margin-bottom:14px;direction:ltr}
            #<?php echo esc_html($uid); ?> .sod-wm-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-head-card{padding:14px 16px;border-radius:18px;background:linear-gradient(180deg,rgba(14,22,34,.98),rgba(9,15,23,.98));border:1px solid var(--line);display:grid;gap:8px;align-content:center;justify-items:center;box-shadow:inset 0 1px 0 rgba(255,255,255,.03);direction:rtl;text-align:center}
            #<?php echo esc_html($uid); ?> .sod-wm-head-card span{font-size:12px;color:var(--muted);font-weight:700}
            #<?php echo esc_html($uid); ?> .sod-wm-head-card b{font-size:24px;line-height:1;font-weight:900}
            #<?php echo esc_html($uid); ?> .sod-wm-head-card b.is-green{color:var(--green)}
            #<?php echo esc_html($uid); ?> .sod-wm-top-main{padding:16px 18px;border-radius:20px;background:linear-gradient(180deg,rgba(13,22,33,.98),rgba(8,13,20,.98));border:1px solid var(--line);position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;direction:ltr;text-align:center}
            #<?php echo esc_html($uid); ?> .sod-wm-top-main:before{content:'';position:absolute;inset:auto -40px -60px auto;width:180px;height:180px;border-radius:50%;background:radial-gradient(circle,rgba(51,180,255,.18),transparent 65%)}
            #<?php echo esc_html($uid); ?> .sod-wm-brand{display:flex;align-items:center;justify-content:center;gap:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-logo{width:58px;height:58px;border-radius:18px;display:grid;place-items:center;background:radial-gradient(circle at 30% 30%,#17395b,#0d1622 72%);border:1px solid rgba(255,255,255,.08);font-size:24px;font-weight:800}
            #<?php echo esc_html($uid); ?> .sod-wm-title{font-size:31px;font-weight:900;line-height:1}
            #<?php echo esc_html($uid); ?> .sod-wm-sub{margin-top:6px;color:var(--muted);font-size:13px;text-align:center}
            #<?php echo esc_html($uid); ?> .sod-wm-actions{display:flex;align-items:center;justify-self:end;gap:8px;flex-wrap:nowrap}
            #<?php echo esc_html($uid); ?> .sod-wm-select,#<?php echo esc_html($uid); ?> .sod-wm-btn{background:linear-gradient(180deg,#132131,#0c1723);border:1px solid rgba(255,255,255,.08);color:var(--text);border-radius:14px;padding:11px 14px;font:inherit}
            #<?php echo esc_html($uid); ?> .sod-wm-select{min-width:170px}
            #<?php echo esc_html($uid); ?> .sod-wm-btn{font-weight:800;cursor:pointer}
            #<?php echo esc_html($uid); ?> .sod-wm-btn-icon{width:46px;height:46px;padding:0;display:grid;place-items:center;font-size:20px;line-height:1}
            #<?php echo esc_html($uid); ?> .sod-wm-nav{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;padding:10px 12px;border-radius:18px;background:linear-gradient(180deg,rgba(13,20,31,.96),rgba(8,13,19,.96));border:1px solid var(--line);direction:ltr}
            #<?php echo esc_html($uid); ?> .sod-wm-nav a,#<?php echo esc_html($uid); ?> .sod-wm-nav span{padding:11px 16px;border-radius:14px;font-size:13px;font-weight:800}
            #<?php echo esc_html($uid); ?> .sod-wm-nav a{background:transparent;border:1px solid transparent;color:#d4deea}
            #<?php echo esc_html($uid); ?> .sod-wm-nav a:hover{background:rgba(255,255,255,.03);border-color:rgba(255,255,255,.05)}
            #<?php echo esc_html($uid); ?> .sod-wm-nav a.is-active{background:linear-gradient(180deg,#d93b49,#b92535);border-color:rgba(255,255,255,.08)}
            #<?php echo esc_html($uid); ?> .sod-wm-nav-icon{width:52px;height:46px;display:grid;place-items:center;padding:0!important;font-size:22px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.05)}
            #<?php echo esc_html($uid); ?> .sod-wm-clock{background:linear-gradient(180deg,#111c2a,#0b131d);border:1px solid rgba(255,255,255,.08);border-radius:14px;min-width:118px;padding:11px 14px;text-align:center;font-weight:800}
            #<?php echo esc_html($uid); ?> .sod-wm-hero{display:grid;grid-template-columns:minmax(0,1.2fr) .8fr;gap:14px;margin-bottom:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-panel{background:linear-gradient(180deg,rgba(14,22,34,.98),rgba(10,17,26,.98));border:1px solid var(--line);border-radius:22px;padding:16px;direction:rtl;text-align:right}
            #<?php echo esc_html($uid); ?> .sod-wm-pills{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);font-size:12px;font-weight:700}
            #<?php echo esc_html($uid); ?> .sod-wm-dot{width:9px;height:9px;border-radius:999px;background:var(--green);display:inline-block}
            #<?php echo esc_html($uid); ?> .sod-wm-hero-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
            #<?php echo esc_html($uid); ?> .sod-wm-mini{padding:16px;border-radius:18px;background:linear-gradient(180deg,#101b29,#0c1520);border:1px solid rgba(255,255,255,.06)}
            #<?php echo esc_html($uid); ?> .sod-wm-mini b{display:block;font-size:28px;line-height:1}
            #<?php echo esc_html($uid); ?> .sod-wm-mini span{display:block;margin-top:8px;color:var(--muted);font-size:12px}
            #<?php echo esc_html($uid); ?> .sod-wm-strip{display:grid;grid-template-columns:170px minmax(0,1fr);gap:12px;align-items:center;margin-bottom:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-alert{border-radius:16px;padding:14px;background:linear-gradient(180deg,#d82f44,#a91d2f);font-weight:900;text-align:center;box-shadow:0 12px 30px rgba(169,29,47,.2)}
            #<?php echo esc_html($uid); ?> .sod-wm-ticker{overflow:hidden;border-radius:16px;padding:14px;background:linear-gradient(180deg,#101a27,#0c141f);border:1px solid var(--line)}
            #<?php echo esc_html($uid); ?> .sod-wm-track{display:flex;gap:24px;white-space:nowrap;width:max-content;animation:sod-wm-marquee 36s linear infinite}
            #<?php echo esc_html($uid); ?> .sod-wm-track:hover{animation-play-state:paused}
            #<?php echo esc_html($uid); ?> .sod-wm-track-item{display:inline-flex;align-items:center;gap:8px;font-size:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-brief-row{display:grid;grid-template-columns:170px minmax(0,1fr);gap:12px;align-items:stretch;margin-bottom:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-brief-label{display:grid;place-items:center;border-radius:16px;padding:14px;background:linear-gradient(180deg,#132131,#0c1723);border:1px solid var(--line);font-weight:900;text-align:center}
            #<?php echo esc_html($uid); ?> .sod-wm-brief{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:12px 14px;border-radius:16px;background:linear-gradient(180deg,#101a27,#0c141f);border:1px solid var(--line);text-align:center}
            #<?php echo esc_html($uid); ?> .sod-wm-brief-item{padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.05)}
            #<?php echo esc_html($uid); ?> .sod-wm-brief-item b{display:block;font-size:17px;line-height:1.3}
            #<?php echo esc_html($uid); ?> .sod-wm-brief-item span{display:block;margin-top:6px;font-size:12px;color:var(--muted)}
            #<?php echo esc_html($uid); ?> .sod-wm-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:14px;direction:ltr}
            #<?php echo esc_html($uid); ?> .sod-wm-kpi{background:linear-gradient(180deg,rgba(15,24,35,.98),rgba(10,16,24,.98));border:1px solid var(--line);border-radius:20px;padding:16px;display:grid;gap:8px;direction:ltr}
            #<?php echo esc_html($uid); ?> .sod-wm-kpi>div:nth-child(2){direction:rtl;text-align:center}
            #<?php echo esc_html($uid); ?> .sod-wm-kpi-top{display:flex;align-items:center;justify-content:center;gap:10px;color:#cfe0f4;font-size:13px;direction:rtl;text-align:center}
            #<?php echo esc_html($uid); ?> .sod-wm-kpi-val{font-size:34px;font-weight:900;line-height:1}
            #<?php echo esc_html($uid); ?> .sod-wm-kpi-sub{font-size:12px;color:var(--muted)}
            #<?php echo esc_html($uid); ?> .sod-wm-grid{display:grid;grid-template-columns:minmax(0,1.25fr) .85fr;gap:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-main{display:grid;gap:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-side{display:grid;gap:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-card-head{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;margin-bottom:14px;direction:rtl;text-align:center}
            #<?php echo esc_html($uid); ?> .sod-wm-card-head h3{margin:0;font-size:18px}
            #<?php echo esc_html($uid); ?> .sod-wm-card-head small{color:var(--muted)}
            #<?php echo esc_html($uid); ?> .sod-wm-map-wrap{position:relative;height:460px;border-radius:20px;background:
                radial-gradient(circle at 20% 22%,rgba(51,180,255,.14),transparent 18%),
                radial-gradient(circle at 67% 38%,rgba(255,191,60,.12),transparent 16%),
                linear-gradient(180deg,#0d1621,#0a1119);
                border:1px solid rgba(255,255,255,.06);overflow:hidden}
            #<?php echo esc_html($uid); ?> .sod-wm-map-world{position:absolute;inset:24px 18px 18px;z-index:1;opacity:.55}
            #<?php echo esc_html($uid); ?> .sod-wm-map-world svg{width:100%;height:100%}
            #<?php echo esc_html($uid); ?> .sod-wm-map-grid{position:absolute;inset:0;background-image:
                linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
                background-size:56px 56px;opacity:.35}
            #<?php echo esc_html($uid); ?> .sod-wm-map-glow{position:absolute;inset:0;background:
                radial-gradient(circle at 30% 35%,rgba(51,180,255,.10),transparent 22%),
                radial-gradient(circle at 70% 45%,rgba(255,77,95,.10),transparent 20%),
                radial-gradient(circle at 58% 28%,rgba(255,191,60,.08),transparent 18%)}
            #<?php echo esc_html($uid); ?> .sod-wm-marker{position:absolute;transform:translate(-50%,-50%);z-index:2}
            #<?php echo esc_html($uid); ?> .sod-wm-marker button{width:16px;height:16px;border:0;border-radius:999px;cursor:pointer;box-shadow:0 0 0 0 rgba(255,255,255,.1),0 0 22px currentColor;background:currentColor;position:relative}
            #<?php echo esc_html($uid); ?> .sod-wm-marker button:after{content:'';position:absolute;inset:-10px;border-radius:999px;border:1px solid currentColor;opacity:.45;animation:sod-wm-pulse 2.2s ease-out infinite}
            #<?php echo esc_html($uid); ?> .sod-wm-marker span{position:absolute;top:18px;right:-2px;background:rgba(5,10,16,.92);border:1px solid rgba(255,255,255,.08);padding:6px 8px;border-radius:10px;font-size:11px;min-width:120px;display:none}
            #<?php echo esc_html($uid); ?> .sod-wm-marker:hover span{display:block}
            #<?php echo esc_html($uid); ?> .sod-wm-map-legend{position:absolute;right:14px;top:18px;display:grid;gap:12px;padding:14px 16px;border-radius:16px;background:rgba(11,17,25,.84);border:1px solid rgba(255,255,255,.08);z-index:3;min-width:148px;direction:rtl;text-align:right}
            #<?php echo esc_html($uid); ?> .sod-wm-map-legend-title{font-size:14px;font-weight:900}
            #<?php echo esc_html($uid); ?> .sod-wm-legend{display:flex;align-items:center;justify-content:center;gap:10px;font-size:13px}
            #<?php echo esc_html($uid); ?> [data-role="map-layer"]{position:absolute;inset:0;z-index:2}
            #<?php echo esc_html($uid); ?> .sod-wm-list{display:grid;gap:10px}
            #<?php echo esc_html($uid); ?> .sod-wm-list-item{padding:12px 14px;border-radius:16px;background:linear-gradient(180deg,#0f1825,#0c141f);border:1px solid rgba(255,255,255,.06)}
            #<?php echo esc_html($uid); ?> .sod-wm-list-item h4{margin:0 0 8px;font-size:14px;line-height:1.6}
            #<?php echo esc_html($uid); ?> .sod-wm-meta{display:flex;gap:8px;flex-wrap:wrap}
            #<?php echo esc_html($uid); ?> .sod-wm-chip{display:inline-flex;align-items:center;gap:7px;padding:6px 10px;border-radius:999px;background:#0d1521;border:1px solid rgba(255,255,255,.05);font-size:12px}
            #<?php echo esc_html($uid); ?> .sod-wm-video-toolbar{display:grid;gap:10px}
            #<?php echo esc_html($uid); ?> .sod-wm-video-mode{display:flex;justify-content:center;gap:8px;flex-wrap:wrap}
            #<?php echo esc_html($uid); ?> .sod-wm-video-mode-btn{padding:10px 16px;border-radius:12px;background:#0c1520;border:1px solid rgba(255,255,255,.06);color:#dce8f6;font:inherit;font-size:12px;font-weight:800;cursor:pointer}
            #<?php echo esc_html($uid); ?> .sod-wm-video-mode-btn.is-active{background:linear-gradient(180deg,#e84653,#b82534);color:#fff}
            #<?php echo esc_html($uid); ?> .sod-wm-video-tabs{display:flex;justify-content:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;direction:ltr}
            #<?php echo esc_html($uid); ?> .sod-wm-video-tab{padding:10px 14px;border-radius:12px;background:#0c1520;border:1px solid rgba(255,255,255,.06);color:#dce8f6;font:inherit;font-size:12px;font-weight:800;cursor:pointer}
            #<?php echo esc_html($uid); ?> .sod-wm-video-tab.is-active{background:linear-gradient(180deg,#e84653,#b82534);color:#fff}
            #<?php echo esc_html($uid); ?> .sod-wm-video-stage{border-radius:18px;overflow:hidden;border:1px solid rgba(255,255,255,.07);background:linear-gradient(180deg,#070c12,#060a10)}
            #<?php echo esc_html($uid); ?> .sod-wm-video-screen{position:relative;aspect-ratio:16/9;background:
                radial-gradient(circle at 20% 15%,rgba(51,180,255,.08),transparent 22%),
                linear-gradient(180deg,#0c141d,#070b10)}
            #<?php echo esc_html($uid); ?> .sod-wm-video-screen iframe{width:100%;height:100%;border:0;display:block;background:#000}
            #<?php echo esc_html($uid); ?> .sod-wm-video-overlay{position:absolute;top:12px;right:12px;left:12px;display:flex;align-items:center;justify-content:space-between;gap:10px;pointer-events:none;z-index:2}
            #<?php echo esc_html($uid); ?> .sod-wm-video-badge{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:rgba(232,70,83,.92);color:#fff;font-size:12px;font-weight:900}
            #<?php echo esc_html($uid); ?> .sod-wm-video-title{padding:8px 12px;border-radius:999px;background:rgba(5,10,15,.78);border:1px solid rgba(255,255,255,.08);font-size:12px;font-weight:800;max-width:70%}
            #<?php echo esc_html($uid); ?> .sod-wm-video-fallback{position:absolute;inset:0;display:grid;place-items:center;padding:24px;text-align:center}
            #<?php echo esc_html($uid); ?> .sod-wm-video-fallback strong{display:block;font-size:22px;margin-bottom:8px}
            #<?php echo esc_html($uid); ?> .sod-wm-video-fallback p{margin:0 0 14px;color:var(--muted);line-height:1.7}
            #<?php echo esc_html($uid); ?> .sod-wm-video-caption{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 14px;background:linear-gradient(180deg,#0f1823,#0a1119);border-top:1px solid rgba(255,255,255,.06)}
            #<?php echo esc_html($uid); ?> .sod-wm-video-headline{font-size:15px;font-weight:800;line-height:1.6}
            #<?php echo esc_html($uid); ?> .sod-wm-ranks{display:grid;gap:10px}
            #<?php echo esc_html($uid); ?> .sod-wm-rank{display:grid;grid-template-columns:minmax(0,1fr) 60px;gap:10px;align-items:center}
            #<?php echo esc_html($uid); ?> .sod-wm-rank-line{height:11px;border-radius:999px;background:#081018;border:1px solid rgba(255,255,255,.05);overflow:hidden;margin-top:8px}
            #<?php echo esc_html($uid); ?> .sod-wm-rank-line i{display:block;height:100%;background:linear-gradient(90deg,#33b4ff,#ff8a3d);border-radius:inherit}
            #<?php echo esc_html($uid); ?> .sod-wm-bottom{display:grid;grid-template-columns:.9fr 1.1fr;gap:14px;margin-top:14px;direction:ltr}
            #<?php echo esc_html($uid); ?> .sod-wm-bars{display:grid;gap:12px}
            #<?php echo esc_html($uid); ?> .sod-wm-bar{display:grid;grid-template-columns:86px minmax(0,1fr) 42px;gap:10px;align-items:center}
            #<?php echo esc_html($uid); ?> .sod-wm-bar-track{height:12px;border-radius:999px;background:#081018;border:1px solid rgba(255,255,255,.05);overflow:hidden}
            #<?php echo esc_html($uid); ?> .sod-wm-bar-track i{display:block;height:100%;border-radius:inherit}
            #<?php echo esc_html($uid); ?> .sod-wm-chart{height:240px;border-radius:18px;background:linear-gradient(180deg,#0d1622,#0a1119);border:1px solid rgba(255,255,255,.06);padding:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-chart svg{width:100%;height:100%}
            #<?php echo esc_html($uid); ?> .sod-wm-overview{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-overbox{padding:12px;border-radius:14px;background:linear-gradient(180deg,#101a27,#0c141f);border:1px solid rgba(255,255,255,.06);direction:rtl;text-align:right}
            #<?php echo esc_html($uid); ?> .sod-wm-overbox b{display:block;font-size:22px;line-height:1}
            #<?php echo esc_html($uid); ?> .sod-wm-overbox span{display:block;margin-top:6px;font-size:12px;color:var(--muted)}
            #<?php echo esc_html($uid); ?> .sod-wm-streams{display:grid;gap:10px}
            #<?php echo esc_html($uid); ?> .sod-wm-stream{padding:12px 14px;border-radius:16px;background:linear-gradient(180deg,#0f1824,#0c141e);border:1px solid rgba(255,255,255,.06);display:flex;align-items:center;justify-content:space-between;gap:12px}
            #<?php echo esc_html($uid); ?> .sod-wm-empty{padding:18px;text-align:center;color:var(--muted)}
            #<?php echo esc_html($uid); ?> .sod-wm-hero{display:none}
            #<?php echo esc_html($uid); ?> .sod-wm-kpis{grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}
            #<?php echo esc_html($uid); ?> .sod-wm-kpi{min-height:108px;grid-template-columns:56px minmax(0,1fr) auto;align-items:center;gap:12px;padding:14px 16px}
            #<?php echo esc_html($uid); ?> .sod-wm-kpi-icon{width:56px;height:56px;border-radius:18px;display:grid;place-items:center;background:#0b121b;border:1px solid rgba(255,255,255,.06);font-size:24px}
            #<?php echo esc_html($uid); ?> .sod-wm-kpi-badge{font-size:12px;font-weight:900}
            #<?php echo esc_html($uid); ?> .sod-wm-kpi-badge.is-up{color:#39d98a}
            #<?php echo esc_html($uid); ?> .sod-wm-kpi-badge.is-down{color:#4db8ff}
            #<?php echo esc_html($uid); ?> .sod-wm-kpi-ring{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(#39d98a 0deg,var(--ring-color,#39d98a) var(--ring-angle,180deg),rgba(255,255,255,.09) var(--ring-angle,180deg),rgba(255,255,255,.06) 360deg);position:relative}
            #<?php echo esc_html($uid); ?> .sod-wm-kpi-ring:after{content:'';position:absolute;inset:7px;border-radius:50%;background:#0c141d;border:1px solid rgba(255,255,255,.05)}
            #<?php echo esc_html($uid); ?> .sod-wm-kpi-ring b{position:relative;z-index:1;font-size:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-stage{display:grid;grid-template-columns:minmax(0,1.06fr) minmax(0,.94fr);gap:14px;margin-bottom:14px;direction:ltr}
            #<?php echo esc_html($uid); ?> .sod-wm-live-shell{display:grid;grid-template-columns:180px minmax(0,1fr);gap:12px;direction:ltr}
            #<?php echo esc_html($uid); ?> .sod-wm-live-status{display:grid;gap:8px}
            #<?php echo esc_html($uid); ?> .sod-wm-live-status-item{padding:14px 12px;border-radius:14px;background:linear-gradient(180deg,#101926,#0c141e);border:1px solid rgba(255,255,255,.06);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;direction:ltr;text-align:center}
            #<?php echo esc_html($uid); ?> .sod-wm-live-status-item strong{font-size:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-live-status-item span{font-size:12px;font-weight:800}
            #<?php echo esc_html($uid); ?> .sod-wm-live-status-item span.is-live{color:#39d98a}
            #<?php echo esc_html($uid); ?> .sod-wm-live-status-item span.is-watch{color:#ffbf3c}
            #<?php echo esc_html($uid); ?> .sod-wm-live-controls{display:grid;gap:8px;margin-top:4px}
            #<?php echo esc_html($uid); ?> .sod-wm-live-ctrl{padding:11px 12px;border-radius:12px;background:linear-gradient(180deg,#121c2a,#0d1520);border:1px solid rgba(255,255,255,.06);font-size:13px;font-weight:800;text-align:center}
            #<?php echo esc_html($uid); ?> .sod-wm-live-board{display:grid;gap:12px;direction:ltr}
            #<?php echo esc_html($uid); ?> .sod-wm-video-tabs{margin-bottom:0}
            #<?php echo esc_html($uid); ?> .sod-wm-video-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;direction:ltr}
            #<?php echo esc_html($uid); ?> .sod-wm-video-grid.is-single{grid-template-columns:1fr}
            #<?php echo esc_html($uid); ?> .sod-wm-video-tile{border-radius:16px;overflow:hidden;background:linear-gradient(180deg,#090d12,#06090d);border:1px solid rgba(255,255,255,.07);position:relative;min-height:210px}
            #<?php echo esc_html($uid); ?> .sod-wm-video-grid.is-single .sod-wm-video-tile{min-height:420px}
            #<?php echo esc_html($uid); ?> .sod-wm-video-tile iframe{width:100%;height:100%;border:0;display:block;background:#000;min-height:210px}
            #<?php echo esc_html($uid); ?> .sod-wm-video-tile-top{position:absolute;top:10px;right:10px;left:10px;display:flex;align-items:center;justify-content:space-between;gap:8px;z-index:2;pointer-events:none}
            #<?php echo esc_html($uid); ?> .sod-wm-video-tile-title{padding:6px 10px;border-radius:10px;background:rgba(8,12,18,.84);border:1px solid rgba(255,255,255,.08);font-size:12px;font-weight:900}
            #<?php echo esc_html($uid); ?> .sod-wm-video-tile-badge{padding:5px 9px;border-radius:999px;background:#e84653;color:#fff;font-size:11px;font-weight:900}
            #<?php echo esc_html($uid); ?> .sod-wm-video-tile-time{position:absolute;left:10px;bottom:10px;z-index:2;padding:5px 8px;border-radius:10px;background:rgba(8,12,18,.84);border:1px solid rgba(255,255,255,.08);font-size:11px;font-weight:800}
            #<?php echo esc_html($uid); ?> .sod-wm-insights{display:grid;grid-template-columns:.72fr 1.2fr .78fr;gap:14px;margin-bottom:14px;direction:ltr}
            #<?php echo esc_html($uid); ?> .sod-wm-donut{width:180px;height:180px;margin:0 auto;border-radius:50%;position:relative;background:conic-gradient(#ff4d5f 0deg 70deg,#ff8a3d 70deg 150deg,#ffbf3c 150deg 240deg,#39d98a 240deg 360deg)}
            #<?php echo esc_html($uid); ?> .sod-wm-donut:after{content:'';position:absolute;inset:24px;border-radius:50%;background:#0c141d;border:1px solid rgba(255,255,255,.06)}
            #<?php echo esc_html($uid); ?> .sod-wm-donut-center{position:absolute;inset:0;display:grid;place-items:center;text-align:center;z-index:2}
            #<?php echo esc_html($uid); ?> .sod-wm-donut-center b{display:block;font-size:28px;line-height:1}
            #<?php echo esc_html($uid); ?> .sod-wm-donut-center span{display:block;margin-top:8px;color:var(--muted);font-size:13px}
            #<?php echo esc_html($uid); ?> .sod-wm-top-regions{display:grid;gap:12px}
            #<?php echo esc_html($uid); ?> .sod-wm-top-region{display:grid;grid-template-columns:1fr;gap:10px;align-items:center;justify-items:center;text-align:center}
            #<?php echo esc_html($uid); ?> .sod-wm-top-region-track{height:12px;border-radius:999px;background:#0a1118;border:1px solid rgba(255,255,255,.05);overflow:hidden}
            #<?php echo esc_html($uid); ?> .sod-wm-top-region-track i{display:block;height:100%;border-radius:inherit}
            #<?php echo esc_html($uid); ?> .sod-wm-bottom{grid-template-columns:minmax(0,1.35fr) .62fr .9fr}
            #<?php echo esc_html($uid); ?> .sod-wm-table-wrap{overflow:auto}
            #<?php echo esc_html($uid); ?> .sod-wm-table{width:100%;border-collapse:collapse;min-width:680px}
            #<?php echo esc_html($uid); ?> .sod-wm-table th,#<?php echo esc_html($uid); ?> .sod-wm-table td{padding:12px 10px;border-bottom:1px solid rgba(255,255,255,.06);text-align:center;font-size:13px;vertical-align:top}
            #<?php echo esc_html($uid); ?> .sod-wm-table th{color:#dbe7f4;background:rgba(255,255,255,.03)}
            #<?php echo esc_html($uid); ?> .sod-wm-state-tag{display:inline-flex;align-items:center;justify-content:center;min-width:84px;padding:7px 10px;border-radius:8px;font-size:12px;font-weight:900}
            #<?php echo esc_html($uid); ?> .sod-wm-state-tag.is-new{background:rgba(232,70,83,.18);color:#ff7b88}
            #<?php echo esc_html($uid); ?> .sod-wm-state-tag.is-follow{background:rgba(255,191,60,.18);color:#ffd978}
            #<?php echo esc_html($uid); ?> .sod-wm-state-tag.is-closed{background:rgba(57,217,138,.18);color:#7ef0b2}
            #<?php echo esc_html($uid); ?> .sod-wm-gauge{width:220px;height:220px;margin:0 auto;position:relative}
            #<?php echo esc_html($uid); ?> .sod-wm-gauge-ring{position:absolute;inset:0;border-radius:50%;background:conic-gradient(#39d98a 0deg 115deg,#ffbf3c 115deg 240deg,#ff4d5f 240deg 360deg)}
            #<?php echo esc_html($uid); ?> .sod-wm-gauge-ring:after{content:'';position:absolute;inset:24px;border-radius:50%;background:#0d1520;border:1px solid rgba(255,255,255,.06)}
            #<?php echo esc_html($uid); ?> .sod-wm-gauge-needle{position:absolute;left:50%;top:50%;width:4px;height:88px;background:linear-gradient(180deg,#ffbf3c,#ff7b31);transform-origin:50% calc(100% - 10px);border-radius:999px;translate:-50% -100%;box-shadow:0 0 14px rgba(255,191,60,.35)}
            #<?php echo esc_html($uid); ?> .sod-wm-gauge-center{position:absolute;inset:0;display:grid;place-items:center;text-align:center;z-index:2}
            #<?php echo esc_html($uid); ?> .sod-wm-gauge-center b{display:block;font-size:54px;line-height:1}
            #<?php echo esc_html($uid); ?> .sod-wm-gauge-center span{display:block;color:var(--muted);margin-top:8px;font-size:14px}
            #<?php echo esc_html($uid); ?> .sod-wm-chart.is-risk{height:270px}
            @keyframes sod-wm-marquee{0%{transform:translateX(-50%)}100%{transform:translateX(0)}}
            @keyframes sod-wm-pulse{0%{transform:scale(.72);opacity:.55}70%{transform:scale(1.8);opacity:0}100%{transform:scale(1.8);opacity:0}}
            @media (max-width:1180px){
                #<?php echo esc_html($uid); ?> .sod-wm-headbar{grid-template-columns:repeat(2,minmax(0,1fr))}
                #<?php echo esc_html($uid); ?> .sod-wm-top-main,#<?php echo esc_html($uid); ?> .sod-wm-actions{grid-column:1 / -1}
                #<?php echo esc_html($uid); ?> .sod-wm-stage,#<?php echo esc_html($uid); ?> .sod-wm-insights,#<?php echo esc_html($uid); ?> .sod-wm-bottom{grid-template-columns:1fr}
                #<?php echo esc_html($uid); ?> .sod-wm-live-shell{grid-template-columns:1fr}
                #<?php echo esc_html($uid); ?> .sod-wm-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}
                #<?php echo esc_html($uid); ?> .sod-wm-overview{grid-template-columns:repeat(2,minmax(0,1fr))}
                #<?php echo esc_html($uid); ?> .sod-wm-brief{grid-template-columns:1fr}
            }
            @media (max-width:760px){
                #<?php echo esc_html($uid); ?>{padding:10px;border-radius:18px}
                #<?php echo esc_html($uid); ?> .sod-wm-title{font-size:24px}
                #<?php echo esc_html($uid); ?> .sod-wm-headbar{grid-template-columns:1fr}
                #<?php echo esc_html($uid); ?> .sod-wm-actions{justify-self:stretch;flex-wrap:wrap}
                #<?php echo esc_html($uid); ?> .sod-wm-select,#<?php echo esc_html($uid); ?> .sod-wm-clock{flex:1 1 160px}
                #<?php echo esc_html($uid); ?> .sod-wm-kpis,#<?php echo esc_html($uid); ?> .sod-wm-overview{grid-template-columns:1fr 1fr}
                #<?php echo esc_html($uid); ?> .sod-wm-strip,#<?php echo esc_html($uid); ?> .sod-wm-brief-row{grid-template-columns:1fr}
                #<?php echo esc_html($uid); ?> .sod-wm-video-grid{grid-template-columns:1fr}
                #<?php echo esc_html($uid); ?> .sod-wm-map-wrap{height:320px}
            }
            @media (max-width:560px){
                #<?php echo esc_html($uid); ?> .sod-wm-kpis,#<?php echo esc_html($uid); ?> .sod-wm-overview{grid-template-columns:1fr}
                #<?php echo esc_html($uid); ?> .sod-wm-top-region{grid-template-columns:86px minmax(0,1fr) 40px}
            }
        </style>

        <div class="sod-wm-headbar">
            <div class="sod-wm-head-card">
                <span>النظام</span>
                <b class="is-green" id="<?php echo esc_attr($uid); ?>_top_status">متصل</b>
            </div>
            <div class="sod-wm-head-card">
                <span>آخر تحديث</span>
                <b id="<?php echo esc_attr($uid); ?>_top_updated"><?php echo esc_html($updated_at); ?></b>
            </div>
            <div class="sod-wm-head-card">
                <span>المصادر الحية</span>
                <b id="<?php echo esc_attr($uid); ?>_top_sources"><?php echo esc_html((int) ($data['source_count'] ?? 0)); ?></b>
            </div>
            <div class="sod-wm-top-main">
                <div class="sod-wm-brand">
                    <div class="sod-wm-logo">⌁</div>
                    <div>
                        <div class="sod-wm-title"><?php echo esc_html((string) $atts['title']); ?></div>
                        <div class="sod-wm-sub">مركز القيادة والسيطرة لرصد التهديدات العالمية في واجهة عربية حية وأنيقة</div>
                    </div>
                </div>
            </div>
            <div class="sod-wm-actions">
                <select class="sod-wm-select" id="<?php echo esc_attr($uid); ?>_days">
                    <option value="1"<?php selected($days, 1); ?>>آخر 24 ساعة</option>
                    <option value="3"<?php selected($days, 3); ?>>آخر 3 أيام</option>
                    <option value="7"<?php selected($days, 7); ?>>آخر 7 أيام</option>
                    <option value="30"<?php selected($days, 30); ?>>آخر 30 يوم</option>
                </select>
                <span class="sod-wm-clock" id="<?php echo esc_attr($uid); ?>_clock_live"><?php echo esc_html($current_clock); ?></span>
                <button type="button" class="sod-wm-btn sod-wm-btn-icon" id="<?php echo esc_attr($uid); ?>_reload" title="تحديث مباشر" aria-label="تحديث مباشر">⟳</button>
            </div>
        </div>

        <div class="sod-wm-nav">
            <span class="sod-wm-nav-icon">☰</span>
            <span class="sod-wm-nav-icon">⚙</span>
            <a href="#<?php echo esc_attr($uid); ?>_analysis">الإعدادات</a>
            <a href="#<?php echo esc_attr($uid); ?>_analysis">الأصول الاستراتيجية</a>
            <a href="#<?php echo esc_attr($uid); ?>_analysis">التقارير التنفيذية</a>
            <a href="#<?php echo esc_attr($uid); ?>_analysis">تحليل بالذكاء السياسي</a>
            <a class="is-active" href="#<?php echo esc_attr($uid); ?>_videocard">المراقبة الحية</a>
            <a href="#<?php echo esc_attr($uid); ?>_mapcard">التهديدات العالمية</a>
            <a href="#<?php echo esc_attr($uid); ?>_overview">اللوحة الرئيسية</a>
        </div>

        <div class="sod-wm-hero" id="<?php echo esc_attr($uid); ?>_overview">
            <div class="sod-wm-panel">
                <div style="font-size:28px;font-weight:900;line-height:1.2">مركز المراقبة العالمية</div>
                <div class="sod-wm-sub">تجميع فوري للأحداث، البؤر الساخنة، الفاعلين، واتجاه المخاطر ضمن واجهة عمليات أقرب لغرف القيادة الحية.</div>
                <div class="sod-wm-pills">
                    <span class="sod-wm-pill"><i class="sod-wm-dot"></i>المراقبة الحية مفعلة</span>
                    <span class="sod-wm-pill">آخر تحديث: <b id="<?php echo esc_attr($uid); ?>_updated"><?php echo esc_html($updated_at); ?></b></span>
                    <span class="sod-wm-pill">المصادر: <b id="<?php echo esc_attr($uid); ?>_sources"><?php echo esc_html((int) ($data['source_count'] ?? 0)); ?></b></span>
                    <span class="sod-wm-pill">طبقة التهديد: <b id="<?php echo esc_attr($uid); ?>_top_layer"><?php echo esc_html((string) ($data['kpi']['top_hybrid_layer'] ?? 'عام')); ?></b></span>
                </div>
            </div>
            <div class="sod-wm-hero-stats">
                <div class="sod-wm-mini"><b id="<?php echo esc_attr($uid); ?>_critical"><?php echo esc_html((int) ($data['kpi']['critical'] ?? 0)); ?></b><span>أحداث حرجة</span></div>
                <div class="sod-wm-mini"><b id="<?php echo esc_attr($uid); ?>_risk2"><?php echo esc_html($risk_index); ?>%</b><span id="<?php echo esc_attr($uid); ?>_risk_label"><?php echo esc_html($risk_label); ?></span></div>
                <div class="sod-wm-mini"><b id="<?php echo esc_attr($uid); ?>_streams"><?php echo esc_html((int) ($data['video_count'] ?? 0)); ?></b><span>مصادر بث وروابط</span></div>
            </div>
        </div>

        <div class="sod-wm-strip">
            <div class="sod-wm-alert">الأخبار العاجلة</div>
            <div class="sod-wm-ticker"><div class="sod-wm-track" id="<?php echo esc_attr($uid); ?>_ticker"></div></div>
        </div>

        <div class="sod-wm-brief-row">
            <div class="sod-wm-brief-label">الموجز</div>
            <div class="sod-wm-brief" id="<?php echo esc_attr($uid); ?>_brief"></div>
        </div>

        <div class="sod-wm-kpis">
            <div class="sod-wm-kpi">
                <div class="sod-wm-kpi-icon" style="color:#ff4d5f">⛨</div>
                <div>
                    <div class="sod-wm-kpi-top"><span>إجمالي الكاميرات</span></div>
                    <div class="sod-wm-kpi-val" id="<?php echo esc_attr($uid); ?>_camera_total"><?php echo esc_html($source_count); ?></div>
                    <div class="sod-wm-kpi-sub">قنوات ومصادر مرئية</div>
                </div>
                <div class="sod-wm-kpi-badge is-up">+12%</div>
            </div>
            <div class="sod-wm-kpi">
                <div class="sod-wm-kpi-icon" style="color:#ffbf3c">⚠</div>
                <div>
                    <div class="sod-wm-kpi-top"><span>تنبيهات متوسطة</span></div>
                    <div class="sod-wm-kpi-val" id="<?php echo esc_attr($uid); ?>_moderate_total"><?php echo esc_html($moderate_count); ?></div>
                    <div class="sod-wm-kpi-sub">تحتاج متابعة مستمرة</div>
                </div>
                <div class="sod-wm-kpi-badge is-up">+8%</div>
            </div>
            <div class="sod-wm-kpi">
                <div class="sod-wm-kpi-icon" style="color:#39d98a">✓</div>
                <div>
                    <div class="sod-wm-kpi-top"><span>تنبيهات خفيفة</span></div>
                    <div class="sod-wm-kpi-val" id="<?php echo esc_attr($uid); ?>_low_total"><?php echo esc_html($low_count); ?></div>
                    <div class="sod-wm-kpi-sub">نشاط منخفض التأثير</div>
                </div>
                <div class="sod-wm-kpi-badge is-down">-5%</div>
            </div>
            <div class="sod-wm-kpi">
                <div class="sod-wm-kpi-icon" style="color:#33b4ff">🛡</div>
                <div>
                    <div class="sod-wm-kpi-top"><span>لا خطر</span></div>
                    <div class="sod-wm-kpi-val" id="<?php echo esc_attr($uid); ?>_safe_total"><?php echo esc_html($safe_count); ?></div>
                    <div class="sod-wm-kpi-sub">مناطق مستقرة</div>
                </div>
                <div class="sod-wm-kpi-badge is-down">-10%</div>
            </div>
            <div class="sod-wm-kpi">
                <div class="sod-wm-kpi-ring" id="<?php echo esc_attr($uid); ?>_connection_ring" style="--ring-angle:<?php echo esc_attr((string) round($connection_quality * 3.6)); ?>deg;--ring-color:#39d98a"><b id="<?php echo esc_attr($uid); ?>_connection_total"><?php echo esc_html($connection_quality); ?>%</b></div>
                <div>
                    <div class="sod-wm-kpi-top"><span>جودة الاتصال</span></div>
                    <div class="sod-wm-kpi-sub">استقرار المصادر والنوافذ</div>
                </div>
            </div>
            <div class="sod-wm-kpi">
                <div class="sod-wm-kpi-icon" style="color:#8fb4ff">◔</div>
                <div>
                    <div class="sod-wm-kpi-top"><span>وقت النظام</span></div>
                    <div class="sod-wm-kpi-val" id="<?php echo esc_attr($uid); ?>_system_time"><?php echo esc_html($current_clock); ?></div>
                    <div class="sod-wm-kpi-sub">التوقيت التشغيلي المباشر</div>
                </div>
            </div>
        </div>

        <div class="sod-wm-stage">
            <div class="sod-wm-panel" id="<?php echo esc_attr($uid); ?>_videocard">
                <div class="sod-wm-card-head">
                    <h3>البث المباشر</h3>
                    <small>مركز مراقبة القنوات الحية</small>
                </div>
                <div class="sod-wm-live-shell">
                    <div class="sod-wm-live-status" id="<?php echo esc_attr($uid); ?>_live_status"></div>
                    <div class="sod-wm-live-board">
                        <div class="sod-wm-video-toolbar">
                            <div class="sod-wm-video-mode" id="<?php echo esc_attr($uid); ?>_video_mode"></div>
                            <div class="sod-wm-video-tabs" id="<?php echo esc_attr($uid); ?>_video_tabs"></div>
                        </div>
                        <div class="sod-wm-video-grid" id="<?php echo esc_attr($uid); ?>_video_grid"></div>
                    </div>
                </div>
            </div>

            <div class="sod-wm-panel" id="<?php echo esc_attr($uid); ?>_mapcard">
                <div class="sod-wm-card-head">
                    <h3>الخريطة العالمية للتهديدات</h3>
                    <small id="<?php echo esc_attr($uid); ?>_map_summary">تحديث حي للبؤر النشطة</small>
                </div>
                <div class="sod-wm-map-wrap">
                    <div class="sod-wm-map-grid"></div>
                    <div class="sod-wm-map-glow"></div>
                    <div class="sod-wm-map-world" aria-hidden="true">
                        <svg viewBox="0 0 1000 500" xmlns="http://www.w3.org/2000/svg">
                            <g fill="rgba(255,255,255,.12)" stroke="rgba(255,255,255,.08)" stroke-width="4">
                                <path d="M91 144l37-26 64 7 29 33-16 38 13 29-28 48-50 8-26-17-7-44-20-33z"/>
                                <path d="M250 116l54-20 90 17 25 25-11 34-51 15-31 34 14 39-15 32-54-18-33-47-9-52 11-30z"/>
                                <path d="M359 263l37 10 27 30-6 47 30 58-21 24-52-14-30-49 6-47-17-25z"/>
                                <path d="M471 132l75-21 89 5 82 28 43 38-17 28-83 0-34 31-66 8-45-23-37 12-31-33z"/>
                                <path d="M656 235l49 10 44 37 37 6 52-36 59 6 18 24-27 35-59 7-45 28-60-2-33-21-15-40z"/>
                                <path d="M769 103l47-20 61 2 23 24-19 37-50 3-39 21-31-23z"/>
                                <path d="M798 373l40 7 22 30-20 35-45 6-31-21 5-36z"/>
                            </g>
                        </svg>
                    </div>
                    <div class="sod-wm-map-legend">
                        <div class="sod-wm-map-legend-title">مستوى التهديد</div>
                        <span class="sod-wm-legend"><span>حرج</span><i class="sod-wm-dot" style="background:#ff4d5f"></i></span>
                        <span class="sod-wm-legend"><span>مرتفع</span><i class="sod-wm-dot" style="background:#ff8a3d"></i></span>
                        <span class="sod-wm-legend"><span>متوسط</span><i class="sod-wm-dot" style="background:#ffbf3c"></i></span>
                        <span class="sod-wm-legend"><span>خفيف</span><i class="sod-wm-dot" style="background:#39d98a"></i></span>
                        <span class="sod-wm-legend"><span>لا خطر</span><i class="sod-wm-dot" style="background:#33b4ff"></i></span>
                    </div>
                    <div data-role="map-layer" id="<?php echo esc_attr($uid); ?>_map"></div>
                </div>
            </div>
        </div>

        <div class="sod-wm-insights">
            <div class="sod-wm-panel">
                <div class="sod-wm-card-head">
                    <h3>توزيع مستويات التهديد</h3>
                    <small>صورة فورية للتصنيف</small>
                </div>
                <div id="<?php echo esc_attr($uid); ?>_severity_donut"></div>
            </div>

            <div class="sod-wm-panel">
                <div class="sod-wm-card-head">
                    <h3>التهديدات عبر الزمن</h3>
                    <small>آخر 12 ساعة</small>
                </div>
                <div class="sod-wm-chart" id="<?php echo esc_attr($uid); ?>_trend_chart"></div>
            </div>

            <div class="sod-wm-panel">
                <div class="sod-wm-card-head">
                    <h3>أعلى المناطق تهديدًا</h3>
                    <small>ترتيب نسبي حسب التأثير</small>
                </div>
                <div class="sod-wm-top-regions" id="<?php echo esc_attr($uid); ?>_top_regions"></div>
            </div>
        </div>

        <div class="sod-wm-bottom" id="<?php echo esc_attr($uid); ?>_analysis">
            <div class="sod-wm-panel" id="<?php echo esc_attr($uid); ?>_feedcard">
                <div class="sod-wm-card-head">
                    <h3>سجل التنبيهات المباشرة</h3>
                    <small>أحدث الحركات المرصودة</small>
                </div>
                <div class="sod-wm-table-wrap" id="<?php echo esc_attr($uid); ?>_alerts_table"></div>
            </div>

            <div class="sod-wm-panel">
                <div class="sod-wm-card-head">
                    <h3>مؤشر المخاطر العام</h3>
                    <small>قراءة مركبة للمشهد</small>
                </div>
                <div id="<?php echo esc_attr($uid); ?>_risk_gauge"></div>
            </div>

            <div class="sod-wm-panel">
                <div class="sod-wm-card-head">
                    <h3>اتجاه المخاطر</h3>
                    <small>رسم الحركة التصاعدية</small>
                </div>
                <div class="sod-wm-chart is-risk" id="<?php echo esc_attr($uid); ?>_risk_direction_chart"></div>
                <div class="sod-wm-overview" id="<?php echo esc_attr($uid); ?>_overview_stats"></div>
            </div>
        </div>

        <script>
        (() => {
            const root = document.getElementById(<?php echo wp_json_encode($uid); ?>);
            if (!root) return;

            const state = {
                ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
                nonce: <?php echo wp_json_encode($nonce); ?>,
                days: <?php echo (int) $days; ?>,
                refreshMs: <?php echo (int) ($refresh * 1000); ?>,
                data: <?php echo wp_json_encode($data, $json_flags); ?>,
                busy: false
            };

            const $ = (suffix) => root.querySelector('#' + <?php echo wp_json_encode($uid); ?> + '_' + suffix);
            const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
            const setText = (suffix, value) => { const el = $(suffix); if (el) el.textContent = String(value ?? ''); };
            const setHtml = (suffix, value) => { const el = $(suffix); if (el) el.innerHTML = String(value ?? ''); };
            const sevColor = (sev) => ({critical:'#ff4d5f',high:'#ff8a3d',moderate:'#ffbf3c',low:'#39d98a'}[sev] || '#39d98a');
            const sevLabel = (sev) => ({critical:'حرج',high:'مرتفع',moderate:'متوسط',low:'خفيف'}[sev] || 'خفيف');
            const short = (text, max = 72) => {
                text = String(text || '');
                return text.length > max ? text.slice(0, max - 1) + '…' : text;
            };
            const num = (value) => Number(value || 0);
            state.activeVideo = 0;
            state.videoView = 'grid';

            function geoToPoint(lat, lon) {
                let x = ((Number(lon) + 180) / 360) * 100;
                let y = ((90 - Number(lat)) / 180) * 100;
                x = Math.max(4, Math.min(96, x));
                y = Math.max(8, Math.min(92, y));
                return { x, y };
            }

            function renderTicker(items) {
                items = Array.isArray(items) ? items : [];
                if (!items.length) {
                    $('ticker').innerHTML = '<span class="sod-wm-empty">لا توجد تنبيهات عاجلة حاليًا</span>';
                    return;
                }
                const line = items.map((item) => (
                    `<span class="sod-wm-track-item"><i class="sod-wm-dot" style="background:${sevColor(item.severity)}"></i>${esc(item.time_label || '')} - ${esc(short(item.title, 84))}</span>`
                )).join('');
                $('ticker').innerHTML = line + line;
            }

            function renderBrief(data) {
                data = data || {};
                const kpi = data.kpi || {};
                const overview = data.threat_overview || {};
                const analytics = overview.analytics_24h || {};
                const urgent = (data.urgent || data.feed || [])[0] || {};
                const items = [
                    {
                        value: urgent.region || analytics.hot_region || 'المشهد العالمي',
                        label: urgent.title ? short(urgent.title, 72) : 'أعلى نقطة متابعة حاليًا'
                    },
                    {
                        value: `${num(kpi.risk_index)}%`,
                        label: `مؤشر المخاطر ${num(kpi.risk_index) >= 70 ? 'مرتفع' : (num(kpi.risk_index) >= 45 ? 'متوسط' : 'مستقر')}`
                    },
                    {
                        value: analytics.hot_region || String(kpi.top_hybrid_layer || 'عام'),
                        label: 'البؤرة أو الطبقة الأعلى نشاطًا'
                    }
                ];
                setHtml('brief', items.map((item) => `
                    <div class="sod-wm-brief-item">
                        <b>${esc(item.value || '—')}</b>
                        <span>${esc(item.label || '')}</span>
                    </div>
                `).join(''));
            }

            function renderRankList(target, items, total) {
                items = Array.isArray(items) ? items : [];
                if (!items.length) {
                    target.innerHTML = '<div class="sod-wm-empty">لا توجد بيانات كافية</div>';
                    return;
                }
                const maxVal = Math.max(1, ...items.map((item) => num(item.count || item.score)));
                target.innerHTML = items.map((item) => {
                    const value = num(item.count || item.score);
                    const pct = Math.max(8, Math.round((value / maxVal) * 100));
                    const label = item.name || item.actor || '—';
                    const share = total > 0 ? Math.round((value / total) * 100) : pct;
                    return `<div class="sod-wm-rank">
                        <div>
                            <div style="display:flex;justify-content:space-between;gap:10px"><strong>${esc(label)}</strong><span style="color:var(--muted)">${share}%</span></div>
                            <div class="sod-wm-rank-line"><i style="width:${pct}%"></i></div>
                        </div>
                        <div style="text-align:left;font-weight:800">${esc(value)}</div>
                    </div>`;
                }).join('');
            }

            function renderUrgent(items) {
                items = Array.isArray(items) ? items : [];
                if (!items.length) {
                    $('urgent').innerHTML = '<div class="sod-wm-empty">لا توجد أحداث عاجلة</div>';
                    return;
                }
                $('urgent').innerHTML = items.map((item) => `
                    <div class="sod-wm-list-item">
                        <h4>${esc(short(item.title, 110))}</h4>
                        <div class="sod-wm-meta">
                            <span class="sod-wm-chip"><i class="sod-wm-dot" style="background:${sevColor(item.severity)}"></i>${esc(item.severity_label || sevLabel(item.severity))}</span>
                            <span class="sod-wm-chip">${esc(item.region || 'عام')}</span>
                            <span class="sod-wm-chip">${esc(item.time_label || '')}</span>
                        </div>
                    </div>
                `).join('');
            }

            function renderFeed(items) {
                items = Array.isArray(items) ? items : [];
                if (!items.length) {
                    $('feed').innerHTML = '<div class="sod-wm-empty">لا توجد بيانات للرصد المباشر</div>';
                    return;
                }
                $('feed').innerHTML = items.slice(0, 12).map((item) => `
                    <div class="sod-wm-list-item">
                        <h4>${esc(short(item.title, 120))}</h4>
                        <div class="sod-wm-meta">
                            <span class="sod-wm-chip"><i class="sod-wm-dot" style="background:${sevColor(item.severity)}"></i>${esc(item.severity_label || sevLabel(item.severity))}</span>
                            <span class="sod-wm-chip">${esc(item.region || 'عام')}</span>
                            <span class="sod-wm-chip">${esc(item.actor || 'غير محدد')}</span>
                            <span class="sod-wm-chip">${esc(item.time_label || '')}</span>
                            <span class="sod-wm-chip">${esc(item.source || 'النظام')}</span>
                        </div>
                    </div>
                `).join('');
            }

            function renderMap(markers) {
                markers = Array.isArray(markers) ? markers : [];
                if (!markers.length) {
                    $('map').innerHTML = '<div class="sod-wm-empty" style="position:absolute;inset:0;display:grid;place-items:center">لا توجد إحداثيات مرئية</div>';
                    $('map_summary').textContent = 'لا توجد علامات جغرافية متاحة';
                    return;
                }
                $('map').innerHTML = markers.slice(0, 120).map((marker) => {
                    const point = geoToPoint(marker.lat, marker.lon);
                    const label = `${marker.region || 'عام'} - ${marker.severity_label || sevLabel(marker.severity)}`;
                    return `<div class="sod-wm-marker" style="left:${point.x}%;top:${point.y}%;color:${sevColor(marker.severity)}">
                        <button type="button" aria-label="${esc(label)}"></button>
                        <span>${esc(short(marker.title, 88))}<br>${esc(label)}<br>${esc(marker.time_label || '')}</span>
                    </div>`;
                }).join('');
                $('map_summary').textContent = `عدد العلامات المعروضة: ${markers.length}`;
            }

            function renderSeverity(items) {
                items = Array.isArray(items) ? items : [];
                if (!items.length) {
                    $('severity').innerHTML = '<div class="sod-wm-empty">لا توجد بيانات تصنيف</div>';
                    return;
                }
                const total = Math.max(1, items.reduce((sum, item) => sum + num(item.count), 0));
                $('severity').innerHTML = items.map((item) => {
                    const count = num(item.count);
                    const pct = Math.round((count / total) * 100);
                    return `<div class="sod-wm-bar">
                        <strong>${esc(item.label || '')}</strong>
                        <div class="sod-wm-bar-track"><i style="width:${Math.max(6, pct)}%;background:${item.color || sevColor(item.key)}"></i></div>
                        <span style="text-align:left;font-weight:800">${count}</span>
                    </div>`;
                }).join('');
            }

            function renderTrend(trendData) {
                trendData = Array.isArray(trendData) ? trendData : [];
                if (!trendData.length) {
                    $('trend_chart').innerHTML = '<div class="sod-wm-empty">لا توجد بيانات اتجاه</div>';
                    return;
                }
                const width = 640;
                const height = 190;
                const maxValue = Math.max(1, ...trendData.map((item) => num(item.value)));
                const step = width / Math.max(1, trendData.length - 1);
                const points = trendData.map((item, index) => {
                    const x = Math.round(index * step);
                    const y = Math.round(height - ((num(item.value) / maxValue) * (height - 30)) - 12);
                    return `${x},${y}`;
                }).join(' ');
                const labels = trendData.map((item, index) => {
                    const x = Math.round(index * step);
                    return `<text x="${x}" y="${height + 18}" fill="#8ea2bb" font-size="11" text-anchor="${index === 0 ? 'start' : (index === trendData.length - 1 ? 'end' : 'middle')}">${esc(item.label || '')}</text>`;
                }).join('');
                $('trend_chart').innerHTML = `
                    <svg viewBox="0 0 ${width} ${height + 26}" preserveAspectRatio="none" aria-hidden="true">
                        <defs>
                            <linearGradient id="<?php echo esc_attr($uid); ?>_line" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#ff4d5f" stop-opacity="0.9"></stop>
                                <stop offset="100%" stop-color="#ff8a3d" stop-opacity="0.25"></stop>
                            </linearGradient>
                        </defs>
                        <polyline fill="none" stroke="url(#<?php echo esc_attr($uid); ?>_line)" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" points="${points}"></polyline>
                        ${labels}
                    </svg>`;
            }

            function renderLiveStatus(streams) {
                streams = Array.isArray(streams) ? streams : [];
                if (!streams.length) {
                    setHtml('live_status', '<div class="sod-wm-empty">لا توجد قنوات متاحة</div>');
                    return;
                }
                const lines = streams.slice(0, 4).map((stream, index) => `
                    <div class="sod-wm-live-status-item">
                        <strong>${esc((stream.name || 'قناة ' + (index + 1)).toUpperCase())}</strong>
                        <span class="${index < 3 ? 'is-live' : 'is-watch'}">${index < 3 ? 'متصل' : 'عادي'}</span>
                    </div>
                `).join('');
                setHtml('live_status', lines + `
                    <div class="sod-wm-live-controls">
                        <div class="sod-wm-live-ctrl">تحديث الكل</div>
                        <div class="sod-wm-live-ctrl">إعادة التشغيل المتصل</div>
                        <div class="sod-wm-live-ctrl">اختبار الاتصال</div>
                        <div class="sod-wm-live-ctrl">إعدادات البث</div>
                    </div>
                `);
            }

            function renderVideoWall(streams, urgentItems) {
                streams = Array.isArray(streams) ? streams : [];
                urgentItems = Array.isArray(urgentItems) ? urgentItems : [];
                if (!streams.length) {
                    setHtml('video_mode', '');
                    setHtml('video_tabs', '');
                    setHtml('video_grid', '<div class="sod-wm-empty">لا توجد قنوات بث معرفة</div>');
                    return;
                }

                if (state.activeVideo >= streams.length) state.activeVideo = 0;
                const channels = streams.slice(0, 8);
                setHtml('video_mode', `
                    <button type="button" class="sod-wm-video-mode-btn ${state.videoView === 'grid' ? 'is-active' : ''}" data-video-view="grid">عرض شبكة</button>
                    <button type="button" class="sod-wm-video-mode-btn ${state.videoView === 'single' ? 'is-active' : ''}" data-video-view="single">قناة واحدة</button>
                `);
                setHtml('video_tabs', channels.map((stream, index) => `
                    <button type="button" class="sod-wm-video-tab ${index === state.activeVideo ? 'is-active' : ''}" data-video-index="${index}">
                        ${esc(stream.name || ('قناة ' + (index + 1)))}
                    </button>
                `).join(''));

                $('video_mode').querySelectorAll('[data-video-view]').forEach((button) => {
                    button.addEventListener('click', () => {
                        state.videoView = button.getAttribute('data-video-view') || 'grid';
                        renderVideoWall(channels, urgentItems);
                    });
                });

                $('video_tabs').querySelectorAll('[data-video-index]').forEach((button) => {
                    button.addEventListener('click', () => {
                        state.activeVideo = Number(button.getAttribute('data-video-index') || 0);
                        renderVideoWall(channels, urgentItems);
                    });
                });

                const grid = $('video_grid');
                const ordered = state.videoView === 'single'
                    ? [channels[state.activeVideo]]
                    : channels.slice(state.activeVideo).concat(channels.slice(0, state.activeVideo)).slice(0, 4);
                if (grid) grid.classList.toggle('is-single', state.videoView === 'single');
                setHtml('video_grid', ordered.map((stream, index) => {
                    const sourceIndex = state.videoView === 'single' ? state.activeVideo : index;
                    const headline = urgentItems[sourceIndex] || urgentItems[0] || {};
                    const top = `
                        <div class="sod-wm-video-tile-top">
                            <span class="sod-wm-video-tile-title">${esc(stream.name || ('LIVE ' + (index + 1)))}</span>
                            <span class="sod-wm-video-tile-badge">LIVE</span>
                        </div>
                    `;
                    const time = `<span class="sod-wm-video-tile-time">${esc(headline.time_label || '')}</span>`;
                    if (stream.embed_url) {
                        return `<div class="sod-wm-video-tile">${top}<iframe src="${esc(stream.embed_url)}" title="${esc(stream.name || 'Live Stream')}" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen"></iframe>${time}</div>`;
                    }
                    return `<div class="sod-wm-video-tile">${top}<div class="sod-wm-video-fallback"><div><strong>${esc(stream.name || 'بث مباشر')}</strong><p>${esc(short(headline.title || 'هذا المصدر لا يدعم التضمين المباشر داخل اللوحة.', 96))}</p><a class="sod-wm-btn" href="${esc(stream.url || '#')}" target="_blank" rel="noopener">فتح المصدر</a></div></div>${time}</div>`;
                }).join(''));
            }

            function renderSeverityDonut(items) {
                items = Array.isArray(items) ? items : [];
                if (!items.length) {
                    setHtml('severity_donut', '<div class="sod-wm-empty">لا توجد بيانات توزيع</div>');
                    return;
                }
                const total = Math.max(1, items.reduce((sum, item) => sum + num(item.count), 0));
                let cursor = 0;
                const gradient = items.map((item) => {
                    const slice = Math.max(2, Math.round((num(item.count) / total) * 360));
                    const start = cursor;
                    const end = Math.min(360, cursor + slice);
                    cursor = end;
                    return `${item.color || sevColor(item.key)} ${start}deg ${end}deg`;
                }).join(',');
                setHtml('severity_donut', `
                    <div class="sod-wm-donut" style="background:conic-gradient(${gradient})">
                        <div class="sod-wm-donut-center">
                            <div>
                                <b>100%</b>
                                <span>إجمالي الرصد</span>
                            </div>
                        </div>
                    </div>
                    <div class="sod-wm-bars" style="margin-top:16px">
                        ${items.map((item) => {
                            const pct = Math.round((num(item.count) / total) * 100);
                            return `<div class="sod-wm-bar">
                                <strong>${esc(item.label || '')}</strong>
                                <div class="sod-wm-bar-track"><i style="width:${Math.max(6, pct)}%;background:${item.color || sevColor(item.key)}"></i></div>
                                <span style="text-align:left;font-weight:800">${pct}%</span>
                            </div>`;
                        }).join('')}
                    </div>
                `);
            }

            function renderTopRegions(regions) {
                const palette = ['#ff4d5f', '#ff8a3d', '#ffbf3c', '#39d98a', '#33b4ff'];
                regions = Array.isArray(regions) ? regions : [];
                if (!regions.length) {
                    setHtml('top_regions', '<div class="sod-wm-empty">لا توجد مناطق بارزة</div>');
                    return;
                }
                const maxValue = Math.max(1, ...regions.map((item) => num(item.count || item.score)));
                setHtml('top_regions', regions.slice(0, 5).map((item, index) => {
                    const value = num(item.count || item.score);
                    const pct = Math.max(8, Math.round((value / maxValue) * 100));
                    return `<div class="sod-wm-top-region">
                        <strong>${esc(item.name || '—')}</strong>
                        <div class="sod-wm-top-region-track"><i style="width:${pct}%;background:${palette[index % palette.length]}"></i></div>
                        <span style="font-weight:800">${Math.round((value / maxValue) * 32) || 1}%</span>
                    </div>`;
                }).join(''));
            }

            function renderAlertsTable(items) {
                items = Array.isArray(items) ? items : [];
                if (!items.length) {
                    setHtml('alerts_table', '<div class="sod-wm-empty">لا توجد تنبيهات مباشرة</div>');
                    return;
                }
                const statusFor = (item) => {
                    const sev = item.severity || 'low';
                    if (sev === 'critical' || sev === 'high') return { label: 'جديد', cls: 'is-new' };
                    if (sev === 'moderate') return { label: 'قيد المتابعة', cls: 'is-follow' };
                    return { label: 'مغلق', cls: 'is-closed' };
                };
                setHtml('alerts_table', `
                    <table class="sod-wm-table">
                        <thead>
                            <tr>
                                <th>الحالة</th>
                                <th>الوصف</th>
                                <th>نوع التهديد</th>
                                <th>المنطقة</th>
                                <th>المستوى</th>
                                <th>الوقت</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${items.slice(0, 6).map((item) => {
                                const stateInfo = statusFor(item);
                                return `<tr>
                                    <td><span class="sod-wm-state-tag ${stateInfo.cls}">${stateInfo.label}</span></td>
                                    <td>${esc(short(item.title || '', 78))}</td>
                                    <td>${esc(item.type || 'عام')}</td>
                                    <td>${esc(item.region || 'عام')}</td>
                                    <td><span class="sod-wm-chip"><i class="sod-wm-dot" style="background:${sevColor(item.severity)}"></i>${esc(item.severity_label || sevLabel(item.severity))}</span></td>
                                    <td>${esc(item.time_label || '')}</td>
                                </tr>`;
                            }).join('')}
                        </tbody>
                    </table>
                `);
            }

            function renderRiskGauge(risk) {
                risk = num(risk);
                const angle = Math.max(-90, Math.min(90, Math.round((risk / 100) * 180) - 90));
                const label = risk >= 70 ? 'مرتفع' : (risk >= 45 ? 'متوسط' : 'منخفض');
                setHtml('risk_gauge', `
                    <div class="sod-wm-gauge">
                        <div class="sod-wm-gauge-ring"></div>
                        <div class="sod-wm-gauge-needle" style="transform:translate(-50%,-100%) rotate(${angle}deg)"></div>
                        <div class="sod-wm-gauge-center">
                            <div>
                                <b>${risk}%</b>
                                <span>${esc(label)}</span>
                            </div>
                        </div>
                    </div>
                `);
            }

            function renderRiskDirection(trendData) {
                trendData = Array.isArray(trendData) ? trendData : [];
                if (!trendData.length) {
                    setHtml('risk_direction_chart', '<div class="sod-wm-empty">لا توجد بيانات اتجاه</div>');
                    return;
                }
                const width = 520;
                const height = 210;
                const values = trendData.map((item) => num(item.value));
                const maxValue = Math.max(1, ...values);
                const step = width / Math.max(1, trendData.length - 1);
                const points = trendData.map((item, index) => {
                    const x = Math.round(index * step);
                    const y = Math.round(height - ((num(item.value) / maxValue) * (height - 40)) - 18);
                    return `${x},${y}`;
                }).join(' ');
                const area = `0,${height} ${points} ${width},${height}`;
                const labels = ['أمس', 'أيام 3', 'أيام 7', 'يوم 30'];
                setHtml('risk_direction_chart', `
                    <svg viewBox="0 0 ${width} ${height + 30}" preserveAspectRatio="none" aria-hidden="true">
                        <defs>
                            <linearGradient id="<?php echo esc_attr($uid); ?>_riskfill" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#ff4d5f" stop-opacity="0.34"></stop>
                                <stop offset="100%" stop-color="#ff4d5f" stop-opacity="0"></stop>
                            </linearGradient>
                        </defs>
                        <polygon fill="url(#<?php echo esc_attr($uid); ?>_riskfill)" points="${area}"></polygon>
                        <polyline fill="none" stroke="#ff4d5f" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" points="${points}"></polyline>
                        ${labels.map((label, index) => {
                            const x = Math.round((width / Math.max(1, labels.length - 1)) * index);
                            return `<text x="${x}" y="${height + 18}" fill="#8ea2bb" font-size="11" text-anchor="${index === 0 ? 'start' : (index === labels.length - 1 ? 'end' : 'middle')}">${label}</text>`;
                        }).join('')}
                    </svg>
                `);
            }

            function renderOverviewBoxes(overview) {
                overview = overview || {};
                const a24 = overview.analytics_24h || {};
                const momentum = num(overview.momentum_change);
                const momentumLabel = momentum > 0 ? `+${momentum}%` : `${momentum}%`;
                $('overview_stats').innerHTML = `
                    <div class="sod-wm-overbox"><b>${esc(a24.hot_region || '—')}</b><span>أكثر منطقة نشاطًا</span></div>
                    <div class="sod-wm-overbox"><b>${esc(a24.gci || 0)}%</b><span>المؤشر العام المركب</span></div>
                    <div class="sod-wm-overbox"><b>${esc(a24.escalation_index || 0)}%</b><span>مؤشر التصعيد</span></div>
                    <div class="sod-wm-overbox"><b>${esc(momentumLabel)}</b><span>زخم آخر 24 ساعة</span></div>
                `;
            }

            function updateTopNumbers(data) {
                const kpi = data.kpi || {};
                const risk = num(kpi.risk_index);
                const riskLabel = risk >= 70 ? 'خطر مرتفع' : (risk >= 45 ? 'مراقبة مشددة' : 'مستوى مستقر');
                const sourceCount = num(data.source_count);
                const videoCount = num(data.video_count);
                const safeCount = Math.max(0, num(kpi.countries) - num(kpi.hotspots));
                const connection = Math.min(98, 74 + (sourceCount * 2) + (videoCount * 4));
                setText('updated', new Date(num(kpi.updated_at) * 1000).toLocaleString('ar-SY'));
                setText('top_updated', new Date(num(kpi.updated_at) * 1000).toLocaleTimeString('ar-SY'));
                setText('top_sources', sourceCount);
                setText('top_status', risk >= 70 ? 'استنفار' : 'متصل');
                setText('sources', sourceCount);
                setText('critical', num(kpi.critical));
                setText('risk2', `${risk}%`);
                setText('risk_label', riskLabel);
                setText('streams', videoCount);
                setText('top_layer', String(kpi.top_hybrid_layer || 'عام'));
                setText('camera_total', sourceCount);
                setText('moderate_total', num(kpi.moderate));
                setText('low_total', num(kpi.low));
                setText('safe_total', safeCount);
                setText('connection_total', `${connection}%`);
                const ring = $('connection_ring');
                if (ring) ring.style.setProperty('--ring-angle', `${Math.round(connection * 3.6)}deg`);
            }

            function updateClock() {
                const now = new Date();
                setText('clock_live', now.toLocaleTimeString('ar-SY'));
                setText('system_time', now.toLocaleTimeString('ar-SY'));
            }

            function renderAll() {
                const data = state.data || {};
                updateTopNumbers(data);
                renderTicker(data.ticker_items || data.urgent || []);
                renderBrief(data);
                renderLiveStatus(data.video_streams || []);
                renderMap(data.markers || []);
                renderVideoWall(data.video_streams || [], data.urgent || data.feed || []);
                renderSeverityDonut(data.severity_breakdown || []);
                renderTrend((data.threat_overview || {}).trend_data || []);
                renderTopRegions((data.threat_overview || {}).top_regions || data.regions || []);
                renderAlertsTable(data.feed || []);
                renderRiskGauge((data.kpi || {}).risk_index || 0);
                renderRiskDirection((data.threat_overview || {}).trend_data || []);
                renderOverviewBoxes(data.threat_overview || {});
            }

            async function refreshSnapshot(days) {
                if (state.busy) return;
                state.busy = true;
                $('reload').disabled = true;
                try {
                    const body = new URLSearchParams({
                        action: 'sod_world_monitor_snapshot',
                        nonce: state.nonce,
                        days: String(days)
                    });
                    const response = await fetch(state.ajaxUrl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body
                    });
                    const json = await response.json();
                    if (json && json.success && json.data) {
                        state.data = json.data;
                        state.days = days;
                        renderAll();
                    }
                } catch (error) {
                    console.error('World Monitor refresh failed', error);
                } finally {
                    state.busy = false;
                    $('reload').disabled = false;
                }
            }

            $('reload').addEventListener('click', () => refreshSnapshot(Number($('days').value || state.days)));
            $('days').addEventListener('change', (event) => refreshSnapshot(Number(event.target.value || state.days)));
            renderAll();
            updateClock();
            window.setInterval(updateClock, 1000);
            window.setInterval(() => refreshSnapshot(Number($('days').value || state.days)), state.refreshMs);
        })();
        </script>
    </div>
    <?php
    return ob_get_clean();
}
}

add_shortcode('sod_world_monitor', 'sod_render_world_monitor_dashboard');
add_shortcode('world_monitor', 'sod_render_world_monitor_dashboard');
add_shortcode('osint_world_monitor', 'sod_render_world_monitor_dashboard');

if (!function_exists('sod_wm_force_shortcode_registration')) {
function sod_wm_force_shortcode_registration(): void {
    remove_shortcode('sod_world_monitor');
    remove_shortcode('world_monitor');
    remove_shortcode('osint_world_monitor');
    add_shortcode('sod_world_monitor', 'sod_render_world_monitor_dashboard');
    add_shortcode('world_monitor', 'sod_render_world_monitor_dashboard');
    add_shortcode('osint_world_monitor', 'sod_render_world_monitor_dashboard');
}
}

add_action('init', 'sod_wm_force_shortcode_registration', 999999);
