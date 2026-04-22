<?php
/**
 * Integrations Module
 * 
 * External service integrations:
 * - Telegram bot for alerts and reports
 * - WebSocket handler for real-time updates
 * - REST API endpoints
 * - Webhook dispatcher
 * 
 * @package OSINT_PRO/Integrations
 * @version 1.0.0
 */

namespace OSINT_PRO\Integrations;

use OSINT_PRO\Core\Interfaces\Module_Interface;
use OSINT_PRO\Core\Utilities;

if (!defined('ABSPATH')) {
    exit;
}

class Integrations_Module implements Module_Interface {
    
    /**
     * Module name
     */
    const NAME = 'integrations';
    
    /**
     * Telegram API base URL
     */
    const TELEGRAM_API = 'https://api.telegram.org/bot';
    
    /**
     * Initialize module
     */
    public function init(): void {
        $this->register_ajax_handlers();
        $this->register_rest_routes();
        $this->register_hooks();
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers(): void {
        add_action('wp_ajax_osint_route', [$this, 'handle_ajax_request']);
    }
    
    /**
     * Register REST API routes
     */
    private function register_rest_routes(): void {
        add_action('rest_api_init', [$this, 'register_rest_endpoints']);
    }
    
    /**
     * Register WordPress hooks
     */
    private function register_hooks(): void {
        // Hook into new high-threat events
        add_action('osint_new_high_threat_event', [$this, 'send_instant_alert'], 10, 2);
    }
    
    /**
     * Register REST API endpoints
     */
    public function register_rest_endpoints(): void {
        register_rest_route('osint/v1', '/telegram/test', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_test_telegram'],
            'permission_callback' => [$this, 'rest_permission_check'],
        ]);
        
        register_rest_route('osint/v1', '/webhook/dispatch', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_dispatch_webhook'],
            'permission_callback' => '__return_true',
        ]);
        
        register_rest_route('osint/v1', '/events/stream', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_events_stream'],
            'permission_callback' => [$this, 'rest_permission_check'],
        ]);
    }
    
    /**
     * REST API permission check
     */
    public function rest_permission_check(\WP_REST_Request $request): bool {
        return current_user_can('manage_options');
    }
    
    /**
     * Handle AJAX requests via router
     */
    public function handle_ajax_request(array $request): array {
        $endpoint = $request['endpoint'] ?? '';
        $parts = explode('.', $endpoint);
        
        if ($parts[0] !== 'integrations') {
            return ['success' => false, 'message' => 'Not an integrations endpoint'];
        }
        
        $action = $parts[1] ?? '';
        
        switch ($action) {
            case 'test_telegram':
                return $this->ajax_test_telegram($request);
            case 'send_telegram_message':
                return $this->ajax_send_telegram_message($request);
            case 'configure_webhook':
                return $this->ajax_configure_webhook($request);
            default:
                return ['success' => false, 'message' => 'Unknown action'];
        }
    }
    
    /**
     * Test Telegram via AJAX
     */
    private function ajax_test_telegram(array $request): array {
        try {
            $token = sanitize_text_field(get_option('osint_telegram_token', ''));
            $chat_id = sanitize_text_field(get_option('osint_telegram_chat_id', ''));
            
            if (empty($token) || empty($chat_id)) {
                throw new \Exception('يرجى إعداد توكن البوت ومعرف المحادثة أولاً');
            }
            
            $message = "🧪 اختبار اتصال OSINT PRO\n\n";
            $message .= "تم إرسال هذه الرسالة بنجاح!\n";
            $message .= "الوقت: " . date('Y-m-d H:i:s');
            
            $result = $this->send_telegram_message($chat_id, $message);
            
            return [
                'success' => $result,
                'message' => $result ? 'تم الإرسال بنجاح ✅' : 'فشل الإرسال ❌',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Send Telegram message via AJAX
     */
    private function ajax_send_telegram_message(array $request): array {
        try {
            $chat_id = sanitize_text_field($request['params']['chat_id'] ?? get_option('osint_telegram_chat_id', ''));
            $message = sanitize_textarea_field($request['params']['message'] ?? '');
            
            if (empty($chat_id)) {
                throw new \Exception('معرف المحادثة مطلوب');
            }
            
            if (empty($message)) {
                throw new \Exception('الرسالة فارغة');
            }
            
            $result = $this->send_telegram_message($chat_id, $message);
            
            return [
                'success' => $result,
                'message' => $result ? 'تم الإرسال بنجاح' : 'فشل الإرسال',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Configure webhook via AJAX
     */
    private function ajax_configure_webhook(array $request): array {
        try {
            $webhook_url = esc_url_raw($request['params']['webhook_url'] ?? '');
            
            if (empty($webhook_url)) {
                // Clear webhook
                delete_option('osint_webhook_url');
                
                return [
                    'success' => true,
                    'message' => 'تم حذف webhook',
                ];
            }
            
            update_option('osint_webhook_url', $webhook_url);
            
            return [
                'success' => true,
                'message' => 'تم حفظ webhook بنجاح',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Test Telegram via REST API
     */
    public function rest_test_telegram(\WP_REST_Request $request): \WP_REST_Response {
        $result = $this->ajax_test_telegram(['params' => []]);
        
        return new \WP_REST_Response($result, $result['success'] ? 200 : 400);
    }
    
    /**
     * Dispatch webhook via REST API
     */
    public function rest_dispatch_webhook(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $data = $request->get_json_params();
            
            if (!$data) {
                throw new \Exception('Invalid JSON payload');
            }
            
            $webhook_url = get_option('osint_webhook_url', '');
            
            if (empty($webhook_url)) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'No webhook configured',
                ], 400);
            }
            
            $response = wp_remote_post($webhook_url, [
                'method' => 'POST',
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($data),
                'timeout' => 15,
            ]);
            
            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message());
            }
            
            $status = wp_remote_retrieve_response_code($response);
            
            return new \WP_REST_Response([
                'success' => $status >= 200 && $status < 300,
                'status' => $status,
            ], $status);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
    
    /**
     * Events stream via REST API (SSE)
     */
    public function rest_events_stream(\WP_REST_Request $request): \WP_REST_Response {
        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        
        global $wpdb;
        $table = $wpdb->prefix . 'so_news_events';
        
        $last_id = intval($request->get_param('last_id') ?? 0);
        
        while (true) {
            $events = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 10",
                $last_id
            ), ARRAY_A);
            
            if (!empty($events)) {
                foreach ($events as $event) {
                    echo "data: " . json_encode($event) . "\n\n";
                    $last_id = $event['id'];
                }
                ob_flush();
                flush();
            }
            
            // Check if client is still connected
            if (connection_aborted()) {
                break;
            }
            
            sleep(2);
        }
        
        exit;
    }
    
    /**
     * Send Telegram message
     * 
     * @param string $chat_id Chat ID or username
     * @param string $message Message text
     * @param string $parse_mode Parse mode (HTML, Markdown, etc.)
     * @return bool Success status
     */
    public function send_telegram_message(string $chat_id, string $message, string $parse_mode = 'HTML'): bool {
        $token = get_option('osint_telegram_token', '');
        
        if (empty($token)) {
            error_log('Telegram token not configured');
            return false;
        }
        
        $url = self::TELEGRAM_API . $token . '/sendMessage';
        
        $response = wp_remote_post($url, [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => $parse_mode,
                'disable_web_page_preview' => true,
            ]),
            'timeout' => 15,
        ]);
        
        if (is_wp_error($response)) {
            error_log('Telegram API error: ' . $response->get_error_message());
            return false;
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status !== 200 || !($body['ok'] ?? false)) {
            error_log('Telegram API response: ' . print_r($body, true));
            return false;
        }
        
        return true;
    }
    
    /**
     * Send instant alert for high-threat event
     * 
     * @param int $event_id Event ID
     * @param array $event Event data
     */
    public function send_instant_alert(int $event_id, array $event): void {
        $enabled = get_option('osint_instant_alerts_enabled', false);
        
        if (!$enabled) {
            return;
        }
        
        $threshold = intval(get_option('osint_instant_alerts_threshold', 80));
        
        if (($event['threat_score'] ?? 0) < $threshold) {
            return;
        }
        
        $chat_id = get_option('osint_telegram_chat_id', '');
        
        if (empty($chat_id)) {
            return;
        }
        
        $alert = "🚨 تنبيه فوري - تهديد عالي الخطورة\n\n";
        $alert .= "📍 الموقع: " . ($event['location'] ?? 'غير محدد') . "\n";
        $alert .= "🎯 الجهة: " . ($event['primary_actor'] ?? 'غير محدد') . "\n";
        $alert .= "⚠️ مستوى التهديد: " . ($event['threat_score'] ?? 0) . "/100\n";
        $alert .= "📝 العنوان: " . ($event['title'] ?? '') . "\n\n";
        $alert .= "🕐 الوقت: " . ($event['event_timestamp'] ?? date('Y-m-d H:i:s'));
        
        $this->send_telegram_message($chat_id, $alert);
    }
    
    /**
     * Send executive report to Telegram
     * 
     * @param array $report Report data
     * @return bool Success status
     */
    public function send_report_to_telegram(array $report): bool {
        $chat_id = get_option('osint_telegram_chat_id', '');
        
        if (empty($chat_id)) {
            return false;
        }
        
        $summary = $report['summary'] ?? [];
        $meta = $report['meta'] ?? [];
        
        $message = "📊 التقرير التنفيذي - {$meta['label']}\n\n";
        $message .= "━━━━━━━━━━━━━━━━━━\n\n";
        $message .= "📌 " . ($summary['headline'] ?? 'لا يوجد ملخص') . "\n\n";
        $message .= "📈 الإحصائيات:\n";
        
        $stats = $report['statistics']['threat_distribution'] ?? [];
        $message .= "• حرجة: " . ($stats['critical'] ?? 0) . "\n";
        $message .= "• عالية: " . ($stats['high'] ?? 0) . "\n";
        $message .= "• متوسطة: " . ($stats['medium'] ?? 0) . "\n";
        $message .= "• منخفضة: " . ($stats['low'] ?? 0) . "\n\n";
        
        $message .= "🔹 إجمالي الأحداث: " . ($meta['total_events'] ?? 0) . "\n";
        $message .= "🕐 تاريخ الإنشاء: " . ($meta['generated_at'] ?? date('Y-m-d H:i:s'));
        
        return $this->send_telegram_message($chat_id, $message);
    }
    
    /**
     * Dispatch webhook notification
     * 
     * @param string $event_type Event type
     * @param array $data Event data
     * @return bool Success status
     */
    public function dispatch_webhook(string $event_type, array $data): bool {
        $webhook_url = get_option('osint_webhook_url', '');
        
        if (empty($webhook_url)) {
            return false;
        }
        
        $payload = [
            'event_type' => $event_type,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data,
        ];
        
        $response = wp_remote_post($webhook_url, [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($payload),
            'timeout' => 15,
        ]);
        
        if (is_wp_error($response)) {
            error_log('Webhook dispatch error: ' . $response->get_error_message());
            return false;
        }
        
        $status = wp_remote_retrieve_response_code($response);
        
        return $status >= 200 && $status < 300;
    }
    
    /**
     * Get module info
     */
    public function get_info(): array {
        return [
            'name' => self::NAME,
            'label' => 'التكاملات الخارجية',
            'version' => '1.0.0',
            'description' => 'نظام التكامل مع الخدمات الخارجية',
            'features' => [
                'إرسال تنبيهات Telegram',
                'تقارير Telegram تلقائية',
                'REST API كاملة',
                'Webhook dispatcher',
                'Server-Sent Events (SSE)',
                'تنبيهات فورية للأحداث الحرجة',
            ],
            'settings' => [
                'osint_telegram_token' => 'توكن بوت Telegram',
                'osint_telegram_chat_id' => 'معرف المحادثة',
                'osint_instant_alerts_enabled' => 'تفعيل التنبيهات الفورية',
                'osint_instant_alerts_threshold' => 'عتبة التنبيه (0-100)',
                'osint_webhook_url' => 'رابط Webhook الخارجي',
            ],
        ];
    }
}
