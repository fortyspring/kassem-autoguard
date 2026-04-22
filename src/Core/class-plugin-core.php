<?php
/**
 * OSINT Pro Namespace Root
 * 
 * All refactored classes use this namespace.
 * 
 * @package OSINT_Pro
 */

namespace OSINT_Pro;

/**
 * Plugin Core - Main orchestrator for the plugin lifecycle
 */
class Plugin_Core {
    
    use \SO\Traits\Singleton;
    use \SO\Traits\Loggable;
    
    /**
     * Plugin version
     */
    const VERSION = '23.0.0';
    
    /**
     * Minimum WordPress version required
     */
    const MIN_WP_VERSION = '6.2';
    
    /**
     * Minimum PHP version required
     */
    const MIN_PHP_VERSION = '8.0';
    
    /**
     * Whether plugin is initialized
     */
    protected bool $initialized = false;
    
    /**
     * Active modules registry
     */
    protected array $modules = [];
    
    /**
     * Boot the plugin
     */
    public function boot(): void {
        if ($this->initialized) {
            return;
        }
        
        // Check requirements
        if (!$this->check_requirements()) {
            add_action('admin_notices', [$this, 'requirements_notice']);
            return;
        }
        
        // Load text domain
        load_plugin_textdomain('beiruttime-osint-pro', false, dirname(OSINT_PRO_PLUGIN_BASENAME) . '/languages');
        
        // Mark as initialized
        $this->initialized = true;
        
        $this->log('Plugin booted successfully', 'core');
    }
    
    /**
     * Check system requirements
     */
    protected function check_requirements(): bool {
        global $wp_version;
        
        // Check WordPress version
        if (version_compare($wp_version, self::MIN_WP_VERSION, '<')) {
            return false;
        }
        
        // Check PHP version
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Show requirements notice
     */
    public function requirements_notice(): void {
        global $wp_version;
        
        echo '<div class="notice notice-error">';
        echo '<p><strong>OSINT-LB PRO:</strong> ';
        
        if (version_compare($wp_version, self::MIN_WP_VERSION, '<')) {
            echo sprintf(
                'This plugin requires WordPress %s or higher. You are running %s.',
                self::MIN_WP_VERSION,
                $wp_version
            );
        } elseif (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            echo sprintf(
                'This plugin requires PHP %s or higher. You are running %s.',
                self::MIN_PHP_VERSION,
                PHP_VERSION
            );
        }
        
        echo '</p></div>';
    }
    
    /**
     * Activation routine
     */
    public function activate(): void {
        // Create database tables if needed
        $this->create_tables();
        
        // Set default options
        $this->set_defaults();
        
        // Log activation
        $this->log('Plugin activated', 'core');
        
        // Set activation timestamp
        update_option('osint_pro_activated_at', time());
    }
    
    /**
     * Deactivation routine
     */
    public function deactivate(): void {
        // Log deactivation
        $this->log('Plugin deactivated', 'core');
        
        // Clear any transients
        delete_transient('osint_pro_cache_*');
    }
    
    /**
     * Create database tables
     */
    protected function create_tables(): void {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Main events table (if not exists)
        $table_events = $wpdb->prefix . 'so_news_events';
        
        // We don't create the main table here as it's managed by existing code
        // This is just for additional tables needed by refactored components
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        // Example: Create intelligence cache table
        $table_intel_cache = $wpdb->prefix . 'osint_intel_cache';
        $sql_intel_cache = "CREATE TABLE IF NOT EXISTS $table_intel_cache (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cache_key varchar(191) NOT NULL,
            cache_value longtext NOT NULL,
            expires_at bigint(20) unsigned NOT NULL,
            created_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY cache_key (cache_key),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        dbDelta($sql_intel_cache);
        
        // Example: Create alert queue table
        $table_alerts = $wpdb->prefix . 'osint_alert_queue';
        $sql_alerts = "CREATE TABLE IF NOT EXISTS $table_alerts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned DEFAULT NULL,
            alert_type varchar(50) NOT NULL,
            recipient varchar(191) NOT NULL,
            payload longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int(11) NOT NULL DEFAULT 0,
            last_attempt_at bigint(20) unsigned DEFAULT NULL,
            sent_at bigint(20) unsigned DEFAULT NULL,
            error_message text,
            created_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($sql_alerts);
    }
    
    /**
     * Set default options
     */
    protected function set_defaults(): void {
        $defaults = [
            'osint_pro_version' => self::VERSION,
            'osint_pro_db_version' => '1.0',
            'osint_pro_telegram_enabled' => 'no',
            'osint_pro_telegram_bot_token' => '',
            'osint_pro_telegram_chat_id' => '',
            'osint_pro_ai_provider' => 'openai',
            'osint_pro_ai_api_key' => '',
            'osint_pro_auto_classification' => 'yes',
            'osint_pro_deduplication_enabled' => 'yes',
            'osint_pro_alert_threshold' => '70',
        ];
        
        foreach ($defaults as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }
    }
    
    /**
     * Register a module
     */
    public function register_module(string $name, object $instance): void {
        $this->modules[$name] = $instance;
    }
    
    /**
     * Get a registered module
     */
    public function get_module(string $name): ?object {
        return $this->modules[$name] ?? null;
    }
    
    /**
     * Get all registered modules
     */
    public function get_modules(): array {
        return $this->modules;
    }
}
