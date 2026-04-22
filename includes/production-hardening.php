<?php
if (!defined('ABSPATH')) exit;

if (!defined('SOD_PRODUCTION_MODE')) {
    define('SOD_PRODUCTION_MODE', true);
}

if (!function_exists('sod_register_ajax_once')) {
    function sod_register_ajax_once(string $action, $callback, bool $nopriv = false, int $priority = 10): void {
        static $registered = [];
        $key = $action . '|' . $priority . '|' . (is_array($callback) ? implode('::', array_map(static fn($v) => is_object($v) ? get_class($v) : (string)$v, $callback)) : (string)$callback) . '|' . ($nopriv ? '1' : '0');
        if (isset($registered[$key])) return;
        $registered[$key] = true;
        add_action('wp_ajax_' . $action, $callback, $priority);
        if ($nopriv) add_action('wp_ajax_nopriv_' . $action, $callback, $priority);
    }
}

if (!function_exists('sod_schedule_event_once')) {
    function sod_schedule_event_once(int $timestamp, string $recurrence, string $hook, array $args = []): void {
        $next = wp_next_scheduled($hook, $args);
        if (!$next) {
            wp_schedule_event($timestamp, $recurrence, $hook, $args);
        }
    }
}

if (!function_exists('sod_schedule_single_once')) {
    function sod_schedule_single_once(int $timestamp, string $hook, array $args = []): void {
        $next = wp_next_scheduled($hook, $args);
        if (!$next) {
            wp_schedule_single_event($timestamp, $hook, $args);
        }
    }
}

if (!function_exists('sod_pipeline_normalize_item')) {
    function sod_pipeline_normalize_item(array $item): array {
        foreach (['title','description','content','summary','source_name','link','guid'] as $k) {
            if (isset($item[$k]) && is_string($item[$k])) {
                $item[$k] = trim(wp_strip_all_tags(sod_fix_mojibake_text($item[$k])));
            }
        }
        if (empty($item['title']) && !empty($item['description'])) {
            $item['title'] = mb_substr(trim(wp_strip_all_tags((string)$item['description'])), 0, 180, 'UTF-8');
        }
        if (!isset($item['date']) || !is_numeric($item['date'])) {
            $item['date'] = time();
        }
        return $item;
    }
}

if (!function_exists('sod_pipeline_is_stale')) {
    function sod_pipeline_is_stale(array $item, int $max_age = 259200): bool {
        $ts = (int)($item['date'] ?? 0);
        return $ts > 0 && (time() - $ts) > $max_age;
    }
}

if (!function_exists('sod_pipeline_similarity_fingerprint')) {
    function sod_pipeline_similarity_fingerprint(array $item): string {
        $parts = [
            (string)($item['title'] ?? ''),
            (string)($item['source_name'] ?? ''),
            (string)($item['region'] ?? ''),
            (string)($item['actor_v2'] ?? ''),
        ];
        $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
        if ($text === '') return '';
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{Arabic}\p{L}\p{N}\s]+/u', ' ', $text);
        $tokens = preg_split('/\s+/u', trim($text)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn($t) => mb_strlen($t, 'UTF-8') >= 3));
        sort($tokens);
        return $tokens ? md5(implode('|', array_slice($tokens, 0, 24))) : '';
    }
}

if (!function_exists('sod_pipeline_is_probable_duplicate')) {
    function sod_pipeline_is_probable_duplicate(array $candidate): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'so_news_events';
        $fp = sod_pipeline_similarity_fingerprint($candidate);
        if ($fp === '') return false;
        $window = max(6, (int)get_option('sod_duplicate_window_hours', 12));
        $cutoff = time() - ($window * HOUR_IN_SECONDS);
        $sql = $wpdb->prepare(
            "SELECT id FROM {$table} WHERE event_timestamp >= %d AND (title_fingerprint = %s OR hash_id = %s) LIMIT 1",
            $cutoff,
            $fp,
            $fp
        );
        return (bool)$wpdb->get_var($sql);
    }
}

if (!function_exists('sod_pipeline_process_candidate')) {
    function sod_pipeline_process_candidate(array $candidate_item, array $context = []): array {
        $candidate_item = sod_pipeline_normalize_item($candidate_item);
        if (sod_pipeline_is_stale($candidate_item)) {
            return ['ok' => false, 'reason' => 'stale'];
        }
        $pipeline = sod_ingest_and_publish_canonical_item($candidate_item, [], ['status' => 'published', 'decision_source' => 'auto']);
        if (empty($pipeline['ok']) || empty($pipeline['canonical'])) {
            return ['ok' => false, 'reason' => 'pipeline_null'];
        }
        $canonical = (array)$pipeline['canonical'];
        $canonical['title_fingerprint'] = sod_pipeline_similarity_fingerprint(array_merge($candidate_item, $canonical));
        if (!empty($canonical['title_fingerprint']) && sod_pipeline_is_probable_duplicate($canonical)) {
            return ['ok' => false, 'reason' => 'probable_duplicate', 'canonical' => $canonical, 'analyzed' => (array)($pipeline['analyzed'] ?? [])];
        }
        return ['ok' => true, 'canonical' => $canonical, 'analyzed' => (array)($pipeline['analyzed'] ?? [])];
    }
}

if (!function_exists('sod_watchdog_touch')) {
    function sod_watchdog_touch(string $channel = 'pipeline'): void {
        update_option('sod_watchdog_' . sanitize_key($channel), time(), false);
    }
}

if (!function_exists('sod_watchdog_needs_recovery')) {
    function sod_watchdog_needs_recovery(string $channel = 'pipeline', int $ttl = 1800): bool {
        $last = (int)get_option('sod_watchdog_' . sanitize_key($channel), 0);
        return $last > 0 && (time() - $last) > $ttl;
    }
}

if (!class_exists('SOD_Production_Kernel')) {
    class SOD_Production_Kernel {
        public static function init(): void {
            add_filter('cron_schedules', [__CLASS__, 'cron_schedules'], 1);
            add_action('sod_watchdog_cron', [__CLASS__, 'watchdog']);
            add_action('admin_init', [__CLASS__, 'production_defaults']);
            add_action('init', [__CLASS__, 'register_shortcodes'], 30);
        }

        public static function cron_schedules(array $schedules): array {
            $schedules['every_five_minutes'] = $schedules['every_five_minutes'] ?? ['interval' => 300, 'display' => 'كل ٥ دقائق'];
            $schedules['every_ten_minutes'] = $schedules['every_ten_minutes'] ?? ['interval' => 600, 'display' => 'كل ١٠ دقائق'];
            return $schedules;
        }

        public static function bootstrap_runtime(): void {
            sod_schedule_event_once(time() + 60, 'every_ten_minutes', 'sod_watchdog_cron');
            self::clear_legacy_cron_hooks();
        }

        public static function clear_legacy_cron_hooks(): void {
            $legacy_hooks = ['so_cron_fetch_news_v4'];
            foreach ($legacy_hooks as $hook) {
                if (wp_next_scheduled($hook)) {
                    wp_clear_scheduled_hook($hook);
                }
            }
        }

        public static function watchdog(): void {
            sod_watchdog_touch('watchdog');
            if (get_transient('so_sync_process_lock') && sod_watchdog_needs_recovery('pipeline', SO_SYNC_LOCK_TTL + 120)) {
                delete_transient('so_sync_process_lock');
                update_option('sod_watchdog_last_recovery', time(), false);
            }
            if (!wp_next_scheduled('so_cron_fetch_news_v5') && class_exists('SO_Cron_Manager')) {
                sod_schedule_event_once(time() + 30, 'every_five_minutes', 'so_cron_fetch_news_v5');
            }
        }

        public static function production_defaults(): void {
            if (!current_user_can('manage_options')) return;
            $defaults = [
                'sod_duplicate_window_hours' => 12,
                'so_exec_reports_enabled' => get_option('so_exec_reports_enabled', 1),
            ];
            foreach ($defaults as $key => $value) {
                if (get_option($key, null) === null) {
                    update_option($key, $value, false);
                }
            }
        }

        public static function register_shortcodes(): void {
            if (shortcode_exists('osint_live_command_center_v2')) return;
            if (shortcode_exists('osint_command_center')) {
                add_shortcode('osint_live_command_center_v2', function($atts = []) {
                    return do_shortcode('[osint_command_center]');
                });
            } elseif (function_exists('sod_render_command_deck')) {
                add_shortcode('osint_live_command_center_v2', 'sod_render_command_deck');
            }
        }
    }
}
