<?php
/**
 * Beiruttime OSINT - Intelligence Pipeline System
 * نظام معالجة الأخبار الاستخباراتي المتكامل
 * 
 * المراحل:
 * 1. Collection: استقبال الخبر
 * 2. Registration: توثيق الوقت والمصدر (سلسلة أدلة)
 * 3. Reliability Assessment: تقييم المصدر (نظام NATO A-F / 1-6)
 * 4. Triage: فرز العاجل من الروتيني
 * 5. Validation: كشف التكرار والتضليل
 * 6. Structuring: استخراج (من، ماذا، أين، متى، لماذا، كيف)
 * 7. Fusion: دمج مع البيانات السابقة (All Source Fusion)
 * 8. Analysis: تحليل الأنماط والروابط
 * 9. Assessment: تقدير التهديد ونسبة الثقة
 * 10. Product Generation: إنتاج (Flash Alert, SITREP)
 * 11. Dissemination: التوزيع حسب الصلاحيات
 * 12. Feedback: حلقة تغذية راجعة للتحسين
 * 
 * @version 1.0.0
 * @package BeiruttimeOSINT
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sod_Intelligence_Pipeline {

    /**
     * سجل المعالجة الحالية
     */
    private static $processing_log = [];

    /**
     * قاعدة بيانات المصادر المقيمة
     */
    private static $source_reliability_db = [];

    /**
     * الأخبار المعالجة
     */
    private static $processed_news = [];

    /**
     * التنبيهات العاجلة
     */
    private static $flash_alerts = [];

    /**
     * تقارير الحالة
     */
    private static $sitreps = [];

    /**
     * سلسلة الأدلة (Chain of Custody)
     */
    private static $chain_of_custody = [];

    /**
     * تصنيف NATO لموثوقية المصادر
     * A-F: موثوقية المصدر
     * 1-6: مصداقية المعلومة
     */
    private static $nato_source_codes = [
        'A' => ['label' => 'مصدر لا تشوبه شائبة', 'reliability' => 100],
        'B' => ['label' => 'مصدر موثوق عادةً', 'reliability' => 80],
        'C' => ['label' => 'مصدر مقبول إلى حد ما', 'reliability' => 60],
        'D' => ['label' => 'مصدر غير موثوق عادةً', 'reliability' => 40],
        'E' => ['label' => 'مcriptor غير موثوق', 'reliability' => 20],
        'F' => ['label' => 'لا يمكن تقييم الموثوقية', 'reliability' => 0]
    ];

    private static $nato_credibility_codes = [
        '1' => ['label' => 'مؤكد من مصادر أخرى', 'credibility' => 100],
        '2' => ['label' => 'مرجح جداً', 'credibility' => 80],
        '3' => ['label' => 'مرجح', 'credibility' => 60],
        '4' => ['label' => 'غير مؤكد', 'credibility' => 40],
        '5' => ['label' => 'غير مرجح', 'credibility' => 20],
        '6' => ['label' => 'لا يمكن تقييم المصداقية', 'credibility' => 0]
    ];

    /**
     * المرحلة 1: Collection - استقبال الخبر
     */
    public static function collect(array $raw_intel): array {
        $collection_id = self::generate_uuid();
        $timestamp = current_time('mysql', true);

        $collected = [
            'collection_id' => $collection_id,
            'received_at' => $timestamp,
            'raw_content' => $raw_intel['content'] ?? '',
            'source_id' => $raw_intel['source_id'] ?? 'unknown',
            'source_name' => $raw_intel['source_name'] ?? 'Unknown',
            'channel' => $raw_intel['channel'] ?? 'unspecified', // Telegram, RSS, API, Manual
            'priority_flag' => $raw_intel['priority_flag'] ?? 'routine',
            'metadata' => $raw_intel['metadata'] ?? []
        ];

        self::$processing_log[] = [
            'stage' => 'COLLECTION',
            'id' => $collection_id,
            'timestamp' => $timestamp,
            'status' => 'COMPLETED'
        ];

        return $collected;
    }

    /**
     * المرحلة 2: Registration - توثيق الوقت والمصدر (سلسلة أدلة)
     */
    public static function register(array $collected_intel): array {
        $registration_id = self::generate_uuid();
        $timestamp = current_time('mysql', true);

        // إنشاء سلسلة الأدلة
        $chain_entry = [
            'registration_id' => $registration_id,
            'collection_id' => $collected_intel['collection_id'],
            'registered_at' => $timestamp,
            'custodian' => get_current_user_id() ?: 'system',
            'hash' => hash('sha256', json_encode($collected_intel)),
            'integrity_verified' => true,
            'audit_trail' => [
                'received' => $collected_intel['received_at'],
                'registered' => $timestamp,
                'processing_started' => $timestamp
            ]
        ];

        self::$chain_of_custody[$registration_id] = $chain_entry;

        $registered = array_merge($collected_intel, [
            'registration_id' => $registration_id,
            'chain_of_custody' => $chain_entry
        ]);

        self::$processing_log[] = [
            'stage' => 'REGISTRATION',
            'id' => $registration_id,
            'timestamp' => $timestamp,
            'status' => 'COMPLETED',
            'hash' => $chain_entry['hash']
        ];

        return $registered;
    }

    /**
     * المرحلة 3: Reliability Assessment - تقييم المصدر (نظام NATO)
     */
    public static function assess_reliability(array $registered_intel): array {
        $source_id = $registered_intel['source_id'];
        $source_name = $registered_intel['source_name'];

        // التحقق من قاعدة بيانات المصادر
        if (!isset(self::$source_reliability_db[$source_id])) {
            // تقييم أولي للمصدر
            self::$source_reliability_db[$source_id] = self::evaluate_source($source_name, $registered_intel);
        }

        $source_eval = self::$source_reliability_db[$source_id];

        $assessed = array_merge($registered_intel, [
            'source_reliability' => [
                'nato_code' => $source_eval['nato_code'],
                'nato_label' => self::$nato_source_codes[$source_eval['nato_code']]['label'],
                'reliability_score' => self::$nato_source_codes[$source_eval['nato_code']]['reliability'],
                'credibility_code' => $source_eval['credibility_code'],
                'credibility_label' => self::$nato_credibility_codes[$source_eval['credibility_code']]['label'],
                'credibility_score' => self::$nato_credibility_codes[$source_eval['credibility_code']]['credibility'],
                'combined_score' => round(($source_eval['reliability'] + $source_eval['credibility']) / 2),
                'assessment_timestamp' => current_time('mysql', true)
            ]
        ]);

        self::$processing_log[] = [
            'stage' => 'RELIABILITY_ASSESSMENT',
            'id' => $registered_intel['registration_id'],
            'timestamp' => current_time('mysql', true),
            'status' => 'COMPLETED',
            'source_rating' => $source_eval['nato_code'] . '-' . $source_eval['credibility_code']
        ];

        return $assessed;
    }

    /**
     * تقييم مصدر جديد
     */
    private static function evaluate_source(string $source_name, array $intel): array {
        // قواعد تقييم مبسطة - يمكن توسيعها
        $trusted_sources = ['reuters', 'ap', 'afp', 'official_government', 'verified_agency'];
        $moderate_sources = ['local_news', 'regional_media', 'stringer'];
        $untrusted_sources = ['anonymous', 'social_media_unverified', 'known_fake'];

        $source_lower = strtolower($source_name);

        // تحديد كود NATO للمصدر
        $nato_code = 'C'; // افتراضي
        foreach ($trusted_sources as $ts) {
            if (strpos($source_lower, $ts) !== false) {
                $nato_code = 'A';
                break;
            }
        }
        if ($nato_code === 'C') {
            foreach ($moderate_sources as $ms) {
                if (strpos($source_lower, $ms) !== false) {
                    $nato_code = 'B';
                    break;
                }
            }
        }
        foreach ($untrusted_sources as $us) {
            if (strpos($source_lower, $us) !== false) {
                $nato_code = 'E';
                break;
            }
        }

        // تحديد كود المصداقية بناءً على محتوى الخبر
        $credibility_code = '3'; // افتراضي
        $content = $intel['raw_content'] ?? '';
        
        if (preg_match('/(أكد|ثبت|وثق|confirmed|verified)/ui', $content)) {
            $credibility_code = '2';
        } elseif (preg_match('/(يُزعم|يُقال|قد|allegedly|reportedly)/ui', $content)) {
            $credibility_code = '4';
        } elseif (preg_match('/(إشاعة|غير مؤكد|unconfirmed|rumor)/ui', $content)) {
            $credibility_code = '5';
        }

        return [
            'nato_code' => $nato_code,
            'credibility_code' => $credibility_code,
            'reliability' => self::$nato_source_codes[$nato_code]['reliability'],
            'credibility' => self::$nato_credibility_codes[$credibility_code]['credibility']
        ];
    }

    /**
     * المرحلة 4: Triage - فرز العاجل من الروتيني
     */
    public static function triage(array $assessed_intel): array {
        $content = $assessed_intel['raw_content'];
        $priority = 'ROUTINE';
        $urgency_score = 0;

        // كلمات دلالية للأولوية العالية
        $critical_keywords = [
            'عاجل', 'عاجل جداً', 'breaking', 'urgent', 'alert',
            'غارة', 'قصف', 'اغتيال', 'انفجار', 'اشتباك',
            'إطلاق نار', 'هجوم', 'كارثة', 'ضحايا', 'قتلى',
            'تهديد مباشر', 'خطر وشيك', 'تحذير أمني'
        ];

        foreach ($critical_keywords as $keyword) {
            if (stripos($content, $keyword) !== false) {
                $urgency_score += 10;
            }
        }

        // تحديد الأولوية
        if ($urgency_score >= 30 || $assessed_intel['priority_flag'] === 'critical') {
            $priority = 'FLASH';
            $sla_minutes = 5; // يجب المعالجة خلال 5 دقائق
        } elseif ($urgency_score >= 15) {
            $priority = 'PRIORITY';
            $sla_minutes = 30;
        } elseif ($urgency_score >= 5) {
            $priority = 'IMMEDIATE';
            $sla_minutes = 60;
        } else {
            $priority = 'ROUTINE';
            $sla_minutes = 240;
        }

        $triaged = array_merge($assessed_intel, [
            'triage' => [
                'priority' => $priority,
                'urgency_score' => $urgency_score,
                'sla_minutes' => $sla_minutes,
                'triage_timestamp' => current_time('mysql', true),
                'requires_immediate_action' => in_array($priority, ['FLASH', 'PRIORITY'])
            ]
        ]);

        self::$processing_log[] = [
            'stage' => 'TRIAGE',
            'id' => $assessed_intel['registration_id'],
            'timestamp' => current_time('mysql', true),
            'status' => 'COMPLETED',
            'priority' => $priority
        ];

        return $triaged;
    }

    /**
     * المرحلة 5: Validation - كشف التكرار والتضليل
     */
    public static function validate(array $triaged_intel): array {
        $content_hash = hash('md5', $triaged_intel['raw_content']);
        $is_duplicate = false;
        $duplicate_of = null;
        $disinformation_flags = [];

        // كشف التكرار
        foreach (self::$processed_news as $processed) {
            if (hash('md5', $processed['raw_content']) === $content_hash) {
                $is_duplicate = true;
                $duplicate_of = $processed['registration_id'];
                break;
            }
        }

        // كشف مؤشرات التضليل
        $disinformation_patterns = [
            'source_mismatch' => preg_match('/^(匿名|unknown source|مصدر مجهول)/ui', $triaged_intel['source_name']),
            'sensational_language' => preg_match('/(حصري|كارثة|mind-blowing|shocking|لأول مرة)/ui', $triaged_intel['raw_content']),
            'no_corroboration' => $triaged_intel['source_reliability']['combined_score'] < 40,
            'contradicts_known_facts' => false // يحتاج لقاعدة معرفة
        ];

        foreach ($disinformation_patterns as $flag => $detected) {
            if ($detected) {
                $disinformation_flags[] = $flag;
            }
        }

        $validation_status = 'VALID';
        if ($is_duplicate) {
            $validation_status = 'DUPLICATE';
        } elseif (count($disinformation_flags) >= 2) {
            $validation_status = 'SUSPICIOUS';
        }

        $validated = array_merge($triaged_intel, [
            'validation' => [
                'status' => $validation_status,
                'is_duplicate' => $is_duplicate,
                'duplicate_of' => $duplicate_of,
                'content_hash' => $content_hash,
                'disinformation_flags' => $disinformation_flags,
                'validation_timestamp' => current_time('mysql', true)
            ]
        ]);

        self::$processing_log[] = [
            'stage' => 'VALIDATION',
            'id' => $triaged_intel['registration_id'],
            'timestamp' => current_time('mysql', true),
            'status' => $validation_status,
            'flags' => count($disinformation_flags)
        ];

        return $validated;
    }

    /**
     * المرحلة 6: Structuring - استخراج 5W1H
     */
    public static function structure(array $validated_intel): array {
        // استخدام Validator الموجود
        if (class_exists('Sod_5W1H_Validator')) {
            $validation_result = Sod_5W1H_Validator::validate_before_publish([
                'content' => $validated_intel['raw_content'],
                'title' => $validated_intel['source_name']
            ]);

            $structured_data = $validation_result['extracted_elements'] ?? [];
            $structure_score = $validation_result['score'] ?? 0;
        } else {
            // Fallback بسيط
            $structured_data = self::basic_extract_5w1h($validated_intel['raw_content']);
            $structure_score = self::calculate_structure_score($structured_data);
        }

        $structured = array_merge($validated_intel, [
            'structure' => [
                'elements' => $structured_data,
                'completeness_score' => $structure_score,
                'extraction_method' => class_exists('Sod_5W1H_Validator') ? 'advanced' : 'basic',
                'structure_timestamp' => current_time('mysql', true)
            ]
        ]);

        self::$processing_log[] = [
            'stage' => 'STRUCTURING',
            'id' => $validated_intel['registration_id'],
            'timestamp' => current_time('mysql', true),
            'status' => 'COMPLETED',
            'completeness' => $structure_score
        ];

        return $structured;
    }

    /**
     * استخراج أساسي لـ 5W1H (Fallback)
     */
    private static function basic_extract_5w1h(string $content): array {
        return [
            'who' => self::extract_pattern($content, '/(رئيس|وزير|قائد|مصدر|مسؤول|قوات|جيش)/ui'),
            'what' => self::extract_pattern($content, '/(غارة|قصف|هجوم|اجتماع|إعلان|قرار)/ui'),
            'where' => self::extract_pattern($content, '/في\s+\w+|على\s+\w+|في\s+\w+\s+\w+/ui'),
            'when' => self::extract_pattern($content, '/\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{1,2}:\d{2}|اليوم|الأمس/ui'),
            'why' => self::extract_pattern($content, '/(رداً على|بسبب|نتيجة|لأجل)/ui'),
            'how' => self::extract_pattern($content, '/(باستخدام|عن طريق|عبر|بواسطة)/ui')
        ];
    }

    private static function extract_pattern(string $content, string $pattern): ?string {
        if (preg_match($pattern, $content, $matches)) {
            return trim($matches[0]);
        }
        return null;
    }

    private static function calculate_structure_score(array $elements): int {
        $filled = 0;
        foreach ($elements as $value) {
            if (!empty($value)) $filled++;
        }
        return round(($filled / 6) * 100);
    }

    /**
     * المرحلة 7: Fusion - دمج مع البيانات السابقة (All Source Fusion)
     */
    public static function fuse(array $structured_intel): array {
        $fused_data = [
            'current_intel' => $structured_intel,
            'related_intel' => [],
            'fusion_analysis' => [],
            'confidence_boost' => 0
        ];

        // البحث عن أخبار ذات صلة
        $current_who = $structured_intel['structure']['elements']['who'] ?? null;
        $current_where = $structured_intel['structure']['elements']['where'] ?? null;

        foreach (self::$processed_news as $processed) {
            $processed_who = $processed['structure']['elements']['who'] ?? null;
            $processed_where = $processed['structure']['elements']['where'] ?? null;

            $relevance_score = 0;
            if ($current_who && $processed_who && stripos($current_who, $processed_who) !== false) {
                $relevance_score += 50;
            }
            if ($current_where && $processed_where && stripos($current_where, $processed_where) !== false) {
                $relevance_score += 30;
            }

            if ($relevance_score >= 50) {
                $fused_data['related_intel'][] = [
                    'registration_id' => $processed['registration_id'],
                    'relevance_score' => $relevance_score,
                    'summary' => substr($processed['raw_content'], 0, 100)
                ];
            }
        }

        // تحليل الدمج
        if (count($fused_data['related_intel']) > 0) {
            $fused_data['fusion_analysis'] = [
                'corroboration' => count($fused_data['related_intel']) >= 2,
                'pattern_detected' => count($fused_data['related_intel']) >= 3,
                'escalation_indicator' => false // يحتاج تحليل متقدم
            ];
            $fused_data['confidence_boost'] = min(count($fused_data['related_intel']) * 10, 30);
        }

        $fused = array_merge($structured_intel, [
            'fusion' => $fused_data,
            'fusion_timestamp' => current_time('mysql', true)
        ]);

        self::$processing_log[] = [
            'stage' => 'FUSION',
            'id' => $structured_intel['registration_id'],
            'timestamp' => current_time('mysql', true),
            'status' => 'COMPLETED',
            'related_count' => count($fused_data['related_intel'])
        ];

        return $fused;
    }

    /**
     * المرحلة 8: Analysis - تحليل الأنماط والروابط
     */
    public static function analyze(array $fused_intel): array {
        $analysis = [
            'patterns' => [],
            'links' => [],
            'anomalies' => [],
            'trend' => 'stable'
        ];

        // تحليل النمط الزمني
        $related_count = count($fused_intel['fusion']['related_intel'] ?? []);
        if ($related_count >= 3) {
            $analysis['patterns'][] = 'escalating_activity';
            $analysis['trend'] = 'escalating';
        }

        // تحليل الروابط
        if (!empty($fused_intel['fusion']['related_intel'])) {
            foreach ($fused_intel['fusion']['related_intel'] as $related) {
                $analysis['links'][] = [
                    'target_id' => $related['registration_id'],
                    'link_type' => 'same_actor_or_location',
                    'strength' => $related['relevance_score']
                ];
            }
        }

        // كشف الشذوذ
        if ($fused_intel['validation']['status'] === 'SUSPICIOUS') {
            $analysis['anomalies'][] = 'potential_disinformation';
        }
        if ($fused_intel['source_reliability']['combined_score'] < 50 && $fused_intel['triage']['priority'] === 'FLASH') {
            $analysis['anomalies'][] = 'high_priority_low_reliability_mismatch';
        }

        $analyzed = array_merge($fused_intel, [
            'analysis' => $analysis,
            'analysis_timestamp' => current_time('mysql', true)
        ]);

        self::$processing_log[] = [
            'stage' => 'ANALYSIS',
            'id' => $fused_intel['registration_id'],
            'timestamp' => current_time('mysql', true),
            'status' => 'COMPLETED',
            'patterns_found' => count($analysis['patterns'])
        ];

        return $analyzed;
    }

    /**
     * المرحلة 9: Assessment - تقدير التهديد ونسبة الثقة
     */
    public static function assess(array $analyzed_intel): array {
        $base_confidence = $analyzed_intel['source_reliability']['combined_score'];
        $structure_bonus = $analyzed_intel['structure']['completeness_score'] * 0.2;
        $fusion_bonus = $analyzed_intel['fusion']['confidence_boost'] ?? 0;
        $validation_penalty = 0;

        if ($analyzed_intel['validation']['status'] === 'SUSPICIOUS') {
            $validation_penalty = 30;
        } elseif ($analyzed_intel['validation']['is_duplicate']) {
            $validation_penalty = 10;
        }

        $final_confidence = max(0, min(100, round($base_confidence + $structure_bonus + $fusion_bonus - $validation_penalty)));

        // تقدير التهديد
        $threat_level = 'LOW';
        $threat_score = 0;

        $threat_keywords = ['تهديد', 'خطر', 'هجوم', 'اغتيال', 'قصف', 'كارثة', 'ضحايا', 'حرب'];
        foreach ($threat_keywords as $keyword) {
            if (stripos($analyzed_intel['raw_content'], $keyword) !== false) {
                $threat_score += 15;
            }
        }

        if ($threat_score >= 60) {
            $threat_level = 'CRITICAL';
        } elseif ($threat_score >= 40) {
            $threat_level = 'HIGH';
        } elseif ($threat_score >= 20) {
            $threat_level = 'MEDIUM';
        }

        $assessed = array_merge($analyzed_intel, [
            'assessment' => [
                'confidence_level' => $final_confidence,
                'confidence_rating' => self::get_confidence_rating($final_confidence),
                'threat_level' => $threat_level,
                'threat_score' => $threat_score,
                'recommendation' => self::get_recommendation($threat_level, $final_confidence),
                'assessment_timestamp' => current_time('mysql', true)
            ]
        ]);

        self::$processing_log[] = [
            'stage' => 'ASSESSMENT',
            'id' => $analyzed_intel['registration_id'],
            'timestamp' => current_time('mysql', true),
            'status' => 'COMPLETED',
            'confidence' => $final_confidence,
            'threat' => $threat_level
        ];

        return $assessed;
    }

    private static function get_confidence_rating(int $score): string {
        if ($score >= 80) return 'HIGH';
        if ($score >= 60) return 'MODERATE';
        if ($score >= 40) return 'LOW';
        return 'VERY_LOW';
    }

    private static function get_recommendation(string $threat_level, int $confidence): string {
        if ($threat_level === 'CRITICAL' && $confidence >= 60) {
            return 'IMMEDIATE_ACTION_REQUIRED';
        } elseif ($threat_level === 'HIGH' && $confidence >= 50) {
            return 'ESCALATE_TO_SUPERVISOR';
        } elseif ($threat_level === 'MEDIUM') {
            return 'CONTINUE_MONITORING';
        }
        return 'FILE_FOR_REFERENCE';
    }

    /**
     * المرحلة 10: Product Generation - إنتاج التقارير
     */
    public static function generate_product(array $assessed_intel): array {
        $product_type = 'STANDARD_REPORT';
        $product_content = '';

        if ($assessed_intel['triage']['priority'] === 'FLASH') {
            $product_type = 'FLASH_ALERT';
            $product_content = self::generate_flash_alert($assessed_intel);
            self::$flash_alerts[] = [
                'alert_id' => self::generate_uuid(),
                'generated_at' => current_time('mysql', true),
                'content' => $product_content,
                'registration_id' => $assessed_intel['registration_id']
            ];
        } else {
            $product_type = 'SITREP';
            $product_content = self::generate_sitrep($assessed_intel);
            self::$sitreps[] = [
                'sitrep_id' => self::generate_uuid(),
                'generated_at' => current_time('mysql', true),
                'content' => $product_content,
                'registration_id' => $assessed_intel['registration_id']
            ];
        }

        $product = array_merge($assessed_intel, [
            'product' => [
                'type' => $product_type,
                'content' => $product_content,
                'generated_at' => current_time('mysql', true)
            ]
        ]);

        self::$processing_log[] = [
            'stage' => 'PRODUCT_GENERATION',
            'id' => $assessed_intel['registration_id'],
            'timestamp' => current_time('mysql', true),
            'status' => 'COMPLETED',
            'product_type' => $product_type
        ];

        return $product;
    }

    private static function generate_flash_alert(array $intel): string {
        $alert = "🚨 FLASH ALERT 🚨\n";
        $alert .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $alert .= "⏰ الوقت: " . $intel['structure']['elements']['when'] ?? 'غير محدد' . "\n";
        $alert .= "📍 الموقع: " . $intel['structure']['elements']['where'] ?? 'غير محدد' . "\n";
        $alert .= "👤 الفاعل: " . $intel['structure']['elements']['who'] ?? 'غير محدد' . "\n";
        $alert .= "📋 الحدث: " . $intel['structure']['elements']['what'] ?? 'غير محدد' . "\n";
        $alert .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $alert .= "🎯 مستوى التهديد: {$intel['assessment']['threat_level']}\n";
        $alert .= "💎 نسبة الثقة: {$intel['assessment']['confidence_level']}% ({$intel['assessment']['confidence_rating']})\n";
        $alert .= "⚡ الأولوية: {$intel['triage']['priority']}\n";
        $alert .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $alert .= "📝 التوصية: {$intel['assessment']['recommendation']}\n";
        $alert .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $alert .= "المصدر: {$intel['source_name']} | التقييم: {$intel['source_reliability']['nato_code']}-{$intel['source_reliability']['credibility_code']}\n";

        return $alert;
    }

    private static function generate_sitrep(array $intel): string {
        $sitrep = "📊 SITUATION REPORT (SITREP)\n";
        $sitrep .= "══════════════════════════\n";
        $sitrep .= "Reference ID: {$intel['registration_id']}\n";
        $sitrep .= "Generated: " . current_time('Y-m-d H:i:s') . "\n";
        $sitrep .= "══════════════════════════\n\n";

        $sitrep .= "1. EXECUTIVE SUMMARY\n";
        $sitrep .= "-------------------\n";
        $sitrep .= substr($intel['raw_content'], 0, 200) . "...\n\n";

        $sitrep .= "2. 5W1H ANALYSIS\n";
        $sitrep .= "---------------\n";
        foreach ($intel['structure']['elements'] as $key => $value) {
            $sitrep .= strtoupper($key) . ": " . ($value ?? 'N/A') . "\n";
        }
        $sitrep .= "\n";

        $sitrep .= "3. SOURCE EVALUATION\n";
        $sitrep .= "-------------------\n";
        $sitrep .= "Source: {$intel['source_name']}\n";
        $sitrep .= "NATO Rating: {$intel['source_reliability']['nato_code']} ({$intel['source_reliability']['nato_label']})\n";
        $sitrep .= "Credibility: {$intel['source_reliability']['credibility_code']} ({$intel['source_reliability']['credibility_label']})\n\n";

        $sitrep .= "4. THREAT ASSESSMENT\n";
        $sitrep .= "-------------------\n";
        $sitrep .= "Threat Level: {$intel['assessment']['threat_level']} (Score: {$intel['assessment']['threat_score']})\n";
        $sitrep .= "Confidence: {$intel['assessment']['confidence_level']}%\n\n";

        if (!empty($intel['fusion']['related_intel'])) {
            $sitrep .= "5. RELATED INTELLIGENCE\n";
            $sitrep .= "----------------------\n";
            $sitrep .= "Found " . count($intel['fusion']['related_intel']) . " related reports\n";
            foreach ($intel['fusion']['related_intel'] as $related) {
                $sitrep .= "- {$related['registration_id']} (Relevance: {$related['relevance_score']}%)\n";
            }
            $sitrep .= "\n";
        }

        $sitrep .= "6. RECOMMENDATION\n";
        $sitrep .= "----------------\n";
        $sitrep .= "{$intel['assessment']['recommendation']}\n";

        return $sitrep;
    }

    /**
     * المرحلة 11: Dissemination - التوزيع حسب الصلاحيات
     */
    public static function disseminate(array $product_intel): array {
        $distribution_list = [];
        $access_level_required = 'analyst';

        // تحديد قائمة التوزيع بناءً على نوع المنتج ومستوى التهديد
        if ($product_intel['product']['type'] === 'FLASH_ALERT') {
            $access_level_required = 'supervisor';
            $distribution_list = [
                'operations_center',
                'senior_analysts',
                'decision_makers'
            ];
        } elseif ($product_intel['assessment']['threat_level'] === 'CRITICAL') {
            $access_level_required = 'director';
            $distribution_list = [
                'director',
                'deputy_director',
                'operations_center',
                'senior_analysts'
            ];
        } else {
            $distribution_list = [
                'analysts',
                'researchers'
            ];
        }

        $disseminated = array_merge($product_intel, [
            'dissemination' => [
                'distributed_to' => $distribution_list,
                'access_level_required' => $access_level_required,
                'distribution_timestamp' => current_time('mysql', true),
                'distribution_method' => 'secure_channel',
                'acknowledgment_required' => $product_intel['triage']['priority'] === 'FLASH'
            ]
        ]);

        self::$processing_log[] = [
            'stage' => 'DISSEMINATION',
            'id' => $product_intel['registration_id'],
            'timestamp' => current_time('mysql', true),
            'status' => 'COMPLETED',
            'recipients' => count($distribution_list)
        ];

        return $disseminated;
    }

    /**
     * المرحلة 12: Feedback - حلقة التغذية الراجعة
     */
    public static function feedback(string $registration_id, array $feedback_data): bool {
        // البحث عن intel المعالج
        $found_intel = null;
        foreach (self::$processed_news as &$intel) {
            if ($intel['registration_id'] === $registration_id) {
                $found_intel = &$intel;
                break;
            }
        }

        if (!$found_intel) {
            return false;
        }

        // إضافة التغذية الراجعة
        $found_intel['feedback'] = [
            'submitted_at' => current_time('mysql', true),
            'submitted_by' => get_current_user_id() ?: 'system',
            'accuracy_rating' => $feedback_data['accuracy_rating'] ?? null, // 1-5
            'usefulness_rating' => $feedback_data['usefulness_rating'] ?? null, // 1-5
            'comments' => $feedback_data['comments'] ?? '',
            'corrections' => $feedback_data['corrections'] ?? [],
            'action_taken' => $feedback_data['action_taken'] ?? ''
        ];

        // تحديث تقييم المصدر إذا كانت هناك ملاحظات
        if (!empty($feedback_data['accuracy_rating']) && $feedback_data['accuracy_rating'] <= 2) {
            // تخفيض تقييم المصدر
            $source_id = $found_intel['source_id'];
            if (isset(self::$source_reliability_db[$source_id])) {
                self::$source_reliability_db[$source_id]['reliability'] = max(
                    0,
                    self::$source_reliability_db[$source_id]['reliability'] - 10
                );
            }
        }

        self::$processing_log[] = [
            'stage' => 'FEEDBACK',
            'id' => $registration_id,
            'timestamp' => current_time('mysql', true),
            'status' => 'COMPLETED',
            'rating' => $feedback_data['accuracy_rating'] ?? 'N/A'
        ];

        return true;
    }

    /**
     * تشغيل الخط الكامل للمعالجة
     */
    public static function process_full_pipeline(array $raw_intel): array {
        // 1. Collection
        $collected = self::collect($raw_intel);

        // 2. Registration
        $registered = self::register($collected);

        // 3. Reliability Assessment
        $assessed_reliability = self::assess_reliability($registered);

        // 4. Triage
        $triaged = self::triage($assessed_reliability);

        // 5. Validation
        $validated = self::validate($triaged);

        // Skip processing if duplicate
        if ($validated['validation']['status'] === 'DUPLICATE') {
            return self::finalize_duplicate($validated);
        }

        // 6. Structuring
        $structured = self::structure($validated);

        // 7. Fusion
        $fused = self::fuse($structured);

        // 8. Analysis
        $analyzed = self::analyze($fused);

        // 9. Assessment
        $assessed = self::assess($analyzed);

        // 10. Product Generation
        $product = self::generate_product($assessed);

        // 11. Dissemination
        $disseminated = self::disseminate($product);

        // حفظ في السجل النهائي
        self::$processed_news[] = $disseminated;

        return $disseminated;
    }

    private static function finalize_duplicate(array $validated): array {
        $final = array_merge($validated, [
            'product' => [
                'type' => 'DUPLICATE_NOTICE',
                'content' => "هذا الخبر مكرر من: {$validated['validation']['duplicate_of']}",
                'generated_at' => current_time('mysql', true)
            ],
            'dissemination' => [
                'distributed_to' => ['archive_only'],
                'access_level_required' => 'analyst',
                'distribution_timestamp' => current_time('mysql', true)
            ]
        ]);

        self::$processed_news[] = $final;

        self::$processing_log[] = [
            'stage' => 'PIPELINE_COMPLETE',
            'id' => $validated['registration_id'],
            'timestamp' => current_time('mysql', true),
            'status' => 'DUPLICATE_SKIPPED'
        ];

        return $final;
    }

    /**
     * توليد UUID
     */
    private static function generate_uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * الحصول على سجل المعالجة
     */
    public static function get_processing_log(): array {
        return self::$processing_log;
    }

    /**
     * الحصول على التنبيهات العاجلة
     */
    public static function get_flash_alerts(): array {
        return self::$flash_alerts;
    }

    /**
     * الحصول على تقارير SITREP
     */
    public static function get_sitreps(): array {
        return self::$sitreps;
    }

    /**
     * الحصول على سلسلة الأدلة
     */
    public static function get_chain_of_custody(): array {
        return self::$chain_of_custody;
    }

    /**
     * تصدير تقرير شامل عن خط المعالجة
     */
    public static function export_pipeline_report(): string {
        $report = "# Intelligence Pipeline Report\n";
        $report .= "Generated: " . current_time('Y-m-d H:i:s') . "\n\n";

        $report .= "## Processing Statistics\n";
        $report .= "- Total Items Processed: " . count(self::$processed_news) . "\n";
        $report .= "- Flash Alerts Generated: " . count(self::$flash_alerts) . "\n";
        $report .= "- SITREPs Generated: " . count(self::$sitreps) . "\n";
        $report .= "- Sources Evaluated: " . count(self::$source_reliability_db) . "\n\n";

        $report .= "## Processing Log\n";
        $report .= "```\n";
        foreach (self::$processing_log as $log) {
            $report .= "[{$log['timestamp']}] {$log['stage']} - {$log['id']} - {$log['status']}\n";
        }
        $report .= "```\n";

        return $report;
    }
}
