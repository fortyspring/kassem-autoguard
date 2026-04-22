<?php
/**
 * Module Interface
 * 
 * All OSINT modules must implement this interface.
 * 
 * @package BeirutTime_OSINT_Pro
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

interface OSINT_Module_Interface {
    
    /**
     * Get module ID (unique identifier)
     * 
     * @return string
     */
    public function get_id();
    
    /**
     * Get module name (human readable)
     * 
     * @return string
     */
    public function get_name();
    
    /**
     * Get module version
     * 
     * @return string
     */
    public function get_version();
    
    /**
     * Check if module is active
     * 
     * @return bool
     */
    public function is_active();
    
    /**
     * Initialize module (register hooks, scripts, etc.)
     * 
     * @return void
     */
    public function init();
    
    /**
     * Handle AJAX requests for this module
     * 
     * @param string $action The action type
     * @param array $data Request data
     * @return array Result array with 'success', 'data', and 'message' keys
     */
    public function handle_ajax($action, $data);
    
    /**
     * Get module configuration/options
     * 
     * @return array
     */
    public function get_config();
    
    /**
     * Render module content (if applicable)
     * 
     * @return string HTML content
     */
    public function render();
    
    /**
     * Module cleanup/deactivation
     * 
     * @return void
     */
    public function deactivate();
}
