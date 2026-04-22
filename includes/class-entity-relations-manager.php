<?php
/**
 * Entity Relations Manager
 * يدير جدول العلاقات بين الكيانات والمصفوفة الذكية
 * 
 * @package Beiruttime\OSINT
 * @version 1.0.0
 */

if (!defined('ABSPATH')) exit;

class SO_Entity_Relations_Manager {
    
    /**
     * تهيئة المدير
     */
    public static function init() {
        add_action('admin_init', [__CLASS__, 'ensure_table_exists']);
    }
    
    /**
     * التأكد من وجود جدول العلاقات
     */
    public static function ensure_table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'so_entity_relations';
        $charset_collate = $wpdb->get_charset_collate();
        
        $current_version = get_option('so_entity_relations_version', '0.0.0');
        
        if (version_compare($current_version, '1.0.0', '<')) {
            self::create_table($charset_collate);
            update_option('so_entity_relations_version', '1.0.0');
        }
    }
    
    /**
     * إنشاء جدول العلاقات
     */
    private static function create_table($charset_collate) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $table = $wpdb->prefix . 'so_entity_relations';
        
        $sql = "CREATE TABLE {$table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            entity1_type varchar(50) NOT NULL,
            entity1_value varchar(255) NOT NULL,
            entity2_type varchar(50) NOT NULL,
            entity2_value varchar(255) NOT NULL,
            relation_type varchar(50) NOT NULL DEFAULT 'general',
            strength float NOT NULL DEFAULT 0.5,
            event_id bigint(20) DEFAULT 0,
            event_count int(11) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            last_seen datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            metadata text NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_relation (entity1_type, entity1_value, entity2_type, entity2_value),
            KEY entity1 (entity1_type, entity1_value),
            KEY entity2 (entity2_type, entity2_value),
            KEY relation_type (relation_type),
            KEY strength (strength),
            KEY last_seen (last_seen)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    /**
     * إضافة علاقة جديدة
     * 
     * @param string $type1 نوع الكيان الأول
     * @param string $value1 قيمة الكيان الأول
     * @param string $type2 نوع الكيان الثاني
     * @param string $value2 قيمة الكيان الثاني
     * @param string $relation_type نوع العلاقة
     * @param float $base_strength قوة العلاقة الأساسية
     * @param int $event_id معرف الحدث
     * @return int|false معرف العلاقة أو false عند الفشل
     */
    public static function add_relation($type1, $value1, $type2, $value2, $relation_type = 'general', $base_strength = 0.5, $event_id = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'so_entity_relations';
        
        // التحقق من وجود العلاقة
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE entity1_type = %s AND entity1_value = %s 
             AND entity2_type = %s AND entity2_value = %s",
            $type1, $value1, $type2, $value2
        ));
        
        if ($existing) {
            // تعزيز القوة بالمصفوفة الذكية
            $new_strength = min(1.0, $existing->strength + 0.05);
            $wpdb->update($table, [
                'strength' => $new_strength,
                'last_seen' => current_time('mysql'),
                'event_count' => $existing->event_count + 1,
                'event_id' => $event_id,
            ], ['id' => $existing->id]);
            
            return $existing->id;
        } else {
            // إنشاء علاقة جديدة
            $wpdb->insert($table, [
                'entity1_type' => $type1,
                'entity1_value' => $value1,
                'entity2_type' => $type2,
                'entity2_value' => $value2,
                'relation_type' => $relation_type,
                'strength' => $base_strength,
                'event_id' => $event_id,
                'event_count' => 1,
                'created_at' => current_time('mysql'),
                'last_seen' => current_time('mysql'),
            ]);
            
            return $wpdb->insert_id;
        }
    }
    
    /**
     * الحصول على علاقات كيان معين
     * 
     * @param string $type نوع الكيان
     * @param string $value قيمة الكيان
     * @param float $min_strength الحد الأدنى للقوة
     * @return array قائمة العلاقات
     */
    public static function get_entity_relations($type, $value, $min_strength = 0.3) {
        global $wpdb;
        $table = $wpdb->prefix . 'so_entity_relations';
        
        $relations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE (entity1_type = %s AND entity1_value = %s) 
             OR (entity2_type = %s AND entity2_value = %s)
             AND strength >= %f
             ORDER BY strength DESC, event_count DESC
             LIMIT 50",
            $type, $value, $type, $value, $min_strength
        ));
        
        return $relations ?: [];
    }
    
    /**
     * الحصول على شبكة علاقات لحدث معين
     * 
     * @param int $event_id معرف الحدث
     * @return array شبكة العلاقات
     */
    public static function get_event_network($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'so_entity_relations';
        
        $relations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE event_id = %d 
             ORDER BY strength DESC",
            $event_id
        ));
        
        // بناء الشبكة
        $network = [
            'nodes' => [],
            'links' => [],
        ];
        
        $nodes_seen = [];
        
        foreach ($relations as $rel) {
            // إضافة العقد
            $node1_key = "{$rel->entity1_type}:{$rel->entity1_value}";
            $node2_key = "{$rel->entity2_type}:{$rel->entity2_value}";
            
            if (!isset($nodes_seen[$node1_key])) {
                $network['nodes'][] = [
                    'id' => $node1_key,
                    'type' => $rel->entity1_type,
                    'value' => $rel->entity1_value,
                ];
                $nodes_seen[$node1_key] = true;
            }
            
            if (!isset($nodes_seen[$node2_key])) {
                $network['nodes'][] = [
                    'id' => $node2_key,
                    'type' => $rel->entity2_type,
                    'value' => $rel->entity2_value,
                ];
                $nodes_seen[$node2_key] = true;
            }
            
            // إضافة الرابط
            $network['links'][] = [
                'source' => $node1_key,
                'target' => $node2_key,
                'type' => $rel->relation_type,
                'strength' => $rel->strength,
                'event_count' => $rel->event_count,
            ];
        }
        
        return $network;
    }
    
    /**
     * تحليل المصفوفة الذكية - اكتشاف الأنماط
     * 
     * @return array أنماط العلاقات المكتشفة
     */
    public static function analyze_patterns() {
        global $wpdb;
        $table = $wpdb->prefix . 'so_entity_relations';
        
        // العلاقات الأكثر تكراراً
        $top_relations = $wpdb->get_results(
            "SELECT entity1_type, entity1_value, entity2_type, entity2_value, relation_type, strength, event_count
             FROM {$table}
             WHERE event_count >= 3
             ORDER BY event_count DESC, strength DESC
             LIMIT 20"
        );
        
        // العلاقات عالية القوة
        $strong_relations = $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE strength >= 0.8
             ORDER BY strength DESC
             LIMIT 20"
        );
        
        // العلاقات الناشئة (ظهرت مؤخراً)
        $emerging_relations = $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY created_at DESC
             LIMIT 20"
        );
        
        return [
            'top_relations' => $top_relations ?: [],
            'strong_relations' => $strong_relations ?: [],
            'emerging_relations' => $emerging_relations ?: [],
        ];
    }
    
    /**
     * تنظيف العلاقات القديمة
     * 
     * @param int $days عدد الأيام للاحتفاظ
     */
    public static function cleanup_old_relations($days = 90) {
        global $wpdb;
        $table = $wpdb->prefix . 'so_entity_relations';
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} 
             WHERE last_seen < DATE_SUB(NOW(), INTERVAL %d DAY)
             AND event_count = 1
             AND strength < 0.5",
            $days
        ));
    }
}

// تهيئة المدير
SO_Entity_Relations_Manager::init();
