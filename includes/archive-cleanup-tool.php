<?php
/**
 * Archive Cleanup Tool - OSINT-LB PRO
 * 
 * أداة تنظيف الأرشيف السابق من الفاعلين الملوثين أو غير المحددين
 * تعيد تحليل الأحداث القديمة باستخدام المحرك الموحد sod_resolve_actor_final
 * 
 * @package OSINT_LB_PRO
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SO_Archive_Cleanup_Tool {

    /**
     * قائمة الفاعلين الملوثين/غير المقبولين
     */
    private static $junk_actors = [
        'فاعل غير محسوم',
        'غير معروف',
        'مجهول',
        'unknown',
        'unidentified',
        'anonymous',
        'news agency',
        'press agency',
        'media',
        'reporter',
        'journalist',
        'correspondent',
        'source',
        'مصدر',
    ];

    /**
     * وكالات إعلامية لا يجب أن تكون فاعلين
     */
    private static $media_labels = [
        // وكالات عربية
        'رويترز',
        'فرانس برس',
        'أسوشيتد برس',
        'الأناضول',
        'العربية',
        'الجزيرة',
        'سي إن إن',
        'بي بي سي',
        'سكاي نيوز',
        'المصدر',
        'وكالة',
        'صحيفة',
        'جريدة',
        'قناة',
        'تلفزيون',
        'إذاعة',
        'الميادين',
        'ايران الان',
        'ايران بالعربي',
        'الاعلام العبري',
        'beiruttime',
        'RNN_Alerts_AR',
        'Nablusgheer',
        
        // وكالات أجنبية
        'reuters',
        'afp',
        'ap',
        'anatolia',
        'alarabiya',
        'aljazeera',
        'cnn',
        'bbc',
        'skynews',
        'bloomberg',
        'nytimes',
        'washingtonpost',
        
        // قنوات ووسائل إعلام
        'القناة',
        'channel',
        'tv',
        'newspaper',
        'magazine',
    ];

    /**
     * أشخاص ومسؤولون لا يجب أن يكونوا فاعلين (يجب تحويلهم لجهات)
     */
    private static $person_patterns = [
        'ترمب',
        'ترامب',
        'donald trump',
        'trump',
        'جعجع',
        'عون',
        'نتنياهو',
        'بايدن',
        'putin',
        'zelensky',
        'khamenei',
        'rais',
        'minister',
        'وزير',
        'رئيس',
        'president',
        'prime minister',
        'secretary',
        'ambassador',
        'envoy',
        'speaker',
        'commander',
        'قائد',
        'مستشار',
        'advisor',
        'representative',
        'مندوب',
        'مسؤول',
        'official',
        'source',
        'مصدر',
        'spokesman',
        'متحدث',
        'director',
        'مدير',
        'head',
        'رئيس',
    ];

    /**
     * دول ومناطق لا يجب أن تكون فاعلين مباشرة
     */
    private static $location_as_actor = [
        'فلسطين',
        'إيران',
        'الولايات المتحدة',
        'اميركا',
        'أميركا',
        'america',
        'usa',
        'us',
        'الصين',
        'china',
        'روسيا',
        'russia',
        'السعودية',
        'saudi',
        'باكستان',
        'pakistan',
        'العراق',
        'iraq',
        'سوريا',
        'syria',
        'لبنان',
        'lebanon',
        'اليمن',
        'yemen',
        'تركيا',
        'turkey',
        'türkiye',
        'britain',
        'uk',
        'france',
        'germany',
        'فرنسا',
        'ألمانيا',
    ];

    /**
     * التحقق مما إذا كان الفاعل ملوثاً
     * 
     * @param string $actor اسم الفاعل
     * @return bool true إذا كان ملوثاً
     */
    public static function is_junk_actor($actor) {
        if (empty($actor)) {
            return true;
        }

        $actor_lower = strtolower(trim($actor));

        // التحقق من القائمة السوداء الأساسية
        foreach (self::$junk_actors as $junk) {
            if (strpos($actor_lower, strtolower($junk)) !== false) {
                return true;
            }
        }

        // التحقق من الوكالات الإعلامية
        foreach (self::$media_labels as $media) {
            if (strpos($actor_lower, strtolower($media)) !== false) {
                return true;
            }
        }

        // التحقق من أنماط الأشخاص والمسؤولين
        foreach (self::$person_patterns as $person) {
            if (strpos($actor_lower, strtolower($person)) !== false) {
                // إذا كان الفاعل هو الشخص فقط (بدون جهة)
                if (strlen($actor_lower) < 50 || strpos($actor_lower, 'وزارة') === false && strpos($actor_lower, 'حزب') === false && strpos($actor_lower, 'حركة') === false) {
                    return true;
                }
            }
        }

        // التحقق من الدول كمصدر وحيد
        foreach (self::$location_as_actor as $location) {
            if ($actor_lower === strtolower($location) || $actor_lower === 'دولة ' . strtolower($location)) {
                return true;
            }
        }

        // أنماط عامة للفاعلين غير المقبولين
        $patterns = [
            '/^agency\b/i',
            '/^news\b/i',
            '/^media\b/i',
            '/^source\b/i',
            '/^report\b/i',
            '/^according to/i',
            '/^said by/i',
            '/^موقع\b/i',
            '/^صحيفة\b/i',
            '/^جريدة\b/i',
            '/^قناة\b/i',
            '/^وكالة\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $actor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * جلب الأحداث الملوثة من الأرشيف
     * 
     * @param int $limit عدد الأحداث في الدفعة
     * @param int $offset نقطة البداية
     * @return array قائمة الأحداث
     */
    public static function get_dirty_events($limit = 50, $offset = 0) {
        global $wpdb;

        $table_posts = $wpdb->posts;
        $table_postmeta = $wpdb->postmeta;

        // نجلب الأحداث التي تحتوي على فاعلين ملوثين أو غير محددين
        // نضيف أنماط جديدة للوكالات الإعلامية والأشخاص
        $query = "
            SELECT DISTINCT p.ID, p.post_title, p.post_content
            FROM {$table_posts} p
            INNER JOIN {$table_postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'so_event'
              AND p.post_status IN ('publish', 'private')
              AND pm.meta_key = '_so_field_data'
              AND (
                  pm.meta_value LIKE '%فاعل غير محسوم%'
                  OR pm.meta_value LIKE '%غير معروف%'
                  OR pm.meta_value LIKE '%مجهول%'
                  OR pm.meta_value LIKE '%unknown%'
                  OR pm.meta_value LIKE '%unidentified%'
                  OR pm.meta_value LIKE '%رويترز%'
                  OR pm.meta_value LIKE '%العربية%'
                  OR pm.meta_value LIKE '%الجزيرة%'
                  OR pm.meta_value LIKE '%الميادين%'
                  OR pm.meta_value LIKE '%ايران الان%'
                  OR pm.meta_value LIKE '%beiruttime%'
                  OR pm.meta_value LIKE '%مصدر%'
                  OR pm.meta_value LIKE '%وكالة%'
                  OR pm.meta_value LIKE '%صحيفة%'
                  OR pm.meta_value LIKE '%قناة%'
                  OR pm.meta_value LIKE '%ترمب%'
                  OR pm.meta_value LIKE '%ترامب%'
                  OR pm.meta_value LIKE '%جعجع%'
                  OR pm.meta_value LIKE '%وزير%'
                  OR pm.meta_value LIKE '%رئيس%'
                  OR pm.meta_value LIKE '%مستشار%'
              )
            ORDER BY p.ID ASC
            LIMIT %d OFFSET %d
        ";

        $events = $wpdb->get_results($wpdb->prepare($query, $limit, $offset), ARRAY_A);

        return $events ?: [];
    }

    /**
     * إعادة تحليل حدث واحد وتنظيفه
     * 
     * @param int $event_id معرف الحدث
     * @return array نتيجة التنظيف
     */
    public static function clean_single_event($event_id) {
        $result = [
            'success' => false,
            'event_id' => $event_id,
            'old_actor' => '',
            'new_actor' => '',
            'confidence' => 0,
            'reason' => '',
            'changes' => [],
            'error' => '',
        ];

        // جلب بيانات الحدث
        $title = get_the_title($event_id);
        $content = get_post_field('post_content', $event_id);
        
        if (empty($title) && empty($content)) {
            $result['error'] = 'حدث فارغ أو غير موجود';
            return $result;
        }

        // استخراج المنطقة من البيانات الحالية
        $field_data = get_post_meta($event_id, '_so_field_data', true);
        $current_actor = '';
        $current_region = '';

        if (is_array($field_data)) {
            $current_actor = $field_data['actor_final'] ?? $field_data['actor_v2'] ?? '';
            $current_region = $field_data['regions'] ?? '';
            
            // إذا لم تكن المنطقة موجودة، نحاول استخراجها من المحتوى
            if (empty($current_region)) {
                if (function_exists('sod_resolve_field')) {
                    $text = so_clean_text((string)$title . ' ' . (string)$content);
                    $current_region = (string)sod_resolve_field($text, 'regions');
                }
            }
        }

        // التحقق مما إذا كان الفاعل الحالي جيداً
        if (!empty($current_actor) && !self::is_junk_actor($current_actor)) {
            // الفاعل الحالي جيد، لا حاجة للتنظيف
            $result['success'] = true;
            $result['old_actor'] = $current_actor;
            $result['new_actor'] = $current_actor;
            $result['reason'] = 'الفاعل الحالي صالح، لم يتم التعديل';
            return $result;
        }

        $result['old_actor'] = $current_actor ?: 'غير موجود';

        // إعادة التحليل باستخدام المحرك الموحد
        if (function_exists('sod_resolve_actor_final')) {
            $resolved = sod_resolve_actor_final($title, $content, $current_region);
            
            $new_actor = trim($resolved['actor_final'] ?? '');
            $confidence = (int)($resolved['confidence'] ?? 0);
            $reason = $resolved['reason'] ?? '';
            $source = $resolved['source'] ?? 'unknown';

            // التحقق من جودة النتيجة الجديدة
            if (empty($new_actor) || self::is_junk_actor($new_actor)) {
                $result['success'] = false;
                $result['new_actor'] = $new_actor ?: 'فشل التحديد';
                $result['confidence'] = $confidence;
                $result['reason'] = 'لم يتم العثور على فاعل أفضل';
                $result['error'] = 'النتيجة الجديدة غير مقبولة';
                return $result;
            }

            // التأكد من أن النتيجة الجديدة أفضل من القديمة
            $is_better = false;
            
            if (empty($current_actor) || self::is_junk_actor($current_actor)) {
                $is_better = true;
            } elseif ($confidence > 50) {
                $is_better = true;
            }

            if (!$is_better) {
                $result['success'] = false;
                $result['new_actor'] = $new_actor;
                $result['confidence'] = $confidence;
                $result['reason'] = 'النتيجة الجديدة ليست أفضل بشكل كافٍ';
                return $result;
            }

            // تحديث البيانات
            $update_result = self::update_event_actor($event_id, $new_actor, $confidence, $reason, $source);

            if ($update_result['success']) {
                $result['success'] = true;
                $result['new_actor'] = $new_actor;
                $result['confidence'] = $confidence;
                $result['reason'] = $reason;
                $result['changes'] = $update_result['changes'];

                // تسجيل العملية في السجل
                self::log_cleanup_action($event_id, [
                    'old_actor' => $current_actor,
                    'new_actor' => $new_actor,
                    'confidence' => $confidence,
                    'reason' => $reason,
                    'source' => $source,
                    'timestamp' => current_time('mysql'),
                ]);
            } else {
                $result['error'] = $update_result['error'];
            }
        } else {
            $result['error'] = 'دالة sod_resolve_actor_final غير موجودة';
        }

        return $result;
    }

    /**
     * تحديث فاعل حدث معين
     * 
     * @param int $event_id معرف الحدث
     * @param string $new_actor الفاعل الجديد
     * @param int $confidence مستوى الثقة
     * @param string $reason سبب التحديد
     * @param string $source مصدر القرار
     * @return array نتيجة التحديث
     */
    private static function update_event_actor($event_id, $new_actor, $confidence, $reason, $source) {
        $result = [
            'success' => false,
            'changes' => [],
            'error' => '',
        ];

        // تحديث field_data
        $field_data = get_post_meta($event_id, '_so_field_data', true);
        if (!is_array($field_data)) {
            $field_data = [];
        }

        $old_actor_field = $field_data['actor_final'] ?? $field_data['actor_v2'] ?? '';
        
        $field_data['actor_final'] = $new_actor;
        $field_data['actor_v2'] = $new_actor;
        $field_data['actor_final_unified'] = true;
        $field_data['actor_source'] = $source;
        $field_data['actor_reason'] = $reason;
        $field_data['actor_confidence'] = $confidence;
        $field_data['_cleaned_at'] = current_time('mysql');
        $field_data['_cleaned_from'] = $old_actor_field;

        update_post_meta($event_id, '_so_field_data', $field_data);
        $result['changes']['field_data'] = true;

        // تحديث war_data
        $war_data = get_post_meta($event_id, '_so_war_data', true);
        if (!is_array($war_data)) {
            $war_data = [];
        }

        $war_data['actor'] = $new_actor;
        $war_data['who'] = $new_actor;
        $war_data['who_primary'] = $new_actor;
        $war_data['_cleaned_at'] = current_time('mysql');

        update_post_meta($event_id, '_so_war_data', $war_data);
        $result['changes']['war_data'] = true;

        // تحديث 5W1H إذا موجود
        $five_w_one_h = get_post_meta($event_id, '_so_5w1h', true);
        if (is_array($five_w_one_h)) {
            $five_w_one_h['who_primary'] = $new_actor;
            $five_w_one_h['_cleaned_at'] = current_time('mysql');
            update_post_meta($event_id, '_so_5w1h', $five_w_one_h);
            $result['changes']['5w1h'] = true;
        }

        // تحديث cache
        wp_cache_delete($event_id, 'posts');
        wp_cache_delete($event_id, 'so_event_data');

        $result['success'] = true;
        return $result;
    }

    /**
     * تنظيف دفعة من الأحداث
     * 
     * @param int $limit عدد الأحداث في الدفعة
     * @param int $offset نقطة البداية
     * @return array إحصائيات الدفعة
     */
    public static function clean_batch($limit = 50, $offset = 0) {
        $events = self::get_dirty_events($limit, $offset);
        
        $stats = [
            'total' => count($events),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'results' => [],
        ];

        foreach ($events as $event) {
            $result = self::clean_single_event($event['ID']);
            
            if ($result['success']) {
                if ($result['old_actor'] === $result['new_actor']) {
                    $stats['skipped']++;
                } else {
                    $stats['success']++;
                }
            } else {
                $stats['failed']++;
            }

            $stats['results'][] = [
                'event_id' => $event['ID'],
                'title' => $event['post_title'],
                'result' => $result,
            ];
        }

        return $stats;
    }

    /**
     * تسجيل عملية تنظيف في السجل
     * 
     * @param int $event_id معرف الحدث
     * @param array $data بيانات العملية
     */
    private static function log_cleanup_action($event_id, $data) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'so_cleanup_log';

        // إنشاء الجدول إذا لم يكن موجوداً
        self::ensure_log_table_exists();

        $wpdb->insert(
            $table_name,
            [
                'event_id' => $event_id,
                'old_actor' => $data['old_actor'] ?? '',
                'new_actor' => $data['new_actor'] ?? '',
                'confidence' => $data['confidence'] ?? 0,
                'reason' => $data['reason'] ?? '',
                'source' => $data['source'] ?? '',
                'cleaned_at' => $data['timestamp'] ?? current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    /**
     * التأكد من وجود جدول السجلات
     */
    public static function ensure_log_table_exists() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'so_cleanup_log';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_id bigint(20) NOT NULL,
            old_actor varchar(255) DEFAULT '',
            new_actor varchar(255) DEFAULT '',
            confidence int(3) DEFAULT 0,
            reason text,
            source varchar(100) DEFAULT '',
            cleaned_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY cleaned_at (cleaned_at)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * الحصول على إحصائيات التنظيف
     * 
     * @return array الإحصائيات
     */
    public static function get_cleanup_stats() {
        global $wpdb;

        self::ensure_log_table_exists();
        $table_name = $wpdb->prefix . 'so_cleanup_log';

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        
        $by_actor = $wpdb->get_results(
            "SELECT new_actor, COUNT(*) as count 
             FROM {$table_name} 
             GROUP BY new_actor 
             ORDER BY count DESC 
             LIMIT 10",
            ARRAY_A
        );

        $recent = $wpdb->get_results(
            "SELECT * FROM {$table_name} 
             ORDER BY cleaned_at DESC 
             LIMIT 20",
            ARRAY_A
        );

        return [
            'total_cleaned' => (int)$total,
            'top_actors' => $by_actor ?: [],
            'recent_cleanups' => $recent ?: [],
        ];
    }

    /**
     * عد الأحداث الملوثة المتبقية
     * 
     * @return int العدد
     */
    public static function count_dirty_events() {
        global $wpdb;

        $table_posts = $wpdb->posts;
        $table_postmeta = $wpdb->postmeta;

        $query = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$table_posts} p
            INNER JOIN {$table_postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'so_event'
              AND p.post_status IN ('publish', 'private')
              AND pm.meta_key = '_so_field_data'
              AND (
                  pm.meta_value LIKE '%فاعل غير محسوم%'
                  OR pm.meta_value LIKE '%غير معروف%'
                  OR pm.meta_value LIKE '%مجهول%'
                  OR pm.meta_value LIKE '%unknown%'
                  OR pm.meta_value LIKE '%unidentified%'
                  OR pm.meta_value LIKE '%رويترز%'
                  OR pm.meta_value LIKE '%العربية%'
                  OR pm.meta_value LIKE '%الجزيرة%'
                  OR pm.meta_value LIKE '%الميادين%'
                  OR pm.meta_value LIKE '%ايران الان%'
                  OR pm.meta_value LIKE '%beiruttime%'
                  OR pm.meta_value LIKE '%مصدر%'
                  OR pm.meta_value LIKE '%وكالة%'
                  OR pm.meta_value LIKE '%صحيفة%'
                  OR pm.meta_value LIKE '%قناة%'
                  OR pm.meta_value LIKE '%ترمب%'
                  OR pm.meta_value LIKE '%ترامب%'
                  OR pm.meta_value LIKE '%جعجع%'
                  OR pm.meta_value LIKE '%وزير%'
                  OR pm.meta_value LIKE '%رئيس%'
                  OR pm.meta_value LIKE '%مستشار%'
              )
        ";

        return (int)$wpdb->get_var($query);
    }
}

// دوال مساعدة لـ WP-CLI والواجهة الإدارية

/**
 * تنظيف دفعة من الأحداث (للاستخدام في WP-CLI أو الواجهة)
 * 
 * @param int $limit عدد الأحداث
 * @param int $offset نقطة البداية
 * @return array الإحصائيات
 */
function so_cleanup_archive_batch($limit = 50, $offset = 0) {
    return SO_Archive_Cleanup_Tool::clean_batch($limit, $offset);
}

/**
 * الحصول على عدد الأحداث الملوثة
 * 
 * @return int العدد
 */
function so_count_dirty_events() {
    return SO_Archive_Cleanup_Tool::count_dirty_events();
}

/**
 * الحصول على إحصائيات التنظيف
 * 
 * @return array الإحصائيات
 */
function so_get_cleanup_stats() {
    return SO_Archive_Cleanup_Tool::get_cleanup_stats();
}

/**
 * تنظيف حدث واحد (للاستخدام الفردي)
 * 
 * @param int $event_id معرف الحدث
 * @return array نتيجة التنظيف
 */
function so_clean_single_event($event_id) {
    return SO_Archive_Cleanup_Tool::clean_single_event($event_id);
}

/**
 * إعادة تحليل حدث واحد (alias لـ so_clean_single_event)
 * 
 * @param int $event_id معرف الحدث
 * @return array نتيجة التنظيف
 */
function so_reanalyze_event($event_id) {
    return SO_Archive_Cleanup_Tool::clean_single_event($event_id);
}

// تسجيل الجدول عند تفعيل الإضافة
// ملاحظة: تم نقل hook إلى osint-pro.php الرئيسي لضمان العمل الصحيح
// register_activation_hook(__FILE__, ['SO_Archive_Cleanup_Tool', 'ensure_log_table_exists']);
