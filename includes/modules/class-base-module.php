<?php
/**
 * Base Module Class
 * 
 * Abstract class that provides common functionality for all modules.
 * 
 * @package BeirutTime_OSINT_Pro
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class OSINT_Base_Module implements OSINT_Module_Interface {
    
    protected $core;
    protected $id;
    protected $name;
    protected $version;
    protected $active = true;
    protected $config = array();
    protected $cache = null;
    
    /**
     * Constructor
     * 
     * @param OSINT_Modular_Core $core Core instance
     */
    public function __construct($core) {
        $this->core = $core;
        $this->cache = $core->get_cache();
        $this->id = $this->get_id();
        $this->name = $this->get_name();
        $this->version = $this->get_version();
        $this->load_config();
    }
    
    /**
     * Load module configuration
     */
    protected function load_config() {
        $default_config = $this->get_default_config();
        $saved_config = get_option('osint_module_' . $this->id . '_config', array());
        $this->config = wp_parse_args($saved_config, $default_config);
    }
    
    /**
     * Get default configuration
     * 
     * @return array
     */
    protected function get_default_config() {
        return array(
            'enabled' => true,
            'cache_ttl' => 3600,
        );
    }
    
    /**
     * {@inheritdoc}
     */
    public function is_active() {
        return $this->active && ($this->config['enabled'] ?? true);
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_config() {
        return $this->config;
    }
    
    /**
     * Save module configuration
     * 
     * @param array $config Configuration to save
     * @return bool
     */
    public function save_config($config) {
        $this->config = wp_parse_args($config, $this->config);
        return update_option('osint_module_' . $this->id . '_config', $this->config);
    }
    
    /**
     * Get cached data or execute callback
     * 
     * @param string $key Cache key
     * @param callable $callback Function to execute if cache miss
     * @param int $ttl Time to live in seconds
     * @return mixed
     */
    protected function get_cached($key, $callback, $ttl = null) {
        if (!$this->cache) {
            return call_user_func($callback);
        }
        
        $cache_key = 'module_' . $this->id . '_' . $key;
        $ttl = $ttl ?? ($this->config['cache_ttl'] ?? 3600);
        
        $cached = $this->cache->get($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $data = call_user_func($callback);
        $this->cache->set($cache_key, $data, $ttl);
        
        return $data;
    }
    
    /**
     * Clear module cache
     * 
     * @param string|null $key Specific key to clear (null for all)
     * @return bool
     */
    protected function clear_cache($key = null) {
        if (!$this->cache) {
            return false;
        }
        
        if ($key) {
            return $this->cache->delete('module_' . $this->id . '_' . $key);
        }
        
        return $this->cache->clear_group('module_' . $this->id);
    }
    
    /**
     * Log module activity
     * 
     * @param string $message Log message
     * @param string $level Log level (info, warning, error)
     * @return void
     */
    protected function log($message, $level = 'info') {
        if (defined('OSINT_DEBUG') && OSINT_DEBUG) {
            error_log('[OSINT ' . strtoupper($level) . '][' . $this->name . '] ' . $message);
        }
    }
    
    /**
     * Validate AJAX request
     * 
     * @param array $data Request data
     * @param array $required Required fields
     * @return array|WP_Error Validated data or WP_Error
     */
    protected function validate_ajax_request($data, $required = array()) {
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', 'Missing required field: ' . $field);
            }
        }
        
        // Sanitize common field types
        $sanitized = array();
        foreach ($data as $key => $value) {
            if (is_numeric($value)) {
                $sanitized[$key] = intval($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = sanitize_text_field($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * {@inheritdoc}
     */
    public function handle_ajax($action, $data) {
        $method = 'ajax_' . $action;
        
        if (!method_exists($this, $method)) {
            return array(
                'success' => false,
                'message' => 'Invalid action: ' . $action,
                'data' => null,
            );
        }
        
        try {
            $result = $this->$method($data);
            return array(
                'success' => true,
                'message' => 'Success',
                'data' => $result,
            );
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            );
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function deactivate() {
        $this->active = false;
        $this->clear_cache();
        delete_option('osint_module_' . $this->id . '_config');
        do_action('osint_module_deactivated', $this->id);
    }
}
