<?php
/**
 * Unit Tests for FingerprintBuilder Utility Class
 * 
 * @package OSINT_Pro\Tests\Unit\Utils
 */

namespace Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use SO\Utils\FingerprintBuilder;

class FingerprintBuilderTest extends TestCase {
    
    /**
     * Test building title fingerprint
     */
    public function testBuildTitleFingerprint(): void {
        $title = 'استشهاد 3 فلسطينيين في غارة إسرائيلية على غزة';
        $fingerprint = FingerprintBuilder::buildTitleFingerprint($title);
        
        $this->assertNotEmpty($fingerprint);
        $this->assertEquals(32, strlen($fingerprint)); // MD5 hash length
    }
    
    /**
     * Test that similar titles produce same fingerprint
     */
    public function testSimilarTitlesProduceSameFingerprint(): void {
        $title1 = 'المقاومة تستهدف دبابة إسرائيلية';
        $title2 = 'دبابة إسرائيلية تستهدفها المقاومة';
        
        $fp1 = FingerprintBuilder::buildTitleFingerprint($title1);
        $fp2 = FingerprintBuilder::buildTitleFingerprint($title2);
        
        // Words are sorted, so order shouldn't matter
        $this->assertEquals($fp1, $fp2);
    }
    
    /**
     * Test fingerprint ignores stop words
     */
    public function testIgnoresStopWords(): void {
        $title1 = 'في اليوم التالي حدث تفجير في بغداد';
        $title2 = 'اليوم التالي حدث تفجير بغداد';
        
        $fp1 = FingerprintBuilder::buildTitleFingerprint($title1);
        $fp2 = FingerprintBuilder::buildTitleFingerprint($title2);
        
        $this->assertEquals($fp1, $fp2);
    }
    
    /**
     * Test empty title returns empty fingerprint
     */
    public function testEmptyTitleReturnsEmptyFingerprint(): void {
        $this->assertEquals('', FingerprintBuilder::buildTitleFingerprint(''));
        $this->assertEquals('', FingerprintBuilder::buildTitleFingerprint(null));
    }
    
    /**
     * Test short titles return empty fingerprint
     */
    public function testShortTitleReturnsEmptyFingerprint(): void {
        // Titles with less than 2 significant words should return empty
        $this->assertEquals('', FingerprintBuilder::buildTitleFingerprint('عاجل'));
        $this->assertEquals('', FingerprintBuilder::buildTitleFingerprint('في اليوم'));
    }
    
    /**
     * Test fingerprint is consistent across multiple calls
     */
    public function testConsistentFingerprint(): void {
        $title = 'غارة إسرائيلية تقصف موقعاً في الجنوب';
        
        $fp1 = FingerprintBuilder::buildTitleFingerprint($title);
        $fp2 = FingerprintBuilder::buildTitleFingerprint($title);
        $fp3 = FingerprintBuilder::buildTitleFingerprint($title);
        
        $this->assertEquals($fp1, $fp2);
        $this->assertEquals($fp2, $fp3);
    }
}
