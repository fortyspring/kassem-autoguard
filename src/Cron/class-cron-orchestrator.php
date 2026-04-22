<?php
/**
 * Cron Orchestrator
 * 
 * Centralized cron job management:
 * - Fetching news/events
 * - Cleanup tasks
 * - Report generation
 * - Cache refresh
 * - Alert dispatch
 * 
 * @package OSINT_Pro\Cron
 */

namespace OSINT_Pro\Cron;

use SO\Traits\Singleton;

/**
 * Cron Orchestrator Class
 */
class Cron_Orchestrator {
    
    use Singleton;
    
    /**
     * Cron event names
     */
    const CRON_FETCH = 'osint_pro_cron_fetch';
    const CRON_CLEANUP = 'osint_pro_cron_cleanup';
    const CRON_REPORTS = 'osint_pro_cron_reports';
    const CRON_ALERTS = 'osint_pro_cron_alerts';
    const CRON_CACHE_REFRESH = 'osint_pro_cron_cache_refresh';
    
    /**
     * Initialize cron hooks
     */
    public function init(): void {
        // Register custom schedules
        add_filter('cron_schedules', [$this, 'register_schedules']);
        
        // Hook into cron events
        add_action(self::CRON_FETCH, [$this, 'run_fetch']);
        add_action(self::CRON_CLEANUP, [$this, 'run_cleanup']);
        add_action(self::CRON_REPORTS, [$this, 'run_reports']);
        add_action(self::CRON_ALERTS, [$this, 'run_alerts']);
        add_action(self::CRON_CACHE_REFRESH, [$this, 'run_cache_refresh']);
        
        // Schedule events on init if not already scheduled
        add_action('init', [$this, 'schedule_events']);
    }
    
    /**
     * Register custom cron schedules
     * 
     * @param array $schedules Existing schedules
     * @return array
     */
    public function register_schedules(array $schedules): array {
        // Every 5 minutes
        $schedules['every_5_minutes'] = [
            'interval' => 300,
            'display' => __('Every 5 Minutes', 'beiruttime-osint-pro'),
        ];
        
        // Every 15 minutes
        $schedules['every_15_minutes'] = [
            'interval' => 900,
            'display' => __('Every 15 Minutes', 'beiruttime-osint-pro'),
        ];
        
        // Every 30 minutes
        $schedules['every_30_minutes'] = [
            'interval' => 1800,
            'display' => __('Every 30 Minutes', 'beiruttime-osint-pro'),
        ];
        
        // Twice daily (every 12 hours)
        $schedules['twicedaily'] = [
            'interval' => 43200,
            'display' => __('Twice Daily', 'beiruttime-osint-pro'),
        ];
        
        return $schedules;
    }
    
    /**
     * Schedule cron events
     */
    public function schedule_events(): void {
        // Fetch events - every 15 minutes
        if (!wp_next_scheduled(self::CRON_FETCH)) {
            wp_schedule_event(time(), 'every_15_minutes', self::CRON_FETCH);
        }
        
        // Cleanup - daily
        if (!wp_next_scheduled(self::CRON_CLEANUP)) {
            wp_schedule_event(time(), 'daily', self::CRON_CLEANUP);
        }
        
        // Reports - twice daily
        if (!wp_next_scheduled(self::CRON_REPORTS)) {
            wp_schedule_event(time(), 'twicedaily', self::CRON_REPORTS);
        }
        
        // Alerts - every 5 minutes
        if (!wp_next_scheduled(self::CRON_ALERTS)) {
            wp_schedule_event(time(), 'every_5_minutes', self::CRON_ALERTS);
        }
        
        // Cache refresh - every 30 minutes
        if (!wp_next_scheduled(self::CRON_CACHE_REFRESH)) {
            wp_schedule_event(time(), 'every_30_minutes', self::CRON_CACHE_REFRESH);
        }
    }
    
    /**
     * Run fetch job
     */
    public function run_fetch(): void {
        $this->log('Starting fetch job');
        
        try {
            // Delegate to fetch service
            do_action('osint_pro_fetch_news');
            
            $this->log('Fetch job completed successfully');
        } catch (\Exception $e) {
            $this->log('Fetch job failed: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Run cleanup job
     */
    public function run_cleanup(): void {
        $this->log('Starting cleanup job');
        
        try {
            // Clean old events
            $this->cleanup_old_events();
            
            // Clean duplicates
            $this->cleanup_duplicates();
            
            // Clean transients
            $this->cleanup_transients();
            
            // Clean orphaned metadata
            $this->cleanup_orphaned_meta();
            
            $this->log('Cleanup job completed successfully');
        } catch (\Exception $e) {
            $this->log('Cleanup job failed: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Run reports job
     */
    public function run_reports(): void {
        $this->log('Starting reports job');
        
        try {
            // Generate scheduled reports
            do_action('osint_pro_generate_scheduled_reports');
            
            $this->log('Reports job completed successfully');
        } catch (\Exception $e) {
            $this->log('Reports job failed: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Run alerts job
     */
    public function run_alerts(): void {
        $this->log('Starting alerts job');
        
        try {
            // Process alert queue
            do_action('osint_pro_process_alert_queue');
            
            $this->log('Alerts job completed successfully');
        } catch (\Exception $e) {
            $this->log('Alerts job failed: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Run cache refresh job
     */
    public function run_cache_refresh(): void {
        $this->log('Starting cache refresh job');
        
        try {
            // Refresh cached data
            delete_transient('osint_pro_dashboard_data');
            delete_transient('osint_pro_world_monitor_data');
            delete_transient('osint_pro_threat_analysis');
            
            $this->log('Cache refresh job completed successfully');
        } catch (\Exception $e) {
            $this->log('Cache refresh job failed: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Cleanup old events
     */
    protected function cleanup_old_events(): void {
        global $wpdb;
        
        $table = $wpdb->prefix . 'so_news_events';
        $retention_days = apply_filters('osint_pro_event_retention_days', 90);
        $cutoff = strtotime("-{$retention_days} days");
        
        // Only cleanup non-archived events older than retention period
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %d AND status != 'archived'",
            $cutoff
        ));
        
        if ($deleted > 0) {
            $this->log("Cleaned up {$deleted} old events");
        }
    }
    
    /**
     * Cleanup duplicate events
     */
    protected function cleanup_duplicates(): void {
        // Delegate to deduplication service
        if (class_exists('\SO\Services\DuplicateCleaner')) {
            try {
                \SO\Services\DuplicateCleaner::instance()->cleanup();
            } catch (\Exception $e) {
                $this->log('Duplicate cleanup failed: ' . $e->getMessage(), 'error');
            }
        }
    }
    
    /**
     * Cleanup expired transients
     */
    protected function cleanup_transients(): void {
        global $wpdb;
        
        // Delete expired transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_osint_pro_%' 
             AND option_value < UNIX_TIMESTAMP()"
        );
        
        // Delete expired transient timeouts
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_timeout_osint_pro_%' 
             AND option_value < UNIX_TIMESTAMP()"
        );
        
        $this->log('Transients cleaned up');
    }
    
    /**
     * Cleanup orphaned post meta
     */
    protected function cleanup_orphaned_meta(): void {
        global $wpdb;
        
        // Clean orphaned event meta
        $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.ID IS NULL 
             AND pm.meta_key LIKE 'osint_%'"
        );
        
        $this->log('Orphaned meta cleaned up');
    }
    
    /**
     * Unschedule all cron events
     */
    public function unschedule_all(): void {
        wp_clear_scheduled_hook(self::CRON_FETCH);
        wp_clear_scheduled_hook(self::CRON_CLEANUP);
        wp_clear_scheduled_hook(self::CRON_REPORTS);
        wp_clear_scheduled_hook(self::CRON_ALERTS);
        wp_clear_scheduled_hook(self::CRON_CACHE_REFRESH);
        
        $this->log('All cron events unscheduled');
    }
    
    /**
     * Get cron status
     * 
     * @return array
     */
    public function get_status(): array {
        return [
            'fetch' => [
                'scheduled' => wp_next_scheduled(self::CRON_FETCH),
                'interval' => 'every_15_minutes',
            ],
            'cleanup' => [
                'scheduled' => wp_next_scheduled(self::CRON_CLEANUP),
                'interval' => 'daily',
            ],
            'reports' => [
                'scheduled' => wp_next_scheduled(self::CRON_REPORTS),
                'interval' => 'twicedaily',
            ],
            'alerts' => [
                'scheduled' => wp_next_scheduled(self::CRON_ALERTS),
                'interval' => 'every_5_minutes',
            ],
            'cache_refresh' => [
                'scheduled' => wp_next_scheduled(self::CRON_CACHE_REFRESH),
                'interval' => 'every_30_minutes',
            ],
        ];
    }
    
    /**
     * Log cron activity
     * 
     * @param string $message Log message
     * @param string $level Log level
     */
    protected function log(string $message, string $level = 'info'): void {
        error_log(sprintf(
            '[OSINT Cron] [%s] %s',
            strtoupper($level),
            $message
        ));
    }
}
