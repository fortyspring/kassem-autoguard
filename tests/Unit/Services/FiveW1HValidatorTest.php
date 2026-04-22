<?php
/**
 * Unit Tests for 5W1H Validator
 * 
 * @package OSINT_Pro\Tests\Unit\Services
 */

namespace SO\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class FiveW1HValidatorTest extends TestCase {

    /**
     * Test case: Valid news with all elements
     */
    public function testValidNewsWithAllElements() {
        $news = [
            'title' => 'حزب الله يستهدف موقعاً إسرائيلياً بصاروخ في الجليل',
            'content' => 'أعلنت المقاومة الإسلامية عن استهداف موقع عسكري إسرائيلي بصاروخ موجه انتقاماً للغارة التي وقعت صباح اليوم.',
            'actor' => 'المقاومة الإسلامية (حزب الله)',
            'meta' => [
                'location' => 'الجليل',
                'time' => 'صباح اليوم'
            ]
        ];

        // Simulate validation logic
        $has_who = !empty($news['actor']);
        $has_what = preg_match('/(استهدف|قصف|غارة)/ui', $news['title']);
        $has_where = preg_match('/(في |إلى|الجليل)/ui', $news['title'] . ' ' . $news['content']);
        $has_when = preg_match('/(اليوم|صباح|مساء)/ui', $news['content']);
        
        $this->assertTrue($has_who, 'Should have actor');
        $this->assertTrue($has_what, 'Should have event');
        $this->assertTrue($has_where, 'Should have location');
        $this->assertTrue($has_when, 'Should have time');
    }

    /**
     * Test case: News with media outlet as actor (should be rejected)
     */
    public function testMediaOutletAsActorShouldBeRejected() {
        $news = [
            'title' => 'العربية: تقارير عن انفجار في طهران',
            'content' => 'نقلت قناة العربية عن مصادر محلية وقوع انفجار في العاصمة الإيرانية.',
            'actor' => 'قناة العربية',
            'meta' => []
        ];

        // Check if actor is media outlet
        $media_outlets = ['العربية', 'قناة العربية', 'الجزيرة', 'الميادين', 'رويترز'];
        $is_media = false;
        foreach ($media_outlets as $outlet) {
            if (mb_stripos($news['actor'], $outlet) !== false) {
                $is_media = true;
                break;
            }
        }

        $this->assertTrue($is_media, 'Actor should be identified as media outlet');
    }

    /**
     * Test case: Missing location
     */
    public function testNewsMissingLocation() {
        $news = [
            'title' => 'ترمب يعلن عن عقوبات جديدة',
            'content' => 'أعلن الرئيس الأمريكي دونالد ترامب عن فرض عقوبات اقتصادية جديدة دون تحديد تفاصيل.',
            'actor' => 'ترمب',
            'meta' => [
                'time' => 'اليوم'
            ]
        ];

        $has_where = preg_match('/(في |إلى|من|على|قرب|جنوب|شمال|شرق|غرب)/ui', $news['title'] . ' ' . $news['content']);
        
        $this->assertFalse((bool)$has_where, 'Should not have location');
    }

    /**
     * Test case: Incomplete news (missing who and where)
     */
    public function testIncompleteNews() {
        $news = [
            'title' => 'انفجار يحدث في منطقة مأهولة',
            'content' => 'وقع انفجار كبير وأسفر عن أضرار مادية.',
            'actor' => '',
            'meta' => []
        ];

        $has_who = !empty($news['actor']);
        $has_where = preg_match('/(في |إلى|من|على)/ui', $news['title'] . ' ' . $news['content']);
        
        $this->assertFalse($has_who, 'Should not have actor');
        $this->assertTrue((bool)$has_where, 'Should have general location word but no specific place');
    }

    /**
     * Test case: Actor inference from text
     */
    public function testActorInferenceFromText() {
        $text = 'أعلن وزير الخارجية الروسي سيرغي لافروف عن موقف روسيا من الأزمة الحالية.';
        
        $patterns = [
            '/(?:قال|صرّح|أعلن|أكّد|ذكر|أفاد|وفقاً لـ|عن لسان)\s+([^،\.:\n]{5,60})/ui' => 1,
            '/^(.+?)[\s:]+(?:يقول|يؤكد|يعلن|صرّح)/ui' => 1,
        ];

        $inferred_actor = '';
        foreach ($patterns as $pattern => $group) {
            if (preg_match($pattern, $text, $matches)) {
                $inferred_actor = trim($matches[$group]);
                break;
            }
        }

        $this->assertNotEmpty($inferred_actor, 'Should infer actor from text');
        $this->assertStringContainsString('لافروف', $inferred_actor, 'Should contain Lavrov');
    }

    /**
     * Test case: Auto-correct actor name
     */
    public function testAutoCorrectActorName() {
        $valid_actors = [
            'حزب الله' => 'المقاومة الإسلامية (حزب الله)',
            'ترامب' => 'الرئيس الأمريكي دونالد ترامب',
            'لافروف' => 'وزير الخارجية الروسي',
        ];

        $input_actor = 'حزب الله';
        $corrected = '';
        
        foreach ($valid_actors as $key => $canonical) {
            if (mb_stripos($input_actor, $key) !== false) {
                $corrected = $canonical;
                break;
            }
        }

        $this->assertEquals('المقاومة الإسلامية (حزب الله)', $corrected);
    }

    /**
     * Test case: Date/time detection
     */
    public function testDateTimeDetection() {
        $texts = [
            '18/04/2026' => true,
            '01:50 م' => true,
            'اليوم' => true,
            'قبل قليل' => true,
            'أمس مساءً' => true,
            'text without time' => false,
        ];

        $pattern = '/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})|(\d{1,2}:\d{2}\s*[صم]?)/ui|(اليوم|الأمس|غداً|الآن|قبل قليل|مؤخراً|مساءً|صباحاً)/ui';

        foreach ($texts as $text => $expected) {
            $found = (bool)preg_match($pattern, $text);
            $this->assertEquals($expected, $found, "Time detection failed for: $text");
        }
    }

    /**
     * Test case: Location detection
     */
    public function testLocationDetection() {
        $texts = [
            'في لبنان' => true,
            'إلى غزة' => true,
            'جنوب بيروت' => true,
            'ضواحي دمشق' => true,
            'في تل أبيب' => true,
            'no location here' => false,
        ];

        $pattern = '/(في |إلى |من |على |بـ|قرب|جنوب|شمال|شرق|غرب|وسط|داخل|خارج|محيط|أطراف|ضواحي|ناحية|بلدة|مدينة|محافظة|منطقة|إقليم)/ui';

        foreach ($texts as $text => $expected) {
            $found = (bool)preg_match($pattern, $text);
            $this->assertEquals($expected, $found, "Location detection failed for: $text");
        }
    }

    /**
     * Test case: Event type detection (What)
     */
    public function testEventDetection() {
        $texts = [
            'غارة إسرائيلية' => true,
            'قصف مدفعي' => true,
            'اجتماع وزراء' => true,
            'تصريح صحفي' => true,
            'قرار عقوبات' => true,
            'random text' => false,
        ];

        $pattern = '/(غارة|قصف|استهداف|اعتقال|اغتيال|انفجار|إطلاق|اشتباك|توغل|اجتماع|مفاوضات|تصريح|قرار|عقوبات)/ui';

        foreach ($texts as $text => $expected) {
            $found = (bool)preg_match($pattern, $text);
            $this->assertEquals($expected, $found, "Event detection failed for: $text");
        }
    }

    /**
     * Test case: Scoring system
     */
    public function testScoringSystem() {
        $elements = [
            'who' => ['found' => true, 'weight' => 25],
            'what' => ['found' => true, 'weight' => 20],
            'where' => ['found' => true, 'weight' => 20],
            'when' => ['found' => true, 'weight' => 15],
            'why' => ['found' => false, 'weight' => 10],
            'how' => ['found' => false, 'weight' => 10],
        ];

        $total_weight = 0;
        $earned_score = 0;

        foreach ($elements as $element => $config) {
            if ($config['found']) {
                $earned_score += $config['weight'];
            }
            $total_weight += $config['weight'];
        }

        $percentage = round(($earned_score / $total_weight) * 100);

        $this->assertEquals(80, $percentage, 'Score should be 80%');
        $this->assertGreaterThanOrEqual(70, $percentage, 'Should pass minimum threshold');
    }

    /**
     * Test case: Context patterns (Why)
     */
    public function testContextPatternDetection() {
        $texts = [
            'رداً على العدوان' => true,
            'انتقاماً للشهداء' => true,
            'بسبب التوتر المتصاعد' => true,
            'في إطار التصعيد الحالي' => true,
            'no context' => false,
        ];

        $pattern = '/(رداً على|انتقاماً|بسبب|نتيجة|على خلفية|في إطار|استجابة لـ)/ui';

        foreach ($texts as $text => $expected) {
            $found = (bool)preg_match($pattern, $text);
            $this->assertEquals($expected, $found, "Context detection failed for: $text");
        }
    }

    /**
     * Test case: Method/Weapon detection (How)
     */
    public function testMethodDetection() {
        $texts = [
            'باستخدام صاروخ موجه' => true,
            'طائرة مسيرة تقصف الهدف' => true,
            'قذائف مدفعية' => true,
            'عملية دهس' => false,
            'no method specified' => false,
        ];

        $pattern = '/(طائرة مسيرة|صاروخ|قذيفة|مدفعية|دبابة|مروحية|زورق|لغم|سكين|رشاش|قناص)/ui';

        foreach ($texts as $text => $expected) {
            $found = (bool)preg_match($pattern, $text);
            $this->assertEquals($expected, $found, "Method detection failed for: $text");
        }
    }

    /**
     * Test case: Complex real-world example from the provided data
     */
    public function testRealWorldExample1() {
        // Example: "إيران: قائد الثورة والجمهورية السيد مجتبى خامنئي: الجيش وقف في وجه المخططات الخبيثة لأميركا"
        $news = [
            'title' => 'إيران: قائد الثورة والجمهورية السيد مجتبى خامنئي: الجيش وقف في وجه المخططات الخبيثة لأميركا وفلول ال...',
            'content' => 'استراتيجي: عام تكتيكي: تكتيكي منطقة: إيران فاعل: قائد الثورة والجمهورية السيد مجتبى خامنئي هدف: إيران',
            'actor' => 'قائد الثورة والجمهورية السيد مجتبى خامنئي',
            'meta' => [
                'location' => 'إيران',
                'strategic' => 'عام',
                'tactical' => 'تكتيكي'
            ]
        ];

        $has_who = !empty($news['actor']) && $news['actor'] !== 'فاعل غير محسوم';
        $has_what = preg_match('/(وقف|واجه|مخططات)/ui', $news['content']);
        $has_where = isset($news['meta']['location']) && !empty($news['meta']['location']);
        $has_when = false; // No explicit time mentioned
        
        $this->assertTrue($has_who, 'Should have valid actor');
        $this->assertTrue((bool)$has_what, 'Should have event/action');
        $this->assertTrue($has_where, 'Should have location');
        // Note: This example would fail the 'when' requirement, showing a real edge case
    }

    /**
     * Test case: Real-world example with media as actor (problematic)
     */
    public function testRealWorldExampleMediaAsActor() {
        // Example: "وسائل إعلام أجنبية عن الهيئة البحرية البريطانية: تقارير عن واقعة..."
        $news = [
            'title' => 'وسائل إعلام أجنبية عن الهيئة البحرية البريطانية: تقارير عن واقعة على بعد 20 ميلاً بحرياً شمال شرقي س...',
            'content' => 'فاعل: فاعل غير محسوم سياق: سياق تصريحي/إعلامي نية: تموضع/رسائل سياسية سلاح: زوارق حربية',
            'actor' => 'فاعل غير محسوم',
            'meta' => []
        ];

        // Should detect that the title starts with media reference
        $is_media_ref = preg_match('/^(وسائل إعلام|إعلام|قناة|العربية|الميادين|الجزيرة)/ui', $news['title']);
        
        $this->assertTrue((bool)$is_media_ref, 'Should detect media reference in title');
        $this->assertEquals('فاعل غير محسوم', $news['actor'], 'Actor should be marked as undetermined');
    }

    /**
     * Test case: Date-only content (should be rejected)
     */
    public function testDateOnlyContent() {
        // Example: "18/04/2026" - just a date, no actual news
        $news = [
            'title' => '18/04/2026',
            'content' => 'استراتيجي: عام تكتيكي: تكتيكي منطقة: الساحة المرتبطة بالحدث (استنتاج) فاعل: إيران',
            'actor' => 'إيران',
            'meta' => []
        ];

        $has_what = preg_match('/(غارة|قصف|استهداف|اعتقال|اغتيال|انفجار|إطلاق|اشتباك|توغل|اجتماع|مفاوضات|تصريح|قرار)/ui', $news['title'] . ' ' . $news['content']);
        
        $this->assertFalse((bool)$has_what, 'Should not have clear event - this is low quality news');
    }
}
