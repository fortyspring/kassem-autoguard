<?php
/**
 * محرك تحليل الحرب المركبة - Hybrid Warfare Analysis Engine
 * 
 * يقوم بتحليل الأحداث ضمن طبقات الحرب المركبة التسع:
 * 1. العسكرية
 * 2. الأمنية
 * 3. السيبرانية
 * 4. السياسية
 * 5. الاقتصادية
 * 6. الاجتماعية
 * 7. الإعلامية والنفسية
 * 8. الطاقة
 * 9. الجيوستراتيجية
 * 
 * @package Beiruttime\OSINT\Services
 * @version 2.0.0
 */

namespace Beiruttime\OSINT\Services;

use Beiruttime\OSINT\Traits\Singleton;

class HybridWarfareEngine {

    use Singleton;

    /**
     * اختصار لـ getInstance() لتوافق الكود القديم
     * @return self
     */
    public static function instance() {
        return self::getInstance();
    }

    /**
     * طبقات الحرب المركبة التسع
     */
    private $hybridLayers = [
        'military' => [
            'id' => 'L1',
            'name_ar' => 'الطبقة العسكرية',
            'keywords' => [
                'غارة', 'قصف', 'صاروخ', 'اشتباك', 'توغل', 'انسحاب', 'كمين',
                'اغتيال', 'مساءرة', 'اعتراض', 'تحركات', 'منظومة', 'مناورة',
                'تموضع', 'تحشيد', 'ضربة', 'رد', 'قاعدة', 'مستودع', 'قيادة',
                'دبابة', 'طائرة', 'مروحية', 'سفينة', 'غواصة', 'لواء', 'فوج',
                'كتيبة', 'سرية', 'فصيلة', 'جندي', 'ضابط', 'جنرال', 'أدميرال'
            ],
            'weight' => 1.0
        ],
        'security' => [
            'id' => 'L2',
            'name_ar' => 'الطبقة الأمنية',
            'keywords' => [
                'اعتقال', 'مداهمة', 'تفكيك', 'خلية', 'إعلان أمني', 'تحذير',
                'إنذار', 'حاجز', 'إقفال', 'جاهزية', 'تجسس', 'تسلل', 'شحنة',
                'منشأة', 'اضطراب', 'أمن داخلي', 'استخبارات', 'مكافحة', 'رصد'
            ],
            'weight' => 0.9
        ],
        'cyber' => [
            'id' => 'L3',
            'name_ar' => 'الطبقة السيبرانية',
            'keywords' => [
                'اختراق', 'سيبراني', 'تعطيل', 'تسريب', 'بيانات', 'قرصنة',
                'حجب', 'خدمة', 'بنية تحتية', 'اتصالات', 'منصة', 'حكومة',
                'طاقة', 'بنك', 'مجموعة', 'ذكاء اصطناعي', 'تضليل', 'تشويه',
                'حسابات', 'منسقة', 'هجوم إلكتروني', 'فيروس', 'برمجية خبيثة'
            ],
            'weight' => 0.95
        ],
        'political' => [
            'id' => 'L4',
            'name_ar' => 'الطبقة السياسية',
            'keywords' => [
                'تصريح', 'قرار', 'عقوبة', 'اتفاق', 'زيارة', 'وفد', 'تهديد',
                'سفير', 'منظمة', 'مجلس أمن', 'تفاهم', 'رسالة', 'موقف', 'تسوية',
                'انسداد', 'ملف', 'إقليمي', 'دولي', 'وزارة', 'خارجية', 'رئيس',
                'حكومة', 'برلمان', 'انتخاب', 'ديمقراطية', 'شرعية'
            ],
            'weight' => 0.85
        ],
        'economic' => [
            'id' => 'L5',
            'name_ar' => 'الطبقة الاقتصادية',
            'keywords' => [
                'عقوبة مالية', 'نفط', 'غاز', 'ميناء', 'إمداد', 'تجارة', 'شحن',
                'طيران', 'وقود', 'سلسلة توريد', 'صرف', 'سوق', 'تحويل', 'تصدير',
                'استيراد', 'اقتصاد', 'عملة', 'تضخم', 'ركود', 'استثمار', 'مال',
                'بنك مركزي', 'صندوق', 'قرض', 'دين', 'ميزانية'
            ],
            'weight' => 0.8
        ],
        'social' => [
            'id' => 'L6',
            'name_ar' => 'الطبقة الاجتماعية',
            'keywords' => [
                'احتجاج', 'فوضى', 'تحريض', 'تعبئة', 'رأي عام', 'إشاعة', 'ذعر',
                'رعب', 'طمأنة', 'جمهور', 'شرعنة', 'معنوي', 'مشاعر', 'خطاب',
                'رمزي', 'وسم', 'هاشتاغ', 'فيديو', 'جماهيري', 'شارع', 'مجتمع',
                'طائفي', 'مذهبي', 'اجتماعي', 'معيشي', 'أزمة'
            ],
            'weight' => 0.75
        ],
        'media_psychological' => [
            'id' => 'L7',
            'name_ar' => 'الطبقة الإعلامية والنفسية',
            'keywords' => [
                'دعاية', 'تضليل', 'حرب نفسية', 'خوف', 'حملة', 'تشويه', 'تلاعب',
                'معنويات', 'إدراك', 'رواية', 'صناعة', 'كسر', 'إرباك', 'بث',
                'إعلام', 'قناة', 'صحيفة', 'وكالة', 'موقع', 'منشور', 'بيان',
                'مؤتمر صحفي', 'تصريح إعلامي', 'تحليل', 'تقرير'
            ],
            'weight' => 0.8
        ],
        'energy' => [
            'id' => 'L8',
            'name_ar' => 'طبقة الطاقة',
            'keywords' => [
                'نفط', 'غاز', 'مصفاة', 'كهرباء', 'محطة', 'شبكة', 'ماء', 'سد',
                'أنبوب', 'خط', 'طاقة', 'وقود', 'ديزل', 'بنزين', 'مازوت',
                'مولد', 'بطارية', 'شمسي', 'رياح', 'نووي', 'ذري', 'مفاعل',
                'خزان', 'ناقلة', 'صهريج', 'مستودع وقود'
            ],
            'weight' => 0.9
        ],
        'geostrategic' => [
            'id' => 'L9',
            'name_ar' => 'الطبقة الجيوستراتيجية',
            'keywords' => [
                'مضيق', 'ممر', 'هرمز', 'مندب', 'سويس', 'ملاحة', 'تجاري',
                'ميناء', 'قاعدة', 'مجال جوي', 'نفوذ', 'بحري', 'حدود', 'نقطة',
                'اختناق', 'عبور', 'مسار', 'عسكري', 'حساس', 'استراتيجي',
                'جغرافي', 'إقليم', 'منطقة', 'نفوذ', 'سيادة', ' waters'
            ],
            'weight' => 0.95
        ]
    ];

    /**
     * تحليل حدث ضمن طبقات الحرب المركبة
     * 
     * @param array $event بيانات الحدث
     * @return array تحليل الطبقات
     */
    public function analyzeLayers(array $event): array {
        $text = $this->extractText($event);
        $layers = [];
        $totalScore = 0;
        
        foreach ($this->hybridLayers as $layerKey => $layerData) {
            $score = $this->calculateLayerScore($text, $layerData);
            $score = $this->applyContextualBoosts($layerKey, $score, $text, $event);
            if ($score > 0.03) {
                $layers[$layerKey] = [
                    'id' => $layerData['id'],
                    'name_ar' => $layerData['name_ar'],
                    'score' => round(min(1.0, $score), 3),
                    'matches' => $this->findMatches($text, $layerData['keywords']),
                    'weight' => $layerData['weight']
                ];
                $totalScore += $score * $layerData['weight'];
            }
        }
        
        // حساب درجة التركيب المركب
        $compositeScore = count($layers) > 1 ? min(1.0, $totalScore / count($layers)) : 0;
        
        return [
            'layers' => $layers,
            'active_layers_count' => count($layers),
            'is_hybrid' => count($layers) >= 2,
            'composite_score' => round($compositeScore, 3),
            'primary_layer' => $this->getPrimaryLayer($layers),
            'layer_combinations' => $this->analyzeCombinations($layers)
        ];
    }

    /**
     * استخراج النصوص من الحدث
     */
    private function extractText(array $event): string {
        $parts = [];
        foreach (['title', 'description', 'content', 'summary', 'source', 'actor', 'actor_v2', 'region', 'intel_type', 'tactical_level'] as $field) {
            if (!empty($event[$field]) && is_string($event[$field])) {
                $parts[] = $event[$field];
            }
        }
        return implode(' ', $parts);
    }

    /**
     * رفع ذكي لدرجات بعض الطبقات بحسب السياق
     */
    private function applyContextualBoosts(string $layerKey, float $score, string $text, array $event): float {
        $textLower = mb_strtolower($text, 'UTF-8');
        $actor = mb_strtolower((string)($event['actor'] ?? $event['actor_v2'] ?? ''), 'UTF-8');
        $region = mb_strtolower((string)($event['region'] ?? ''), 'UTF-8');

        $has = function(array $terms) use ($textLower): bool {
            foreach ($terms as $term) {
                if (mb_strpos($textLower, mb_strtolower($term, 'UTF-8')) !== false) {
                    return true;
                }
            }
            return false;
        };

        if ($layerKey === 'military' && $has(['غارة','قصف','استهداف','صاروخ','مسيرة','توغل','اعتراض'])) {
            $score += 0.18;
        }
        if ($layerKey === 'security' && $has(['إنذار','تحذير','اعتقال','مخابرات','خلية','تسلل'])) {
            $score += 0.14;
        }
        if ($layerKey === 'political' && $has(['الخارجية','وزارة','مباحثات','سفير','تصريح','دعم بكين','مجلس الأمن'])) {
            $score += 0.16;
        }
        if ($layerKey === 'economic' && $has(['عقوبات','نفط','غاز','مصفاة','تجارة','ميناء','تصدير','استيراد'])) {
            $score += 0.14;
        }
        if ($layerKey === 'media_psychological' && $has(['الإعلام الحربي','بيان','رواية','تحريض','حرب نفسية','رسالة','منشور'])) {
            $score += 0.15;
        }
        if ($layerKey === 'energy' && $has(['محطة','كهرباء','مصفاة','مفاعل','وقود','خط الغاز','خزان'])) {
            $score += 0.12;
        }
        if ($layerKey === 'geostrategic' && $has(['هرمز','باب المندب','المجال الجوي','قاعدة','حدود','ممر','مضيق'])) {
            $score += 0.17;
        }

        if ($region !== '' && ($region === 'لبنان' || $region === 'فلسطين' || $region === 'إيران' || $region === 'سوريا')) {
            $score += 0.03;
        }
        if ($actor !== '' && ($actor === 'جيش العدو الإسرائيلي' || $actor === 'إيران' || mb_strpos($actor, 'حزب الله') !== false)) {
            $score += 0.03;
        }

        return min(1.0, $score);
    }

    /**
     * حساب درجة مطابقة الطبقة
     */
    private function calculateLayerScore(string $text, array $layerData): float {
        $matches = $this->findMatches($text, $layerData['keywords']);
        if (empty($matches)) return 0.0;
        
        $baseScore = count($matches) / max(1, count($layerData['keywords']));
        $densityScore = min(1.0, count($matches) / max(1, strlen($text) / 50));
        
        return ($baseScore * 0.6 + $densityScore * 0.4) * $layerData['weight'];
    }

    /**
     * العثور على الكلمات المفتاحية المطابقة
     */
    private function findMatches(string $text, array $keywords): array {
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
     * تحديد الطبقة الأساسية
     */
    private function getPrimaryLayer(array $layers): ?string {
        if (empty($layers)) return null;
        
        $maxScore = 0;
        $primary = null;
        
        foreach ($layers as $key => $data) {
            $weightedScore = $data['score'] * $data['weight'];
            if ($weightedScore > $maxScore) {
                $maxScore = $weightedScore;
                $primary = $key;
            }
        }
        
        return $primary;
    }

    /**
     * تحليل توليفات الطبقات
     */
    private function analyzeCombinations(array $layers): array {
        $combinations = [];
        $layerKeys = array_keys($layers);
        
        // تحليل التوليفات الثنائية
        for ($i = 0; $i < count($layerKeys); $i++) {
            for ($j = $i + 1; $j < count($layerKeys); $j++) {
                $combo = [$layerKeys[$i], $layerKeys[$j]];
                $comboKey = implode('_', $combo);
                
                $patterns = $this->getCombinationPattern($combo);
                if ($patterns) {
                    $combinations[$comboKey] = [
                        'layers' => $combo,
                        'pattern' => $patterns['name'],
                        'threat_level' => $patterns['threat'],
                        'description' => $patterns['desc']
                    ];
                }
            }
        }
        
        return $combinations;
    }

    /**
     * الحصول على نمط التوليفة
     */
    private function getCombinationPattern(array $combo): ?array {
        if (empty($combo)) {
            return null;
        }
        
        // ترتيب المصفوفة لإنشاء المفتاح
        $sortedCombo = $combo;
        sort($sortedCombo);
        $key = implode('-', $sortedCombo);
        
        $patterns = [
            'energy-military' => [
                'name' => 'استهداف البنية التحتية الحيوية',
                'threat' => 'critical',
                'desc' => 'هجوم عسكري على منشآت الطاقة يشير إلى استراتيجية شل القدرات'
            ],
            'military-political' => [
                'name' => 'ضغط عسكري-سياسي متكامل',
                'threat' => 'high',
                'desc' => 'تنسيق بين الفعل الميداني والضغط الدبلوماسي'
            ],
            'cyber-military' => [
                'name' => 'حرب هجينة رقمية-عسكرية',
                'threat' => 'high',
                'desc' => 'هجمات سيبرانية تمهد أو ترافق عمليات عسكرية'
            ],
            'economic-political' => [
                'name' => 'حصار اقتصادي-سياسي',
                'threat' => 'high',
                'desc' => 'استخدام العقوبات والضغوط الدبلوماسية كأداة حرب'
            ],
            'media_psychological-social' => [
                'name' => 'حرب نفسية-اجتماعية',
                'threat' => 'medium',
                'desc' => 'استهداف المعنويات والرأي العام عبر الإعلام'
            ],
            'geostrategic-economic' => [
                'name' => 'سيطرة على الممرات الاستراتيجية',
                'threat' => 'high',
                'desc' => 'التحكم في طرق التجارة والإمداد العالمية'
            ],
            'security-military' => [
                'name' => 'عملية أمنية-عسكرية مشتركة',
                'threat' => 'medium',
                'desc' => 'تنسيق بين الأجهزة الأمنية والقوات العسكرية'
            ]
        ];
        
        foreach ($patterns as $patternCombo => $patternData) {
            if ($patternCombo === $key) {
                return $patternData;
            }
        }
        
        return null;
    }

    /**
     * الحصول على جميع الطبقات
     */
    public function getAllLayers(): array {
        return $this->hybridLayers;
    }

    /**
     * الحصول على اسم الطبقة بالعربية
     */
    public function getLayerName(string $layerKey): string {
        return $this->hybridLayers[$layerKey]['name_ar'] ?? $layerKey;
    }
}
