<?php

namespace Tests\Unit\Engines;

use App\Engines\ActorDecisionEngineV3;
use PHPUnit\Framework\TestCase;

/**
 * اختبارات محرك قرار الفاعل V3
 * 
 * تختبر الحلول الجذرية للمشاكل الست المكتشفة:
 * 1. تضارب الحقول -> حلها: حقل actor_final واحد
 * 2. الاستنتاج من المنطقة فقط -> حلها: أولوية للذكر الصريح والأفعال
 * 3. تلويث المصدر -> حلها: قائمة سوداء صارمة
 * 4. نسخ الجملة كفاعل -> حلها: أنماط محددة بدقة
 * 5. JSON مكسور -> حلها: دالة cleanForJson
 * 6. غياب الثقة -> حلها: نظام ثقة 3 مستويات
 */
class ActorDecisionEngineV3Test extends TestCase
{
    private ActorDecisionEngineV3 $engine;

    protected function setUp(): void
    {
        $this->engine = new ActorDecisionEngineV3();
    }

    /**
     * PHASE 1: توحيد القرار - اختبار الذكر الصريح (المستوى 1)
     */
    public function testExplicitMentionHezbollah(): void
    {
        $result = $this->engine->decide(
            'حزب الله: قصفنا موقعًا إسرائيليًا',
            'أعلن حزب الله مسؤوليته عن القصف'
        );

        $this->assertEquals('حزب الله', $result['actor']);
        $this->assertGreaterThanOrEqual(90, $result['confidence']);
        $this->assertStringContainsString('مستوى 1', $result['reason']);
    }

    public function testExplicitMentionIsraeliArmy(): void
    {
        $result = $this->engine->decide(
            'الجيش الإسرائيلي يقصف جنوب لبنان',
            'غارات جوية شنها سلاح الجو الإسرائيلي'
        );

        $this->assertEquals('جيش العدو الإسرائيلي', $result['actor']);
        $this->assertGreaterThanOrEqual(90, $result['confidence']);
    }

    public function testExplicitMentionHamas(): void
    {
        $result = $this->engine->decide(
            'حماس تطلق صواريخ على تل أبيب',
            'كتائب القسام تعلن إطلاق 10 صواريخ'
        );

        $this->assertEquals('حركة حماس', $result['actor']);
        $this->assertGreaterThanOrEqual(90, $result['confidence']);
    }

    /**
     * PHASE 2: استنتاج من الفعل (المستوى 2)
     * الحالة من الأرشيف: "رشقات رشاشة إسرائيلية"
     */
    public function testInferFromActionRashqatRashasha(): void
    {
        $result = $this->engine->decide(
            'لبنان: مراسل الميادين في الجنوب: رشقات رشاشة إسرائيلية على بلدة عيترون',
            'قصف مدفعي للاحتلال على بلدة كونين',
            'لبنان / الجنوب'
        );

        $this->assertEquals('جيش العدو الإسرائيلي', $result['actor']);
        $this->assertGreaterThanOrEqual(80, $result['confidence']);
        $this->assertStringContainsString('استنتاج من الفعل', $result['reason']);
    }

    public function testInferFromActionQasfLebanon(): void
    {
        $result = $this->engine->decide(
            'قصف إسرائيلي على الضاحية',
            'غارة جوية تستهدف مبنى',
            'لبنان'
        );

        $this->assertEquals('جيش العدو الإسرائيلي', $result['actor']);
        $this->assertGreaterThanOrEqual(80, $result['confidence']);
    }

    public function testInferFromActionSarawikhGaza(): void
    {
        $result = $this->engine->decide(
            'إطلاق صواريخ من غزة نحو سديروت',
            'الفصائل تقصف الاحتلال',
            'قطاع غزة'
        );

        $this->assertEquals('الفصائل الفلسطينية', $result['actor']);
        $this->assertGreaterThanOrEqual(80, $result['confidence']);
    }

    /**
     * PHASE 3: القائمة السوداء - منع المصادر من أن تكون فاعلين
     */
    public function testBlacklistPreventsSourceAsActor(): void
    {
        $result = $this->engine->decide(
            'اليوم السابع: محور المقاومة يرد على العدوان',
            'نقلت قناة العربية عن مصادر...',
        );

        // يجب ألا يكون "اليوم السابع" أو "قناة العربية" هو الفاعل
        $this->assertNotEquals('اليوم السابع', $result['actor']);
        $this->assertNotEquals('قناة العربية', $result['actor']);
        
        // النتيجة المتوقعة: فاعل غير محسوم (لأن النص لا يحتوي على فاعل حقيقي)
        $this->assertEquals('فاعل غير محسوم', $result['actor']);
    }

    public function testBlacklistPreventsTamRasad(): void
    {
        $result = $this->engine->decide(
            'تم رصد اقتحام لمستوطنة',
            'مصادر أمنية تؤكد الرصد',
        );

        // "تم رصد" لا يمكن أن يكون فاعلاً
        $this->assertNotEquals('تم رصد', $result['actor']);
        $this->assertNotEquals('مصادر', $result['actor']);
    }

    /**
     * PHASE 4: منع نسخ الجملة كفاعل
     * الحالة من الأرشيف: "تجدد القصف المدفعي الإسرائيلي..." أصبح الفاعل
     */
    public function testPreventFullSentenceAsActor(): void
    {
        $result = $this->engine->decide(
            'تجدد القصف المدفعي الإسرائيلي على الحدود',
            '',
            'لبنان'
        );

        // الفاعل يجب أن يكون "جيش العدو الإسرائيلي" وليس الجملة كاملة
        $this->assertEquals('جيش العدو الإسرائيلي', $result['actor']);
        $this->assertLessThan(50, strlen($result['actor'])); // ليس جملة طويلة
    }

    /**
     * PHASE 5: أخبار اقتصادية/سياسية بدون فاعل مباشر
     * الحالة من الأرشيف: "أين تذهب أموال نفط العراق؟" → إيران (خطأ)
     */
    public function testEconomicNewsNoActor(): void
    {
        $result = $this->engine->decide(
            'أين تذهب أموال نفط العراق؟',
            'تحليل اقتصادي حول تدفقات النفط',
            'العراق'
        );

        // يجب أن يكون "فاعل غير محسوم" وليس "إيران"
        $this->assertEquals('فاعل غير محسوم', $result['actor']);
        $this->assertEquals(0, $result['confidence']);
    }

    /**
     * PHASE 6: نظام الثقة
     */
    public function testConfidenceLevels(): void
    {
        // مستوى 1: ثقة عالية
        $result1 = $this->engine->decide('حزب الله: ...', '');
        $this->assertGreaterThanOrEqual(90, $result1['confidence']);

        // مستوى 2: ثقة متوسطة
        $result2 = $this->engine->decide('قصف على لبنان', '', 'لبنان');
        $this->assertGreaterThanOrEqual(80, $result2['confidence']);
        $this->assertLessThan(90, $result2['confidence']);

        // مستوى 3: غير محسوم
        $result3 = $this->engine->decide('خبر عام بدون تفاصيل', '');
        $this->assertEquals(0, $result3['confidence']);
    }

    /**
     * PHASE 7: تنظيف JSON
     */
    public function testCleanForJsonPreventsArrayString(): void
    {
        $data = [
            'actor' => 'حزب الله',
            'layers' => ['عسكري', 'سياسي'], // مصفوفة
            'meta' => (object)['key' => 'value'] // كائن
        ];

        $cleaned = ActorDecisionEngineV3::cleanForJson($data);

        $json = json_encode($cleaned);
        
        // يجب ألا تحتوي على كلمة "Array" كنص
        $this->assertStringNotContainsString('"Array"', $json);
        $this->assertStringContainsString('["عسكري","سياسي"]', $json);
    }

    /**
     * حالات حدية من الأرشيف
     */
    public function testArchivalCaseIranIraqOil(): void
    {
        // قبل: إيران (خطأ)
        // بعد: فاعل غير محسوم
        $result = $this->engine->decide(
            'أين تذهب أموال نفط العراق؟',
            '',
            'العراق'
        );

        $this->assertEquals('فاعل غير محسوم', $result['actor']);
    }

    public function testArchivalCaseTamRasadIqtihama(): void
    {
        // قبل: "تم رصد اقتحام..." (نسخ الجملة)
        // بعد: جيش العدو الإسرائيلي
        $result = $this->engine->decide(
            'تم رصد اقتحام Israeli forces للضفة',
            '',
            'الضفة الغربية'
        );

        $this->assertEquals('جيش العدو الإسرائيلي', $result['actor']);
        $this->assertNotEquals('تم رصد اقتحام', $result['actor']);
    }

    public function testArchivalCaseAlYoumAlSabaa(): void
    {
        // قبل: اليوم السابع (كمصدر) أصبح فاعل
        // بعد: فاعل حقيقي أو غير محسوم
        $result = $this->engine->decide(
            'اليوم السابع: محور المقاومة يهدد إسرائيل',
            '',
            ''
        );

        $this->assertNotEquals('اليوم السابع', $result['actor']);
        // المحور قد يُستخرج إذا كان هناك نمط، لكن اليوم السابع مستحيل
    }
}
