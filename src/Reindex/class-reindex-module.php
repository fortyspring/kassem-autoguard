<?php
/**
 * Reindex Module
 * 
 * Handles batch reindexing, duplicate cleaning, and archive management:
 * - Batch reprocessing of events
 * - Duplicate detection and removal
 * - Archive cleanup and optimization
 * - Data integrity checks
 * 
 * @package OSINT_PRO/Reindex
 * @version 1.0.0
 */

namespace OSINT_PRO\Reindex;

use OSINT_PRO\Core\Interfaces\Module_Interface;
use OSINT_PRO\Core\Utilities;

if (!defined('ABSPATH')) {
    exit;
}

class Reindex_Module implements Module_Interface {
    
    /**
     * Module name
     */
    const NAME = 'reindex';
    
    /**
     * Batch size for processing
     */
    const DEFAULT_BATCH_SIZE = 100;
    
    /**
     * Maximum batch size
     */
    const MAX_BATCH_SIZE = 500;
    
    /**
     * Progress transient prefix
     */
    const PROGRESS_PREFIX = 'osint_reindex_progress_';
    
    /**
     * Initialize module
     */
    public function init(): void {
        $this->register_ajax_handlers();
        $this->register_cron_jobs();
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers(): void {
        add_action('wp_ajax_osint_route', [$this, 'handle_ajax_request']);
    }
    
    /**
     * Register cron jobs
     */
    private function register_cron_jobs(): void {
        // Weekly cleanup
        if (!wp_next_scheduled('osint_weekly_cleanup')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', 'osint_weekly_cleanup');
        }
        
        add_action('osint_weekly_cleanup', [$this, 'run_scheduled_cleanup']);
    }
    
    /**
     * Handle AJAX requests via router
     */
    public function handle_ajax_request(array $request): array {
        $endpoint = $request['endpoint'] ?? '';
        $parts = explode('.', $endpoint);
        
        if ($parts[0] !== 'reindex') {
            return ['success' => false, 'message' => 'Not a reindex endpoint'];
        }
        
        $action = $parts[1] ?? '';
        
        switch ($action) {
            case 'start_batch':
                return $this->ajax_start_batch($request);
            case 'process_batch':
                return $this->ajax_process_batch($request);
            case 'get_progress':
                return $this->ajax_get_progress($request);
            case 'cancel_operation':
                return $this->ajax_cancel_operation($request);
            case 'cleanup_duplicates':
                return $this->ajax_cleanup_duplicates($request);
            case 'get_stats':
                return $this->ajax_get_stats($request);
            default:
                return ['success' => false, 'message' => 'Unknown action'];
        }
    }
    
    /**
     * Start batch reindex via AJAX
     */
    private function ajax_start_batch(array $request): array {
        try {
            $batch_size = min(
                intval($request['params']['batch_size'] ?? self::DEFAULT_BATCH_SIZE),
                self::MAX_BATCH_SIZE
            );
            
            $start_id = intval($request['params']['start_id'] ?? 0);
            $end_id = intval($request['params']['end_id'] ?? 0);
            $operation = sanitize_text_field($request['params']['operation'] ?? 'reclassify');
            
            global $wpdb;
            $table = $wpdb->prefix . 'so_news_events';
            
            // If no end_id provided, get max ID
            if (!$end_id) {
                $end_id = $wpdb->get_var("SELECT MAX(id) FROM {$table}");
            }
            
            $total_count = $end_id - $start_id;
            
            if ($total_count <= 0) {
                throw new \Exception('لا توجد أحداث للمعالجة');
            }
            
            // Store progress
            $progress_key = self::PROGRESS_PREFIX . $operation;
            set_transient($progress_key, [
                'status' => 'running',
                'start_id' => $start_id,
                'end_id' => $end_id,
                'current_id' => $start_id,
                'processed' => 0,
                'errors' => 0,
                'started_at' => date('Y-m-d H:i:s'),
                'operation' => $operation,
            ], HOUR_IN_SECONDS);
            
            return [
                'success' => true,
                'data' => [
                    'total' => $total_count,
                    'batch_size' => $batch_size,
                    'start_id' => $start_id,
                    'end_id' => $end_id,
                    'operation' => $operation,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Process batch via AJAX
     */
    private function ajax_process_batch(array $request): array {
        try {
            $batch_size = min(
                intval($request['params']['batch_size'] ?? self::DEFAULT_BATCH_SIZE),
                self::MAX_BATCH_SIZE
            );
            
            $operation = sanitize_text_field($request['params']['operation'] ?? 'reclassify');
            
            $progress_key = self::PROGRESS_PREFIX . $operation;
            $progress = get_transient($progress_key);
            
            if (!$progress || $progress['status'] !== 'running') {
                throw new \Exception('لا توجد عملية جارية أو تم إلغاؤها');
            }
            
            $current_id = $progress['current_id'];
            $end_id = $progress['end_id'];
            
            global $wpdb;
            $table = $wpdb->prefix . 'so_news_events';
            
            // Fetch batch
            $events = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT %d",
                $current_id,
                $end_id,
                $batch_size
            ), ARRAY_A);
            
            if (empty($events)) {
                // Completed
                set_transient($progress_key, [
                    ...$progress,
                    'status' => 'completed',
                    'completed_at' => date('Y-m-d H:i:s'),
                ], HOUR_IN_SECONDS);
                
                return [
                    'success' => true,
                    'data' => [
                        'completed' => true,
                        'processed' => $progress['processed'],
                        'errors' => $progress['errors'],
                    ],
                ];
            }
            
            // Process based on operation
            $processed = 0;
            $errors = 0;
            $last_id = $current_id;
            
            switch ($operation) {
                case 'reclassify':
                    list($processed, $errors, $last_id) = $this->process_reclassify_batch($events);
                    break;
                case 'clean_duplicates':
                    list($processed, $errors, $last_id) = $this->process_duplicate_cleanup($events);
                    break;
                case 'fix_encoding':
                    list($processed, $errors, $last_id) = $this->process_encoding_fix($events);
                    break;
                default:
                    throw new \Exception('عملية غير معروفة');
            }
            
            // Update progress
            $progress['current_id'] = $last_id;
            $progress['processed'] += $processed;
            $progress['errors'] += $errors;
            
            set_transient($progress_key, $progress, HOUR_IN_SECONDS);
            
            return [
                'success' => true,
                'data' => [
                    'completed' => false,
                    'processed_in_batch' => $processed,
                    'errors_in_batch' => $errors,
                    'total_processed' => $progress['processed'],
                    'total_errors' => $progress['errors'],
                    'next_id' => $last_id,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get progress via AJAX
     */
    private function ajax_get_progress(array $request): array {
        try {
            $operation = sanitize_text_field($request['params']['operation'] ?? 'reclassify');
            $progress_key = self::PROGRESS_PREFIX . $operation;
            
            $progress = get_transient($progress_key);
            
            if (!$progress) {
                return [
                    'success' => true,
                    'data' => [
                        'status' => 'idle',
                        'message' => 'لا توجد عملية جارية',
                    ],
                ];
            }
            
            $total = $progress['end_id'] - $progress['start_id'];
            $current = $progress['current_id'] - $progress['start_id'];
            $percent = $total > 0 ? round(($current / $total) * 100, 2) : 0;
            
            return [
                'success' => true,
                'data' => [
                    'status' => $progress['status'],
                    'operation' => $progress['operation'],
                    'processed' => $progress['processed'],
                    'errors' => $progress['errors'],
                    'percent' => $percent,
                    'started_at' => $progress['started_at'] ?? null,
                    'completed_at' => $progress['completed_at'] ?? null,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Cancel operation via AJAX
     */
    private function ajax_cancel_operation(array $request): array {
        try {
            $operation = sanitize_text_field($request['params']['operation'] ?? 'reclassify');
            $progress_key = self::PROGRESS_PREFIX . $operation;
            
            $progress = get_transient($progress_key);
            
            if (!$progress) {
                throw new \Exception('لا توجد عملية لإلغائها');
            }
            
            $progress['status'] = 'cancelled';
            $progress['cancelled_at'] = date('Y-m-d H:i:s');
            
            set_transient($progress_key, $progress, HOUR_IN_SECONDS);
            
            return [
                'success' => true,
                'message' => 'تم إلغاء العملية',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Cleanup duplicates via AJAX
     */
    private function ajax_cleanup_duplicates(array $request): array {
        try {
            $batch_size = min(
                intval($request['params']['batch_size'] ?? self::DEFAULT_BATCH_SIZE),
                self::MAX_BATCH_SIZE
            );
            
            $dry_run = !empty($request['params']['dry_run']);
            
            global $wpdb;
            $table = $wpdb->prefix . 'so_news_events';
            
            // Find potential duplicates (same title within time window)
            $duplicates_query = "
                SELECT e1.id as keep_id, GROUP_CONCAT(e2.id) as remove_ids
                FROM {$table} e1
                INNER JOIN {$table} e2 ON e1.title = e2.title 
                    AND e1.event_timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    AND e2.event_timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    AND e1.id < e2.id
                GROUP BY e1.id
                LIMIT %d
            ";
            
            $results = $wpdb->get_results($wpdb->prepare($duplicates_query, $batch_size), ARRAY_A);
            
            if (empty($results)) {
                return [
                    'success' => true,
                    'data' => [
                        'found' => 0,
                        'removed' => 0,
                        'message' => 'لا توجد تكرارات',
                    ],
                ];
            }
            
            $total_found = count($results);
            $total_removed = 0;
            $removed_ids = [];
            
            foreach ($results as $result) {
                $remove_ids = explode(',', $result['remove_ids']);
                
                if ($dry_run) {
                    $total_removed += count($remove_ids);
                } else {
                    $placeholders = implode(',', array_fill(0, count($remove_ids), '%d'));
                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$table} WHERE id IN ({$placeholders})",
                        ...$remove_ids
                    ));
                    
                    $total_removed += count($remove_ids);
                    $removed_ids = array_merge($removed_ids, $remove_ids);
                }
            }
            
            return [
                'success' => true,
                'data' => [
                    'found' => $total_found,
                    'removed' => $total_removed,
                    'removed_ids' => $dry_run ? [] : $removed_ids,
                    'dry_run' => $dry_run,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get stats via AJAX
     */
    private function ajax_get_stats(array $request): array {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'so_news_events';
            
            // Total events
            $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            
            // Events by threat level
            $by_threat = $wpdb->get_results("
                SELECT 
                    CASE 
                        WHEN threat_score >= 90 THEN 'critical'
                        WHEN threat_score >= 70 THEN 'high'
                        WHEN threat_score >= 40 THEN 'medium'
                        ELSE 'low'
                    END as level,
                    COUNT(*) as count
                FROM {$table}
                GROUP BY level
            ", ARRAY_A);
            
            // Events without classification
            $unclassified = $wpdb->get_var("
                SELECT COUNT(*) FROM {$table} 
                WHERE primary_actor IS NULL OR primary_actor = ''
            ");
            
            // Potential duplicates (same title in last 7 days)
            $potential_duplicates = $wpdb->get_var("
                SELECT COUNT(DISTINCT e2.id)
                FROM {$table} e1
                INNER JOIN {$table} e2 ON e1.title = e2.title 
                    AND e1.event_timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    AND e2.event_timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    AND e1.id < e2.id
            ");
            
            // Encoding issues (mojibake detection)
            $encoding_issues = $wpdb->get_var("
                SELECT COUNT(*) FROM {$table}
                WHERE title LIKE '%Ø§%' OR title LIKE '%Ù†%'
            ");
            
            return [
                'success' => true,
                'data' => [
                    'total_events' => intval($total),
                    'by_threat_level' => $by_threat,
                    'unclassified' => intval($unclassified),
                    'potential_duplicates' => intval($potential_duplicates),
                    'encoding_issues' => intval($encoding_issues),
                    'last_updated' => date('Y-m-d H:i:s'),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Process reclassify batch
     * 
     * @param array $events Events to process
     * @return array [processed, errors, last_id]
     */
    private function process_reclassify_batch(array $events): array {
        global $wpdb;
        $table = $wpdb->prefix . 'so_news_events';
        
        $processed = 0;
        $errors = 0;
        $last_id = 0;
        
        // Get intelligence module
        $intelligence = new Intelligence_Module();
        
        foreach ($events as $event) {
            try {
                $classified = $intelligence->classify_event($event);
                
                $wpdb->update($table, $classified, ['id' => $event['id']]);
                
                $processed++;
            } catch (\Exception $e) {
                error_log("Reindex error for event {$event['id']}: " . $e->getMessage());
                $errors++;
            }
            
            $last_id = $event['id'];
        }
        
        return [$processed, $errors, $last_id];
    }
    
    /**
     * Process duplicate cleanup
     * 
     * @param array $events Events to process
     * @return array [processed, errors, last_id]
     */
    private function process_duplicate_cleanup(array $events): array {
        global $wpdb;
        $table = $wpdb->prefix . 'so_news_events';
        
        $processed = 0;
        $errors = 0;
        $last_id = 0;
        
        // Get intelligence module for duplicate detection
        $intelligence = new Intelligence_Module();
        
        $ids_to_remove = [];
        
        foreach ($events as $event) {
            try {
                $duplicates = $intelligence->find_duplicates($event, 30); // 30 min window
                
                foreach ($duplicates as $dup) {
                    if ($dup['similarity'] >= 0.95 && $dup['event']['id'] != $event['id']) {
                        $ids_to_remove[] = $dup['event']['id'];
                    }
                }
                
                $processed++;
            } catch (\Exception $e) {
                error_log("Duplicate cleanup error for event {$event['id']}: " . $e->getMessage());
                $errors++;
            }
            
            $last_id = $event['id'];
        }
        
        // Remove duplicates
        if (!empty($ids_to_remove)) {
            $ids_to_remove = array_unique($ids_to_remove);
            $placeholders = implode(',', array_fill(0, count($ids_to_remove), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE id IN ({$placeholders})",
                ...$ids_to_remove
            ));
        }
        
        return [$processed, $errors, $last_id];
    }
    
    /**
     * Process encoding fix
     * 
     * @param array $events Events to process
     * @return array [processed, errors, last_id]
     */
    private function process_encoding_fix(array $events): array {
        global $wpdb;
        $table = $wpdb->prefix . 'so_news_events';
        
        $processed = 0;
        $errors = 0;
        $last_id = 0;
        
        foreach ($events as $event) {
            try {
                $fixed = [
                    'title' => Utilities::fix_mojibake($event['title'] ?? ''),
                    'content' => Utilities::fix_mojibake($event['content'] ?? ''),
                ];
                
                $wpdb->update($table, $fixed, ['id' => $event['id']]);
                
                $processed++;
            } catch (\Exception $e) {
                error_log("Encoding fix error for event {$event['id']}: " . $e->getMessage());
                $errors++;
            }
            
            $last_id = $event['id'];
        }
        
        return [$processed, $errors, $last_id];
    }
    
    /**
     * Run scheduled cleanup
     */
    public function run_scheduled_cleanup(): void {
        error_log('Starting scheduled weekly cleanup...');
        
        global $wpdb;
        $table = $wpdb->prefix . 'so_news_events';
        
        // Clean old transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_osint_%' AND option_value < UNIX_TIMESTAMP() - 86400");
        
        // Optimize table
        $wpdb->query("OPTIMIZE TABLE {$table}");
        
        error_log('Scheduled cleanup completed.');
    }
    
    /**
     * Get module info
     */
    public function get_info(): array {
        return [
            'name' => self::NAME,
            'label' => 'إعادة الفهرسة والتنظيف',
            'version' => '1.0.0',
            'description' => 'نظام المعالجة الجماعية وتنظيف البيانات',
            'features' => [
                'إعادة التصنيف الجماعي',
                'كشف التكرار وإزالته',
                'إصلاح الترميز',
                'تنظيف الأرشيف',
                'فحص سلامة البيانات',
                'معالجة مجدولة',
                'تقارير إحصائية',
            ],
        ];
    }
}
