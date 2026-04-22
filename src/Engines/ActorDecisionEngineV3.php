<?php
/**
 * Actor Decision Engine V3
 * 
 * Philosophy: Single Source of Truth (actor_final)
 * Replaces conflicting layers: actor_v2, war_data.actor, field_data.actor_v2, who_primary
 * 
 * Decision Hierarchy:
 * 1. Explicit Mention (High Confidence)
 * 2. Action-Based Inference (Medium Confidence)
 * 3. Geopolitical Context (Low Confidence - Deprecated for now to avoid noise)
 * 4. Undecided (Fallback)
 * 
 * Blacklist: Prevents sources/media terms from becoming actors.
 */

namespace App\Engines;

class ActorDecisionEngineV3
{
    // قائمة سوداء صارمة: كلمات ممنوعة نهائيًا أن تكون فاعلاً
    private const BLACKLIST = [
        'تم رصد', 'تقارير', 'مصادر', 'اليوم السابع', 'قناة العربية', 'واشنطن بوست',
        'فيديو', 'مقطع', 'عاجل', 'توضيح', 'بيانات', 'مشاهد', 'مراسل', 'صحيفة',
        'جريدة', 'وكالة', 'تلفزيون', 'قناة', 'موقع', 'صحفي', 'إعلامي', 'نشر',
        'أكد', 'أفاد', 'ذكر', 'قال', 'صرح', 'أعلن', 'كشف', 'وثق', 'رصد'
    ];

    // أنماط المستوى 1: ذكر صريح ومباشر (ثقة 95+)
    private const LEVEL_1_PATTERNS = [
        '/حزب الله[:\s]/u' => 'حزب الله',
        '/المقاومة الإسلامية[:\s]/u' => 'حزب الله',
        '/الحرس الثوري[:\s]/u' => 'الحرس الثوري الإيراني',
        '/الجيش الإسرائيلي[:\s]/u' => 'جيش العدو الإسرائيلي',
        '/قوات الاحتلال[:\s]/u' => 'جيش العدو الإسرائيلي',
        '/العدو الإسرائيلي[:\s]/u' => 'جيش العدو الإسرائيلي',
        '/ترامب[:\s]/u' => 'الولايات المتحدة (إدارة ترامب)',
        '/ماكرون[:\s]/u' => 'فرنسا (إدارة ماكرون)',
        '/بوتين[:\s]/u' => 'روسيا (إدارة بوتين)',
        '/حماس[:\s]/u' => 'حركة حماس',
        '/الجهاد الإسلامي[:\s]/u' => 'حركة الجهاد الإسلامي',
        '/كتائب القسام[:\s]/u' => 'حركة حماس (كتائب القسام)',
        '/ألوية القدس[:\s]/u' => 'حركة الجهاد الإسلامي (ألوية القدس)',
        '/الفصائل الفلسطينية[:\s]/u' => 'الفصائل الفلسطينية',
        '/سلاح الجو الإسرائيلي[:\s]/u' => 'جيش العدو الإسرائيلي (سلاح الجو)',
        '/الطيران الإسرائيلي[:\s]/u' => 'جيش العدو الإسرائيلي (سلاح الجوي)',
    ];

    // أنماط المستوى 2: استنتاج من الفعل (ثقة 80-90)
    private const LEVEL_2_ACTIONS = [
        ['patterns' => ['/اقتحام/u', '/مداهمة/u', '/اعتقال/u', '/هدم منزل/u'], 'context' => 'الضفة', 'actor' => 'جيش العدو الإسرائيلي'],
        ['patterns' => ['/اقتحام/u', '/مداهمة/u', '/اعتقال/u', '/هدم منزل/u'], 'context' => 'غزة', 'actor' => 'جيش العدو الإسرائيلي'],
        ['patterns' => ['/قصف/u', '/غارة/u', '/إغارة/u'], 'context' => 'لبنان', 'actor' => 'جيش العدو الإسرائيلي'],
        ['patterns' => ['/قصف/u', '/غارة/u', '/إغارة/u'], 'context' => 'غزة', 'actor' => 'جيش العدو الإسرائيلي'],
        ['patterns' => ['/إطلاق صواريخ/u', '/إطلاق قذائف/u', '/صاروخ/u'], 'context' => 'غزة', 'actor' => 'الفصائل الفلسطينية'],
        ['patterns' => ['/إطلاق صواريخ/u', '/إطلاق قذائف/u', '/صاروخ/u'], 'context' => 'لبنان', 'actor' => 'حزب الله'],
        ['patterns' => ['/رشقات رشاشة/u', '/نيران/u', '/قصف مدفعي/u'], 'context' => 'لبنان', 'actor' => 'جيش العدو الإسرائيلي'],
        ['patterns' => ['/رشقات رشاشة/u', '/نيران/u', '/قصف مدفعي/u'], 'context' => 'الأراضي المحتلة', 'actor' => 'الفصائل الفلسطينية'],
    ];

    /**
     * القرار الرئيسي: يستخرج فاعلاً واحداً نهائياً
     * @param string $headline عنوان الخبر
     * @param string $content محتوى الخبر
     * @param string|null $region المنطقة المستخرجة مسبقاً (اختياري للمساعدة)
     * @return array ['actor' => string, 'confidence' => int, 'reason' => string]
     */
    public function decide(string $headline, string $content, ?string $region = null): array
    {
        $text = $headline . ' ' . $content;
        
        // 1. التحقق من المستوى الأول (الذكر الصريح)
        $level1Result = $this->checkLevel1($text);
        if ($level1Result['confidence'] > 0) {
            return $level1Result;
        }

        // 2. التحقق من المستوى الثاني (الاستنتاج من الفعل)
        $level2Result = $this->checkLevel2($text, $region);
        if ($level2Result['confidence'] > 0) {
            return $level2Result;
        }

        // 3. الفشل في التحديد -> غير محسوم
        return [
            'actor' => 'فاعل غير محسوم',
            'confidence' => 0,
            'reason' => 'لا يوجد نمط صريح أو فعل دال كافٍ للتحديد'
        ];
    }

    private function checkLevel1(string $text): array
    {
        foreach (self::LEVEL_1_PATTERNS as $pattern => $actorName) {
            if (preg_match($pattern, $text, $matches)) {
                // تحقق إضافي: هل المطابقة جزء من قائمة سوداء؟ (نادر في المستوى 1 لكن للاحتياط)
                if ($this->isBlacklisted($actorName)) {
                    continue;
                }
                
                return [
                    'actor' => $actorName,
                    'confidence' => 95,
                    'reason' => 'ذكر صريح في النص (مستوى 1)'
                ];
            }
        }
        return ['confidence' => 0];
    }

    private function checkLevel2(string $text, ?string $region): array
    {
        // تطبيع المنطقة للمقارنة
        $normalizedRegion = $this->normalizeRegion($region);

        foreach (self::LEVEL_2_ACTIONS as $action) {
            foreach ($action['patterns'] as $pattern) {
                if (preg_match($pattern, $text)) {
                    // هل السياق الجغرافي متطابق؟
                    $regionMatch = empty($normalizedRegion) || 
                                   stripos($text, $action['context']) !== false || 
                                   stripos($normalizedRegion, $action['context']) !== false;
                    
                    if ($regionMatch) {
                        return [
                            'actor' => $action['actor'],
                            'confidence' => 85,
                            'reason' => "استنتاج من الفعل '{$action['patterns'][0]}' في سياق {$action['context']}"
                        ];
                    }
                }
            }
        }
        return ['confidence' => 0];
    }

    private function isBlacklisted(string $candidate): bool
    {
        foreach (self::BLACKLIST as $forbidden) {
            if (stripos($candidate, $forbidden) !== false) {
                return true;
            }
        }
        // فحص خاص: إذا كان النص الأصلي يحتوي على "قناة" أو "صحيفة" قبل الاسم
        return false;
    }

    private function normalizeRegion(?string $region): string
    {
        if (!$region) return '';
        $region = mb_strtolower($region);
        // تبسيط الأسماء
        $replacements = [
            'الأراضي المحتلة (إسرائيل)' => 'الأراضي المحتلة',
            'فلسطين المحتلة' => 'الأراضي المحتلة',
            'الضفة الغربية' => 'الضفة',
            'قطاع غزة' => 'غزة',
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $region);
    }

    /**
     * تنظيف مصفوفة البيانات قبل الترميز JSON لمنع "Array"
     */
    public static function cleanForJson(array $data): array
    {
        array_walk_recursive($data, function (&$item) {
            if (is_array($item)) {
                // تحويل المصفوفات الفرعية إلى نص مفصول بفواصل إذا لزم الأمر، أو تركها مصفوفة
                // الأهم: عدم السماح بتحويلها تلقائياً لسلسلة "Array" عند الطباعة
                // هنا نضمن أنها تبقى مصفوفة صحيحة لـ json_encode
            } elseif (is_object($item)) {
                $item = (array) $item;
            }
        });
        return $data;
    }
}
