<?php
namespace OSINT_PRO\AJAX;

use OSINT_PRO\Core\Interfaces\Module;

class AJAX_Module implements Module {
    private bool $is_active = false;
    private array $endpoints = [];
    
    public function boot(): void {
        $this->register_hooks();
        $this->register_endpoints();
        $this->is_active = true;
    }
    
    public function register(): void {}
    public function activate(): void {}
    public function deactivate(): void { $this->is_active = false; }
    public function is_active(): bool { return $this->is_active; }
    
    private function register_hooks(): void {
        add_action('wp_ajax_osint_route', [$this, 'route_request']);
    }
    
    private function register_endpoints(): void {
        $this->endpoints['world_monitor.snapshot'] = ['callback' => [$this, 'handle_world_monitor_snapshot'], 'capability' => 'manage_options'];
        $this->endpoints['reports.generate'] = ['callback' => [$this, 'handle_generate_report'], 'capability' => 'manage_options'];
        $this->endpoints['cleanup.batch'] = ['callback' => [$this, 'handle_cleanup_batch'], 'capability' => 'manage_options'];
    }
    
    public function route_request(): void {
        check_ajax_referer('osint_pro_ajax', 'nonce');
        $endpoint = isset($_POST['endpoint']) ? sanitize_text_field($_POST['endpoint']) : '';
        
        if (empty($endpoint) || !isset($this->endpoints[$endpoint])) {
            wp_send_json_error(['message' => 'Invalid endpoint']);
        }
        
        $config = $this->endpoints[$endpoint];
        if (!current_user_can($config['capability'])) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        try {
            call_user_func($config['callback']);
        } catch (\Exception $e) {
            osint_log('AJAX error: ' . $e->getMessage(), 'error', 'ajax:' . $endpoint);
            wp_send_json_error(['message' => 'Internal error']);
        }
    }
    
    public function handle_world_monitor_snapshot(): void {
        $wm = osint_pro()->get_module('WorldMonitor');
        if ($wm && method_exists($wm, 'ajax_snapshot')) { $wm->ajax_snapshot(); }
        else { wp_send_json_error(['message' => 'Module not available']); }
    }
    
    public function handle_generate_report(): void {
        $rpt = osint_pro()->get_module('Reports');
        if ($rpt && method_exists($rpt, 'ajax_generate_report')) { $rpt->ajax_generate_report(); }
        else { wp_send_json_error(['message' => 'Module not available']); }
    }
    
    public function handle_cleanup_batch(): void {
        $rx = osint_pro()->get_module('Reindex');
        if ($rx && method_exists($rx, 'ajax_cleanup_batch')) { $rx->ajax_cleanup_batch(); }
        else { wp_send_json_error(['message' => 'Module not available']); }
    }
}
