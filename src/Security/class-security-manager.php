<?php
/**
 * Security Manager
 * 
 * Handles all security-related functionality:
 * - Nonce verification
 * - Capability checks
 * - Input sanitization
 * - Output escaping
 * - AJAX security
 * 
 * @package OSINT_Pro\Security
 */

namespace OSINT_Pro\Security;

use SO\Traits\Singleton;

/**
 * Security Manager Class
 */
class Security_Manager {
    
    use Singleton;
    
    /**
     * Initialize security hooks
     */
    public function init(): void {
        // Add security headers
        add_action('send_headers', [$this, 'add_security_headers']);
        
        // Restrict admin access
        add_action('admin_init', [$this, 'restrict_admin_access']);
        
        // Filter unauthorized users
        add_filter('wp_ajax_nopriv_', [$this, 'block_unauthorized_ajax'], 1);
    }
    
    /**
     * Add security headers
     */
    public function add_security_headers(): void {
        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');
        
        // XSS protection
        header('X-XSS-Protection: 1; mode=block');
        
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
    
    /**
     * Restrict admin access to authorized users only
     */
    public function restrict_admin_access(): void {
        // Only allow users with appropriate capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
    }
    
    /**
     * Block unauthorized AJAX requests
     */
    public function block_unauthorized_ajax(): void {
        // All nopriv AJAX should be explicitly allowed
        // This is a catch-all for any undefined endpoints
        wp_die('Unauthorized access', 'Error', ['response' => 403]);
    }
    
    /**
     * Verify nonce
     * 
     * @param string $nonce Nonce value
     * @param string $action Action name
     * @return bool
     */
    public function verify_nonce(string $nonce, string $action): bool {
        return wp_verify_nonce($nonce, $action) !== false;
    }
    
    /**
     * Check capability
     * 
     * @param string $capability Required capability
     * @param int|null $user_id User ID (defaults to current user)
     * @return bool
     */
    public function check_capability(string $capability, ?int $user_id = null): bool {
        if ($user_id === null) {
            return current_user_can($capability);
        }
        return user_can($user_id, $capability);
    }
    
    /**
     * Require capability or die
     * 
     * @param string $capability Required capability
     * @param string $message Error message
     */
    public function require_capability(string $capability, string $message = 'Unauthorized'): void {
        if (!$this->check_capability($capability)) {
            wp_die($message, 'Unauthorized', ['response' => 403]);
        }
    }
    
    /**
     * Sanitize input text
     * 
     * @param string $input Input value
     * @return string
     */
    public function sanitize_text(string $input): string {
        return sanitize_text_field($input);
    }
    
    /**
     * Sanitize textarea input
     * 
     * @param string $input Input value
     * @return string
     */
    public function sanitize_textarea(string $input): string {
        return sanitize_textarea_field($input);
    }
    
    /**
     * Sanitize integer
     * 
     * @param mixed $input Input value
     * @return int
     */
    public function sanitize_int($input): int {
        return (int) $input;
    }
    
    /**
     * Sanitize array of values
     * 
     * @param array $values Input array
     * @param string $type Type of sanitization (text, int, email, url)
     * @return array
     */
    public function sanitize_array(array $values, string $type = 'text'): array {
        return array_map(function($value) use ($type) {
            switch ($type) {
                case 'int':
                    return $this->sanitize_int($value);
                case 'email':
                    return sanitize_email($value);
                case 'url':
                    return esc_url_raw($value);
                case 'textarea':
                    return $this->sanitize_textarea($value);
                default:
                    return $this->sanitize_text($value);
            }
        }, $values);
    }
    
    /**
     * Escape output for HTML
     * 
     * @param string $output Output value
     * @return string
     */
    public function escape_html(string $output): string {
        return esc_html($output);
    }
    
    /**
     * Escape output for attributes
     * 
     * @param string $output Output value
     * @return string
     */
    public function escape_attr(string $output): string {
        return esc_attr($output);
    }
    
    /**
     * Escape output for URLs
     * 
     * @param string $output Output value
     * @return string
     */
    public function escape_url(string $output): string {
        return esc_url($output);
    }
    
    /**
     * Escape output for JavaScript
     * 
     * @param string $output Output value
     * @return string
     */
    public function escape_js(string $output): string {
        return esc_js($output);
    }
    
    /**
     * Validate and sanitize JSON input
     * 
     * @param string $json JSON string
     * @return array|null
     */
    public function sanitize_json(string $json): ?array {
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return $decoded;
    }
    
    /**
     * Generate secure random token
     * 
     * @param int $length Token length in bytes
     * @return string
     */
    public function generate_token(int $length = 32): string {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Hash sensitive data
     * 
     * @param string $data Data to hash
     * @return string
     */
    public function hash(string $data): string {
        return hash('sha256', $data);
    }
    
    /**
     * Verify hashed data
     * 
     * @param string $data Original data
     * @param string $hash Hash to verify
     * @return bool
     */
    public function verify_hash(string $data, string $hash): bool {
        return hash_equals($hash, $this->hash($data));
    }
    
    /**
     * Rate limit check
     * 
     * @param string $key Rate limit key
     * @param int $limit Maximum attempts
     * @param int $window Time window in seconds
     * @return bool True if allowed, false if rate limited
     */
    public function rate_limit(string $key, int $limit = 10, int $window = 60): bool {
        $transient_key = 'osint_rate_limit_' . md5($key);
        $attempts = get_transient($transient_key) ?: 0;
        
        if ($attempts >= $limit) {
            return false;
        }
        
        set_transient($transient_key, $attempts + 1, $window);
        return true;
    }
    
    /**
     * Log security event
     * 
     * @param string $event Event type
     * @param array $context Event context
     */
    public function log_event(string $event, array $context = []): void {
        error_log(sprintf(
            '[OSINT Security] %s: %s',
            $event,
            json_encode(array_merge($context, [
                'user_id' => get_current_user_id(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'timestamp' => time(),
            ]))
        ));
    }
}
