<?php
/**
 * OSINT-LB PRO - Admin Module
 * 
 * Handles all admin UI and functionality
 * 
 * @package     OSINT_PRO\Admin
 * @author      Production Architect
 * @since       12.0.0
 */

namespace OSINT_PRO\Admin;

use OSINT_PRO\Core\Interfaces\Module;

class Admin_Module implements Module {
    
    /**
     * Whether module is active
     * 
     * @var bool
     */
    private bool $is_active = false;
    
    /**
     * {@inheritDoc}
     */
    public function boot(): void {
        // Register admin hooks
        $this->register_hooks();
        
        $this->is_active = true;
    }
    
    /**
     * {@inheritDoc}
     */
    public function register(): void {
        // Already registered in boot()
    }
    
    /**
     * {@inheritDoc}
     */
    public function activate(): void {
        // Admin-specific activation
    }
    
    /**
     * {@inheritDoc}
     */
    public function deactivate(): void {
        $this->is_active = false;
    }
    
    /**
     * {@inheritDoc}
     */
    public function is_active(): bool {
        return $this->is_active;
    }
    
    /**
     * Register admin hooks
     * 
     * @return void
     */
    private function register_hooks(): void {
        // Add custom admin body class
        add_filter('admin_body_class', [$this, 'admin_body_class']);
        
        // Add dashboard widgets
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widgets']);
        
        // Admin footer text
        add_filter('admin_footer_text', [$this, 'admin_footer_text'], 10, 1);
        
        // Update request for plugin row meta
        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);
    }
    
    /**
     * Add admin body class for OSINT pages
     * 
     * @param string $classes
     * @return string
     */
    public function admin_body_class(string $classes): string {
        global $pagenow;
        
        if ($pagenow === 'admin.php' && isset($_GET['page']) && strpos($_GET['page'], 'osint') !== false) {
            $classes .= ' osint-pro-page';
        }
        
        return $classes;
    }
    
    /**
     * Add custom dashboard widgets to WordPress dashboard
     * 
     * @return void
     */
    public function add_dashboard_widgets(): void {
        wp_add_dashboard_widget(
            'osint_pro_quick_stats',
            __('OSINT Quick Stats', 'osint-pro'),
            [$this, 'render_quick_stats_widget']
        );
        
        wp_add_dashboard_widget(
            'osint_pro_recent_alerts',
            __('Recent High-Threat Alerts', 'osint-pro'),
            [$this, 'render_recent_alerts_widget']
        );
    }
    
    /**
     * Render quick stats widget
     * 
     * @return void
     */
    public function render_quick_stats_widget(): void {
        global $wpdb;
        $events_table = osint_table('news_events');
        
        $today = current_time('Y-m-d');
        
        $stats = [
            'total_events' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $events_table"),
            'events_today' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $events_table WHERE DATE(event_timestamp) = %s",
                $today
            )),
            'high_threat' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $events_table WHERE threat_score >= 7"
            ),
            'pending_review' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $events_table WHERE status = 'new'"
            ),
        ];
        
        ?>
        <div class="osint-quick-stats-widget">
            <style>
                .osint-quick-stats-widget {
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 12px;
                }
                .osint-stat-card {
                    background: #f6f7f7;
                    border-left: 4px solid #2271b1;
                    padding: 12px;
                    border-radius: 4px;
                }
                .osint-stat-card.danger {
                    border-left-color: #d63638;
                }
                .osint-stat-card.warning {
                    border-left-color: #dba617;
                }
                .osint-stat-value {
                    font-size: 24px;
                    font-weight: bold;
                    color: #1d2327;
                }
                .osint-stat-label {
                    font-size: 12px;
                    color: #646970;
                    margin-top: 4px;
                }
            </style>
            
            <div class="osint-stat-card">
                <div class="osint-stat-value"><?php echo $stats['events_today']; ?></div>
                <div class="osint-stat-label"><?php _e('Events Today', 'osint-pro'); ?></div>
            </div>
            
            <div class="osint-stat-card danger">
                <div class="osint-stat-value"><?php echo $stats['high_threat']; ?></div>
                <div class="osint-stat-label"><?php _e('High Threat', 'osint-pro'); ?></div>
            </div>
            
            <div class="osint-stat-card warning">
                <div class="osint-stat-value"><?php echo $stats['pending_review']; ?></div>
                <div class="osint-stat-label"><?php _e('Pending Review', 'osint-pro'); ?></div>
            </div>
            
            <div class="osint-stat-card">
                <div class="osint-stat-value"><?php echo $stats['total_events']; ?></div>
                <div class="osint-stat-label"><?php _e('Total Events', 'osint-pro'); ?></div>
            </div>
            
            <div style="grid-column: span 2; margin-top: 12px;">
                <a href="<?php echo admin_url('admin.php?page=osint-pro-dashboard'); ?>" class="button button-primary">
                    <?php _e('Open Dashboard', 'osint-pro'); ?> →
                </a>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render recent alerts widget
     * 
     * @return void
     */
    public function render_recent_alerts_widget(): void {
        global $wpdb;
        $events_table = osint_table('news_events');
        
        $alerts = $wpdb->get_results($wpdb->prepare(
            "SELECT id, event_title, threat_score, event_timestamp, primary_actor
             FROM $events_table
             WHERE threat_score >= 7
             ORDER BY event_timestamp DESC
             LIMIT 5",
            ARRAY_A
        ));
        
        if (empty($alerts)) {
            echo '<p>' . __('No high-threat alerts at this time.', 'osint-pro') . '</p>';
            return;
        }
        
        ?>
        <style>
            .osint-alerts-list {
                list-style: none;
                margin: 0;
                padding: 0;
            }
            .osint-alert-item {
                padding: 8px 0;
                border-bottom: 1px solid #f0f0f1;
            }
            .osint-alert-item:last-child {
                border-bottom: none;
            }
            .osint-alert-title {
                font-weight: 600;
                color: #1d2327;
                display: block;
                margin-bottom: 4px;
            }
            .osint-alert-meta {
                font-size: 11px;
                color: #646970;
            }
            .osint-threat-badge {
                display: inline-block;
                background: #d63638;
                color: white;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 10px;
                font-weight: bold;
                margin-right: 6px;
            }
        </style>
        
        <ul class="osint-alerts-list">
            <?php foreach ($alerts as $alert): ?>
            <li class="osint-alert-item">
                <a href="<?php echo admin_url('admin.php?page=osint-pro-dashboard&view=event&id=' . $alert['id']); ?>" class="osint-alert-title">
                    <span class="osint-threat-badge"><?php echo esc_html($alert['threat_score']); ?></span>
                    <?php echo esc_html(wp_trim_words($alert['event_title'], 8)); ?>
                </a>
                <div class="osint-alert-meta">
                    <?php if ($alert['primary_actor']): ?>
                        <?php echo esc_html($alert['primary_actor']); ?> • 
                    <?php endif; ?>
                    <?php echo human_time_diff(strtotime($alert['event_timestamp'])); ?> <?php _e('ago', 'osint-pro'); ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        
        <p style="margin-top: 12px;">
            <a href="<?php echo admin_url('admin.php?page=osint-pro-dashboard&filter=high_threat'); ?>">
                <?php _e('View all alerts', 'osint-pro'); ?> →
            </a>
        </p>
        <?php
    }
    
    /**
     * Modify admin footer text on OSINT pages
     * 
     * @param string $footer_text
     * @return string
     */
    public function admin_footer_text(string $footer_text): string {
        global $pagenow;
        
        if ($pagenow === 'admin.php' && isset($_GET['page']) && strpos($_GET['page'], 'osint') !== false) {
            return sprintf(
                __('Thank you for using <strong>OSINT-LB PRO</strong>. | <a href="%s" target="_blank">Documentation</a> | <a href="%s">Support</a>', 'osint-pro'),
                'https://osint-lb.pro/docs',
                admin_url('admin.php?page=osint-pro-settings#support')
            );
        }
        
        return $footer_text;
    }
    
    /**
     * Add plugin row meta links
     * 
     * @param array $links
     * @param string $file
     * @return array
     */
    public function plugin_row_meta(array $links, string $file): array {
        if ($file === OSINT_PRO_PLUGIN_BASENAME) {
            $meta_links = [
                'docs' => '<a href="https://osint-lb.pro/docs" target="_blank">' . __('Documentation', 'osint-pro') . '</a>',
                'support' => '<a href="' . admin_url('admin.php?page=osint-pro-settings#support') . '">' . __('Support', 'osint-pro') . '</a>',
            ];
            
            $links = array_merge($links, $meta_links);
        }
        
        return $links;
    }
}
