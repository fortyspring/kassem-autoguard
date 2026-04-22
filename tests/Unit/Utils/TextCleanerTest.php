<?php
/**
 * Unit Tests for TextCleaner Utility Class
 * 
 * @package OSINT_Pro\Tests\Unit\Utils
 */

namespace Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use SO\Utils\TextCleaner;

class TextCleanerTest extends TestCase {
    
    /**
     * Test that HTML tags are removed from text
     */
    public function testRemovesHTMLTags(): void {
        $input = '<p>هذا <strong>نص</strong> تجريبي</p>';
        $result = TextCleaner::clean($input);
        
        $this->assertStringNotContainsString('<', $result);
        $this->assertStringNotContainsString('>', $result);
        $this->assertStringContainsString('هذا', $result);
        $this->assertStringContainsString('نص', $result);
        $this->assertStringContainsString('تجريبي', $result);
    }
    
    /**
     * Test that URLs are removed from text
     */
    public function testRemovesURLs(): void {
        $input = 'خبر عاجل من https://example.com/article وزيارة http://test.org';
        $result = TextCleaner::clean($input);
        
        $this->assertStringNotContainsString('https://', $result);
        $this->assertStringNotContainsString('http://', $result);
        $this->assertStringNotContainsString('example.com', $result);
        $this->assertStringContainsString('خبر عاجل', $result);
    }
    
    /**
     * Test that timestamps are removed from Arabic text
     */
    public function testRemovesTimestamps(): void {
        $input = 'وقع الحدث الساعة 14:30 مساءً بتاريخ اليوم';
        $result = TextCleaner::clean($input);
        
        $this->assertStringNotContainsString('14:30', $result);
        $this->assertStringNotContainsString('مساءً', $result);
        $this->assertStringContainsString('وقع الحدث', $result);
    }
    
    /**
     * Test that promotional content is removed
     */
    public function testRemovesPromotionalContent(): void {
        $input = 'عاجل: خبر مهم للاشتراك في قناتنا على واتساب عبر الرابط telegram.me/test';
        $result = TextCleaner::clean($input);
        
        $this->assertStringNotContainsString('قناة', $result);
        $this->assertStringNotContainsString('واتساب', $result);
        $this->assertStringNotContainsString('telegram.me', $result);
        $this->assertStringNotContainsString('t.me', $result);
    }
    
    /**
     * Test that emojis are removed from text
     */
    public function testRemovesEmojis(): void {
        $input = '🚨 عاجل: تفجير 💥 في بغداد 🇮🇶';
        $result = TextCleaner::clean($input);
        
        // Check that emoji characters are removed
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1F6FF}]/u', $result);
        $this->assertStringContainsString('عاجل', $result);
        $this->assertStringContainsString('تفجير', $result);
        $this->assertStringContainsString('بغداد', $result);
    }
    
    /**
     * Test that non-Arabic lines are filtered out
     */
    public function testExtractsArabicOnly(): void {
        $input = "عاجل: تفجير في بغداد\nBreaking News: Explosion in Baghdad\n12:30 PM";
        $result = TextCleaner::clean($input);
        
        $this->assertStringContainsString('تفجير', $result);
        $this->assertStringContainsString('بغداد', $result);
        $this->assertDoesNotMatchRegularExpression('/[a-zA-Z]{2,}/', $result);
    }
    
    /**
     * Test normalizeTitleForDedupe with similar titles
     */
    public function testNormalizeTitleForDedupe(): void {
        $title1 = "عاجل: استشهاد 3 فلسطينيين في غارة إسرائيلية - غزة";
        $title2 = "استشهاد 3 فلسطينيين في غارة إسرائيلية";
        $title3 = "#عاجل استشهاد ٣ فلسطينيين بغارة إسرائيلية Gaza";
        
        $norm1 = TextCleaner::normalizeTitleForDedupe($title1);
        $norm2 = TextCleaner::normalizeTitleForDedupe($title2);
        $norm3 = TextCleaner::normalizeTitleForDedupe($title3);
        
        // All three should normalize to similar strings
        $this->assertEquals($norm1, $norm2);
        $this->assertEquals($norm1, $norm3);
    }
    
    /**
     * Test normalizeTitleForDedupe removes news prefixes
     */
    public function testNormalizeTitleRemovesPrefixes(): void {
        $input = "عاجل | وسائل إعلام: رويترز تنقل عن مصدر قوله أكد صرح أعلن";
        $result = TextCleaner::normalizeTitleForDedupe($input);
        
        $this->assertStringNotContainsString('عاجل', $result);
        $this->assertStringNotContainsString('وسائل إعلام', $result);
        $this->assertStringNotContainsString('رويترز', $result);
        $this->assertStringNotContainsString('قال', $result);
        $this->assertStringNotContainsString('صرح', $result);
        $this->assertStringNotContainsString('أعلن', $result);
    }
    
    /**
     * Test empty input handling
     */
    public function testHandlesEmptyInput(): void {
        $this->assertEquals('', TextCleaner::clean(''));
        $this->assertEquals('', TextCleaner::clean(null));
        $this->assertEquals('', TextCleaner::normalizeTitleForDedupe(''));
    }
    
    /**
     * Test extractArabic method
     */
    public function testExtractArabic(): void {
        $input = "Breaking: عاجل تفجير Baghdad explosion في العراق";
        $result = TextCleaner::extractArabic($input);
        
        $this->assertStringContainsString('عاجل', $result);
        $this->assertStringContainsString('تفجير', $result);
        $this->assertStringContainsString('العراق', $result);
        $this->assertStringNotContainsString('Breaking', $result);
        $this->assertStringNotContainsString('Baghdad', $result);
        $this->assertStringNotContainsString('explosion', $result);
    }
    
    /**
     * Test text cleaning with HTML entities
     */
    public function testDecodesHTMLEntities(): void {
        $input = '&quot;عاجل&quot; &amp; تفجير &#39;في&#39; بغداد';
        $result = TextCleaner::clean($input);
        
        $this->assertStringContainsString('"', $result);
        $this->assertStringContainsString('&', $result);
        $this->assertStringContainsString('\'', $result);
    }
    
    /**
     * Test multiple spaces are collapsed
     */
    public function testCollapsesMultipleSpaces(): void {
        $input = 'عاجل      تفجير         في       بغداد';
        $result = TextCleaner::clean($input);
        
        $this->assertMatchesRegularExpression('/^عاجل تفجير في بغداد$/', $result);
    }
    
    /**
     * Test repeated exclamation marks are limited
     */
    public function testLimitsExclamationMarks(): void {
        $input = 'عاجل!!!!!!! تفجير!!!!!!!! في بغداد!!!';
        $result = TextCleaner::clean($input);
        
        $this->assertStringContainsString('!!!', $result);
        $this->assertDoesNotMatchRegularExpression('/!{4,}/', $result);
    }
}
