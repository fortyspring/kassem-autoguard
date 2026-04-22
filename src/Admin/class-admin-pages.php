<?php
/**
 * Admin Pages Manager
 * 
 * Handles all admin UI pages, menus, and settings panels.
 * Single source of truth for admin interface.
 * 
 * @package OSINT_LB_PRO
 * @subpackage Admin
 * @since 2.0.0
 */

namespace OSINT_LB_PRO\Admin;

use OSINT_LB_PRO\Security\Security_Manager;

class Admin_Pages {
    
    /**
     * Security manager instance
     * 
     * @var Security_Manager
     */
    private $security;
    
    /**
     * Page hooks registry
     * 
     * @var array
     */
    private $page_hooks = [];
    
    /**
     * Menu configuration
     * 
     * @var array
     */
    private $menu_config = [
        'dashboard' => [
            'title' => 'لوحة القيادة',
            'capability' => 'manage_options',
            'icon' => 'dashicons-dashboard',
            'position' => 3
        ],
        'world_monitor' => [
            'title' => 'مراقب العالم',
            'capability' => 'manage_options',
            'parent' => 'osint-pro',
            'icon' => 'dashicons-global'
        ],
        'intelligence' => [
            'title' => 'خط الاستخبارات',
            'capability' => 'manage_options',
            'parent' => 'osint-pro',
            'icon' => 'dashicons-networking'
        ],
        'reports' => [
            'title' => 'التقارير التنفيذية',
            'capability' => 'manage_options',
            'parent' => 'osint-pro',
            'icon' => 'dashicons-media-document'
        ],
        'alerts' => [
            'title' => 'التنبيهات والإشعارات',
            'capability' => 'manage_options',
            'parent' => 'osint-pro',
            'icon' => 'dashicons-warning'
        ],
        'sources' => [
            'title' => 'إدارة المصادر',
            'capability' => 'manage_options',
            'parent' => 'osint-pro',
            'icon' => 'dashicons-list-view'
        ],
        'settings' => [
            'title' => 'الإعدادات',
            'capability' => 'manage_options',
            'parent' => 'osint-pro',
            'icon' => 'dashicons-admin-settings'
        ]
    ];
    
    /**
     * Constructor
     * 
     * @param Security_Manager $security Security manager instance
     */
    public function __construct(Security_Manager $security) {
        $this->security = $security;
    }
    
    /**
     * Initialize admin pages
     * 
     * @return void
     */
    public function init(): void {
        add_action('admin_menu', [$this, 'register_menus']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }
    
    /**
     * Register admin menus
     * 
     * @return void
     */
    public function register_menus(): void {
        // Main menu
        $this->page_hooks['dashboard'] = add_menu_page(
            __('OSINT-LB PRO', 'osint-lb-pro'),
            __('OSINT-LB PRO', 'osint-lb-pro'),
            $this->menu_config['dashboard']['capability'],
            'osint-pro',
            [$this, 'render_dashboard'],
            $this->menu_config['dashboard']['icon'],
            $this->menu_config['dashboard']['position']
        );
        
        // Sub-menus
        foreach ($this->menu_config as $key => $config) {
            if ($key === 'dashboard') {
                continue;
            }
            
            if (isset($config['parent'])) {
                $this->page_hooks[$key] = add_submenu_page(
                    $config['parent'],
                    $config['title'],
                    $config['title'],
                    $config['capability'],
                    "osint-pro-{$key}",
                    [$this, "render_{$key}"]
                );
            }
        }
    }
    
    /**
     * Register settings sections and fields
     * 
     * @return void
     */
    public function register_settings(): void {
        // General Settings
        register_setting('osint_general', 'osint_api_keys', [
            'sanitize_callback' => [$this->security, 'sanitize_array']
        ]);
        
        register_setting('osint_general', 'osint_refresh_interval', [
            'sanitize_callback' => 'absint',
            'default' => 300
        ]);
        
        register_setting('osint_general', 'osint_default_region', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'LB'
        ]);
        
        // Intelligence Pipeline Settings
        register_setting('osint_intelligence', 'osint_auto_classify', [
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true
        ]);
        
        register_setting('osint_intelligence', 'osint_threat_threshold', [
            'sanitize_callback' => 'absint',
            'default' => 50
        ]);
        
        register_setting('osint_intelligence', 'osint_dedupe_window', [
            'sanitize_callback' => 'absint',
            'default' => 3600
        ]);
        
        // Alert Settings
        register_setting('osint_alerts', 'osint_telegram_enabled', [
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false
        ]);
        
        register_setting('osint_alerts', 'osint_telegram_bot_token', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        register_setting('osint_alerts', 'osint_telegram_chat_id', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        
        register_setting('osint_alerts', 'osint_alert_min_severity', [
            'sanitize_callback' => 'absint',
            'default' => 70
        ]);
        
        // Add settings sections
        add_settings_section(
            'osint_general_section',
            __('الإعدادات العامة', 'osint-lb-pro'),
            [$this, 'render_general_section_desc'],
            'osint-pro-settings'
        );
        
        add_settings_section(
            'osint_intelligence_section',
            __('إعدادات خط الاستخبارات', 'osint-lb-pro'),
            [$this, 'render_intelligence_section_desc'],
            'osint-pro-intelligence'
        );
        
        add_settings_section(
            'osint_alerts_section',
            __('إعدادات التنبيهات', 'osint-lb-pro'),
            [$this, 'render_alerts_section_desc'],
            'osint-pro-alerts'
        );
    }
    
    /**
     * Enqueue admin assets
     * 
     * @param string $hook Current page hook
     * @return void
     */
    public function enqueue_assets(string $hook): void {
        // Only load on our pages
        if (!in_array($hook, $this->page_hooks)) {
            return;
        }
        
        wp_enqueue_style(
            'osint-admin',
            OSINT_PLUGIN_URL . 'assets/css/admin.css',
            [],
            OSINT_VERSION
        );
        
        wp_enqueue_script(
            'osint-admin',
            OSINT_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'wp-api'],
            OSINT_VERSION,
            true
        );
        
        wp_localize_script('osint-admin', 'osintAdmin', [
            'nonce' => wp_create_nonce('wp_rest'),
            'apiUrl' => rest_url('osint/v1'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'strings' => [
                'confirmDelete' => __('هل أنت متأكد من الحذف؟', 'osint-lb-pro'),
                'saving' => __('جاري الحفظ...', 'osint-lb-pro'),
                'saved' => __('تم الحفظ بنجاح', 'osint-lb-pro'),
                'error' => __('حدث خطأ', 'osint-lb-pro')
            ]
        ]);
    }
    
    /**
     * Render dashboard page
     * 
     * @return void
     */
    public function render_dashboard(): void {
        $this->security->verify_capability('manage_options');
        
        include OSINT_PLUGIN_DIR . 'src/Admin/views/dashboard.php';
    }
    
    /**
     * Render world monitor page
     * 
     * @return void
     */
    public function render_world_monitor(): void {
        $this->security->verify_capability('manage_options');
        
        include OSINT_PLUGIN_DIR . 'src/Admin/views/world-monitor.php';
    }
    
    /**
     * Render intelligence page
     * 
     * @return void
     */
    public function render_intelligence(): void {
        $this->security->verify_capability('manage_options');
        
        include OSINT_PLUGIN_DIR . 'src/Admin/views/intelligence.php';
    }
    
    /**
     * Render reports page
     * 
     * @return void
     */
    public function render_reports(): void {
        $this->security->verify_capability('manage_options');
        
        include OSINT_PLUGIN_DIR . 'src/Admin/views/reports.php';
    }
    
    /**
     * Render alerts page
     * 
     * @return void
     */
    public function render_alerts(): void {
        $this->security->verify_capability('manage_options');
        
        include OSINT_PLUGIN_DIR . 'src/Admin/views/alerts.php';
    }
    
    /**
     * Render sources page
     * 
     * @return void
     */
    public function render_sources(): void {
        $this->security->verify_capability('manage_options');
        
        include OSINT_PLUGIN_DIR . 'src/Admin/views/sources.php';
    }
    
    /**
     * Render settings page
     * 
     * @return void
     */
    public function render_settings(): void {
        $this->security->verify_capability('manage_options');
        
        include OSINT_PLUGIN_DIR . 'src/Admin/views/settings.php';
    }
    
    /**
     * Render general settings section description
     * 
     * @return void
     */
    public function render_general_section_desc(): void {
        echo '<p>' . __('الإعدادات الأساسية للنظام ومفاتيح API', 'osint-lb-pro') . '</p>';
    }
    
    /**
     * Render intelligence settings section description
     * 
     * @return void
     */
    public function render_intelligence_section_desc(): void {
        echo '<p>' . __('إعدادات معالجة وتصنيف البيانات الاستخباراتية', 'osint-lb-pro') . '</p>';
    }
    
    /**
     * Render alerts settings section description
     * 
     * @return void
     */
    public function render_alerts_section_desc(): void {
        echo '<p>' . __('إعدادات نظام التنبيهات والإشعارات التلقائية', 'osint-lb-pro') . '</p>';
    }
    
    /**
     * Get page hook by key
     * 
     * @param string $key Page key
     * @return string|null
     */
    public function get_page_hook(string $key): ?string {
        return $this->page_hooks[$key] ?? null;
    }
}
