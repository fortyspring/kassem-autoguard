<?php
/**
 * OSINT-LB PRO - Production Bootstrap
 * 
 * This file serves as the minimal bootstrap for the plugin.
 * All business logic has been moved to dedicated modules in src/.
 * 
 * @package OSINT_Pro
 * @since 23.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('OSINT_PRO_VERSION', '23.0.0');
define('OSINT_PRO_PLUGIN_FILE', __FILE__);
define('OSINT_PRO_PLUGIN_DIR', dirname(__FILE__));
define('OSINT_PRO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OSINT_PRO_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Minimal safe require helper
 */
function osint_pro_require(string $path): void {
    $full_path = OSINT_PRO_PLUGIN_DIR . '/' . $path;
    if (file_exists($full_path)) {
        require_once $full_path;
    }
}

/**
 * Load autoloader
 */
osint_pro_require('src/class-autoloader.php');

/**
 * Load Core Services
 */
osint_pro_require('src/Core/class-plugin-core.php');
osint_pro_require('src/Security/class-security-manager.php');
osint_pro_require('src/Cron/class-cron-orchestrator.php');

/**
 * Load Intelligence Pipeline
 */
osint_pro_require('src/Pipeline/class-intake-handler.php');
osint_pro_require('src/Pipeline/class-classification-engine.php');
osint_pro_require('src/Pipeline/class-deduplication-service.php');

/**
 * Load Admin Components
 */
osint_pro_require('src/Admin/class-admin-pages.php');
osint_pro_require('src/Admin/class-settings-manager.php');

/**
 * Load AJAX Handlers
 */
osint_pro_require('src/Ajax/class-ajax-handlers.php');

/**
 * Load Dashboard Components
 */
osint_pro_require('src/Dashboard/class-dashboard-renderer.php');
osint_pro_require('src/Dashboard/WorldMonitor/class-world-monitor.php');

/**
 * Load Reports Engine
 */
osint_pro_require('src/Reports/class-executive-reports.php');

/**
 * Load Integrations
 */
osint_pro_require('src/Integrations/class-telegram-integration.php');
osint_pro_require('src/Integrations/class-ai-services.php');

/**
 * Activation Hook
 */
register_activation_hook(OSINT_PRO_PLUGIN_FILE, 'osint_pro_activate');

/**
 * Deactivation Hook
 */
register_deactivation_hook(OSINT_PRO_PLUGIN_FILE, 'osint_pro_deactivate');

/**
 * Plugin Activation
 */
function osint_pro_activate(): void {
    // Clear any existing cron events
    wp_clear_scheduled_hook('osint_pro_cron_fetch');
    wp_clear_scheduled_hook('osint_pro_cron_cleanup');
    wp_clear_scheduled_hook('osint_pro_cron_reports');
    
    // Schedule new cron events
    if (!wp_next_scheduled('osint_pro_cron_fetch')) {
        wp_schedule_event(time(), 'hourly', 'osint_pro_cron_fetch');
    }
    if (!wp_next_scheduled('osint_pro_cron_cleanup')) {
        wp_schedule_event(time(), 'daily', 'osint_pro_cron_cleanup');
    }
    if (!wp_next_scheduled('osint_pro_cron_reports')) {
        wp_schedule_event(time(), 'twicedaily', 'osint_pro_cron_reports');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
    
    // Run any database migrations
    OSINT_Pro\Core\Plugin_Core::instance()->activate();
}

/**
 * Plugin Deactivation
 */
function osint_pro_deactivate(): void {
    // Clear scheduled cron events
    wp_clear_scheduled_hook('osint_pro_cron_fetch');
    wp_clear_scheduled_hook('osint_pro_cron_cleanup');
    wp_clear_scheduled_hook('osint_pro_cron_reports');
    
    // Flush rewrite rules
    flush_rewrite_rules();
    
    // Run deactivation cleanup
    OSINT_Pro\Core\Plugin_Core::instance()->deactivate();
}

/**
 * Initialize Plugin
 */
function osint_pro_init(): void {
    // Initialize core services
    OSINT_Pro\Core\Plugin_Core::instance()->boot();
    
    // Initialize security manager
    OSINT_Pro\Security\Security_Manager::instance()->init();
    
    // Initialize cron orchestrator
    OSINT_Pro\Cron\Cron_Orchestrator::instance()->init();
    
    // Initialize admin pages
    if (is_admin()) {
        OSINT_Pro\Admin\Admin_Pages::instance()->init();
        OSINT_Pro\Admin\Settings_Manager::instance()->init();
    }
    
    // Initialize AJAX handlers
    OSINT_Pro\Ajax\Ajax_Handlers::instance()->init();
    
    // Initialize dashboard renderer
    OSINT_Pro\Dashboard\Dashboard_Renderer::instance()->init();
    
    // Initialize World Monitor
    OSINT_Pro\Dashboard\WorldMonitor\World_Monitor::instance()->init();
    
    // Initialize reports engine
    OSINT_Pro\Reports\Executive_Reports::instance()->init();
    
    // Initialize integrations
    OSINT_Pro\Integrations\Telegram_Integration::instance()->init();
    OSINT_Pro\Integrations\AI_Services::instance()->init();
    
    // Load legacy compatibility layer (only if needed)
    osint_pro_require('includes/class-hybrid-warfare-integrator.php');
}

// Hook into WordPress init
add_action('plugins_loaded', 'osint_pro_init', 10);

/**
 * Enqueue assets conditionally
 */
function osint_pro_enqueue_assets(): void {
    // Only load on pages that need them
    global $post;
    
    $needs_assets = false;
    
    // Check for shortcodes
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'sod_world_monitor')) {
        $needs_assets = true;
    }
    
    // Check for admin pages
    if (is_admin()) {
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'osint') !== false) {
            $needs_assets = true;
        }
    }
    
    if (!$needs_assets) {
        return;
    }
    
    // Enqueue CSS
    wp_enqueue_style(
        'osint-pro-admin',
        OSINT_PRO_PLUGIN_URL . 'assets/css/admin-v19.css',
        [],
        OSINT_PRO_VERSION
    );
    
    // Enqueue JS
    wp_enqueue_script(
        'osint-pro-admin',
        OSINT_PRO_PLUGIN_URL . 'assets/js/admin-v19.js',
        ['jquery'],
        OSINT_PRO_VERSION,
        true
    );
    
    // Localize script
    wp_localize_script('osint-pro-admin', 'osintProConfig', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('osint_pro_nonce'),
        'restUrl' => rest_url('osint-pro/v1/'),
    ]);
}

add_action('admin_enqueue_scripts', 'osint_pro_enqueue_assets');
add_action('wp_enqueue_scripts', 'osint_pro_enqueue_assets');
