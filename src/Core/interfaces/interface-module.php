<?php
/**
 * Module Interface - All modules must implement this
 * 
 * @package OSINT_PRO\Core
 * @since 12.0.0
 */

namespace OSINT_PRO\Core\Interfaces;

interface Module {
    /**
     * Initialize the module
     * 
     * @return void
     */
    public function boot(): void;
    
    /**
     * Register hooks, actions, and filters
     * 
     * @return void
     */
    public function register(): void;
    
    /**
     * Module activation logic
     * 
     * @return void
     */
    public function activate(): void;
    
    /**
     * Module deactivation logic
     * 
     * @return void
     */
    public function deactivate(): void;
    
    /**
     * Check if module is available/active
     * 
     * @return bool
     */
    public function is_active(): bool;
}
