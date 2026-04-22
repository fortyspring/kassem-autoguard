<?php
/**
 * Beiruttime OSINT - Executive Reports with Hybrid Warfare Metrics
 * Generates executive reports including hybrid warfare layers, threat scores, and actor networks.
 */

if (!defined('ABSPATH')) exit;

class Sod_Executive_Reports {

    public function __construct() {
        add_shortcode('sod_executive_report', [$this, 'render_executive_report']);
        add_action('wp_ajax_sod_generate_report', [$this, 'ajax_generate_report']);
    }

    /**
     * Render the Executive Report Dashboard
     */
    public function render_executive_report($atts) {
        $atts = shortcode_atts([
            'days' => 7,
            'format' => 'html', // html, json, pdf-ready
        ], $atts);

        ob_start();
        
        $report_data = $this->generate_report_data($atts['days']);
        
        ?>
        <div class="sod-executive-report" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <h2 style="border-bottom: 2px solid #c0392b; padding-bottom: 10px; color: #2c3e50;">
                📑 التقرير التنفيذي الاستخباراتي 
                <span style="font-size: 0.6em; color: #7f8c8d;">(آخر <?php echo esc_html($atts['days']); ?> أيام)</span>
            </h2>

            <!-- ملخص الأرقام -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px;">
                <div style="background: #ecf0f1; padding: 15px; border-radius: 5px; text-align: center;">
                    <div style="font-size: 2em; font-weight: bold; color: #2c3e50;"><?php echo number_format($report_data['total_events']); ?></div>
                    <div style="color: #7f8c8d;">إجمالي الأحداث</div>
                </div>
                <div style="background: #fadbd8; padding: 15px; border-radius: 5px; text-align: center;">
                    <div style="font-size: 2em; font-weight: bold; color: #c0392b;"><?php echo number_format($report_data['high_threat_events']); ?></div>
                    <div style="color: #c0392b;">أحداث عالية التهديد</div>
                </div>
                <div style="background: #d6eaf8; padding: 15px; border-radius: 5px; text-align: center;">
                    <div style="font-size: 2em; font-weight: bold; color: #2980b9;"><?php echo number_format($report_data['multi_domain_events']); ?></div>
                    <div style="color: #2980b9;">أحداث مركبة (Multi-Domain)</div>
                </div>
                <div style="background: #fdebd0; padding: 15px; border-radius: 5px; text-align: center;">
                    <div style="font-size: 2em; font-weight: bold; color: #d35400;"><?php echo number_format($report_data['active_actors']); ?></div>
                    <div style="color: #d35400;">فاعلين نشطين</div>
                </div>
            </div>

            <!-- تحليل طبقات الحرب المركبة -->
            <h3 style="color: #2c3e50; margin-top: 30px;">🕸️ تحليل طبقات الحرب المركبة</h3>
            <div style="margin-bottom: 20px;">
                <canvas id="hybridLayersChart" height="100"></canvas>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    if (typeof Chart !== 'undefined') {
                        const ctx = document.getElementById('hybridLayersChart').getContext('2d');
                        new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: <?php echo json_encode(array_keys($report_data['layers_distribution'])); ?>,
                                datasets: [{
                                    label: 'عدد الأحداث حسب الطبقة',
                                    data: <?php echo json_encode(array_values($report_data['layers_distribution'])); ?>,
                                    backgroundColor: [
                                        '#c0392b', '#2980b9', '#27ae60', '#f39c12', '#8e44ad', 
                                        '#16a085', '#d35400', '#7f8c8d', '#2c3e50'
                                    ]
                                }]
                            },
                            options: { responsive: true, maintainAspectRatio: false }
                        });
                    }
                });
            </script>

            <!-- أبرز الفاعلين -->
            <h3 style="color: #2c3e50; margin-top: 30px;">🎯 أبرز الفاعلين والنشاط</h3>
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                <thead>
                    <tr style="background: #ecf0f1; text-align: right;">
                        <th style="padding: 10px; border: 1px solid #bdc3c7;">الفاعل</th>
                        <th style="padding: 10px; border: 1px solid #bdc3c7;">عدد الأحداث</th>
                        <th style="padding: 10px; border: 1px solid #bdc3c7;">متوسط التهديد</th>
                        <th style="padding: 10px; border: 1px solid #bdc3c7;">الطبقات المسيطرة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report_data['top_actors'] as $actor): ?>
                    <tr>
                        <td style="padding: 10px; border: 1px solid #bdc3c7;"><?php echo esc_html($actor['name']); ?></td>
                        <td style="padding: 10px; border: 1px solid #bdc3c7;"><?php echo esc_html($actor['count']); ?></td>
                        <td style="padding: 10px; border: 1px solid #bdc3c7;">
                            <span style="color: <?php echo $actor['avg_threat'] > 70 ? '#c0392b' : '#27ae60'; ?>; font-weight: bold;">
                                <?php echo esc_html($actor['avg_threat']); ?>/100
                            </span>
                        </td>
                        <td style="padding: 10px; border: 1px solid #bdc3c7; font-size: 0.9em;">
                            <?php echo esc_html(implode(', ', array_slice($actor['top_layers'], 0, 3))); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- سيناريوهات الإنذار -->
            <h3 style="color: #2c3e50; margin-top: 30px;">⚠️ مؤشرات الإنذار المبكر</h3>
            <ul style="list-style: none; padding: 0;">
                <?php foreach ($report_data['alerts'] as $alert): ?>
                <li style="background: #fff5f5; border-right: 4px solid #c0392b; padding: 10px; margin-bottom: 5px;">
                    <strong><?php echo esc_html($alert['type']); ?>:</strong> <?php echo esc_html($alert['message']); ?>
                    <small style="display:block; color: #7f8c8d; margin-top:5px;">
                        الحدث: <?php echo esc_html($alert['event_title']); ?> | التاريخ: <?php echo date('Y-m-d H:i', $alert['time']); ?>
                    </small>
                </li>
                <?php endforeach; ?>
            </ul>

        </div>
        <?php
        // Load Chart.js if not already loaded
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.0', true);
        
        return ob_get_clean();
    }

    /**
     * Generate Report Data Logic
     */
    private function generate_report_data($days) {
        global $wpdb;
        $table = $wpdb->prefix . 'so_news_events';
        $since = time() - ($days * 24 * 3600);

        // Basic Counts
        $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE event_timestamp >= %d", $since));
        $high_threat = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE event_timestamp >= %d AND threat_score >= 70", $since));
        $multi_domain = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE event_timestamp >= %d AND multi_domain_score >= 30", $since));

        // Layers Distribution
        $layers_dist = [];
        $all_layers = ['Military', 'Security', 'Cyber', 'Political', 'Economic', 'Social', 'Energy', 'Geo', 'Media'];
        foreach ($all_layers as $layer) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE event_timestamp >= %d AND hybrid_layers LIKE %s", 
                $since, '%' . $layer . '%'
            ));
            $layers_dist[$layer] = (int)$count;
        }

        // Top Actors
        $actors_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT primary_actor, COUNT(*) as count, AVG(threat_score) as avg_threat, GROUP_CONCAT(DISTINCT osint_type) as types
             FROM $table 
             WHERE event_timestamp >= %d AND primary_actor IS NOT NULL AND primary_actor != ''
             GROUP BY primary_actor
             ORDER BY count DESC
             LIMIT 10", 
            $since
        ), ARRAY_A);

        $top_actors = [];
        foreach ($actors_raw as $row) {
            $top_actors[] = [
                'name' => $row['primary_actor'],
                'count' => $row['count'],
                'avg_threat' => round($row['avg_threat']),
                'top_layers' => !empty($row['types']) ? explode(',', $row['types']) : []
            ];
        }

        // Alerts
        $alerts_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT title, alert_type, alert_reason, event_timestamp 
             FROM $table 
             WHERE event_timestamp >= %d AND alert_flag = 1 
             ORDER BY threat_score DESC 
             LIMIT 5", 
            $since
        ), ARRAY_A);

        $alerts = [];
        foreach ($alerts_raw as $row) {
            $alerts[] = [
                'type' => $row['alert_type'] ?? 'تنبيه عام',
                'message' => $row['alert_reason'] ?? 'ارتفاع مستوى التهديد',
                'event_title' => $row['title'],
                'time' => $row['event_timestamp']
            ];
        }

        return [
            'total_events' => $total,
            'high_threat_events' => $high_threat,
            'multi_domain_events' => $multi_domain,
            'active_actors' => count($top_actors),
            'layers_distribution' => $layers_dist,
            'top_actors' => $top_actors,
            'alerts' => $alerts
        ];
    }

    /**
     * AJAX Handler for generating downloadable reports (JSON/PDF prep)
     */
    public function ajax_generate_report() {
        check_ajax_referer('sod_report_nonce', 'nonce');
        
        $days = isset($_POST['days']) ? intval($_POST['days']) : 7;
        $data = $this->generate_report_data($days);
        
        wp_send_json_success($data);
    }
}

new Sod_Executive_Reports();
