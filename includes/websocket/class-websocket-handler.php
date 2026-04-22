<?php
/**
 * WebSocket Handler for Real-time Updates
 * 
 * Provides WebSocket support for real-time OSINT data updates.
 * Uses Ratchet library or falls back to Server-Sent Events (SSE).
 * 
 * @package BeirutTime_OSINT_Pro
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OSINT_WebSocket_Handler {
    
    private static $instance = null;
    private $websocket_url = '';
    private $sse_enabled = false;
    private $connections = array();
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->setup();
    }
    
    /**
     * Setup WebSocket/SSE
     */
    private function setup() {
        // Check if Ratchet is available
        if (class_exists('Ratchet\Server\IoServer')) {
            $this->websocket_url = $this->get_websocket_url();
        }
        
        // SSE is always available as fallback
        $this->sse_enabled = true;
        
        // Register AJAX endpoints for real-time communication - REMOVED nopriv for security
        add_action('wp_ajax_osint_subscribe', array($this, 'handle_subscribe'));
        add_action('wp_ajax_osint_sse', array($this, 'handle_sse'));
    }
    
    /**
     * Get WebSocket server URL
     * 
     * @return string
     */
    private function get_websocket_url() {
        $host = defined('OSINT_WS_HOST') ? OSINT_WS_HOST : get_site_url();
        $port = defined('OSINT_WS_PORT') ? OSINT_WS_PORT : 8080;
        
        // Parse host to get domain
        $parsed = parse_url($host);
        $domain = $parsed['host'] ?? 'localhost';
        
        return 'ws://' . $domain . ':' . $port;
    }
    
    /**
     * Handle subscription request
     */
    public function handle_subscribe() {
        // Verify nonce for CSRF protection
        check_ajax_referer('osint_nonce', 'nonce');
        
        // Check user capabilities - require authenticated users with proper permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'غير مصرح - يتطلب صلاحيات إدارة'), 403);
            return;
        }
        
        $channels = isset($_POST['channels']) ? $_POST['channels'] : array('general');
        $channels = array_map('sanitize_text_field', $channels);
        
        $subscription = array(
            'user_id' => get_current_user_id(),
            'channels' => $channels,
            'timestamp' => time(),
            'token' => wp_generate_password(32, false),
        );
        
        // Store subscription with encryption for sensitive data
        $encrypted_subscription = base64_encode(json_encode($subscription));
        set_transient('osint_sub_' . $subscription['token'], $encrypted_subscription, HOUR_IN_SECONDS);
        
        wp_send_json_success(array(
            'token' => $subscription['token'],
            'websocket_url' => $this->websocket_url,
            'sse_url' => admin_url('admin-ajax.php?action=osint_sse&token=' . $subscription['token']),
        ));
    }
    
    /**
     * Handle Server-Sent Events stream
     */
    public function handle_sse() {
        // Verify nonce for CSRF protection
        check_ajax_referer('osint_nonce', 'nonce');
        
        // Check user capabilities - require authenticated users with proper permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'غير مصرح - يتطلب صلاحيات إدارة'), 403);
            return;
        }
        
        // Disable compression and caching for SSE
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        
        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable Nginx buffering
        
        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
        
        if (empty($token)) {
            echo "data: " . json_encode(array('error' => 'Missing token')) . "\n\n";
            flush();
            exit;
        }
        
        // Verify subscription with decryption
        $encrypted_subscription = get_transient('osint_sub_' . $token);
        if (!$encrypted_subscription) {
            echo "data: " . json_encode(array('error' => 'Invalid token')) . "\n\n";
            flush();
            exit;
        }
        
        // Decrypt subscription data
        $subscription = json_decode(base64_decode($encrypted_subscription), true);
        if (!$subscription || !is_array($subscription)) {
            echo "data: " . json_encode(array('error' => 'Invalid subscription data')) . "\n\n";
            flush();
            exit;
        }
        
        // Send initial connection message
        $this->send_event('connected', array(
            'message' => 'Connected to OSINT real-time updates',
            'channels' => $subscription['channels'],
        ));
        
        // Keep connection alive
        $last_update = time();
        $timeout = 300; // 5 minutes timeout
        
        while (connection_status() === CONNECTION_NORMAL && (time() - $last_update) < $timeout) {
            // Check for new data in channels
            foreach ($subscription['channels'] as $channel) {
                $new_data = $this->get_channel_updates($channel, $last_update);
                
                if (!empty($new_data)) {
                    $this->send_event($channel, $new_data);
                    $last_update = time();
                }
            }
            
            // Send heartbeat every 30 seconds
            if (time() % 30 === 0) {
                $this->send_event('heartbeat', array('timestamp' => time()));
            }
            
            sleep(1);
        }
        
        exit;
    }
    
    /**
     * Send SSE event
     * 
     * @param string $event Event name
     * @param array $data Event data
     */
    private function send_event($event, $data) {
        echo "event: " . $event . "\n";
        echo "data: " . json_encode($data) . "\n\n";
        flush();
    }
    
    /**
     * Get updates for a channel
     * 
     * @param string $channel Channel name
     * @param int $since_timestamp Get updates since this time
     * @return array
     */
    private function get_channel_updates($channel, $since_timestamp) {
        $cache_key = 'osint_updates_' . $channel . '_' . $since_timestamp;
        
        // Try to get from cache first
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $updates = array();
        
        switch ($channel) {
            case 'alerts':
                $updates = $this->get_alert_updates($since_timestamp);
                break;
                
            case 'map':
                $updates = $this->get_map_updates($since_timestamp);
                break;
                
            case 'analysis':
                $updates = $this->get_analysis_updates($since_timestamp);
                break;
                
            default:
                // General updates
                $updates = $this->get_general_updates($since_timestamp);
        }
        
        // Cache for short period
        if (!empty($updates)) {
            set_transient($cache_key, $updates, 30);
        }
        
        return $updates;
    }
    
    /**
     * Get alert updates
     */
    private function get_alert_updates($since) {
        global $wpdb;
        
        // SECURITY: Sanitize table name - only allow alphanumeric and underscore
        $table_name = 'osint_alerts';
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
            error_log('[OSINT] Invalid table name attempted: ' . $table_name);
            return array();
        }
        $table = $wpdb->prefix . $table_name;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE created_at > %d ORDER BY created_at DESC LIMIT 10",
            $since
        ));
        
        return $results ? (array) $results : array();
    }
    
    /**
     * Get map updates (new incidents, location changes)
     */
    private function get_map_updates($since) {
        global $wpdb;
        
        // SECURITY: Sanitize table name - only allow alphanumeric and underscore
        $table_name = 'osint_incidents';
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
            error_log('[OSINT] Invalid table name attempted: ' . $table_name);
            return array();
        }
        $table = $wpdb->prefix . $table_name;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, lat, lng, type, status, created_at 
             FROM {$table} 
             WHERE created_at > %d OR updated_at > %d 
             ORDER BY created_at DESC LIMIT 20",
            $since,
            $since
        ));
        
        return $results ? (array) $results : array();
    }
    
    /**
     * Get analysis updates
     */
    private function get_analysis_updates($since) {
        // Check for completed AI analysis tasks
        $transients = get_option('_transients', array());
        $updates = array();
        
        foreach ($transients as $key => $value) {
            if (strpos($key, 'osint_analysis_') === 0) {
                $updates[] = array(
                    'task_id' => str_replace('osint_analysis_', '', $key),
                    'status' => 'completed',
                );
            }
        }
        
        return $updates;
    }
    
    /**
     * Get general updates
     */
    private function get_general_updates($since) {
        return array(
            'message' => 'System operational',
            'timestamp' => time(),
        );
    }
    
    /**
     * Broadcast message to all subscribers of a channel
     * 
     * @param string $channel Channel name
     * @param array $data Message data
     */
    public function broadcast($channel, $data) {
        // Store in transient for SSE clients to pick up
        $cache_key = 'osint_broadcast_' . $channel . '_' . time();
        set_transient($cache_key, $data, MINUTE_IN_SECONDS);
        
        // If WebSocket server is running, send via WebSocket
        if (!empty($this->websocket_url) && class_exists('Ratchet\MessageComponentInterface')) {
            $this->send_via_websocket($channel, $data);
        }
        
        // Trigger WordPress action for other integrations
        do_action('osint_realtime_broadcast', $channel, $data);
    }
    
    /**
     * Send message via WebSocket (requires external server)
     */
    private function send_via_websocket($channel, $data) {
        // This would require a running Ratchet server
        // Implementation depends on server setup
        error_log('[OSINT] WebSocket broadcast to ' . $channel . ': ' . json_encode($data));
    }
    
    /**
     * Enqueue frontend scripts for real-time updates
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            'osint-realtime',
            OSINT_PRO_PLUGIN_URL . 'assets/js/modules/realtime.js',
            array('jquery'),
            OSINT_PRO_VERSION,
            true
        );
        
        wp_localize_script('osint-realtime', 'osintRealtimeConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('osint_nonce'),
            'websocketUrl' => $this->websocket_url,
            'sseEnabled' => $this->sse_enabled,
            'reconnectInterval' => 5000,
        ));
    }
}

// Initialize WebSocket handler early
add_action('plugins_loaded', function() {
    OSINT_WebSocket_Handler::get_instance();
}, 10);
