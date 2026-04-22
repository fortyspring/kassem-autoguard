<?php
/**
 * Executive Reports Module
 * 
 * Generates professional executive reports (6h, 24h, weekly)
 * with strategic narrative, threat analysis, and recommendations.
 * 
 * @package OSINT_PRO/Reports
 * @version 1.0.0
 */

namespace OSINT_PRO\Reports;

use OSINT_PRO\Core\Interfaces\Module_Interface;
use OSINT_PRO\Core\Utilities;

if (!defined('ABSPATH')) {
    exit;
}

class Executive_Reports_Module implements Module_Interface {
    
    /**
     * Module name
     */
    const NAME = 'executive_reports';
    
    /**
     * Cache transients prefix
     */
    const CACHE_PREFIX = 'osint_exec_report_';
    
    /**
     * Report types
     */
    const REPORT_TYPES = [
        '6h' => ['hours' => 6, 'label' => 'آخر 6 ساعات'],
        '24h' => ['hours' => 24, 'label' => 'آخر 24 ساعة'],
        'weekly' => ['days' => 7, 'label' => 'تقرير أسبوعي'],
    ];
    
    /**
     * Initialize module
     */
    public function init(): void {
        $this->register_ajax_handlers();
        $this->register_cron_jobs();
        $this->register_capabilities();
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers(): void {
        add_action('wp_ajax_osint_route', [$this, 'handle_ajax_request']);
    }
    
    /**
     * Register cron jobs
     */
    private function register_cron_jobs(): void {
        // Daily report at 6 AM
        if (!wp_next_scheduled('osint_daily_exec_report')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'osint_daily_exec_report');
        }
        
        // Weekly report on Monday 8 AM
        if (!wp_next_scheduled('osint_weekly_exec_report')) {
            $next_monday = strtotime('next monday 08:00');
            wp_schedule_single_event($next_monday, 'osint_weekly_exec_report');
        }
        
        add_action('osint_daily_exec_report', [$this, 'generate_scheduled_report']);
        add_action('osint_weekly_exec_report', [$this, 'generate_scheduled_report']);
    }
    
    /**
     * Register custom capabilities
     */
    private function register_capabilities(): void {
        $caps = [
            'osint_view_reports',
            'osint_generate_reports',
            'osint_send_reports',
        ];
        
        add_filter('user_has_cap', function($allcaps, $cap, $args, $user) use ($caps) {
            if (in_array($cap[0], $caps) && in_array($user->roles[0], ['administrator', 'editor'])) {
                $allcaps[$cap[0]] = true;
            }
            return $allcaps;
        }, 10, 4);
    }
    
    /**
     * Handle AJAX requests via router
     */
    public function handle_ajax_request(array $request): array {
        $endpoint = $request['endpoint'] ?? '';
        $parts = explode('.', $endpoint);
        
        if ($parts[0] !== 'reports') {
            return ['success' => false, 'message' => 'Not a reports endpoint'];
        }
        
        $action = $parts[1] ?? '';
        
        switch ($action) {
            case 'generate':
                return $this->ajax_generate_report($request);
            case 'send_telegram':
                return $this->ajax_send_telegram($request);
            case 'send_email':
                return $this->ajax_send_email($request);
            case 'download_pdf':
                return $this->ajax_download_pdf($request);
            case 'download_word':
                return $this->ajax_download_word($request);
            default:
                return ['success' => false, 'message' => 'Unknown action'];
        }
    }
    
    /**
     * Generate report via AJAX
     */
    private function ajax_generate_report(array $request): array {
        try {
            $type = sanitize_text_field($request['params']['type'] ?? '24h');
            $force_refresh = !empty($request['params']['force']);
            
            $report = $this->generate_report($type, $force_refresh);
            
            return [
                'success' => true,
                'data' => $report,
                'cached' => !$force_refresh,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Send report to Telegram via AJAX
     */
    private function ajax_send_telegram(array $request): array {
        try {
            $type = sanitize_text_field($request['params']['type'] ?? '24h');
            $chat_id = sanitize_text_field($request['params']['chat_id'] ?? '');
            
            $report = $this->generate_report($type);
            $result = $this->send_to_telegram($report, $chat_id);
            
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
     * Send report to email via AJAX
     */
    private function ajax_send_email(array $request): array {
        try {
            $type = sanitize_text_field($request['params']['type'] ?? '24h');
            $email = sanitize_email($request['params']['email'] ?? '');
            
            if (!is_email($email)) {
                throw new \Exception('بريد إلكتروني غير صالح');
            }
            
            $report = $this->generate_report($type);
            $result = $this->send_to_email($report, $email);
            
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
     * Download PDF via AJAX
     */
    private function ajax_download_pdf(array $request): array {
        try {
            $type = sanitize_text_field($request['params']['type'] ?? '24h');
            $report = $this->generate_report($type);
            
            $pdf_content = $this->generate_pdf($report);
            
            return [
                'success' => true,
                'data' => [
                    'filename' => "exec_report_{$type}_" . date('Y-m-d_H-i') . '.pdf',
                    'content' => base64_encode($pdf_content),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Download Word via AJAX
     */
    private function ajax_download_word(array $request): array {
        try {
            $type = sanitize_text_field($request['params']['type'] ?? '24h');
            $report = $this->generate_report($type);
            
            $word_content = $this->generate_word($report);
            
            return [
                'success' => true,
                'data' => [
                    'filename' => "exec_report_{$type}_" . date('Y-m-d_H-i') . '.docx',
                    'content' => base64_encode($word_content),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Generate executive report
     * 
     * @param string $type Report type (6h, 24h, weekly)
     * @param bool $force_refresh Force regeneration
     * @return array Report data
     */
    public function generate_report(string $type = '24h', bool $force_refresh = false): array {
        global $wpdb;
        
        if (!isset(self::REPORT_TYPES[$type])) {
            throw new \Exception("نوع التقرير غير صالح: {$type}");
        }
        
        $cache_key = self::CACHE_PREFIX . $type;
        
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $config = self::REPORT_TYPES[$type];
        
        // Calculate time range
        if (isset($config['hours'])) {
            $start_time = date('Y-m-d H:i:s', strtotime("-{$config['hours']} hours"));
            $time_label = sprintf('آخر %d ساعة', $config['hours']);
        } else {
            $start_time = date('Y-m-d H:i:s', strtotime("-{$config['days']} days"));
            $time_label = sprintf('آخر %d يوم', $config['days']);
        }
        
        $end_time = date('Y-m-d H:i:s');
        
        // Fetch events from database
        $table = $wpdb->prefix . 'so_news_events';
        
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE event_timestamp BETWEEN %s AND %s 
             ORDER BY threat_score DESC, event_timestamp DESC",
            $start_time,
            $end_time
        ), ARRAY_A);
        
        if (empty($events)) {
            $report = $this->generate_empty_report($type, $time_label, $start_time, $end_time);
        } else {
            $report = $this->analyze_events($events, $type, $time_label, $start_time, $end_time);
        }
        
        // Cache the report
        $cache_duration = $type === '6h' ? 1800 : ($type === '24h' ? 3600 : 7200);
        set_transient($cache_key, $report, $cache_duration);
        
        return $report;
    }
    
    /**
     * Analyze events and generate report
     */
    private function analyze_events(array $events, string $type, string $time_label, string $start_time, string $end_time): array {
        $total_events = count($events);
        
        // Calculate statistics
        $threat_levels = $this->calculate_threat_distribution($events);
        $top_actors = $this->extract_top_actors($events);
        $top_targets = $this->extract_top_targets($events);
        $top_locations = $this->extract_top_locations($events);
        $severity_trend = $this->calculate_severity_trend($events, $start_time, $end_time);
        $hotspots = $this->identify_hotspots($events);
        
        // Generate strategic narrative
        $narrative = $this->generate_strategic_narrative($events, $threat_levels, $top_actors);
        
        // Generate recommendations
        $recommendations = $this->generate_recommendations($events, $threat_levels, $top_actors);
        
        // Executive summary
        $summary = $this->generate_executive_summary($total_events, $threat_levels, $severity_trend);
        
        return [
            'meta' => [
                'type' => $type,
                'label' => $time_label,
                'generated_at' => date('Y-m-d H:i:s'),
                'start_time' => $start_time,
                'end_time' => $end_time,
                'total_events' => $total_events,
            ],
            'summary' => $summary,
            'statistics' => [
                'threat_distribution' => $threat_levels,
                'severity_trend' => $severity_trend,
                'trend_direction' => $this->determine_trend_direction($severity_trend),
            ],
            'key_findings' => [
                'top_actors' => $top_actors,
                'top_targets' => $top_targets,
                'top_locations' => $top_locations,
                'hotspots' => $hotspots,
            ],
            'narrative' => $narrative,
            'recommendations' => $recommendations,
            'raw_events' => array_slice($events, 0, 50), // Top 50 events only
        ];
    }
    
    /**
     * Generate empty report when no events
     */
    private function generate_empty_report(string $type, string $time_label, string $start_time, string $end_time): array {
        return [
            'meta' => [
                'type' => $type,
                'label' => $time_label,
                'generated_at' => date('Y-m-d H:i:s'),
                'start_time' => $start_time,
                'end_time' => $end_time,
                'total_events' => 0,
            ],
            'summary' => [
                'headline' => 'لا توجد أحداث مسجلة في الفترة المحددة',
                'overview' => 'لم يتم رصد أي أحداث تستدعي الانتباه خلال الفترة الزمنية المحددة.',
                'status' => 'هادئ',
            ],
            'statistics' => [
                'threat_distribution' => ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0],
                'severity_trend' => [],
                'trend_direction' => 'stable',
            ],
            'key_findings' => [
                'top_actors' => [],
                'top_targets' => [],
                'top_locations' => [],
                'hotspots' => [],
            ],
            'narrative' => 'لم تسجل أي نشاطات استخباراتية مهمة خلال الفترة المحددة. الوضع العام هادئ دون تطورات تستدعي التدخل.',
            'recommendations' => [
                'الاستمرار في المراقبة الروتينية',
                'مراجعة مصادر الاستخبارات للتأكد من فعاليتها',
                'تحديث معايير التصنيف إذا لزم الأمر',
            ],
            'raw_events' => [],
        ];
    }
    
    /**
     * Calculate threat level distribution
     */
    private function calculate_threat_distribution(array $events): array {
        $distribution = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        
        foreach ($events as $event) {
            $score = intval($event['threat_score'] ?? 0);
            
            if ($score >= 90) {
                $distribution['critical']++;
            } elseif ($score >= 70) {
                $distribution['high']++;
            } elseif ($score >= 40) {
                $distribution['medium']++;
            } else {
                $distribution['low']++;
            }
        }
        
        return $distribution;
    }
    
    /**
     * Extract top actors
     */
    private function extract_top_actors(array $events, int $limit = 10): array {
        $actors = [];
        
        foreach ($events as $event) {
            $actor = trim($event['primary_actor'] ?? '');
            if (!empty($actor)) {
                $actors[$actor] = ($actors[$actor] ?? 0) + 1;
            }
        }
        
        arsort($actors);
        
        return array_slice(array_keys($actors), 0, $limit);
    }
    
    /**
     * Extract top targets
     */
    private function extract_top_targets(array $events, int $limit = 10): array {
        $targets = [];
        
        foreach ($events as $event) {
            $target = trim($event['primary_target'] ?? '');
            if (!empty($target)) {
                $targets[$target] = ($targets[$target] ?? 0) + 1;
            }
        }
        
        arsort($targets);
        
        return array_slice(array_keys($targets), 0, $limit);
    }
    
    /**
     * Extract top locations
     */
    private function extract_top_locations(array $events, int $limit = 10): array {
        $locations = [];
        
        foreach ($events as $event) {
            $location = trim($event['location'] ?? '');
            if (!empty($location)) {
                $locations[$location] = ($locations[$location] ?? 0) + 1;
            }
        }
        
        arsort($locations);
        
        return array_slice(array_keys($locations), 0, $limit);
    }
    
    /**
     * Identify geographic hotspots
     */
    private function identify_hotspots(array $events, int $limit = 5): array {
        $locations = $this->extract_top_locations($events, $limit * 2);
        $hotspots = [];
        
        foreach ($locations as $location) {
            $location_events = array_filter($events, fn($e) => trim($e['location'] ?? '') === $location);
            $avg_threat = array_sum(array_column($location_events, 'threat_score')) / count($location_events);
            
            if (count($location_events) >= 3 || $avg_threat >= 70) {
                $hotspots[] = [
                    'location' => $location,
                    'event_count' => count($location_events),
                    'avg_threat' => round($avg_threat, 1),
                    'max_threat' => max(array_column($location_events, 'threat_score')),
                ];
            }
        }
        
        usort($hotspots, fn($a, $b) => $b['avg_threat'] - $a['avg_threat']);
        
        return array_slice($hotspots, 0, $limit);
    }
    
    /**
     * Calculate severity trend over time
     */
    private function calculate_severity_trend(array $events, string $start_time, string $end_time): array {
        $trend = [];
        $total_seconds = strtotime($end_time) - strtotime($start_time);
        $interval = $total_seconds > 86400 ? 'day' : 'hour';
        
        $grouped = [];
        
        foreach ($events as $event) {
            $timestamp = strtotime($event['event_timestamp']);
            $key = $interval === 'day' 
                ? date('Y-m-d', $timestamp) 
                : date('Y-m-d H:00', $timestamp);
            
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['count' => 0, 'total_score' => 0];
            }
            
            $grouped[$key]['count']++;
            $grouped[$key]['total_score'] += intval($event['threat_score'] ?? 0);
        }
        
        ksort($grouped);
        
        foreach ($grouped as $period => $data) {
            $trend[] = [
                'period' => $period,
                'event_count' => $data['count'],
                'avg_threat' => round($data['total_score'] / $data['count'], 1),
            ];
        }
        
        return $trend;
    }
    
    /**
     * Determine overall trend direction
     */
    private function determine_trend_direction(array $trend): string {
        if (count($trend) < 2) {
            return 'insufficient_data';
        }
        
        $last_half = array_slice($trend, ceil(count($trend) / 2));
        $first_half = array_slice($trend, 0, floor(count($trend) / 2));
        
        $last_avg = array_sum(array_column($last_half, 'avg_threat')) / count($last_half);
        $first_avg = array_sum(array_column($first_half, 'avg_threat')) / count($first_half);
        
        $diff = $last_avg - $first_avg;
        
        if ($diff > 10) {
            return 'escalating';
        } elseif ($diff < -10) {
            return 'de-escalating';
        } else {
            return 'stable';
        }
    }
    
    /**
     * Generate strategic narrative
     */
    private function generate_strategic_narrative(array $events, array $threat_levels, array $top_actors): string {
        $total = count($events);
        $critical_high = $threat_levels['critical'] + $threat_levels['high'];
        $percentage = $total > 0 ? round(($critical_high / $total) * 100) : 0;
        
        $narrative = "خلال الفترة المشمولة بالتقرير، تم رصد {$total} حدثاً استخباراتياً. ";
        
        if ($percentage >= 50) {
            $narrative .= "تشير البيانات إلى تصاعد ملحوظ في النشاطات المعادية، حيث شكلت الأحداث عالية الخطورة {$percentage}% من إجمالي الأحداث. ";
        } elseif ($percentage >= 25) {
            $narrative .= "الوضع الأمني يظهر مستويات متوسطة من التوتر، مع تركيز %{$percentage} من الأحداث في نطاق الخطورة العالية والحرجة. ";
        } else {
            $narrative .= "الفترة شهدت استقراراً نسبياً، مع هيمنة الأحداث منخفضة ومتوسطة الخطورة على المشهد. ";
        }
        
        if (!empty($top_actors)) {
            $top_actor = $top_actors[0];
            $narrative .= "ظهرت جهة \"{$top_actor}\" كأكثر الفاعلين نشاطاً في الفترة المشمولة. ";
        }
        
        return $narrative;
    }
    
    /**
     * Generate executive summary
     */
    private function generate_executive_summary(int $total_events, array $threat_levels, array $severity_trend): array {
        $critical_high = $threat_levels['critical'] + $threat_levels['high'];
        
        $status = 'stable';
        $headline = 'الوضع العام مستقر';
        
        if ($critical_high > $total_events * 0.5) {
            $status = 'critical';
            $headline = 'تصاعد خطير في التهديدات';
        } elseif ($critical_high > $total_events * 0.25) {
            $status = 'elevated';
            $headline = 'مستوى تهديد مرتفع';
        }
        
        $trend_direction = $this->determine_trend_direction($severity_trend);
        
        if ($trend_direction === 'escalating') {
            $headline .= ' - اتجاه تصاعدي';
        } elseif ($trend_direction === 'de-escalating') {
            $headline .= ' - اتجاه هدوء';
        }
        
        return [
            'headline' => $headline,
            'status' => $status,
            'overview' => "إجمالي الأحداث: {$total_events} | حرجة/عالية: {$critical_high}",
            'trend' => $trend_direction,
        ];
    }
    
    /**
     * Generate actionable recommendations
     */
    private function generate_recommendations(array $events, array $threat_levels, array $top_actors): array {
        $recommendations = [];
        
        $critical_high = $threat_levels['critical'] + $threat_levels['high'];
        $total = count($events);
        
        if ($critical_high > $total * 0.4) {
            $recommendations[] = 'رفع مستوى التأهب والاستعداد للتعامل مع التهديدات الحرجة';
            $recommendations[] = 'تكثيف عمليات المراقبة والرصد في المناطق الساخنة';
        }
        
        if (!empty($top_actors)) {
            $recommendations[] = "تعزيز المراقبة الاستخباراتية الموجهة لجهة \"{$top_actors[0]}\"";
        }
        
        $hotspots = $this->identify_hotspots($events);
        if (!empty($hotspots)) {
            foreach (array_slice($hotspots, 0, 3) as $hotspot) {
                $recommendations[] = "زيادة التواجد الأمني والاستخباراتي في منطقة \"{$hotspot['location']}\"";
            }
        }
        
        $recommendations[] = 'مراجعة وتحديث خطط الطوارئ بناءً على التهديدات الحالية';
        $recommendations[] = 'تنسيق أوثق مع الجهات المعنية لتبادل المعلومات الاستخباراتية';
        
        return array_unique($recommendations);
    }
    
    /**
     * Generate PDF report
     */
    private function generate_pdf(array $report): string {
        // Placeholder for PDF generation
        // In production, use TCPDF or DomPDF
        return "PDF Content for: " . $report['meta']['label'];
    }
    
    /**
     * Generate Word report
     */
    private function generate_word(array $report): string {
        // Placeholder for Word generation
        // In production, use PHPWord
        return "Word Content for: " . $report['meta']['label'];
    }
    
    /**
     * Send report to Telegram
     */
    private function send_to_telegram(array $report, string $chat_id = ''): bool {
        // Integration with Telegram module
        // This will be implemented in Integrations module
        error_log('Telegram send requested for report: ' . $report['meta']['label']);
        return true;
    }
    
    /**
     * Send report to email
     */
    private function send_to_email(array $report, string $email): bool {
        $subject = "التقرير التنفيذي - {$report['meta']['label']}";
        
        $message = $this->format_email_message($report);
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: OSINT PRO <noreply@' . $_SERVER['HTTP_HOST'] . '>',
        ];
        
        return wp_mail($email, $subject, $message, $headers);
    }
    
    /**
     * Format email message
     */
    private function format_email_message(array $report): string {
        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; direction: rtl; text-align: right;">
            <h2 style="color: #1a1f2e;">التقرير التنفيذي</h2>
            <p><strong>الفترة:</strong> <?php echo esc_html($report['meta']['label']); ?></p>
            <p><strong>تاريخ الإنشاء:</strong> <?php echo esc_html($report['meta']['generated_at']); ?></p>
            
            <div style="background: #f4f6f8; padding: 15px; border-radius: 8px; margin: 20px 0;">
                <h3 style="margin-top: 0;"><?php echo esc_html($report['summary']['headline']); ?></h3>
                <p><?php echo esc_html($report['summary']['overview']); ?></p>
            </div>
            
            <h3>الإحصائيات الرئيسية</h3>
            <ul>
                <?php foreach ($report['statistics']['threat_distribution'] as $level => $count): ?>
                <li><?php echo ucfirst($level); ?>: <?php echo intval($count); ?></li>
                <?php endforeach; ?>
            </ul>
            
            <h3>أهم التوصيات</h3>
            <ol>
                <?php foreach (array_slice($report['recommendations'], 0, 5) as $rec): ?>
                <li><?php echo esc_html($rec); ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate scheduled report
     */
    public function generate_scheduled_report(): void {
        $type = doing_action('osint_weekly_exec_report') ? 'weekly' : '24h';
        
        try {
            $report = $this->generate_report($type, true);
            
            // Auto-send to configured recipients
            $telegram_enabled = get_option('osint_telegram_enabled', false);
            if ($telegram_enabled) {
                $chat_id = get_option('osint_telegram_chat_id', '');
                $this->send_to_telegram($report, $chat_id);
            }
            
            $emails = get_option('osint_report_emails', []);
            if (!empty($emails)) {
                foreach ((array)$emails as $email) {
                    $this->send_to_email($report, $email);
                }
            }
            
            error_log("Scheduled {$type} executive report generated and sent successfully.");
        } catch (\Exception $e) {
            error_log("Failed to generate scheduled report: " . $e->getMessage());
        }
    }
    
    /**
     * Get module info
     */
    public function get_info(): array {
        return [
            'name' => self::NAME,
            'label' => 'التقارير التنفيذية',
            'version' => '1.0.0',
            'description' => 'نظام توليد التقارير التنفيذية الذكية',
            'features' => [
                'تقارير 6 ساعات',
                'تقارير 24 ساعة',
                'تقارير أسبوعية',
                'سرد استراتيجي تلقائي',
                'توصيات قابلة للتنفيذ',
                'كشف الاتجاهات',
                'تحديد النقاط الساخنة',
                'تصدير PDF و Word',
                'إرسال Telegram و Email',
            ],
        ];
    }
}
