<?php
/**
 * Intelligence Module
 * 
 * Core classification engine for events:
 * - Actor extraction and resolution
 * - Target detection
 * - Location extraction
 * - Intent detection
 * - Severity scoring
 * - Threat level calculation
 * - Duplicate semantic detection
 * 
 * @package OSINT_PRO/Intelligence
 * @version 1.0.0
 */

namespace OSINT_PRO\Intelligence;

use OSINT_PRO\Core\Interfaces\Module_Interface;
use OSINT_PRO\Core\Utilities;

if (!defined('ABSPATH')) {
    exit;
}

class Intelligence_Module implements Module_Interface {
    
    /**
     * Module name
     */
    const NAME = 'intelligence';
    
    /**
     * Known actors database (cached)
     */
    const ACTORS_CACHE_KEY = 'osint_known_actors';
    
    /**
     * Known locations database (cached)
     */
    const LOCATIONS_CACHE_KEY = 'osint_known_locations';
    
    /**
     * Threat score thresholds
     */
    const THREAT_THRESHOLDS = [
        'critical' => 90,
        'high' => 70,
        'medium' => 40,
        'low' => 0,
    ];
    
    /**
     * Initialize module
     */
    public function init(): void {
        $this->register_ajax_handlers();
        $this->register_filters();
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers(): void {
        add_action('wp_ajax_osint_route', [$this, 'handle_ajax_request']);
    }
    
    /**
     * Register WordPress filters
     */
    private function register_filters(): void {
        add_filter('osint_classify_event', [$this, 'classify_event'], 10, 2);
        add_filter('osint_extract_actors', [$this, 'extract_actors'], 10, 2);
        add_filter('osint_calculate_threat_score', [$this, 'calculate_threat_score'], 10, 2);
    }
    
    /**
     * Handle AJAX requests via router
     */
    public function handle_ajax_request(array $request): array {
        $endpoint = $request['endpoint'] ?? '';
        $parts = explode('.', $endpoint);
        
        if ($parts[0] !== 'intelligence') {
            return ['success' => false, 'message' => 'Not an intelligence endpoint'];
        }
        
        $action = $parts[1] ?? '';
        
        switch ($action) {
            case 'classify':
                return $this->ajax_classify_event($request);
            case 'extract_actors':
                return $this->ajax_extract_actors($request);
            case 'detect_duplicates':
                return $this->ajax_detect_duplicates($request);
            case 'reclassify_batch':
                return $this->ajax_reclassify_batch($request);
            default:
                return ['success' => false, 'message' => 'Unknown action'];
        }
    }
    
    /**
     * Classify event via AJAX
     */
    private function ajax_classify_event(array $request): array {
        try {
            $event_id = intval($request['params']['event_id'] ?? 0);
            
            if (!$event_id) {
                throw new \Exception('معرف الحدث غير صالح');
            }
            
            global $wpdb;
            $table = $wpdb->prefix . 'so_news_events';
            
            $event = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $event_id
            ), ARRAY_A);
            
            if (!$event) {
                throw new \Exception('الحدث غير موجود');
            }
            
            $classified = $this->classify_event($event);
            
            // Update database
            $wpdb->update($table, $classified, ['id' => $event_id]);
            
            return [
                'success' => true,
                'data' => $classified,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Extract actors via AJAX
     */
    private function ajax_extract_actors(array $request): array {
        try {
            $text = sanitize_textarea_field($request['params']['text'] ?? '');
            
            if (empty($text)) {
                throw new \Exception('النص فارغ');
            }
            
            $actors = $this->extract_actors(['title' => '', 'content' => $text]);
            
            return [
                'success' => true,
                'data' => [
                    'primary_actor' => $actors['primary'],
                    'secondary_actors' => $actors['secondary'],
                    'all_actors' => $actors['all'],
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
     * Detect duplicates via AJAX
     */
    private function ajax_detect_duplicates(array $request): array {
        try {
            $event_id = intval($request['params']['event_id'] ?? 0);
            
            if (!$event_id) {
                throw new \Exception('معرف الحدث غير صالح');
            }
            
            global $wpdb;
            $table = $wpdb->prefix . 'so_news_events';
            
            $event = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $event_id
            ), ARRAY_A);
            
            if (!$event) {
                throw new \Exception('الحدث غير موجود');
            }
            
            $duplicates = $this->find_duplicates($event, 50);
            
            return [
                'success' => true,
                'data' => [
                    'original' => $event,
                    'duplicates' => $duplicates,
                    'count' => count($duplicates),
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
     * Reclassify batch via AJAX
     */
    private function ajax_reclassify_batch(array $request): array {
        try {
            $batch_size = intval($request['params']['batch_size'] ?? 100);
            $start_id = intval($request['params']['start_id'] ?? 0);
            
            global $wpdb;
            $table = $wpdb->prefix . 'so_news_events';
            
            $query = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
                $start_id,
                $batch_size
            );
            
            $events = $wpdb->get_results($query, ARRAY_A);
            
            if (empty($events)) {
                return [
                    'success' => true,
                    'data' => [
                        'processed' => 0,
                        'last_id' => $start_id,
                        'completed' => true,
                    ],
                ];
            }
            
            $processed = 0;
            $last_id = $start_id;
            
            foreach ($events as $event) {
                $classified = $this->classify_event($event);
                
                $wpdb->update($table, $classified, ['id' => $event['id']]);
                
                $processed++;
                $last_id = $event['id'];
            }
            
            return [
                'success' => true,
                'data' => [
                    'processed' => $processed,
                    'last_id' => $last_id,
                    'completed' => count($events) < $batch_size,
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
     * Main classification function
     * 
     * @param array $event Event data
     * @return array Classified event data
     */
    public function classify_event(array $event): array {
        $text = ($event['title'] ?? '') . ' ' . ($event['content'] ?? '');
        
        // Extract actors
        $actors = $this->extract_actors($event);
        
        // Extract targets
        $targets = $this->extract_targets($event);
        
        // Extract locations
        $locations = $this->extract_locations($event);
        
        // Detect intent
        $intent = $this->detect_intent($text);
        
        // Calculate threat score
        $threat_score = $this->calculate_threat_score($event, $actors, $intent);
        
        // Determine threat level
        $threat_level = $this->determine_threat_level($threat_score);
        
        // Calculate confidence
        $confidence = $this->calculate_confidence($actors, $locations, $intent);
        
        return [
            'primary_actor' => $actors['primary'],
            'secondary_actors' => implode(',', $actors['secondary']),
            'primary_target' => $targets['primary'],
            'location' => $locations['primary'],
            'country' => $locations['country'],
            'intent' => $intent['type'],
            'intent_details' => $intent['details'],
            'threat_score' => $threat_score,
            'threat_level' => $threat_level,
            'confidence_score' => $confidence,
            'classified_at' => date('Y-m-d H:i:s'),
        ];
    }
    
    /**
     * Extract actors from event
     * 
     * @param array $event Event data
     * @return array Actors information
     */
    public function extract_actors(array $event): array {
        $text = ($event['title'] ?? '') . ' ' . ($event['content'] ?? '');
        
        // Get known actors
        $known_actors = $this->get_known_actors();
        
        $found_actors = [];
        
        foreach ($known_actors as $actor) {
            $patterns = $this->build_actor_patterns($actor);
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    $found_actors[] = [
                        'name' => $actor['name'],
                        'type' => $actor['type'],
                        'confidence' => $actor['confidence'],
                        'match_type' => 'exact',
                    ];
                    break;
                }
            }
        }
        
        // Sort by confidence
        usort($found_actors, fn($a, $b) => $b['confidence'] - $a['confidence']);
        
        // Extract unique actor names
        $unique_names = array_unique(array_column($found_actors, 'name'));
        
        $primary = !empty($found_actors) ? $found_actors[0]['name'] : '';
        $secondary = array_slice($unique_names, 1, 5);
        
        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'all' => $found_actors,
        ];
    }
    
    /**
     * Extract targets from event
     * 
     * @param array $event Event data
     * @return array Targets information
     */
    public function extract_targets(array $event): array {
        $text = ($event['title'] ?? '') . ' ' . ($event['content'] ?? '');
        
        $target_keywords = [
            'government' => ['حكومة', 'وزارة', 'رئيس', 'برلمان'],
            'military' => ['جيش', 'عسكري', 'قوات', 'دفاع'],
            'civilian' => ['مدني', 'سكان', 'أهالي', 'مواطنين'],
            'infrastructure' => ['بنية تحتية', 'طاقة', 'كهرباء', 'ماء'],
            'economic' => ['اقتصادي', 'مال', 'بنك', 'شركة'],
        ];
        
        $found_targets = [];
        
        foreach ($target_keywords as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_stripos($text, $keyword) !== false) {
                    $found_targets[] = [
                        'category' => $category,
                        'keyword' => $keyword,
                        'confidence' => 80,
                    ];
                }
            }
        }
        
        usort($found_targets, fn($a, $b) => $b['confidence'] - $a['confidence']);
        
        $primary = !empty($found_targets) ? $found_targets[0]['category'] : '';
        
        return [
            'primary' => $primary,
            'all' => $found_targets,
        ];
    }
    
    /**
     * Extract locations from event
     * 
     * @param array $event Event data
     * @return array Locations information
     */
    public function extract_locations(array $event): array {
        $text = ($event['title'] ?? '') . ' ' . ($event['content'] ?? '');
        
        // Get known locations
        $known_locations = $this->get_known_locations();
        
        $found_locations = [];
        
        foreach ($known_locations as $location) {
            $patterns = $this->build_location_patterns($location);
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    $found_locations[] = [
                        'name' => $location['name'],
                        'country' => $location['country'],
                        'lat' => $location['lat'] ?? null,
                        'lng' => $location['lng'] ?? null,
                        'type' => $location['type'],
                        'confidence' => $location['confidence'],
                    ];
                    break;
                }
            }
        }
        
        usort($found_locations, fn($a, $b) => $b['confidence'] - $a['confidence']);
        
        $primary = !empty($found_locations) ? $found_locations[0]['name'] : '';
        $country = !empty($found_locations) ? $found_locations[0]['country'] : '';
        
        return [
            'primary' => $primary,
            'country' => $country,
            'all' => $found_locations,
        ];
    }
    
    /**
     * Detect intent from text
     * 
     * @param string $text Event text
     * @return array Intent information
     */
    public function detect_intent(string $text): array {
        $intent_patterns = [
            'attack' => [
                'pattern' => '/(هجوم|اعتداء|ضربة|قصف|اغتيال|تفجير|إطلاق نار)/u',
                'severity_boost' => 30,
            ],
            'threat' => [
                'pattern' => '/(تهديد|وعيد|توعد|إنذار)/u',
                'severity_boost' => 20,
            ],
            'movement' => [
                'pattern' => '/(تحرك|انتشار|تمركز|تقدم|انسحاب)/u',
                'severity_boost' => 10,
            ],
            'statement' => [
                'pattern' => '/(تصريح|بيان|إعلان|مؤتمر صحفي)/u',
                'severity_boost' => 5,
            ],
            'meeting' => [
                'pattern' => '/(اجتماع|لقاء|مباحثات|زيارة)/u',
                'severity_boost' => 3,
            ],
        ];
        
        $detected_intents = [];
        
        foreach ($intent_patterns as $intent => $data) {
            if (preg_match($data['pattern'], $text)) {
                $detected_intents[] = [
                    'type' => $intent,
                    'severity_boost' => $data['severity_boost'],
                ];
            }
        }
        
        if (!empty($detected_intents)) {
            usort($detected_intents, fn($a, $b) => $b['severity_boost'] - $a['severity_boost']);
            return [
                'type' => $detected_intents[0]['type'],
                'details' => $detected_intents,
                'max_boost' => $detected_intents[0]['severity_boost'],
            ];
        }
        
        return [
            'type' => 'unknown',
            'details' => [],
            'max_boost' => 0,
        ];
    }
    
    /**
     * Calculate threat score
     * 
     * @param array $event Event data
     * @param array $actors Extracted actors
     * @param array $intent Detected intent
     * @return int Threat score (0-100)
     */
    public function calculate_threat_score(array $event, array $actors, array $intent): int {
        $base_score = 20;
        
        // Actor reputation boost
        if (!empty($actors['primary'])) {
            $known_actors = $this->get_known_actors();
            foreach ($known_actors as $actor) {
                if ($actor['name'] === $actors['primary']) {
                    $base_score += $actor['threat_level'] ?? 20;
                    break;
                }
            }
        }
        
        // Intent severity boost
        $base_score += $intent['max_boost'] ?? 0;
        
        // Keyword-based boosts
        $text = ($event['title'] ?? '') . ' ' . ($event['content'] ?? '');
        
        $danger_keywords = [
            '/قتلى/u' => 25,
            '/جرحى/u' => 15,
            '/دمار/u' => 20,
            '/أسرى/u' => 18,
            '/تصعيد/u' => 15,
            '/حرب/u' => 25,
            '/غزو/u' => 30,
            '/حصار/u' => 20,
        ];
        
        foreach ($danger_keywords as $pattern => $boost) {
            if (preg_match($pattern, $text)) {
                $base_score += $boost;
            }
        }
        
        // Cap at 100
        return min(100, max(0, $base_score));
    }
    
    /**
     * Determine threat level from score
     * 
     * @param int $score Threat score
     * @return string Threat level
     */
    public function determine_threat_level(int $score): string {
        if ($score >= self::THREAT_THRESHOLDS['critical']) {
            return 'critical';
        } elseif ($score >= self::THREAT_THRESHOLDS['high']) {
            return 'high';
        } elseif ($score >= self::THREAT_THRESHOLDS['medium']) {
            return 'medium';
        } else {
            return 'low';
        }
    }
    
    /**
     * Calculate confidence score
     * 
     * @param array $actors Extracted actors
     * @param array $locations Extracted locations
     * @param array $intent Detected intent
     * @return int Confidence score (0-100)
     */
    public function calculate_confidence(array $actors, array $locations, array $intent): int {
        $confidence = 50;
        
        // Actor confidence
        if (!empty($actors['primary'])) {
            $confidence += 20;
            if (!empty($actors['all'][0]['confidence'])) {
                $confidence += min(15, $actors['all'][0]['confidence'] / 7);
            }
        }
        
        // Location confidence
        if (!empty($locations['primary'])) {
            $confidence += 15;
            if (!empty($locations['all'][0]['confidence'])) {
                $confidence += min(10, $locations['all'][0]['confidence'] / 10);
            }
        }
        
        // Intent confidence
        if ($intent['type'] !== 'unknown') {
            $confidence += 10;
        }
        
        return min(100, $confidence);
    }
    
    /**
     * Find duplicate events
     * 
     * @param array $event Event to check
     * @param int $time_window Time window in minutes
     * @return array Duplicate events
     */
    public function find_duplicates(array $event, int $time_window = 60): array {
        global $wpdb;
        $table = $wpdb->prefix . 'so_news_events';
        
        $title = $event['title'] ?? '';
        $timestamp = $event['event_timestamp'] ?? date('Y-m-d H:i:s');
        
        // Calculate time range
        $start_time = date('Y-m-d H:i:s', strtotime($timestamp) - ($time_window * 60));
        $end_time = date('Y-m-d H:i:s', strtotime($timestamp) + ($time_window * 60));
        
        // Normalize title for comparison
        $normalized_title = $this->normalize_for_comparison($title);
        
        // Fetch potential duplicates
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE event_timestamp BETWEEN %s AND %s
             AND id != %d
             ORDER BY event_timestamp DESC",
            $start_time,
            $end_time,
            $event['id'] ?? 0
        ), ARRAY_A);
        
        $duplicates = [];
        
        foreach ($candidates as $candidate) {
            $similarity = $this->calculate_similarity($normalized_title, $candidate);
            
            if ($similarity >= 0.85) {
                $duplicates[] = [
                    'event' => $candidate,
                    'similarity' => $similarity,
                    'reason' => 'semantic_match',
                ];
            }
        }
        
        usort($duplicates, fn($a, $b) => $b['similarity'] - $a['similarity']);
        
        return array_slice($duplicates, 0, 10);
    }
    
    /**
     * Calculate similarity between events
     * 
     * @param string $normalized_title Normalized title
     * @param array $candidate Candidate event
     * @return float Similarity score (0-1)
     */
    private function calculate_similarity(string $normalized_title, array $candidate): float {
        $candidate_title = $this->normalize_for_comparison($candidate['title'] ?? '');
        
        // Levenshtein distance
        $lev_distance = levenshtein($normalized_title, $candidate_title);
        $max_len = max(strlen($normalized_title), strlen($candidate_title));
        
        if ($max_len === 0) {
            return 0;
        }
        
        $lev_similarity = 1 - ($lev_distance / $max_len);
        
        // Check for common keywords
        $title_words = preg_split('/\s+/', $normalized_title);
        $candidate_words = preg_split('/\s+/', $candidate_title);
        
        $common_words = array_intersect($title_words, $candidate_words);
        $word_overlap = count($common_words) / max(count($title_words), count($candidate_words));
        
        // Weighted average
        return ($lev_similarity * 0.6) + ($word_overlap * 0.4);
    }
    
    /**
     * Normalize text for comparison
     * 
     * @param string $text Text to normalize
     * @return string Normalized text
     */
    private function normalize_for_comparison(string $text): string {
        // Remove diacritics
        $text = preg_replace('/[\x{064B}-\x{0652}]/u', '', $text);
        
        // Normalize alef forms
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        
        // Normalize ha forms
        $text = str_replace('ة', 'ه', $text);
        
        // Remove punctuation
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        
        // Lowercase (for Arabic, this doesn't change much but good practice)
        $text = mb_strtolower($text, 'UTF-8');
        
        // Trim extra spaces
        $text = trim(preg_replace('/\s+/', ' ', $text));
        
        return $text;
    }
    
    /**
     * Build regex patterns for actor matching
     * 
     * @param array $actor Actor data
     * @return array Regex patterns
     */
    private function build_actor_patterns(array $actor): array {
        $patterns = [];
        $name = $actor['name'];
        
        // Exact match
        $patterns[] = '/' . preg_quote($name, '/') . '/u';
        
        // With aliases
        if (!empty($actor['aliases']) && is_array($actor['aliases'])) {
            foreach ($actor['aliases'] as $alias) {
                $patterns[] = '/' . preg_quote($alias, '/') . '/u';
            }
        }
        
        return $patterns;
    }
    
    /**
     * Build regex patterns for location matching
     * 
     * @param array $location Location data
     * @return array Regex patterns
     */
    private function build_location_patterns(array $location): array {
        $patterns = [];
        $name = $location['name'];
        
        // Exact match
        $patterns[] = '/' . preg_quote($name, '/') . '/u';
        
        // With variations
        $variations = [
            $name,
            'مدينة ' . $name,
            'محافظة ' . $name,
            'منطقة ' . $name,
        ];
        
        foreach ($variations as $variation) {
            $patterns[] = '/' . preg_quote($variation, '/') . '/u';
        }
        
        return $patterns;
    }
    
    /**
     * Get known actors from cache or options
     * 
     * @return array Known actors
     */
    private function get_known_actors(): array {
        $actors = get_transient(self::ACTORS_CACHE_KEY);
        
        if ($actors === false) {
            $actors = get_option('osint_known_actors', $this->get_default_actors());
            set_transient(self::ACTORS_CACHE_KEY, $actors, DAY_IN_SECONDS);
        }
        
        return $actors;
    }
    
    /**
     * Get known locations from cache or options
     * 
     * @return array Known locations
     */
    private function get_known_locations(): array {
        $locations = get_transient(self::LOCATIONS_CACHE_KEY);
        
        if ($locations === false) {
            $locations = get_option('osint_known_locations', $this->get_default_locations());
            set_transient(self::LOCATIONS_CACHE_KEY, $locations, DAY_IN_SECONDS);
        }
        
        return $locations;
    }
    
    /**
     * Get default actors (fallback)
     * 
     * @return array Default actors
     */
    private function get_default_actors(): array {
        return [
            [
                'name' => 'حماس',
                'type' => 'faction',
                'threat_level' => 70,
                'confidence' => 90,
                'aliases' => ['حركة حماس', 'حركة المقاومة الإسلامية'],
            ],
            [
                'name' => 'فتح',
                'type' => 'faction',
                'threat_level' => 40,
                'confidence' => 90,
                'aliases' => ['حركة فتح', 'حركة التحرير الوطني الفلسطيني'],
            ],
            [
                'name' => 'إسرائيل',
                'type' => 'state',
                'threat_level' => 80,
                'confidence' => 95,
                'aliases' => ['الكيان الصهيوني', 'عدو'],
            ],
        ];
    }
    
    /**
     * Get default locations (fallback)
     * 
     * @return array Default locations
     */
    private function get_default_locations(): array {
        return [
            [
                'name' => 'غزة',
                'country' => 'فلسطين',
                'lat' => 31.5017,
                'lng' => 34.4668,
                'type' => 'city',
                'confidence' => 95,
            ],
            [
                'name' => 'الضفة الغربية',
                'country' => 'فلسطين',
                'lat' => 31.9522,
                'lng' => 35.2332,
                'type' => 'region',
                'confidence' => 90,
            ],
            [
                'name' => 'القدس',
                'country' => 'فلسطين',
                'lat' => 31.7683,
                'lng' => 35.2137,
                'type' => 'city',
                'confidence' => 95,
            ],
        ];
    }
    
    /**
     * Get module info
     */
    public function get_info(): array {
        return [
            'name' => self::NAME,
            'label' => 'محرك الاستخبارات',
            'version' => '1.0.0',
            'description' => 'نظام التصنيف والاستخراج الذكي للأحداث',
            'features' => [
                'استخراج الفاعلين',
                'كشف الأهداف',
                'تحديد المواقع',
                'تحليل النوايا',
                'حساب مستوى التهديد',
                'كشف التكرار الدلالي',
                'إعادة التصنيف الجماعي',
            ],
        ];
    }
}
