<?php
/**
 * News Event Classification Pipeline
 * 
 * Main pipeline for classifying and analyzing news events
 * Coordinates multiple classification engines and AI layers
 * 
 * @package OSINT_Pro/Pipeline
 */

namespace SO\Pipeline;

use SO\Utils\TextCleaner;
use SO\Utils\FingerprintBuilder;
use SO\Classifiers\ActorEngine;

class EventClassifier {
    
    /**
     * Classify a news event using all available engines
     * 
     * @param array $event Event data with title and content
     * @return array Classification results
     */
    public static function classifyEvent($event) {
        // Clean input text
        $text = TextCleaner::clean(
            ($event['title'] ?? '') . ' ' . ($event['content'] ?? '')
        );
        
        // Extract context words for better matching
        $context_words = self::extractContextWords($text);
        
        // Detect content bucket and mode
        $bucket = self::detectContentBucket($text);
        $mode = self::detectEventMode($text);
        
        // Run actor detection engine
        $actor_ai = ActorEngine::analyze($text);
        
        // Resolve additional fields
        $target_v2 = !empty($actor_ai['target']) ? $actor_ai['target'] : self::resolveField($text, 'targets');
        $context_actor = in_array($bucket, ['statement', 'report']) ? '' : self::resolveField($text, 'contexts');
        $intent = self::resolveField($text, 'intents');
        $weapon_v2 = self::resolveField($text, 'weapons');
        
        // Apply mode-specific rules
        if ($mode === 'defensive_alert') {
            $context_actor = 'إنذار دفاعي';
            $intent = 'دفاع';
            if (empty($weapon_v2)) $weapon_v2 = 'إنذار / اعتراض';
        } elseif ($mode === 'kinetic') {
            if (empty($intent)) $intent = 'هجوم';
        }
        
        // Handle statement/report contexts
        if (in_array($bucket, ['statement', 'report']) || self::isNonMilitaryContext($text)) {
            $named_actor = self::extractNamedNonMilitaryActor($text);
            if ($mode !== 'kinetic') {
                $actor_ai['primary_actor'] = !empty($named_actor) ? $named_actor : 'فاعل غير محسوم';
                $actor_ai['secondary_actor'] = '';
                $actor_ai['target'] = '';
                $actor_ai['confidence'] = !empty($named_actor) 
                    ? max((int)($actor_ai['confidence'] ?? 20), 70) 
                    : min((int)($actor_ai['confidence'] ?? 20), 35);
                $actor_ai['reason'] = !empty($named_actor) 
                    ? 'statement-named-actor' 
                    : 'statement-or-report-context';
            }
        }
        
        // Resolve region
        $resolved_region = (string) self::resolveField($text, 'regions');
        
        // Run early warning AI
        $early_warning = EarlyWarningService::analyze($text, [
            'actor' => (string) ($actor_ai['primary_actor'] ?? ''),
            'region' => $resolved_region,
            'intel_type' => (string) $bucket,
        ]);
        
        // Run prediction layer
        $prediction = PredictionLayer::analyze($text, [
            'actor' => (string) ($actor_ai['primary_actor'] ?? ''),
            'region' => $resolved_region,
            'intel_type' => (string) $bucket,
            'target' => (string) $target_v2,
            'weapon' => (string) $weapon_v2,
            'early_warning' => $early_warning,
        ]);
        
        return [
            'actor_v2' => $actor_ai['primary_actor'],
            'target_v2' => $target_v2,
            'context_actor' => !empty($actor_ai['secondary_actor']) ? $actor_ai['secondary_actor'] : $context_actor,
            'intent' => $intent,
            'weapon_v2' => $weapon_v2,
            '_ai_v2' => [
                'confidence' => (int) ($actor_ai['confidence'] ?? 20),
                'reason' => (string) ($actor_ai['reason'] ?? ''),
                'bucket' => $bucket,
            ],
            '_early_warning' => $early_warning,
            '_prediction' => $prediction,
            '_match_details' => [
                'actor_matches' => !empty($actor_ai['actor_matches']) 
                    ? $actor_ai['actor_matches'] 
                    : self::matchBank($text, 'actors', $context_words),
                'weapon_matches' => self::matchBank($text, 'weapons', $context_words),
            ]
        ];
    }
    
    /**
     * Extract context words (countries/regions) from text
     * 
     * @param string $text Cleaned text
     * @return array Context words
     */
    private static function extractContextWords($text) {
        $context_words = [];
        if (preg_match('/(لبنان|فلسطين|سوريا|العراق|اليمن|إيران|ايران|إسرائيل|اسرائيل)/u', $text, $m)) {
            $context_words[] = $m[1];
        }
        return $context_words;
    }
    
    /**
     * Detect content bucket type
     * 
     * @param string $text Cleaned text
     * @return string Bucket type
     */
    private static function detectContentBucket($text) {
        // Delegate to existing function if available
        if (function_exists('so_detect_content_bucket')) {
            return so_detect_content_bucket($text);
        }
        
        // Fallback detection
        if (preg_match('/(بيان|تصريح|مؤتمر صحفي|قال|أكد|صرح)/ui', $text)) {
            return 'statement';
        }
        if (preg_match('/(تقرير|تحليل|ملخص|رويترز|وكالة)/ui', $text)) {
            return 'report';
        }
        if (preg_match('/(غارة|قصف|استهداف|هجوم|اشتباك)/ui', $text)) {
            return 'kinetic';
        }
        if (preg_match('/(انذار|إنذار|صفارات|الجبهة الداخلية)/ui', $text)) {
            return 'defensive_alert';
        }
        
        return 'general';
    }
    
    /**
     * Detect event mode
     * 
     * @param string $text Cleaned text
     * @return string Event mode
     */
    private static function detectEventMode($text) {
        if (function_exists('sod_detect_event_mode')) {
            return sod_detect_event_mode($text);
        }
        
        if (preg_match('/(انذار|إنذار|صفارات|الجبهة الداخلية|اعتراض|رصد إطلاق)/ui', $text)) {
            return 'defensive_alert';
        }
        if (preg_match('/(غارة|قصف|استهداف|هجوم|اشتباك|توغل|إطلاق صواريخ)/ui', $text)) {
            return 'kinetic';
        }
        if (preg_match('/(مفاوضات|محادثات|تصريح|اجتماع|لقاء|وفد)/ui', $text)) {
            return 'political';
        }
        
        return 'general';
    }
    
    /**
     * Check if text is non-military context
     * 
     * @param string $text Cleaned text
     * @return bool Is non-military
     */
    private static function isNonMilitaryContext($text) {
        if (function_exists('sod_is_non_military_context')) {
            return sod_is_non_military_context($text);
        }
        
        $patterns = [
            'صورة للوفد', 'الوفد الإيراني', 'إعلام إيراني', 'رويترز عن مصدر',
            'مصدر دبلوماسي', 'الجزيرة', 'العربية', 'الميادين', 'تغطية خاصة',
            'كاتب', 'محلل سياسي', 'نتبلوكس', 'وزارة الخدمة المدنية',
            'المؤتمر الشعبي', 'النائب فضل الله', 'تاكر كارلسون', 'ولايتي',
            'البيت الأبيض', 'البيت الابيض', 'مستشار المرشد'
        ];
        
        foreach ($patterns as $pattern) {
            if (strpos($text, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract named non-military actor from text
     * 
     * @param string $text Cleaned text
     * @return string Actor name or empty
     */
    private static function extractNamedNonMilitaryActor($text) {
        if (function_exists('sod_extract_named_nonmilitary_actor')) {
            return sod_extract_named_nonmilitary_actor($text);
        }
        
        // Fallback: look for explicit speaker patterns
        if (preg_match('/(?:قال|صرح|أكد|أعلن)[^.\n]{0,100}?(ال(?:وزير|رئيس|نائب|متحدث|أمين|قائد|شيخ|السيد)\s+[^\n.،]+)/ui', $text, $m)) {
            return trim($m[1]);
        }
        
        return '';
    }
    
    /**
     * Resolve field from text using bank matching
     * 
     * @param string $text Cleaned text
     * @param string $fieldType Field type (targets, weapons, regions, etc.)
     * @return string Resolved value
     */
    private static function resolveField($text, $fieldType) {
        if (function_exists('sod_resolve_field')) {
            return sod_resolve_field($text, $fieldType);
        }
        
        // Fallback to bank matching
        $matches = self::matchBank($text, $fieldType, []);
        if (!empty($matches)) {
            return (string) array_keys($matches)[0];
        }
        
        return '';
    }
    
    /**
     * Match text against entity bank
     * 
     * @param string $text Cleaned text
     * @param string $bankType Bank type (actors, weapons, targets, etc.)
     * @param array $contextWords Context words for boosting
     * @return array Match results
     */
    private static function matchBank($text, $bankType, $contextWords = []) {
        if (function_exists('sod_match_bank')) {
            $banks = function_exists('sod_get_all_banks') ? sod_get_all_banks() : [];
            $bank = $banks[$bankType] ?? [];
            return sod_match_bank($text, $bank, ['context_words' => $contextWords, 'bank' => $bankType]);
        }
        
        return [];
    }
}
