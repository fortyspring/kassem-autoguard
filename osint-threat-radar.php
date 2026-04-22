<?php
/**
 * Beiruttime OSINT - Live Threat Index Engine
 *
 * نسخة متوافقة مع جدول so_news_events الحالي دون الاعتماد على أعمدة غير مضمونة.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('sod_safe_substr')) {
    function sod_safe_substr($text, $start, $length) {
        $text = trim((string)$text);
        if ($text === '') return '';
        return mb_substr(wp_strip_all_tags($text), $start, $length, 'UTF-8');
    }
}

function sod_lti_table_exists() {
    global $wpdb;
    $table = $wpdb->prefix . 'so_news_events';
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
}

function sod_lti_status_label($index) {
    if ($index >= 85) return 'حرج جدًا';
    if ($index >= 70) return 'خطير';
    if ($index >= 55) return 'مرتفع';
    if ($index >= 35) return 'مراقبة';
    return 'منخفض';
}

function sod_lti_status_class($index) {
    if ($index >= 85) return 'critical';
    if ($index >= 70) return 'danger';
    if ($index >= 55) return 'high';
    if ($index >= 35) return 'watch';
    return 'low';
}

function sod_lti_trend_label($delta) {
    if ($delta >= 18) return '⬆ تصاعد سريع';
    if ($delta >= 7) return '↗ ارتفاع';
    if ($delta <= -18) return '⬇ هبوط واضح';
    if ($delta <= -7) return '↘ انخفاض';
    return '→ مستقر';
}

function sod_lti_extract_layers($row) {
    $layers = [];
    foreach (['war_data', 'field_data'] as $field) {
        $raw = $row[$field] ?? '';
        if (!$raw) continue;
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) continue;
        foreach (['hybrid_layers', 'active_layers'] as $key) {
            if (!empty($decoded[$key])) {
                $candidate = $decoded[$key];
                if (is_string($candidate)) {
                    $candidate = preg_split('/[,|]+/u', $candidate);
                }
                if (is_array($candidate)) {
                    foreach ($candidate as $v) {
                        $v = trim((string)$v);
                        if ($v !== '') $layers[] = $v;
                    }
                }
            }
        }
    }
    return array_values(array_unique($layers));
}

function sod_lti_calculate($window_hours = 6) {
    global $wpdb;

    $window_hours = max(1, (int)$window_hours);
    $table = $wpdb->prefix . 'so_news_events';
    $now = time();
    $current_since = $now - ($window_hours * HOUR_IN_SECONDS);
    $prev_since = $now - ($window_hours * 2 * HOUR_IN_SECONDS);

    if (!sod_lti_table_exists()) {
        return [
            'ok' => false,
            'message' => 'جدول الأحداث غير موجود بعد.',
        ];
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id,title,source_name,intel_type,tactical_level,region,actor_v2,score,event_timestamp,status,war_data,field_data
         FROM {$table}
         WHERE event_timestamp >= %d
         ORDER BY event_timestamp DESC
         LIMIT 1200",
        $prev_since
    ), ARRAY_A);

    if (!is_array($rows)) $rows = [];

    $current = [];
    $previous = [];
    foreach ($rows as $row) {
        $ts = (int)($row['event_timestamp'] ?? 0);
        if ($ts >= $current_since) {
            $current[] = $row;
        } elseif ($ts >= $prev_since) {
            $previous[] = $row;
        }
    }

    $build_bucket = function(array $items) {
        $total = count($items);
        $sum = 0;
        $critical = 0;
        $high = 0;
        $regions = [];
        $actors = [];
        $types = [];
        $layers = [];
        $latest_ts = 0;

        foreach ($items as $row) {
            $score = (int)($row['score'] ?? 0);
            $sum += $score;
            if ($score >= 120) $critical++;
            if ($score >= 80) $high++;

            $region = trim((string)($row['region'] ?? '')) ?: 'غير محدد';
            $actor = trim((string)($row['actor_v2'] ?? '')) ?: 'فاعل غير محسوم';
            $type = trim((string)($row['intel_type'] ?? '')) ?: 'عام';
            $regions[$region] = ($regions[$region] ?? 0) + 1;
            $actors[$actor] = ($actors[$actor] ?? 0) + 1;
            $types[$type] = ($types[$type] ?? 0) + 1;
            foreach (sod_lti_extract_layers($row) as $layer) {
                $layers[$layer] = ($layers[$layer] ?? 0) + 1;
            }
            $latest_ts = max($latest_ts, (int)($row['event_timestamp'] ?? 0));
        }

        arsort($regions);
        arsort($actors);
        arsort($types);
        arsort($layers);

        return [
            'total' => $total,
            'avg_score' => $total ? round($sum / $total, 1) : 0,
            'critical' => $critical,
            'high' => $high,
            'regions' => $regions,
            'actors' => $actors,
            'types' => $types,
            'layers' => $layers,
            'latest_ts' => $latest_ts,
        ];
    };

    $cur = $build_bucket($current);
    $prev = $build_bucket($previous);

    $volume_score   = min(30, $cur['total'] * 1.6);
    $severity_score = min(30, $cur['avg_score'] * 0.28);
    $critical_score = min(25, $cur['critical'] * 4.5);
    $spread_score   = min(15, count($cur['regions']) * 2.3);
    $index = (int)round(min(100, $volume_score + $severity_score + $critical_score + $spread_score));

    $prev_volume   = min(30, $prev['total'] * 1.6);
    $prev_severity = min(30, $prev['avg_score'] * 0.28);
    $prev_critical = min(25, $prev['critical'] * 4.5);
    $prev_spread   = min(15, count($prev['regions']) * 2.3);
    $prev_index = (int)round(min(100, $prev_volume + $prev_severity + $prev_critical + $prev_spread));
    $trend_delta = $index - $prev_index;

    return [
        'ok' => true,
        'window_hours' => $window_hours,
        'index' => $index,
        'prev_index' => $prev_index,
        'trend_delta' => $trend_delta,
        'trend' => sod_lti_trend_label($trend_delta),
        'status' => sod_lti_status_label($index),
        'status_class' => sod_lti_status_class($index),
        'total_events' => $cur['total'],
        'critical_events' => $cur['critical'],
        'high_events' => $cur['high'],
        'avg_score' => $cur['avg_score'],
        'hot_regions' => array_slice($cur['regions'], 0, 5, true),
        'top_actors' => array_slice($cur['actors'], 0, 5, true),
        'top_types' => array_slice($cur['types'], 0, 5, true),
        'active_layers' => array_slice($cur['layers'], 0, 6, true),
        'last_update' => $cur['latest_ts'] ?: $now,
    ];
}

function sod_render_live_threat_index($atts = []) {
    $atts = shortcode_atts([
        'hours' => 6,
        'show_layers' => '1',
    ], $atts, 'bt_live_threat_index');

    $data = sod_lti_calculate((int)$atts['hours']);

    ob_start();
    ?>
    <style>
    .bt-lti-wrap{direction:rtl;font-family:Tajawal,Arial,sans-serif;background:linear-gradient(135deg,#0f172a,#111827);color:#e5e7eb;border-radius:18px;padding:22px;border:1px solid rgba(255,255,255,.08);box-shadow:0 12px 28px rgba(0,0,0,.22)}
    .bt-lti-top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:18px}
    .bt-lti-title{font-size:24px;font-weight:800;color:#fff}
    .bt-lti-sub{font-size:13px;color:#94a3b8}
    .bt-lti-badge{padding:10px 14px;border-radius:999px;font-size:14px;font-weight:700}
    .bt-lti-badge.low{background:rgba(34,197,94,.14);color:#86efac}.bt-lti-badge.watch{background:rgba(250,204,21,.14);color:#fde68a}.bt-lti-badge.high{background:rgba(249,115,22,.14);color:#fdba74}.bt-lti-badge.danger{background:rgba(239,68,68,.14);color:#fca5a5}.bt-lti-badge.critical{background:rgba(127,29,29,.35);color:#fecaca}
    .bt-lti-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:18px}
    .bt-lti-card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:16px}
    .bt-lti-k{font-size:13px;color:#93c5fd;margin-bottom:8px}.bt-lti-v{font-size:28px;font-weight:800;color:#fff}.bt-lti-small{font-size:13px;color:#cbd5e1}
    .bt-lti-sections{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}
    .bt-lti-list{list-style:none;margin:0;padding:0}.bt-lti-list li{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.08)}.bt-lti-list li:last-child{border-bottom:none}
    .bt-lti-name{font-size:14px;color:#e5e7eb}.bt-lti-num{font-size:13px;color:#93c5fd;font-weight:700}
    .bt-lti-empty{color:#94a3b8;text-align:center;padding:18px 0}
    </style>
    <div class="bt-lti-wrap">
        <?php if (empty($data['ok'])): ?>
            <div class="bt-lti-title">مؤشر التهديد الحي</div>
            <div class="bt-lti-empty"><?php echo esc_html($data['message'] ?? 'لا توجد بيانات متاحة.'); ?></div>
        <?php else: ?>
            <div class="bt-lti-top">
                <div>
                    <div class="bt-lti-title">مؤشر التهديد الحي</div>
                    <div class="bt-lti-sub">آخر <?php echo esc_html((int)$data['window_hours']); ?> ساعات — آخر تحديث: <?php echo esc_html(date_i18n('Y-m-d h:i a', (int)$data['last_update'])); ?></div>
                </div>
                <div class="bt-lti-badge <?php echo esc_attr($data['status_class']); ?>"><?php echo esc_html($data['status']); ?> — <?php echo esc_html($data['index']); ?>/100</div>
            </div>

            <div class="bt-lti-grid">
                <div class="bt-lti-card"><div class="bt-lti-k">الاتجاه</div><div class="bt-lti-v" style="font-size:22px"><?php echo esc_html($data['trend']); ?></div><div class="bt-lti-small">مقارنةً بالنافذة السابقة</div></div>
                <div class="bt-lti-card"><div class="bt-lti-k">إجمالي الأحداث</div><div class="bt-lti-v"><?php echo esc_html($data['total_events']); ?></div><div class="bt-lti-small">ضمن نافذة الرصد الحالية</div></div>
                <div class="bt-lti-card"><div class="bt-lti-k">الأحداث الحرجة</div><div class="bt-lti-v"><?php echo esc_html($data['critical_events']); ?></div><div class="bt-lti-small">نقاط 120 فأكثر</div></div>
                <div class="bt-lti-card"><div class="bt-lti-k">متوسط النقاط</div><div class="bt-lti-v"><?php echo esc_html($data['avg_score']); ?></div><div class="bt-lti-small">شدة عامة للأحداث</div></div>
            </div>

            <div class="bt-lti-sections">
                <div class="bt-lti-card">
                    <div class="bt-lti-k">البؤر الساخنة</div>
                    <?php if (!empty($data['hot_regions'])): ?><ul class="bt-lti-list"><?php foreach ($data['hot_regions'] as $name => $count): ?><li><span class="bt-lti-name"><?php echo esc_html($name); ?></span><span class="bt-lti-num"><?php echo esc_html($count); ?></span></li><?php endforeach; ?></ul><?php else: ?><div class="bt-lti-empty">لا توجد بيانات مناطق.</div><?php endif; ?>
                </div>
                <div class="bt-lti-card">
                    <div class="bt-lti-k">الجهات الأبرز</div>
                    <?php if (!empty($data['top_actors'])): ?><ul class="bt-lti-list"><?php foreach ($data['top_actors'] as $name => $count): ?><li><span class="bt-lti-name"><?php echo esc_html($name); ?></span><span class="bt-lti-num"><?php echo esc_html($count); ?></span></li><?php endforeach; ?></ul><?php else: ?><div class="bt-lti-empty">لا توجد بيانات فاعلين.</div><?php endif; ?>
                </div>
                <div class="bt-lti-card">
                    <div class="bt-lti-k">الأنماط الأبرز</div>
                    <?php if (!empty($data['top_types'])): ?><ul class="bt-lti-list"><?php foreach ($data['top_types'] as $name => $count): ?><li><span class="bt-lti-name"><?php echo esc_html($name); ?></span><span class="bt-lti-num"><?php echo esc_html($count); ?></span></li><?php endforeach; ?></ul><?php else: ?><div class="bt-lti-empty">لا توجد بيانات تصنيف.</div><?php endif; ?>
                </div>
                <?php if ($atts['show_layers'] !== '0'): ?>
                <div class="bt-lti-card">
                    <div class="bt-lti-k">الطبقات النشطة</div>
                    <?php if (!empty($data['active_layers'])): ?><ul class="bt-lti-list"><?php foreach ($data['active_layers'] as $name => $count): ?><li><span class="bt-lti-name"><?php echo esc_html($name); ?></span><span class="bt-lti-num"><?php echo esc_html($count); ?></span></li><?php endforeach; ?></ul><?php else: ?><div class="bt-lti-empty">لا توجد طبقات نشطة مرصودة.</div><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function sod_render_threat_radar($atts = []) {
    return sod_render_live_threat_index($atts);
}

add_shortcode('bt_live_threat_index', 'sod_render_live_threat_index');
add_shortcode('sod_threat_radar', 'sod_render_threat_radar');

function sod_add_threat_radar_to_powerbi($content) {
    return $content . '<div style="margin:30px 0">' . sod_render_live_threat_index(['hours' => 6]) . '</div>';
}
add_filter('sod_powerbi_content', 'sod_add_threat_radar_to_powerbi');
