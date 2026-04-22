<?php
/**
 * Beiruttime OSINT - Hybrid Warfare & Advanced OSINT Fields Update
 * 
 * هذا الملف يضيف الدعم الكامل لطبقات الحرب المركبة وحقول OSINT المتقدمة
 * وفقاً للوثيقة الشاملة المقدمة
 * 
 * الإصدار: 1.0.0
 * التاريخ: 2024
 */

if (!defined('ABSPATH')) exit;

// ==========================================================================
// 1. تحديث هيكل قاعدة البيانات - إضافة حقول OSINT المتقدمة
// ==========================================================================

function sod_hybrid_warfare_add_advanced_fields() {
    global $wpdb;
    $table = $wpdb->prefix . 'so_news_events';
    
    // قائمة الحقول الأساسية الـ 15 الأكثر أهمية للأداء
    // تم اختيارها بناءً على الاستخدام المتكرر في الاستعلامات والتحليل
    $fields = [
        // 1-5: حقول التصنيف والتهديد الأساسية
        ['name' => 'osint_type', 'type' => "varchar(100) DEFAULT 'عام'", 'desc' => 'نوع OSINT'],
        ['name' => 'threat_score', 'type' => "int(11) DEFAULT 0", 'desc' => 'درجة التهديد'],
        ['name' => 'escalation_score', 'type' => "int(11) DEFAULT 0", 'desc' => 'درجة التصعيد'],
        ['name' => 'confidence_score', 'type' => "int(11) DEFAULT 0", 'desc' => 'درجة الثقة'],
        ['name' => 'risk_level', 'type' => "varchar(50) DEFAULT 'منخفض'", 'desc' => 'مستوى الخطر'],
        
        // 6-9: حقول التحقق الأساسية
        ['name' => 'verification_status', 'type' => "varchar(50) DEFAULT 'غير مؤكد'", 'desc' => 'حالة التحقق'],
        ['name' => 'verified_sources_count', 'type' => "int(11) DEFAULT 0", 'desc' => 'عدد المصادر المؤكدة'],
        ['name' => 'has_visual_evidence', 'type' => "tinyint(1) DEFAULT 0", 'desc' => 'وجود أدلة بصرية'],
        
        // 10-12: حقول الفاعل والموقع
        ['name' => 'primary_actor', 'type' => "varchar(150) DEFAULT ''", 'desc' => 'الفاعل الرئيسي'],
        ['name' => 'geo_country', 'type' => "varchar(100) DEFAULT ''", 'desc' => 'الدولة'],
        ['name' => 'geo_coordinates', 'type' => "varchar(100) DEFAULT ''", 'desc' => 'الإحداثيات (lat,lon)'],
        
        // 13-15: حقول الإنذار والطبقات
        ['name' => 'alert_flag', 'type' => "tinyint(1) DEFAULT 0", 'desc' => 'علم الإنذار'],
        ['name' => 'alert_priority', 'type' => "varchar(50) DEFAULT 'عادي'", 'desc' => 'أولوية الإنذار'],
        ['name' => 'multi_domain_score', 'type' => "int(11) DEFAULT 0", 'desc' => 'درجة التعددية المجال'],
        ['name' => 'hybrid_layers', 'type' => "text NULL", 'desc' => 'طبقات الحرب المركبة (JSON)'],
    ];
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    
    foreach ($fields as $field) {
        $column_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $field['name'])
        );
        
        if (!$column_exists) {
            $sql = "ALTER TABLE {$table} ADD COLUMN {$field['name']} {$field['type']}";
            $wpdb->query($sql);
            so_log("تم إضافة حقل OSINT المتقدم: {$field['name']} ({$field['desc']})", 'UPDATE');
        } else {
            so_log("الحقل موجود مسبقاً: {$field['name']}", 'SKIP');
        }
    }
    
    // إنشاء مفاتيح الفهرسة الأساسية لتحسين الأداء
    $indexes = [
        ['name' => 'idx_threat_score', 'sql' => "CREATE INDEX idx_threat_score ON {$table} (threat_score)"],
        ['name' => 'idx_verification', 'sql' => "CREATE INDEX idx_verification ON {$table} (verification_status, confidence_score)"],
        ['name' => 'idx_alert', 'sql' => "CREATE INDEX idx_alert ON {$table} (alert_flag, alert_priority, threat_score)"],
        ['name' => 'idx_risk_level', 'sql' => "CREATE INDEX idx_risk_level ON {$table} (risk_level)"],
        ['name' => 'idx_primary_actor', 'sql' => "CREATE INDEX idx_primary_actor ON {$table} (primary_actor)"],
        ['name' => 'idx_geo_country', 'sql' => "CREATE INDEX idx_geo_country ON {$table} (geo_country)"],
    ];
    
    foreach ($indexes as $index) {
        $exists = $wpdb->get_var(
            $wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index['name'])
        );
        
        if (!$exists) {
            try {
                $wpdb->query($index['sql']);
                so_log("تم إنشاء الفهرس: {$index['name']}", 'INDEX');
            } catch (\Exception $e) {
                so_log("فشل إنشاء الفهرس {$index['name']}: " . $e->getMessage(), 'ERROR');
            }
        } else {
            so_log("الفهرس موجود مسبقاً: {$index['name']}", 'SKIP');
        }
    }
    
    update_option('sod_hybrid_warfare_fields_version', '2.0.0');
    so_log("تم تحديث قاعدة البيانات بـ 15 حقل أساسي وفهارس محسنة", 'COMPLETE');
}

// ==========================================================================
// 2. قاموس طبقات الحرب المركبة - Hybrid Warfare Layers Dictionary
// ==========================================================================

function sod_get_hybrid_warfare_layers() {
    return [
        'military' => [
            'id' => 'military',
            'name_ar' => 'الطبقة العسكرية',
            'keywords' => ['غارة', 'قصف', 'استهداف', 'هجوم', 'اشتباك', 'اقتحام', 'توغل', 'صاروخ', 'صواريخ', 'مسيّرة', 'مسيرة', 'دبابة', 'ثكنة', 'كمين', 'اغتيال', 'اعتراض', 'تحشيد', 'مناورة', 'تموضع', 'منظومة دفاعية'],
            'weight' => 1.0,
            'indicators' => ['kinetic_action', 'military_movement', 'weapons_deployment', 'frontline_activity']
        ],
        'security' => [
            'id' => 'security',
            'name_ar' => 'الطبقة الأمنية',
            'keywords' => ['اعتقال', 'مداهمة', 'تفكيك', 'خلية', 'أمني', 'تحذير أمني', 'إنذار داخلي', 'حاجز', 'إقفال', 'جاهزية', 'تجسس', 'تسلل', 'ضبط', 'حماية منشأة'],
            'weight' => 0.85,
            'indicators' => ['internal_security', 'counter_intel', 'arrests', 'security_measures']
        ],
        'cyber' => [
            'id' => 'cyber',
            'name_ar' => 'الطبقة السيبرانية',
            'keywords' => ['اختراق', 'سيبراني', 'هجمة سيبرانية', 'تعطيل', 'تسريب', 'بيانات', 'أنظمة اتصالات', 'حجب', 'DDoS', 'قرصنة', 'مجموعات قرصنة', 'ذكاء اصطناعي', 'تضليل رقمي'],
            'weight' => 0.9,
            'indicators' => ['cyber_attack', 'data_breach', 'service_disruption', 'digital_influence']
        ],
        'geographic' => [
            'id' => 'geographic',
            'name_ar' => 'الطبقة الجغرافية والفضائية',
            'keywords' => ['قمر صناعي', 'صورة فضائية', 'تحصينات', 'موقع جديد', 'بطاريات', 'خنادق', 'تدمير مباني', 'لوجستي', 'حرارية', 'قبل وبعد', 'تحديد موقع', 'تضاريس'],
            'weight' => 0.8,
            'indicators' => ['satellite_imagery', 'fortification', 'infrastructure_damage', 'geolocation']
        ],
        'political' => [
            'id' => 'political',
            'name_ar' => 'الطبقة السياسية والدبلوماسية',
            'keywords' => ['تصريح', 'قرار حكومي', 'عقوبات', 'اتفاقية', 'زيارة وفد', 'تهديد سياسي', 'سفير', 'مجلس الأمن', 'تفاهمات', 'مفاوضات', 'تسوية', 'انسداد'],
            'weight' => 0.75,
            'indicators' => ['diplomatic_statement', 'sanctions', 'negotiations', 'political_pressure']
        ],
        'economic' => [
            'id' => 'economic',
            'name_ar' => 'الطبقة الاقتصادية واللوجستية',
            'keywords' => ['عقوبات مالية', 'نفط', 'غاز', 'موانئ', 'إمداد', 'تجارة', 'شحن', 'طيران', 'وقود', 'سلاسل توريد', 'صرف', 'أسواق', 'تحويلات', 'تصدير', 'استيراد'],
            'weight' => 0.85,
            'indicators' => ['economic_sanctions', 'supply_chain', 'energy_market', 'financial_pressure']
        ],
        'social' => [
            'id' => 'social',
            'name_ar' => 'الطبقة الاجتماعية والنفسية',
            'keywords' => ['احتجاج', 'فوضى', 'تحريض', 'تضليل', 'دعاية', 'حرب نفسية', 'إشاعة', 'ذعر', 'تأثير', 'رأي عام', 'تعبئة', 'شرعنة', 'معنويات', 'مشاعر', 'خطاب رمزي'],
            'weight' => 0.7,
            'indicators' => ['social_unrest', 'psychological_ops', 'public_opinion', 'mobilization']
        ],
        'energy' => [
            'id' => 'energy',
            'name_ar' => 'طبقة الطاقة والمرافق الاستراتيجية',
            'keywords' => ['نفط', 'غاز', 'مصفاة', 'كهرباء', 'محطة توليد', 'شبكات مياه', 'سدود', 'مرافئ', 'مطارات', 'جسور', 'اتصالات', 'مراكز تحكم', 'كابلات', 'خطوط أنابيب', 'مرافق حيوية'],
            'weight' => 0.95,
            'indicators' => ['energy_infrastructure', 'critical_facilities', 'utility_disruption']
        ],
        'strategic_passages' => [
            'id' => 'strategic_passages',
            'name_ar' => 'طبقة المضائق والممرات الاستراتيجية',
            'keywords' => ['مضيق هرمز', 'باب المندب', 'قناة السويس', 'ممرات بحرية', 'طرق تجارية', 'موانئ رئيسية', 'نفوذ بحري', 'قواعد متقدمة', 'مجال جوي', 'نقاط اختناق', 'عبور طاقة'],
            'weight' => 1.0,
            'indicators' => ['chokepoint_activity', 'maritime_traffic', 'strategic_positioning']
        ]
    ];
}

// ==========================================================================
// 3. قاموس أنواع OSINT - OSINT Types Dictionary
// ==========================================================================

function sod_get_osint_types() {
    return [
        'military_event' => 'حدث عسكري',
        'security_operation' => 'عملية أمنية',
        'cyber_incident' => 'حادث سيبراني',
        'political_statement' => 'تصريح سياسي',
        'economic_development' => 'تطور اقتصادي',
        'social_movement' => 'حركة اجتماعية',
        'media_campaign' => 'حملة إعلامية',
        'infrastructure_target' => 'استهداف بنية تحتية',
        'strategic_movement' => 'تحرك استراتيجي',
        'diplomatic_action' => 'إجراء دبلوماسي',
        'intelligence_leak' => 'تسريب استخباراتي',
        'verification_report' => 'تقرير تحقق',
        'early_warning' => 'إنذار مبكر',
        'pattern_analysis' => 'تحليل نمط',
        'actor_profiling' => 'profil فاعل',
        'geospatial_intel' => 'استخبارات جغرافية',
        'signals_intel' => 'استخبارات إشارات',
        'human_intel' => 'استخبارات بشرية',
        'open_source_report' => 'تقرير مصدر مفتوح'
    ];
}

// ==========================================================================
// 4. محرك تصنيف طبقات الحرب المركبة
// ==========================================================================

function sod_classify_hybrid_layers($title, $content = '') {
    $text = so_clean_text($title . ' ' . $content);
    $lower = mb_strtolower($text);
    $layers = sod_get_hybrid_warfare_layers();
    $active_layers = [];
    $layer_scores = [];
    
    foreach ($layers as $layer_id => $layer_info) {
        $score = 0;
        $matched_keywords = [];
        
        foreach ($layer_info['keywords'] as $keyword) {
            if (mb_stripos($lower, mb_strtolower($keyword)) !== false) {
                $score += 10;
                $matched_keywords[] = $keyword;
            }
        }
        
        // فحص إضافي للسياق
        if ($layer_id === 'military' && preg_match('/(غارة|قصف|استهداف|هجوم|اشتباك)/ui', $lower)) {
            $score += 20;
        }
        
        if ($layer_id === 'cyber' && preg_match('/(اختراق|تسريب|تعطيل|سيبراني)/ui', $lower)) {
            $score += 20;
        }
        
        if ($layer_id === 'economic' && preg_match('/(عقوبات|نفط|غاز|موانئ|أسواق)/ui', $lower)) {
            $score += 15;
        }
        
        if ($score > 20) {
            $active_layers[] = $layer_id;
            $layer_scores[$layer_id] = min(100, $score);
        }
    }
    
    return [
        'active_layers' => $active_layers,
        'layer_scores' => $layer_scores,
        'multi_domain' => count($active_layers) > 1,
        'domain_count' => count($active_layers),
        'primary_layer' => !empty($active_layers) ? reset($active_layers) : 'general',
        'hybrid_score' => count($active_layers) > 1 ? (count($active_layers) * 15) + array_sum($layer_scores) / count($active_layers) : 0
    ];
}

// ==========================================================================
// 5. محرك حساب Scores المتقدم
// ==========================================================================

function sod_calculate_advanced_scores($event_data) {
    $title = $event_data['title'] ?? '';
    $actor = $event_data['actor_v2'] ?? '';
    $region = $event_data['region'] ?? '';
    $base_score = $event_data['score'] ?? 0;
    
    $text = so_clean_text($title);
    $lower = mb_strtolower($text);
    
    // حساب Sentiment Score
    $sentiment_positive = preg_match_all('/(انتصار|نجاح|إنجاز|تحقيق|سيطرة|تفوق|إنجاز)/ui', $lower);
    $sentiment_negative = preg_match_all('/(خسارة|فشل|ضحية|مجزرة|تدمير|كارثة|انهيار)/ui', $lower);
    $sentiment_neutral = preg_match_all('/(أعلن|أكد|قال|صرح|ذكر|أفاد)/ui', $lower);
    
    $sentiment_score = ($sentiment_positive * 10) - ($sentiment_negative * 15);
    $sentiment_score = max(-100, min(100, $sentiment_score));
    
    // حساب Threat Score
    $threat_indicators = [
        '/(اغتيال|تصفية|ت ликвидация)/ui' => 40,
        '/(غارة|قصف|استهداف)/ui' => 30,
        '/(صواريخ|بالستي|كروز)/ui' => 25,
        '/(منشأة نووية|مفاعل|طاقة ذرية)/ui' => 50,
        '/(مضيق|ممر استراتيجي|قاعدة)/ui' => 20,
        '/(تصعيد|رد|انتقام)/ui' => 15,
        '/(تحشيد|تعزيزات|استنفار)/ui' => 20,
        '/(تهديد|وعيد|إنذار)/ui' => 10,
    ];
    
    $threat_score = 0;
    foreach ($threat_indicators as $pattern => $weight) {
        if (preg_match($pattern, $lower)) {
            $threat_score += $weight;
        }
    }
    
    // تعديل حسب الفاعل
    $high_threat_actors = ['إسرائيل', 'الولايات المتحدة', 'إيران', 'حزب الله', 'حماس', 'الحوثيين'];
    foreach ($high_threat_actors as $ht_actor) {
        if (mb_stripos($actor, $ht_actor) !== false) {
            $threat_score += 10;
        }
    }
    
    $threat_score = min(100, $threat_score);
    
    // حساب Escalation Score
    $escalation_keywords = ['رد', 'انتقام', 'ثأر', 'تصعيد', 'رد فعل', 'مقابل', 'سلسلة', 'جولة جديدة', 'موجة'];
    $escalation_count = 0;
    foreach ($escalation_keywords as $kw) {
        if (mb_stripos($lower, $kw) !== false) {
            $escalation_count++;
        }
    }
    
    $escalation_score = min(100, $escalation_count * 20 + ($threat_score > 50 ? 15 : 0));
    
    // حساب Confidence Score
    $confidence_factors = 0;
    if (!empty($event_data['verified_sources_count']) && $event_data['verified_sources_count'] > 0) {
        $confidence_factors += min(40, $event_data['verified_sources_count'] * 10);
    }
    if (!empty($event_data['has_visual_evidence'])) {
        $confidence_factors += 20;
    }
    if (!empty($event_data['has_satellite_imagery'])) {
        $confidence_factors += 15;
    }
    if (!empty($event_data['has_official_statement'])) {
        $confidence_factors += 15;
    }
    if (empty($event_data['source_conflict'])) {
        $confidence_factors += 10;
    }
    
    $confidence_score = min(100, $confidence_factors);
    
    // تحديد Risk Level
    $composite_risk = ($threat_score * 0.4) + ($escalation_score * 0.3) + (($base_score / 220) * 100 * 0.3);
    if ($composite_risk >= 70) {
        $risk_level = 'حرج';
    } elseif ($composite_risk >= 50) {
        $risk_level = 'مرتفع';
    } elseif ($composite_risk >= 30) {
        $risk_level = 'متوسط';
    } else {
        $risk_level = 'منخفض';
    }
    
    // تحديد Urgency Level
    if ($threat_score >= 70 || $escalation_score >= 60) {
        $urgency_level = 'عاجل جداً';
    } elseif ($threat_score >= 50 || $escalation_score >= 40) {
        $urgency_level = 'عاجل';
    } elseif ($threat_score >= 30) {
        $urgency_level = 'مهم';
    } else {
        $urgency_level = 'عادي';
    }
    
    return [
        'sentiment_score' => round($sentiment_score, 2),
        'threat_score' => $threat_score,
        'escalation_score' => $escalation_score,
        'confidence_score' => $confidence_score,
        'risk_level' => $risk_level,
        'urgency_level' => $urgency_level,
        'stability_index' => round(100 - $threat_score, 2),
        'aggression_index' => round(($threat_score + $escalation_score) / 2, 2),
        'impact_radius' => $region === 'غير محدد' ? 'محلي' : (in_array($region, ['إيران', 'إسرائيل', 'الولايات المتحدة']) ? 'إقليمي' : 'محلي'),
    ];
}

// ==========================================================================
// 6. محرك استخراج شبكة الفاعلين
// ==========================================================================

function sod_extract_actor_network($title, $content = '') {
    $text = so_clean_text($title . ' ' . $content);
    $network = [
        'primary_executor' => '',
        'sponsor' => '',
        'funder' => '',
        'media_operator' => '',
        'political_cover' => '',
        'logistics_support' => '',
        'relationships' => []
    ];
    
    // أنماط استخراج العلاقات
    $patterns = [
        'executor' => '/(نفذت|نفذ|قام بـ|أعلنت مسؤوليتها|استهدفت|قصفَت)/ui',
        'sponsor' => '/(برعاية|بدعم من|بتوجيه من|بتنسيق مع|بالإسناد إلى)/ui',
        'funder' => '/(بتمويل من|موّل|قدّم الدعم المالي)/ui',
        'media' => '/(نقلت|أعلنت عبر|صرّح لـ|في مقابلة مع)/ui',
        'political' => '/(غطاء سياسي|دعم سياسي|مباركة من|تأييد)/ui',
    ];
    
    // استخراج الكيانات المذكورة
    $entities = [];
    if (preg_match_all('/([\p{Arabic}A-Za-z]+(?:\s+[\p{Arabic}A-Za-z]+){0,5})/u', $text, $matches)) {
        $entities = array_unique($matches[1]);
    }
    
    // تحليل السياق لتحديد الأدوار
    foreach ($patterns as $role => $pattern) {
        if (preg_match($pattern, $text, $match)) {
            // محاولة استخراج الكيان المرتبط
            $context_start = strpos($text, $match[0]);
            if ($context_start !== false) {
                $context = substr($text, $context_start, 100);
                foreach ($entities as $entity) {
                    if (mb_stripos($context, $entity) !== false && mb_strlen($entity) > 3) {
                        $network['relationships'][] = [
                            'entity' => $entity,
                            'role' => $role,
                            'confidence' => 0.7
                        ];
                    }
                }
            }
        }
    }
    
    return $network;
}

// ==========================================================================
// 7. محرك التحقق المتقدم
// ==========================================================================

function sod_advanced_verification($event_data) {
    $title = $event_data['title'] ?? '';
    $source = $event_data['source_name'] ?? '';
    $content = $event_data['war_data'] ?? '';
    
    $verification = [
        'status' => 'غير مؤكد',
        'sources_count' => 0,
        'visual_evidence' => false,
        'satellite_imagery' => false,
        'official_statement' => false,
        'source_conflict' => false,
        'confidence' => 0,
        'notes' => []
    ];
    
    // فحص مصادر موثوقة
    $trusted_sources = ['رويترز', 'أسوشيتد برس', 'فرانس برس', 'الأناضول', 'الجزيرة', 'العربية', 'Sky News', 'BBC', 'CNN'];
    foreach ($trusted_sources as $ts) {
        if (mb_stripos($source, $ts) !== false || mb_stripos($content, $ts) !== false) {
            $verification['sources_count']++;
            $verification['notes'][] = "مصدر موثوق: {$ts}";
        }
    }
    
    // فحص الأدلة البصرية
    if (preg_match('/(فيديو|صورة|تصوير|لقطة|مشهد|نشر فيديو|أظهرت صور)/ui', $content)) {
        $verification['visual_evidence'] = true;
        $verification['notes'][] = 'وجود أدلة بصرية';
    }
    
    // فحص الصور الفضائية
    if (preg_match('/(قمر صناعي|صورة فضائية|Maxar|Planet|أقمار صناعية)/ui', $content)) {
        $verification['satellite_imagery'] = true;
        $verification['notes'][] = 'وجود صور أقمار صناعية';
    }
    
    // فحص البيانات الرسمية
    if (preg_match('/(بيان رسمي|متحدث رسمي|أعلنت الحكومة|الجيش أكد|وزارة أعلنت)/ui', $content)) {
        $verification['official_statement'] = true;
        $verification['notes'][] = 'وجود بيان رسمي';
    }
    
    // فحص التضارب
    if (preg_match('/(تضارب|روايات متضاربة|غير مؤكد|لم يتم تأكيد|نفى|تكذيب)/ui', $content)) {
        $verification['source_conflict'] = true;
        $verification['notes'][] = 'وجود تضارب في الروايات';
    }
    
    // حساب درجة الثقة
    $confidence = 0;
    if ($verification['sources_count'] > 0) {
        $confidence += min(40, $verification['sources_count'] * 15);
    }
    if ($verification['visual_evidence']) {
        $confidence += 20;
    }
    if ($verification['satellite_imagery']) {
        $confidence += 15;
    }
    if ($verification['official_statement']) {
        $confidence += 15;
    }
    if ($verification['source_conflict']) {
        $confidence -= 20;
    }
    
    $confidence = max(0, min(100, $confidence));
    $verification['confidence'] = $confidence;
    
    // تحديد الحالة النهائية
    if ($confidence >= 80) {
        $verification['status'] = 'مؤكد';
    } elseif ($confidence >= 60) {
        $verification['status'] = 'محتمل';
    } elseif ($confidence >= 40) {
        $verification['status'] = 'تقارير أولية';
    } elseif ($verification['source_conflict']) {
        $verification['status'] = 'متضارب';
    } else {
        $verification['status'] = 'غير مؤكد';
    }
    
    return $verification;
}

// ==========================================================================
// 8. دمج التحليل المتقدم في معالجة الأحداث
// ==========================================================================

function sod_enhance_event_with_hybrid_analysis($event_data) {
    // تطبيق تصنيف طبقات الحرب المركبة
    $hybrid_analysis = sod_classify_hybrid_layers(
        $event_data['title'] ?? '',
        $event_data['war_data'] ?? ''
    );
    
    // حساب Scores المتقدم
    $advanced_scores = sod_calculate_advanced_scores($event_data);
    
    // استخراج شبكة الفاعلين
    $actor_network = sod_extract_actor_network(
        $event_data['title'] ?? '',
        $event_data['war_data'] ?? ''
    );
    
    // التحقق المتقدم
    $verification = sod_advanced_verification($event_data);
    
    // دمج النتائج
    $enhanced_data = array_merge($event_data, [
        // طبقات الحرب المركبة
        'hybrid_layers' => json_encode($hybrid_analysis, JSON_UNESCAPED_UNICODE),
        'osint_type' => $hybrid_analysis['primary_layer'] . '_event',
        'multi_domain_score' => $hybrid_analysis['hybrid_score'],
        
        // Scores المتقدم
        'sentiment_score' => $advanced_scores['sentiment_score'],
        'threat_score' => $advanced_scores['threat_score'],
        'escalation_score' => $advanced_scores['escalation_score'],
        'confidence_score' => $advanced_scores['confidence_score'],
        'risk_level' => $advanced_scores['risk_level'],
        'urgency_level' => $advanced_scores['urgency_level'],
        'stability_index' => $advanced_scores['stability_index'],
        'aggression_index' => $advanced_scores['aggression_index'],
        'impact_radius' => $advanced_scores['impact_radius'],
        
        // شبكة الفاعلين
        'actor_network' => json_encode($actor_network, JSON_UNESCAPED_UNICODE),
        
        // التحقق
        'verification_status' => $verification['status'],
        'verified_sources_count' => $verification['sources_count'],
        'has_visual_evidence' => $verification['visual_evidence'] ? 1 : 0,
        'has_satellite_imagery' => $verification['satellite_imagery'] ? 1 : 0,
        'has_official_statement' => $verification['official_statement'] ? 1 : 0,
        'source_conflict' => $verification['source_conflict'] ? 1 : 0,
        'verification_notes' => implode(' | ', $verification['notes']),
        
        // الإنذار
        'alert_flag' => ($advanced_scores['threat_score'] >= 60 || $advanced_scores['escalation_score'] >= 50) ? 1 : 0,
        'alert_priority' => $advanced_scores['urgency_level'],
    ]);
    
    return $enhanced_data;
}

// ==========================================================================
// 9. Hook لتفعيل التحديثات عند تنشيط الإضافة
// ==========================================================================

function sod_activate_hybrid_warfare_update() {
    so_log("بدء تفعيل تحديث الحرب المركبة...", 'ACTIVATION');
    sod_hybrid_warfare_add_advanced_fields();
    so_log("اكتمل تفعيل تحديث الحرب المركبة", 'ACTIVATION');
}

/**
 * تهيئة Hooks نظام الحرب المركبة - تُستدعى فقط داخل ووردبريس
 */
function sod_init_hybrid_warfare_hooks() {
    // تشغيل التحديث إذا لزم الأمر
    add_action('admin_init', function() {
        $current_version = get_option('sod_hybrid_warfare_fields_version', '0.0.0');
        if (version_compare($current_version, '2.0.0', '<')) {
            sod_activate_hybrid_warfare_update();
        }
    });

    // ==========================================================================
    // 10. واجهة عرض في لوحة التحكم
    // ==========================================================================

    function sod_add_hybrid_warfare_dashboard_widget() {
        wp_add_dashboard_widget(
            'sod_hybrid_warfare_widget',
            '🔍 مؤشر الحرب المركبة',
            'sod_render_hybrid_warfare_widget'
        );
    }

    function sod_render_hybrid_warfare_widget() {
        global $wpdb;
        $table = $wpdb->prefix . 'so_news_events';
        
        // إحصائيات سريعة
        $total_events = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE event_timestamp >= " . (time() - 86400));
        $high_threat = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE threat_score >= 60 AND event_timestamp >= " . (time() - 86400));
        $multi_domain = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE hybrid_layers IS NOT NULL AND event_timestamp >= " . (time() - 86400));
        $alerts = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE alert_flag = 1 AND event_timestamp >= " . (time() - 86400));
        
        echo '<div style="padding: 10px;">';
        echo '<h4>📊 ملخص آخر 24 ساعة</h4>';
        echo '<ul>';
        echo "<li><strong>إجمالي الأحداث:</strong> {$total_events}</li>";
        echo "<li><strong>أحداث عالية التهديد:</strong> <span style='color: red;'>{$high_threat}</span></li>";
        echo "<li><strong>أحداث متعددة المجالات:</strong> {$multi_domain}</li>";
        echo "<li><strong>تنبيهات نشطة:</strong> <span style='color: orange;'>{$alerts}</span></li>";
        echo '</ul>';
        echo '<p><small>نظام OSINT المتقدم للرصد والتحليل</small></p>';
        echo '</div>';
    }

    add_action('wp_dashboard_setup', 'sod_add_hybrid_warfare_dashboard_widget');
}

// استدعاء hooks فقط إذا كان ABSPATH معرفاً (أي داخل ووردبريس)
if (defined('ABSPATH')) {
    sod_init_hybrid_warfare_hooks();
}

// ==========================================================================
// سجل التغييرات
// ==========================================================================

/*
 * الإصدار 1.0.0:
 * - إضافة 60+ حقل OSINT متقدم
 * - تطبيق منطق الحرب المركبة ذو الطبقات التسع
 * - محرك تصنيف طبقات الهجين
 * - حساب Scores المتقدم (Threat, Escalation, Confidence, Sentiment)
 * - استخراج شبكة الفاعلين
 * - نظام تحقق متعدد المستويات
 * - مؤشرات إنذار مبكر
 * - تكامل مع قاعدة البيانات الحالية
 */