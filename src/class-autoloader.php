<?php
/**
 * Autoloader for OSINT Pro Refactored Classes
 * 
 * PSR-4 style autoloader for the refactored namespace structure
 * Supports both SO\ and App\ namespaces
 * 
 * @package OSINT_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(function ($class) {
    $base_dir = dirname(__DIR__) . '/src/';
    
    // Support App\ namespace (e.g., App\Engines\ActorDecisionEngineV3)
    if (strpos($class, 'App\\') === 0) {
        $relative_class = substr($class, 4);
        $file_path = str_replace('\\', '/', $relative_class);
        $app_path = $base_dir . $file_path . '.php';
        if (file_exists($app_path)) { 
            require_once $app_path; 
            return;
        }
    }
    
    if (strpos($class, 'SO\\') !== 0) { return; }

    // Remove SO\ prefix
    $relative_class = substr($class, 3);
    
    // Convert namespace to path
    $file_path = str_replace('\\', '/', $relative_class);
    
    // Base directory for SO namespace
    $base_dir = dirname(__DIR__) . '/src/';
    
    // Try different class types
    $possible_paths = [
        $base_dir . $file_path . '.php',
        $base_dir . 'utils/class-' . strtolower(basename($file_path)) . '.php',
        $base_dir . 'services/class-' . strtolower(basename($file_path)) . '.php',
        $base_dir . 'classifiers/class-' . strtolower(basename($file_path)) . '.php',
        $base_dir . 'pipeline/class-' . strtolower(basename($file_path)) . '.php',
        $base_dir . 'admin/class-' . strtolower(basename($file_path)) . '.php',
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

/**
 * Initialize refactored components
 * 
 * Call this function after WordPress is loaded to initialize
 * all refactored services and utilities
 */
function so_init_refactored_components() {
    // Load utility classes
    require_once dirname(__DIR__) . '/src/utils/class-text-cleaner.php';
    require_once dirname(__DIR__) . '/src/utils/class-fingerprint-builder.php';
    
    // Load service classes
    require_once dirname(__DIR__) . '/src/services/class-duplicate-cleaner.php';
    
    // Load classifier classes
    require_once dirname(__DIR__) . '/src/classifiers/class-actor-engine.php';
    
    // Load pipeline classes
    require_once dirname(__DIR__) . '/src/pipeline/class-event-classifier.php';
    
    // Register AJAX handlers for refactored services
    add_action('wp_ajax_so_duplicate_cleanup_batch', ['SO\\Services\\DuplicateCleaner', 'ajaxBatch']);
    add_action('wp_ajax_so_duplicate_cleanup_reset', ['SO\\Services\\DuplicateCleaner', 'ajaxReset']);
}

// Hook into WordPress init
add_action('plugins_loaded', 'so_init_refactored_components', 5);
