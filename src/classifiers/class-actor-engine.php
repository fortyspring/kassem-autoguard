<?php
/**
 * Actor Detection Engine
 * 
 * Analyzes text to identify primary and secondary actors
 * Uses pattern matching, context memory, and AI governance
 * 
 * @package OSINT_Pro/Classifiers
 */

namespace SO\Classifiers;

use SO\Utils\TextCleaner;

class ActorEngine {
    
    /**
     * Analyze text to detect actors
     * 
     * @param string $text Cleaned text
     * @return array Actor analysis results
     */
    public static function analyze($text) {
        // Check context memory first
        $memory_hit = self::checkContextMemory($text);
        if (!empty($memory_hit)) {
            return $memory_hit;
        }
        
        // Detect event mode
        $mode = self::detectEventMode($text);
        
        // Extract target from text
        $target = self::detectTargetFromText($text);
        
        // Handle defensive alert mode
        if ($mode === 'defensive_alert') {
            return self::handleDefensiveAlert($text, $target);
        }
        
        // Handle political/non-military mode
        if ($mode === 'political' || self::isNonMilitaryContext($text)) {
            return self::handlePoliticalContext($text);
        }
        
        // Standard kinetic analysis
        return self::analyzeKineticContext($text, $target);
    }
    
    /**
     * Check context memory for known patterns
     * 
     * @param string $text Cleaned text
     * @return array|null Memory hit or null
     */
    private static function checkContextMemory($text) {
        if (function_exists('sod_context_memory_infer')) {
            $result = sod_context_memory_infer($text);
            if (!empty($result)) {
                return $result;
            }
        }
        return null;
    }
    
    /**
     * Detect event mode from text
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
     * Detect target from text
     * 
     * @param string $text Cleaned text
     * @return string Target or empty
     */
    private static function detectTargetFromText($text) {
        if (function_exists('sod_detect_target_from_text')) {
            $banks = function_exists('sod_get_all_banks') ? sod_get_all_banks() : [];
            return sod_detect_target_from_text($text, $banks['targets'] ?? []);
        }
        return '';
    }
    
    /**
     * Handle defensive alert mode
     * 
     * @param string $text Cleaned text
     * @param string $target Detected target
     * @return array Analysis result
     */
    private static function handleDefensiveAlert($text, $target) {
        // Try to extract target from context if missing
        if (empty($target)) {
            if (preg_match('/(?:في|خط(?: ?المواجهة)?[:：]?)\s*([^\.\n]{2,80})/ui', $text, $m)) {
                $target = trim((string) $m[1]);
            }
        }
        
        // Infer actor for defensive alerts
        $alert_actor = '';
        if (function_exists('sod_infer_actor_strict')) {
            $alert_actor = sod_infer_actor_strict($text, '', 'الأراضي المحتلة (إسرائيل)', '', '');
        }
        
        // Default actor if not detected
        if (empty($alert_actor) || 
            self::isUnknownActor($alert_actor) || 
            self::isMediaSourceActor($alert_actor) || 
            preg_match('/(منظومة الإنذار|الجبهة الداخلية)/ui', $alert_actor)) {
            $alert_actor = 'محور المقاومة (استنتاج)';
        }
        
        return [
            'primary_actor' => $alert_actor,
            'secondary_actor' => 'الأراضي المحتلة (إسرائيل)',
            'target' => !empty($target) ? $target : 'منطقة إنذار',
            'confidence' => 91,
            'reason' => 'defensive-alert-inferred',
            'actor_matches' => [],
        ];
    }
    
    /**
     * Handle political/non-military context
     * 
     * @param string $text Cleaned text
     * @return array Analysis result
     */
    private static function handlePoliticalContext($text) {
        $named_actor = '';
        if (function_exists('sod_extract_named_nonmilitary_actor')) {
            $named_actor = sod_extract_named_nonmilitary_actor($text);
        }
        
        return [
            'primary_actor' => !empty($named_actor) ? $named_actor : 'فاعل غير محسوم',
            'secondary_actor' => '',
            'target' => '',
            'confidence' => !empty($named_actor) ? 88 : 96,
            'reason' => !empty($named_actor) ? 'non-military-named-actor' : 'non-military-context',
            'actor_matches' => [],
        ];
    }
    
    /**
     * Analyze kinetic context with full actor matching
     * 
     * @param string $text Cleaned text
     * @param string $target Detected target
     * @return array Analysis result
     */
    private static function analyzeKineticContext($text, $target) {
        // Get actor bank and match
        $actor_matches = [];
        if (function_exists('sod_match_bank') && function_exists('sod_get_all_banks')) {
            $banks = sod_get_all_banks();
            $actors_bank = [];
            if (function_exists('sod_prepare_actor_bank')) {
                $actors_bank = sod_prepare_actor_bank($banks['actors'] ?? []);
            }
            $actor_matches = sod_match_bank($text, $actors_bank, ['bank' => 'actors']);
        }
        
        // Apply special rules for specific patterns
        $actor_matches = self::applySpecialActorRules($text, $actor_matches);
        
        // Extract top actors
        $actors = array_slice(array_keys($actor_matches), 0, 3);
        $primary = $actors[0] ?? 'فاعل غير محسوم';
        $secondary = $actors[1] ?? '';
        
        // Fallback inference for unknown primary
        if ($primary === 'فاعل غير محسوم') {
            $primary = self::inferPrimaryFromPatterns($text);
        }
        
        // Filter out media sources as actors
        if (self::isMediaSourceActor($primary)) {
            $primary = 'فاعل غير محسوم';
            $secondary = '';
        }
        
        // Calculate confidence
        $confidence = self::calculateConfidence($primary, $actor_matches, $target, $secondary);
        
        // Apply AI governor
        $result = [
            'primary_actor' => $primary,
            'secondary_actor' => $secondary,
            'target' => $target,
            'confidence' => $confidence,
            'reason' => 'kinetic-or-operational-context',
            'actor_matches' => $actor_matches,
        ];
        
        if (function_exists('sod_governor_ai')) {
            $result = sod_governor_ai($result, $text);
        }
        
        return $result;
    }
    
    /**
     * Apply special actor detection rules
     * 
     * @param string $text Cleaned text
     * @param array $actor_matches Current matches
     * @return array Updated matches
     */
    private static function applySpecialActorRules($text, $actor_matches) {
        // Hezbollah patterns
        if (preg_match('/(المقاومة الإسلامية|حزب الله|مجاهدونا|استهدفنا|استهدف مجاهدونا|بيان صادر عن المقاومة الإسلامية|استهدفنا دبابة|استهدفنا تجمّع|ثكنة|مستوطنة|جنود وآليات جيش العدو)/ui', $text)) {
            $actor_matches = ['المقاومة الإسلامية (حزب الله)' => ['score' => 120, 'keyword' => 'المقاومة الإسلامية', 'weight' => 1.6]] + $actor_matches;
        }
        
        // IDF patterns - شامل الكلمات المفردة والعبارات الكاملة
        if (preg_match('/(الجيش الإسرائيلي|جيش الاحتلال|قوات الاحتلال|طيران الاحتلال|غارة إسرائيلية|قصف إسرائيلي|اعتداء إسرائيلي|توغل قوات الاحتلال|مستوطنون يقتحمون|الطيران الحربي المعادي|طائرات الاحتلال|طيران العدو|العدو شن غارة|قصف طيران الاحتلال|رشاشات إسرائيلية|رشقات إسرائيلية|نيران إسرائيلية|قذائف إسرائيلية|طلقات إسرائيلية|إسرائيلية|اسرائيلية)/ui', $text)) {
            $actor_matches = ['جيش العدو الإسرائيلي' => ['score' => 120, 'keyword' => 'الجيش الإسرائيلي', 'weight' => 1.6]] + $actor_matches;
        }
        
        // Hamas patterns
        if (preg_match('/(^|\s)(حماس|حركة حماس|كتائب القسام)(\s|:|$)/ui', $text)) {
            $actor_matches = ['كتائب القسام (حماس)' => ['score' => 116, 'keyword' => 'حماس', 'weight' => 1.5]] + $actor_matches;
        }
        
        // Islamic Jihad patterns
        if (preg_match('/(^|\s)(سرايا القدس|الجهاد الإسلامي)(\s|:|$)/ui', $text)) {
            $actor_matches = ['سرايا القدس (الجهاد الإسلامي)' => ['score' => 114, 'keyword' => 'سرايا القدس', 'weight' => 1.5]] + $actor_matches;
        }
        
        return $actor_matches;
    }
    
    /**
     * Infer primary actor from text patterns
     * 
     * @param string $text Cleaned text
     * @return string Inferred actor
     */
    private static function inferPrimaryFromPatterns($text) {
        // IDF patterns - للاستدلال عندما لا يوجد فاعل محدد
        if (preg_match('/(استهداف دبابة إسرائيلية|استهداف موقع|استهدفنا تجمعا|استهدفنا تجمّعا|ثكنة يعرا|مستوطنة نهاريا|جنود وآليات جيش العدو|رشاشات إسرائيلية|رشقات إسرائيلية|نيران إسرائيلية|قذائف إسرائيلية|طلقات إسرائيلية|غارة إسرائيلية|طيران الاحتلال|الطيران الحربي المعادي|طائرات الاحتلال|قوات الاحتلال|مستوطنون يقتحمون)/ui', $text)) {
            return 'جيش العدو الإسرائيلي';
        }
        return 'فاعل غير محسوم';
    }
    
    /**
     * Calculate confidence score
     * 
     * @param string $primary Primary actor
     * @param array $actor_matches Actor matches
     * @param string $target Target
     * @param string $secondary Secondary actor
     * @return int Confidence score (15-96)
     */
    private static function calculateConfidence($primary, $actor_matches, $target, $secondary) {
        $confidence = 42;
        
        if (!empty($actor_matches) && $primary !== 'فاعل غير محسوم') {
            $top_score = (float) ($actor_matches[$primary]['score'] ?? 0);
            $confidence += min(35, (int) round($top_score));
        }
        
        if (!empty($target)) $confidence += 10;
        if (!empty($secondary)) $confidence += 4;
        if ($primary === 'فاعل غير محسوم') $confidence = min($confidence, 45);
        
        return max(15, min(96, $confidence));
    }
    
    /**
     * Check if actor label is unknown
     * 
     * @param string $actor Actor label
     * @return bool Is unknown
     */
    private static function isUnknownActor($actor) {
        if (function_exists('sod_is_unknown_actor_label')) {
            return sod_is_unknown_actor_label($actor);
        }
        return in_array($actor, ['فاعل غير محسوم', 'غير معروف', '']);
    }
    
    /**
     * Check if actor is a media source
     * 
     * @param string $actor Actor label
     * @return bool Is media source
     */
    private static function isMediaSourceActor($actor) {
        if (function_exists('sod_is_media_source_actor')) {
            return sod_is_media_source_actor($actor);
        }
        return preg_match('/(إعلام|قناة|رويترز|العربية|الميادين|الجزيرة|صحيفة|تغطية|محلل|كاتب)/ui', $actor);
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
        
        return preg_match('/(صورة للوفد|الوفد الإيراني|إعلام إيراني|رويترز عن مصدر|مصدر دبلوماسي|الجزيرة|العربية|الميادين|تغطية خاصة|كاتب|محلل سياسي|نتبلوكس|وزارة الخدمة المدنية|المؤتمر الشعبي|النائب فضل الله|تاكر كارلسون|ولايتي|البيت الأبيض|البيت الابيض|مستشار المرشد)/ui', $text);
    }
}
