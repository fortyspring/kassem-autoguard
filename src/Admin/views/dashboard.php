<?php
/**
 * Dashboard View
 * 
 * Main operational dashboard with KPIs and quick stats.
 * 
 * @package OSINT_LB_PRO
 * @subpackage Admin/Views
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap osint-dashboard">
    <h1 class="wp-heading-inline"><?php esc_html_e('لوحة القيادة التشغيلية', 'osint-lb-pro'); ?></h1>
    
    <div class="osint-kpi-grid">
        <div class="osint-kpi-card">
            <div class="osint-kpi-value" id="kpi-total-events">-</div>
            <div class="osint-kpi-label"><?php esc_html_e('إجمالي الأحداث', 'osint-lb-pro'); ?></div>
        </div>
        
        <div class="osint-kpi-card critical">
            <div class="osint-kpi-value" id="kpi-critical-events">-</div>
            <div class="osint-kpi-label"><?php esc_html_e('أحداث حرجة', 'osint-lb-pro'); ?></div>
        </div>
        
        <div class="osint-kpi-card warning">
            <div class="osint-kpi-value" id="kpi-active-threats">-</div>
            <div class="osint-kpi-label"><?php esc_html_e('تهديدات نشطة', 'osint-lb-pro'); ?></div>
        </div>
        
        <div class="osint-kpi-card info">
            <div class="osint-kpi-value" id="kpi-sources-active">-</div>
            <div class="osint-kpi-label"><?php esc_html_e('مصادر نشطة', 'osint-lb-pro'); ?></div>
        </div>
    </div>
    
    <div class="osint-dashboard-row">
        <div class="osint-dashboard-panel">
            <h2><?php esc_html_e('آخر الأحداث', 'osint-lb-pro'); ?></h2>
            <div id="recent-events-list"></div>
        </div>
        
        <div class="osint-dashboard-panel">
            <h2><?php esc_html_e('المناطق الساخنة', 'osint-lb-pro'); ?></div>
            <div id="hot-zones-map"></div>
        </div>
    </div>
    
    <div class="osint-dashboard-row">
        <div class="osint-dashboard-panel full-width">
            <h2><?php esc_html_e('الاتجاهات الزمنية', 'osint-lb-pro'); ?></h2>
            <div id="timeline-chart"></div>
        </div>
    </div>
</div>
