<?php
namespace OSINT_PRO\Dashboard;

use OSINT_PRO\Core\Interfaces\Module;

class Dashboard_Module implements Module {
    private bool $is_active = false;
    
    public function boot(): void {
        $this->register_hooks();
        $this->is_active = true;
    }
    
    public function register(): void {}
    public function activate(): void {}
    public function deactivate(): void { $this->is_active = false; }
    public function is_active(): bool { return $this->is_active; }
    
    private function register_hooks(): void {
        add_shortcode('osint_kpi_cards', [$this, 'render_kpi_cards']);
        add_shortcode('osint_events_table', [$this, 'render_events_table']);
        add_action('wp_ajax_osint_get_dashboard_data', [$this, 'ajax_get_data']);
        add_action('wp_ajax_osint_get_kpi_stats', [$this, 'ajax_get_kpi']);
    }
    
    public function render_kpi_cards($atts): string {
        if (!current_user_can('manage_options')) return '';
        $atts = shortcode_atts(['period' => 'today'], $atts);
        $stats = $this->get_kpi_stats($atts['period']);
        
        ob_start(); ?>
        <div class="osint-kpi-cards">
            <?php foreach ($stats as $k => $v): ?>
            <div class="osint-kpi-card">
                <div class="osint-kpi-value"><?php echo number_format_i18n($v['value']); ?></div>
                <div class="osint-kpi-label"><?php echo esc_html($v['label']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php return ob_get_clean();
    }
    
    public function render_events_table($atts): string {
        if (!current_user_can('manage_options')) return '';
        $atts = shortcode_atts(['limit' => 25, 'filter' => 'all'], $atts);
        $events = $this->get_recent_events((int)$atts['limit'], $atts['filter']);
        
        ob_start(); ?>
        <table class="osint-events-table wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?php _e('Time', 'osint-pro'); ?></th>
                <th><?php _e('Event', 'osint-pro'); ?></th>
                <th><?php _e('Actor', 'osint-pro'); ?></th>
                <th><?php _e('Location', 'osint-pro'); ?></th>
                <th><?php _e('Threat', 'osint-pro'); ?></th>
                <th><?php _e('Status', 'osint-pro'); ?></th>
            </tr></thead>
            <tbody>
            <?php if (empty($events)): ?>
                <tr><td colspan="6" style="text-align:center;"><?php _e('No events found.', 'osint-pro'); ?></td></tr>
            <?php else: foreach ($events as $e): ?>
                <tr>
                    <td><?php echo human_time_diff(strtotime($e->event_timestamp)); ?> <?php _e('ago', 'osint-pro'); ?></td>
                    <td><strong><?php echo esc_html(wp_trim_words($e->event_title, 10)); ?></strong></td>
                    <td><?php echo esc_html($e->primary_actor ?: '-'); ?></td>
                    <td><?php echo esc_html($e->location_country ?: '-'); ?></td>
                    <td><span class="osint-threat-badge threat-<?php echo $this->threat_class($e->threat_score); ?>"><?php echo (int)$e->threat_score; ?></span></td>
                    <td><span class="osint-status-badge status-<?php echo esc_attr($e->status); ?>"><?php echo ucfirst($e->status); ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php return ob_get_clean();
    }
    
    public function get_kpi_stats(string $period = 'today'): array {
        global $wpdb;
        $tbl = osint_table('news_events');
        $range = $this->date_range($period);
        
        return [
            'total_events' => ['value' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tbl WHERE event_timestamp >= %s AND event_timestamp <= %s", $range[0], $range[1])), 'label' => __('Total Events', 'osint-pro')],
            'high_threat' => ['value' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tbl WHERE threat_score >= 7 AND event_timestamp >= %s AND event_timestamp <= %s", $range[0], $range[1])), 'label' => __('High Threat', 'osint-pro')],
            'unique_actors' => ['value' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT primary_actor) FROM $tbl WHERE primary_actor != '' AND event_timestamp >= %s", $range[0])), 'label' => __('Active Actors', 'osint-pro')],
            'countries' => ['value' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT location_country) FROM $tbl WHERE location_country != '' AND event_timestamp >= %s", $range[0])), 'label' => __('Countries', 'osint-pro')],
        ];
    }
    
    public function get_recent_events(int $limit = 25, string $filter = 'all'): array {
        global $wpdb;
        $tbl = osint_table('news_events');
        $where = '1=1';
        if ($filter === 'high_threat') $where .= ' AND threat_score >= 7';
        elseif ($filter === 'pending') $where .= " AND status = 'new'";
        
        return $wpdb->get_results($wpdb->prepare("SELECT id, event_title, event_timestamp, primary_actor, location_country, threat_score, status FROM $tbl WHERE $where ORDER BY event_timestamp DESC LIMIT %d", $limit));
    }
    
    private function date_range(string $period): array {
        $now = current_time('mysql');
        switch ($period) {
            case 'today': return [date('Y-m-d 00:00:00', strtotime($now)), $now];
            case 'week': return [date('Y-m-d H:i:s', strtotime('-7 days')), $now];
            case 'month': return [date('Y-m-d H:i:s', strtotime('-30 days')), $now];
            default: return [date('Y-m-d 00:00:00'), $now];
        }
    }
    
    private function threat_class(int $score): string {
        if ($score >= 8) return 'critical';
        if ($score >= 6) return 'high';
        if ($score >= 4) return 'medium';
        if ($score >= 2) return 'low';
        return 'minimal';
    }
    
    public function ajax_get_data(): void {
        check_ajax_referer('osint_pro_ajax', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized']);
        wp_send_json_success(['kpi_stats' => $this->get_kpi_stats('today'), 'recent_events' => $this->get_recent_events(10)]);
    }
    
    public function ajax_get_kpi(): void {
        check_ajax_referer('osint_pro_ajax', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized']);
        $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : 'today';
        wp_send_json_success($this->get_kpi_stats($period));
    }
}
