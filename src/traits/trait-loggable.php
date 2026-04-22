<?php
/**
 * سمة Loggable
 * 
 * توفر وظائف التسجيل للفئات
 * 
 * @package Beiruttime\OSINT\Traits
 */

namespace Beiruttime\OSINT\Traits;

/**
 * سمة Loggable
 */
trait Loggable {
    
    /**
     * تسجيل رسالة
     * 
     * @param string $message الرسالة المراد تسجيلها
     * @param string $level مستوى السجل (info, warning, error)
     * @return void
     */
    protected function log($message, $level = 'info') {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        $prefix = '[Beiruttime OSINT Pro]';
        $timestamp = current_time('mysql');
        
        switch ($level) {
            case 'error':
                error_log("{$prefix} [ERROR] {$timestamp}: {$message}");
                break;
            case 'warning':
                error_log("{$prefix} [WARNING] {$timestamp}: {$message}");
                break;
            default:
                error_log("{$prefix} [INFO] {$timestamp}: {$message}");
                break;
        }
    }
    
    /**
     * تسجيل خطأ
     * 
     * @param string $message الرسالة المراد تسجيلها
     * @return void
     */
    protected function logError($message) {
        $this->log($message, 'error');
    }
    
    /**
     * تسجيل تحذير
     * 
     * @param string $message الرسالة المراد تسجيلها
     * @return void
     */
    protected function logWarning($message) {
        $this->log($message, 'warning');
    }
    
    /**
     * تسجيل معلومة
     * 
     * @param string $message الرسالة المراد تسجيلها
     * @return void
     */
    protected function logInfo($message) {
        $this->log($message, 'info');
    }
}
