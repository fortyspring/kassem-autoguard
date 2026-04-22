<?php
/**
 * OSINT-LB PRO - Core Module
 * 
 * Base core module that handles fundamental plugin operations
 * 
 * @package     OSINT_PRO\Core
 * @author      Production Architect
 * @since       12.0.0
 */

namespace OSINT_PRO\Core;

use OSINT_PRO\Core\Interfaces\Module;

class Core_Module implements Module {
    
    /**
     * Whether module is active
     * 
     * @var bool
     */
    private bool $is_active = false;
    
    /**
     * {@inheritDoc}
     */
    public function boot(): void {
        // Initialize database tables if needed
        $this->init_database();
        
        // Register core hooks
        $this->register_hooks();
        
        $this->is_active = true;
    }
    
    /**
     * {@inheritDoc}
     */
    public function register(): void {
        // Core hooks already registered in boot()
    }
    
    /**
     * {@inheritDoc}
     */
    public function activate(): void {
        // Create database tables
        $this->create_tables();
        
        // Set default options
        $this->set_default_options();
    }
    
    /**
     * {@inheritDoc}
     */
    public function deactivate(): void {
        // Cleanup if needed
        $this->is_active = false;
    }
    
    /**
     * {@inheritDoc}
     */
    public function is_active(): bool {
        return $this->is_active;
    }
    
    /**
     * Initialize database connections and table names
     * 
     * @return void
     */
    private function init_database(): void {
        global $wpdb;
        
        // Define table constants for easy access
        if (!defined('OSINT_TABLE_EVENTS')) {
            define('OSINT_TABLE_EVENTS', $wpdb->prefix . 'so_news_events');
        }
        
        if (!defined('OSINT_TABLE_ENTITY_GRAPH')) {
            define('OSINT_TABLE_ENTITY_GRAPH', $wpdb->prefix . 'so_entity_graph');
        }
        
        if (!defined('OSINT_TABLE_HYBRID_WARFARE')) {
            define('OSINT_TABLE_HYBRID_WARFARE', $wpdb->prefix . 'so_hybrid_warfare');
        }
    }
    
    /**
     * Register core WordPress hooks
     * 
     * @return void
     */
    private function register_hooks(): void {
        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menus'], 1);
        
        // Add admin bar menu
        add_action('admin_bar_menu', [$this, 'add_admin_bar_menu'], 100);
        
        // Load assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        
        // Heartbeat for live updates
        add_filter('heartbeat_received', [$this, 'heartbeat_received'], 10, 2);
        add_filter('heartbeat_send', [$this, 'heartbeat_send'], 10, 2);
    }
    
    /**
     * Create database tables on activation
     * 
     * @return void
     */
    private function create_tables(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Events table (main events storage)
        $sql_events = "CREATE TABLE {$wpdb->prefix}so_news_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_title text NOT NULL,
            event_content longtext NOT NULL,
            event_summary text,
            source_url varchar(2048),
            source_name varchar(255),
            event_timestamp datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            primary_actor varchar(255),
            target_actor varchar(255),
            location_country varchar(100),
            location_city varchar(255),
            threat_score int(11) DEFAULT 0,
            severity_level varchar(50) DEFAULT 'normal',
            category varchar(100),
            subcategory varchar(100),
            tags text,
            entities text,
            is_duplicate tinyint(1) DEFAULT 0,
            duplicate_of bigint(20) unsigned DEFAULT NULL,
            status varchar(50) DEFAULT 'new',
            reviewed_by bigint(20) unsigned DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            INDEX idx_event_timestamp (event_timestamp),
            INDEX idx_threat_score (threat_score),
            INDEX idx_primary_actor (primary_actor),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at),
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        dbDelta($sql_events);
        
        // Entity graph table (relationships between entities)
        $sql_entity = "CREATE TABLE {$wpdb->prefix}so_entity_graph (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_name varchar(255) NOT NULL,
            entity_type varchar(100) NOT NULL,
            entity_category varchar(100),
            parent_entity_id bigint(20) unsigned DEFAULT NULL,
            related_entities text,
            metadata longtext,
            confidence_score decimal(5,4) DEFAULT 1.0000,
            first_seen datetime DEFAULT CURRENT_TIMESTAMP,
            last_seen datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            mention_count int(11) DEFAULT 1,
            INDEX idx_entity_type (entity_type),
            INDEX idx_entity_name (entity_name),
            INDEX idx_parent_entity (parent_entity_id),
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        dbDelta($sql_entity);
        
        // Hybrid warfare layers table
        $sql_hybrid = "CREATE TABLE {$wpdb->prefix}so_hybrid_warfare (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            layer_type varchar(100) NOT NULL,
            layer_name varchar(255),
            intensity_level int(11) DEFAULT 1,
            actors_involved text,
            targets_affected text,
            narrative text,
            evidence_urls text,
            confidence_score decimal(5,4) DEFAULT 0.5000,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_id (event_id),
            INDEX idx_layer_type (layer_type),
            INDEX idx_intensity (intensity_level),
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        dbDelta($sql_hybrid);
    }
    
    /**
     * Set default plugin options
     * 
     * @return void
     */
    private function set_default_options(): void {
        $defaults = [
            'version' => OSINT_PRO_VERSION,
            'activated_at' => current_time('mysql'),
            'settings' => [
                'auto_fetch_enabled' => true,
                'fetch_interval_minutes' => 15,
                'duplicate_detection_enabled' => true,
                'ai_classification_enabled' => true,
                'telegram_notifications_enabled' => false,
                'executive_reports_auto' => false,
                'debug_mode' => OSINT_PRO_DEBUG_MODE,
            ],
            'cleanup' => [
                'last_cleanup' => null,
                'duplicates_removed' => 0,
                'events_archived' => 0,
            ],
        ];
        
        if (get_option('osint_pro_settings') === false) {
            update_option('osint_pro_settings', $defaults);
        }
    }
    
    /**
     * Add admin menus
     * 
     * @return void
     */
    public function add_admin_menus(): void {
        // Main OSINT menu
        add_menu_page(
            __('OSINT-LB PRO', 'osint-pro'),
            __('OSINT Intelligence', 'osint-pro'),
            'manage_options',
            'osint-pro-dashboard',
            [$this, 'render_dashboard_page'],
            'dashicons-chart-line',
            3
        );
        
        // Dashboard submenu
        add_submenu_page(
            'osint-pro-dashboard',
            __('Dashboard', 'osint-pro'),
            __('Dashboard', 'osint-pro'),
            'manage_options',
            'osint-pro-dashboard',
            [$this, 'render_dashboard_page']
        );
        
        // World Monitor submenu
        add_submenu_page(
            'osint-pro-dashboard',
            __('World Monitor', 'osint-pro'),
            __('World Monitor', 'osint-pro'),
            'manage_options',
            'osint-world-monitor',
            [$this, 'render_world_monitor_page']
        );
        
        // Executive Reports submenu
        add_submenu_page(
            'osint-pro-dashboard',
            __('Executive Reports', 'osint-pro'),
            __('Executive Reports', 'osint-pro'),
            'manage_options',
            'osint-exec-reports',
            [$this, 'render_exec_reports_page']
        );
        
        // Settings submenu
        add_submenu_page(
            'osint-pro-dashboard',
            __('Settings', 'osint-pro'),
            __('Settings', 'osint-pro'),
            'manage_options',
            'osint-pro-settings',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Add admin bar menu
     * 
     * @param \WP_Admin_Bar $wp_admin_bar
     * @return void
     */
    public function add_admin_bar_menu(\WP_Admin_Bar $wp_admin_bar): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $wp_admin_bar->add_node([
            'id' => 'osint-pro',
            'title' => '<span class="ab-icon dashicons dashicons-chart-line"></span><span class="ab-label">' . __('OSINT', 'osint-pro') . '</span>',
            'href' => admin_url('admin.php?page=osint-pro-dashboard'),
            'meta' => ['class' => 'osint-pro-admin-bar'],
        ]);
        
        $wp_admin_bar->add_node([
            'id' => 'osint-pro-dashboard',
            'title' => __('Dashboard', 'osint-pro'),
            'parent' => 'osint-pro',
            'href' => admin_url('admin.php?page=osint-pro-dashboard'),
        ]);
        
        $wp_admin_bar->add_node([
            'id' => 'osint-pro-world-monitor',
            'title' => __('World Monitor', 'osint-pro'),
            'parent' => 'osint-pro',
            'href' => admin_url('admin.php?page=osint-world-monitor'),
        ]);
        
        $wp_admin_bar->add_node([
            'id' => 'osint-pro-reports',
            'title' => __('Reports', 'osint-pro'),
            'parent' => 'osint-pro',
            'href' => admin_url('admin.php?page=osint-exec-reports'),
        ]);
        
        $wp_admin_bar->add_node([
            'id' => 'osint-pro-settings',
            'title' => __('Settings', 'osint-pro'),
            'parent' => 'osint-pro',
            'href' => admin_url('admin.php?page=osint-pro-settings'),
        ]);
    }
    
    /**
     * Enqueue admin assets
     * 
     * @param string $hook
     * @return void
     */
    public function enqueue_admin_assets(string $hook): void {
        // Only load on OSINT pages
        if (strpos($hook, 'osint-pro') === false && strpos($hook, 'osint-') === false) {
            return;
        }
        
        // Core admin CSS
        wp_enqueue_style(
            'osint-pro-admin',
            OSINT_PRO_PLUGIN_URL . 'assets/css/admin.css',
            [],
            OSINT_PRO_VERSION
        );
        
        // Chart.js for dashboards
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );
        
        // Core admin JS
        wp_enqueue_script(
            'osint-pro-admin',
            OSINT_PRO_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'chart-js'],
            OSINT_PRO_VERSION,
            true
        );
        
        // Localize script with config
        wp_localize_script('osint-pro-admin', 'osintProConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('osint_pro_ajax'),
            'restUrl' => rest_url('osint-pro/v1'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'version' => OSINT_PRO_VERSION,
            'debugMode' => OSINT_PRO_DEBUG_MODE,
        ]);
    }
    
    /**
     * Enqueue frontend assets
     * 
     * @return void
     */
    public function enqueue_frontend_assets(): void {
        // Only load if shortcode or widget is used
        if (!has_shortcode(get_queried_object()->post_content ?? '', 'osint_')) {
            return;
        }
        
        wp_enqueue_style(
            'osint-pro-frontend',
            OSINT_PRO_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            OSINT_PRO_VERSION
        );
        
        wp_enqueue_script(
            'osint-pro-frontend',
            OSINT_PRO_PLUGIN_URL . 'assets/js/frontend.js',
            ['jquery'],
            OSINT_PRO_VERSION,
            true
        );
    }
    
    /**
     * Handle heartbeat for live updates
     * 
     * @param array $response
     * @param array $data
     * @return array
     */
    public function heartbeat_received(array $response, array $data): array {
        if (isset($data['osint_heartbeat'])) {
            // Return latest stats
            $response['osint_stats'] = $this->get_quick_stats();
        }
        
        return $response;
    }
    
    /**
     * Modify heartbeat send interval
     * 
     * @param array $data
     * @param string $screen_id
     * @return array
     */
    public function heartbeat_send(array $data, string $screen_id): array {
        // Increase frequency on OSINT pages
        if (strpos($screen_id, 'osint-pro') !== false) {
            $data['interval'] = 15; // 15 seconds
        }
        
        return $data;
    }
    
    /**
     * Get quick statistics
     * 
     * @return array
     */
    private function get_quick_stats(): array {
        global $wpdb;
        
        $events_table = osint_table('news_events');
        
        $stats = [];
        
        // Total events today
        $today = current_time('Y-m-d');
        $stats['events_today'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $events_table WHERE DATE(event_timestamp) = %s",
            $today
        ));
        
        // High threat events
        $stats['high_threat'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $events_table WHERE threat_score >= 7 AND status = 'new'"
        ));
        
        // Pending review
        $stats['pending_review'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $events_table WHERE status = 'new'"
        ));
        
        return $stats;
    }
    
    /**
     * Render dashboard page
     * 
     * @return void
     */
    public function render_dashboard_page(): void {
        include OSINT_PRO_PLUGIN_DIR . 'src/Dashboard/views/dashboard-page.php';
    }
    
    /**
     * Render world monitor page
     * 
     * @return void
     */
    public function render_world_monitor_page(): void {
        include OSINT_PRO_PLUGIN_DIR . 'src/WorldMonitor/views/world-monitor-page.php';
    }
    
    /**
     * Render executive reports page
     * 
     * @return void
     */
    public function render_exec_reports_page(): void {
        include OSINT_PRO_PLUGIN_DIR . 'src/Reports/views/exec-reports-page.php';
    }
    
    /**
     * Render settings page
     * 
     * @return void
     */
    public function render_settings_page(): void {
        include OSINT_PRO_PLUGIN_DIR . 'src/Admin/views/settings-page.php';
    }
}
