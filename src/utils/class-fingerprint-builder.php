<?php
/**
 * Fingerprint and Signature Generation
 * 
 * Generates unique fingerprints for duplicate detection
 * 
 * @package OSINT_Pro/Utils
 */

namespace SO\Utils;

class FingerprintBuilder {
    
    /**
     * Arabic stop words that don't carry semantic meaning
     */
    private static $stopWords = [
        'في','من','إلى','على','عن','مع','هذا','هذه','ذلك','التي','الذي','كان','يكون',
        'قد','أن','أو','لم','لن','هو','هي','هم','بعد','قبل','حتى','عند','الذين',
        'وفي','وعلى','وإلى','وعن','ويؤكد','ويقول','وأضاف','وأوضح','كما','بين','لكن'
    ];
    
    /**
     * Geographic terms to normalize
     */
    private static $geoTerms = [
        'لبنان','فلسطين','الأراضي المحتلة','إسرائيل','اسرائيل','سوريا','إيران',
        'حيفا','نهاريا','كريات شمونة','تل أبيب','تل ابيب','الجنوب','الجليل'
    ];
    
    /**
     * Build title fingerprint for deduplication
     * 
     * @param string $title Event title
     * @return string MD5 fingerprint or empty string
     */
    public static function buildTitleFingerprint($title) {
        if (empty($title)) return '';
        
        // Normalize title
        $normalized = TextCleaner::normalizeTitleForDedupe((string)$title);
        
        // Split into words
        $words = array_filter(explode(' ', $normalized));
        
        // Remove stop words and short words
        $words = array_values(array_filter($words, function($w) {
            return !in_array($w, self::$stopWords, true) && mb_strlen($w) > 2;
        }));
        
        // Need at least 2 significant words
        if (count($words) < 2) return '';
        
        // Sort words for order-independent fingerprinting
        sort($words);
        
        // Take top 8 unique words
        $significant = array_slice($words, 0, 8);
        
        return md5(implode('|', $significant));
    }
    
    /**
     * Calculate similarity percentage between two titles
     * 
     * @param string $a First title
     * @param string $b Second title
     * @return float Similarity percentage (0-100)
     */
    public static function titleSimilarityPercent($a, $b) {
        $a = TextCleaner::normalizeTitleForDedupe((string)$a);
        $b = TextCleaner::normalizeTitleForDedupe((string)$b);
        
        if ($a === '' || $b === '') return 0.0;
        
        similar_text($a, $b, $percent);
        return (float) $percent;
    }
    
    /**
     * Build duplicate candidate signature
     * 
     * @param string $title Event title
     * @param string $actor Actor/attributed party
     * @param string $region Geographic region
     * @param string $intel Intelligence type
     * @return string MD5 signature
     */
    public static function buildDuplicateSignature($title, $actor = '', $region = '', $intel = '') {
        // Normalize title
        $normalized = TextCleaner::normalizeTitleForDedupe((string)$title);
        
        // Remove geographic terms for better matching
        $normalized = preg_replace(
            '/\b(?:' . implode('|', self::$geoTerms) . ')\b/iu',
            ' ',
            $normalized
        );
        
        // Normalize whitespace
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized));
        
        // Build signature with all components
        return md5(
            $normalized . '|' .
            trim((string)$actor) . '|' .
            trim((string)$region) . '|' .
            trim((string)$intel)
        );
    }
    
    /**
     * Build composite key for duplicate detection
     * 
     * @param string $title Event title
     * @param string $actor Actor/attributed party
     * @param string $region Geographic region
     * @param string $intel Intelligence type
     * @param int $eventTimestamp Unix timestamp
     * @return string Composite key
     */
    public static function buildCompositeKey($title, $actor, $region, $intel, $eventTimestamp) {
        $tfp = self::buildTitleFingerprint($title);
        $sig = self::buildDuplicateSignature($title, $actor, $region, $intel);
        
        // Time bucket (4-hour windows)
        $bucket = floor(((int)$eventTimestamp) / 14400);
        
        return ($tfp ?: $sig) . '|' . $bucket;
    }
}
