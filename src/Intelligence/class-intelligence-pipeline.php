<?php
/**
 * Intelligence Pipeline Engine
 * 
 * Handles news intake, classification, enrichment, and deduplication.
 * Single source of truth for intelligence processing.
 * 
 * @package OSINT_LB_PRO
 * @subpackage Intelligence
 * @since 2.0.0
 */

namespace OSINT_LB_PRO\Intelligence;

use OSINT_LB_PRO\Security\Security_Manager;

class Intelligence_Pipeline {
    
    /**
     * Security manager instance
     * 
     * @var Security_Manager
     */
    private $security;
    
    /**
     * Processing queue
     * 
     * @var array
     */
    private $queue = [];
    
    /**
     * Constructor
     * 
     * @param Security_Manager $security Security manager instance
     */
    public function __construct(Security_Manager $security) {
        $this->security = $security;
        
        add_action('osint_classify_event', [$this, 'classify_event'], 10, 1);
        add_action('osint_process_intake', [$this, 'process_intake'], 10, 2);
    }
    
    /**
     * Initialize pipeline
     * 
     * @return void
     */
    public function init(): void {
        // Pipeline initialization
    }
    
    /**
     * Process news intake
     * 
     * @param array $raw_data Raw news data
     * @param string $source_id Source identifier
     * @return int|WP_Error Event ID or error
     */
    public function process_intake(array $raw_data, string $source_id) {
        // Step 1: Clean and normalize
        $cleaned = $this->clean_and_normalize($raw_data);
        
        if (is_wp_error($cleaned)) {
            return $cleaned;
        }
        
        // Step 2: Generate content hash for deduplication
        $content_hash = $this->generate_content_hash($cleaned);
        
        // Step 3: Check for duplicates
        $existing = $this->check_duplicate($content_hash);
        
        if ($existing) {
            return new \WP_Error(
                'duplicate_event',
                __('حدث مكرر', 'osint-lb-pro'),
                ['existing_id' => $existing]
            );
        }
        
        // Step 4: Enrich data
        $enriched = $this->enrich_data($cleaned);
        
        // Step 5: Classify
        $classified = $this->classify_intake($enriched);
        
        // Step 6: Score threat level
        $scored = $this->calculate_threat_score($classified);
        
        // Step 7: Persist
        $event_id = $this->persist_event($scored, $source_id, $content_hash);
        
        // Step 8: Trigger alerts if needed
        if ($scored['severity'] >= get_option('osint_alert_min_severity', 70)) {
            do_action('osint_trigger_alert', $event_id, $scored);
        }
        
        return $event_id;
    }
    
    /**
     * Clean and normalize raw data
     * 
     * @param array $raw_data Raw input
     * @return array|WP_Error
     */
    private function clean_and_normalize(array $raw_data) {
        $required_fields = ['title', 'content'];
        
        foreach ($required_fields as $field) {
            if (empty($raw_data[$field])) {
                return new \WP_Error(
                    'missing_required_field',
                    sprintf(__('الحقل المطلوب مفقود: %s', 'osint-lb-pro'), $field)
                );
            }
        }
        
        return [
            'title' => sanitize_text_field($raw_data['title']),
            'content' => wp_kses_post($raw_data['content']),
            'source_url' => isset($raw_data['url']) ? esc_url_raw($raw_data['url']) : '',
            'published_at' => isset($raw_data['published_at']) 
                ? sanitize_text_field($raw_data['published_at']) 
                : current_time('mysql'),
            'author' => isset($raw_data['author']) 
                ? sanitize_text_field($raw_data['author']) 
                : '',
            'categories' => isset($raw_data['categories']) 
                ? array_map('sanitize_text_field', $raw_data['categories']) 
                : [],
            'raw_metadata' => isset($raw_data['metadata']) ? $raw_data['metadata'] : []
        ];
    }
    
    /**
     * Generate content hash for deduplication
     * 
     * @param array $data Cleaned data
     * @return string
     */
    private function generate_content_hash(array $data): string {
        $content = sprintf(
            '%s|%s|%s',
            strtolower(trim($data['title'])),
            substr(strtolower(trim($data['content'])), 0, 500),
            date('Y-m-d', strtotime($data['published_at']))
        );
        
        return md5($content);
    }
    
    /**
     * Check for existing duplicate
     * 
     * @param string $content_hash Content hash
     * @return int|null Existing event ID or null
     */
    private function check_duplicate(string $content_hash): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        $dedupe_window = absint(get_option('osint_dedupe_window', 3600));
        
        $id = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$table}
            WHERE content_hash = %s
            AND created_at >= DATE_SUB(NOW(), INTERVAL %d SECOND)
            LIMIT 1
        ", $content_hash, $dedupe_window));
        
        return $id ? (int) $id : null;
    }
    
    /**
     * Enrich data with extracted entities
     * 
     * @param array $data Cleaned data
     * @return array
     */
    private function enrich_data(array $data): array {
        $data['actor_name'] = $this->extract_actor($data['title'], $data['content']);
        $data['region'] = $this->extract_region($data['title'], $data['content']);
        $data['country'] = $this->extract_country($data['title'], $data['content']);
        $data['location'] = $this->extract_location($data['title'], $data['content']);
        $data['coordinates'] = $this->geocode_location($data['location'], $data['country']);
        $data['targets'] = $this->extract_targets($data['content']);
        $data['context'] = $this->infer_context($data);
        $data['intent'] = $this->detect_intent($data);
        
        return $data;
    }
    
    /**
     * Extract actor name from content
     * 
     * @param string $title Article title
     * @param string $content Article content
     * @return string|null
     */
    private function extract_actor(string $title, string $content): ?string {
        // Simple actor extraction patterns
        $patterns = [
            '/(?:قال|أعلن|صرح|أكّد)\s+([^،\.]+)/u',
            '/(?:جماعة|منظمة|حركة|قوات|جيش)\s+([^\s،\.]+)/u',
            '/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                return trim($matches[1]);
            }
        }
        
        return null;
    }
    
    /**
     * Extract region from content
     * 
     * @param string $title Article title
     * @param string $content Article content
     * @return string|null
     */
    private function extract_region(string $title, string $content): ?string {
        $regions = [
            'الشرق الأوسط', 'أوروبا', 'آسيا', 'أفريقيا', 
            'أمريكا الشمالية', 'أمريكا الجنوبية', 'أستراليا'
        ];
        
        foreach ($regions as $region) {
            if (mb_strpos($title, $region) !== false || mb_strpos($content, $region) !== false) {
                return $region;
            }
        }
        
        // Default to Middle East for Arabic content
        return 'الشرق الأوسط';
    }
    
    /**
     * Extract country from content
     * 
     * @param string $title Article title
     * @param string $content Article content
     * @return string|null
     */
    private function extract_country(string $title, string $content): ?string {
        $countries = [
            'لبنان', 'سوريا', 'فلسطين', 'إسرائيل', 'الأردن',
            'العراق', 'إيران', 'السعودية', 'مصر', 'تركيا'
        ];
        
        foreach ($countries as $country) {
            if (mb_strpos($title, $country) !== false || mb_strpos($content, $country) !== false) {
                return $country;
            }
        }
        
        return null;
    }
    
    /**
     * Extract specific location from content
     * 
     * @param string $title Article title
     * @param string $content Article content
     * @return string|null
     */
    private function extract_location(string $title, string $content): ?string {
        $city_patterns = [
            '/(?:في|من)\s+(بيروت|دمشق|غزة|القدس|عمّان|بغداد|طهران|الرياض|القاهرة|أنقرة)/u'
        ];
        
        foreach ($city_patterns as $pattern) {
            if (preg_match($pattern, $title, $matches) || preg_match($pattern, $content, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    /**
     * Geocode location to coordinates
     * 
     * @param string|null $location Location name
     * @param string|null $country Country name
     * @return array|null [lat, lng]
     */
    private function geocode_location(?string $location, ?string $country): ?array {
        static $coordinates = [
            'بيروت' => [33.8938, 35.5018],
            'دمشق' => [33.5138, 36.2765],
            'غزة' => [31.5017, 34.4668],
            'القدس' => [31.7683, 35.2137],
            'عمّان' => [31.9454, 35.9284],
            'بغداد' => [33.3152, 44.3661],
            'طهران' => [35.6892, 51.3890],
            'الرياض' => [24.7136, 46.6753],
            'القاهرة' => [30.0444, 31.2357],
            'أنقرة' => [39.9334, 32.8597]
        ];
        
        if ($location && isset($coordinates[$location])) {
            return $coordinates[$location];
        }
        
        return null;
    }
    
    /**
     * Extract targets from content
     * 
     * @param string $content Article content
     * @return array
     */
    private function extract_targets(string $content): array {
        $target_keywords = [
            '军事' => 'military',
            'اقتصادي' => 'economic',
            'سياسي' => 'political',
            'أمني' => 'security',
            'مدني' => 'civilian',
            'بنية تحتية' => 'infrastructure'
        ];
        
        $targets = [];
        
        foreach ($target_keywords as $arabic => $type) {
            if (mb_strpos($content, $arabic) !== false) {
                $targets[] = $type;
            }
        }
        
        return array_unique($targets);
    }
    
    /**
     * Infer context from data
     * 
     * @param array $data Enriched data
     * @return string
     */
    private function infer_context(array $data): string {
        $context_keywords = [
            'انتخاب' => 'electoral',
            'صراع' => 'conflict',
            'تفاوض' => 'negotiation',
            'هجوم' => 'attack',
            'احتجاج' => 'protest',
            'تصعيد' => 'escalation',
            'هدنة' => 'ceasefire'
        ];
        
        $content = $data['title'] . ' ' . $data['content'];
        
        foreach ($context_keywords as $arabic => $context) {
            if (mb_strpos($content, $arabic) !== false) {
                return $context;
            }
        }
        
        return 'general';
    }
    
    /**
     * Detect intent from content
     * 
     * @param array $data Enriched data
     * @return string
     */
    private function detect_intent(array $data): string {
        $threat_words = [
            'تهديد', 'تحذير', 'وعيد', 'توعد', 'خطر'
        ];
        
        $content = $data['title'] . ' ' . $data['content'];
        
        foreach ($threat_words as $word) {
            if (mb_strpos($content, $word) !== false) {
                return 'threatening';
            }
        }
        
        return 'informative';
    }
    
    /**
     * Classify intake event
     * 
     * @param array $data Enriched data
     * @return array
     */
    private function classify_intake(array $data): array {
        $data['event_type'] = $this->determine_event_type($data);
        $data['category'] = $this->determine_category($data);
        $data['subcategory'] = $this->determine_subcategory($data);
        
        return $data;
    }
    
    /**
     * Determine event type
     * 
     * @param array $data Classified data
     * @return string
     */
    private function determine_event_type(array $data): string {
        $type_keywords = [
            'هجوم' => 'attack',
            'اغتيال' => 'assassination',
            'انفجار' => 'explosion',
            'إطلاق نار' => 'shooting',
            'قصف' => 'bombardment',
            'اختطاف' => 'kidnapping',
            'اعتقال' => 'arrest',
            'تظاهرة' => 'demonstration',
            'إضراب' => 'strike'
        ];
        
        $content = $data['title'] . ' ' . $data['content'];
        
        foreach ($type_keywords as $arabic => $type) {
            if (mb_strpos($content, $arabic) !== false) {
                return $type;
            }
        }
        
        return 'incident';
    }
    
    /**
     * Determine category
     * 
     * @param array $data Classified data
     * @return string
     */
    private function determine_category(array $data): string {
        $categories = [
            'security' => ['أمني', 'عسكري', 'قتال'],
            'political' => ['سياسي', 'انتخاب', 'حكومة'],
            'social' => ['اجتماعي', 'احتجاج', 'تظاهرة'],
            'economic' => ['اقتصادي', 'مالي', 'تجارة']
        ];
        
        $content = $data['title'] . ' ' . $data['content'];
        
        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_strpos($content, $keyword) !== false) {
                    return $category;
                }
            }
        }
        
        return 'general';
    }
    
    /**
     * Determine subcategory
     * 
     * @param array $data Classified data
     * @return string
     */
    private function determine_subcategory(array $data): string {
        return $data['event_type'];
    }
    
    /**
     * Calculate threat score
     * 
     * @param array $data Classified data
     * @return array
     */
    private function calculate_threat_score(array $data): array {
        $base_score = 20;
        
        // Intent modifier
        if ($data['intent'] === 'threatening') {
            $base_score += 25;
        }
        
        // Event type modifier
        $type_scores = [
            'attack' => 40,
            'assassination' => 50,
            'explosion' => 45,
            'shooting' => 35,
            'bombardment' => 45,
            'kidnapping' => 40,
            'arrest' => 15,
            'demonstration' => 20,
            'strike' => 15,
            'incident' => 25
        ];
        
        $base_score += $type_scores[$data['event_type']] ?? 20;
        
        // Actor presence modifier
        if (!empty($data['actor_name'])) {
            $base_score += 10;
        }
        
        // Target modifier
        if (in_array('military', $data['targets']) || in_array('security', $data['targets'])) {
            $base_score += 15;
        }
        
        if (in_array('civilian', $data['targets'])) {
            $base_score += 20;
        }
        
        // Context modifier
        $context_scores = [
            'conflict' => 20,
            'attack' => 25,
            'escalation' => 20,
            'negotiation' => -10,
            'ceasefire' => -15
        ];
        
        $base_score += $context_scores[$data['context']] ?? 0;
        
        // Clamp score between 0-100
        $data['severity'] = max(0, min(100, $base_score));
        $data['threat_level'] = $data['severity'];
        
        return $data;
    }
    
    /**
     * Persist event to database
     * 
     * @param array $data Scored data
     * @param string $source_id Source identifier
     * @param string $content_hash Content hash
     * @return int|WP_Error
     */
    private function persist_event(array $data, string $source_id, string $content_hash) {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        $result = $wpdb->insert($table, [
            'title' => $data['title'],
            'content' => $data['content'],
            'source_id' => $source_id,
            'source_url' => $data['source_url'],
            'actor_name' => $data['actor_name'],
            'region' => $data['region'],
            'country' => $data['country'],
            'location' => $data['location'],
            'latitude' => $data['coordinates'][0] ?? null,
            'longitude' => $data['coordinates'][1] ?? null,
            'targets' => !empty($data['targets']) ? implode(',', $data['targets']) : null,
            'context' => $data['context'],
            'intent' => $data['intent'],
            'event_type' => $data['event_type'],
            'category' => $data['category'],
            'subcategory' => $data['subcategory'],
            'severity' => $data['severity'],
            'threat_level' => $data['threat_level'],
            'status' => 'active',
            'content_hash' => $content_hash,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ], [
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', 
            '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', 
            '%d', '%d', '%s', '%s', '%s', '%s'
        ]);
        
        if ($result === false) {
            return new \WP_Error('event_persist_failed', $wpdb->last_error);
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Classify existing event by ID
     * 
     * @param int $event_id Event ID
     * @return array|WP_Error
     */
    public function classify_event(int $event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'osint_events';
        
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $event_id
        ), ARRAY_A);
        
        if (!$event) {
            return new \WP_Error('event_not_found', __('الحدث غير موجود', 'osint-lb-pro'));
        }
        
        // Re-process through enrichment and classification
        $enriched = $this->enrich_data($event);
        $classified = $this->classify_intake($enriched);
        $scored = $this->calculate_threat_score($classified);
        
        // Update event
        $wpdb->update($table, [
            'actor_name' => $scored['actor_name'],
            'region' => $scored['region'],
            'country' => $scored['country'],
            'location' => $scored['location'],
            'latitude' => $scored['coordinates'][0] ?? null,
            'longitude' => $scored['coordinates'][1] ?? null,
            'targets' => !empty($scored['targets']) ? implode(',', $scored['targets']) : null,
            'context' => $scored['context'],
            'intent' => $scored['intent'],
            'event_type' => $scored['event_type'],
            'category' => $scored['category'],
            'subcategory' => $scored['subcategory'],
            'severity' => $scored['severity'],
            'threat_level' => $scored['threat_level'],
            'updated_at' => current_time('mysql')
        ], ['id' => $event_id], [
            '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', 
            '%s', '%s', '%s', '%s', '%d', '%d', '%s'
        ], ['%d']);
        
        return $scored;
    }
    
    /**
     * Batch process queue
     * 
     * @param int $limit Number of items to process
     * @return array Processing results
     */
    public function process_queue(int $limit = 10): array {
        $results = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'duplicates' => 0,
            'errors' => []
        ];
        
        $items = array_splice($this->queue, 0, $limit);
        
        foreach ($items as $item) {
            $results['processed']++;
            
            $result = $this->process_intake($item['data'], $item['source_id']);
            
            if (is_wp_error($result)) {
                if ($result->get_error_code() === 'duplicate_event') {
                    $results['duplicates']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'item' => $item,
                        'error' => $result->get_error_message()
                    ];
                }
            } else {
                $results['success']++;
            }
        }
        
        return $results;
    }
    
    /**
     * Add item to processing queue
     * 
     * @param array $data Raw data
     * @param string $source_id Source ID
     * @return void
     */
    public function queue_item(array $data, string $source_id): void {
        $this->queue[] = [
            'data' => $data,
            'source_id' => $source_id,
            'queued_at' => time()
        ];
    }
}
