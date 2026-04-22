<?php
/**
 * Beiruttime OSINT - Batch Reindexing Service
 * 
 * خدمة إعادة معالجة الأخبار القديمة لتصنيفها وفق منطق الحرب المركبة
 * وتحديث الحقول الاستخباراتية المتقدمة.
 * 
 * @package Beiruttime\OSINT\Services
 * @version 1.0.0
 */

namespace Beiruttime\OSINT\Services;

use WordPress;
use WP_Query;
use WP_CLI;

class Batch_Reindexer {
    
    /**
     * اسم الجدول
     */
    private $table_name;
    
    /**
     * سجل العمليات
     */
    private $log = [];
    
    /**
     * إحصائيات العملية
     */
    private $stats = [
        'processed' => 0,
        'updated' => 0,
        'errors' => 0,
        'start_time' => 0,
        'end_time' => 0
    ];

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'so_news_events';
    }

    /**
     * بدء عملية إعادة المعالجة
     * 
     * @param int $limit عدد الأحداث في كل دفعة
     * @param int $offset نقطة البداية
     * @param bool $dry_run تجربة بدون حفظ
     * @return array نتائج العملية
     */
    public function run_batch($limit = 100, $offset = 0, $dry_run = false) {
        global $wpdb;
        
        $this->stats['start_time'] = microtime(true);
        
        // جلب الأحداث التي لم تُحلل بعد أو تحتاج تحديث
        $events = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, title, war_data, actor_v2, region, score, osint_type, hybrid_layers 
                 FROM {$this->table_name} 
                 WHERE (hybrid_layers IS NULL OR hybrid_layers = '' OR threat_score = 0)
                 ORDER BY event_timestamp DESC 
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );

        if (empty($events)) {
            return ['status' => 'completed', 'message' => 'لا توجد أحداث للمعالجة', 'stats' => $this->stats];
        }

        // تحميل محرك التحليل الموحد مع معالجة الأخطاء
        $use_legacy = false;
        $hybrid_engine = null;
        
        if (!class_exists('Beiruttime\OSINT\Services\HybridWarfareEngine')) {
            $hybrid_file = __DIR__ . '/class-hybrid-warfare.php';
            if (file_exists($hybrid_file)) {
                require_once $hybrid_file;
            } else {
                $use_legacy = true;
            }
        }
        
        if (!$use_legacy && class_exists('Beiruttime\OSINT\Services\HybridWarfareEngine')) {
            try {
                $hybrid_engine = \Beiruttime\OSINT\Services\HybridWarfareEngine::instance();
            } catch (\Exception $e) {
                error_log("Beiruttime OSINT: Failed to init HybridWarfareEngine: " . $e->getMessage());
                $use_legacy = true;
            }
        }

        foreach ($events as $event) {
            try {
                $this->stats['processed']++;

                // تحضير البيانات للتحليل
                $event_data = [
                    'title' => $event['title'],
                    'war_data' => $event['war_data'],
                    'actor_v2' => $event['actor_v2'],
                    'region' => $event['region'],
                    'score' => $event['score']
                ];

                $layers_json = '';
                $primary_layer = '';
                $multi_domain = 0;

                // 1. محاولة استخدام المحرك الجديد
                if (!$use_legacy && $hybrid_engine) {
                    try {
                        $hybrid_analysis = $hybrid_engine->analyzeLayers($event_data);
                        $layers_json = !empty($hybrid_analysis['layers']) ? json_encode($hybrid_analysis['layers'], JSON_UNESCAPED_UNICODE) : '';
                        $primary_layer = $hybrid_analysis['primary_layer'] ?? '';
                        $multi_domain = round($hybrid_analysis['composite_score'] ?? 0, 3);
                    } catch (\Exception $e) {
                        error_log("Beiruttime OSINT: Hybrid engine failed for event {$event['id']}: " . $e->getMessage());
                        $use_legacy = true;
                    }
                }
                
                // 2. إذا فشل المحرك أو كانت النتيجة فارغة، نستخدم fallback داخلي موحد
                if ($use_legacy || empty($layers_json)) {
                    $layers_json = $this->legacy_classify_hybrid_layers($event_data);
                    $primary_layer = $this->legacy_get_primary_layer($layers_json);
                    $multi_domain = $this->legacy_calculate_multi_domain($layers_json);
                }

                
                // 2. حساب Scores المتقدم
                $scores = $this->calculate_scores($event_data, $layers_json);
                
                // 3. استخراج شبكة الفاعلين
                $actor_network = $this->extract_actor_network($event_data);

                if (!$dry_run) {
                    // تحديث السجل في قاعدة البيانات
                    $update_data = [
                        'hybrid_layers' => $layers_json,
                        'osint_type' => $primary_layer,
                        'multi_domain_score' => $multi_domain,
                        'threat_score' => (int)$scores['threat_score'],
                        'escalation_score' => (int)$scores['escalation_score'],
                        'confidence_score' => (int)$scores['confidence_score'],
                        'risk_level' => $scores['risk_level'],
                        'primary_actor' => $actor_network['primary_actor'] ?? '',
                        'actor_network' => $actor_network['actor_network'] ?? null,
                        'reindexed_at' => current_time('mysql')
                    ];

                    $wpdb->update(
                        $this->table_name,
                        $update_data,
                        ['id' => $event['id']],
                        [
                            'hybrid_layers' => '%s',
                            'osint_type' => '%s',
                            'multi_domain_score' => '%f',
                            'threat_score' => '%d',
                            'escalation_score' => '%d',
                            'confidence_score' => '%d',
                            'risk_level' => '%s',
                            'primary_actor' => '%s',
                            'actor_network' => '%s',
                            'reindexed_at' => '%s'
                        ],
                        ['id' => '%d']
                    );

                    if ($wpdb->last_error) {
                        throw new \Exception($wpdb->last_error);
                    }
                    
                    $this->stats['updated']++;
                } else {
                    $this->stats['updated']++; // في وضع التجربة نعتبرها محدثة
                }

            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->log[] = [
                    'event_id' => $event['id'],
                    'error' => $e->getMessage(),
                    'time' => current_time('mysql')
                ];
                
                error_log("Beiruttime OSINT Reindex Error [Event ID: {$event['id']}]: " . $e->getMessage());
            }
        }

        $this->stats['end_time'] = microtime(true);
        
        return [
            'status' => 'partial',
            'message' => sprintf('تم معالجة %d حدث، تحديث %d، أخطاء %d', 
                $this->stats['processed'], 
                $this->stats['updated'], 
                $this->stats['errors']
            ),
            'stats' => $this->stats,
            'logs' => array_slice($this->log, -10) // آخر 10 أخطاء فقط
        ];
    }

    /**
     * حساب مؤشراتScores للحدث
     */
    private function calculate_scores($event_data, $layers_json) {
        // حساب Scores بناءً على الطبقات النشطة
        $layers_count = !empty($layers_json) ? count(json_decode($layers_json, true)) : 0;
        
        // كلمات مفتاحية لزيادة التهديد
        $high_threat_keywords = ['استهداف', 'اغتيال', 'تصفية', 'تدمير', 'قصف', 'غارة'];
        $escalation_keywords = ['تصعيد', 'تهديد', 'إنذار', 'حرب', 'مواجهة', 'انتقام'];
        
        $title = $event_data['title'] ?? '';
        $threat_bonus = 0;
        foreach ($high_threat_keywords as $keyword) {
            if (stripos($title, $keyword) !== false) {
                $threat_bonus += 10;
            }
        }
        
        $escalation_bonus = 0;
        foreach ($escalation_keywords as $keyword) {
            if (stripos($title, $keyword) !== false) {
                $escalation_bonus += 15;
            }
        }
        
        $threat_score = min(100, ($layers_count * 12) + $threat_bonus);
        $escalation_score = min(100, ($layers_count * 10) + $escalation_bonus);
        $confidence_score = 70 + min(20, $layers_count * 3); // زيادة الثقة مع تعدد المصادر
        
        return [
            'threat_score' => $threat_score,
            'escalation_score' => $escalation_score,
            'confidence_score' => $confidence_score,
            'risk_level' => $threat_score > 70 ? 'حرج' : ($threat_score > 40 ? 'متوسط' : 'منخفض'),
            'multi_domain_score' => $layers_count * 0.12
        ];
    }

    /**
     * دالة مساعدة لتصنيف الطبقات بالطريقة القديمة
     */
    private function legacy_classify_hybrid_layers($event_data) {
        $text = ($event_data['title'] ?? '') . ' ' . ($event_data['war_data'] ?? '');
        $layers = [];
        
        $keywords_map = [
            'military' => ['غارة', 'قصف', 'صاروخ', 'اشتباك', 'توغل', 'ضربة', 'دبابة', 'طائرة'],
            'security' => ['اعتقال', 'مداهمة', 'تفكيك', 'خلية', 'أمن', 'حاجز'],
            'cyber' => ['اختراق', 'سيبراني', 'تسريب', 'قرصنة', 'بيانات'],
            'political' => ['تصريح', 'قرار', 'عقوبة', 'اتفاق', 'زيارة', 'وفد'],
            'economic' => ['نفط', 'غاز', 'ميناء', 'اقتصاد', 'سوق', 'عملة'],
            'social' => ['احتجاج', 'تحريض', 'تعبئة', 'شارع', 'مجتمع'],
            'media_psychological' => ['دعاية', 'تضليل', 'إعلام', 'رواية'],
            'energy' => ['كهرباء', 'محطة', 'طاقة', 'وقود', 'مصفاة'],
            'geostrategic' => ['مضيق', 'ممر', 'قاعدة', 'استراتيجي', 'حدود']
        ];
        
        foreach ($keywords_map as $layer => $keywords) {
            $matches = [];
            foreach ($keywords as $keyword) {
                if (mb_stripos($text, $keyword) !== false) {
                    $matches[] = $keyword;
                }
            }
            if (!empty($matches)) {
                $layers[$layer] = [
                    'name_ar' => $this->get_layer_name_ar($layer),
                    'score' => round(count($matches) / count($keywords), 3),
                    'matches' => $matches
                ];
            }
        }
        
        return !empty($layers) ? json_encode($layers, JSON_UNESCAPED_UNICODE) : '';
    }

    private function get_layer_name_ar($layer) {
        $names = [
            'military' => 'الطبقة العسكرية',
            'security' => 'الطبقة الأمنية',
            'cyber' => 'الطبقة السيبرانية',
            'political' => 'الطبقة السياسية',
            'economic' => 'الطبقة الاقتصادية',
            'social' => 'الطبقة الاجتماعية',
            'media_psychological' => 'الطبقة الإعلامية',
            'energy' => 'طبقة الطاقة',
            'geostrategic' => 'الطبقة الجيوستراتيجية'
        ];
        return $names[$layer] ?? $layer;
    }

    private function legacy_get_primary_layer($layers_json) {
        if (empty($layers_json)) return '';
        $layers = json_decode($layers_json, true);
        if (empty($layers)) return '';
        
        $max_score = 0;
        $primary = '';
        foreach ($layers as $key => $data) {
            $score = $data['score'] ?? 0;
            if ($score > $max_score) {
                $max_score = $score;
                $primary = $key;
            }
        }
        return $primary;
    }

    private function legacy_calculate_multi_domain($layers_json) {
        if (empty($layers_json)) return 0;
        $layers = json_decode($layers_json, true);
        if (empty($layers)) return 0;
        return round(min(1.0, count($layers) * 0.15), 3);
    }

    /**
     * استخراج شبكة الفاعلين من النص
     */
    private function extract_actor_network($event_data) {
        $text = ($event_data['title'] ?? '') . ' ' . ($event_data['war_data'] ?? '');
        $primary_actor = $event_data['actor_v2'] ?? '';
        
        // إذا كان الفاعل موجوداً مسبقاً نستخدمه
        if (!empty($primary_actor)) {
            return [
                'primary_actor' => $primary_actor,
                'actor_network' => json_encode(['primary' => $primary_actor], JSON_UNESCAPED_UNICODE)
            ];
        }
        
        // محاولة استخراج الفاعل من النص باستخدام كلمات مفتاحية بسيطة
        $actor_keywords = ['إسرائيلية', 'إسرائيلي', 'حزب الله', 'أمريكية', 'أمريكي', 'سورية', 'سوري', 'لبنانية', 'لبناني'];
        $detected_actor = '';
        
        foreach ($actor_keywords as $keyword) {
            if (mb_stripos($text, $keyword) !== false) {
                $detected_actor = $keyword;
                break;
            }
        }
        
        return [
            'primary_actor' => $detected_actor,
            'actor_network' => !empty($detected_actor) ? json_encode(['primary' => $detected_actor], JSON_UNESCAPED_UNICODE) : null
        ];
    }

    /**
     * معالجة كاملة للأرشيف (تعمل عبر دفعات متتالية)
     * 
     * @param int $total_limit الحد الأقصى الإجمالي
     * @param bool $dry_run
     */
    public function process_full_archive($total_limit = 10000, $dry_run = false) {
        $batch_size = 100;
        $offset = 0;
        $total_processed = 0;
        
        while ($total_processed < $total_limit) {
            $result = $this->run_batch($batch_size, $offset, $dry_run);
            
            if ($result['status'] === 'completed' || empty($result['stats']['processed'])) {
                break;
            }
            
            $total_processed += $result['stats']['processed'];
            $offset += $batch_size;
            
            // وقت راحة قصير لتجنب ضغط القاعدة
            usleep(500000); 
        }
        
        return $this->stats;
    }
}

// تكامل مع WP-CLI
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('beiruttime reindex', function($args, $assoc_args) {
        $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 1000;
        $dry_run = isset($assoc_args['dry-run']);
        
        \WP_CLI::line("بدء إعادة معالجة الأرشفة (الحد: $limit، تجربة: " . ($dry_run ? 'نعم' : 'لا') . ")...");
        
        $reindexer = new Batch_Reindexer();
        $stats = $reindexer->process_full_archive($limit, $dry_run);
        
        \WP_CLI::success("اكتملت العملية!");
        \WP_CLI::line("تمت معالجة: {$stats['processed']}");
        \WP_CLI::line("تم التحديث: {$stats['updated']}");
        \WP_CLI::line("أخطاء: {$stats['errors']}");
        \WP_CLI::line("الزمن المستغرق: " . round($stats['end_time'] - $stats['start_time'], 2) . " ثانية");
    });
}
