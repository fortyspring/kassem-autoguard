<?php
/**
 * Duplicate Detection and Cleanup Service
 * 
 * Handles detection and removal of duplicate news events
 * 
 * @package OSINT_Pro/Services
 */

namespace SO\Services;

use SO\Utils\TextCleaner;

class DuplicateCleaner {
    
    /**
     * Option names for state management
     */
    const OPT_CURSOR = 'so_duplicate_cleanup_cursor';
    const OPT_SEEN = 'so_duplicate_cleanup_seen';
    const OPT_PROGRESS = 'so_duplicate_cleanup_progress';
    
    /**
     * Process a batch of duplicates
     * 
     * @param int $batch Batch size (5-2000)
     * @param bool $reset Reset state
     * @return array Progress data
     */
    public static function processBatch($batch = 10, $reset = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'so_news_events';
        
        // Validate permissions
        if (!current_user_can('manage_options')) {
            return ['error' => 'forbidden'];
        }
        
        // Handle reset
        if ($reset) {
            self::resetState();
        }
        
        // Get current state
        $cursor = (int) get_option(self::OPT_CURSOR, 0);
        $batch = max(5, min(2000, intval($batch)));
        
        // Get total count
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        
        // Fetch rows
        $rows = self::fetchRows($cursor, $batch);
        
        // Get seen cache
        $seen = get_option(self::OPT_SEEN, []);
        if (!is_array($seen)) $seen = [];
        
        // Process rows
        $result = self::scanRows($rows, $seen);
        $delete_ids = $result['delete_ids'];
        $seen = $result['seen'];
        $last_id = $result['last_id'];
        $scanned = count($rows);
        
        // Delete duplicates using prepared statement for SQL injection protection
        if (!empty($delete_ids)) {
            $placeholders = implode(',', array_fill(0, count($delete_ids), '%d'));
            $sql = $wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $delete_ids);
            $wpdb->query($sql);
        }
        
        // Keep seen cache bounded
        if (count($seen) > 4000) {
            $seen = array_slice($seen, -4000);
        }
        
        // Calculate next state
        $next_cursor = !empty($rows) ? $last_id : 0;
        $done = empty($rows) || count($rows) < $batch || $next_cursor <= 0;
        
        // Update state
        update_option(self::OPT_CURSOR, $done ? 0 : $next_cursor, false);
        update_option(self::OPT_SEEN, $done ? [] : $seen, false);
        
        // Calculate progress
        $progress = self::calculateProgress($total, $scanned, count($delete_ids), $done, $batch, $next_cursor);
        update_option(self::OPT_PROGRESS, $progress, false);
        
        return $progress;
    }
    
    /**
     * Reset cleanup state
     */
    public static function resetState() {
        update_option(self::OPT_CURSOR, 0, false);
        update_option(self::OPT_SEEN, [], false);
    }
    
    /**
     * Fetch rows from database
     * 
     * @param int $cursor Current cursor position
     * @param int $limit Number of rows to fetch
     * @return array Rows
     */
    private static function fetchRows($cursor, $limit) {
        global $wpdb;
        $table = $wpdb->prefix . 'so_news_events';
        
        if ($cursor > 0) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT id,title,actor_v2,region,intel_type,event_timestamp,title_fingerprint,image_url
                 FROM {$table}
                 WHERE id < %d
                 ORDER BY id DESC
                 LIMIT %d",
                $cursor,
                $limit
            ), ARRAY_A);
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id,title,actor_v2,region,intel_type,event_timestamp,title_fingerprint,image_url
             FROM {$table}
             ORDER BY id DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);
    }
    
    /**
     * Scan rows for duplicates
     * 
     * @param array $rows Rows to scan
     * @param array $seen Seen cache
     * @return array Results with delete_ids, seen, last_id
     */
    private static function scanRows($rows, $seen) {
        $delete_ids = [];
        $last_id = 0;
        
        foreach ($rows as $row) {
            $last_id = (int)($row['id'] ?? 0);
            
            // Extract fields
            $title = (string)($row['title'] ?? '');
            $actor = (string)($row['actor_v2'] ?? '');
            $region = (string)($row['region'] ?? '');
            $intel = (string)($row['intel_type'] ?? '');
            $tfp = (string)($row['title_fingerprint'] ?? '');
            $image = trim((string)($row['image_url'] ?? ''));
            
            // Build fingerprint if missing
            if ($tfp === '') {
                $tfp = so_build_title_fingerprint($title);
            }
            
            // Build signature
            $sig = so_duplicate_candidate_signature($title, $actor, $region, $intel);
            
            // Time bucket (4-hour windows)
            $bucket = floor(((int)$row['event_timestamp']) / 14400);
            
            // Create key
            $key = ($tfp ?: $sig) . '|' . $bucket;
            
            // Check for duplicates
            $is_dup = self::isDuplicate($key, $image, $title, $region, $seen);
            
            if ($is_dup) {
                $delete_ids[] = (int)$row['id'];
                continue;
            }
            
            // Add to seen cache
            $seen[$key] = ['title' => $title, 'region' => $region, 'actor' => $actor];
            if ($image !== '') {
                $seen['img:' . $image] = ['title' => $title, 'region' => $region, 'actor' => $actor];
            }
        }
        
        return [
            'delete_ids' => $delete_ids,
            'seen' => $seen,
            'last_id' => $last_id
        ];
    }
    
    /**
     * Check if a row is a duplicate
     * 
     * @param string $key Unique key
     * @param string $image Image URL
     * @param string $title Event title
     * @param string $region Region
     * @param array $seen Seen cache
     * @return bool Is duplicate
     */
    private static function isDuplicate($key, $image, $title, $region, $seen) {
        // Check image hash
        if ($image !== '' && isset($seen['img:' . $image])) {
            return true;
        }
        
        // Check exact key match
        if (isset($seen[$key])) {
            return true;
        }
        
        // Check fuzzy title match
        foreach ($seen as $prevKey => $prev) {
            if (!is_array($prev) || empty($prev['title'])) continue;
            if (($prev['region'] ?? '') !== $region) continue;
            
            $a = TextCleaner::normalizeTitleForDedupe($title);
            $b = TextCleaner::normalizeTitleForDedupe($prev['title']);
            
            similar_text($a, $b, $sim);
            if ($sim >= 92) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Calculate progress statistics
     * 
     * @param int $total Total events
     * @param int $scanned Scanned in this batch
     * @param int $deleted Deleted in this batch
     * @param bool $done Is complete
     * @param int $batch Batch size
     * @param int $next_cursor Next cursor
     * @return array Progress data
     */
    private static function calculateProgress($total, $scanned, $deleted, $done, $batch, $next_cursor) {
        $prev_progress = get_option(self::OPT_PROGRESS, []);
        $processed_before = (!is_array($prev_progress)) ? 0 : (int)($prev_progress['processed'] ?? 0);
        $processed_now = $processed_before + $scanned;
        
        $deleted_total_before = (!is_array($prev_progress)) ? 0 : (int)($prev_progress['deleted_total'] ?? 0);
        
        return [
            'time' => time(),
            'batch' => $batch,
            'processed' => min($total, $processed_now),
            'total' => $total,
            'percent' => $total > 0 ? (int) round((min($total, $processed_now) / $total) * 100) : 100,
            'deleted_now' => $deleted,
            'deleted_total' => $deleted_total_before + $deleted,
            'done' => $done ? 1 : 0,
            'running' => $done ? 0 : 1,
            'next_cursor' => $done ? 0 : $next_cursor
        ];
    }
    
    /**
     * AJAX handler for batch processing
     */
    public static function ajaxBatch() {
        check_ajax_referer('so_ajax_v13', 'nonce');
        
        $batch = isset($_POST['batch']) ? max(5, min(2000, intval($_POST['batch']))) : 10;
        $reset = !empty($_POST['reset']);
        
        $result = self::processBatch($batch, $reset);
        
        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']], 403);
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX handler for reset
     */
    public static function ajaxReset() {
        check_ajax_referer('so_ajax_v13', 'nonce');
        
        global $wpdb;
        $table = $wpdb->prefix . 'so_news_events';
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        
        self::resetState();
        
        wp_send_json_success([
            'time' => time(),
            'batch' => 0,
            'processed' => 0,
            'total' => $total,
            'percent' => 0,
            'deleted_now' => 0,
            'deleted_total' => 0,
            'done' => 0,
            'running' => 0,
            'next_cursor' => 0
        ]);
    }
}
