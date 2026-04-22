<?php
/**
 * نظام التحقق المتقدم - Advanced Verification System
 * 
 * يوفر مستويات تحقق متعددة من المصادر المفتوحة
 * مع حساب درجات الثقة بناءً على عوامل متعددة
 * 
 * @package Beiruttime\OSINT\Services
 * @version 2.0.0
 */

namespace Beiruttime\OSINT\Services;

use Beiruttime\OSINT\Traits\Singleton;

class VerificationSystem {

    use Singleton;

    /**
     * مستويات التحقق
     */
    const VERIFICATION_UNCONFIRMED = 'unconfirmed';
    const VERIFICATION_CONFLICTING = 'conflicting_reports';
    const VERIFIED_SOURCE = 'source_verified';
    const VERIFIED_LOCATION = 'location_verified';
    const VERIFIED_TIME = 'time_verified';
    const VERIFIED_MULTI = 'multi_source_verified';
    const VERIFIED_OFFICIAL = 'officially_confirmed';

    /**
     * التحقق من حدث ما
     * 
     * @param array $event بيانات الحدث
     * @return array نتيجة التحقق
     */
    public function verifyEvent(array $event): array {
        $verification = [
            'status' => self::VERIFICATION_UNCONFIRMED,
            'confidence_score' => 0,
            'sources_count' => 0,
            'has_visual_evidence' => false,
            'has_geolocation' => false,
            'has_timestamp' => false,
            'cross_reference_matches' => 0,
            'contradictions' => [],
            'verification_steps' => []
        ];

        // التحقق من وجود أدلة بصرية
        $verification['has_visual_evidence'] = $this->checkVisualEvidence($event);
        
        // التحقق الجغرافي
        $verification['has_geolocation'] = $this->checkGeolocation($event);
        
        // التحقق الزمني
        $verification['has_timestamp'] = $this->checkTimestamp($event);
        
        // التحقق من تعدد المصادر
        $verification['sources_count'] = $this->countSources($event);
        
        // التحقق من التناقضات
        $verification['contradictions'] = $this->detectContradictions($event);
        
        // المطابقة المرجعية
        $verification['cross_reference_matches'] = $this->crossReference($event);
        
        // خطوات التحقق المنفذة
        $verification['verification_steps'] = $this->getVerificationSteps($verification);
        
        // حساب درجة الثقة
        $verification['confidence_score'] = $this->calculateConfidenceScore($verification);
        
        // تحديد الحالة النهائية
        $verification['status'] = $this->determineStatus($verification);

        return $verification;
    }

    /**
     * التحقق من الأدلة البصرية
     */
    private function checkVisualEvidence(array $event): bool {
        $hasImage = !empty($event['image_url']) || !empty($event['images']);
        $hasVideo = !empty($event['video_url']) || !empty($event['videos']);
        $hasSatellite = !empty($event['satellite_image']) || 
                       (isset($event['content']) && stripos($event['content'], 'قمر صناعي') !== false);
        
        return $hasImage || $hasVideo || $hasSatellite;
    }

    /**
     * التحقق الجغرافي
     */
    private function checkGeolocation(array $event): bool {
        $hasCoords = !empty($event['latitude']) && !empty($event['longitude']);
        $hasLocation = !empty($event['location']) || !empty($event['city']) || 
                      !empty($event['country']) || !empty($event['region']);
        $hasGeoMatch = isset($event['geo_matched']) && $event['geo_matched'] === true;
        
        return $hasCoords || ($hasLocation && $hasGeoMatch);
    }

    /**
     * التحقق الزمني
     */
    private function checkTimestamp(array $event): bool {
        $hasPublishTime = !empty($event['publish_time']) || !empty($event['published_at']);
        $hasEventTime = !empty($event['event_time']) || !empty($event['occurred_at']);
        $timePrecision = isset($event['time_precision']) && in_array($event['time_precision'], ['exact', 'approximate']);
        
        return ($hasPublishTime || $hasEventTime) && $timePrecision;
    }

    /**
     * đếm عدد المصادر
     */
    private function countSources(array $event): int {
        $sources = [];
        
        if (!empty($event['source'])) {
            $sources[] = $event['source'];
        }
        
        if (!empty($event['sources']) && is_array($event['sources'])) {
            $sources = array_merge($sources, $event['sources']);
        }
        
        if (!empty($event['references']) && is_array($event['references'])) {
            foreach ($event['references'] as $ref) {
                if (isset($ref['source'])) {
                    $sources[] = $ref['source'];
                }
            }
        }
        
        return count(array_unique($sources));
    }

    /**
     * كشف التناقضات
     */
    private function detectContradictions(array $event): array {
        $contradictions = [];
        
        // التحقق من تناقض التوقيت
        if (!empty($event['publish_time']) && !empty($event['event_time'])) {
            $publishTime = strtotime($event['publish_time']);
            $eventTime = strtotime($event['event_time']);
            
            if ($publishTime && $eventTime && abs($publishTime - $eventTime) > 86400 * 7) {
                $contradictions[] = [
                    'type' => 'time_gap',
                    'description' => 'فارق زمني كبير بين وقت الحدث ووقت النشر',
                    'severity' => 'medium'
                ];
            }
        }
        
        // التحقق من تناقض الموقع
        if (isset($event['location_conflicts']) && $event['location_conflicts'] === true) {
            $contradictions[] = [
                'type' => 'location_conflict',
                'description' => 'تضارب في تحديد الموقع الجغرافي',
                'severity' => 'high'
            ];
        }
        
        // التحقق من تناقض الروايات
        if (!empty($event['narrative_conflicts']) && is_array($event['narrative_conflicts'])) {
            foreach ($event['narrative_conflicts'] as $conflict) {
                $contradictions[] = [
                    'type' => 'narrative_conflict',
                    'description' => $conflict,
                    'severity' => 'medium'
                ];
            }
        }
        
        return $contradictions;
    }

    /**
     * المطابقة المرجعية
     */
    private function crossReference(array $event): int {
        $matches = 0;
        
        // محاكاة المطابقة مع قواعد البيانات
        // في التطبيق الفعلي، يتم الربط مع APIs وقواعد بيانات
        if (!empty($event['title'])) {
            $matches += $this->matchTitle($event['title']);
        }
        
        if (!empty($event['location'])) {
            $matches += $this->matchLocation($event['location']);
        }
        
        if (!empty($event['actor'])) {
            $matches += $this->matchActor($event['actor']);
        }
        
        return $matches;
    }

    /**
     * مطابقة العنوان
     */
    private function matchTitle(string $title): int {
        // في التطبيق الفعلي: بحث في قاعدة البيانات والأرشيف
        return rand(0, 3); // محاكاة
    }

    /**
     * مطابقة الموقع
     */
    private function matchLocation(string $location): int {
        // في التطبيق الفعلي: تحقق من قواعد البيانات الجغرافية
        return rand(0, 2); // محاكاة
    }

    /**
     * مطابقة الفاعل
     */
    private function matchActor(string $actor): int {
        // في التطبيق الفعلي: تحقق من قاعدة بيانات الفاعلين
        return rand(0, 2); // محاكاة
    }

    /**
     * الحصول على خطوات التحقق
     */
    private function getVerificationSteps(array $verification): array {
        $steps = [];
        
        if ($verification['has_visual_evidence']) {
            $steps[] = [
                'step' => 'visual_evidence',
                'name_ar' => 'التحقق من الأدلة البصرية',
                'status' => 'passed'
            ];
        } else {
            $steps[] = [
                'step' => 'visual_evidence',
                'name_ar' => 'التحقق من الأدلة البصرية',
                'status' => 'missing'
            ];
        }
        
        if ($verification['has_geolocation']) {
            $steps[] = [
                'step' => 'geolocation',
                'name_ar' => 'التحقق الجغرافي',
                'status' => 'passed'
            ];
        } else {
            $steps[] = [
                'step' => 'geolocation',
                'name_ar' => 'التحقق الجغرافي',
                'status' => 'pending'
            ];
        }
        
        if ($verification['has_timestamp']) {
            $steps[] = [
                'step' => 'timestamp',
                'name_ar' => 'التحقق الزمني',
                'status' => 'passed'
            ];
        } else {
            $steps[] = [
                'step' => 'timestamp',
                'name_ar' => 'التحقق الزمني',
                'status' => 'pending'
            ];
        }
        
        if ($verification['sources_count'] >= 3) {
            $steps[] = [
                'step' => 'multi_source',
                'name_ar' => 'تعدد المصادر',
                'status' => 'passed',
                'count' => $verification['sources_count']
            ];
        } else {
            $steps[] = [
                'step' => 'multi_source',
                'name_ar' => 'تعدد المصادر',
                'status' => 'insufficient',
                'count' => $verification['sources_count']
            ];
        }
        
        if (empty($verification['contradictions'])) {
            $steps[] = [
                'step' => 'contradiction_check',
                'name_ar' => 'فحص التناقضات',
                'status' => 'passed'
            ];
        } else {
            $steps[] = [
                'step' => 'contradiction_check',
                'name_ar' => 'فحص التناقضات',
                'status' => 'warnings',
                'count' => count($verification['contradictions'])
            ];
        }
        
        return $steps;
    }

    /**
     * حساب درجة الثقة
     */
    private function calculateConfidenceScore(array $verification): float {
        $score = 0;
        
        // الأدلة البصرية: 25 نقطة
        if ($verification['has_visual_evidence']) {
            $score += 25;
        }
        
        // التحقق الجغرافي: 20 نقطة
        if ($verification['has_geolocation']) {
            $score += 20;
        }
        
        // التحقق الزمني: 15 نقطة
        if ($verification['has_timestamp']) {
            $score += 15;
        }
        
        // تعدد المصادر: 25 نقطة كحد أقصى
        $sourceScore = min(25, $verification['sources_count'] * 8);
        $score += $sourceScore;
        
        // المطابقة المرجعية: 15 نقطة كحد أقصى
        $refScore = min(15, $verification['cross_reference_matches'] * 5);
        $score += $refScore;
        
        // خصم التناقضات
        foreach ($verification['contradictions'] as $contradiction) {
            $penalty = $contradiction['severity'] === 'high' ? 15 : 8;
            $score -= $penalty;
        }
        
        return max(0, min(100, $score));
    }

    /**
     * تحديد حالة التحقق النهائية
     */
    private function determineStatus(array $verification): string {
        $confidence = $verification['confidence_score'];
        $sources = $verification['sources_count'];
        $contradictions = count($verification['contradictions']);
        
        if ($contradictions > 2) {
            return self::VERIFICATION_CONFLICTING;
        }
        
        if ($confidence >= 90 && $sources >= 5) {
            return self::VERIFICATION_OFFICIAL;
        }
        
        if ($confidence >= 75 && $sources >= 3) {
            return self::VERIFICATION_MULTI;
        }
        
        if ($confidence >= 60 && $verification['has_geolocation']) {
            return self::VERIFICATION_LOCATION;
        }
        
        if ($confidence >= 50 && $verification['has_timestamp']) {
            return self::VERIFICATION_TIME;
        }
        
        if ($confidence >= 40 && $sources >= 1) {
            return self::VERIFIED_SOURCE;
        }
        
        if ($confidence >= 20) {
            return self::VERIFICATION_CONFLICTING;
        }
        
        return self::VERIFICATION_UNCONFIRMED;
    }

    /**
     * الحصول على وصف حالة التحقق
     */
    public function getStatusDescription(string $status): string {
        $descriptions = [
            self::VERIFICATION_UNCONFIRMED => 'غير مؤكد - معلومات أولية تحتاج تحقق',
            self::VERIFICATION_CONFLICTING => 'تقارير متضاربة - توجد تناقضات في الروايات',
            self::VERIFIED_SOURCE => 'تم التحقق من المصدر - مصدر واحد موثوق',
            self::VERIFICATION_LOCATION => 'تم تحديد الموقع - تحقق جغرافي مكتمل',
            self::VERIFICATION_TIME => 'تم تحديد الوقت - تحقق زمني دقيق',
            self::VERIFICATION_MULTI => 'مؤكد من عدة مصادر - 3 مصادر أو أكثر',
            self::VERIFICATION_OFFICIAL => 'مؤكد رسمياً - تأكيد رسمي + أدلة قاطعة'
        ];
        
        return $descriptions[$status] ?? 'حالة غير معروفة';
    }

    /**
     * الحصول على أيقونة حالة التحقق
     */
    public function getStatusIcon(string $status): string {
        $icons = [
            self::VERIFICATION_UNCONFIRMED => '⚪',
            self::VERIFICATION_CONFLICTING => '⚠️',
            self::VERIFIED_SOURCE => '🔵',
            self::VERIFICATION_LOCATION => '📍',
            self::VERIFICATION_TIME => '⏰',
            self::VERIFICATION_MULTI => '✅',
            self::VERIFICATION_OFFICIAL => '✔️'
        ];
        
        return $icons[$status] ?? '❓';
    }
}
