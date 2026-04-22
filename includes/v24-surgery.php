<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('bt_v24_strip_source_prefixes')) {
    function bt_v24_strip_source_prefixes(string $title, string $source = ''): string {
        $title = trim(preg_replace('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]+/u', '', $title));
        $title = preg_replace('/^[\s\|#:\-—–•·]+/u', '', $title);
        $title = preg_replace('/^["“”«»\'\(\[\{]+/u', '', $title);
        $title = preg_replace('/^(عاجل|مباشر|متابعة|تحديث|حصري)\s*[:\-|]+\s*/u', '', $title);
        $prefixes = [
            'رويترز','العربية','قناة العربية','إيران الآن','ايران الان','إيران بالعربي','الميادين','Axios','أكسيوس','سي إن إن','CNN','بلومبرغ','Bloomberg'
        ];
        foreach ($prefixes as $prefix) {
            $title = preg_replace('/^' . preg_quote($prefix, '/') . '\s*(\||:|：|-|—)+\s*/u', '', $title);
            $title = preg_replace('/^' . preg_quote($prefix, '/') . '\s+عن\s+/u', '', $title);
        }
        $source = trim($source);
        if ($source !== '') {
            $title = preg_replace('/^' . preg_quote($source, '/') . '\s*(\||:|：|-|—)+\s*/u', '', $title);
        }
        return trim($title);
    }
}

if (!function_exists('bt_v24_is_media_label')) {
    function bt_v24_is_media_label(string $value): bool {
        $value = trim($value);
        if ($value === '') return false;
        return preg_match('/(رويترز|العربية|قناة العربية|إعلام|إعلامي|وكالة|صحيفة|قناة|Axios|أكسيوس|CNN|بلومبرغ|Bloomberg|الجزيرة|الميادين|سكاي نيوز|يديعوت|وول ستريت جورنال|واشنطن بوست|فايننشال تايمز|مصادر فلسطينية|مصادر إسرائيلية|مصادر أميركية|مصادر أمريكية|مسؤولين? أمريكيين?|مسؤولين? أميركيين?)/ui', $value) === 1;
    }
}

if (!function_exists('bt_v24_resolve_actor')) {
    function bt_v24_resolve_actor(string $title, string $actor, string $source = '', string $region = ''): string {
        $title = bt_v24_strip_source_prefixes($title, $source);
        $unknown = ['جهة غير معلنة','فاعل قيد التقييم','غير محدد','عام/مجهول','فاعل غير محسوم','فاعل سياقي','فاعل سياقي غير مباشر',''];
        $actor = trim($actor);
        if (bt_v24_is_media_label($actor) || preg_match('/^(مصادر|مسؤولين?|بيان|تقرير|تم رصد|رصد|تسلل مخربين)/ui', $actor)) {
            $actor = '';
        }

        // Hard-cut title prefixes that should never survive as actor labels.
        if (preg_match('/^(مصادر فلسطينية|مصادر إسرائيلية|مصادر أميركية|مصادر أمريكية|مسؤولين? أمريكيين?|مسؤولين? أميركيين?|رويترز عن|رويتزر عن|واشنطن بوست|وول ستريت جورنال|فايننشال تايمز|تم رصد|رصد|الهلال الأحمر الإيراني|إيران الآن\s*\|)/ui', $title)) {
            if (preg_match('/(جيش العدو الإسرائيلي|قوات الاحتلال|الاحتلال|دبابات|غارات? جوية|غارة|قصف|إطلاق نار|اقتحام|توغل)/ui', $title)) {
                return 'جيش العدو الإسرائيلي';
            }
            if (preg_match('/(ترامب|ترمب|دونالد ترامب|البيت الأبيض|الخارجية الأميركية|الخارجية الأمريكية|مسؤولين? أمريكيين?|مسؤولين? أميركيين?|مدمرات? أميركية|مدمرات? أمريكية|أميركا|أمريكا|الجيش الأميركي|الجيش الأمريكي)/ui', $title)) {
                if (preg_match('/(ترامب|ترمب|دونالد ترامب)/ui', $title)) return 'دونالد ترامب';
                return 'الولايات المتحدة';
            }
            if (preg_match('/(الهلال الأحمر الإيراني|إيران|طهران|الحرس الثوري)/ui', $title) && !preg_match('/(قوات الاحتلال|الاحتلال|جيش العدو)/ui', $title)) {
                return 'إيران';
            }
        }

        $patterns = [
            '/\b(ترامب|ترمب|دونالد ترامب)\b/u' => 'دونالد ترامب',
            '/\b(نتنياهو|بنيامين نتنياهو)\b/u' => 'بنيامين نتنياهو',
            '/\b(قاليباف|محمد باقر قاليباف)\b/u' => 'محمد باقر قاليباف',
            '/(الجيش الإسرائيلي|جيش الاحتلال|جيش العدو الإسرائيلي|قوات الاحتلال)/ui' => 'جيش العدو الإسرائيلي',
            '/(الجيش الأميركي|الجيش الأمريكي|القيادة المركزية الأميركية|القيادة المركزية الأمريكية|البنتاغون|مدمرات? أميركية|مدمرات? أمريكية|أميركا|أمريكا)/ui' => 'الولايات المتحدة',
            '/(حزب الله|المقاومة الإسلامية)/ui' => 'المقاومة الإسلامية (حزب الله)',
            '/(الحرس الثوري|حرس الثورة)/ui' => 'الحرس الثوري الإيراني',
            '/(الخارجية الأميركية|وزارة الخارجية الأميركية|البيت الأبيض)/ui' => 'الولايات المتحدة',
            '/(الخارجية السعودية)/ui' => 'الخارجية السعودية',
            '/(الهلال الأحمر الإيراني)/ui' => 'إيران',
            '/(إيران قيادة القوة البحرية لحرس الثورة)/ui' => 'الحرس الثوري الإيراني',
        ];
        foreach ($patterns as $pattern => $resolved) {
            if (preg_match($pattern, $title) === 1) return $resolved;
        }
        if (!in_array($actor, $unknown, true) && !bt_v24_is_junk_actor($actor)) return $actor;
        if (($region === 'لبنان' || preg_match('/(لبنان|الجنوب|بنت جبيل|الخيام|مرجعيون|الناقورة|صور|النبطية)/ui', $title)) && preg_match('/(غارة|قصف|استهداف|نسف|تفجير|اقتحام|توغل|طائرة مسيرة|مسيرة إسرائيلية|الاحتلال)/ui', $title)) {
            return 'جيش العدو الإسرائيلي';
        }
        if (($region === 'فلسطين' || preg_match('/(غزة|رفح|الخليل|الخليل|قلقيلية|عزون|الضفة الغربية|فلسطين)/ui', $title)) && preg_match('/(إطلاق نار|دبابات|قوات الاحتلال|الاحتلال|اقتحام|قصف|استهداف)/ui', $title)) {
            return 'جيش العدو الإسرائيلي';
        }
        return $actor ?: 'فاعل غير محسوم';
    }
}

if (!function_exists('bt_v24_resolve_region')) {
    function bt_v24_resolve_region(string $title, string $region, string $actor = '', string $source = ''): string {
        $title = bt_v24_strip_source_prefixes($title, $source);
        $region = trim($region);
        $maps = [
            '/(بنت جبيل|الخيام|مرجعيون|ميس الجبل|عيتا الشعب|الناقورة|صور|النبطية|جنوب لبنان|الضاحية|بيروت|بعلبك|الهرمل)/ui' => 'لبنان',
            '/(غزة|رفح|قلقيلية|عزون|الضفة الغربية|فلسطين)/ui' => 'فلسطين',
            '/(أربيل|بغداد|العراق)/ui' => 'العراق',
            '/(طهران|إيران|هرمز|كردكوي)/ui' => 'إيران',
            '/(الخليج|مضيق هرمز)/ui' => 'الخليج',
            '/(الصين|بكين)/ui' => 'الصين',
            '/(الأراضي المحتلة|تل أبيب|حيفا|صفد|إسرائيل)/ui' => 'الأراضي المحتلة (إسرائيل)',
        ];
        foreach ($maps as $pattern => $resolved) {
            if (preg_match($pattern, $title) === 1) {
                if ($resolved === 'الأراضي المحتلة (إسرائيل)' && preg_match('/(بنت جبيل|الخيام|مرجعيون|صور|النبطية|لبنان)/ui', $title)) {
                    return 'لبنان';
                }
                return $resolved;
            }
        }
        return $region !== '' ? $region : 'غير محدد';
    }
}

if (!function_exists('bt_v24_score_event')) {
    function bt_v24_score_event(array $event): int {
        $title = (string)($event['title'] ?? '');
        $axis = (string)($event['strategic_axis'] ?? 'عام');
        $pre = (int)($event['pre_score'] ?? 0);
        $calc = (int)($event['calc_score'] ?? 0);
        $is_statement = !empty($event['is_statement']);
        $is_summary = !empty($event['is_summary_report']);
        $has_direct = !empty($event['has_direct_kinetic']);
        $has_alert = !empty($event['has_alert_only']);
        $is_economic = !empty($event['is_economic_news']);

        if ($is_statement && !$has_direct) {
            $score = (int) round(($pre * 0.85) + ($calc * 0.15));
            if (preg_match('/(اتفاق|محادثات|مفاوضات)/ui', $title)) $score = min($score, 55);
            else $score = min($score, $is_economic ? 70 : 45);
            return max(10, $score);
        }
        if ($is_summary && !$has_direct) {
            return max($pre, min(65, (int) round(($pre * 0.75) + ($calc * 0.25))));
        }
        if ($has_alert && !$has_direct) {
            return max($pre, min(95, (int) round(($pre * 0.7) + ($calc * 0.3))));
        }
        if ($axis === 'اقتصادي/لوجستي') {
            return max($pre, min(125, (int) round(($pre * 0.72) + ($calc * 0.28))));
        }
        return max($pre, min(220, (int) round(($pre * 0.62) + ($calc * 0.38))));
    }
}


if (!function_exists('bt_v24_is_unknown_actor')) {
    function bt_v24_is_unknown_actor(string $value): bool {
        $value = trim($value);
        return in_array($value, ['','جهة غير معلنة','فاعل قيد التقييم','غير محدد','عام/مجهول','فاعل غير محسوم','فاعل سياقي','فاعل سياقي غير مباشر'], true);
    }
}

if (!function_exists('bt_v24_is_junk_actor')) {
    function bt_v24_is_junk_actor(string $value): bool {
        $value = trim($value);
        if ($value === '') return true;
        if (bt_v24_is_media_label($value) || bt_v24_is_unknown_actor($value)) return true;
        if (preg_match('/^(تم رصد|رصد|رويترز عن|رويتزر عن|بحسب|أكثر من شهر|ما يقارب|استهلاك النفط|قد لا تصدق|مصادر فلسطينية|مصادر إسرائيلية|مصادر أميركية|مصادر أمريكية|مسؤولين? أمريكيين?|مسؤولين? أميركيين?|واشنطن بوست|وول ستريت جورنال|فايننشال تايمز|الهلال الأحمر الإيراني|إيران الآن\s*\|)/ui', $value)) return true;
        if (preg_match('/(المناطق المتأثرة|اليومين الماضيين|خلال اليومين|تغطية مستمرة)/ui', $value)) return true;
        return false;
    }
}

if (!function_exists('bt_v24_unify_actor')) {
    function bt_v24_unify_actor(string $title, array $candidates, string $source = '', string $region = ''): string {
        $resolved = bt_v24_resolve_actor($title, '', $source, $region);
        if (!bt_v24_is_junk_actor($resolved)) return $resolved;
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') continue;
            $candidate = bt_v24_resolve_actor($title, $candidate, $source, $region);
            if (!bt_v24_is_junk_actor($candidate)) return $candidate;
        }
        return bt_v24_resolve_actor($title, 'فاعل غير محسوم', $source, $region);
    }
}

if (!function_exists('bt_v24_unify_hybrid_layers')) {
    function bt_v24_unify_hybrid_layers(string $title, string $axis, array $layers = []): array {
        $title = mb_strtolower($title);
        $normalized = [];
        foreach ($layers as $layer) {
            $layer = trim((string)$layer);
            if ($layer === '') continue;
            $map = [
                'سياسي' => 'political',
                'economic' => 'economic',
                'اقتصادي' => 'economic',
                'طاقة' => 'energy',
                'energy' => 'energy',
                'media_psychological' => 'media_psychological',
                'إعلامي/نفسي' => 'media_psychological',
                'أمني' => 'security',
                'security' => 'security',
                'عسكري' => 'military',
                'military' => 'military',
                'cyber' => 'cyber',
                'جيوستراتيجي' => 'geostrategic',
            ];
            $normalized[] = $map[$layer] ?? $layer;
        }
        $normalized = array_values(array_unique($normalized));
        if (preg_match('/(اختراق|سيبر|الكترون|إلكترون|هاكر|هجوم إلكتروني)/ui', $title) !== 1) {
            $normalized = array_values(array_filter($normalized, static function($l){ return $l !== 'cyber'; }));
        }
        $forced = [];
        if (preg_match('/(نفط|خام|ناقلات|موانئ|ملاحة|شحن|إمدادات|هرمز|طاقة)/ui', $title)) {
            $forced[] = 'economic';
            if (preg_match('/(نفط|خام|هرمز|طاقة)/ui', $title)) $forced[] = 'energy';
        }
        if (preg_match('/(قال|أعلن|صرح|تصريح|بيان|كشف|توقع|محادثات|اتفاق|مفاوضات)/ui', $title)) {
            $forced[] = 'political';
            $forced[] = 'media_psychological';
        }
        if (preg_match('/(قصف|غارة|استهداف|اغتيال|اقتحام|توغل|نسف|اشتباك|إطلاق نار|طائرة مسيرة|مسيرة إسرائيلية)/ui', $title)) {
            $forced[] = 'military';
            $forced[] = 'security';
        }
        if ($axis === 'اقتصادي/لوجستي') $forced[] = 'economic';
        if (in_array($axis, ['سياسي','سياسي/إعلامي'], true)) $forced[] = 'political';
        if (in_array($axis, ['عسكري/أمني','تكتيكي/ميداني','أمني'], true)) $forced[] = 'security';
        $final = array_values(array_unique(array_merge($forced, $normalized)));
        if (empty($final)) return [];
        return array_slice($final, 0, 2);
    }
}


if (!function_exists('bt_v24_dirty_actor_patterns')) {
    function bt_v24_dirty_actor_patterns(): array {
        return [
            '/^(\||#|:|\s)+/u',
            '/^(تم رصد|رصد|رويترز عن|رويتزر عن|بحسب|وكالة|صحيفة|قناة|إعلام_العدو|إعلام العدو|بيان صادر عن|بيان المسيرات|أكثر من شهر|ما يقارب|استهلاك النفط|قد لا تصدق|اليمن أبرز ما جاء)/ui',
            '/(المناطق المتأثرة|اليومين الماضيين|خلال اليومين|تغطية مستمرة|أبرز ما جاء في كلمة)/ui',
        ];
    }
}

if (!function_exists('bt_v24_row_needs_targeted_cleanup')) {
    function bt_v24_row_needs_targeted_cleanup(array $row): bool {
        $title = (string)($row['title'] ?? '');
        $actor = trim((string)($row['actor_v2'] ?? ''));
        $region = trim((string)($row['region'] ?? ''));
        $source = trim((string)($row['source_name'] ?? $row['source'] ?? ''));
        if ($actor === '' || bt_v24_is_junk_actor($actor)) return true;
        foreach (bt_v24_dirty_actor_patterns() as $pattern) {
            if (preg_match($pattern, $actor) === 1) return true;
        }
        if ($source !== '' && $actor === $source) return true;
        if ($region === '' || $region === 'غير محدد' || $region === 'الساحة المرتبطة بالحدث (استنتاج)') return true;
        if ((preg_match('/(لبنان|الجنوب|بنت جبيل|الخيام|مرجعيون|صور|النبطية)/ui', $title) && $region === 'الأراضي المحتلة (إسرائيل)') || (preg_match('/(طهران|إيران|مستشفى غاندي|بوشهر|هرمز)/ui', $title) && $region === 'الأراضي المحتلة (إسرائيل)')) return true;
        if (preg_match('/(صفد|نهاريا|كريات شمونه|تل أبيب|حيفا|الجليل|المطلة|الأراضي المحتلة|إسرائيل)/ui', $title) && $region === 'لبنان') return true;
        return false;
    }
}



if (!function_exists('bt_v24_master_return_override')) {
    function bt_v24_master_return_override(array $payload, string $title, string $source = ''): array {
        $title = bt_v24_strip_source_prefixes($title, $source);
        $region = (string)($payload['region'] ?? '');
        $actor  = (string)($payload['actor_v2'] ?? '');
        $normalized = preg_replace('/^[\s\|#:\-—–•·"“”«»\'\(\[\{]+/u', '', $title);

        $starts_with_transfer = preg_match('/^(مصادر\s+(فلسطينية|إسرائيلية|أميركية|أمريكية)|مسؤولين?\s+(أميركيين?|أمريكيين?)|رويترز(?:\s+عن)?|رويتزر(?:\s+عن)?|واشنطن بوست|وول ستريت جورنال|فايننشال تايمز|سي إن إن|CNN|بلومبرغ|Bloomberg|أكسيوس|Axios|الهلال الأحمر الإيراني|إيران الآن\s*\||ايران الان\s*\||قناة العربية\s*\||العربية\s*\||الميادين\s*\||الجزيرة\s*\||الأناضول\s*\||وكالة الأناضول\s*\||تم رصد|رصد|تسلل مخربين)/ui', $normalized) === 1;

        $forced_actor = '';
        if (preg_match('/(قوات الاحتلال|الاحتلال|جيش العدو(?:\s+الإسرائيلي)?|الجيش\s+["“”«»]?الإسرائيلي["“”«»]?|طائرات حربية إسرائيلية|غارات? إسرائيلية|مسيرة إسرائيلية|دبابات|إطلاق نار|اقتحام|توغل|قصف|استهداف)/ui', $normalized)
            && preg_match('/(فلسطين|غزة|رفح|الخليل|الضفة الغربية|قلقيلية|عزون|لبنان|الليطاني|الخيام|بنت جبيل|بيت ياحون|كونين|مرجعيون|صور|النبطية)/ui', $normalized)) {
            $forced_actor = 'جيش العدو الإسرائيلي';
        } elseif (preg_match('/(ترامب|ترمب|دونالد ترامب)/ui', $normalized)) {
            $forced_actor = 'دونالد ترامب';
        } elseif (preg_match('/(مدمرات?\s+أميركية|مدمرات?\s+أمريكية|الجيش\s+الأميركي|الجيش\s+الأمريكي|البيت الأبيض|الخارجية\s+الأميركية|الخارجية\s+الأمريكية|الخزانة\s+الأميركية|الخزانة\s+الأمريكية|القيادة المركزية\s+الأميركية|القيادة المركزية\s+الأمريكية|واشنطن\b|أميركا|أمريكا)/ui', $normalized)) {
            if (preg_match('/(الخزانة\s+الأميركية|الخزانة\s+الأمريكية)/ui', $normalized)) $forced_actor = 'الخزانة الأميركية';
            else $forced_actor = 'الولايات المتحدة';
        } elseif (preg_match('/(الحرس الثوري|حرس الثورة)/ui', $normalized)) {
            $forced_actor = 'الحرس الثوري الإيراني';
        } elseif (preg_match('/(الخارجية السعودية)/ui', $normalized)) {
            $forced_actor = 'الخارجية السعودية';
        } elseif (preg_match('/(قاليباف|محمد باقر قاليباف)/ui', $normalized)) {
            $forced_actor = 'محمد باقر قاليباف';
        } elseif (preg_match('/(رئيس الأركان العامة البلجيكية|جورجيا ميلوني|الخارجية الروسية|الدفاع المدني في جنوب لبنان|الهلال الأحمر الإيراني)/ui', $normalized, $m)) {
            $forced_actor = trim($m[1]);
        }

        if ($starts_with_transfer && $forced_actor !== '') {
            $actor = $forced_actor;
        } elseif (bt_v24_is_junk_actor($actor) && $forced_actor !== '') {
            $actor = $forced_actor;
        }

        if (bt_v24_is_junk_actor($actor) && preg_match('/^(مصادر\s+(فلسطينية|إسرائيلية|أميركية|أمريكية))/ui', $normalized)) {
            if (preg_match('/(جيش العدو(?:\s+الإسرائيلي)?|قوات الاحتلال|الاحتلال|اقتحام|إطلاق نار|قصف|استهداف)/ui', $normalized)) {
                $actor = 'جيش العدو الإسرائيلي';
            }
        }

        if ($starts_with_transfer && preg_match('/(طهران|إيران|بوشهر|مستشفى غاندي|كردكوي)/ui', $normalized) && !preg_match('/(تل أبيب|حيفا|صفد|الجليل|كريات شمونه|نهاريا|الأراضي المحتلة)/ui', $normalized)) {
            $region = 'إيران';
        }
        if (preg_match('/(الخيام|بنت جبيل|مرجعيون|صور|النبطية|بيت ياحون|كونين|الليطاني|جنوب لبنان|الجنوب اللبناني)/ui', $normalized)) {
            $region = 'لبنان';
        } elseif (preg_match('/(غزة|رفح|الخليل|قلقيلية|عزون|الضفة الغربية|فلسطين)/ui', $normalized)) {
            $region = 'فلسطين';
        } elseif (preg_match('/(مضيق هرمز|الخليج)/ui', $normalized) && !preg_match('/(طهران|إيران|بوشهر|كردكوي)/ui', $normalized)) {
            $region = 'الخليج';
        } elseif (preg_match('/(تل أبيب|حيفا|صفد|كريات شمونه|الجليل|المطلة|الأراضي المحتلة|إسرائيل)/ui', $normalized) && !preg_match('/(لبنان|بنت جبيل|الخيام|بيت ياحون|كونين|صور|النبطية)/ui', $normalized)) {
            $region = 'الأراضي المحتلة (إسرائيل)';
        } elseif (preg_match('/(طهران|إيران|بوشهر|مستشفى غاندي|كردكوي)/ui', $normalized)) {
            $region = 'إيران';
        }

        $payload['actor_v2'] = $actor;
        $payload['region'] = $region;
        if (!empty($payload['war_data'])) {
            $war = json_decode((string)$payload['war_data'], true);
            if (is_array($war)) {
                $war['actor'] = $actor;
                $war['who'] = $actor;
                $war['where'] = $region;
                if (isset($war['5w1h']) && is_array($war['5w1h'])) {
                    $war['5w1h']['who_primary'] = $actor;
                    $war['5w1h']['where_event'] = $region;
                    $war['5w1h']['where_summary'] = $region;
                }
                $payload['war_data'] = wp_json_encode($war, JSON_UNESCAPED_UNICODE);
            }
        }
        if (!empty($payload['field_data'])) {
            $field = json_decode((string)$payload['field_data'], true);
            if (is_array($field)) {
                $field['actor_v2'] = $actor;
                $field['region'] = $region;
                $field['master_return_override'] = true;
                $payload['field_data'] = wp_json_encode($field, JSON_UNESCAPED_UNICODE);
            }
        }
        return $payload;
    }
}

if (!function_exists('bt_v24_presave_lockdown')) {
    function bt_v24_presave_lockdown(array $payload, string $title, string $source = ''): array {
        $title = bt_v24_strip_source_prefixes($title, $source);
        $region = (string)($payload['region'] ?? '');
        $actor  = (string)($payload['actor_v2'] ?? '');

        $resolved_actor = bt_v24_unify_actor($title, [
            $actor,
            (string)($payload['context_actor'] ?? ''),
            (string)($payload['target_v2'] ?? ''),
        ], $source, $region);

        // Priority 1: Respect actor_final from unified engine if present and valid
        $field_check = [];
        if (!empty($payload['field_data'])) {
            $field_check = json_decode((string)$payload['field_data'], true);
            if (!is_array($field_check)) $field_check = [];
        }
        
        $locked_actor = trim((string)($field_check['actor_final'] ?? ''));
        
        // If actor_final exists and is not junk, use it as source of truth
        if ($locked_actor !== '' && !bt_v24_is_junk_actor($locked_actor)) {
            $resolved_actor = $locked_actor;
        }

        $resolved_region = bt_v24_resolve_region($title, $region, $resolved_actor, $source);

        if (preg_match('/^(مصادر\s+(فلسطينية|إسرائيلية|أميركية|أمريكية)|مسؤولين?\s+(أميركيين?|أمريكيين?)|واشنطن بوست|وول ستريت جورنال|فايننشال تايمز|رويترز عن|رويتزر عن|تم رصد|رصد|إيران الآن\s*\||ايران الان\s*\||الهلال الأحمر الإيراني)/ui', $title)) {
            if (preg_match('/(جيش العدو الإسرائيلي|قوات الاحتلال|الاحتلال|دبابات|غارات?|غارة|قصف|إطلاق نار|اقتحام|توغل|استهداف|طائرات حربية إسرائيلية|الجيش "?الإسرائيلي"?)/ui', $title)) {
                $resolved_actor = 'جيش العدو الإسرائيلي';
            } elseif (preg_match('/(ترامب|ترمب|دونالد ترامب)/ui', $title)) {
                $resolved_actor = 'دونالد ترامب';
            } elseif (preg_match('/(مدمرات? أميركية|مدمرات? أمريكية|الجيش الأميركي|الجيش الأمريكي|البيت الأبيض|الخارجية الأميركية|الخارجية الأمريكية|مسؤولين? أمريكيين?|مسؤولين? أميركيين?|أميركا|أمريكا|واشنطن\b)/ui', $title)) {
                $resolved_actor = 'الولايات المتحدة';
            } elseif (preg_match('/(الخارجية السعودية)/ui', $title)) {
                $resolved_actor = 'الخارجية السعودية';
            } elseif (preg_match('/(الخزانة الأميركية|وزارة الخزانة الأميركية)/ui', $title)) {
                $resolved_actor = 'الخزانة الأميركية';
            }
        }

        if (preg_match('/(طهران|إيران|بوشهر|مستشفى غاندي|كردكوي)/ui', $title) && !preg_match('/(تل أبيب|حيفا|صفد|الأراضي المحتلة|إسرائيل)/ui', $title)) {
            $resolved_region = 'إيران';
        }
        if (preg_match('/(الخيام|بنت جبيل|مرجعيون|صور|النبطية|بيت ياحون|كونين|الليطاني|جنوب لبنان)/ui', $title)) {
            $resolved_region = 'لبنان';
        }
        if (preg_match('/(غزة|رفح|الخليل|قلقيلية|عزون|الضفة الغربية|فلسطين)/ui', $title)) {
            $resolved_region = 'فلسطين';
        }
        if (preg_match('/(مضيق هرمز|الخليج)/ui', $title) && !preg_match('/(طهران|بوشهر|كردكوي)/ui', $title)) {
            $resolved_region = 'الخليج';
        }
        if (preg_match('/(تل أبيب|حيفا|صفد|كريات شمونه|الجليل|المطلة|الأراضي المحتلة|إسرائيل)/ui', $title) && !preg_match('/(لبنان|بنت جبيل|الخيام|بيت ياحون|كونين|صور|النبطية)/ui', $title)) {
            $resolved_region = 'الأراضي المحتلة (إسرائيل)';
        }

        if (bt_v24_is_junk_actor($resolved_actor)) {
            if (preg_match('/(جيش العدو الإسرائيلي|قوات الاحتلال|الاحتلال|دبابات|إطلاق نار|اقتحام|غارات?|قصف|توغل)/ui', $title) && preg_match('/(فلسطين|غزة|رفح|الخليل|الضفة الغربية|لبنان|الخيام|بنت جبيل|بيت ياحون|كونين)/ui', $title)) {
                $resolved_actor = 'جيش العدو الإسرائيلي';
            }
        }

        $payload['actor_v2'] = $resolved_actor;
        $payload['region'] = $resolved_region;

        $war = [];
        if (!empty($payload['war_data'])) {
            $war = json_decode((string)$payload['war_data'], true);
            if (!is_array($war)) $war = [];
        }
        $field = [];
        if (!empty($payload['field_data'])) {
            $field = json_decode((string)$payload['field_data'], true);
            if (!is_array($field)) $field = [];
        }

        $war['actor'] = $resolved_actor;
        $war['who'] = $resolved_actor;
        $war['where'] = $resolved_region;
        if (isset($war['5w1h']) && is_array($war['5w1h'])) {
            $war['5w1h']['who_primary'] = $resolved_actor;
            $war['5w1h']['where_event'] = $resolved_region;
            $war['5w1h']['where_summary'] = $resolved_region;
            if (empty($war['5w1h']['where_target']) || in_array($war['5w1h']['where_target'], ['غير محدد','الساحة المرتبطة بالحدث (استنتاج)'], true)) {
                $war['5w1h']['where_target'] = !empty($payload['target_v2']) ? (string)$payload['target_v2'] : $resolved_region;
            }
        }

        $field['actor_v2'] = $resolved_actor;
        $field['actor_final'] = $resolved_actor;
        $field['actor_final_unified'] = true;
        $field['actor_source'] = !empty($field['actor_source']) ? $field['actor_source'] : 'unified_engine';
        $field['actor_reason'] = !empty($field['actor_reason']) ? $field['actor_reason'] : 'presave_lockdown';
        $field['actor_confidence'] = !empty($field['actor_confidence']) ? $field['actor_confidence'] : 85;
        $field['region'] = $resolved_region;
        $field['presave_lockdown'] = true;
        if (!isset($field['attribution']) || !is_array($field['attribution'])) $field['attribution'] = [];
        $field['attribution']['name'] = $resolved_actor;
        if (empty($field['attribution']['status'])) $field['attribution']['status'] = 'locked';

        $payload['war_data'] = wp_json_encode($war, JSON_UNESCAPED_UNICODE);
        $payload['field_data'] = wp_json_encode($field, JSON_UNESCAPED_UNICODE);
        return $payload;
    }
}
