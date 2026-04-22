<?php
/**
 * OSINT-LB PRO - Core Utilities
 * 
 * Common utility functions used throughout the plugin
 * 
 * @package     OSINT_PRO\Core
 * @author      Production Architect
 * @since       12.0.0
 */

namespace OSINT_PRO\Core;

class Utilities {
    
    /**
     * Fix mojibake (encoding issues) in text
     * 
     * @param string $text
     * @return string
     */
    public static function fix_mojibake(string $text): string {
        if (empty($text)) {
            return $text;
        }
        
        // Fix common encoding issues
        $replacements = [
            'Ø§Ù„' => 'ال',
            'Ù‚' => 'ق',
            'Ø' => '',
            '™' => '',
            'œ' => 'ع',
            '§' => '',
        ];
        
        $result = str_replace(array_keys($replacements), array_values($replacements), $text);
        
        // Ensure UTF-8
        if (function_exists('mb_convert_encoding')) {
            $result = mb_convert_encoding($result, 'UTF-8', 'UTF-8');
        }
        
        return $result;
    }
    
    /**
     * Check if text contains Arabic characters
     * 
     * @param string $text
     * @return bool
     */
    public static function has_arabic_chars(string $text): bool {
        return (bool) preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text);
    }
    
    /**
     * Normalize a list of strings (trim, remove empty, deduplicate)
     * 
     * @param array $list
     * @return array
     */
    public static function normalize_string_list(array $list): array {
        if (empty($list)) {
            return [];
        }
        
        // Trim and filter empty
        $normalized = array_filter(array_map('trim', $list));
        
        // Deduplicate (case-insensitive)
        $seen = [];
        $unique = [];
        
        foreach ($normalized as $item) {
            $key = mb_strtolower($item);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $item;
            }
        }
        
        return array_values($unique);
    }
    
    /**
     * Sanitize text for database storage
     * 
     * @param string $text
     * @return string
     */
    public static function sanitize_for_db(string $text): string {
        global $wpdb;
        return $wpdb->_real_escape($text);
    }
    
    /**
     * Escape HTML for output
     * 
     * @param string $text
     * @return string
     */
    public static function esc_html(string $text): string {
        return esc_html($text);
    }
    
    /**
     * Escape attribute for HTML output
     * 
     * @param string $text
     * @return string
     */
    public static function esc_attr(string $text): string {
        return esc_attr($text);
    }
    
    /**
     * Generate secure nonce
     * 
     * @param string $action
     * @return string
     */
    public static function create_nonce(string $action): string {
        return wp_create_nonce($action);
    }
    
    /**
     * Verify nonce
     * 
     * @param string $nonce
     * @param string $action
     * @return bool
     */
    public static function verify_nonce(string $nonce, string $action): bool {
        return wp_verify_nonce($nonce, $action) !== false;
    }
    
    /**
     * Check user capability
     * 
     * @param string $capability
     * @return bool
     */
    public static function current_user_can(string $capability): bool {
        return current_user_can($capability);
    }
    
    /**
     * Get current user ID
     * 
     * @return int
     */
    public static function get_current_user_id(): int {
        return get_current_user_id();
    }
    
    /**
     * Log error with context
     * 
     * @param string $message
     * @param string $context
     * @param mixed $data
     * @return void
     */
    public static function log_error(string $message, string $context = 'general', $data = null): void {
        $log_message = $message;
        
        if ($data !== null) {
            $log_message .= ' | Data: ' . wp_json_encode($data);
        }
        
        osint_log($log_message, 'error', $context);
    }
    
    /**
     * Log debug message
     * 
     * @param string $message
     * @param string $context
     * @return void
     */
    public static function log_debug(string $message, string $context = 'general'): void {
        osint_log($message, 'debug', $context);
    }
    
    /**
     * Calculate time difference in human readable format
     * 
     * @param string|int $from
     * @param string|int $to
     * @return string
     */
    public static function human_time_diff($from, $to = ''): string {
        if (empty($to)) {
            $to = time();
        }
        
        return human_time_diff($from, $to);
    }
    
    /**
     * Format timestamp to MySQL datetime
     * 
     * @param int $timestamp
     * @return string
     */
    public static function format_mysql_datetime(int $timestamp): string {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
    
    /**
     * Parse MySQL datetime to timestamp
     * 
     * @param string $datetime
     * @return int
     */
    public static function parse_mysql_datetime(string $datetime): int {
        return strtotime($datetime);
    }
    
    /**
     * Batch process array
     * 
     * @param array $items
     * @param callable $callback
     * @param int $batch_size
     * @return array
     */
    public static function batch_process(array $items, callable $callback, int $batch_size = 100): array {
        $results = [];
        $batches = array_chunk($items, $batch_size);
        
        foreach ($batches as $batch) {
            $batch_results = call_user_func($callback, $batch);
            if (is_array($batch_results)) {
                $results = array_merge($results, $batch_results);
            }
        }
        
        return $results;
    }
    
    /**
     * Safe JSON decode with error handling
     * 
     * @param string $json
     * @param bool $assoc
     * @return mixed
     */
    public static function json_decode(string $json, bool $assoc = true) {
        $result = json_decode($json, $assoc);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log_error('JSON decode error: ' . json_last_error_msg(), 'utilities');
            return null;
        }
        
        return $result;
    }
    
    /**
     * Safe JSON encode with error handling
     * 
     * @param mixed $data
     * @param int $options
     * @return string|false
     */
    public static function json_encode($data, int $options = 0) {
        $result = json_encode($data, $options);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log_error('JSON encode error: ' . json_last_error_msg(), 'utilities');
            return false;
        }
        
        return $result;
    }
    
    /**
     * Extract URLs from text
     * 
     * @param string $text
     * @return array
     */
    public static function extract_urls(string $text): array {
        preg_match_all('/(https?:\/\/[^\s<>"{}|\\^`\[\]]+)/i', $text, $matches);
        return array_unique($matches[1] ?? []);
    }
    
    /**
     * Extract mentions (@username) from text
     * 
     * @param string $text
     * @return array
     */
    public static function extract_mentions(string $text): array {
        preg_match_all('/@([a-zA-Z0-9_]+)/', $text, $matches);
        return array_unique($matches[1] ?? []);
    }
    
    /**
     * Extract hashtags from text
     * 
     * @param string $text
     * @return array
     */
    public static function extract_hashtags(string $text): array {
        preg_match_all('/#([a-zA-Z0-9_\x{0600}-\x{06FF}]+)/u', $text, $matches);
        return array_unique($matches[1] ?? []);
    }
    
    /**
     * Truncate text with ellipsis
     * 
     * @param string $text
     * @param int $length
     * @param string $suffix
     * @return string
     */
    public static function truncate(string $text, int $length = 100, string $suffix = '...'): string {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        
        return mb_substr($text, 0, $length) . $suffix;
    }
    
    /**
     * Generate unique ID
     * 
     * @param string $prefix
     * @return string
     */
    public static function generate_id(string $prefix = ''): string {
        $id = uniqid($prefix, true);
        
        if ($prefix) {
            $id = $prefix . '_' . $id;
        }
        
        return $id;
    }
    
    /**
     * Hash sensitive data
     * 
     * @param string $data
     * @return string
     */
    public static function hash(string $data): string {
        return hash('sha256', $data);
    }
    
    /**
     * Compare hashes securely
     * 
     * @param string $hash1
     * @param string $hash2
     * @return bool
     */
    public static function secure_compare(string $hash1, string $hash2): bool {
        return hash_equals($hash1, $hash2);
    }
}
