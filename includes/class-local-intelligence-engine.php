<?php
/**
 * Local Intelligence Engine - المحرك الذكي المحلي لإعادة التصنيف
 * 
 * يقوم بفحص الأحداث الملوثة، تحديد نوع التلوث، واقتراح إعادة التصنيف
 * باستخدام العقل المركزي (ActorDecisionEngineV3 + sod_resolve_actor_final)
 * 
 * @version 1.0.0
 * @package OSINT-LB-PRO
 */

if (!defined('ABSPATH')) exit;

class SO_Local_Intelligence_Engine {
    
    /**
     * النسخة الوحيدة من الفئة
     */
    private static $instance = null;
    
    /**
     * الحصول على النسخة الوحيدة
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * منع النسخ
     */
    private function __clone() {}
    
    /**
     * منع إلغاء التسلسل
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
    
    /**
     * قائمة أنماط التلوث
     */
    private $contamination_patterns = [
        'media_sources' => [
            'رويترز', 'العربية', 'الجزيرة', 'الميادين', 'ايران الان', 'beiruttime',
            'RNN_Alerts_AR', 'Nablusgheer', 'قناة الساحات', 'الاعلام العبري',
            'قناة العربية', 'الاخبار', 'LBCI', 'MTV', 'OTV', 'Tele Liban',
            'المنار', 'النهار', 'الأخبار', 'السفير', 'الجمهورية', 'الشرق الأوسط',
            'الحياة', 'عكاظ', 'الرياض', 'الوطن', 'المصري اليوم', 'اليوم السابع',
            'أخبار اليوم', 'الأهرام', 'الوفد', 'الدستور', 'الشروق', 'البوابة',
            'فرانس 24', 'بي بي سي', 'سي إن إن', 'سكاي نيوز', 'يورونيوز',
            'DW', 'RT Arabic', 'Sputnik', 'TRT عربي', ' Anadolu', 'معا', 'وفا',
            'سانا', 'تشرين', 'الثورة', 'الكوفة', 'بغداد اليوم', 'السومرية',
            'الحدث', 'العين', 'البيان', 'الاتحاد', 'الخليج', 'الراية', 'الشرق',
            'الوطن قطر', 'صحيفة عاجل', 'سبق', 'مباشر', 'CNBC عربية', 'العربية بيزنس'
        ],
        'persons_officials' => [
            'ترمب', 'ترامب', 'بايدن', 'بوتين', 'ماكرون', 'شولتز', 'ستولتنبرغ',
            'نتنياهو', 'سموتريتش', 'بن غفير', 'كاتس', 'غالانت', 'هيرتسي',
            'خامنئي', 'روحاني', ' Raisi', 'ظريف', 'عراقجي', 'سليمانى', 'قاآني',
            'نصر الله', 'هنية', 'مشعل', 'هنينة', 'الجهاد', 'الحوثي', 'صالح الصمد',
            'جعجع', 'عون', 'ميقاتي', 'سلام', 'جنبلاط', 'بري', 'اللهيب', 'حويش',
            'السيسي', 'مرسي', 'البرهان', 'حميدتي', 'الجبير', 'الفيسان', 'النعيمي',
            'بن زايد', 'محمد بن راشد', 'محمد بن سلمان', 'بندر', 'الجبير', 'عاصف',
            ' Erdogan', 'أردوغان', 'تشاووش أوغلو', 'فيضان', 'أقباز', 'يلدرم',
            'الملك سلمان', 'الملك عبدالله', 'الحسين بن طلال', 'عبدالله الثاني',
            'الرئيس', 'الوزير', 'المتحدث', 'القائد', 'المستشار', 'المبعوث',
            'السفير', 'النائب', 'السناتور', 'الجنرال', 'الأمير', 'الشيخ', 'الإمام'
        ],
        'countries_as_actors' => [
            'فلسطين', 'إسرائيل', 'إيران', 'أميركا', 'الولايات المتحدة', 'روسيا',
            'الصين', 'تركيا', 'السعودية', 'مصر', 'الأردن', 'لبنان', 'سوريا',
            'العراق', 'اليمن', 'ليبيا', 'تونس', 'الجزائر', 'المغرب', 'قطر',
            'الإمارات', 'الكويت', 'البحرين', 'عمان', 'باكستان', 'أفغانستان',
            'الهند', 'باكستان', 'أذربيجان', 'أرمينيا', 'جورجيا', 'أوكرانيا',
            'بولندا', 'ألمانيا', 'فرنسا', 'بريطانيا', 'إيطاليا', 'إسبانيا',
            'هولندا', 'بلجيكا', 'السويد', 'النرويج', 'الدنمارك', 'فنلندا',
            'اليونان', 'بلغاريا', 'رومانيا', 'المجر', 'التشيك', 'سلوفاكيا',
            'النمسا', 'سويسرا', 'كندا', 'المكسيك', 'البرازيل', 'الأرجنتين'
        ],
        'vague_patterns' => [
            'فاعل غير محسوم', 'غير معروف', 'مجهول', 'غير محدد', 'نامعلوم',
            'مصدر مجهول', 'مصادر', 'مراقبون', 'شهود عيان', 'ناشطون',
            'جهات مسؤولة', 'جهات أمنية', 'أطراف النزاع', 'الأطراف المعنية',
            'الطرف الأول', 'الطرف الثاني', 'جهة فاعلة', 'فاعل مجهول'
        ],
        'regex_patterns' => [
            '/^وكالة\b/i',
            '/^صحيفة\b/i',
            '/^جريدة\b/i',
            '/^قناة\b/i',
            '/^موقع\b/i',
            '/^إعلام\b/i',
            '/^مراسل\b/i',
            '/^مصدر\b/i',
            '/^مسؤول\b/i',
            '/^متحدث\b/i',
            '/^ناطق\b/i',
            '/^بيان\b/i'
        ]
    ];
    
    /**
     * التحقق مما إذا كان الفاعل ملوثاً
     * 
     * @param string $actor_name اسم الفاعل
     * @return array ['is_dirty'=>bool, 'type'=>string, 'pattern'=>string]
     */
    public function is_dirty_actor(string $actor_name): array {
        $actor_name = trim($actor_name);
        
        if (empty($actor_name)) {
            return ['is_dirty' => true, 'type' => 'empty', 'pattern' => ''];
        }
        
        // التحقق من الأنماط الغامضة
        foreach ($this->contamination_patterns['vague_patterns'] as $pattern) {
            if (stripos($actor_name, $pattern) !== false) {
                return ['is_dirty' => true, 'type' => 'vague', 'pattern' => $pattern];
            }
        }
        
        // التحقق من المصادر الإعلامية
        foreach ($this->contamination_patterns['media_sources'] as $source) {
            if (stripos($actor_name, $source) !== false) {
                return ['is_dirty' => true, 'type' => 'media', 'pattern' => $source];
            }
        }
        
        // التحقق من الأشخاص والمسؤولين
        foreach ($this->contamination_patterns['persons_officials'] as $person) {
            if (stripos($actor_name, $person) !== false) {
                // استثناء: إذا كان النص يحتوي على جهة رسمية مع الشخص
                if (!preg_match('/(حزب|حركة|جماعة|منظمة|حكومة|وزارة|جيش|قوات)/u', $actor_name)) {
                    return ['is_dirty' => true, 'type' => 'person', 'pattern' => $person];
                }
            }
        }
        
        // التحقق من الدول كفاعلين وحيدين
        foreach ($this->contamination_patterns['countries_as_actors'] as $country) {
            if ($actor_name === $country || stripos($actor_name, $country) === 0 && strlen($actor_name) <= strlen($country) + 5) {
                // استثناء: إذا كان هناك وصف مؤسسي
                if (!preg_match('/(جيش|قوات|حرس|حكومة|وزارة|رئاسة|برلمان|مجلس)/u', $actor_name)) {
                    return ['is_dirty' => true, 'type' => 'country_only', 'pattern' => $country];
                }
            }
        }
        
        // التحقق من أنماط Regex
        foreach ($this->contamination_patterns['regex_patterns'] as $regex) {
            if (preg_match($regex, $actor_name)) {
                return ['is_dirty' => true, 'type' => 'regex', 'pattern' => $regex];
            }
        }
        
        return ['is_dirty' => false, 'type' => 'clean', 'pattern' => ''];
    }
    
    /**
     * عد الأحداث الملوثة في الأرشيف
     * 
     * @param int|null $limit عدد أقصى للعد
     * @return array إحصائيات التلوث
     */
    public function count_dirty_events(?int $limit = null): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'so_news_events';

        $dirty_types = [
            'total' => 0,
            'scanned' => 0,
            'by_type' => [
                'empty' => 0,
                'vague' => 0,
                'media' => 0,
                'person' => 0,
                'country_only' => 0,
                'regex' => 0
            ],
            'samples' => [],
            'table' => $table_name,
            'actor_column' => 'actor_v2'
        ];

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) !== $table_name) {
            $dirty_types['error'] = 'جدول الأحداث غير موجود';
            return $dirty_types;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
        if (empty($columns) || !is_array($columns)) {
            $dirty_types['error'] = 'تعذر قراءة بنية جدول الأحداث';
            return $dirty_types;
        }

        $actor_column = 'actor_v2';
        foreach (['actor_v2', 'actor', 'actor_final'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $actor_column = $candidate;
                break;
            }
        }
        $dirty_types['actor_column'] = $actor_column;

        $title_column = in_array('title', $columns, true) ? 'title' : (in_array('post_title', $columns, true) ? 'post_title' : null);
        $select_title = $title_column ? $title_column : "''";

        $scan_limit = $limit !== null ? max(10, min(2000, (int) $limit)) : 500;
        $sql = $wpdb->prepare(
            "SELECT id, {$select_title} AS title, {$actor_column} AS actor_name FROM {$table_name} ORDER BY id DESC LIMIT %d",
            $scan_limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (!empty($wpdb->last_error)) {
            $dirty_types['error'] = $wpdb->last_error;
            return $dirty_types;
        }

        if (empty($rows)) {
            return $dirty_types;
        }

        foreach ($rows as $row) {
            $dirty_types['scanned']++;
            $actor = isset($row['actor_name']) ? (string) $row['actor_name'] : '';
            $check = $this->is_dirty_actor($actor);

            if (!$check['is_dirty']) {
                continue;
            }

            $dirty_types['total']++;
            if (!isset($dirty_types['by_type'][$check['type']])) {
                $dirty_types['by_type'][$check['type']] = 0;
            }
            $dirty_types['by_type'][$check['type']]++;

            if (count($dirty_types['samples']) < 10) {
                $title = (string) ($row['title'] ?? '');
                if (function_exists('mb_substr')) {
                    $title = mb_substr($title, 0, 120);
                } else {
                    $title = substr($title, 0, 120);
                }
                $dirty_types['samples'][] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'title' => $title,
                    'actor' => $actor,
                    'type' => $check['type'],
                    'pattern' => $check['pattern'],
                ];
            }
        }

        return $dirty_types;
    }
    
    /**
     * إعادة تحليل حدث واحد باستخدام العقل المركزي
     * 
     * @param int $event_id معرف الحدث
     * @return array نتيجة إعادة التحليل
     */
    public function reanalyze_event(int $event_id): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'so_news_events';
        
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $event_id
        ), ARRAY_A);
        
        if (!$event) {
            return ['success' => false, 'error' => 'الحدث غير موجود'];
        }
        
        // التحقق من التلوث الحالي
        $current_actor = $event['actor_v2'] ?? '';
        $dirty_check = $this->is_dirty_actor($current_actor);
        
        if (!$dirty_check['is_dirty']) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'الفاعل نظيف بالفعل',
                'current_actor' => $current_actor
            ];
        }
        
        // استخراج البيانات للتحليل
        $title = $event['title'] ?? '';
        $content = $event['content'] ?? '';
        
        // محاولة استخراج المنطقة من field_data
        $field_data = json_decode($event['field_data'] ?? '{}', true);
        $region = $field_data['region'] ?? $field_data['regions'] ?? '';
        
        if (empty($region)) {
            // محاولة من war_data
            $war_data = json_decode($event['war_data'] ?? '{}', true);
            $region = $war_data['location'] ?? $war_data['region'] ?? '';
        }
        
        // استخدام المحرك الموحد لإعادة التحليل
        if (function_exists('sod_resolve_actor_final')) {
            $new_analysis = sod_resolve_actor_final($title, $content, $region);
            
            $new_actor = $new_analysis['actor_final'] ?? '';
            $confidence = $new_analysis['confidence'] ?? 0;
            $reason = $new_analysis['reason'] ?? '';
            $source = $new_analysis['source'] ?? 'local_intelligence';
            
            // التحقق من جودة النتيجة الجديدة
            $new_dirty_check = $this->is_dirty_actor($new_actor);
            
            if ($new_dirty_check['is_dirty'] && $new_actor !== $current_actor) {
                // النتيجة الجديدة أيضاً ملوثة - لا نحدث
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'النتيجة الجديدة أيضاً ملوثة',
                    'old_actor' => $current_actor,
                    'new_actor' => $new_actor,
                    'confidence' => $confidence
                ];
            }
            
            if (empty($new_actor) || $new_actor === 'فاعل غير محسوم') {
                // النتيجة الجديدة فارغة - لا نحدث
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'النتيجة الجديدة فارغة',
                    'old_actor' => $current_actor,
                    'confidence' => $confidence
                ];
            }
            
            // تحديث الحدث
            $update_data = [
                'actor_v2' => $new_actor,
                'processed_at' => current_time('mysql')
            ];
            
            // تحديث field_data
            if (!empty($field_data)) {
                $field_data['actor_final'] = $new_actor;
                $field_data['actor_source'] = $source;
                $field_data['actor_reason'] = $reason;
                $field_data['actor_confidence'] = $confidence;
                $field_data['actor_cleaned_at'] = current_time('mysql');
                $field_data['actor_previous'] = $current_actor;
                $update_data['field_data'] = wp_json_encode($field_data, JSON_UNESCAPED_UNICODE);
            }
            
            // تحديث war_data
            if (!empty($war_data)) {
                $war_data['actor'] = $new_actor;
                $war_data['who'] = $new_actor;
                $war_data['who_primary'] = $new_actor;
                $update_data['war_data'] = wp_json_encode($war_data, JSON_UNESCAPED_UNICODE);
            }
            
            $updated = $wpdb->update($table_name, $update_data, ['id' => $event_id]);
            
            if ($updated !== false) {
                // تسجيل العملية
                $this->log_cleanup($event_id, $current_actor, $new_actor, $confidence, $reason, $source);
                
                return [
                    'success' => true,
                    'updated' => true,
                    'old_actor' => $current_actor,
                    'new_actor' => $new_actor,
                    'confidence' => $confidence,
                    'reason' => $reason,
                    'source' => $source,
                    'dirty_type' => $dirty_check['type']
                ];
            }
            
            return ['success' => false, 'error' => 'فشل التحديث في قاعدة البيانات'];
        }
        
        return ['success' => false, 'error' => 'المحرك الموحد غير متوفر'];
    }
    
    /**
     * معالجة دفعة من الأحداث
     * 
     * @param int $batch_size حجم الدفعة
     * @param int $offset الإزاحة
     * @param array $filters فلاتر اختيارية
     * @return array نتائج المعالجة
     */
    public function process_batch(int $batch_size = 50, int $offset = 0, array $filters = []): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'so_news_events';

        $batch_size = max(10, min(200, (int) $batch_size));
        $cursor = isset($filters['cursor']) ? (int) $filters['cursor'] : max(0, (int) $offset);

        $results = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => [],
            'next_cursor' => $cursor,
            'done' => 0,
            'scanned' => 0
        ];

        $max_scan = max($batch_size * 6, 100);
        $query = $wpdb->prepare(
            "SELECT id, title, actor_v2, content, field_data, war_data, source_name
             FROM {$table_name}
             WHERE id > %d
             ORDER BY id ASC
             LIMIT %d",
            $cursor,
            $max_scan
        );

        $events = $wpdb->get_results($query, ARRAY_A);
        if (empty($events)) {
            $results['done'] = 1;
            return $results;
        }

        foreach ($events as $event) {
            $results['scanned']++;
            $event_id = (int) ($event['id'] ?? 0);
            $results['next_cursor'] = $event_id;

            $dirty_check = $this->is_dirty_actor((string) ($event['actor_v2'] ?? ''));

            if (!empty($filters['dirty_type']) && !in_array($dirty_check['type'], (array) $filters['dirty_type'], true)) {
                $results['skipped']++;
                continue;
            }

            if (!$dirty_check['is_dirty']) {
                $results['skipped']++;
                continue;
            }

            $results['processed']++;
            $reanalysis = $this->reanalyze_event($event_id);

            if (!empty($reanalysis['error'])) {
                $results['errors']++;
                $results['details'][] = [
                    'id' => $event_id,
                    'status' => 'error',
                    'error' => $reanalysis['error']
                ];
            } elseif (!empty($reanalysis['skipped'])) {
                $results['skipped']++;
                $results['details'][] = [
                    'id' => $event_id,
                    'status' => 'skipped',
                    'reason' => $reanalysis['reason'] ?? ''
                ];
            } elseif (!empty($reanalysis['updated'])) {
                $results['updated']++;
                $results['details'][] = [
                    'id' => $event_id,
                    'status' => 'updated',
                    'old_actor' => $reanalysis['old_actor'] ?? '',
                    'new_actor' => $reanalysis['new_actor'] ?? '',
                    'confidence' => $reanalysis['confidence'] ?? 0
                ];
            }

            if ($results['processed'] >= $batch_size) {
                break;
            }
        }

        $results['done'] = count($events) < $max_scan ? 1 : 0;
        return $results;
    }
    
    /**
     * تسجيل عملية تنظيف
     * 
     * @param int $event_id معرف الحدث
     * @param string $old_actor الفاعل القديم
     * @param string $new_actor الفاعل الجديد
     * @param int $confidence مستوى الثقة
     * @param string $reason السبب
     * @param string $source المصدر
     */
    private function log_cleanup(int $event_id, string $old_actor, string $new_actor, int $confidence, string $reason, string $source) {
        global $wpdb;
        
        // التأكد من وجود جدول السجلات
        $this->ensure_log_table_exists();
        
        $wpdb->insert(
            $wpdb->prefix . 'so_intel_cleanup_log',
            [
                'event_id' => $event_id,
                'old_actor' => $old_actor,
                'new_actor' => $new_actor,
                'confidence' => $confidence,
                'reason' => $reason,
                'source' => $source,
                'cleanup_type' => 'local_intelligence',
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );
    }
    
    /**
     * التأكد من وجود جدول السجلات
     */
    public function ensure_log_table_exists() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'so_intel_cleanup_log';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id bigint(20) UNSIGNED NOT NULL,
            old_actor varchar(255) DEFAULT '',
            new_actor varchar(255) DEFAULT '',
            confidence int(3) DEFAULT 0,
            reason text,
            source varchar(100) DEFAULT '',
            cleanup_type varchar(50) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY cleanup_type (cleanup_type),
            KEY created_at (created_at)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * الحصول على إحصائيات التنظيف
     * 
     * @param int $days عدد الأيام الأخيرة
     * @return array الإحصائيات
     */
    public function get_cleanup_stats(int $days = 30): array {
        global $wpdb;
        
        $this->ensure_log_table_exists();
        
        $table_name = $wpdb->prefix . 'so_intel_cleanup_log';
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_cleanups,
                COUNT(DISTINCT event_id) as unique_events,
                AVG(confidence) as avg_confidence,
                SUM(CASE WHEN cleanup_type = 'local_intelligence' THEN 1 ELSE 0 END) as local_intel_cleanups
             FROM {$table_name}
             WHERE created_at >= %s",
            $date_from
        ), ARRAY_A);
        
        // أنواع التلوث الأكثر شيوعاً
        $top_types = $wpdb->get_results($wpdb->prepare(
            "SELECT old_actor, COUNT(*) as count
             FROM {$table_name}
             WHERE created_at >= %s
             GROUP BY old_actor
             ORDER BY count DESC
             LIMIT 10",
            $date_from
        ), ARRAY_A);
        
        // المصادر الأكثر تنظيفاً
        $top_sources = $wpdb->get_results($wpdb->prepare(
            "SELECT source, COUNT(*) as count
             FROM {$table_name}
             WHERE created_at >= %s
             GROUP BY source
             ORDER BY count DESC
             LIMIT 10",
            $date_from
        ), ARRAY_A);
        
        return [
            'summary' => $stats ?: [],
            'top_dirty_actors' => $top_types ?: [],
            'top_sources' => $top_sources ?: [],
            'period_days' => $days
        ];
    }
}
