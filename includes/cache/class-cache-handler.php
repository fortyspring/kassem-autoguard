<?php
/**
 * Cache Handler
 * 
 * Handles caching with support for Redis, Memcached, and WordPress Transients.
 * Auto-detects available cache backends and uses the best available option.
 * 
 * @package BeirutTime_OSINT_Pro
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OSINT_Cache_Handler {
    
    private static $instance = null;
    private $backend = 'wp'; // wp, redis, memcached
    private $redis = null;
    private $memcached = null;
    private $prefix = 'osint_';
    private $default_ttl = 3600;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - detect and initialize best available cache backend
     */
    private function __construct() {
        $this->detect_backend();
        $this->initialize();
    }
    
    /**
     * Detect best available cache backend
     */
    private function detect_backend() {
        // Check for Redis first (fastest)
        if (class_exists('Redis') && defined('OSINT_REDIS_HOST')) {
            $this->backend = 'redis';
            return;
        }
        
        // Check for Memcached
        if (class_exists('Memcached') && defined('OSINT_MEMCACHED_HOST')) {
            $this->backend = 'memcached';
            return;
        }
        
        // Fall back to WordPress transients
        $this->backend = 'wp';
    }
    
    /**
     * Initialize cache backend
     */
    private function initialize() {
        if ($this->backend === 'redis') {
            $this->init_redis();
        } elseif ($this->backend === 'memcached') {
            $this->init_memcached();
        }
    }
    
    /**
     * Initialize Redis connection
     */
    private function init_redis() {
        try {
            $this->redis = new Redis();
            $host = defined('OSINT_REDIS_HOST') ? OSINT_REDIS_HOST : '127.0.0.1';
            $port = defined('OSINT_REDIS_PORT') ? OSINT_REDIS_PORT : 6379;
            $timeout = defined('OSINT_REDIS_TIMEOUT') ? OSINT_REDIS_TIMEOUT : 2.5;
            
            $this->redis->connect($host, $port, $timeout);
            
            if (defined('OSINT_REDIS_PASSWORD')) {
                $this->redis->auth(OSINT_REDIS_PASSWORD);
            }
            
            $db = defined('OSINT_REDIS_DB') ? OSINT_REDIS_DB : 0;
            $this->redis->select($db);
            
            $this->prefix .= 'redis_';
        } catch (Exception $e) {
            error_log('[OSINT] Redis initialization failed: ' . $e->getMessage());
            $this->backend = 'wp';
        }
    }
    
    /**
     * Initialize Memcached connection
     */
    private function init_memcached() {
        try {
            $this->memcached = new Memcached();
            $host = defined('OSINT_MEMCACHED_HOST') ? OSINT_MEMCACHED_HOST : '127.0.0.1';
            $port = defined('OSINT_MEMCACHED_PORT') ? OSINT_MEMCACHED_PORT : 11211;
            
            $this->memcached->addServer($host, $port);
            
            // Set options
            $this->memcached->setOption(Memcached::OPT_PREFIX_KEY, $this->prefix . 'memcached_');
            $this->memcached->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_PHP);
            
        } catch (Exception $e) {
            error_log('[OSINT] Memcached initialization failed: ' . $e->getMessage());
            $this->backend = 'wp';
        }
    }
    
    /**
     * Get value from cache
     * 
     * @param string $key Cache key
     * @return mixed|false Cached value or false if not found
     */
    public function get($key) {
        $full_key = $this->prefix . $key;
        
        switch ($this->backend) {
            case 'redis':
                if ($this->redis) {
                    $value = $this->redis->get($full_key);
                    // Use JSON_decode instead of unserialize for security (prevent RCE)
                    return $value !== false ? json_decode($value, true) : false;
                }
                break;
                
            case 'memcached':
                if ($this->memcached) {
                    return $this->memcached->get($full_key);
                }
                break;
                
            default:
                return get_transient($full_key);
        }
        
        return false;
    }
    
    /**
     * Set value in cache
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return bool
     */
    public function set($key, $value, $ttl = null) {
        $full_key = $this->prefix . $key;
        $ttl = $ttl ?? $this->default_ttl;
        
        switch ($this->backend) {
            case 'redis':
                if ($this->redis) {
                    // Use JSON_encode instead of serialize for security (prevent RCE)
                    return $this->redis->setex($full_key, $ttl, json_encode($value));
                }
                break;
                
            case 'memcached':
                if ($this->memcached) {
                    return $this->memcached->set($full_key, $value, $ttl);
                }
                break;
                
            default:
                return set_transient($full_key, $value, $ttl);
        }
        
        return false;
    }
    
    /**
     * Delete value from cache
     * 
     * @param string $key Cache key
     * @return bool
     */
    public function delete($key) {
        $full_key = $this->prefix . $key;
        
        switch ($this->backend) {
            case 'redis':
                if ($this->redis) {
                    return $this->redis->del($full_key) > 0;
                }
                break;
                
            case 'memcached':
                if ($this->memcached) {
                    return $this->memcached->delete($full_key);
                }
                break;
                
            default:
                return delete_transient($full_key);
        }
        
        return false;
    }
    
    /**
     * Clear cache group (keys with common prefix)
     * 
     * @param string $group Group prefix
     * @return bool
     */
    public function clear_group($group) {
        $pattern = $this->prefix . $group . '*';
        
        switch ($this->backend) {
            case 'redis':
                if ($this->redis) {
                    $keys = $this->redis->keys($pattern);
                    if (!empty($keys)) {
                        return $this->redis->del($keys) > 0;
                    }
                }
                break;
                
            case 'memcached':
                // Memcached doesn't support pattern deletion, flush all (use with caution)
                if ($this->memcached) {
                    return $this->memcached->flush();
                }
                break;
                
            default:
                // WordPress doesn't support group clearing efficiently
                global $wpdb;
                $like = $wpdb->esc_like('_transient_' . $pattern) . '%';
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                        $like
                    )
                );
                return true;
        }
        
        return false;
    }
    
    /**
     * Clear all cache
     * 
     * @return bool
     */
    public function flush() {
        switch ($this->backend) {
            case 'redis':
                if ($this->redis) {
                    return $this->redis->flushDb();
                }
                break;
                
            case 'memcached':
                if ($this->memcached) {
                    return $this->memcached->flush();
                }
                break;
                
            default:
                global $wpdb;
                $like = $wpdb->esc_like('_transient_' . $this->prefix) . '%';
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                        $like
                    )
                );
                return true;
        }
        
        return false;
    }
    
    /**
     * Cleanup old/expired cache entries
     * 
     * @return void
     */
    public function cleanup() {
        // Redis and Memcached handle expiration automatically
        // WordPress transients also handle this, but we can force cleanup
        if ($this->backend === 'wp') {
            global $wpdb;
            $time = time();
            $expired_time = $time - HOUR_IN_SECONDS;
            
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} 
                     WHERE option_name LIKE '_transient_timeout_%' 
                     AND option_value < %d",
                    $expired_time
                )
            );
            
            // Clean orphaned transient values
            $wpdb->query(
                "DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE '_transient_%' 
                 AND option_name NOT IN (
                     SELECT REPLACE(option_name, '_transient_timeout_', '') 
                     FROM {$wpdb->options} 
                     WHERE option_name LIKE '_transient_timeout_%'
                 )"
            );
        }
    }
    
    /**
     * Get cache statistics
     * 
     * @return array
     */
    public function get_stats() {
        $stats = array(
            'backend' => $this->backend,
            'prefix' => $this->prefix,
        );
        
        switch ($this->backend) {
            case 'redis':
                if ($this->redis) {
                    $info = $this->redis->info();
                    $stats['used_memory'] = $info['used_memory_human'] ?? 'N/A';
                    $stats['connected_clients'] = $info['connected_clients'] ?? 0;
                }
                break;
                
            case 'memcached':
                if ($this->memcached) {
                    $memStats = $this->memcached->getStats();
                    $stats['servers'] = count($memStats) ?? 0;
                }
                break;
        }
        
        return $stats;
    }
    
    /**
     * Test cache connection
     * 
     * @return bool
     */
    public function test_connection() {
        $test_key = $this->prefix . 'connection_test';
        $test_value = time();
        
        $set = $this->set($test_key, $test_value, 60);
        if (!$set) {
            return false;
        }
        
        $get = $this->get($test_key);
        $this->delete($test_key);
        
        return $get === $test_value;
    }
}
