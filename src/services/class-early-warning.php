<?php
/**
 * نظام الإنذار المبكر المتقدم - Advanced Early Warning System
 * 
 * يحلل المؤشرات المتراكمة لإصدار تنبيهات مبكرة
 * عن التصعيد المحتمل بناءً على أنماط الحرب المركبة
 * 
 * @package Beiruttime\OSINT\Services
 * @version 2.0.0
 */

namespace Beiruttime\OSINT\Services;

use Beiruttime\OSINT\Traits\Singleton;

class EarlyWarningSystem {

    use Singleton;

    /**
     * مستويات الإنذار
     */
    const ALERT_GREEN = 'green';      // وضع طبيعي
    const ALERT_YELLOW = 'yellow';    // يقوي متزايد
    const ALERT_ORANGE = 'orange';    // تهديد محتمل
    const ALERT_RED = 'red';          // تهديد وشيك
    const ALERT_CRITICAL = 'critical'; // أزمة فعالة

    /**
     * مؤشرات الإنذار المبكر
     */
    private $warningIndicators = [
        'military_buildup' => [
            'name_ar' => 'تحشيد عسكري',
            'weight' => 1.0,
            'keywords' => ['تحشيد', 'تعزيزات', 'نشر قوات', 'تحركات عسكرية', 'تركيز', 'تجمع']
        ],
        'escalating_rhetoric' => [
            'name_ar' => 'خطاب تصعيدي',
            'weight' => 0.8,
            'keywords' => ['تهديد', 'وعيد', 'رد حاسم', 'عاصفة', 'تدمير', 'اجتياح', 'قصف']
        ],
        'diplomatic_breakdown' => [
            'name_ar' => 'انهيار دبلوماسي',
            'weight' => 0.9,
            'keywords' => ['سحب سفير', 'قطع علاقات', 'إغلاق قنصلية', 'طرد دبلوماسي', 'تجميد مفاوضات']
        ],
        'economic_pressure' => [
            'name_ar' => 'ضغط اقتصادي',
            'weight' => 0.7,
            'keywords' => ['عقوبات جديدة', 'حظر', 'قيود', 'تجميد أصول', 'منع تحويلات']
        ],
        'cyber_activity' => [
            'name_ar' => 'نشاط سيبراني مشبوه',
            'weight' => 0.85,
            'keywords' => ['اختراق', 'هجوم سيبراني', 'تعطيل', 'تشويش', 'قرصنة']
        ],
        'media_campaign' => [
            'name_ar' => 'حملة إعلامية ممهدة',
            'weight' => 0.6,
            'keywords' => ['حملة', 'تضليل', 'دعاية', 'تحريض', 'تعبئة', 'بث ذعر']
        ],
        'evacuation_orders' => [
            'name_ar' => 'أوامر إخلاء',
            'weight' => 0.95,
            'keywords' => ['إخلاء', 'إجلاء', 'مغادرة', 'نزوح', 'فرار', 'تهجير']
        ],
        'air_defense_activation' => [
            'name_ar' => 'تفعيل دفاعات جوية',
            'weight' => 0.9,
            'keywords' => ['دفاع جوي', 'باتريوت', 'حديدة قبضة', 'اعتراض', 'جاهزية قصوى']
        ],
        'naval_movement' => [
            'name_ar' => 'تحركات بحرية',
            'weight' => 0.85,
            'keywords' => ['حاملة', 'أسطول', 'سفينة حربية', 'غواصة', 'مناورة بحرية', 'انتشار بحري']
        ],
        'intelligence_alert' => [
            'name_ar' => 'تنبيه استخباراتي',
            'weight' => 0.95,
            'keywords' => ['معلومات استخباراتية', 'تحذير أمني', 'رفع جاهزية', 'إنذار مسبق']
        ]
    ];

    /**
     * تحليل مجموعة أحداث للكشف عن مؤشرات الإنذار
     * 
     * @param array $events مصفوفة الأحداث
     * @return array تحليل الإنذار المبكر
     */
    public function analyzeEventsForWarnings(array $events): array {
        $indicators = [];
        $totalScore = 0;
        $activeIndicators = 0;
        
        foreach ($this->warningIndicators as $key => $indicator) {
            $detections = $this->detectIndicator($events, $indicator);
            
            if (!empty($detections)) {
                $score = count($detections) * $indicator['weight'];
                $indicators[$key] = [
                    'name_ar' => $indicator['name_ar'],
                    'count' => count($detections),
                    'score' => round($score, 2),
                    'weight' => $indicator['weight'],
                    'events' => array_slice($detections, 0, 5) // أول 5 أحداث فقط
                ];
                $totalScore += $score;
                $activeIndicators++;
            }
        }
        
        // حساب مستوى الإنذار
        $alertLevel = $this->calculateAlertLevel($totalScore, $activeIndicators);
        
        // توليد السيناريوهات المحتملة
        $scenarios = $this->generateScenarios($indicators, $alertLevel);
        
        // التوصيات
        $recommendations = $this->generateRecommendations($indicators, $alertLevel);
        
        return [
            'alert_level' => $alertLevel,
            'alert_level_ar' => $this->getAlertLevelName($alertLevel),
            'total_score' => round($totalScore, 2),
            'active_indicators_count' => $activeIndicators,
            'indicators' => $indicators,
            'scenarios' => $scenarios,
            'recommendations' => $recommendations,
            'timestamp' => current_time('mysql')
        ];
    }

    /**
     * كشف مؤشر معين في الأحداث
     */
    private function detectIndicator(array $events, array $indicator): array {
        $detections = [];
        
        foreach ($events as $event) {
            $text = $this->extractEventText($event);
            $matches = $this->findKeywordMatches($text, $indicator['keywords']);
            
            if (!empty($matches)) {
                $detections[] = [
                    'event_id' => $event['id'] ?? null,
                    'title' => $event['title'] ?? '',
                    'matches' => $matches,
                    'time' => $event['publish_time'] ?? $event['created_at'] ?? null
                ];
            }
        }
        
        return $detections;
    }

    /**
     * استخراج نص الحدث
     */
    private function extractEventText(array $event): string {
        $parts = [];
        foreach (['title', 'description', 'content', 'summary'] as $field) {
            if (!empty($event[$field]) && is_string($event[$field])) {
                $parts[] = $event[$field];
            }
        }
        return implode(' ', $parts);
    }

    /**
     * العثور على مطابقات الكلمات المفتاحية
     */
    private function findKeywordMatches(string $text, array $keywords): array {
        $matches = [];
        $textLower = mb_strtolower($text, 'UTF-8');
        
        foreach ($keywords as $keyword) {
            $keywordLower = mb_strtolower($keyword, 'UTF-8');
            if (mb_strpos($textLower, $keywordLower) !== false) {
                $matches[] = $keyword;
            }
        }
        
        return $matches;
    }

    /**
     * حساب مستوى الإنذار
     */
    private function calculateAlertLevel(float $totalScore, int $activeIndicators): string {
        // معادلة حساب مستوى الإنذار
        $normalizedScore = min(100, $totalScore);
        $indicatorBonus = min(30, $activeIndicators * 5);
        $finalScore = $normalizedScore + $indicatorBonus;
        
        if ($finalScore >= 80) {
            return self::ALERT_CRITICAL;
        } elseif ($finalScore >= 60) {
            return self::ALERT_RED;
        } elseif ($finalScore >= 40) {
            return self::ALERT_ORANGE;
        } elseif ($finalScore >= 20) {
            return self::ALERT_YELLOW;
        } else {
            return self::ALERT_GREEN;
        }
    }

    /**
     * توليد السيناريوهات المحتملة
     */
    private function generateScenarios(array $indicators, string $alertLevel): array {
        $scenarios = [];
        
        // سيناريو التصعيد العسكري
        if (isset($indicators['military_buildup']) && isset($indicators['escalating_rhetoric'])) {
            $probability = $alertLevel === self::ALERT_CRITICAL ? 85 : 
                          ($alertLevel === self::ALERT_RED ? 70 : 50);
            
            $scenarios[] = [
                'id' => 'military_escalation',
                'title' => 'تصعيد عسكري وشيك',
                'description' => 'التحشيد العسكري مع الخطاب التصعيدي يشير إلى احتمال عملية عسكرية خلال 72 ساعة',
                'probability' => $probability,
                'timeframe' => '24-72 ساعة',
                'indicators' => ['military_buildup', 'escalating_rhetoric']
            ];
        }
        
        // سيناريو الضربة الاستباقية
        if (isset($indicators['air_defense_activation']) && isset($indicators['evacuation_orders'])) {
            $scenarios[] = [
                'id' => 'preemptive_strike',
                'title' => 'ضربة استباقية محتملة',
                'description' => 'تفعيل الدفاعات الجوية وأوامر الإخلاء قد تسبق ضربة استباقية',
                'probability' => 65,
                'timeframe' => '12-48 ساعة',
                'indicators' => ['air_defense_activation', 'evacuation_orders']
            ];
        }
        
        // سيناريو الحرب السيبرانية
        if (isset($indicators['cyber_activity']) && isset($indicators['media_campaign'])) {
            $scenarios[] = [
                'id' => 'cyber_warfare',
                'title' => 'هجوم سيبراني واسع النطاق',
                'description' => 'النشاط السيبراني مع الحملة الإعلامية قد يمهد لهجوم إلكتروني كبير',
                'probability' => 60,
                'timeframe' => '24-96 ساعة',
                'indicators' => ['cyber_activity', 'media_campaign']
            ];
        }
        
        // سيناريو الأزمة الدبلوماسية
        if (isset($indicators['diplomatic_breakdown'])) {
            $scenarios[] = [
                'id' => 'diplomatic_crisis',
                'title' => 'أزمة دبلوماسية حادة',
                'description' => 'الانهيار الدبلوماسي قد يؤدي إلى قطع العلاقات أو مواجهة غير مباشرة',
                'probability' => 75,
                'timeframe' => '48-168 ساعة',
                'indicators' => ['diplomatic_breakdown']
            ];
        }
        
        // سيناريو الضغط الاقتصادي
        if (isset($indicators['economic_pressure']) && isset($indicators['naval_movement'])) {
            $scenarios[] = [
                'id' => 'economic_blockade',
                'title' => 'حصار اقتصادي/بحري',
                'description' => 'الضغط الاقتصادي مع التحركات البحرية قد يشير إلى حصار أو تقييد ملاحة',
                'probability' => 55,
                'timeframe' => '72-168 ساعة',
                'indicators' => ['economic_pressure', 'naval_movement']
            ];
        }
        
        return $scenarios;
    }

    /**
     * توليد التوصيات
     */
    private function generateRecommendations(array $indicators, string $alertLevel): array {
        $recommendations = [];
        
        // توصيات عامة حسب مستوى الإنذار
        switch ($alertLevel) {
            case self::ALERT_CRITICAL:
                $recommendations[] = [
                    'priority' => 'critical',
                    'action' => 'تفعيل غرفة العمليات فوراً',
                    'details' => 'رفع مستوى الجاهزية القصوى ومتابعة لحظية'
                ];
                break;
                
            case self::ALERT_RED:
                $recommendations[] = [
                    'priority' => 'high',
                    'action' => 'زيادة وتيرة الرصد',
                    'details' => 'متابعة كل 15 دقيقة وتحديث مستمر'
                ];
                break;
                
            case self::ALERT_ORANGE:
                $recommendations[] = [
                    'priority' => 'medium',
                    'action' => 'تكثيف جمع المعلومات',
                    'details' => 'مضاعفة مصادر الرصد والتحقق'
                ];
                break;
                
            case self::ALERT_YELLOW:
                $recommendations[] = [
                    'priority' => 'low',
                    'action' => 'مراقبة عادية مع انتباه',
                    'details' => 'متابعة روتينية مع تسجيل الملاحظات'
                ];
                break;
        }
        
        // توصيات خاصة بالمؤشرات النشطة
        if (isset($indicators['military_buildup'])) {
            $recommendations[] = [
                'priority' => 'high',
                'action' => 'رصد التحركات العسكرية',
                'details' => 'تتبع تحركات القوات والمعدات عبر الصور والأقمار الصناعية'
            ];
        }
        
        if (isset($indicators['cyber_activity'])) {
            $recommendations[] = [
                'priority' => 'high',
                'action' => 'تأمين البنية الرقمية',
                'details' => 'رفع مستوى الحماية السيبرانية ومراقبة الهجمات'
            ];
        }
        
        if (isset($indicators['evacuation_orders'])) {
            $recommendations[] = [
                'priority' => 'critical',
                'action' => 'رصد حركات النزوح',
                'details' => 'توثيق أوامر الإخلاء وحركات السكان'
            ];
        }
        
        return $recommendations;
    }

    /**
     * الحصول على اسم مستوى الإنذار بالعربية
     */
    private function getAlertLevelName(string $level): string {
        $names = [
            self::ALERT_GREEN => 'أخضر - وضع طبيعي',
            self::ALERT_YELLOW => 'أصفر - يقظة متزايدة',
            self::ALERT_ORANGE => 'برتقالي - تهديد محتمل',
            self::ALERT_RED => 'أحمر - تهديد وشيك',
            self::ALERT_CRITICAL => 'حرج - أزمة فعالة'
        ];
        
        return $names[$level] ?? 'غير معروف';
    }

    /**
     * الحصول على لون مستوى الإنذار
     */
    public function getAlertColor(string $level): string {
        $colors = [
            self::ALERT_GREEN => '#22c55e',
            self::ALERT_YELLOW => '#eab308',
            self::ALERT_ORANGE => '#f97316',
            self::ALERT_RED => '#ef4444',
            self::ALERT_CRITICAL => '#7c2d12'
        ];
        
        return $colors[$level] ?? '#6b7280';
    }

    /**
     * الحصول على أيقونة مستوى الإنذار
     */
    public function getAlertIcon(string $level): string {
        $icons = [
            self::ALERT_GREEN => '🟢',
            self::ALERT_YELLOW => '🟡',
            self::ALERT_ORANGE => '🟠',
            self::ALERT_RED => '🔴',
            self::ALERT_CRITICAL => '⚫'
        ];
        
        return $icons[$level] ?? '⚪';
    }
}
