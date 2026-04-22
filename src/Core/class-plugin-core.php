<?php
/**
 * OSINT-LB PRO - Core Plugin Class
 * 
 * Main plugin orchestrator that loads and manages all modules
 * 
 * @package     OSINT_PRO\Core
 * @author      Production Architect
 * @since       12.0.0
 */

namespace OSINT_PRO\Core;

use OSINT_PRO\Core\Interfaces\Module;

class Plugin_Core implements Module {
    
    /**
     * Plugin version
     * 
     * @var string
     */
    const VERSION = OSINT_PRO_VERSION;
    
    /**
     * Singleton instance
     * 
     * @var self|null
     */
    private static ?self $instance = null;
    
    /**
     * Registered modules
     * 
     * @var array<string, Module>
     */
    private array $modules = [];
    
    /**
     * Module load order
     * 
     * @var array<string>
     */
    private array $module_order = [
        'Core',
        'Admin',
        'Dashboard',
        'AJAX',
        'Intelligence',
        'Reports',
        'Reindex',
        'WorldMonitor',
        'AI',
        'Integrations',
    ];
    
    /**
     * Whether plugin is active
     * 
     * @var bool
     */
    private bool $is_active = false;
    
    /**
     * Get singleton instance
     * 
     * @return self
     */
    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Constructor - prevent direct instantiation
     */
    private function __construct() {
        // Private constructor for singleton
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new \Exception('Cannot unserialize singleton');
    }
    
    /**
     * {@inheritDoc}
     */
    public function boot(): void {
        // Load core utilities first
        $this->load_utilities();
        
        // Register all modules
        $this->register_modules();
        
        // Boot modules in order
        foreach ($this->module_order as $module_name) {
            if (isset($this->modules[$module_name])) {
                $this->modules[$module_name]->boot();
            }
        }
        
        // Mark as active
        $this->is_active = true;
        
        // Fire action for other plugins
        do_action('osint_pro_loaded', $this);
    }
    
    /**
     * {@inheritDoc}
     */
    public function register(): void {
        foreach ($this->modules as $module) {
            $module->register();
        }
    }
    
    /**
     * {@inheritDoc}
     */
    public function activate(): void {
        foreach ($this->modules as $module) {
            $module->activate();
        }
    }
    
    /**
     * {@inheritDoc}
     */
    public function deactivate(): void {
        foreach ($this->modules as $module) {
            $module->deactivate();
        }
        
        $this->is_active = false;
    }
    
    /**
     * {@inheritDoc}
     */
    public function is_active(): bool {
        return $this->is_active;
    }
    
    /**
     * Get a registered module
     * 
     * @param string $name
     * @return Module|null
     */
    public function get_module(string $name): ?Module {
        return $this->modules[$name] ?? null;
    }
    
    /**
     * Check if module is registered
     * 
     * @param string $name
     * @return bool
     */
    public function has_module(string $name): bool {
        return isset($this->modules[$name]);
    }
    
    /**
     * Load core utilities
     * 
     * @return void
     */
    private function load_utilities(): void {
        // Utilities are loaded via autoloader when needed
    }
    
    /**
     * Register all modules
     * 
     * @return void
     */
    private function register_modules(): void {
        // Core module (always loaded)
        $this->register_module('Core', new Core_Module());
        
        // Admin module
        if (is_admin()) {
            $this->register_module('Admin', new Admin\Admin_Module());
        }
        
        // Dashboard module
        $this->register_module('Dashboard', new Dashboard\Dashboard_Module());
        
        // AJAX module
        $this->register_module('AJAX', new AJAX\AJAX_Module());
        
        // Intelligence module
        $this->register_module('Intelligence', new Intelligence\Intelligence_Module());
        
        // Reports module
        $this->register_module('Reports', new Reports\Reports_Module());
        
        // Reindex module
        $this->register_module('Reindex', new Reindex\Reindex_Module());
        
        // WorldMonitor module
        $this->register_module('WorldMonitor', new WorldMonitor\WorldMonitor_Module());
        
        // AI module
        $this->register_module('AI', new AI\AI_Module());
        
        // Integrations module
        $this->register_module('Integrations', new Integrations\Integrations_Module());
    }
    
    /**
     * Register a single module
     * 
     * @param string $name
     * @param Module $module
     * @return void
     */
    private function register_module(string $name, Module $module): void {
        $this->modules[$name] = $module;
    }
    
    /**
     * Get all registered modules
     * 
     * @return array<string, Module>
     */
    public function get_modules(): array {
        return $this->modules;
    }
    
    /**
     * Get plugin info
     * 
     * @return array<string, mixed>
     */
    public function get_info(): array {
        return [
            'version' => self::VERSION,
            'path' => OSINT_PRO_PLUGIN_DIR,
            'url' => OSINT_PRO_PLUGIN_URL,
            'basename' => OSINT_PRO_PLUGIN_BASENAME,
            'is_active' => $this->is_active,
            'modules_count' => count($this->modules),
            'modules' => array_keys($this->modules),
        ];
    }
}
