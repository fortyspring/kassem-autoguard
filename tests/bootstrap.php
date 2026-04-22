<?php
/**
 * PHPUnit Bootstrap File for OSINT Pro Plugin Tests
 * 
 * This file sets up the testing environment including:
 * - WordPress function mocks
 * - Autoloader registration
 * - Test helpers and utilities
 */

// Define ABSPATH if not already defined
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wp-mock/');
}

// Register Composer autoloader if it exists
$composer_autoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composer_autoloader)) {
    require_once $composer_autoloader;
}

// Register project autoloader
require_once __DIR__ . '/../src/class-autoloader.php';

/**
 * Mock WordPress Functions
 * 
 * These functions mimic WordPress core functions for testing purposes
 */

if (!function_exists('wp_strip_all_tags')) {
    /**
     * Mock wp_strip_all_tags
     */
    function wp_strip_all_tags($string) {
        return strip_tags($string);
    }
}

if (!function_exists('sanitize_text_field')) {
    /**
     * Mock sanitize_text_field
     */
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_file_name')) {
    /**
     * Mock sanitize_file_name
     */
    function sanitize_file_name($filename) {
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    }
}

if (!function_exists('sanitize_hex_color')) {
    /**
     * Mock sanitize_hex_color
     */
    function sanitize_hex_color($color) {
        if (preg_match('/^#[a-fA-F0-9]{6}$/', $color)) {
            return $color;
        }
        return null;
    }
}

if (!function_exists('esc_url_raw')) {
    /**
     * Mock esc_url_raw
     */
    function esc_url_raw($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('html_entity_decode')) {
    /**
     * Mock html_entity_decode (already exists in PHP, but ensuring UTF-8)
     */
    function html_entity_decode_mock($text) {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('_e')) {
    /**
     * Mock translation function
     */
    function _e($text, $domain = 'default') {
        echo $text;
    }
}

if (!function_exists('__')) {
    /**
     * Mock translation function
     */
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('wp_mkdir_p')) {
    /**
     * Mock wp_mkdir_p
     */
    function wp_mkdir_p($target) {
        return mkdir($target, 0755, true);
    }
}

if (!function_exists('add_action')) {
    /**
     * Mock add_action
     */
    function add_action($tag, $function_to_add, $priority = 10, $accepted_args = 1) {
        // No-op for tests
        return true;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * Mock apply_filters
     */
    function apply_filters($tag, $value) {
        return $value;
    }
}

if (!function_exists('do_action')) {
    /**
     * Mock do_action
     */
    function do_action($tag, ...$args) {
        // No-op for tests
    }
}

if (!class_exists('WP_Error')) {
    /**
     * Mock WP_Error class
     */
    class WP_Error {
        private $errors = [];
        
        public function __construct($code = '', $message = '', $data = '') {
            if ($code) {
                $this->add($code, $message, $data);
            }
        }
        
        public function add($code, $message, $data = '') {
            $this->errors[$code][] = [
                'message' => $message,
                'data' => $data
            ];
        }
        
        public function get_error_code() {
            $codes = array_keys($this->errors);
            return !empty($codes) ? $codes[0] : '';
        }
        
        public function get_error_message($code = '') {
            if (!$code) {
                $code = $this->get_error_code();
            }
            if (isset($this->errors[$code][0]['message'])) {
                return $this->errors[$code][0]['message'];
            }
            return '';
        }
        
        public function has_errors() {
            return !empty($this->errors);
        }
        
        public function get_error_codes() {
            return array_keys($this->errors);
        }
    }
}

if (!class_exists('wpdb')) {
    /**
     * Mock wpdb class
     */
    class wpdb {
        public $prefix = 'wp_';
        
        public function prepare($query, ...$args) {
            // Simple placeholder replacement for tests
            return $query;
        }
        
        public function get_results($query, $output = OBJECT) {
            return [];
        }
        
        public function get_row($query, $output = OBJECT, $offset = 0) {
            return null;
        }
        
        public function get_var($query, $x = 0, $y = 0) {
            return null;
        }
        
        public function query($query) {
            return true;
        }
        
        public function insert($table, $data, $format = null) {
            return true;
        }
        
        public function update($table, $data, $where, $format = null, $where_format = null) {
            return true;
        }
        
        public function delete($table, $where, $where_format = null) {
            return true;
        }
        
        public function _real_escape($string) {
            return addslashes($string);
        }
    }
}

// Global $wpdb mock
$GLOBALS['wpdb'] = new wpdb();

/**
 * Test Helper Functions
 */

/**
 * Load a fixture file
 * 
 * @param string $name Fixture filename
 * @return string|false Fixture content or false on failure
 */
function load_fixture($name) {
    $fixture_path = __DIR__ . '/fixtures/' . $name;
    if (file_exists($fixture_path)) {
        return file_get_contents($fixture_path);
    }
    return false;
}

/**
 * Load JSON fixture
 * 
 * @param string $name Fixture filename
 * @return array|null Decoded JSON array or null on failure
 */
function load_json_fixture($name) {
    $content = load_fixture($name);
    if ($content !== false) {
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    return null;
}

/**
 * Create a temporary file with content
 * 
 * @param string $content File content
 * @param string $extension File extension
 * @return string Path to temporary file
 */
function create_temp_file($content, $extension = 'tmp') {
    $temp_dir = sys_get_temp_dir() . '/osint-tests';
    if (!is_dir($temp_dir)) {
        mkdir($temp_dir, 0755, true);
    }
    
    $filename = tempnam($temp_dir, 'test_') . '.' . $extension;
    file_put_contents($filename, $content);
    
    return $filename;
}

/**
 * Clean up temporary files
 * 
 * @param string $path Path to file or directory
 */
function cleanup_temp_files($path) {
    if (is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $fileinfo) {
            if ($fileinfo->isDir()) {
                rmdir($fileinfo->getRealPath());
            } else {
                unlink($fileinfo->getRealPath());
            }
        }
        rmdir($path);
    }
}

echo "PHPUnit bootstrap loaded successfully.\n";
