<?php
/**
 * Alert Dispatcher Service
 * 
 * Handles alert generation, queuing, and dispatch to Telegram/other channels.
 * Production-safe with retry logic and deduplication.
 * 
 * @package OSINT_LB_PRO
 * @subpackage Alerts
 * @since 2.0.0
 */

namespace OSINT_LB_PRO\Alerts;

use OSINT_LB_PRO\Security\Security_Manager;

class Alert_Dispatcher {
    
    /**
     * Security manager instance
     * 
     * @var Security_Manager
     */
    private $security;
    
    /**
     * Alert queue
     * 
     * @var array
     */
    private $queue = [];
    
    /**
     * Dispatch log
     * 
     * @var array
     */
    private $dispatch_log = [];
    
    /**
     * Constructor
     * 
     * @param Security_Manager $security Security manager instance
     */
    public function __construct(Security_Manager $security) {
        $this->security = $security;
        
        add_action('osint_trigger_alert', [$this, 'trigger_alert'], 10, 2);
        add_action('osint_send_test_alert', [$this, 'send_test_alert']);
    }
    
    /**
     * Initialize dispatcher
     * 
     * @return void
     */
    public function init(): void {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('osint_cron_dispatch_alerts', [$this, 'process_queue']);
    }
    
    /**
     * Register alert settings
     * 
     * @return void
     */
    public function register_settings(): void {
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
        
        register_setting('osint_alerts', 'osint_alert_dedupe_window', [
            'sanitize_callback' => 'absint',
            'default' => 300
        ]);
        
        register_setting('osint_alerts', 'osint_alert_retry_count', [
            'sanitize_callback' => 'absint',
            'default' => 3
        ]);
    }
    
    /**
     * Trigger alert for an event
     * 
     * @param int $event_id Event ID
     * @param array $event_data Event data
     * @return bool|WP_Error
     */
    public function trigger_alert(int $event_id, array $event_data = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        if (empty($event_data)) {
            $event_data = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $event_id
            ), ARRAY_A);
            
            if (!$event_data) {
                return new \WP_Error('event_not_found', __('الحدث غير موجود', 'osint-lb-pro'));
            }
        }
        
        $min_severity = absint(get_option('osint_alert_min_severity', 70));
        
        if ($event_data['severity'] < $min_severity) {
            return new \WP_Error(
                'below_threshold',
                sprintf(__('الخطورة %d أقل من الحد الأدنى %d', 'osint-lb-pro'), 
                    $event_data['severity'], $min_severity)
            );
        }
        
        if ($this->is_duplicate_alert($event_data)) {
            return new \WP_Error('duplicate_alert', __('تنبيه مكرر', 'osint-lb-pro'));
        }
        
        $alert = [
            'event_id' => $event_id,
            'event_data' => $event_data,
            'created_at' => time(),
            'retry_count' => 0,
            'status' => 'pending'
        ];
        
        $this->queue[] = $alert;
        $this->store_alert($alert);
        
        if (count($this->queue) <= 5) {
            return $this->dispatch_alert($alert);
        }
        
        return true;
    }
    
    /**
     * Check if alert is duplicate
     * 
     * @param array $event_data Event data
     * @return bool
     */
    private function is_duplicate_alert(array $event_data): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_alerts';
        
        $dedupe_window = absint(get_option('osint_alert_dedupe_window', 300));
        $content_hash = md5($event_data['title'] . $event_data['region']);
        
        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$table}
            WHERE content_hash = %s
            AND created_at >= DATE_SUB(NOW(), INTERVAL %d SECOND)
            AND status != 'failed'
        ", $content_hash, $dedupe_window));
        
        return (bool) $exists;
    }
    
    /**
     * Store alert in database
     * 
     * @param array $alert Alert data
     * @return int|false
     */
    private function store_alert(array $alert) {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_alerts';
        
        $content_hash = md5($alert['event_data']['title'] . $alert['event_data']['region']);
        
        return $wpdb->insert($table, [
            'event_id' => $alert['event_id'],
            'content_hash' => $content_hash,
            'alert_data' => json_encode($alert, JSON_UNESCAPED_UNICODE),
            'severity' => $alert['event_data']['severity'],
            'status' => $alert['status'],
            'retry_count' => $alert['retry_count'],
            'created_at' => current_time('mysql')
        ], ['%d', '%s', '%s', '%d', '%s', '%d', '%s']);
    }
    
    /**
     * Dispatch alert to channels
     * 
     * @param array $alert Alert data
     * @return bool|WP_Error
     */
    private function dispatch_alert(array $alert) {
        $telegram_enabled = get_option('osint_telegram_enabled', false);
        $results = [];
        
        if ($telegram_enabled) {
            $result = $this->send_telegram_alert($alert);
            $results['telegram'] = $result;
        }
        
        $success = !in_array(false, $results, true);
        $this->update_alert_status($alert['event_id'], $success ? 'sent' : 'failed', $results);
        $this->log_dispatch($alert, $results);
        
        return $success ? true : new \WP_Error('dispatch_failed', 'Dispatch failed');
    }
    
    /**
     * Send alert via Telegram
     * 
     * @param array $alert Alert data
     * @return bool
     */
    private function send_telegram_alert(array $alert): bool {
        $bot_token = get_option('osint_telegram_bot_token', '');
        $chat_id = get_option('osint_telegram_chat_id', '');
        
        if (empty($bot_token) || empty($chat_id)) {
            return false;
        }
        
        $event = $alert['event_data'];
        $message = $this->build_telegram_message($event);
        
        $api_url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
        
        $response = wp_remote_post($api_url, [
            'timeout' => 15,
            'body' => [
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => 'HTML'
            ]
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return isset($body['ok']) && $body['ok'];
    }
    
    /**
     * Build Telegram message
     * 
     * @param array $event Event data
     * @return string
     */
    private function build_telegram_message(array $event): string {
        $severity_emoji = $this->get_severity_emoji($event['severity']);
        $severity_label = $this->get_severity_label($event['severity']);
        
        $message = sprintf("%s <b>تنبيه أمني - %s</b>\n\n", $severity_emoji, $severity_label);
        $message .= sprintf("<b>📌 العنوان:</b> %s\n\n", esc_html($event['title']));
        
        if (!empty($event['region'])) {
            $message .= sprintf("<b>🌍 المنطقة:</b> %s\n", esc_html($event['region']));
        }
        
        if (!empty($event['country'])) {
            $message .= sprintf("<b>🏳️ الدولة:</b> %s\n", esc_html($event['country']));
        }
        
        if (!empty($event['actor_name'])) {
            $message .= sprintf("<b>👤 الفاعل:</b> %s\n", esc_html($event['actor_name']));
        }
        
        $message .= sprintf("<b>⚠️ الخطورة:</b> %d/100\n", $event['severity']);
        $message .= sprintf("<b>📊 النوع:</b> %s\n", esc_html($event['event_type']));
        
        if (!empty($event['source_url'])) {
            $message .= sprintf("\n🔗 <a href=\"%s\">المصدر</a>", esc_url($event['source_url']));
        }
        
        return $message;
    }
    
    /**
     * Get severity emoji
     * 
     * @param int $severity Severity score
     * @return string
     */
    private function get_severity_emoji(int $severity): string {
        if ($severity >= 80) return '🔴';
        if ($severity >= 60) return '🟠';
        if ($severity >= 40) return '🟡';
        return '🟢';
    }
    
    /**
     * Get severity label
     * 
     * @param int $severity Severity score
     * @return string
     */
    private function get_severity_label(int $severity): string {
        if ($severity >= 80) return 'حرج';
        if ($severity >= 60) return 'عالي';
        if ($severity >= 40) return 'متوسط';
        return 'منخفض';
    }
    
    /**
     * Update alert status
     * 
     * @param int $event_id Event ID
     * @param string $status Status
     * @param array $results Results
     * @return void
     */
    private function update_alert_status(int $event_id, string $status, array $results): void {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_alerts';
        
        $wpdb->query($wpdb->prepare("
            UPDATE {$table}
            SET status = %s, dispatch_results = %s, updated_at = NOW()
            WHERE event_id = %d
            ORDER BY id DESC LIMIT 1
        ", $status, json_encode($results), $event_id));
    }
    
    /**
     * Process alert queue
     * 
     * @param int $limit Limit
     * @return array
     */
    public function process_queue(int $limit = 10): array {
        $results = ['processed' => 0, 'success' => 0, 'failed' => 0, 'errors' => []];
        
        global $wpdb;
        $table = $wpdb->prefix . 'osint_alerts';
        $max_retries = absint(get_option('osint_alert_retry_count', 3));
        
        $pending = $wpdb->get_results("
            SELECT * FROM {$table}
            WHERE status = 'pending' AND retry_count < {$max_retries}
            ORDER BY created_at ASC LIMIT {$limit}
        ", ARRAY_A);
        
        foreach ($pending as $alert_row) {
            $results['processed']++;
            $alert_data = json_decode($alert_row['alert_data'], true);
            
            $success = $this->dispatch_alert($alert_data);
            
            if ($success) {
                $results['success']++;
            } else {
                $results['failed']++;
                $wpdb->query($wpdb->prepare("
                    UPDATE {$table}
                    SET retry_count = retry_count + 1, updated_at = NOW()
                    WHERE id = %d
                ", $alert_row['id']));
            }
        }
        
        return $results;
    }
    
    /**
     * Send test alert
     * 
     * @return bool
     */
    public function send_test_alert() {
        $test_event = [
            'id' => 0,
            'title' => 'تنبيه تجريبي - اختبار النظام',
            'content' => 'اختبار نظام الإشعارات',
            'region' => 'لبنان',
            'country' => 'LB',
            'severity' => 50,
            'event_type' => 'test',
            'source_url' => home_url()
        ];
        
        return $this->trigger_alert(0, $test_event);
    }
    
    /**
     * Log dispatch
     * 
     * @param array $alert Alert
     * @param array $results Results
     * @return void
     */
    private function log_dispatch(array $alert, array $results): void {
        $this->dispatch_log[] = [
            'event_id' => $alert['event_id'],
            'timestamp' => time(),
            'results' => $results
        ];
    }
}
