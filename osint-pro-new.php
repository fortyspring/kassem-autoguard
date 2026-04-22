<?php
/**
 * OSINT-LB PRO - Production Bootstrap
 * 
 * @package     OSINT_PRO
 * @author      Production Architect
 * @copyright   2025 OSINT-LB
 * @license     GPL-3.0+
 * @link        https://osint-lb.pro
 * 
 * @wordpress-plugin
 * Plugin Name:       OSINT-LB PRO
 * Plugin URI:        https://osint-lb.pro
 * Description:       Operational Intelligence Platform & Live Monitoring Dashboards
 * Version:           12.0.0 (Production Rescue)
 * Author:            OSINT-LB Team
 * Author URI:        https://osint-lb.pro
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       osint-pro
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 5.8
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * ============================================================================
 * CONSTANTS
 * ============================================================================
 */

define('OSINT_PRO_VERSION', '12.0.0');
define('OSINT_PRO_PLUGIN_FILE', __FILE__);
define('OSINT_PRO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OSINT_PRO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OSINT_PRO_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Paths
define('OSINT_PRO_SRC_DIR', OSINT_PRO_PLUGIN_DIR . 'src/');
define('OSINT_PRO_INCLUDES_DIR', OSINT_PRO_PLUGIN_DIR . 'includes/');
define('OSINT_PRO_ASSETS_DIR', OSINT_PRO_PLUGIN_DIR . 'assets/');
define('OSINT_PRO_LOGS_DIR', OSINT_PRO_PLUGIN_DIR . 'logs/');

// Feature Flags
define('OSINT_PRO_DEBUG_MODE', defined('WP_DEBUG') && WP_DEBUG);
define('OSINT_PRO_DEV_MODE', defined('WP_DEBUG_LOG') && WP_DEBUG_LOG);

/**
 * ============================================================================
 * AUTOLOADER
 * ============================================================================
 */

spl_autoload_register(function ($class) {
    // Only handle OSINT_PRO classes
    if (strpos($class, 'OSINT_PRO\\') !== 0) {
        return;
    }

    // Remove namespace prefix
    $relative_class = substr($class, strlen('OSINT_PRO\\'));
    
    // Convert namespace separators to directory separators
    $file_path = str_replace('\\', DIRECTORY_SEPARATOR, $relative_class);
    
    // Build file path
    $file = OSINT_PRO_SRC_DIR . $file_path . '.php';
    
    // Check if file exists and load it
    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * ============================================================================
 * CORE FUNCTIONS
 * ============================================================================
 */

/**
 * Get main plugin instance
 * 
 * @return OSINT_PRO\Core\Plugin_Core
 */
function osint_pro(): OSINT_PRO\Core\Plugin_Core {
    static $instance = null;
    
    if ($instance === null) {
        $instance = new OSINT_PRO\Core\Plugin_Core();
    }
    
    return $instance;
}

/**
 * Log message to plugin log file
 * 
 * @param string $message
 * @param string $level
 * @param string $context
 * @return void
 */
function osint_log(string $message, string $level = 'info', string $context = 'general'): void {
    if (!OSINT_PRO_DEBUG_MODE && $level === 'debug') {
        return;
    }
    
    $log_file = OSINT_PRO_LOGS_DIR . 'osint-' . date('Y-m-d') . '.log';
    $timestamp = current_time('mysql');
    $log_entry = sprintf("[%s] [%s] [%s] %s\n", $timestamp, strtoupper($level), $context, $message);
    
    // Ensure logs directory exists
    if (!is_dir(OSINT_PRO_LOGS_DIR)) {
        wp_mkdir_p(OSINT_PRO_LOGS_DIR);
    }
    
    // Append to log file
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Safe wrapper for database operations
 * 
 * @return wpdb
 */
function osint_db(): wpdb {
    global $wpdb;
    return $wpdb;
}

/**
 * Get table name with prefix
 * 
 * @param string $table
 * @return string
 */
function osint_table(string $table): string {
    global $wpdb;
    
    $prefix = $wpdb->prefix;
    
    // Ensure our tables use so_ prefix
    if (strpos($table, 'so_') !== 0) {
        $table = 'so_' . $table;
    }
    
    return $prefix . $table;
}

/**
 * ============================================================================
 * ACTIVATION & DEACTIVATION
 * ============================================================================
 */

/**
 * Plugin activation hook
 * 
 * @return void
 */
function osint_pro_activate(): void {
    try {
        // Create necessary directories
        wp_mkdir_p(OSINT_PRO_LOGS_DIR);
        wp_mkdir_p(OSINT_PRO_ASSETS_DIR . 'cache/');
        
        // Initialize core modules
        $core = osint_pro();
        $core->activate();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Set activation timestamp
        update_option('osint_pro_activated_at', current_time('mysql'));
        update_option('osint_pro_version', OSINT_PRO_VERSION);
        
        osint_log('Plugin activated successfully', 'info', 'lifecycle');
        
    } catch (Exception $e) {
        osint_log('Activation failed: ' . $e->getMessage(), 'error', 'lifecycle');
        deactivate_plugins(OSINT_PRO_PLUGIN_BASENAME);
        throw $e;
    }
}

/**
 * Plugin deactivation hook
 * 
 * @return void
 */
function osint_pro_deactivate(): void {
    try {
        $core = osint_pro();
        $core->deactivate();
        
        // Clear scheduled hooks
        wp_clear_scheduled_hook('so_cron_fetch_news_v5');
        wp_clear_scheduled_hook('so_cron_daily_cleanup');
        wp_clear_scheduled_hook('so_exec_refresh_cache');
        wp_clear_scheduled_hook('so_instant_alerts_cron');
        wp_clear_scheduled_hook('sod_watchdog_cron');
        
        osint_log('Plugin deactivated successfully', 'info', 'lifecycle');
        
    } catch (Exception $e) {
        osint_log('Deactivation failed: ' . $e->getMessage(), 'error', 'lifecycle');
    }
}

/**
 * Plugin uninstall hook
 * 
 * @return void
 */
function osint_pro_uninstall(): void {
    // Cleanup options (optional - can be disabled for data preservation)
    if (get_option('osint_pro_delete_data_on_uninstall', false)) {
        delete_option('osint_pro_activated_at');
        delete_option('osint_pro_version');
        delete_option('osint_pro_settings');
        delete_option('osint_pro_telegram_config');
        delete_option('osint_pro_ai_config');
        
        osint_log('Plugin data cleaned on uninstall', 'info', 'lifecycle');
    }
}

register_activation_hook(OSINT_PRO_PLUGIN_FILE, 'osint_pro_activate');
register_deactivation_hook(OSINT_PRO_PLUGIN_FILE, 'osint_pro_deactivate');
register_uninstall_hook(OSINT_PRO_PLUGIN_FILE, 'osint_pro_uninstall');

/**
 * ============================================================================
 * INITIALIZATION
 * ============================================================================
 */

/**
 * Initialize plugin after WordPress is loaded
 * 
 * @return void
 */
function osint_pro_init(): void {
    // Load text domain
    load_plugin_textdomain('osint-pro', false, dirname(OSINT_PRO_PLUGIN_BASENAME) . '/languages');
    
    // Initialize core plugin instance
    $core = osint_pro();
    $core->boot();
    
    osint_log('Plugin initialized', 'info', 'lifecycle');
}

add_action('plugins_loaded', 'osint_pro_init', 1);

/**
 * Admin initialization
 * 
 * @return void
 */
function osint_pro_admin_init(): void {
    if (!is_admin()) {
        return;
    }
    
    // Load admin-specific modules
    do_action('osint_pro_admin_init');
}

add_action('admin_init', 'osint_pro_admin_init', 10);

/**
 * Frontend initialization
 * 
 * @return void
 */
function osint_pro_frontend_init(): void {
    if (is_admin()) {
        return;
    }
    
    // Load frontend-specific modules
    do_action('osint_pro_frontend_init');
}

add_action('wp', 'osint_pro_frontend_init', 10);

/**
 * ============================================================================
 * BACKWARD COMPATIBILITY LAYER
 * ============================================================================
 * 
 * Temporary compatibility functions for legacy code during migration.
 * These will be removed in version 13.0.0
 */

/**
 * Legacy function wrapper for sod_fix_mojibake_text
 * 
 * @deprecated 12.0.0 Use OSINT_PRO\Core\Utilities::fix_mojibake() instead
 * @param string $text
 * @return string
 */
function sod_fix_mojibake_text(string $text): string {
    return OSINT_PRO\Core\Utilities::fix_mojibake($text);
}

/**
 * Legacy function wrapper for sod_has_arabic_chars
 * 
 * @deprecated 12.0.0 Use OSINT_PRO\Core\Utilities::has_arabic_chars() instead
 * @param string $text
 * @return bool
 */
function sod_has_arabic_chars(string $text): bool {
    return OSINT_PRO\Core\Utilities::has_arabic_chars($text);
}

/**
 * Legacy function wrapper for sod_normalize_string_list
 * 
 * @deprecated 12.0.0 Use OSINT_PRO\Core\Utilities::normalize_string_list() instead
 * @param array $list
 * @return array
 */
function sod_normalize_string_list(array $list): array {
    return OSINT_PRO\Core\Utilities::normalize_string_list($list);
}

/**
 * Legacy database table name helper
 * 
 * @deprecated 12.0.0 Use osint_table() instead
 * @param string $table
 * @return string
 */
function so_table_name(string $table): string {
    return osint_table($table);
}

/**
 * Legacy logger
 * 
 * @deprecated 12.0.0 Use osint_log() instead
 * @param string $message
 * @param string $level
 * @return void
 */
function so_log_message(string $message, string $level = 'info'): void {
    osint_log($message, $level, 'legacy');
}

// End of bootstrap file
