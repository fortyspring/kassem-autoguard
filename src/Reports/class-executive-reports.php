<?php
/**
 * Executive Reports Engine
 * 
 * Generates professional intelligence reports with AI assistance.
 * Single source of truth for report generation.
 * 
 * @package OSINT_LB_PRO
 * @subpackage Reports
 * @since 2.0.0
 */

namespace OSINT_LB_PRO\Reports;

use OSINT_LB_PRO\Security\Security_Manager;

class Executive_Reports {
    
    /**
     * Security manager instance
     * 
     * @var Security_Manager
     */
    private $security;
    
    /**
     * Report templates cache
     * 
     * @var array
     */
    private $templates = [];
    
    /**
     * Constructor
     * 
     * @param Security_Manager $security Security manager instance
     */
    public function __construct(Security_Manager $security) {
        $this->security = $security;
        
        add_action('osint_generate_report', [$this, 'generate_report'], 10, 1);
    }
    
    /**
     * Initialize reports engine
     * 
     * @return void
     */
    public function init(): void {
        add_action('admin_init', [$this, 'register_report_actions']);
    }
    
    /**
     * Register report-related actions
     * 
     * @return void
     */
    public function register_report_actions(): void {
        // Report generation scheduled by AJAX
    }
    
    /**
     * Generate executive report
     * 
     * @param array $args Report arguments
     * @return array|WP_Error
     */
    public function generate_report(array $args = []) {
        $defaults = [
            'timeframe' => 24,
            'type' => 'executive',
            'format' => 'html',
            'include_recommendations' => true,
            'include_anomalies' => true
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        $hours = absint($args['timeframe']);
        $interval = "INTERVAL {$hours} HOUR";
        
        // Gather statistics
        $stats = $this->gather_statistics($hours);
        
        // Get top actors
        $top_actors = $this->get_top_actors($hours);
        
        // Get hot zones
        $hot_zones = $this->get_hot_zones($hours);
        
        // Get severity trend
        $severity_trend = $this->get_severity_trend($hours);
        
        // Detect anomalies
        $anomalies = $args['include_anomalies'] ? $this->detect_anomalies($stats) : [];
        
        // Generate recommendations
        $recommendations = $args['include_recommendations'] ? $this->generate_recommendations($stats, $anomalies) : [];
        
        // Build narrative
        $narrative = $this->build_narrative($stats, $top_actors, $hot_zones, $severity_trend);
        
        $report = [
            'metadata' => [
                'generated_at' => current_time('mysql'),
                'timeframe_hours' => $hours,
                'type' => sanitize_text_field($args['type']),
                'format' => sanitize_text_field($args['format'])
            ],
            'summary' => [
                'total_events' => $stats['total_events'],
                'critical_events' => $stats['critical_events'],
                'avg_severity' => $stats['avg_severity'],
                'unique_actors' => count($top_actors),
                'active_regions' => count($hot_zones)
            ],
            'statistics' => $stats,
            'top_actors' => $top_actors,
            'hot_zones' => $hot_zones,
            'severity_trend' => $severity_trend,
            'anomalies' => $anomalies,
            'recommendations' => $recommendations,
            'narrative' => $narrative
        ];
        
        // Store report
        $report_id = $this->store_report($report);
        $report['id'] = $report_id;
        
        return $report;
    }
    
    /**
     * Gather statistics for timeframe
     * 
     * @param int $hours Timeframe in hours
     * @return array
     */
    private function gather_statistics(int $hours): array {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        $row = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_events,
                SUM(CASE WHEN severity >= 80 THEN 1 ELSE 0 END) as critical_events,
                SUM(CASE WHEN severity >= 60 AND severity < 80 THEN 1 ELSE 0 END) as high_events,
                SUM(CASE WHEN severity >= 40 AND severity < 60 THEN 1 ELSE 0 END) as medium_events,
                SUM(CASE WHEN severity < 40 THEN 1 ELSE 0 END) as low_events,
                AVG(severity) as avg_severity,
                MAX(severity) as max_severity,
                MIN(severity) as min_severity
            FROM {$table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
        ", ARRAY_A);
        
        return array_map(function($val) {
            return $val !== null ? (float) $val : 0;
        }, $row);
    }
    
    /**
     * Get top actors for timeframe
     * 
     * @param int $hours Timeframe in hours
     * @return array
     */
    private function get_top_actors(int $hours): array {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        return $wpdb->get_results("
            SELECT actor_name, COUNT(*) as event_count, AVG(severity) as avg_severity
            FROM {$table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
            AND actor_name IS NOT NULL AND actor_name != ''
            GROUP BY actor_name
            ORDER BY event_count DESC, avg_severity DESC
            LIMIT 10
        ", ARRAY_A);
    }
    
    /**
     * Get hot zones for timeframe
     * 
     * @param int $hours Timeframe in hours
     * @return array
     */
    private function get_hot_zones(int $hours): array {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        return $wpdb->get_results("
            SELECT region, country, COUNT(*) as event_count, 
                   AVG(severity) as avg_severity,
                   MAX(severity) as max_severity
            FROM {$table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
            AND region IS NOT NULL AND region != ''
            GROUP BY region, country
            ORDER BY event_count DESC, avg_severity DESC
            LIMIT 15
        ", ARRAY_A);
    }
    
    /**
     * Get severity trend over time
     * 
     * @param int $hours Timeframe in hours
     * @return array
     */
    private function get_severity_trend(int $hours): array {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        return $wpdb->get_results("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as hour,
                COUNT(*) as event_count,
                AVG(severity) as avg_severity,
                MAX(severity) as max_severity
            FROM {$table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
            GROUP BY hour
            ORDER BY hour ASC
        ", ARRAY_A);
    }
    
    /**
     * Detect anomalies in the data
     * 
     * @param array $stats Statistics data
     * @return array
     */
    private function detect_anomalies(array $stats): array {
        $anomalies = [];
        
        // Spike detection
        if ($stats['total_events'] > 50) {
            $anomalies[] = [
                'type' => 'volume_spike',
                'severity' => 'high',
                'description' => sprintf(
                    __('ارتفاع غير طبيعي في عدد الأحداث: %d حدث', 'osint-lb-pro'),
                    $stats['total_events']
                )
            ];
        }
        
        // High severity concentration
        if ($stats['critical_events'] > 10) {
            $anomalies[] = [
                'type' => 'severity_concentration',
                'severity' => 'critical',
                'description' => sprintf(
                    __('تركيز عالي للأحداث الحرجة: %d حدث حرج', 'osint-lb-pro'),
                    $stats['critical_events']
                )
            ];
        }
        
        // Average severity anomaly
        if ($stats['avg_severity'] > 70) {
            $anomalies[] = [
                'type' => 'elevated_threat_level',
                'severity' => 'high',
                'description' => sprintf(
                    __('مستوى تهديد مرتفع بشكل عام: متوسط %d', 'osint-lb-pro'),
                    round($stats['avg_severity'])
                )
            ];
        }
        
        return $anomalies;
    }
    
    /**
     * Generate recommendations based on data
     * 
     * @param array $stats Statistics data
     * @param array $anomalies Detected anomalies
     * @return array
     */
    private function generate_recommendations(array $stats, array $anomalies): array {
        $recommendations = [];
        
        foreach ($anomalies as $anomaly) {
            switch ($anomaly['type']) {
                case 'volume_spike':
                    $recommendations[] = [
                        'priority' => 'high',
                        'action' => __('زيادة مراقبة المصادر وتفعيل التنبيهات الفورية', 'osint-lb-pro'),
                        'reason' => $anomaly['description']
                    ];
                    break;
                    
                case 'severity_concentration':
                    $recommendations[] = [
                        'priority' => 'critical',
                        'action' => __('إصدار تحذير عاجل للجهات المعنية وبدء بروتوكول الاستجابة', 'osint-lb-pro'),
                        'reason' => $anomaly['description']
                    ];
                    break;
                    
                case 'elevated_threat_level':
                    $recommendations[] = [
                        'priority' => 'high',
                        'action' => __('رفع مستوى الجاهزية وتشديد إجراءات المراقبة', 'osint-lb-pro'),
                        'reason' => $anomaly['description']
                    ];
                    break;
            }
        }
        
        // Default recommendation if no anomalies
        if (empty($recommendations)) {
            $recommendations[] = [
                'priority' => 'normal',
                'action' => __('متابعة المراقبة الروتينية والحفاظ على اليقظة', 'osint-lb-pro'),
                'reason' => __('لا توجد شذوذات ملحوظة في الفترة الحالية', 'osint-lb-pro')
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Build strategic narrative
     * 
     * @param array $stats Statistics
     * @param array $top_actors Top actors
     * @param array $hot_zones Hot zones
     * @param array $severity_trend Severity trend
     * @return string
     */
    private function build_narrative(array $stats, array $top_actors, array $hot_zones, array $severity_trend): string {
        $narrative = [];
        
        // Opening summary
        $narrative[] = sprintf(
            __('خلال الـ %d ساعة الماضية، تم رصد %d حدث أمني واستخباراتي، منها %d أحداث حرجة.', 'osint-lb-pro'),
            24,
            $stats['total_events'],
            $stats['critical_events']
        );
        
        // Actor analysis
        if (!empty($top_actors)) {
            $top_actor = $top_actors[0];
            $narrative[] = sprintf(
                __('الفاعل الأبرز هو "%s" بمجموع %d أحداث ومتوسط خطورة %d.', 'osint-lb-pro'),
                esc_html($top_actor['actor_name']),
                $top_actor['event_count'],
                round($top_actor['avg_severity'])
            );
        }
        
        // Regional focus
        if (!empty($hot_zones)) {
            $hot_zone = $hot_zones[0];
            $narrative[] = sprintf(
                __('المنطقة الأكثر نشاطاً هي %s/%s بواقع %d حدث.', 'osint-lb-pro'),
                esc_html($hot_zone['region']),
                esc_html($hot_zone['country']),
                $hot_zone['event_count']
            );
        }
        
        // Trend analysis
        if (!empty($severity_trend)) {
            $recent = array_slice($severity_trend, -6);
            $trend_direction = 'مستقر';
            
            if (count($recent) >= 2) {
                $first_half = array_slice($recent, 0, count($recent) / 2);
                $second_half = array_slice($recent, count($recent) / 2);
                
                $first_avg = array_sum(array_column($first_half, 'avg_severity')) / count($first_half);
                $second_avg = array_sum(array_column($second_half, 'avg_severity')) / count($second_half);
                
                if ($second_avg > $first_avg * 1.2) {
                    $trend_direction = 'متصاعد';
                } elseif ($second_avg < $first_avg * 0.8) {
                    $trend_direction = 'متناقص';
                }
            }
            
            $narrative[] = sprintf(
                __('اتجاه مستوى الخطورة خلال الساعات الأخيرة: %s.', 'osint-lb-pro'),
                $trend_direction
            );
        }
        
        return implode(' ', $narrative);
    }
    
    /**
     * Store report in database
     * 
     * @param array $report Report data
     * @return int|WP_Error
     */
    private function store_report(array $report) {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_reports';
        
        $result = $wpdb->insert($table, [
            'report_type' => $report['metadata']['type'],
            'timeframe_hours' => $report['metadata']['timeframe_hours'],
            'report_data' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'generated_at' => current_time('mysql'),
            'status' => 'completed'
        ], ['%s', '%d', '%s', '%s', '%s']);
        
        if ($result === false) {
            return new \WP_Error('report_storage_failed', $wpdb->last_error);
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get report by ID
     * 
     * @param int $report_id Report ID
     * @return array|null
     */
    public function get_report(int $report_id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_reports';
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $report_id
        ), ARRAY_A);
        
        if (!$row) {
            return null;
        }
        
        $row['report_data'] = json_decode($row['report_data'], true);
        
        return $row;
    }
    
    /**
     * Get recent reports
     * 
     * @param int $limit Number of reports
     * @return array
     */
    public function get_recent_reports(int $limit = 10): array {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_reports';
        
        return $wpdb->get_results("
            SELECT id, report_type, timeframe_hours, generated_at, status
            FROM {$table}
            ORDER BY generated_at DESC
            LIMIT {$limit}
        ", ARRAY_A);
    }
}
