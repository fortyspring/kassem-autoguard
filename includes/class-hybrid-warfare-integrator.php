<?php
/**
 * Hybrid Warfare Integrator
 * يدمج محركات الحرب المركبة في سير المعالجة الرئيسي
 * 
 * @package Beiruttime\OSINT
 * @version 1.0.0
 */

if (!defined('ABSPATH')) exit;

class SO_Hybrid_Warfare_Integrator {
    
    /**
     * تهيئة التكامل
     */
    public static function init() {
        add_action('so_event_processed', [__CLASS__, 'process_hybrid_analysis'], 10, 2);
        add_action('so_reclassify_event_after_insert', [__CLASS__, 'post_insert_enhancement'], 10, 1);
        add_filter('so_event_before_insert', [__CLASS__, 'pre_insert_enhancement'], 10, 1);
    }
    
    /**
     * معالجة تحليل الحرب المركبة قبل الإدخال
     * 
     * @param array $event_data بيانات الحدث
     * @return array بيانات محسنة
     */
    public static function pre_insert_enhancement($event_data) {
        if (empty($event_data['title']) && empty($event_data['war_data'])) {
            return $event_data;
        }
        
        // تحميل محرك الحرب المركبة
        if (!class_exists('Beiruttime\\OSINT\\Services\\HybridWarfareEngine')) {
            require_once __DIR__ . '/../src/services/class-hybrid-warfare.php';
        }
        
        try {
            $engine = Beiruttime\OSINT\Services\HybridWarfareEngine::instance();
            
            // تحليل الطبقات
            $layers = $engine->classify_hybrid_layers(
                $event_data['title'] ?? '',
                $event_data['war_data'] ?? ''
            );
            
            // حساب المؤشرات
            $scores = $engine->calculate_advanced_scores($event_data);
            
            // استخراج شبكة الفاعلين
            $actor_network = $engine->extract_actor_network(
                $event_data['title'] ?? '',
                $event_data['war_data'] ?? '',
                $event_data['actor_v2'] ?? ''
            );
            
            // دمج النتائج
            $event_data = array_merge($event_data, [
                'hybrid_layers' => !empty($layers) ? json_encode($layers, JSON_UNESCAPED_UNICODE) : null,
                'multi_domain_score' => $scores['multi_domain_score'] ?? 0,
                'threat_score' => $scores['threat_score'] ?? 0,
                'escalation_score' => $scores['escalation_score'] ?? 0,
                'sentiment_score' => $scores['sentiment_score'] ?? 0,
                'confidence_score' => $scores['confidence_score'] ?? 0,
                'risk_level' => $scores['risk_level'] ?? 'medium',
                'urgency_level' => $scores['urgency_level'] ?? 'normal',
                'primary_actor' => $actor_network['primary_actor'] ?? null,
                'secondary_actor' => $actor_network['secondary_actor'] ?? null,
                'actor_network' => !empty($actor_network['network']) ? json_encode($actor_network['network'], JSON_UNESCAPED_UNICODE) : null,
                'actor_relationships' => !empty($actor_network['relationships']) ? json_encode($actor_network['relationships'], JSON_UNESCAPED_UNICODE) : null,
            ]);
            
            // إضافة حقول التحقق
            $verification = $engine->advanced_verification($event_data);
            $event_data['verification_status'] = $verification['status'] ?? 'unverified';
            $event_data['verified_sources_count'] = $verification['sources_count'] ?? 0;
            $event_data['has_visual_evidence'] = !empty($verification['visual_evidence']) ? 1 : 0;
            $event_data['has_satellite_imagery'] = !empty($verification['satellite_imagery']) ? 1 : 0;
            $event_data['source_conflict'] = !empty($verification['conflicts']) ? 1 : 0;
            
        } catch (Exception $e) {
            error_log('Beiruttime OSINT - Hybrid Integration Error: ' . $e->getMessage());
        }
        
        return $event_data;
    }
    
    /**
     * معالجة إضافية بعد الإدخال
     * 
     * @param int $event_id معرف الحدث
     */
    public static function post_insert_enhancement($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'so_news_events';
        
        $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $event_id), ARRAY_A);
        
        if (!$event) {
            return;
        }
        
        // تحديث العلاقات بين الكيانات
        self::update_entity_relations($event);
        
        // تحديث نمط التصعيد
        self::update_escalation_pattern($event);
        
        // فحص الإنذار المبكر
        self::check_early_warning($event);
    }
    
    /**
     * تحديث علاقات الكيانات
     * 
     * @param array $event بيانات الحدث
     */
    private static function update_entity_relations($event) {
        global $wpdb;
        $relations_table = $wpdb->prefix . 'so_entity_relations';
        
        $entities = [
            'actor' => $event['primary_actor'] ?? null,
            'target' => $event['target_entity'] ?? null,
            'region' => $event['geo_country'] ?? $event['region'] ?? null,
            'weapon' => $event['weapon_type'] ?? null,
        ];
        
        // تصفية القيم الفارغة
        $entities = array_filter($entities);
        
        if (count($entities) < 2) {
            return;
        }
        
        // إنشاء علاقات زوجية
        $entity_types = array_keys($entities);
        for ($i = 0; $i < count($entity_types); $i++) {
            for ($j = $i + 1; $j < count($entity_types); $j++) {
                $type1 = $entity_types[$i];
                $type2 = $entity_types[$j];
                $value1 = $entities[$type1];
                $value2 = $entities[$type2];
                
                if (empty($value1) || empty($value2)) {
                    continue;
                }
                
                // تحديد نوع العلاقة
                $relation_type = self::determine_relation_type($type1, $type2);
                
                // حساب قوة العلاقة الأساسية
                $base_strength = self::get_base_relation_strength($relation_type);
                
                // التحقق من وجود علاقة سابقة
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$relations_table} 
                     WHERE entity1_type = %s AND entity1_value = %s 
                     AND entity2_type = %s AND entity2_value = %s",
                    $type1, $value1, $type2, $value2
                ));
                
                if ($existing) {
                    // تعزيز القوة
                    $new_strength = min(1.0, $existing->strength + 0.05);
                    $wpdb->update($relations_table, [
                        'strength' => $new_strength,
                        'last_seen' => current_time('mysql'),
                        'event_count' => $existing->event_count + 1,
                    ], ['id' => $existing->id]);
                } else {
                    // إنشاء علاقة جديدة
                    $wpdb->insert($relations_table, [
                        'entity1_type' => $type1,
                        'entity1_value' => $value1,
                        'entity2_type' => $type2,
                        'entity2_value' => $value2,
                        'relation_type' => $relation_type,
                        'strength' => $base_strength,
                        'event_id' => $event['id'],
                        'event_count' => 1,
                        'created_at' => current_time('mysql'),
                        'last_seen' => current_time('mysql'),
                    ]);
                }
            }
        }
    }
    
    /**
     * تحديد نوع العلاقة
     */
    private static function determine_relation_type($type1, $type2) {
        $pair = [$type1, $type2];
        sort($pair);
        
        if ($pair === ['actor', 'target']) {
            return 'actor_target';
        } elseif ($pair === ['actor', 'region']) {
            return 'actor_region';
        } elseif ($pair === ['actor', 'weapon']) {
            return 'actor_weapon';
        } elseif ($pair === ['region', 'target']) {
            return 'region_target';
        }
        
        return 'general';
    }
    
    /**
     * الحصول على قوة العلاقة الأساسية
     */
    private static function get_base_relation_strength($relation_type) {
        $strengths = [
            'actor_target' => 0.8,
            'actor_region' => 0.6,
            'actor_weapon' => 0.7,
            'region_target' => 0.5,
            'sponsorship' => 0.9,
        ];
        
        return $strengths[$relation_type] ?? 0.5;
    }
    
    /**
     * تحديث نمط التصعيد
     */
    private static function update_escalation_pattern($event) {
        // منطق اكتشاف أنماط التصعيد
        // يمكن توسيعه لاحقاً
    }
    
    /**
     * فحص الإنذار المبكر
     */
    private static function check_early_warning($event) {
        $threat_score = intval($event['threat_score'] ?? 0);
        $escalation_score = intval($event['escalation_score'] ?? 0);
        
        // عتبات الإنذار
        if ($threat_score >= 70 || $escalation_score >= 60) {
            $alert_priority = $threat_score >= 80 ? 'critical' : 'high';
            
            global $wpdb;
            $table = $wpdb->prefix . 'so_news_events';
            
            $wpdb->update($table, [
                'alert_flag' => 1,
                'alert_type' => 'escalation_warning',
                'alert_reason' => sprintf('Threat: %d, Escalation: %d', $threat_score, $escalation_score),
                'alert_priority' => $alert_priority,
                'alert_status' => 'active',
            ], ['id' => $event['id']]);
        }
    }
}

// تهيئة التكامل
SO_Hybrid_Warfare_Integrator::init();
