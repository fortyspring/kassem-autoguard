<?php
/**
 * Text Cleaning and Normalization Utilities
 * 
 * Centralized text processing functions to eliminate duplication
 * 
 * @package OSINT_Pro/Utils
 */

namespace SO\Utils;

class TextCleaner {
    
    /**
     * Clean text from HTML, URLs, emojis, promotional content
     * 
     * @param string $text Raw text input
     * @return string Cleaned text
     */
    public static function clean($text) {
        if (empty($text)) return '';
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Strip all tags
        $text = wp_strip_all_tags($text);
        
        // Remove URLs
        $text = preg_replace("/\b(?:https?|ftp):\/\/[a-z0-9\-+&@#\/%?=~_|!:,.;]*[a-z0-9\-+&@#\/%=~_|]/i", '', $text);
        
        // Remove timestamps
        $text = preg_replace("/(?:[0-9٠-٩]{1,2}:[0-9٠-٩]{2})\s*(?:ص|م|صباحا|مساء|am|pm)?/ui", '', $text);
        
        // Remove promotional content
        $promotional = [
            "/قناة\s+.*?على\s+واتساب/ui",
            "/للاشتراك\s+في\s+قناة/ui",
            "/عبر\s+الرابط/ui",
            "/telegram\.me/ui",
            "/t\.me/ui"
        ];
        $text = preg_replace($promotional, '', $text);
        
        // Remove emojis
        $text = preg_replace("/[\x{1F300}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u", '', $text);
        
        // Remove common prefixes/symbols
        $text = str_replace(
            ["'s","عاجل","عاجل:","عاجل |","🚨","📲","🔴","🟢","💥","⚔️","📌","📊","🎯","▪️"],
            '',
            $text
        );
        
        // Extract Arabic lines only
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $arabic_lines = [];
        
        foreach ($lines as $line) {
            if (preg_match("/[\p{Arabic}]/u", $line)) {
                $line = preg_replace("/\b[a-zA-Z]{2,}\b/", '', $line);
                $arabic_lines[] = trim($line);
            }
        }
        
        // Fallback if no Arabic lines found
        if (empty($arabic_lines)) {
            return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text));
        }
        
        // Final cleanup
        return preg_replace(
            ["/\(\s*\)/","/\[\s*\]/","/\s+/","/[!]{3,}/"],
            ['','', ' ','!!!'],
            trim(implode(' ', $arabic_lines))
        );
    }
    
    /**
     * Normalize title for deduplication
     * 
     * @param string $title Raw title
     * @return string Normalized title
     */
    public static function normalizeTitleForDedupe($title) {
        $title = self::clean((string)$title);
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove hashtags
        $title = preg_replace('/#[^\s]+/u', ' ', $title);
        
        // Remove news prefixes and agency names
        $title = preg_replace(
            '/\b(?:عاجل|خبر عاجل|متداول|أنباء أولية|أولية|حصري|متابعة|تحديث|وسائل إعلام|إعلام العدو|وكالة|رويترز|أ\s*ف\s*ب|القناة\s*\d+|صحيفة|مراسل|نقلاً عن|قال|صرح|أعلن|أكد|أفاد)\b/iu',
            ' ',
            $title
        );
        
        // Remove punctuation and extra spaces
        $title = preg_replace(['/[^\p{Arabic}\p{Latin}\s]/u', '/\s+/'], ['', ' '], $title);
        
        return trim(mb_strtolower($title, 'UTF-8'));
    }
    
    /**
     * Extract pure Arabic text from mixed content
     * 
     * @param string $text Mixed language text
     * @return string Arabic-only text
     */
    public static function extractArabic($text) {
        if (empty($text)) return '';
        
        // Remove Latin characters (words with 2+ chars)
        $text = preg_replace("/\b[a-zA-Z]{2,}\b/", '', $text);
        
        // Remove remaining non-Arabic symbols
        $text = preg_replace('/[^\p{Arabic}\s\p{Punctuation}]/u', '', $text);
        
        return trim(preg_replace('/\s+/', ' ', $text));
    }
    
    /**
     * Sanitize input for database storage
     * 
     * @param string $input Raw input
     * @return string Sanitized input
     */
    public static function sanitizeForDB($input) {
        global $wpdb;
        return $wpdb->_real_escape(self::clean($input));
    }
}
