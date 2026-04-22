<?php
if (!defined('ABSPATH')) exit;

function sod_parse_json_array($raw): array {
    if (is_array($raw)) return $raw;
    if (!is_string($raw) || $raw === '') return [];
    $tmp = json_decode($raw, true);
    return is_array($tmp) ? $tmp : [];
}

function sod_newslog_json_flags(): int {
    $flags = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) $flags |= JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    return $flags;
}

function sod_newslog_send_success($data = null, int $status_code = 200): void {
    wp_send_json_success($data, $status_code, sod_newslog_json_flags());
}

function sod_newslog_send_error($data = null, ?int $status_code = null): void {
    wp_send_json_error($data, $status_code, sod_newslog_json_flags());
}

function sod_get_manual_override_state(array $row): array {
    $wd = sod_parse_json_array($row['war_data'] ?? '');
    $fd = sod_parse_json_array($row['field_data'] ?? '');
    $manual = [];
    if (!empty($wd['manual_override']) && is_array($wd['manual_override'])) $manual = $wd['manual_override'];
    elseif (!empty($fd['manual_override']) && is_array($fd['manual_override'])) $manual = $fd['manual_override'];
    $fields = [];
    if (!empty($manual['fields']) && is_array($manual['fields'])) {
        foreach ($manual['fields'] as $f) {
            $f = trim((string)$f);
            if ($f !== '') $fields[$f] = true;
        }
    }
    return ['enabled'=>!empty($manual['enabled']),'fields'=>$fields,'updated_at'=>(int)($manual['updated_at'] ?? 0),'editor'=>(string)($manual['editor'] ?? '')];
}

function sod_apply_manual_override_to_analyzed(array $analyzed, array $row): array {
    $state = sod_get_manual_override_state($row);
    if (empty($state['enabled']) || empty($state['fields'])) return $analyzed;
    $map = ['title'=>'title','intel_type'=>'intel_type','tactical_level'=>'tactical_level','region'=>'region','actor_v2'=>'actor_v2','score'=>'score','weapon_v2'=>'weapon_v2','target_v2'=>'target_v2','context_actor'=>'context_actor','intent'=>'intent'];
    foreach ($map as $src => $dst) {
        if (!isset($state['fields'][$src])) continue;
        if (array_key_exists($src, $row)) $analyzed[$dst] = $row[$src];
    }
    $wd = sod_parse_json_array($analyzed['war_data'] ?? '');
    $wd['evaluation_mode'] = 'manual_override';
    $wd['evaluation_label'] = 'يدوي مقفل';
    $wd['manual_override'] = ['enabled'=>true,'fields'=>array_keys($state['fields']),'updated_at'=>$state['updated_at'],'editor'=>$state['editor']];
    $analyzed['war_data'] = wp_json_encode($wd, JSON_UNESCAPED_UNICODE);
    $fd = sod_parse_json_array($analyzed['field_data'] ?? '');
    $fd['manual_override'] = $wd['manual_override'];
    $fd['evaluation_meta'] = ['mode'=>'manual_override','label'=>'يدوي مقفل'];
    $analyzed['field_data'] = wp_json_encode($fd, JSON_UNESCAPED_UNICODE);
    return $analyzed;
}

function sod_mark_evaluation_state(array $row, array $update, string $mode = 'auto'): array {
    $wd = sod_parse_json_array($row['war_data'] ?? '');
    if (isset($update['war_data'])) {
        $tmp = sod_parse_json_array($update['war_data']);
        if ($tmp) $wd = array_merge($wd, $tmp);
    }
    $label = 'آلي';
    if ($mode === 'manual_override') $label = 'يدوي مقفل';
    elseif ($mode === 'manual_saved') $label = 'حُفظ يدويًا';
    $wd['evaluation_mode'] = $mode;
    $wd['evaluation_label'] = $label;
    $wd['evaluated_at'] = time();
    $update['war_data'] = wp_json_encode($wd, JSON_UNESCAPED_UNICODE);

    $fd = sod_parse_json_array($row['field_data'] ?? '');
    if (isset($update['field_data'])) {
        $tmp = sod_parse_json_array($update['field_data']);
        if ($tmp) $fd = array_merge($fd, $tmp);
    }
    $fd['evaluation_meta'] = ['mode'=>$mode,'label'=>$label,'at'=>time()];
    $update['field_data'] = wp_json_encode($fd, JSON_UNESCAPED_UNICODE);
    return $update;
}

function sod_is_manual_locked_row(array $row): bool {
    $state = sod_get_manual_override_state($row);
    return !empty($state['enabled']);
}

function sod_collect_manual_override_fields(array $row, array $incoming = []): array {
    $fields = [];
    $tracked = ['title','intel_type','tactical_level','region','actor_v2','score','weapon_v2','target_v2','context_actor','intent'];
    foreach ($tracked as $field) {
        $newVal = array_key_exists($field, $incoming) ? (string)$incoming[$field] : (string)($row[$field] ?? '');
        $oldVal = (string)($row[$field] ?? '');
        if ($field === 'score') {
            $newVal = (string)((int)$newVal);
            $oldVal = (string)((int)$oldVal);
        }
        if ($newVal !== $oldVal) $fields[] = $field;
    }
    if (empty($fields)) $fields = ['actor_v2','intel_type','tactical_level','region','score','weapon_v2','target_v2','context_actor','intent'];
    return array_values(array_unique($fields));
}

function sod_attach_manual_override_state(array $row, array $update, array $fields, string $editor = ''): array {
    $wd = sod_parse_json_array($row['war_data'] ?? '');
    if (isset($update['war_data'])) {
        $tmp = sod_parse_json_array($update['war_data']);
        if ($tmp) $wd = array_merge($wd, $tmp);
    }
    $fd = sod_parse_json_array($row['field_data'] ?? '');
    if (isset($update['field_data'])) {
        $tmp = sod_parse_json_array($update['field_data']);
        if ($tmp) $fd = array_merge($fd, $tmp);
    }
    $manual = ['enabled'=>true,'fields'=>array_values(array_unique(array_filter(array_map('strval', $fields)))),'updated_at'=>time(),'editor'=>$editor !== '' ? $editor : 'admin'];
    $wd['manual_override'] = $manual;
    $wd['evaluation_mode'] = 'manual_override';
    $wd['evaluation_label'] = 'يدوي مقفل';
    $wd['evaluated_at'] = time();
    $fd['manual_override'] = $manual;
    $fd['evaluation_meta'] = ['mode'=>'manual_override','label'=>'يدوي مقفل','at'=>time()];
    $update['war_data'] = wp_json_encode($wd, JSON_UNESCAPED_UNICODE);
    $update['field_data'] = wp_json_encode($fd, JSON_UNESCAPED_UNICODE);
    return $update;
}

function sod_newslog_normalize_layers($raw): array {
    if (function_exists('sod_normalize_hybrid_layers_value')) {
        $normalized = sod_normalize_hybrid_layers_value($raw);
        if (!empty($normalized)) {
            $arabic = [];
            foreach ($normalized as $layer_key) {
                if (function_exists('sod_translate_hybrid_value')) {
                    $arabic[] = sod_translate_hybrid_value((string)$layer_key);
                } else {
                    $arabic[] = (string)$layer_key;
                }
            }
            return array_values(array_unique(array_filter(array_map('strval', $arabic))));
        }
    }

    $layers = [];
    if (is_array($raw)) {
        $layers = $raw;
    } elseif (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0' || $raw === '[]' || $raw === '{}') {
            return [];
        }
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) {
            $layers = $tmp;
        } else {
            if (!preg_match('/[,|+]/u', $raw)) {
                $probe = function_exists('mb_strtolower') ? mb_strtolower($raw) : strtolower($raw);
                if (preg_match_all('/media_psychological|geostrategic|military|security|political|economic|social|energy|cyber/u', $probe, $m) && !empty($m[0])) {
                    $layers = $m[0];
                } else {
                    $layers = preg_split('/[,|]+/u', $raw);
                }
            } else {
                $layers = preg_split('/[,|+]+/u', $raw);
            }
        }
    }

    $map = [
        'عسكري'=>'عسكري','العسكرية'=>'عسكري','military'=>'عسكري',
        'أمني'=>'أمني','امني'=>'أمني','security'=>'أمني',
        'سياسي'=>'سياسي','political'=>'سياسي',
        'اقتصادي'=>'اقتصادي','economic'=>'اقتصادي',
        'إعلامي/نفسي'=>'إعلامي/نفسي','إعلامي نفسي'=>'إعلامي/نفسي','media_psychological'=>'إعلامي/نفسي',
        'سيبراني/تقني'=>'سيبراني/تقني','cyber'=>'سيبراني/تقني',
        'طاقة'=>'طاقة','energy'=>'طاقة',
        'جيوستراتيجي'=>'جيوستراتيجي','geostrategic'=>'جيوستراتيجي',
        'اجتماعي'=>'اجتماعي','social'=>'اجتماعي',
    ];

    $out = [];
    foreach ((array)$layers as $k => $layer) {
        if (is_array($layer)) {
            $layer = $layer['name'] ?? $layer['label'] ?? $layer['layer'] ?? '';
        } elseif (!is_numeric($k) && !is_array($layer) && (is_bool($layer) || is_int($layer) || is_float($layer))) {
            if ((int)$layer === 0) {
                continue;
            }
            $layer = (string)$k;
        }

        $layer = trim((string)$layer);
        if ($layer === '' || $layer === '0') continue;
        $key = function_exists('mb_strtolower') ? mb_strtolower($layer) : strtolower($layer);
        $norm = $map[$layer] ?? $map[$key] ?? $layer;
        $out[$norm] = true;
    }
    return array_keys($out);
}

function sod_newslog_state_meta(array $row): array {
    $wd = sod_parse_json_array($row['war_data'] ?? '');
    $fd = sod_parse_json_array($row['field_data'] ?? '');
    $hybrid = $row['hybrid_layers'] ?? ($wd['hybrid_layers'] ?? ($fd['hybrid_layers'] ?? []));
    $layers = sod_newslog_normalize_layers($hybrid);
    $manual = sod_get_manual_override_state($row);
    $eval = [];
    if (!empty($fd['evaluation_meta']) && is_array($fd['evaluation_meta'])) $eval = $fd['evaluation_meta'];
    if (!empty($wd['evaluation_mode'])) $eval['mode'] = (string)$wd['evaluation_mode'];
    if (!empty($wd['evaluation_label'])) $eval['label'] = (string)$wd['evaluation_label'];
    if (!empty($wd['evaluated_at'])) $eval['at'] = (int)$wd['evaluated_at'];
    $mode = (string)($eval['mode'] ?? ($manual['enabled'] ? 'manual_override' : 'auto'));
    $label = (string)($eval['label'] ?? ($manual['enabled'] ? 'يدوي مقفل' : 'آلي'));
    $at = (int)($eval['at'] ?? 0);
    $reindexed_at = (int)($wd['reindexed_at'] ?? ($fd['reindexed_at'] ?? 0));
    return [
        'hybrid_layers' => $layers,
        'hybrid_count' => count($layers),
        'manual_locked' => !empty($manual['enabled']),
        'manual_fields' => array_keys($manual['fields'] ?? []),
        'manual_updated_at' => (int)($manual['updated_at'] ?? 0),
        'evaluation_mode' => $mode,
        'evaluation_label' => $label,
        'evaluated_at' => $at,
        'reindexed_at' => $reindexed_at,
    ];
}

function sod_newslog_rejection_summary_label(string $reason): string {
    $map = [
        'missing_title_5w1h' => 'عنوان غير مكتمل 5W1H',
        'title_incomplete_5w1h' => 'عنوان غير مكتمل 5W1H',
        'date_only_title' => 'تاريخ فقط',
        'handle_only_title' => 'حساب فقط',
        'date_and_handle_title' => 'تاريخ + حساب',
        'too_short_title' => 'عنوان قصير جدًا',
        'low_semantic_title' => 'عنوان ضعيف دلاليًا',
        'invalid_actor' => 'لا يوجد فاعل صالح',
        'empty_title' => 'عنوان فارغ',
    ];
    return (string)($map[$reason] ?? ($reason !== '' ? $reason : 'مرفوض'));
}

function sod_newslog_rejection_meta(array $row): array {
    $fd = sod_parse_json_array($row['field_data'] ?? '');
    $meta = is_array($fd['5w1h_validation'] ?? null) ? $fd['5w1h_validation'] : [];
    $reason = (string)($meta['rejection_reason'] ?? '');
    $hard = !empty($meta['hard_reject']) || $reason !== '';
    $title_audit = is_array($meta['title_audit'] ?? null) ? $meta['title_audit'] : [];
    return [
        'hard_reject' => $hard,
        'rejection_reason' => $reason,
        'rejection_label' => $hard ? sod_newslog_rejection_summary_label($reason) : '',
        'title_audit' => $title_audit,
    ];
}

function sod_newslog_rejection_panel_stats(): array {
    global $wpdb;
    $table = $wpdb->prefix . 'so_news_events';
    $reasons = [
        'title_incomplete_5w1h' => ['like' => '%"rejection_reason":"missing_title_5w1h"%', 'label' => 'عنوان غير مكتمل 5W1H'],
        'date_only_title' => ['like' => '%"rejection_reason":"date_only_title"%', 'label' => 'تاريخ فقط'],
        'handle_only_title' => ['like' => '%"rejection_reason":"handle_only_title"%', 'label' => 'حساب فقط'],
        'date_and_handle_title' => ['like' => '%"rejection_reason":"date_and_handle_title"%', 'label' => 'تاريخ + حساب'],
        'too_short_title' => ['like' => '%"rejection_reason":"too_short_title"%', 'label' => 'عنوان قصير جدًا'],
        'low_semantic_title' => ['like' => '%"rejection_reason":"low_semantic_title"%', 'label' => 'عنوان ضعيف دلاليًا'],
        'invalid_actor' => ['like' => '%"rejection_reason":"invalid_actor"%', 'label' => 'لا يوجد فاعل صالح'],
        'empty_title' => ['like' => '%"rejection_reason":"empty_title"%', 'label' => 'عنوان فارغ'],
    ];
    $reason_rows = [];
    $total_rejected = 0;
    foreach ($reasons as $code => $cfg) {
        $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE field_data LIKE %s", $cfg['like']));
        if ($count > 0) {
            $reason_rows[] = ['code' => $code, 'label' => $cfg['label'], 'count' => $count];
            $total_rejected += $count;
        }
    }
    usort($reason_rows, static function($a, $b){ return ($b['count'] <=> $a['count']); });

    $source_rows = $wpdb->get_results(
        "SELECT source_name, COUNT(*) AS cnt FROM {$table} WHERE field_data LIKE '%\"hard_reject\":true%' GROUP BY source_name ORDER BY cnt DESC LIMIT 7",
        ARRAY_A
    );
    $sources = [];
    foreach ((array)$source_rows as $row) {
        $sources[] = [
            'source' => (string)($row['source_name'] ?? 'غير محدد'),
            'count' => (int)($row['cnt'] ?? 0),
        ];
    }

    return [
        'total_rejected' => $total_rejected,
        'reasons' => $reason_rows,
        'sources' => $sources,
        'top_reason' => $reason_rows[0]['label'] ?? '',
    ];
}

function sod_newslog_extract_classification_fields(array $row): array {
    $wd = sod_parse_json_array($row['war_data'] ?? '');
    return [
        'title' => (string)($row['title'] ?? ''),
        'intel_type' => (string)($row['intel_type'] ?? ($wd['intel_type'] ?? '')),
        'tactical_level' => (string)($row['tactical_level'] ?? ($wd['tactical_level'] ?? ($wd['level'] ?? ''))),
        'region' => (string)($row['region'] ?? ($wd['region'] ?? '')),
        'actor_v2' => (string)($row['actor_v2'] ?? ($wd['actor'] ?? '')),
        'target_v2' => (string)($row['target_v2'] ?? ($wd['target'] ?? '')),
        'context_actor' => (string)($row['context_actor'] ?? ($wd['context_actor'] ?? '')),
        'intent' => (string)($row['intent'] ?? ($wd['intent'] ?? '')),
        'weapon_v2' => (string)($row['weapon_v2'] ?? ($wd['weapon_means'] ?? '')),
        'score' => (int)($row['score'] ?? 0),
        'status' => (string)($row['status'] ?? 'published'),
        'war_data' => $wd,
    ];
}

function sod_ajax_newslog_search(): void {
    if (!current_user_can('manage_options')) { sod_newslog_send_error('unauthorized', 403); }
    check_ajax_referer('sod_newslog_search', 'nonce');
    global $wpdb;
    $table   = $wpdb->prefix . 'so_news_events';
    $lrn_tbl = $wpdb->prefix . 'so_manual_learning';

    $q = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));
    $region = sanitize_text_field(wp_unslash($_POST['region'] ?? ''));
    $actor = sanitize_text_field(wp_unslash($_POST['actor'] ?? ''));
    $type = sanitize_text_field(wp_unslash($_POST['intel_type'] ?? ''));
    $score = (int)($_POST['score'] ?? 0);
    $eval_filter = sanitize_text_field(wp_unslash($_POST['evaluation_state'] ?? ''));
    $manual_filter = sanitize_text_field(wp_unslash($_POST['manual_state'] ?? ''));
    $hybrid_filter = sanitize_text_field(wp_unslash($_POST['hybrid_state'] ?? ''));
    $page = max(1, (int)($_POST['page'] ?? 1));
    $per = min(200, max(10, (int)($_POST['per_page'] ?? 25)));
    $offset = ($page - 1) * $per;

    $where = ['1=1'];
    $params = [];
    if ($q !== '') { $where[] = '(title LIKE %s OR source_name LIKE %s OR actor_v2 LIKE %s OR region LIKE %s)'; $like = '%' . $wpdb->esc_like($q) . '%'; array_push($params, $like, $like, $like, $like); }
    if ($region !== '') { $where[] = 'region = %s'; $params[] = $region; }
    if ($actor !== '') { $where[] = 'actor_v2 = %s'; $params[] = $actor; }
    if ($type !== '') { $where[] = 'intel_type = %s'; $params[] = $type; }
    if ($score > 0) { $where[] = 'score >= %d'; $params[] = $score; }

    if ($manual_filter === 'locked') { $where[] = '(war_data LIKE %s OR field_data LIKE %s)'; $params[] = '%"manual_override":%'; $params[] = '%"manual_override":%'; }
    elseif ($manual_filter === 'unlocked') { $where[] = '(war_data NOT LIKE %s AND field_data NOT LIKE %s)'; $params[] = '%"manual_override":%'; $params[] = '%"manual_override":%'; }

    if ($eval_filter === 'manual_override') { $where[] = '(war_data LIKE %s OR field_data LIKE %s)'; $params[] = '%"evaluation_mode":"manual_override"%'; $params[] = '%"mode":"manual_override"%'; }
    elseif ($eval_filter === 'manual_saved') { $where[] = '(war_data LIKE %s OR field_data LIKE %s)'; $params[] = '%"evaluation_mode":"manual_saved"%'; $params[] = '%"mode":"manual_saved"%'; }
    elseif ($eval_filter === 'auto') { $where[] = '(war_data LIKE %s OR field_data LIKE %s)'; $params[] = '%"evaluation_mode":"auto"%'; $params[] = '%"mode":"auto"%'; }

    if ($hybrid_filter === 'yes') { $where[] = '((hybrid_layers IS NOT NULL AND hybrid_layers <> "" AND hybrid_layers <> "[]") OR war_data LIKE %s OR field_data LIKE %s)'; $params[] = '%"hybrid_layers":%'; $params[] = '%"hybrid_layers":%'; }
    elseif ($hybrid_filter === 'no') { $where[] = '((hybrid_layers IS NULL OR hybrid_layers = "" OR hybrid_layers = "[]") AND war_data NOT LIKE %s AND field_data NOT LIKE %s)'; $params[] = '%"hybrid_layers":%'; $params[] = '%"hybrid_layers":%'; }

    $clause = implode(' AND ', $where);
    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
    $rows_sql = "SELECT id,title,link,source_name,source_color,intel_type,tactical_level,region,actor_v2,score,status,event_timestamp,war_data,field_data,weapon_v2,target_v2,context_actor,intent,title_fingerprint,hybrid_layers FROM {$table} WHERE {$clause} ORDER BY event_timestamp DESC, id DESC LIMIT %d OFFSET %d";

    $total = (int)$wpdb->get_var($wpdb->prepare($count_sql, ...$params));
    $rows_params = array_merge($params, [$per, $offset]);
    $rows = $wpdb->get_results($wpdb->prepare($rows_sql, ...$rows_params), ARRAY_A);

    $all_total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $classified = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE actor_v2 IS NOT NULL AND actor_v2 <> '' AND actor_v2 NOT IN ('غير محدد','عام/مجهول','فاعل غير محسوم','فاعل قيد التقييم','جهة غير معلنة','فاعل سياقي','فاعل سياقي غير مباشر')");
    $manual_locked = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE war_data LIKE '%\"manual_override\":%' OR field_data LIKE '%\"manual_override\":%'");
    $hybrid_ready = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE ((hybrid_layers IS NOT NULL AND hybrid_layers <> '' AND hybrid_layers <> '[]') OR war_data LIKE '%\"hybrid_layers\":%' OR field_data LIKE '%\"hybrid_layers\":%')");

    $items = [];
    foreach ((array)$rows as $row) {
        $state = sod_newslog_state_meta($row);
        $reject = sod_newslog_rejection_meta($row);
        $item = [
            'id' => (int)($row['id'] ?? 0),
            'title' => (string)($row['title'] ?? ''),
            'link' => (string)($row['link'] ?? ''),
            'source_name' => (string)($row['source_name'] ?? ''),
            'source_color' => (string)($row['source_color'] ?? ''),
            'intel_type' => (string)($row['intel_type'] ?? ''),
            'tactical_level' => (string)($row['tactical_level'] ?? ''),
            'region' => (string)($row['region'] ?? ''),
            'actor_v2' => (string)($row['actor_v2'] ?? ''),
            'score' => (int)($row['score'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'event_timestamp' => (int)($row['event_timestamp'] ?? 0),
            'weapon_v2' => (string)($row['weapon_v2'] ?? ''),
            'target_v2' => (string)($row['target_v2'] ?? ''),
            'context_actor' => (string)($row['context_actor'] ?? ''),
            'intent' => (string)($row['intent'] ?? ''),
            'title_fingerprint' => (string)($row['title_fingerprint'] ?? ''),
            'hybrid_layers' => $state['hybrid_layers'],
            'evaluation_mode' => (string)$state['evaluation_mode'],
            'evaluation_label' => (string)$state['evaluation_label'],
            'manual_locked' => !empty($state['manual_locked']),
            'hard_reject' => !empty($reject['hard_reject']),
            'rejection_reason' => (string)$reject['rejection_reason'],
            'rejection_label' => (string)$reject['rejection_label'],
            'title_audit' => $reject['title_audit'],
            'has_learning' => false,
        ];
        $fp = (string)($item['title_fingerprint'] ?: (function_exists('so_build_title_fingerprint') ? so_build_title_fingerprint($item['title']) : md5($item['title'])));
        if ($fp !== '' && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$lrn_tbl} WHERE title_fingerprint=%s LIMIT 1", $fp))) {
            $item['has_learning'] = true;
        }
        $items[] = $item;
    }

    sod_newslog_send_success([
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $per,
        'stats' => array_merge([
            'classified' => $classified,
            'unclassified' => max(0, $all_total - $classified),
            'manual_locked' => $manual_locked,
            'hybrid_ready' => $hybrid_ready,
        ], [
            'rejections' => sod_newslog_rejection_panel_stats(),
        ]),
    ]);
}

function sod_ajax_newslog_autotrain(): void {
    if (!current_user_can('manage_options')) { sod_newslog_send_error('unauthorized', 403); }
    check_ajax_referer('sod_newslog_save', 'nonce');
    $limit = min(5000, max(200, (int)($_POST['limit'] ?? 1200)));
    sod_newslog_send_success(sod_auto_dataset_training_from_newslog($limit));
}

function sod_ajax_newslog_bulk(): void {
    if (!current_user_can('manage_options')) { sod_newslog_send_error('unauthorized', 403); }
    check_ajax_referer('sod_newslog_bulk', 'nonce');
    global $wpdb;
    $table = $wpdb->prefix . 'so_news_events';
    $action = sanitize_text_field($_POST['bulk_action'] ?? '');
    $ids_raw = $_POST['ids'] ?? '';
    $ids = array_filter(array_map('intval', is_array($ids_raw) ? $ids_raw : explode(',', $ids_raw)));
    if (empty($ids)) { sod_newslog_send_error('no ids'); }
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    if ($action === 'delete') {
        $affected = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", ...$ids));
        sod_newslog_send_success(['deleted'=>$affected]);
    } elseif (in_array($action, ['published','pending','draft'], true)) {
        $affected = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status=%s WHERE id IN ({$placeholders})", $action, ...$ids));
        sod_newslog_send_success(['updated'=>$affected,'status'=>$action]);
    }
    sod_newslog_send_error('unknown action');
}

function sod_ajax_newslog_get_banks(): void {
    if (!current_user_can('manage_options')) { sod_newslog_send_error('unauthorized', 403); }
    check_ajax_referer('sod_newslog_search', 'nonce');
    sod_newslog_send_success(sod_get_visible_learning_banks());
}

function sod_ajax_newslog_add_to_bank(): void {
    if (!current_user_can('manage_options')) { sod_newslog_send_error('unauthorized', 403); }
    check_ajax_referer('sod_newslog_save', 'nonce');
    $bank = sanitize_text_field($_POST['bank'] ?? '');
    $value = sanitize_text_field($_POST['value'] ?? '');
    if (!$value) { sod_newslog_send_error('empty value'); }
    $banks = sod_add_bank_value($bank, $value);
    sod_newslog_send_success(['bank'=>sod_normalize_bank_key($bank),'value'=>$value,'banks'=>$banks]);
}

function sod_ajax_newslog_remove_from_bank(): void {
    if (!current_user_can('manage_options')) { sod_newslog_send_error('unauthorized', 403); }
    check_ajax_referer('sod_newslog_save', 'nonce');
    $bank = sanitize_text_field($_POST['bank'] ?? '');
    $value = sanitize_text_field($_POST['value'] ?? '');
    if (!$value) { sod_newslog_send_error('invalid'); }
    $banks = sod_remove_bank_value($bank, $value);
    sod_newslog_send_success(['bank'=>sod_normalize_bank_key($bank),'value'=>$value,'banks'=>$banks]);
}


function sod_newslog_classified_count(): int {
    global $wpdb;
    $table = $wpdb->prefix . 'so_news_events';
    return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE actor_v2 IS NOT NULL AND actor_v2 <> '' AND actor_v2 NOT IN ('غير محدد','عام/مجهول','فاعل غير محسوم','فاعل قيد التقييم','جهة غير معلنة','فاعل سياقي','فاعل سياقي غير مباشر')");
}

function sod_newslog_total_count(): int {
    global $wpdb;
    $table = $wpdb->prefix . 'so_news_events';
    return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
}

function sod_newslog_build_item_from_row(array $row): array {
    return [
        'title'      => (string)($row['title'] ?? ''),
        'link'       => (string)($row['link'] ?? ''),
        'source'     => (string)($row['source_name'] ?? ($row['source'] ?? '')),
        'source_name'=> (string)($row['source_name'] ?? ($row['source'] ?? '')),
        'color'      => (string)($row['source_color'] ?? '#1da1f2'),
        'date'       => (string)($row['event_timestamp'] ?? ''),
        'agency_loc' => (string)($row['agency_loc'] ?? ''),
        'image_url'  => (string)($row['image_url'] ?? ''),
        'content'    => (string)($row['content'] ?? ''),
    ];
}

function sod_newslog_reclassify_single_row(array $row): array {
    global $wpdb;
    $table = $wpdb->prefix . 'so_news_events';
    if (empty($row['id'])) return ['ok'=>false,'error'=>'invalid_row'];
    if (sod_is_manual_locked_row($row)) {
        $update = sod_mark_evaluation_state($row, [
            'war_data' => (string)($row['war_data'] ?? '{}'),
            'field_data' => (string)($row['field_data'] ?? '{}'),
        ], 'manual_override');
        sod_db_safe_update($table, $update, ['id' => (int)$row['id']]);
        return ['ok'=>false,'locked'=>true,'error'=>'manual_locked'];
    }

    $item = sod_newslog_build_item_from_row($row);
    $analyzed = SO_OSINT_Engine::process_event($item);
    if (!$analyzed || !is_array($analyzed)) {
        return ['ok'=>false,'error'=>'analysis_failed'];
    }

    $analyzed = sod_finalize_reanalysis_payload($row, $item, $analyzed);
    $analyzed = sod_v22_improve_reanalysis_payload($row, $item, $analyzed);

    $wd = sod_parse_json_array($analyzed['war_data'] ?? '{}');
    $target_v2 = (string)($wd['target'] ?? ($analyzed['target_v2'] ?? ''));
    $context_actor = (string)($wd['context_actor'] ?? ($analyzed['context_actor'] ?? ''));
    $intent = (string)($wd['intent'] ?? ($analyzed['intent'] ?? ''));
    $weapon_v2 = (string)($wd['weapon_means'] ?? ($analyzed['weapon_v2'] ?? ''));
    $title = (string)($row['title'] ?? '');

    $update_payload = [
        'title_fingerprint' => function_exists('so_build_title_fingerprint') ? so_build_title_fingerprint($title) : md5($title),
        'intel_type'     => (string)($analyzed['intel_type'] ?? ($row['intel_type'] ?? '')),
        'tactical_level' => (string)($analyzed['tactical_level'] ?? ($row['tactical_level'] ?? '')),
        'region'         => (string)($analyzed['region'] ?? ($row['region'] ?? '')),
        'actor_v2'       => (string)($analyzed['actor_v2'] ?? ($row['actor_v2'] ?? 'فاعل غير محسوم')),
        'score'          => (int)($analyzed['score'] ?? ($row['score'] ?? 0)),
        'war_data'       => (string)($analyzed['war_data'] ?? '{}'),
        'field_data'     => (string)($analyzed['field_data'] ?? '{}'),
        'target_v2'      => $target_v2,
        'context_actor'  => $context_actor,
        'intent'         => $intent,
        'weapon_v2'      => $weapon_v2,
        'hybrid_layers'  => (string)($analyzed['hybrid_layers'] ?? ($row['hybrid_layers'] ?? '[]')),
    ];
    $update_payload = sod_mark_evaluation_state($row, $update_payload, 'auto');
    $res = sod_db_safe_update($table, $update_payload, ['id' => (int)$row['id']]);
    if (empty($res['ok'])) {
        return ['ok'=>false,'error'=>'db_update_failed','db'=>$res];
    }

    foreach ([['types',$update_payload['intel_type']],['levels',$update_payload['tactical_level']],['regions',$update_payload['region']],['actors',$update_payload['actor_v2']],['targets',$target_v2],['contexts',$context_actor],['intents',$intent],['weapons',$weapon_v2]] as $pair) {
        [$bk,$val] = $pair;
        if ($val !== '' && $val !== 'فاعل غير محسوم' && $val !== 'غير محدد') {
            sod_add_bank_value($bk, $val);
        }
    }

    return ['ok'=>true,'updated'=>1,'item'=>array_merge($row, $update_payload)];
}

function sod_ajax_newslog_save(): void {
    if (!current_user_can('manage_options')) { sod_newslog_send_error(['message'=>'unauthorized'], 403); }
    check_ajax_referer('sod_newslog_save', 'nonce');
    global $wpdb;
    $table = $wpdb->prefix . 'so_news_events';
    $id = max(0, (int)($_POST['id'] ?? 0));
    if ($id <= 0) { sod_newslog_send_error(['message'=>'invalid_id'], 400); }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
    if (!$row) { sod_newslog_send_error(['message'=>'not_found'], 404); }

    $incoming = [
        'title' => sanitize_textarea_field(wp_unslash($_POST['title'] ?? ($row['title'] ?? ''))),
        'intel_type' => sanitize_text_field(wp_unslash($_POST['intel_type'] ?? ($row['intel_type'] ?? ''))),
        'tactical_level' => sanitize_text_field(wp_unslash($_POST['tactical_level'] ?? ($row['tactical_level'] ?? ''))),
        'region' => sanitize_text_field(wp_unslash($_POST['region'] ?? ($row['region'] ?? ''))),
        'actor_v2' => sanitize_text_field(wp_unslash($_POST['actor_v2'] ?? ($row['actor_v2'] ?? ''))),
        'score' => max(0, min(300, (int)($_POST['score'] ?? ($row['score'] ?? 0)))),
        'status' => sanitize_text_field(wp_unslash($_POST['status'] ?? ($row['status'] ?? 'published'))),
        'weapon_v2' => sanitize_text_field(wp_unslash($_POST['weapon_v2'] ?? ($row['weapon_v2'] ?? ''))),
        'target_v2' => sanitize_text_field(wp_unslash($_POST['target_v2'] ?? ($row['target_v2'] ?? ''))),
        'context_actor' => sanitize_text_field(wp_unslash($_POST['context_actor'] ?? ($row['context_actor'] ?? ''))),
        'intent' => sanitize_text_field(wp_unslash($_POST['intent'] ?? ($row['intent'] ?? ''))),
    ];
    if ($incoming['title'] === '') $incoming['title'] = (string)($row['title'] ?? '');

    $fields = sod_collect_manual_override_fields($row, $incoming);
    $update = [
        'title' => $incoming['title'],
        'title_fingerprint' => function_exists('so_build_title_fingerprint') ? so_build_title_fingerprint($incoming['title']) : md5($incoming['title']),
        'intel_type' => $incoming['intel_type'],
        'tactical_level' => $incoming['tactical_level'],
        'region' => $incoming['region'],
        'actor_v2' => $incoming['actor_v2'],
        'score' => $incoming['score'],
        'status' => in_array($incoming['status'], ['published','pending','draft'], true) ? $incoming['status'] : (string)($row['status'] ?? 'published'),
        'weapon_v2' => $incoming['weapon_v2'],
        'target_v2' => $incoming['target_v2'],
        'context_actor' => $incoming['context_actor'],
        'intent' => $incoming['intent'],
    ];

    $editor = function_exists('wp_get_current_user') ? (string)(wp_get_current_user()->user_login ?? 'admin') : 'admin';
    $event = [
        'title' => $incoming['title'],
        'source_name' => (string)($row['source_name'] ?? ''),
    ];
    if (class_exists('SO_Manual_Learning')) {
        SO_Manual_Learning::save_feedback($event, $incoming);
    }
    $update = sod_attach_manual_override_state($row, $update, $fields, $editor);

    $result = sod_db_safe_update($table, $update, ['id' => $id]);
    if (empty($result['ok'])) {
        sod_newslog_send_error(['message'=>'db_update_failed','details'=>$result], 500);
    }

    foreach ([['types',$incoming['intel_type']],['levels',$incoming['tactical_level']],['regions',$incoming['region']],['actors',$incoming['actor_v2']],['targets',$incoming['target_v2']],['contexts',$incoming['context_actor']],['intents',$incoming['intent']],['weapons',$incoming['weapon_v2']]] as $pair) {
        [$bk,$val] = $pair;
        if ($val !== '' && $val !== 'فاعل غير محسوم' && $val !== 'غير محدد') {
            sod_add_bank_value($bk, $val);
        }
    }

    $updated_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
    $state = $updated_row ? sod_newslog_state_meta($updated_row) : ['evaluation_mode'=>'manual_override','evaluation_label'=>'يدوي مقفل'];
    sod_newslog_send_success([
        'id' => $id,
        'locked' => true,
        'learning_saved' => true,
        'evaluation_mode' => (string)($state['evaluation_mode'] ?? 'manual_override'),
        'evaluation_label' => (string)($state['evaluation_label'] ?? 'يدوي مقفل'),
        'fields_locked' => $fields,
    ]);
}

function sod_ajax_newslog_reclassify(): void {
    if (!current_user_can('manage_options')) { sod_newslog_send_error(['message'=>'unauthorized'], 403); }
    check_ajax_referer('sod_newslog_reclassify', 'nonce');
    global $wpdb;
    $table = $wpdb->prefix . 'so_news_events';
    $mode = sanitize_text_field(wp_unslash($_POST['mode'] ?? 'single'));

    if ($mode === 'single') {
        $id = max(0, (int)($_POST['id'] ?? 0));
        if ($id <= 0) { sod_newslog_send_error(['message'=>'invalid_id'], 400); }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
        if (!$row) { sod_newslog_send_error(['message'=>'not_found'], 404); }
        $res = sod_newslog_reclassify_single_row($row);
        if (!empty($res['locked'])) {
            sod_newslog_send_error(['message'=>'manual_locked','human'=>'هذا الخبر مقفل يدويًا ولن يُعاد تصنيفه آليًا.'], 409);
        }
        if (empty($res['ok'])) {
            sod_newslog_send_error(['message'=>$res['error'] ?? 'reclassify_failed','details'=>$res], 500);
        }
        sod_newslog_send_success(['updated'=>1,'id'=>$id]);
    }

    if ($mode === 'all') {
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $batch = min(200, max(10, (int)($_POST['batch'] ?? 50)));
        $before_classified = sod_newslog_classified_count();
        $before_total = sod_newslog_total_count();
        $summary = so_reanalyze_all_news_events($batch, $offset);
        $after_classified = sod_newslog_classified_count();
        $after_total = sod_newslog_total_count();
        $processed = min($after_total, (int)($summary['next_offset'] ?? 0));
        $total = max(0, $after_total);
        $percent = $total > 0 ? (int)round(($processed / $total) * 100) : 100;
        sod_newslog_send_success([
            'updated' => (int)($summary['updated'] ?? 0),
            'locked' => (int)($summary['locked'] ?? 0),
            'skipped' => (int)($summary['locked'] ?? 0),
            'processed' => $processed,
            'next_offset' => (int)($summary['next_offset'] ?? 0),
            'total' => $total,
            'percent' => max(0, min(100, $percent)),
            'done' => !empty($summary['done']),
            'stats' => [
                'classified_before' => $before_classified,
                'unclassified_before' => max(0, $before_total - $before_classified),
                'classified_after' => $after_classified,
                'unclassified_after' => max(0, $after_total - $after_classified),
            ],
        ]);
    }

    sod_newslog_send_error(['message'=>'unknown_mode'], 400);
}
