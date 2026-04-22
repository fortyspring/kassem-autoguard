<?php
/**
 * AJAX Handlers Manager
 * 
 * Centralized AJAX endpoint handler for all OSINT operations.
 * Single source of truth for AJAX requests.
 * 
 * @package OSINT_LB_PRO
 * @subpackage Ajax
 * @since 2.0.0
 */

namespace OSINT_LB_PRO\Ajax;

use OSINT_LB_PRO\Security\Security_Manager;

class Ajax_Handlers {
    
    /**
     * Security manager instance
     * 
     * @var Security_Manager
     */
    private $security;
    
    /**
     * Registered endpoints
     * 
     * @var array
     */
    private $endpoints = [
        'get_dashboard_data' => ['method' => 'POST', 'public' => false],
        'get_world_monitor_snapshot' => ['method' => 'POST', 'public' => false],
        'get_intelligence_feed' => ['method' => 'POST', 'public' => false],
        'generate_report' => ['method' => 'POST', 'public' => false],
        'trigger_reindex' => ['method' => 'POST', 'public' => false],
        'cleanup_duplicates' => ['method' => 'POST', 'public' => false],
        'classify_event' => ['method' => 'POST', 'public' => false],
        'send_test_alert' => ['method' => 'POST', 'public' => false],
        'update_source_status' => ['method' => 'POST', 'public' => false],
        'get_event_details' => ['method' => 'GET', 'public' => false]
    ];
    
    /**
     * Constructor
     * 
     * @param Security_Manager $security Security manager instance
     */
    public function __construct(Security_Manager $security) {
        $this->security = $security;
    }
    
    /**
     * Initialize AJAX handlers
     * 
     * @return void
     */
    public function init(): void {
        foreach ($this->endpoints as $action => $config) {
            add_action("wp_ajax_osint_{$action}", [$this, "handle_{$action}"]);
            
            if ($config['public']) {
                add_action("wp_ajax_nopriv_osint_{$action}", [$this, "handle_{$action}"]);
            }
        }
    }
    
    /**
     * Handle dashboard data request
     * 
     * @return void
     */
    public function handle_get_dashboard_data(): void {
        $this->security->verify_nonce('osint_dashboard');
        $this->security->verify_capability('manage_options');
        
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        $data = [
            'total_events' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'critical_events' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE severity >= 80"),
            'active_threats' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND threat_level >= 70"),
            'sources_active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}osint_sources WHERE status = 'active'"),
            'recent_events' => $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 10", ARRAY_A),
            'hot_zones' => $wpdb->get_results("
                SELECT region, COUNT(*) as count, AVG(severity) as avg_severity
                FROM {$table}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY region
                ORDER BY count DESC
                LIMIT 10
            ", ARRAY_A)
        ];
        
        wp_send_json_success($data);
    }
    
    /**
     * Handle world monitor snapshot request
     * 
     * @return void
     */
    public function handle_get_world_monitor_snapshot(): void {
        $this->security->verify_nonce('osint_world_monitor');
        $this->security->verify_capability('manage_options');
        
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        $timeframe = isset($_POST['timeframe']) ? absint($_POST['timeframe']) : 24;
        
        $data = [
            'events' => $wpdb->get_results($wpdb->prepare("
                SELECT id, title, region, country, latitude, longitude, 
                       severity, threat_level, event_type, created_at
                FROM {$table}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)
                AND latitude IS NOT NULL AND longitude IS NOT NULL
                ORDER BY created_at DESC
                LIMIT 500
            ", $timeframe), ARRAY_A),
            
            'kpi_summary' => [
                'total' => (int) $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) FROM {$table}
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)
                ", $timeframe)),
                
                'critical' => (int) $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) FROM {$table}
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)
                    AND severity >= 80
                ", $timeframe)),
                
                'by_region' => $wpdb->get_results($wpdb->prepare("
                    SELECT region, COUNT(*) as count
                    FROM {$table}
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)
                    GROUP BY region
                    ORDER BY count DESC
                ", $timeframe), ARRAY_A)
            ],
            
            'timestamp' => current_time('mysql')
        ];
        
        wp_send_json_success($data);
    }
    
    /**
     * Handle intelligence feed request
     * 
     * @return void
     */
    public function handle_get_intelligence_feed(): void {
        $this->security->verify_nonce('osint_intelligence');
        $this->security->verify_capability('manage_options');
        
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        $filters = [];
        $where = ['1=1'];
        
        if (isset($_POST['status'])) {
            $where[] = $wpdb->prepare('status = %s', sanitize_text_field($_POST['status']));
        }
        
        if (isset($_POST['min_severity'])) {
            $where[] = $wpdb->prepare('severity >= %d', absint($_POST['min_severity']));
        }
        
        if (isset($_POST['region'])) {
            $where[] = $wpdb->prepare('region = %s', sanitize_text_field($_POST['region']));
        }
        
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 50;
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        
        $where_clause = implode(' AND ', $where);
        
        $data = [
            'events' => $wpdb->get_results("
                SELECT * FROM {$table}
                WHERE {$where_clause}
                ORDER BY created_at DESC
                LIMIT {$offset}, {$limit}
            ", ARRAY_A),
            
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where_clause}")
        ];
        
        wp_send_json_success($data);
    }
    
    /**
     * Handle report generation request
     * 
     * @return void
     */
    public function handle_generate_report(): void {
        $this->security->verify_nonce('osint_reports');
        $this->security->verify_capability('manage_options');
        
        $timeframe = isset($_POST['timeframe']) ? absint($_POST['timeframe']) : 24;
        $report_type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'executive';
        
        // Delegate to Reports service
        do_action('osint_generate_report', [
            'timeframe' => $timeframe,
            'type' => $report_type
        ]);
        
        wp_send_json_success([
            'message' => __('جاري توليد التقرير...', 'osint-lb-pro'),
            'timeframe' => $timeframe,
            'type' => $report_type
        ]);
    }
    
    /**
     * Handle reindex trigger
     * 
     * @return void
     */
    public function handle_trigger_reindex(): void {
        $this->security->verify_nonce('osint_admin');
        $this->security->verify_capability('manage_options');
        
        // Schedule immediate reindex
        wp_schedule_single_event(time(), 'osint_cron_reindex');
        
        wp_send_json_success([
            'message' => __('تم جدولة إعادة الفهرسة', 'osint-lb-pro')
        ]);
    }
    
    /**
     * Handle duplicate cleanup
     * 
     * @return void
     */
    public function handle_cleanup_duplicates(): void {
        $this->security->verify_nonce('osint_admin');
        $this->security->verify_capability('manage_options');
        
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        // Find and mark duplicates
        $result = $wpdb->query("
            DELETE t1 FROM {$table} t1
            INNER JOIN {$table} t2
            WHERE t1.id < t2.id
            AND t1.content_hash = t2.content_hash
        ");
        
        wp_send_json_success([
            'message' => sprintf(
                __('تم حذف %d حدث مكرر', 'osint-lb-pro'),
                $result
            ),
            'deleted_count' => $result
        ]);
    }
    
    /**
     * Handle event classification
     * 
     * @return void
     */
    public function handle_classify_event(): void {
        $this->security->verify_nonce('osint_intelligence');
        $this->security->verify_capability('manage_options');
        
        $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
        
        if (!$event_id) {
            wp_send_json_error(['message' => __('معرف الحدث مطلوب', 'osint-lb-pro')]);
        }
        
        // Trigger AI classification
        do_action('osint_classify_event', $event_id);
        
        wp_send_json_success([
            'message' => __('جاري تصنيف الحدث...', 'osint-lb-pro'),
            'event_id' => $event_id
        ]);
    }
    
    /**
     * Handle test alert
     * 
     * @return void
     */
    public function handle_send_test_alert(): void {
        $this->security->verify_nonce('osint_alerts');
        $this->security->verify_capability('manage_options');
        
        // Trigger test alert
        do_action('osint_send_test_alert');
        
        wp_send_json_success([
            'message' => __('تم إرسال تنبيه تجريبي', 'osint-lb-pro')
        ]);
    }
    
    /**
     * Handle source status update
     * 
     * @return void
     */
    public function handle_update_source_status(): void {
        $this->security->verify_nonce('osint_admin');
        $this->security->verify_capability('manage_options');
        
        $source_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'active';
        
        if (!$source_id) {
            wp_send_json_error(['message' => __('معرف المصدر مطلوب', 'osint-lb-pro')]);
        }
        
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'osint_sources',
            ['status' => $status],
            ['id' => $source_id],
            ['%s'],
            ['%d']
        );
        
        wp_send_json_success([
            'message' => __('تم تحديث حالة المصدر', 'osint-lb-pro'),
            'source_id' => $source_id,
            'status' => $status
        ]);
    }
    
    /**
     * Handle event details request
     * 
     * @return void
     */
    public function handle_get_event_details(): void {
        $this->security->verify_nonce('osint_dashboard');
        $this->security->verify_capability('manage_options');
        
        $event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
        
        if (!$event_id) {
            wp_send_json_error(['message' => __('معرف الحدث مطلوب', 'osint-lb-pro')]);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $event_id
        ), ARRAY_A);
        
        if (!$event) {
            wp_send_json_error(['message' => __('الحدث غير موجود', 'osint-lb-pro')]);
        }
        
        wp_send_json_success($event);
    }
    
    /**
     * Send JSON error response
     * 
     * @param string $message Error message
     * @param int $code HTTP status code
     * @return void
     */
    private function send_error(string $message, int $code = 400): void {
        http_response_code($code);
        wp_send_json_error(['message' => $message]);
    }
}
