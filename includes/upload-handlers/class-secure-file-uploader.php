<?php
/**
 * File Upload Handler for OSINT Pro Plugin
 * 
 * Provides secure file upload functionality with validation for:
 * - JSON settings files
 * - JSON archive files
 * - CSV entity bank files
 * 
 * @package BeirutTime\OSINTPro\UploadHandlers
 * @since 1.0.0
 */

namespace BeirutTime\OSINTPro\UploadHandlers;

use WP_Error;

class SecureFileUploader {

    /**
     * Allowed MIME types for different file categories
     *
     * @var array
     */
    private static $allowed_mime_types = [
        'json' => [
            'application/json',
            'text/json',
        ],
        'csv' => [
            'text/csv',
            'text/plain',
            'application/vnd.ms-excel',
        ],
    ];

    /**
     * Allowed file extensions
     *
     * @var array
     */
    private static $allowed_extensions = [
        'json' => ['json'],
        'csv' => ['csv'],
    ];

    /**
     * Maximum file size in bytes (default: 5MB)
     *
     * @var int
     */
    private static $max_file_size = 5242880;

    /**
     * Validate and handle uploaded file
     *
     * @param array  $file_data     The $_FILES array element
     * @param string $file_type     Expected file type ('json' or 'csv')
     * @param string $error_message Custom error message prefix
     * @return array|WP_Error Array with 'tmp_name' and 'type' on success, WP_Error on failure
     */
    public static function handle_upload($file_data, $file_type = 'json', $error_message = 'خطأ في رفع الملف.') {
        // Check if file exists in request
        if (empty($file_data) || !is_array($file_data)) {
            return new \WP_Error('no_file', $error_message);
        }

        // Check for upload errors
        if (isset($file_data['error']) && $file_data['error'] !== UPLOAD_ERR_OK) {
            return self::get_upload_error($file_data['error']);
        }

        // Validate file was actually uploaded
        if (empty($file_data['tmp_name']) || !is_uploaded_file($file_data['tmp_name'])) {
            return new \WP_Error('invalid_upload', 'الملف المرفوع غير صالح.');
        }

        // SECURITY: Validate file path is within safe upload directory
        $upload_dir = wp_upload_dir();
        $real_tmp_path = realpath($file_data['tmp_name']);
        $real_upload_path = realpath($upload_dir['basedir']);
        
        // Ensure the file is within the WordPress upload directory
        if ($real_tmp_path === false || strpos($real_tmp_path, $real_upload_path) !== 0) {
            // Additional check: allow temp directory as it's where uploads initially go
            $temp_dir = sys_get_temp_dir();
            $real_temp_dir = realpath($temp_dir);
            if ($real_temp_dir === false || strpos($real_tmp_path, $real_temp_dir) !== 0) {
                return new \WP_Error('unsafe_path', 'مسار الملف غير آمن.');
            }
        }

        // Validate file size
        if (isset($file_data['size']) && $file_data['size'] > self::$max_file_size) {
            return new \WP_Error('file_too_large', 'حجم الملف يتجاوز الحد المسموح به (5 ميجابايت).');
        }

        // Validate file extension
        $original_name = isset($file_data['name']) ? sanitize_file_name($file_data['name']) : '';
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        
        if (!in_array($extension, self::$allowed_extensions[$file_type] ?? [], true)) {
            return new \WP_Error(
                'invalid_extension', 
                sprintf('امتداد الملف غير مسموح به. الامتدادات المسموحة: %s', implode(', ', self::$allowed_extensions[$file_type] ?? []))
            );
        }

        // Validate MIME type
        $mime_type = mime_content_type($file_data['tmp_name']);
        if (!in_array($mime_type, self::$allowed_mime_types[$file_type] ?? [], true)) {
            // Fallback: check file content for JSON
            if ($file_type === 'json') {
                $content = file_get_contents($file_data['tmp_name']);
                if (!self::validate_json_content($content)) {
                    return new \WP_Error('invalid_mime', 'نوع الملف غير صالح. يجب أن يكون ملف JSON صحيح.');
                }
            } elseif ($file_type === 'csv') {
                // For CSV, be more lenient but still validate
                if (!$mime_type || !in_array($mime_type, ['text/csv', 'text/plain', 'application/vnd.ms-excel'], true)) {
                    // Try to validate CSV structure
                    if (!self::validate_csv_structure($file_data['tmp_name'])) {
                        return new \WP_Error('invalid_mime', 'نوع الملف غير صالح. يجب أن يكون ملف CSV صحيح.');
                    }
                }
            } else {
                return new \WP_Error('invalid_mime', 'نوع الملف غير مدعوم.');
            }
        }

        // Additional security: scan for malicious content
        if (!self::scan_file_for_malicious_content($file_data['tmp_name'], $file_type)) {
            return new \WP_Error('malicious_content', 'تم اكتشاف محتوى مشبوه في الملف.');
        }

        return [
            'tmp_name' => $file_data['tmp_name'],
            'name' => $original_name,
            'type' => $mime_type,
            'size' => $file_data['size'] ?? 0,
        ];
    }

    /**
     * Get human-readable upload error message
     *
     * @param int $error_code PHP upload error code
     * @return WP_Error
     */
    private static function get_upload_error($error_code) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'حجم الملف يتجاوز الحد المسموح به في إعدادات PHP.',
            UPLOAD_ERR_FORM_SIZE  => 'حجم الملف يتجاوز الحد المسموح به في النموذج.',
            UPLOAD_ERR_PARTIAL    => 'تم رفع جزء من الملف فقط.',
            UPLOAD_ERR_NO_FILE    => 'لم يتم رفع أي ملف.',
            UPLOAD_ERR_NO_TMP_DIR => 'المجلد المؤقت غير موجود.',
            UPLOAD_ERR_CANT_WRITE => 'فشل كتابة الملف على القرص.',
            UPLOAD_ERR_EXTENSION  => 'إضافة PHP أوقفت رفع الملف.',
        ];

        $message = $messages[$error_code] ?? 'خطأ неизвест في رفع الملف.';
        return new \WP_Error('upload_error', $message);
    }

    /**
     * Validate JSON content
     *
     * @param string $content File content
     * @return bool
     */
    private static function validate_json_content($content) {
        if (empty($content)) {
            return false;
        }

        json_decode($content);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Validate CSV file structure
     *
     * @param string $file_path Path to CSV file
     * @return bool
     */
    private static function validate_csv_structure($file_path) {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return false;
        }

        // Try to read first row (header)
        $header = fgetcsv($handle);
        fclose($handle);

        // Valid CSV should have at least one row with columns
        return is_array($header) && count($header) > 0;
    }

    /**
     * Scan file for potentially malicious content
     *
     * @param string $file_path Path to uploaded file
     * @param string $file_type Expected file type
     * @return bool True if file is safe, false otherwise
     */
    private static function scan_file_for_malicious_content($file_path, $file_type) {
        $content = file_get_contents($file_path);
        if ($content === false) {
            return false;
        }

        // Check for PHP tags in non-PHP files
        if ($file_type !== 'php') {
            $dangerous_patterns = [
                '<?php',
                '<script',
                'javascript:',
                'vbscript:',
                'data:text/html',
                'eval(',
                'base64_decode(',
                'exec(',
                'system(',
                'passthru(',
                'shell_exec(',
            ];

            $content_lower = strtolower($content);
            foreach ($dangerous_patterns as $pattern) {
                if (strpos($content_lower, strtolower($pattern)) !== false) {
                    // For JSON files, allow these patterns in string values but not as structure
                    if ($file_type === 'json') {
                        $decoded = json_decode($content, true);
                        if (is_array($decoded)) {
                            // Recursively check if dangerous patterns are in keys (not values)
                            return self::check_json_keys_safe($decoded);
                        }
                    }
                    return false;
                }
            }
        }

        // Check for null bytes
        if (strpos($content, "\0") !== false) {
            return false;
        }

        return true;
    }

    /**
     * Check if JSON keys are safe (no dangerous patterns in keys)
     *
     * @param array $data Decoded JSON data
     * @return bool
     */
    private static function check_json_keys_safe($data) {
        if (!is_array($data)) {
            return true;
        }

        foreach ($data as $key => $value) {
            $key_lower = strtolower($key);
            if (strpos($key_lower, '<?php') !== false || 
                strpos($key_lower, '<script') !== false ||
                strpos($key_lower, 'eval') !== false) {
                return false;
            }

            if (is_array($value) && !self::check_json_keys_safe($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read and validate JSON file
     *
     * @param string $file_path Path to JSON file
     * @param array  $required_keys Optional array of required top-level keys
     * @return array|WP_Error Decoded JSON array or WP_Error
     */
    public static function read_json_file($file_path, $required_keys = []) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return new \WP_Error('file_not_readable', 'لا يمكن قراءة الملف.');
        }

        // SECURITY: Resolve real path and validate it's within safe directories (prevent Path Traversal)
        $real_path = realpath($file_path);
        if ($real_path === false) {
            return new \WP_Error('invalid_path', 'مسار الملف غير صالح.');
        }
        
        // Ensure file is within WordPress upload directory or temp directory
        $upload_dir = wp_upload_dir();
        $real_upload_path = realpath($upload_dir['basedir']);
        $real_temp_dir = realpath(sys_get_temp_dir());
        
        $is_safe_path = false;
        if ($real_upload_path !== false && strpos($real_path, $real_upload_path) === 0) {
            $is_safe_path = true;
        } elseif ($real_temp_dir !== false && strpos($real_path, $real_temp_dir) === 0) {
            $is_safe_path = true;
        }
        
        if (!$is_safe_path) {
            return new \WP_Error('unsafe_path', 'مسار الملف خارج النطاق الآمن.');
        }

        $content = file_get_contents($real_path);
        if ($content === false) {
            return new \WP_Error('file_read_error', 'فشل قراءة محتوى الملف.');
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return new \WP_Error('invalid_json', 'الملف لا يحتوي على JSON صالح.');
        }

        // Validate required keys
        foreach ($required_keys as $key) {
            if (!array_key_exists($key, $decoded)) {
                return new \WP_Error(
                    'missing_required_key', 
                    sprintf('الملف يفتقد إلى الحقل المطلوب: %s', $key)
                );
            }
        }

        return $decoded;
    }

    /**
     * Read and parse CSV file
     *
     * @param string $file_path Path to CSV file
     * @param int    $skip_rows Number of rows to skip (e.g., for BOM or header)
     * @return array|WP_Error Array of rows or WP_Error
     */
    public static function read_csv_file($file_path, $skip_rows = 0) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return new \WP_Error('file_not_readable', 'لا يمكن قراءة الملف.');
        }

        // SECURITY: Resolve real path and validate it's within safe directories (prevent Path Traversal)
        $real_path = realpath($file_path);
        if ($real_path === false) {
            return new \WP_Error('invalid_path', 'مسار الملف غير صالح.');
        }
        
        // Ensure file is within WordPress upload directory or temp directory
        $upload_dir = wp_upload_dir();
        $real_upload_path = realpath($upload_dir['basedir']);
        $real_temp_dir = realpath(sys_get_temp_dir());
        
        $is_safe_path = false;
        if ($real_upload_path !== false && strpos($real_path, $real_upload_path) === 0) {
            $is_safe_path = true;
        } elseif ($real_temp_dir !== false && strpos($real_path, $real_temp_dir) === 0) {
            $is_safe_path = true;
        }
        
        if (!$is_safe_path) {
            return new \WP_Error('unsafe_path', 'مسار الملف خارج النطاق الآمن.');
        }

        $handle = fopen($real_path, 'r');
        if (!$handle) {
            return new \WP_Error('file_open_error', 'فشل فتح الملف للقراءة.');
        }

        // Handle BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        } else {
            $skip_rows++;
        }

        // Skip specified rows
        for ($i = 0; $i < $skip_rows; $i++) {
            fgetcsv($handle);
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        if (empty($rows)) {
            return new \WP_Error('empty_csv', 'الملف فارغ أو لا يحتوي على بيانات صالحة.');
        }

        return $rows;
    }

    /**
     * Set maximum file size
     *
     * @param int $bytes Maximum file size in bytes
     */
    public static function set_max_file_size($bytes) {
        self::$max_file_size = max(0, (int) $bytes);
    }
}
