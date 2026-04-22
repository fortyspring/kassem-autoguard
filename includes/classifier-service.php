<?php
/**
 * Classifier Service
 * 
 * Provides context-aware classification for OSINT content.
 * Uses pattern matching and context memory to identify actors and entities.
 * 
 * @package BeirutTime_OSINT_Pro
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Infer context from text using memory patterns
 * 
 * Analyzes text against stored patterns to identify primary actors
 * and contextual relationships.
 * 
 * @param string $text The text to analyze
 * @return array Context inference results with actor information
 */
function sod_context_memory_infer(string $text): array {
    $text = so_clean_text($text);
    if ($text === '') {
        return [];
    }

    $fp = so_build_title_fingerprint($text);
    $patterns = sod_context_memory_get();
    $best = [];
    $bestScore = 0.0;

    foreach (array_reverse($patterns) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entryTitle = so_clean_text((string)($entry['title'] ?? ''));
        if ($entryTitle === '') {
            continue;
        }

        $score = 0.0;
        if ($fp !== '' && !empty($entry['fingerprint']) && $fp === $entry['fingerprint']) {
            $score += 70;
        }
        similar_text(so_normalize_title_for_dedupe($text), so_normalize_title_for_dedupe($entryTitle), $pct);
        $score += ($pct / 100.0) * 40.0;
        if (!empty($entry['actor_v2']) && in_array($entry['actor_v2'], ['فاعل غير محسوم', 'فاعل سياقي', 'فاعل سياقي غير مباشر'], true)) {
            $score -= 35;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $entry;
        }
    }

    if ($bestScore < 60 || empty($best['actor_v2'])) {
        return [];
    }

    return [
        'primary_actor' => (string)$best['actor_v2'],
        'secondary_actor' => '',
        'target' => '',
        'confidence' => min(98, (int)round($bestScore)),
        'reason' => 'context-memory-match',
        'actor_matches' => [],
    ];
}

/**
 * Extract named non-military actor from text
 * 
 * Uses pattern matching to identify actors, organizations, and entities
 * mentioned in the text. Returns the first matched actor label.
 * 
 * @param string $text The text to analyze
 * @return string The identified actor label or empty string if none found
 */
function sod_extract_named_nonmilitary_actor(string $text): string {
    $text = so_clean_text($text);
    if ($text === '') {
        return '';
    }

    $patterns = [
        'الخارجية الإيرانية' => '/(الخارجية الإيرانية|وزارة الخارجية الإيرانية|عراقجي|وزير الخارجية الايراني|وزير الخارجية الإيراني)/ui',
        'البيت الأبيض' => '/(البيت الأبيض|البيت الابيض|مسؤول أمريكي|مسؤول أميركي|نائب الرئيس الأميركي|نائب الرئيس الأمريكي)/ui',
        'الولايات المتحدة' => '/(ترامب|ترمب|واشنطن|الرئاسة الأميركية|الإدارة الأميركية|الادارة الاميركية|وفد أمريكي|الوفد الأمريكي|قائد سنتكوم|سنتكوم)/ui',
        'الحكومة الإيرانية' => '/(الحكومة الإيرانية|المتحدثة باسم الحكومة الإيرانية|المتحدث باسم الحكومة الإيرانية)/ui',
        'الرئاسة الإيرانية' => '/(الرئاسة الإيرانية|الرئاسة:\s*الرئيس مسعود بزشكيان|الرئيس مسعود بزشكيان|بزشكيان)/ui',
        'التلفزيون الإيراني' => '/(التلفزيون الإيراني|الاذاعة و التلفزيون الإيراني|الإذاعة والتلفزيون الإيراني|وكالة إيرنا|إيرنا)/ui',
        'وكالة فارس' => '/(وكالة فارس|فارس:|فارس\s*:)/ui',
        'وكالة تسنيم' => '/(وكالة\s*[\"“”]?\s*تسنيم|تسنيم للأنباء|تسنيم:|تسنيم\s*:|نورنيوز)/ui',
        'مقر خاتم الأنبياء الإيراني' => '/(مقر خاتم الأنبياء|خاتم الأنبياء المركزي|المتحدث باسم مقر خاتم الأنبياء)/ui',
        'رئيس البرلمان الإيراني' => '/(رئيس البرلمان الإيراني|رئيس مجلس الشورى الإيراني|محمد باقر قاليباف|قاليباف)/ui',
        'السفير الإيراني' => '/(السفير الإيراني|السفير الايراني|مير مسعود حسينيان|مسعود حسينيان)/ui',
        'الوفد الإيراني' => '/(الوفد الإيراني|الوفد الايراني|المفاوضات الإيرانية الأميركية|المفاوضات الإيرانية الأمريكية|المفاوضين الإيرانيين|فريق المفاوضات الإيراني|فريق المفاوضات الايراني)/ui',
        'إيران' => '/(مصادر إيرانية|مصدر إيراني|مسؤول عسكري إيراني|غريب آبادي|مرندي|آية الله مجتبى خامنئي|قائد الثورة|المرشد الأعلى لإيران|مجتبى خامنئي|عارف:|إعلام إيراني)/ui',
        'الشرطة الإيرانية' => '/(الشرطة الإيرانية|الشرطة الايرانية)/ui',
        'المجلس الإعلامي الحكومي الإيراني' => '/(أمين المجلس الإعلامي الحكومي الإيراني|المجلس الإعلامي الحكومي الإيراني)/ui',
        'علي أكبر ولايتي' => '/(علي أكبر ولايتي|ولايتي:|مستشار المرشد الإيراني|مستشار قائد الثورة الإسلامية)/ui',
        'الحكومة الإسرائيلية' => '/(نتنياهو|رئيس الوزراء الإسرائيلي|مكتب نتنياهو)/ui',
        'وزارة الصحة الإسرائيلية' => '/(وزارة الصحة الإسرائيلية|وزارة الصحة الاسرائيلية)/ui',
        'سفير إسرائيل' => '/(سفير إسرائيل|سفير اسرائيل)/ui',
        'جيش العدو الإسرائيلي' => '/(المتحدث باسم الجيش الإسرائيلي|المتحدث باسم جيش العدو الإسرائيلي|الجيش الإسرائيلي ينذر|قوات اللواء|قوات الاحتلال)/ui',
        'إعلام إسرائيلي' => '/(وسائل إعلام إسرائيلية|اعلام إسرائيلي|إعلام_العدو|إعلام العدو|القناة 12 العبرية|القناة 15|يديعوت أحرونوت|هآرتس|إذاعة جيش العدو الإسرائيلي)/ui',
        'باكستان' => '/(شهباز شريف|رئيس الوزراء الباكستاني|الخارجية الباكستانية|رئيس وزراء باكستان|الجيش الباكستاني|القوات الجوية الباكستانية|مسؤول باكستاني رفيع|وزير الدفاع الباكستاني|خواجة آصف)/ui',
        'دول البريكس' => '/(بريكس|BRICS|دول البريكس)/ui',
        'دول الخليج' => '/(دول الخليج|مجلس التعاون الخليجي|الخليج العربي|الخليج)/ui',
        'الدول العربية' => '/(الدول العربية|جامعة الدول العربية|الجامعة العربية)/ui',
        'المؤتمر الشعبي اللبناني' => '/(المؤتمر الشعبي)/ui',
        'إبراهيم الموسوي' => '/(إبراهيم الموسوي|النائب إبراهيم الموسوي)/ui',
        'حسين الحاج حسن' => '/(حسين الحاج حسن|الحاج حسن للميادين|عضو كتلة الوفاء للمقاومة النائب د\.?حسين الحاج حسن)/ui',
        'النائب فضل الله' => '/(النائب فضل الله|فضل الله:)/ui',
        'النائب رعد' => '/(النائب رعد|رعد:)/ui',
        'ماهر حمود' => '/(ماهر حمود|رئيس الاتحاد العالمي لعلماء المقاومة الشيخ ماهر حمود)/ui',
        'شاكر البرجاوي' => '/(شاكر البرجاوي)/ui',
        'عبد العزيز أبو طالب' => '/(عبد العزيز ابو طالب|عبد العزيز أبو طالب)/ui',
        'نزيه منصور' => '/(نزيه منصور)/ui',
        'كتائب القسام (حماس)' => '/(^|\s)(حماس|حركة حماس|كتائب القسام)(\s|:|$)/ui',
        'المقاومة الإسلامية (حزب الله)' => '/(المقاومة الإسلامية|المقاومة الاسلامية|حزب الله|الشيخ قاسم|بيان صادر عن المقاومة الإسلامية|بيان صادر عن المقاومة الاسلامية|استهدفنا|استهدف مجاهدونا|مجاهدونا|قصفنا موقع|قصفنا)/ui',
        'سرايا القدس (الجهاد الإسلامي)' => '/(^|\s)(سرايا القدس|الجهاد الإسلامي)(\s|:|$)/ui',
        'الاتحاد الأوروبي' => '/(الاتحاد الأوروبي)/ui',
        'الناتو' => '/(الناتو|حلف شمال الأطلسي|حلف شمال الاطلسي|NATO)/ui',
        'رئيس الناتو' => '/(رئيس الناتو|الأمين العام للناتو|امين عام الناتو|الأمين العام لحلف شمال الأطلسي)/ui',
        'الأمم المتحدة' => '/(الأمم المتحدة|اليونيسيف)/ui',
        'الأمين العام للأمم المتحدة' => '/(الأمين العام للأمم المتحدة|امين عام الامم المتحدة|رئيس الأمم المتحدة|رئيس الامم المتحدة|غوتيريش|غوتيريس)/ui',
        'البنك الدولي' => '/(رئيس البنك الدولي|البنك الدولي)/ui',
        'الكويت' => '/(الدفاع الكويتية|الكويتية:|وزارة الدفاع الكويتية|الكويت)/ui',
        'العراق' => '/(وسائل اعلام عراقية|إعلام عراقي|العراقية)/ui',
        'فايننشال تايمز' => '/(فايننشال تايمز)/ui',
        'بوليتيكو' => '/(بوليتيكو)/ui',
        'الجيش الكويتي' => '/(الجيش الكويتي)/ui',
        'حرس الثورة الإسلامية' => '/(حرس الثورة|وحدة المسيرات في حرس الثورة)/ui',
    ];

    foreach ($patterns as $label => $regex) {
        if (preg_match($regex, $text)) return $label;
    }
    return '';
}

function sod_infer_explicit_speaker_actor(string $text): string {
    $text = so_clean_text($text);
    if ($text === '') return '';

    if (preg_match('/(^|[:：]\s*)(فانس|جي دي فانس|جاي دي فانس|نائب الرئيس الأميركي|نائب الرئيس الأمريكي)/ui', $text)) {
        return 'البيت الأبيض';
    }
    if (preg_match('/(^|[:：]\s*)(ترامب|ترمب|دونالد ترامب|دونالد ترمب)/ui', $text)) {
        return 'دونالد ترامب';
    }
    if (preg_match('/(^|[:：]\s*)(محمد مرندي|مرندي)\b/ui', $text)) {
        return 'محمد مرندي';
    }
    if (preg_match('/(^|[:：]\s*)(قاآني|قآني|إسماعيل قاآني|إسماعيل قآني|قائد قوة القدس)/ui', $text)) {
        return 'حرس الثورة الإسلامية';
    }
    if (preg_match('/(^|[:：]\s*)(بقائي)\b/ui', $text)) {
        return 'الخارجية الإيرانية';
    }
    if (preg_match('/(^|[:：]\s*)(وكالة\s*[\"“”]?\s*تسنيم|تسنيم|وكالة فارس|فارس|التلفزيون الإيراني|إيرنا|إعلام إيراني)\s*[:：]/ui', $text)) {
        return 'إيران';
    }

    return '';
}

function sod_governor_ai(array $result, string $text): array {
    $text = so_clean_text($text);
    $has_attack = preg_match('/(غارة|قصف|استهداف|هجوم|اشتباك|اقتحام|توغل|صاروخ|صواريخ|مسيّرة|مسيرة|دبابة|ثكنة|كمين|أغار|اعتداء إسرائيلي|إطلاق نار|استطلاع بطائرة مسيّرة|تم رصد)/ui', $text) === 1;
    $is_non_military = sod_is_non_military_context($text);
    $named_actor = sod_extract_named_nonmilitary_actor($text);

    if ($is_non_military && !$has_attack) {
        $result['primary_actor'] = $named_actor !== '' ? $named_actor : 'فاعل غير محسوم';
        $result['secondary_actor'] = '';
        $result['target'] = '';
        $result['confidence'] = $named_actor !== '' ? max((int)($result['confidence'] ?? 0), 82) : min((int)($result['confidence'] ?? 35), 45);
        $result['reason'] = $named_actor !== '' ? 'governor-named-nonmilitary' : 'governor-nonmilitary';
        return $result;
    }

    if (preg_match('/(استهدفنا|استهدف مجاهدونا|بيان صادر عن المقاومة الإسلامية|المقاومة الإسلامية في لبنان|المقاومة الاسلامية \(|ثكنة يعرا|مستوطنة أدميت|مستوطنة نهاريا)/ui', $text)) {
        $result['primary_actor'] = 'المقاومة الإسلامية (حزب الله)';
        $result['confidence'] = max((int)($result['confidence'] ?? 0), 90);
        $result['reason'] = 'governor-force-resistance';
    }

    if (!$has_attack && (($result['primary_actor'] ?? '') === 'جيش العدو الإسرائيلي' || ($result['primary_actor'] ?? '') === 'المقاومة الإسلامية (حزب الله)')) {
        $result['primary_actor'] = $named_actor !== '' ? $named_actor : 'فاعل غير محسوم';
        $result['secondary_actor'] = '';
        $result['target'] = '';
        $result['confidence'] = $named_actor !== '' ? 84 : 40;
        $result['reason'] = 'governor-remove-false-military';
    }

    return $result;
}

function sod_force_requested_actor_rule(string $actor, string $region, string $title = ''): string {
    $actor = trim((string)$actor);
    $region = trim((string)$region);
    $title = so_clean_text((string)$title);
    $named_actor = sod_extract_named_nonmilitary_actor($title);
    $explicit_actor = sod_infer_explicit_speaker_actor($title);
    $unknown_actor_values = ['', 'غير محدد', 'عام/مجهول', 'فاعل غير محسوم', 'فاعل سياقي', 'فاعل سياقي غير مباشر'];
    $known_locked_actor_values = [
        'الخارجية الإيرانية','البيت الأبيض','الولايات المتحدة','الحكومة الإيرانية','الرئاسة الإيرانية',
        'مقر خاتم الأنبياء الإيراني','رئيس البرلمان الإيراني','السفير الإيراني','إيران',
        'الشرطة الإيرانية','المجلس الإعلامي الحكومي الإيراني','علي أكبر ولايتي','الحكومة الإسرائيلية','وزارة الصحة الإسرائيلية',
        'سفير إسرائيل','جيش العدو الإسرائيلي','إعلام إسرائيلي','باكستان','دول البريكس','دول الخليج','الدول العربية',
        'المؤتمر الشعبي اللبناني','إبراهيم الموسوي','حسين الحاج حسن','النائب فضل الله','النائب رعد','ماهر حمود',
        'شاكر البرجاوي','عبد العزيز أبو طالب','نزيه منصور','كتائب القسام (حماس)','المقاومة الإسلامية (حزب الله)',
        'سرايا القدس (الجهاد الإسلامي)','الاتحاد الأوروبي','الناتو','رئيس الناتو','الأمم المتحدة','الأمين العام للأمم المتحدة',
        'البنك الدولي','الكويت','العراق','فايننشال تايمز','بوليتيكو','منظومة الإنذار / الجبهة الداخلية','الوفد الإيراني'
    ];

    if ($explicit_actor !== '') return $explicit_actor;
    if ($actor !== '' && !in_array($actor, ['غير محدد', 'عام/مجهول', 'فاعل غير محسوم', 'فاعل سياقي', 'فاعل سياقي غير مباشر'], true) && in_array($actor, $known_locked_actor_values, true)) {
        return $actor;
    }
    if (in_array($named_actor, ['وكالة فارس', 'وكالة تسنيم', 'التلفزيون الإيراني'], true)) {
        $named_actor = 'إيران';
    }
    if ($named_actor !== '' && (sod_is_non_military_context($title) || in_array($actor, $unknown_actor_values, true) || $actor === 'جيش العدو الإسرائيلي')) {
        return $named_actor;
    }
    if ($actor !== '' && !in_array($actor, $unknown_actor_values, true) && $actor !== 'جيش العدو الإسرائيلي') {
        return $actor;
    }
    if ($actor === 'منظومة الإنذار / الجبهة الداخلية') return $actor;
    if (preg_match('/(لقد انتهى الحدث|انتهى الحدث|انذار احمر|إنذار أحمر|صفارات الإنذار|صافرات الإنذار|الجبهة الداخلية|تسوفار)/ui', $title)) return 'منظومة الإنذار / الجبهة الداخلية';
    if (sod_is_non_military_context($title) || preg_match('/(صورة\s+لل?وفد|الوفد\s+الإيراني\s+المفاوض|إعلام\s+إيراني|رويترز\s+عن\s+مصدر|مصدر\s+دبلوماسي|المؤتمر الشعبي|النائب فضل الله|نتبلوكس|تغطية\s+خاصة)/ui', $title)) return $named_actor !== '' ? $named_actor : 'فاعل غير محسوم';
    if (preg_match('/(الطيران الحربي المعادي|غارة إسرائيلية|غارات إسرائيلية|الجيش الإسرائيلي ينذر|شن غارات|أغار على بلدة|طائرات الاحتلال|الطيران الحربي المعادي أغار|سلسلة غارات إسرائيلية|غارتان لطيران العدو الإسرائيلي|مسيّرات معادية|مسيرات معادية)/ui', $title)) return 'جيش العدو الإسرائيلي';
    if (($actor === 'جيش العدو الإسرائيلي' || $actor === 'فاعل غير محسوم' || $actor === 'فاعل سياقي غير مباشر' || $actor === 'فاعل سياقي') && $named_actor !== '') return $named_actor;
    if (preg_match('/(المقاومة الإسلامية|حزب الله|استهدفنا|استهدف مجاهدونا|بيان صادر عن المقاومة الإسلامية)/ui', $title)) return 'المقاومة الإسلامية (حزب الله)';
    if (preg_match('/(الجيش الإسرائيلي|جيش الاحتلال|غارة إسرائيلية|قصف إسرائيلي|اعتداء إسرائيلي|طيران الاحتلال|الطيران الحربي المعادي|طائرات الاحتلال)/ui', $title)) return 'جيش العدو الإسرائيلي';
    if (preg_match('/(إعلام|قناة|رويترز|العربية|الميادين|الجزيرة|صحيفة|تغطية|محلل|كاتب|اعلام باكستاني)/ui', $actor)) return $named_actor !== '' ? $named_actor : 'فاعل غير محسوم';
    if (in_array($actor, ['', 'غير محدد', 'عام/مجهول', 'فاعل غير محسوم', 'فاعل سياقي', 'فاعل سياقي غير مباشر'], true)) {
        if ($named_actor !== '') return $named_actor;
        if (preg_match('/(إيران|ايران|طهران)/ui', $title)) return 'إيران';
        if (preg_match('/(الولايات المتحدة|أمريكا|امريكا|واشنطن|البيت الأبيض|ترامب|ترمب)/ui', $title)) return 'الولايات المتحدة';
        if (preg_match('/(باكستان|إسلام آباد|اسلام آباد)/ui', $title)) return 'باكستان';
        return 'فاعل غير محسوم';
    }
    return $actor;
}

function sod_strip_leading_source_attribution(string $text): string {
    $text = so_clean_text($text);
    if ($text === '') return '';

    $patterns = [
        '/^\s*(?:فلسطين المحتلة|لبنان|إيران|العراق|سوريا)\s*:\s*مراسل\s+(?:المنار|الميادين|العربية)\s*(?:في\s+[^\:]{1,40})?\s*:\s*/ui',
        '/^\s*مراسل\s+(?:المنار|الميادين|العربية)\s*(?:في\s+[^\:]{1,40})?\s*:\s*/ui',
        '/^\s*(?:أ\s*ف\s*ب|رويترز|وكالة\s+فارس|وكالة\s+تسنيم|التلفزيون\s+الإيراني|وسائل\s+إعلام\s+إسرائيلية|إعلام\s+العدو|القناة\s*1[25]|المنار|الميادين|العربية)\s*:\s*/ui',
    ];

    foreach ($patterns as $pattern) {
        $text = preg_replace($pattern, '', $text);
    }

    return trim($text);
}

function sod_infer_actor_fallback(string $title, string $region = '', string $source_name = ''): string {
    $title_text = sod_strip_leading_source_attribution($title);
    $text = so_clean_text($title_text !== '' ? $title_text : $title);
    $source_text = so_clean_text((string)$source_name);
    $region = trim((string)$region);
    if ($text === '') return '';

    $explicit_actor = sod_infer_explicit_speaker_actor($text);
    if ($explicit_actor !== '') return $explicit_actor;

    $named_actor = sod_extract_named_nonmilitary_actor($text);
    if ($named_actor !== '') return $named_actor;

    $is_kinetic = preg_match('/(غارة|غارات|قصف|استهداف|اقتحام|عدوان|اعتداء|هدم عقابي|إطلاق نار|توغل|هجوم|طيران حربي|قصف مدفعي|مسيّرة|مسيرة|اشتباكات|نيران مشاة|استطلاع بطائرة مسيّرة)/ui', $text) === 1;
    $enemy_markers = preg_match('/(العدو الإسرائيلي|العدو الاسرائيلي|إسرائيلي|اسرائيلي|الاحتلال|جيش الاحتلال|جيش العدو|طيران الاحتلال|طيران حربي معاد|قوات الاحتلال)/ui', $text) === 1;
    $palestine_theater = preg_match('/(فلسطين|غزة|الضفة الغربية|جنين|نابلس|الخليل|بيت لحم|خانيونس|خان يونس|رفح|طوباس|طولكرم|قلنديا)/ui', $text) === 1;
    $lebanon_theater = preg_match('/(لبنان|جنوب لبنان|البقاع|صور|النبطية|بنت جبيل|قانا|السماعية|عيتيت|الخيام|شبعا|كونين|خربة سلم|حانين|حلتا)/ui', $text) === 1;

    if ($is_kinetic && ($enemy_markers || $palestine_theater || $lebanon_theater || in_array($region, ['لبنان', 'فلسطين', 'الأراضي المحتلة (إسرائيل)'], true))) {
        if (preg_match('/(المقاومة الإسلامية|حزب الله|استهدفنا|استهدف مجاهدونا|بيان صادر عن المقاومة الإسلامية)/ui', $text)) {
            return 'المقاومة الإسلامية (حزب الله)';
        }
        return 'جيش العدو الإسرائيلي';
    }

    if (preg_match('/(بقائي|عراقجي|طهران|إيران|ايران|الوفد الإيراني|المحادثات الإيرانية الأمريكية|المفاوضات الإيرانية الأمريكية)/ui', $text)) {
        return 'إيران';
    }
    if (preg_match('/(الخارجية الإيرانية|وزارة الخارجية الإيرانية)/ui', $text)) {
        return 'الخارجية الإيرانية';
    }
    if (preg_match('/(الحكومة الإيرانية)/ui', $text)) {
        return 'الحكومة الإيرانية';
    }
    if (preg_match('/(قاآني|قآني|إسماعيل قاآني|إسماعيل قآني|قائد قوة القدس|قوة القدس|حرس الثورة|الحرس الثوري|القوة الجوفضائية لحرس الثورة)/ui', $text)) {
        return 'حرس الثورة الإسلامية';
    }
    if (preg_match('/(ترامب|ترمب|دونالد ترامب|دونالد ترمب)/ui', $text)) {
        return 'دونالد ترامب';
    }
    if (preg_match('/(فانس|جي دي فانس|جاي دي فانس|JD Vance)/ui', $text)) {
        return 'البيت الأبيض';
    }
    if (preg_match('/(البيت الأبيض|مسؤول بالبيت الأبيض|مسؤول في البيت الأبيض)/ui', $text)) {
        return 'البيت الأبيض';
    }
    if (preg_match('/(محمد مرندي|مرندي)/ui', $text)) {
        return 'محمد مرندي';
    }
    if (preg_match('/(شارل جبور)/ui', $text)) {
        return 'شارل جبور';
    }
    if (preg_match('/(نتنياهو|الحكومة الإسرائيلية|الحكومة الاسرائيلية)/ui', $text)) {
        return 'الحكومة الإسرائيلية';
    }
    if (preg_match('/(باكستان|إسلام آباد|اسلام آباد|الوسيط الباكستاني|وفد باكستاني)/ui', $text)) {
        return 'باكستان';
    }
    if (preg_match('/(الولايات المتحدة|أمريكا|امريكا|واشنطن)/ui', $text)) {
        return 'الولايات المتحدة';
    }

    if (preg_match('/(وسائل إعلام إسرائيلية|إعلام إسرائيلي|إعلام العدو|الاعلام العبري|القناة\s*1[25]|القناة 12|القناة 15|يديعوت|هآرتس)/ui', $text)) {
        return 'إعلام إسرائيلي';
    }
    if (preg_match('/(التلفزيون الإيراني|إيرنا|وكالة فارس|وكالة تسنيم|إعلام إيراني)/ui', $text)) {
        return 'إيران';
    }
    if ($is_kinetic) {
        return '';
    }

    if (preg_match('/(قناة العربية|العربية_|العربية)/ui', $source_text)) {
        return 'قناة العربية';
    }
    if (preg_match('/(الميادين|مراسل الميادين)/ui', $source_text)) {
        return 'الميادين';
    }
    if (preg_match('/(المنار|مراسل المنار)/ui', $source_text)) {
        return 'المنار';
    }

    return '';
}
