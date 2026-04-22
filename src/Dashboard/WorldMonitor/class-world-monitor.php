<?php
/**
 * World Monitor - Unified Dashboard Renderer
 * 
 * Single source of truth for the World Monitor dashboard.
 * Replaces all duplicate renderers (live, v2, hotfix).
 * 
 * @package OSINT_Pro\Dashboard\WorldMonitor
 */

namespace OSINT_Pro\Dashboard\WorldMonitor;

use SO\Traits\Singleton;

/**
 * World Monitor Class
 */
class World_Monitor {
    
    use Singleton;
    
    /**
     * Shortcode name
     */
    const SHORTCODE = 'sod_world_monitor';
    
    /**
     * AJAX action name
     */
    const AJAX_ACTION = 'osint_pro_world_monitor_snapshot';
    
    /**
     * Initialize hooks
     */
    public function init(): void {
        // Register shortcode
        add_shortcode(self::SHORTCODE, [$this, 'render']);
        
        // Register AJAX handlers (auth only)
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_snapshot']);
        
        // Enqueue assets when shortcode is used
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets']);
    }
    
    /**
     * Render the dashboard
     * 
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function render(array $atts = []): string {
        $atts = shortcode_atts([
            'days' => 7,
            'refresh' => 60,
            'height' => 'auto',
        ], $atts);
        
        $uid = 'wm_' . wp_generate_uuid4();
        
        ob_start();
        ?>
        <div id="<?php echo esc_attr($uid); ?>" class="osint-world-monitor" data-uid="<?php echo esc_attr($uid); ?>">
            <?php $this->render_header($atts); ?>
            <?php $this->render_kpi_cards(); ?>
            <?php $this->render_command_brief(); ?>
            <?php $this->render_main_content($uid); ?>
            <?php $this->render_footer($atts); ?>
        </div>
        <?php
        $this->render_scripts($uid, $atts);
        
        return ob_get_clean();
    }
    
    /**
     * Render header section
     */
    protected function render_header(array $atts): void {
        ?>
        <header class="wm-header">
            <div class="wm-header-title">
                <h2><?php _e('World Monitor', 'beiruttime-osint-pro'); ?></h2>
                <span class="wm-badge"><?php _e('Live Intelligence Dashboard', 'beiruttime-osint-pro'); ?></span>
            </div>
            <div class="wm-controls">
                <div class="wm-range-selector">
                    <button class="wm-range-btn" data-days="1"><?php _e('24H', 'beiruttime-osint-pro'); ?></button>
                    <button class="wm-range-btn" data-days="3"><?php _e('3 Days', 'beiruttime-osint-pro'); ?></button>
                    <button class="wm-range-btn is-active" data-days="7"><?php _e('7 Days', 'beiruttime-osint-pro'); ?></button>
                    <button class="wm-range-btn" data-days="14"><?php _e('14 Days', 'beiruttime-osint-pro'); ?></button>
                    <button class="wm-range-btn" data-days="30"><?php _e('30 Days', 'beiruttime-osint-pro'); ?></button>
                </div>
                <button class="wm-refresh-btn" title="<?php _e('Refresh', 'beiruttime-osint-pro'); ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M23 4v6h-6M1 20v-6h6"/>
                        <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
                    </svg>
                </button>
            </div>
        </header>
        <?php
    }
    
    /**
     * Render KPI cards
     */
    protected function render_kpi_cards(): void {
        ?>
        <div class="wm-kpi-grid">
            <div class="wm-kpi-card wm-kpi-risk">
                <div class="wm-kpi-value" data-field="risk_index">--</div>
                <div class="wm-kpi-label"><?php _e('Risk Index', 'beiruttime-osint-pro'); ?></div>
                <div class="wm-kpi-trend" data-field="risk_trend"></div>
            </div>
            <div class="wm-kpi-card wm-kpi-events">
                <div class="wm-kpi-value" data-field="total_events">--</div>
                <div class="wm-kpi-label"><?php _e('Total Events', 'beiruttime-osint-pro'); ?></div>
                <div class="wm-kpi-sub" data-field="events_24h"></div>
            </div>
            <div class="wm-kpi-card wm-kpi-critical">
                <div class="wm-kpi-value" data-field="critical_count">--</div>
                <div class="wm-kpi-label"><?php _e('Critical', 'beiruttime-osint-pro'); ?></div>
            </div>
            <div class="wm-kpi-card wm-kpi-escalation">
                <div class="wm-kpi-value" data-field="escalation_index">--%</div>
                <div class="wm-kpi-label"><?php _e('Escalation', 'beiruttime-osint-pro'); ?></div>
            </div>
            <div class="wm-kpi-card wm-kpi-actors">
                <div class="wm-kpi-value" data-field="actor_count">--</div>
                <div class="wm-kpi-label"><?php _e('Active Actors', 'beiruttime-osint-pro'); ?></div>
            </div>
            <div class="wm-kpi-card wm-kpi-regions">
                <div class="wm-kpi-value" data-field="region_count">--</div>
                <div class="wm-kpi-label"><?php _e('Hot Regions', 'beiruttime-osint-pro'); ?></div>
            </div>
            <div class="wm-kpi-card wm-kpi-hybrid">
                <div class="wm-kpi-value" data-field="hybrid_intensity">--%</div>
                <div class="wm-kpi-label"><?php _e('Hybrid Intensity', 'beiruttime-osint-pro'); ?></div>
            </div>
            <div class="wm-kpi-card wm-kpi-updated">
                <div class="wm-kpi-value" data-field="updated_at">--</div>
                <div class="wm-kpi-label"><?php _e('Last Update', 'beiruttime-osint-pro'); ?></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render command brief section
     */
    protected function render_command_brief(): void {
        ?>
        <section class="wm-command-brief">
            <div class="wm-section-header">
                <h3><?php _e('Command Brief', 'beiruttime-osint-pro'); ?></h3>
            </div>
            <div class="wm-command-content">
                <div class="wm-command-summary" data-field="command_summary">
                    <?php _e('Loading intelligence summary...', 'beiruttime-osint-pro'); ?>
                </div>
                <div class="wm-command-chips">
                    <span class="wm-chip" data-field="top_actor"></span>
                    <span class="wm-chip" data-field="top_region"></span>
                    <span class="wm-chip" data-field="momentum"></span>
                </div>
            </div>
        </section>
        <?php
    }
    
    /**
     * Render main content area
     */
    protected function render_main_content(string $uid): void {
        ?>
        <div class="wm-main-content">
            <!-- Map Panel -->
            <div class="wm-panel wm-map-panel">
                <div class="wm-panel-header">
                    <h4><?php _e('Global Heatmap', 'beiruttime-osint-pro'); ?></h4>
                </div>
                <div id="<?php echo esc_attr($uid); ?>_map" class="wm-map-container"></div>
            </div>
            
            <!-- Charts Panel -->
            <div class="wm-panel wm-charts-panel">
                <div class="wm-chart-row">
                    <div class="wm-chart-wrapper">
                        <canvas id="<?php echo esc_attr($uid); ?>_types_chart"></canvas>
                    </div>
                    <div class="wm-chart-wrapper">
                        <canvas id="<?php echo esc_attr($uid); ?>_trend_chart"></canvas>
                    </div>
                </div>
                <div class="wm-chart-row">
                    <div class="wm-chart-wrapper">
                        <canvas id="<?php echo esc_attr($uid); ?>_regions_chart"></canvas>
                    </div>
                    <div class="wm-chart-wrapper">
                        <canvas id="<?php echo esc_attr($uid); ?>_severity_chart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Intelligence Feed -->
            <div class="wm-panel wm-feed-panel">
                <div class="wm-panel-header">
                    <h4><?php _e('Intelligence Feed', 'beiruttime-osint-pro'); ?></h4>
                    <span class="wm-feed-count" data-field="feed_count">0</span>
                </div>
                <div class="wm-feed-list" data-field="feed_list">
                    <div class="wm-feed-loading"><?php _e('Loading...', 'beiruttime-osint-pro'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render footer with live ticker
     */
    protected function render_footer(array $atts): void {
        ?>
        <footer class="wm-footer">
            <div class="wm-ticker">
                <div class="wm-ticker-track" data-field="ticker_items">
                    <span class="wm-ticker-item"><?php _e('Initializing live feed...', 'beiruttime-osint-pro'); ?></span>
                </div>
            </div>
            <div class="wm-meta">
                <span data-field="ticker_meta"></span>
            </div>
        </footer>
        <?php
    }
    
    /**
     * Render JavaScript
     */
    protected function render_scripts(string $uid, array $atts): void {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('osint_pro_nonce');
        $refresh_ms = (int) $atts['refresh'] * 1000;
        
        ?>
        <script>
        (function() {
            'use strict';
            
            const CONFIG = {
                uid: '<?php echo esc_js($uid); ?>',
                ajaxUrl: '<?php echo esc_js($ajax_url); ?>',
                nonce: '<?php echo esc_js($nonce); ?>',
                action: '<?php echo esc_js(self::AJAX_ACTION); ?>',
                refreshMs: <?php echo (int) $refresh_ms; ?>,
                defaultDays: <?php echo (int) $atts['days']; ?>
            };
            
            const state = {
                data: null,
                days: CONFIG.defaultDays,
                map: null,
                charts: {},
                intervalId: null
            };
            
            function esc(str) {
                if (!str) return '';
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }
            
            function formatTime(ts) {
                if (!ts) return '--';
                const date = new Date(ts * 1000);
                return date.toLocaleTimeString('ar-LB', {hour: '2-digit', minute: '2-digit'});
            }
            
            function fetchData() {
                const body = new URLSearchParams({
                    action: CONFIG.action,
                    nonce: CONFIG.nonce,
                    days: String(state.days)
                });
                
                fetch(CONFIG.ajaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: body.toString()
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success && res.data) {
                        state.data = res.data;
                        renderAll();
                    }
                })
                .catch(err => console.error('WM fetch error:', err));
            }
            
            function renderAll() {
                if (!state.data) return;
                const d = state.data;
                
                // Update KPIs
                document.querySelectorAll('[data-field]').forEach(el => {
                    const field = el.dataset.field;
                    const value = getField(d, field);
                    if (value !== undefined) {
                        el.textContent = typeof value === 'string' ? esc(value) : value;
                    }
                });
                
                // Render map
                renderMap();
                
                // Render charts
                renderCharts();
                
                // Render feed
                renderFeed();
                
                // Render ticker
                renderTicker();
            }
            
            function getField(obj, path) {
                return path.split('.').reduce((o, k) => o && o[k], obj);
            }
            
            function renderMap() {
                // Map rendering logic (Leaflet.js integration)
                if (typeof L === 'undefined') return;
                
                if (!state.map) {
                    state.map = L.map(CONFIG.uid + '_map').setView([33.8, 35.5], 6);
                    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                        maxZoom: 19
                    }).addTo(state.map);
                }
                
                // Add markers from data
                // Implementation continues...
            }
            
            function renderCharts() {
                // Chart.js integration
                // Implementation continues...
            }
            
            function renderFeed() {
                // Feed list rendering
                // Implementation continues...
            }
            
            function renderTicker() {
                // Live ticker rendering
                // Implementation continues...
            }
            
            // Initialize
            document.addEventListener('DOMContentLoaded', function() {
                fetchData();
                state.intervalId = setInterval(fetchData, CONFIG.refreshMs);
                
                // Range selector
                document.querySelectorAll('.wm-range-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        document.querySelectorAll('.wm-range-btn').forEach(b => b.classList.remove('is-active'));
                        this.classList.add('is-active');
                        state.days = parseInt(this.dataset.days);
                        fetchData();
                    });
                });
                
                // Refresh button
                document.querySelector('.wm-refresh-btn').addEventListener('click', fetchData);
            });
            
            // Cleanup on page unload
            window.addEventListener('beforeunload', function() {
                if (state.intervalId) clearInterval(state.intervalId);
            });
        })();
        </script>
        <?php
    }
    
    /**
     * AJAX snapshot handler
     */
    public function ajax_snapshot(): void {
        // Verify nonce
        if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'osint_pro_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            return;
        }
        
        // Check capability
        if (!current_user_can('read')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }
        
        $days = isset($_REQUEST['days']) ? (int) $_REQUEST['days'] : 7;
        $days = max(1, min(90, $days));
        
        try {
            $data = $this->fetch_dashboard_data($days);
            wp_send_json_success($data);
        } catch (\Exception $e) {
            error_log('WM snapshot error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Failed to fetch data'], 500);
        }
    }
    
    /**
     * Fetch dashboard data
     * 
     * @param int $days Number of days
     * @return array
     */
    public function fetch_dashboard_data(int $days): array {
        global $wpdb;
        
        $table = $wpdb->prefix . 'so_news_events';
        $cutoff = strtotime("-{$days} days");
        
        // Get KPI data
        $kpi = $this->fetch_kpi_data($table, $cutoff);
        
        // Get threat overview
        $threat = $this->fetch_threat_data($table, $cutoff);
        
        // Get markers for map
        $markers = $this->fetch_markers($table, $cutoff);
        
        // Get feed items
        $feed = $this->fetch_feed($table, $cutoff);
        
        // Get trend data
        $trend = $this->fetch_trend($table, $cutoff);
        
        // Get actors
        $actors = $this->fetch_actors($table, $cutoff);
        
        // Get regions
        $regions = $this->fetch_regions($table, $cutoff);
        
        // Get event types
        $types = $this->fetch_types($table, $cutoff);
        
        // Get hybrid layers
        $hybrid = $this->fetch_hybrid_layers($table, $cutoff);
        
        // Get ticker items
        $ticker = $this->fetch_ticker($table, $cutoff);
        
        return [
            'kpi' => $kpi,
            'threat_overview' => $threat,
            'markers' => $markers,
            'feed' => $feed,
            'trend' => $trend,
            'actors' => $actors,
            'regions' => $regions,
            'types' => $types,
            'hybrid_layers' => $hybrid,
            'ticker_items' => $ticker,
            'generated_at' => time(),
        ];
    }
    
    /**
     * Fetch KPI data
     */
    protected function fetch_kpi_data(string $table, int $cutoff): array {
        global $wpdb;
        
        $results = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN severity_score >= 80 THEN 1 ELSE 0 END) as critical,
                SUM(CASE WHEN severity_score >= 60 AND severity_score < 80 THEN 1 ELSE 0 END) as high,
                SUM(CASE WHEN severity_score >= 40 AND severity_score < 60 THEN 1 ELSE 0 END) as moderate,
                SUM(CASE WHEN severity_score < 40 THEN 1 ELSE 0 END) as low
            FROM {$table}
            WHERE created_at >= %d
        ", $cutoff), ARRAY_A);
        
        return [
            'total' => (int) ($results['total'] ?? 0),
            'critical' => (int) ($results['critical'] ?? 0),
            'high' => (int) ($results['high'] ?? 0),
            'moderate' => (int) ($results['moderate'] ?? 0),
            'low' => (int) ($results['low'] ?? 0),
            'risk_index' => $this->calculate_risk_index($results),
            'updated_at' => time(),
        ];
    }
    
    /**
     * Calculate risk index
     */
    protected function calculate_risk_index(array $counts): int {
        $total = max(1, (int) $counts['total']);
        $critical = (int) $counts['critical'];
        $high = (int) $counts['high'];
        
        return min(100, round((($critical * 4) + ($high * 2)) / $total * 25));
    }
    
    /**
     * Fetch threat data
     */
    protected function fetch_threat_data(string $table, int $cutoff): array {
        global $wpdb;
        
        // Top actors
        $actors = $wpdb->get_results($wpdb->prepare("
            SELECT actor_name as actor, COUNT(*) as events, AVG(severity_score) as avg_severity
            FROM {$table}
            WHERE created_at >= %d AND actor_name != ''
            GROUP BY actor_name
            ORDER BY events DESC, avg_severity DESC
            LIMIT 10
        ", $cutoff), ARRAY_A);
        
        // Top regions
        $regions = $wpdb->get_results($wpdb->prepare("
            SELECT region_name as name, COUNT(*) as score
            FROM {$table}
            WHERE created_at >= %d AND region_name != ''
            GROUP BY region_name
            ORDER BY score DESC
            LIMIT 10
        ", $cutoff), ARRAY_A);
        
        return [
            'actor_threats' => $actors ?: [],
            'top_regions' => $regions ?: [],
            'momentum_change' => 0, // Calculate based on comparison
            'escalation_index' => 0, // Calculate based on recent trends
        ];
    }
    
    /**
     * Fetch markers for map
     */
    protected function fetch_markers(string $table, int $cutoff): array {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT id, post_title as title, region_name as region, 
                   actor_name as actor, event_type as type, 
                   severity_score as severity, latitude as lat, longitude as lon
            FROM {$table}
            WHERE created_at >= %d AND latitude IS NOT NULL AND longitude IS NOT NULL
            ORDER BY created_at DESC
            LIMIT 100
        ", $cutoff), ARRAY_A) ?: [];
    }
    
    /**
     * Fetch feed items
     */
    protected function fetch_feed(string $table, int $cutoff): array {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT id, post_title as title, region_name as region,
                   actor_name as actor, event_type as type,
                   severity_score as score, created_at
            FROM {$table}
            WHERE created_at >= %d
            ORDER BY created_at DESC
            LIMIT 50
        ", $cutoff), ARRAY_A) ?: [];
    }
    
    /**
     * Fetch trend data
     */
    protected function fetch_trend(string $table, int $cutoff): array {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT DATE(FROM_UNIXTIME(created_at)) as date, COUNT(*) as count
            FROM {$table}
            WHERE created_at >= %d
            GROUP BY DATE(FROM_UNIXTIME(created_at))
            ORDER BY date ASC
        ", $cutoff), ARRAY_A) ?: [];
    }
    
    /**
     * Fetch actors
     */
    protected function fetch_actors(string $table, int $cutoff): array {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT actor_name as name, COUNT(*) as count
            FROM {$table}
            WHERE created_at >= %d AND actor_name != ''
            GROUP BY actor_name
            ORDER BY count DESC
            LIMIT 15
        ", $cutoff), ARRAY_A) ?: [];
    }
    
    /**
     * Fetch regions
     */
    protected function fetch_regions(string $table, int $cutoff): array {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT region_name as name, COUNT(*) as count
            FROM {$table}
            WHERE created_at >= %d AND region_name != ''
            GROUP BY region_name
            ORDER BY count DESC
            LIMIT 15
        ", $cutoff), ARRAY_A) ?: [];
    }
    
    /**
     * Fetch event types
     */
    protected function fetch_types(string $table, int $cutoff): array {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT event_type as name, COUNT(*) as count
            FROM {$table}
            WHERE created_at >= %d AND event_type != ''
            GROUP BY event_type
            ORDER BY count DESC
            LIMIT 10
        ", $cutoff), ARRAY_A) ?: [];
    }
    
    /**
     * Fetch hybrid warfare layers
     */
    protected function fetch_hybrid_layers(string $table, int $cutoff): array {
        global $wpdb;
        
        // This would query hybrid warfare specific fields
        return [];
    }
    
    /**
     * Fetch ticker items
     */
    protected function fetch_ticker(string $table, int $cutoff): array {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT post_title as title, region_name as region, severity_score as score
            FROM {$table}
            WHERE created_at >= %d
            ORDER BY created_at DESC
            LIMIT 12
        ", $cutoff), ARRAY_A) ?: [];
    }
    
    /**
     * Enqueue assets conditionally
     */
    public function maybe_enqueue_assets(): void {
        global $post;
        
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, self::SHORTCODE)) {
            return;
        }
        
        // Enqueue Leaflet CSS/JS
        wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        
        // Enqueue Chart.js
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true);
        
        // Enqueue our styles
        wp_enqueue_style(
            'osint-world-monitor',
            OSINT_PRO_PLUGIN_URL . 'assets/css/world-monitor.css',
            ['leaflet'],
            OSINT_PRO_VERSION
        );
    }
}
