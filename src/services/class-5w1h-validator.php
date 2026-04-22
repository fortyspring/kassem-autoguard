<?php
/**
 * Beiruttime OSINT - 5W1H News Validator
 * نظام التحقق من الشروط الأساسية للأخبار قبل النشر
 * 
 * الشروط الواجب توفرها:
 * - من (Who): الفاعل يجب أن يكون محدداً وليس وسيلة إعلامية
 * - ماذا (What): الحدث واضح ومحدد
 * - لماذا (Why): السياق أو الدافع واضح
 * - كيف (How): الكيفية أو الأداة واضحة (للأحداث العسكرية/الأمنية)
 * - متى (When): الوقت محدد
 * - أين (Where): المكان محدد
 * 
 * @version 2.0.0
 * @package BeiruttimeOSINT
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sod_5W1H_Validator {

    /**
     * الحد الأدنى للنقاط المطلوبة للنشر
     */
    private static $min_publish_score = 70;

    /**
     * عناصر التحقق الأساسية
     */
    private static $validation_elements = [
        'who' => [
            'label' => 'من (الفاعل)',
            'required' => true,
            'weight' => 25,
            'patterns' => [
                '/(قائد|وزير|رئيس|مصدر|مسؤول|ناطق|إعلام|قناة|صحيفة|وكالة|مراسل|موقع|تلفزيون|راديو)/ui',
                '/(حزب الله|حماس|الجهاد|حرس الثورة|الجيش الإسرائيلي|القوات المسلحة|المقاومة)/ui',
                '/(ترمب|بايدن|بوتين|خامنئي|نتنياهو|نصر الله|السيّد|السيد)/ui'
            ],
            'media_patterns' => [
                '/^(إيران الآن|العربية|الميادين|الجزيرة|رويترز|فرانس24|سكاي نيوز|beiruttime|ايران الان|ايران بالعربي|قناة العربية|الاعلام الحربي|قناة الساحات|RNN_Alerts_AR|bintjbeil\.org|انذار الجبهة الداخلية)/ui',
                '/(عن مصدر|عن مسؤول|نقلاً عن|according to|reported by)/ui'
            ]
        ],
        'what' => [
            'label' => 'ماذا (الحدث)',
            'required' => true,
            'weight' => 20,
            'patterns' => [
                '/(غارة|قصف|استهداف|اعتقال|اغتيال|انفجار|إطلاق|اشتباك|توغل|اجتماع|مفاوضات|تصريح|قرار|عقوبات|حصار|إغلاق|فتح|استئناف|تجديد|توقف|تهدئة|تصعيد)/ui',
                '/(strike|raid|attack|meeting|statement|decision|sanctions|ceasefire|escalation)/ui'
            ]
        ],
        'why' => [
            'label' => 'لماذا (السياق/الدافع)',
            'required' => false,
            'weight' => 10,
            'patterns' => [
                '/(رداً على|انتقاماً|بسبب|نتيجة|على خلفية|في إطار|استجابة لـ|تحقيقاً لـ|لمناسبة|احتفالاً بـ|دعماً لـ|تضامناً مع)/ui',
                '/(in response to|as retaliation|due to|following|in support of)/ui',
                '/(نية:|هدف:|غرض:|دافع:|context:|reason:)/ui'
            ]
        ],
        'how' => [
            'label' => 'كيف (الكيفية/الأداة)',
            'required' => false,
            'weight' => 10,
            'patterns' => [
                '/(طائرة مسيرة|صاروخ|قذيفة|مدفعية|دبابة|مروحية|زورق|لغم|سكين|رشاش|قناص|طيران حربي|مسلحة|مفخخة|مسيرة انتحارية)/ui',
                '/(drone|missile|artillery|tank|helicopter|bomb|explosive|rifle|sniper)/ui',
                '/(سلاح:|أداة:|وسيلة:|weapon:|method:)/ui'
            ]
        ],
        'when' => [
            'label' => 'متى (الوقت)',
            'required' => true,
            'weight' => 15,
            'patterns' => [
                '/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/', // 18/04/2026
                '/(\d{1,2}:\d{2}\s*[صم]?)/ui', // 01:50 م
                '/(اليوم|الأمس|غداً|الآن|قبل قليل|مؤخراً|مساءً|صباحاً|ظهراً|ليلاً)/ui',
                '/(today|yesterday|tomorrow|now|recently|this morning|last night)/ui',
                '/(تاريخ:|وقت:|ساعة:|عند الساعة|تمام الساعة|date:|time:)/ui'
            ]
        ],
        'where' => [
            'label' => 'أين (المكان)',
            'required' => true,
            'weight' => 20,
            'patterns' => [
                '/(في |إلى |من |على |بـ|قرب|جنوب|شمال|شرق|غرب|وسط|داخل|خارج|محيط|أطراف|ضواحي|ناحية|بلدة|مدينة|محافظة|منطقة|إقليم)/ui',
                '/(lebanon|syria|iraq|yemen|palestine|israel|iran|gaza|jerusalem|damascus|baghdad|beirut)/ui',
                '/(منطقة:|مكان:|موقع:|إحداثيات|location:|place:|area:)/ui'
            ]
        ]
    ];

    /**
     * قائمة الفواعل المقبولة (نماذج) - تتضمن القادة السياسيين والعسكريين
     * تحديث أبريل 2026
     */
    private static $valid_actors = [
        // المقاومة والفصائل
        'حزب الله' => 'المقاومة الإسلامية (حزب الله)',
        'كتائب القسام' => 'كتائب القسام (حماس)',
        'سرايا القدس' => 'سرايا القدس (الجهاد الإسلامي)',
        'الحرس الثوري' => 'حرس الثورة الإسلامية',
        'الجيش الإسرائيلي' => 'جيش العدو الإسرائيلي',
        'جيش الاحتلال' => 'جيش العدو الإسرائيلي',
        'الحوثيين' => 'أنصار الله (الحوثيون)',
        'البغدادي' => 'داعش',
        'طالبان' => 'حركة طالبان',
        ' PKK' => 'حزب العمال الكردستاني',
        'FSA' => 'الجيش السوري الحر',
        
        // إيران - القيادة السياسية والعسكرية (أبريل 2026)
        'مجتبى خامنئي' => 'المرشد الأعلى مجتبى خامنئي',
        'خامنئي' => 'المرشد الأعلى مجتبى خامنئي',
        'محمد رضا عارف' => 'النائب الأول للرئيس محمد رضا عارف',
        'عزيز نصير زاده' => 'وزير الدفاع الإيراني عزيز نصير زاده',
        'عباس عراقجي' => 'وزير الخارجية الإيراني عباس عراقجي',
        'محمد باقر قاليباف' => 'رئيس مجلس الشورى محمد باقر قاليباف',
        'قاليباف' => 'رئيس مجلس الشورى محمد باقر قاليباف',
        
        // السعودية - القيادة السياسية والعسكرية (أبريل 2026)
        'الملك سلمان' => 'الملك سلمان بن عبد العزيز',
        'سلمان بن عبد العزيز' => 'الملك سلمان بن عبد العزيز',
        'محمد بن سلمان' => 'ولي العهد الأمير محمد بن سلمان',
        'بن سلمان' => 'ولي العهد الأمير محمد بن سلمان',
        'خالد بن سلمان' => 'وزير الدفاع السعودي الأمير خالد بن سلمان',
        'فيصل بن فرحان' => 'وزير الخارجية السعودي الأمير فيصل بن فرحان',
        'عبد الله آل الشيخ' => 'رئيس مجلس الشورى السعودي عبد الله آل الشيخ',
        
        // الإمارات - القيادة السياسية والعسكرية (أبريل 2026)
        'محمد بن زايد' => 'الرئيس الشيخ محمد بن زايد',
        'بن زايد' => 'الرئيس الشيخ محمد بن زايد',
        'محمد بن راشد' => 'نائب الرئيس رئيس الوزراء الشيخ محمد بن راشد',
        'بن راشد' => 'نائب الرئيس رئيس الوزراء الشيخ محمد بن راشد',
        'عبد الله بن زايد' => 'وزير الخارجية الشيخ عبد الله بن زايد',
        'صقر غباش' => 'رئيس المجلس الوطني صقر غباش',
        
        // قطر - القيادة السياسية والعسكرية (أبريل 2026)
        'تميم بن حمد' => 'الأمير الشيخ تميم بن حمد',
        'محمد بن عبد الرحمن' => 'رئيس الوزراء وزير الخارجية الشيخ محمد بن عبد الرحمن',
        'خالد العطية' => 'وزير الدفاع القطري خالد بن محمد العطية',
        'حسن الغانم' => 'رئيس مجلس الشورى حسن الغانم',
        
        // الكويت - القيادة السياسية والعسكرية (أبريل 2026)
        'مشعل الأحمد' => 'الأمير الشيخ مشعل الأحمد',
        'أحمد العبد الله' => 'رئيس الوزراء الشيخ أحمد العبد الله',
        'فهد اليوسف' => 'وزير الدفاع الشيخ فهد اليوسف',
        'عبد الله اليحيا' => 'وزير الخارجية عبد الله اليحيا',
        
        // عمان - القيادة السياسية والعسكرية (أبريل 2026)
        'هيثم بن طارق' => 'السلطان هيثم بن طارق',
        'شهاب بن طارق' => 'نائب السلطان للشؤون الدفاعية شهاب بن طارق',
        'بدر البوسعيدي' => 'وزير الخارجية بدر البوسعيدي',
        'خالد المعولي' => 'رئيس مجلس الشورى خالد المعولي',
        
        // البحرين - القيادة السياسية والعسكرية (أبريل 2026)
        'حمد بن عيسى' => 'الملك حمد بن عيسى',
        'سلمان بن حمد' => 'ولي العهد رئيس الوزراء الأمير سلمان بن حمد',
        'عبد الله النعيمي' => 'وزير الدفاع عبد الله بن حسن النعيمي',
        'عبد اللطيف الزياني' => 'وزير الخارجية عبد اللطيف الزياني',
        'أحمد المسلم' => 'رئيس مجلس النواب أحمد المسلم',
        
        // مصر - القيادة السياسية والعسكرية (أبريل 2026)
        'عبد الفتاح السيسي' => 'الرئيس المصري عبد الفتاح السيسي',
        'السيسي' => 'الرئيس المصري عبد الفتاح السيسي',
        'مصطفى مدبولي' => 'رئيس الوزراء المصري مصطفى مدبولي',
        'عبد المجيد صقر' => 'وزير الدفاع المصري عبد المجيد صقر',
        'بدر عبد العاطي' => 'وزير الخارجية المصري بدر عبد العاطي',
        'حنفي جبالي' => 'رئيس مجلس النواب حنفي جبالي',
        
        // العراق - القيادة السياسية والعسكرية (أبريل 2026)
        'نزار آميدي' => 'رئيس الجمهورية العراقي نزار آميدي',
        'نوري المالكي' => 'رئيس الوزراء العراقي نوري المالكي',
        'ثابت العباسي' => 'وزير الدفاع العراقي ثابت العباسي',
        'فؤاد حسين' => 'وزير الخارجية العراقي فؤاد حسين',
        'محمود المشهداني' => 'رئيس البرلمان العراقي محمود المشهداني',
        
        // سوريا - القيادة السياسية والعسكرية (أبريل 2026)
        'أحمد الشرع' => 'الرئيس السوري أحمد الشرع',
        'محمد بشير' => 'رئيس الوزراء السوري محمد بشير',
        'مرهف أبو قصرة' => 'وزير الدفاع السوري مرهف أبو قصرة',
        'أسعد الشيباني' => 'وزير الخارجية السوري أسعد الشيباني',
        
        // لبنان - القيادة السياسية والعسكرية (أبريل 2026)
        'نواف سلام' => 'رئيس الوزراء اللبناني نواف سلام',
        'ميشال منسى' => 'وزير الدفاع اللبناني ميشال منسى',
        'يوسف رجي' => 'وزير الخارجية اللبناني يوسف رجي',
        'نبيه بري' => 'رئيس البرلمان اللبناني نبيه بري',
        'فضل الله' => 'النائب فضل الله',
        
        // الأردن - القيادة السياسية والعسكرية (أبريل 2026)
        'عبد الله الثاني' => 'الملك الأردني عبد الله الثاني',
        'جعفر حسان' => 'رئيس الوزراء الأردني جعفر حسان',
        'أيمن الصفدي' => 'وزير الخارجية الأردني أيمن الصفدي',
        'أحمد الصفدي' => 'رئيس البرلمان الأردني أحمد الصفدي',
        
        // فلسطين - القيادة السياسية والعسكرية (أبريل 2026)
        'محمود عباس' => 'الرئيس الفلسطيني محمود عباس',
        'محمد مصطفى' => 'رئيس الوزراء الفلسطيني محمد مصطفى',
        'زياد هب الريح' => 'وزير الداخلية الفلسطيني زياد هب الريح',
        'روحي فتوح' => 'رئيس المجلس الوطني روحي فتوح',
        
        // المغرب - القيادة السياسية والعسكرية (أبريل 2026)
        'محمد السادس' => 'الملك المغربي محمد السادس',
        'عزيز أخنوش' => 'رئيس الوزراء المغربي عزيز أخنوش',
        'عبد اللطيف لوديي' => 'وزير الدفاع المغربي عبد اللطيف لوديي',
        'ناصر بوريطة' => 'وزير الخارجية المغربي ناصر بوريطة',
        'راشيد الطالبي العلمي' => 'رئيس البرلمان المغربي راشيد الطالبي العلمي',
        
        // الجزائر - القيادة السياسية والعسكرية (أبريل 2026)
        'عبد المجيد تبون' => 'الرئيس الجزائري عبد المجيد تبون',
        'نذير العرباوي' => 'رئيس الوزراء الجزائري نذير العرباوي',
        'أحمد عطاف' => 'وزير الخارجية الجزائري أحمد عطاف',
        'إبراهيم بوغالي' => 'رئيس البرلمان الجزائري إبراهيم بوغالي',
        
        // تونس - القيادة السياسية والعسكرية (أبريل 2026)
        'قيس سعيد' => 'الرئيس التونسي قيس سعيد',
        'كمال المدوري' => 'رئيس الوزراء التونسي كمال المدوري',
        'خالد السهيلي' => 'وزير الدفاع التونسي خالد السهيلي',
        'محمد علي النفطي' => 'وزير الخارجية التونسي محمد علي النفطي',
        'إبراهيم بودربالة' => 'رئيس البرلمان التونسي إبراهيم بودربالة',
        
        // ليبيا - القيادة السياسية والعسكرية (أبريل 2026)
        'محمد المنفي' => 'رئيس المجلس الرئاسي محمد المنفي',
        'عبد الحميد الدبيبة' => 'رئيس الوزراء الليبي عبد الحميد الدبيبة',
        'الطاهر الباعور' => 'وزير الخارجية الليبي الطاهر الباعور',
        'عقيلة صالح' => 'رئيس البرلمان عقيلة صالح',
        
        // السودان - القيادة السياسية والعسكرية (أبريل 2026)
        'عبد الفتاح البرهان' => 'رئيس المجلس السيادي عبد الفتاح البرهان',
        'كامل إدريس' => 'رئيس الوزراء السوداني كامل إدريس',
        'ياسين إبراهيم' => 'وزير الدفاع السوداني ياسين إبراهيم',
        'علي يوسف الشريف' => 'وزير الخارجية السوداني علي يوسف الشريف',
        
        // اليمن - القيادة السياسية والعسكرية (أبريل 2026)
        'رشاد العليمي' => 'رئيس المجلس الرئاسي اليمني رشاد العليمي',
        'شائع الزنداني' => 'رئيس الوزراء وزير الخارجية شائع محسن الزنداني',
        'طاهر العقيلي' => 'وزير الدفاع اليمني طاهر العقيلي',
        'سلطان البركاني' => 'رئيس البرلمان اليمني سلطان البركاني',
        
        // موريتانيا - القيادة السياسية والعسكرية (أبريل 2026)
        'محمد ولد الغزواني' => 'الرئيس الموريتاني محمد ولد الغزواني',
        'المختار ولد أجاي' => 'رئيس الوزراء المختار ولد أجاي',
        'حننه ولد سيدي' => 'وزير الدفاع حننه ولد سيدي',
        'محمد سالم ولد مرزوك' => 'وزير الخارجية محمد سالم ولد مرزوك',
        'دليتا محمد دليتا' => 'رئيس البرلمان دليتا محمد دليتا',
        
        // الصومال - القيادة السياسية والعسكرية (أبريل 2026)
        'حسن شيخ محمود' => 'الرئيس الصومالي حسن شيخ محمود',
        'حمزة عبدي بري' => 'رئيس الوزراء الصومالي حمزة عبدي بري',
        'عبد القادر محمد نور' => 'وزير الدفاع عبد القادر محمد نور',
        'أحمد معلم فقي' => 'وزير الخارجية أحمد معلم فقي',
        'شيخ آدم مدوبي' => 'رئيس البرلمان شيخ آدم مدوبي',
        
        // جيبوتي - القيادة السياسية والعسكرية (أبريل 2026)
        'إسماعيل عمر جيله' => 'الرئيس الجيبوتي إسماعيل عمر جيله',
        'عبد القادر كامل محمد' => 'رئيس الوزراء عبد القادر كامل محمد',
        'برهان عبده محمد' => 'وزير الدفاع برهان عبده محمد',
        'محمود علي يوسف' => 'وزير الخارجية محمود علي يوسف',
        'دليتا محمد دليتا' => 'رئيس البرلمان دليتا محمد دليتا',
        
        // روسيا - القيادة السياسية والعسكرية (أبريل 2026)
        'فلاديمير بوتين' => 'الرئيس الروسي فلاديمير بوتين',
        'بوتين' => 'الرئيس الروسي فلاديمير بوتين',
        'ميخائيل ميشوستين' => 'رئيس الوزراء الروسي ميخائيل ميشوستين',
        'أندريه بيلوسوف' => 'وزير الدفاع الروسي أندريه بيلوسوف',
        'سيرغي لافروف' => 'وزير الخارجية الروسي سيرغي لافروف',
        'لافروف' => 'وزير الخارجية الروسي سيرغي لافروف',
        'فياتشيسلاف فولودين' => 'رئيس الدوما فياتشيسلاف فولودين',
        
        // الصين - القيادة السياسية والعسكرية (أبريل 2026)
        'شي جين بينغ' => 'الرئيس الصيني شي جين بينغ',
        'لي تشيانغ' => 'رئيس الوزراء الصيني لي تشيانغ',
        'دونغ جون' => 'وزير الدفاع الصيني دونغ جون',
        'وانغ يي' => 'وزير الخارجية الصيني وانغ يي',
        'تشاو ليجي' => 'رئيس البرلمان الصيني تشاو ليجي',
        
        // الهند - القيادة السياسية والعسكرية (أبريل 2026)
        'دروبادي مورمو' => 'الرئيس الهندي دروبادي مورمو',
        'ناريندرا مودي' => 'رئيس الوزراء الهندي ناريندرا مودي',
        'راجناث سينغ' => 'وزير الدفاع الهندي راجناث سينغ',
        'س. جايشانكار' => 'وزير الخارجية الهندي س. جايشانكار',
        'أوم بيرلا' => 'رئيس البرلمان أوم بيرلا',
        
        // أمريكا - القيادة السياسية والعسكرية (أبريل 2026)
        'دونالد ترامب' => 'الرئيس الأمريكي دونالد ترامب',
        'ترامب' => 'الرئيس الأمريكي دونالد ترامب',
        'بيت هيغسيث' => 'وزير الدفاع الأمريكي بيت هيغسيث',
        'ماركو روبيو' => 'وزير الخارجية الأمريكي ماركو روبيو',
        'مايك جونسون' => 'رئيس مجلس النواب مايك جونسون',
        
        // تركيا - القيادة السياسية والعسكرية (أبريل 2026)
        'رجب طيب أردوغان' => 'الرئيس التركي رجب طيب أردوغان',
        'أردوغان' => 'الرئيس التركي رجب طيب أردوغان',
        'يشار غولر' => 'وزير الدفاع التركي يشار غولر',
        'هاكان فيدان' => 'وزير الخارجية التركي هاكان فيدان',
        'نعمان كورتولموش' => 'رئيس البرلمان التركي نعمان كورتولموش',
        
        // فرنسا - القيادة السياسية والعسكرية (أبريل 2026)
        'إيمانويل ماكرون' => 'الرئيس الفرنسي إيمانويل ماكرون',
        'ماكرون' => 'الرئيس الفرنسي إيمانويل ماكرون',
        'ميشيل بارنييه' => 'رئيس الوزراء الفرنسي ميشيل بارنييه',
        'سيباستيان ليكورنو' => 'وزير الدفاع الفرنسي سيباستيان ليكورنو',
        'جان نويل بارو' => 'وزير الخارجية الفرنسي جان نويل بارو',
        'يائيل برون بيفيه' => 'رئيسة الجمعية الوطنية يائيل برون بيفيه',
        
        // اليابان - القيادة السياسية والعسكرية (أبريل 2026)
        'ناروهيتو' => 'الإمبراطور الياباني ناروهيتو',
        'شايغيرو إيشيبا' => 'رئيس الوزراء الياباني شايغيرو إيشيبا',
        'جين ناكاتاني' => 'وزير الدفاع الياباني جين ناكاتاني',
        'تاكيشي إيوايا' => 'وزير الخارجية الياباني تاكيشي إيوايا',
        'فوكوشيرو نكاجاوا' => 'رئيس البرلمان فوكوشيرو نكاجاوا',
        
        // كوريا الجنوبية - القيادة السياسية والعسكرية (أبريل 2026)
        'لي جيه ميونغ' => 'الرئيس الكوري لي جيه ميونغ',
        'هان دوك-سو' => 'رئيس الوزراء الكوري هان دوك-سو',
        'كيم يونغ-هيون' => 'وزير الدفاع الكوري كيم يونغ-هيون',
        'تشو تاي-يول' => 'وزير الخارجية الكوري تشو تاي-يول',
        'وو وون-شيك' => 'رئيس البرلمان وو وون-شيك',
        
        // قادة آخرون
        'ترمب' => 'الرئيس الأمريكي دونالد ترامب',
        'بايدن' => 'الرئيس الأمريكي جو بايدن',
        'نتنياهو' => 'رئيس الوزراء الإسرائيلي',
        'نصر الله' => 'الأمين العام لحزب الله',
        'السيّد' => 'الأمين العام لحزب الله',
        'السيد' => 'الأمين العام لحزب الله'
    ];

    /**
     * وسائل الإعلام التي لا يمكن أن تكون فاعلاً
     */
    private static $media_outlets = [
        'إيران الآن', 'ايران الان', 'ايران بالعربي', 'العربية', 'قناة العربية',
        'الميادين', 'الجزيرة', 'رويترز', 'فرانس24', 'سكاي نيوز', 'beiruttime',
        'الاعلام الحربي', 'قناة الساحات', 'RNN_Alerts_AR', 'bintjbeil.org',
        'انذار الجبهة الداخلية', 'تسنيم', 'فارس', 'إرنا', 'واس', 'سانا',
        'معاً', 'وطن', 'دنيا الوطن', 'عربي21', 'الخليج أونلاين', 'العين الإخبارية',
        'المشهد', 'دهشة', ' Mashhad', 'Al-Mayadeen', 'Al-Alam', 'Press TV',
        'Wall Street Journal', 'New York Times', 'Washington Post', 'CNN',
        'BBC', 'Guardian', 'Reuters', 'AP', 'AFP', 'Xinhua', 'RT', 'Sputnik',
        'روسيا الاتحادية', 'ايران الان |', 'إيران الآن |', 'tancker trackers', 'تانكر تراكرز',
        'الشمال نيوز', 'جيروزاليم بوست', 'وول ستريت جورنال', 'واشنطن بوست', 'أكسيوس', 'اللواء'
    ];

    /**
     * كلمات/مواقع استراتيجية ترفع أولوية الخبر عسكرياً حتى لو كان بعض 5W1H ناقصاً.
     */
    private static $strategic_keywords = [
        'منشأة نووية','مفاعل','قاعدة أمريكية','قاعدة جوية','قاعدة بحرية','قيادة الأركان',
        'حاملة طائرات','غواصة','سرب','صواريخ بالستية','صواريخ فرط صوتية','دفاع جوي','منظومة دفاع',
        'مطار بن غوريون','تل أبيب','القدس','دمشق','بيروت','بغداد','طهران','صنعاء','غزة',
        'الحديدة','باب المندب','مضيق هرمز','البحر الأحمر','الحدود اللبنانية','الجليل','الضفة الغربية'
    ];

    /**
     * تصنيف مبدئي لطبيعة الحدث العسكري/الأمني.
     */
    private static $military_taxonomy = [
        'cyber' => ['هجوم سيبراني','اختراق','تعطيل إلكتروني','برمجية خبيثة','cyber','malware','ransomware'],
        'strategic' => ['منشأة نووية','قيادة الأركان','حاملة طائرات','مضيق هرمز','قاعدة أمريكية','صواريخ بالستية','مطار بن غوريون'],
        'operational' => ['مناورات','حشد','إعادة انتشار','تعزيزات','إنزال','اعتراض','اعادة تموضع','تموضع'],
        'tactical' => ['قصف','استهداف','اشتباك','توغل','اغتيال','كمين','غارة','قنص','طائرة مسيرة','صاروخ','قذيفة'],
        'psychological' => ['تهديد','رسالة سياسية','تحذير','دعاية','تحريض','خطاب','تصريح ناري'],
    ];



    /**
     * Polyfill-safe stripos for environments where mbstring is unavailable.
     */
    private static function stripos_safe(string $haystack, string $needle): int|false {
        if ($needle === '') {
            return false;
        }
        if (function_exists('mb_stripos')) {
            return self::stripos_safe($haystack, $needle);
        }
        return stripos($haystack, $needle);
    }

    /**
     * Polyfill-safe strtolower for environments where mbstring is unavailable.
     */
    private static function strtolower_safe(string $value): string {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    /**
     * تطبيع نص العنوان للفحص الصارم
     */
    private static function normalize_title_text(string $title): string {
        $title = wp_strip_all_tags($title);
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s+/u', ' ', trim($title));
        return trim((string)$title);
    }

    /**
     * رفض العناوين المشوّهة أو غير الخبرية قبل أي تحليل أعمق
     */
    private static function is_invalid_stub_title(string $title): array {
        $title = self::normalize_title_text($title);
        if ($title === '') {
            return ['invalid' => true, 'reason' => 'empty_title'];
        }

        if (preg_match('/^\d{1,2}[\/\-.\s]\d{1,2}[\/\-.\s]\d{2,4}$/u', $title)) {
            return ['invalid' => true, 'reason' => 'date_only_title'];
        }

        if (preg_match('/^\/[A-Za-z0-9_\.]+$/u', $title)) {
            return ['invalid' => true, 'reason' => 'handle_only_title'];
        }

        if (preg_match('/^\d{1,2}[\/\-.\s]\d{1,2}[\/\-.\s]\d{2,4}\s+\/[A-Za-z0-9_\.]+$/u', $title)) {
            return ['invalid' => true, 'reason' => 'date_and_handle_title'];
        }

        $word_count = preg_match_all('/[\p{L}\p{N}_]+/u', $title, $m);
        if ((int)$word_count < 4) {
            return ['invalid' => true, 'reason' => 'too_short_title'];
        }

        $letters_count = preg_match_all('/\p{L}/u', $title, $m1);
        $symbols_count = preg_match_all('/[\/\-\._:#]/u', $title, $m2);
        if ((int)$letters_count < 6 && (int)$symbols_count >= 1) {
            return ['invalid' => true, 'reason' => 'low_semantic_title'];
        }

        return ['invalid' => false, 'reason' => ''];
    }

    /**
     * تحقق صارم من اكتمال 5W1H داخل العنوان نفسه
     */
    private static function check_title_completeness(string $title, array $meta = []): array {
        $normalized_title = self::normalize_title_text($title);
        $invalid = self::is_invalid_stub_title($normalized_title);

        $result = [
            'valid' => false,
            'reason' => $invalid['reason'] ?? '',
            'found' => [],
            'missing' => [],
            'title' => $normalized_title,
        ];

        if (!empty($invalid['invalid'])) {
            $result['missing'] = ['من (الفاعل)','ماذا (الحدث)','لماذا (السياق/الدافع)','كيف (الكيفية/الأداة)','متى (الوقت)','أين (المكان)'];
            return $result;
        }

        foreach (self::$validation_elements as $element => $config) {
            $check_result = self::check_element($element, $normalized_title, [], $config);
            if ($check_result['found']) {
                $result['found'][$element] = $check_result['extracted_value'] !== '' ? $check_result['extracted_value'] : true;
            } else {
                $result['missing'][] = $config['label'];
            }
        }

        $result['valid'] = empty($result['missing']);
        if (!$result['valid'] && $result['reason'] === '') {
            $result['reason'] = 'missing_title_5w1h';
        }

        return $result;
    }

    /**
     * التحقق من خبر قبل النشر
     * 
     * @param array $news_data بيانات الخبر
     * @return array نتيجة التحقق
     */
    public static function validate_before_publish(array $news_data): array {
        $result = [
            'approved' => false,
            'score' => 0,
            'missing_elements' => [],
            'warnings' => [],
            'details' => [],
            'suggested_actor' => '',
            'corrected_data' => $news_data,
            'hard_reject' => false,
            'rejection_reason' => '',
            'title_audit' => [],
            'decision' => 'reject',
            'decision_label' => 'مرفوض',
            'military_classification' => 'general',
            'strategic_priority' => 'normal',
            'strategic_boost' => 0,
            'entities' => [
                'actors' => [],
                'locations' => [],
                'weapons' => [],
                'tactics' => [],
                'times' => [],
                'motives' => [],
            ],
        ];

        $title = (string)($news_data['title'] ?? '');
        $content = (string)($news_data['content'] ?? '');
        $meta = is_array($news_data['meta'] ?? null) ? $news_data['meta'] : [];
        $actor = (string)($news_data['actor'] ?? ($meta['who'] ?? ''));
        $region = (string)($news_data['region'] ?? ($meta['where'] ?? ''));
        $date = (string)($news_data['date'] ?? ($meta['when'] ?? ''));
        $intelType = (string)($news_data['intel_type'] ?? ($meta['what'] ?? ''));

        $normalized_title = self::normalize_title_text($title);
        $title_audit = self::check_title_completeness($normalized_title, $meta);
        $result['title_audit'] = $title_audit;

        $text = trim($normalized_title . ' ' . $content . ' ' . $actor . ' ' . $region . ' ' . $date . ' ' . $intelType);
        $total_weight = 0;
        $earned_score = 0;

        foreach (self::$validation_elements as $element => $config) {
            $check_result = self::check_element($element, $text, $meta, $config);
            $result['details'][$element] = $check_result;

            if ($check_result['found']) {
                $earned_score += min((int)$check_result['score'], (int)$config['weight'] + 5);
            } elseif (!empty($config['required'])) {
                $result['missing_elements'][] = $config['label'];
            }

            $total_weight += (int)$config['weight'];
        }

        $actor_check = self::validate_actor([
            'actor' => $actor,
            'title' => $normalized_title,
            'content' => $content,
        ]);
        $result['details']['actor_validation'] = $actor_check;

        if (!$actor_check['is_valid']) {
            $result['warnings'][] = $actor_check['message'];
            if (!empty($actor_check['suggested_actor'])) {
                $result['suggested_actor'] = $actor_check['suggested_actor'];
            }
        }

        $baseScore = $total_weight > 0 ? (int)round(($earned_score / $total_weight) * 100) : 0;
        $strategicBoost = self::compute_strategic_boost($text);
        $entities = self::extract_military_entities($text, $meta, $actor, $region, $date);
        $militaryClassification = self::detect_military_classification($text, $intelType);
        $finalScore = min(100, $baseScore + $strategicBoost);

        $result['entities'] = $entities;
        $result['military_classification'] = $militaryClassification;
        $result['strategic_boost'] = $strategicBoost;
        $result['strategic_priority'] = $strategicBoost >= 15 ? 'high' : ($strategicBoost > 0 ? 'elevated' : 'normal');
        $result['score'] = $finalScore;

        $hasMinimumCore = !empty($result['details']['who']['found'])
            && !empty($result['details']['what']['found'])
            && !empty($result['details']['where']['found']);

        $titleLooksBroken = !empty($title_audit['reason'])
            && in_array($title_audit['reason'], ['empty_title', 'date_only_title', 'date_and_handle_title', 'handle_only_title', 'too_short_title', 'low_semantic_title'], true);

        if ($titleLooksBroken && $strategicBoost === 0) {
            $result['hard_reject'] = true;
            $result['rejection_reason'] = (string)($title_audit['reason'] ?? 'invalid_title');
            $result['decision'] = 'reject';
            $result['decision_label'] = 'مرفوض';
            $result['message'] = 'العنوان مشوّه أو غير خبري، وتم رفض الخبر قبل النشر.';
            return $result;
        }

        if ($finalScore >= 85 && $hasMinimumCore) {
            $result['approved'] = true;
            $result['decision'] = 'publish';
            $result['decision_label'] = 'نشر مباشر';
        } elseif ($finalScore >= 65 && $hasMinimumCore) {
            $result['approved'] = false;
            $result['decision'] = 'review';
            $result['decision_label'] = 'مراجعة سريعة';
        } elseif ($finalScore >= 40 || $strategicBoost >= 15) {
            $result['approved'] = false;
            $result['decision'] = 'draft';
            $result['decision_label'] = 'مسودة للمراجعة';
        } else {
            $result['approved'] = false;
            $result['decision'] = 'reject';
            $result['decision_label'] = 'مرفوض';
        }

        if (!$result['approved']) {
            if ($result['decision'] === 'review') {
                $result['message'] = 'الخبر يحمل عناصر أساسية كافية لكنه يحتاج مراجعة سريعة قبل النشر.';
            } elseif ($result['decision'] === 'draft') {
                $result['message'] = 'الخبر مهم جزئياً لكن عناصر 5W1H غير مكتملة بما يكفي، فتم تحويله إلى مسودة.';
            } else {
                $result['message'] = 'الخبر غير مستوفٍ للشروط العسكرية الأساسية وتم رفضه.';
            }
        } else {
            $result['message'] = 'الخبر اجتاز بوابة 5Ws العسكرية وهو جاهز للنشر.';
        }

        if (!empty($result['missing_elements']) && $result['decision'] !== 'reject') {
            $result['warnings'][] = 'عناصر ناقصة: ' . implode('، ', $result['missing_elements']);
        }

        return $result;
    }

    /**
     * احتساب تعزيز للأخبار ذات القيمة الاستراتيجية العالية.
     */
    private static function compute_strategic_boost(string $text): int {
        $boost = 0;
        foreach (self::$strategic_keywords as $keyword) {
            if ($keyword !== '' && self::stripos_safe($text, $keyword) !== false) {
                $boost += 5;
            }
        }
        return min(20, $boost);
    }

    /**
     * استخراج كيانات عسكرية أساسية دون استخدام AI، اعتماداً على أنماط وقيم الميتا.
     */
    private static function extract_military_entities(string $text, array $meta, string $actor, string $region, string $date): array {
        $entities = [
            'actors' => [],
            'locations' => [],
            'weapons' => [],
            'tactics' => [],
            'times' => [],
            'motives' => [],
        ];

        if ($actor !== '') {
            $entities['actors'][] = $actor;
        }
        if ($region !== '') {
            $entities['locations'][] = $region;
        }
        if ($date !== '') {
            $entities['times'][] = $date;
        }

        foreach (self::$valid_actors as $candidate => $canonical) {
            if ($candidate !== '' && self::stripos_safe($text, $candidate) !== false) {
                $entities['actors'][] = $canonical;
            }
        }

        $patternMap = [
            'locations' => '/(?:في|إلى|من|على|قرب|داخل|محيط|جنوب|شمال|شرق|غرب)\s+([\p{L}\p{N}\-]{2,40}(?:\s+[\p{L}\p{N}\-]{2,40}){0,3})/u',
            'weapons' => '/(طائرة مسيرة|مسيرة انتحارية|صاروخ(?:\s+[\p{L}\-]+)?|قذيفة|مدفعية|دبابة ميركافا|دبابة|راجمة|عبوة ناسفة|هجوم سيبراني|منظومة دفاع جوي|قناص|طيران حربي)/u',
            'tactics' => '/(قصف|استهداف|توغل|اغتيال|كمين|اشتباك|حشد|اعتراض|تسلل|تحليق|تموضع|إعادة انتشار|حصار|إغلاق|مناورة|إنزال)/u',
            'motives' => '/(رداً على|انتقاماً|بسبب|نتيجة|على خلفية|في إطار|دعماً لـ|استجابة لـ|تمهيداً لـ)/u',
            'times' => '/(اليوم|الأمس|غداً|الآن|قبل قليل|صباحاً|مساءً|ليلاً|فجر اليوم|\d{1,2}:\d{2}|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/u',
        ];

        foreach ($patternMap as $bucket => $pattern) {
            if (preg_match_all($pattern, $text, $matches) && !empty($matches[1] ?? $matches[0])) {
                $values = $matches[1] ?? $matches[0];
                foreach ($values as $value) {
                    $value = trim((string)$value);
                    if ($value !== '') {
                        $entities[$bucket][] = $value;
                    }
                }
            }
        }

        foreach ($entities as $key => $values) {
            $entities[$key] = array_values(array_unique(array_filter(array_map('trim', $values))));
        }

        foreach (['who' => 'actors', 'where' => 'locations', 'when' => 'times', 'why' => 'motives', 'how' => 'weapons'] as $metaKey => $bucket) {
            if (!empty($meta[$metaKey]) && is_string($meta[$metaKey])) {
                $entities[$bucket][] = trim($meta[$metaKey]);
                $entities[$bucket] = array_values(array_unique(array_filter(array_map('trim', $entities[$bucket]))));
            }
        }

        return $entities;
    }

    /**
     * تصنيف عسكري أولي للخبر من النص الخام.
     */
    private static function detect_military_classification(string $text, string $intelType = ''): string {
        foreach (self::$military_taxonomy as $label => $keywords) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && self::stripos_safe($text, $keyword) !== false) {
                    return $label;
                }
            }
        }

        $normalizedIntelType = self::strtolower_safe(trim($intelType));
        if ($normalizedIntelType !== '') {
            if (self::stripos_safe($normalizedIntelType, 'سيبر') !== false) {
                return 'cyber';
            }
            if (self::stripos_safe($normalizedIntelType, 'استراتي') !== false) {
                return 'strategic';
            }
            if (self::stripos_safe($normalizedIntelType, 'عمليات') !== false) {
                return 'operational';
            }
            if (self::stripos_safe($normalizedIntelType, 'تكتي') !== false || self::stripos_safe($normalizedIntelType, 'أمني') !== false) {
                return 'tactical';
            }
        }

        return 'general';
    }

    /**
     * التحقق من عنصر واحد
     */
    private static function check_element(string $element, string $text, array $meta, array $config): array {
        $result = [
            'found' => false,
            'score' => 0,
            'matches' => [],
            'extracted_value' => ''
        ];

        // البحث عن الأنماط
        foreach ($config['patterns'] as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                $result['found'] = true;
                $result['matches'] = array_merge($result['matches'], $matches[0]);
                
                // استخراج القيمة
                if (!empty($matches[0])) {
                    $result['extracted_value'] = trim($matches[0][0]);
                }
            }
        }

        // التحقق من البيانات الوصفية
        if (!$result['found'] && isset($meta[$element])) {
            $result['found'] = true;
            $result['extracted_value'] = $meta[$element];
        }

        // حساب النقاط
        if ($result['found']) {
            $match_count = count(array_unique($result['matches']));
            $bonus = min(5, $match_count); // مكافأة تعدد المطابقات
            $result['score'] = $config['weight'] + $bonus;
        }

        return $result;
    }

    /**
     * التحقق من صحة الفاعل
     */
    private static function validate_actor(array $news_data): array {
        $result = [
            'is_valid' => true,
            'message' => '',
            'suggested_actor' => '',
            'actor_type' => 'unknown'
        ];

        $actor = $news_data['actor'] ?? '';
        $title = $news_data['title'] ?? '';
        $content = $news_data['content'] ?? '';
        $text = $title . ' ' . $content;

        if (empty($actor)) {
            $result['is_valid'] = false;
            $result['message'] = 'الفاعل غير محدد';
            $result['actor_type'] = 'missing';
            
            // محاولة استنتاج الفاعل
            $inferred = self::infer_actor_from_text($text);
            if (!empty($inferred)) {
                $result['suggested_actor'] = $inferred;
            }
            
            return $result;
        }

        // التحقق مما إذا كان الفاعل وسيلة إعلامية
        foreach (self::$media_outlets as $media) {
            if (self::stripos_safe($actor, $media) !== false || self::stripos_safe($text, $media) === 0) {
                $result['is_valid'] = false;
                $result['message'] = "الفاعل المحدد هو وسيلة إعلامية ($actor) وليس جهة فاعلة حقيقية";
                $result['actor_type'] = 'media';
                
                // محاولة استخراج الفاعل الحقيقي
                $real_actor = self::extract_real_actor_from_media_report($text, $actor);
                if (!empty($real_actor)) {
                    $result['suggested_actor'] = $real_actor;
                }
                
                return $result;
            }
        }

        // التحقق من الأنماط الشائعة للأخطاء
        if (preg_match('/^(.+?)\s*(عن|نقلاً عن|according to|reported by)/ui', $actor, $m)) {
            $result['is_valid'] = false;
            $result['message'] = "الفاعل يحتوي على إشارة لمصدر ($actor)";
            $result['actor_type'] = 'media_reference';
            $result['suggested_actor'] = trim($m[1]);
            return $result;
        }

        // التحقق من وجود الفاعل في القائمة المعتمدة
        foreach (self::$valid_actors as $key => $canonical) {
            if (self::stripos_safe($actor, $key) !== false) {
                $result['actor_type'] = 'validated';
                if ($actor !== $canonical) {
                    $result['suggested_actor'] = $canonical;
                }
                return $result;
            }
        }

        $result['actor_type'] = 'unverified';
        return $result;
    }

    /**
     * استنتاج الفاعل من النص
     */
    private static function infer_actor_from_text(string $text): string {
        // أنماط لاستخراج الفاعل
        $patterns = [
            '/(?:قال|صرّح|أعلن|أكّد|ذكر|أفاد|وفقاً لـ|عن لسان)\s+([^،\.:\n]{5,60})/ui' => 1,
            '/^(.+?)[\s:]+(?:يقول|يؤكد|يعلن|صرّح)/ui' => 1,
            '/(?:بعد(?:ما)?|على إثر)\s+(?:قامت?|شنّت?|نفّذت?|أعلنت?)\s+([^\s\.]{3,40})/ui' => 1,
            '/(?:في عملية|عملية)\s+(?:شنّها|نفّذها|قام بها)\s+([^\s\.]{3,40})/ui' => 1,
        ];

        foreach ($patterns as $pattern => $group) {
            if (preg_match($pattern, $text, $matches)) {
                $candidate = trim($matches[$group]);
                // التحقق من أن المرشح ليس وسيلة إعلامية
                foreach (self::$media_outlets as $media) {
                    if (self::stripos_safe($candidate, $media) !== false) {
                        continue 2;
                    }
                }
                return $candidate;
            }
        }

        return '';
    }

    /**
     * استخراج الفاعل الحقيقي من تقرير إعلامي
     */
    private static function extract_real_actor_from_media_report(string $text, string $media): string {
        // إزالة اسم الوسيلة من البداية
        $clean_text = preg_replace('/^' . preg_quote($media, '/') . '[\s|:|-]*/ui', '', $text);
        
        // البحث عن الفاعل في النص المتبقي
        return self::infer_actor_from_text($clean_text);
    }

    /**
     * تصحيح الفاعل تلقائياً
     */
    public static function auto_correct_actor(string $actor): string {
        // تطبيع النص
        $normalized = self::normalize_actor_name($actor);
        
        // البحث في القائمة
        foreach (self::$valid_actors as $key => $canonical) {
            if (self::stripos_safe($normalized, self::normalize_actor_name($key)) !== false) {
                return $canonical;
            }
        }
        
        // إذا كان الفاعل وسيلة إعلامية، إرجاع "فاعل غير محسوم"
        foreach (self::$media_outlets as $media) {
            if (self::stripos_safe($normalized, self::normalize_actor_name($media)) !== false) {
                return 'فاعل غير محسوم';
            }
        }
        
        return $actor;
    }

    /**
     * تطبيع اسم الفاعل
     */
    private static function normalize_actor_name(string $name): string {
        $replacements = [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي',
            '‌' => '', '‍' => '',
            ' ' => ' ', "\u{200C}" => '', "\u{200D}" => ''
        ];
        
        $normalized = strtr($name, $replacements);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        return self::strtolower_safe(trim($normalized));
    }

    /**
     * واجهة تقرير التحقق
     */
    public static function render_validation_report(array $validation_result): string {
        $html = '<div class="validation-report" style="border:1px solid #ddd;padding:15px;margin:10px 0;border-radius:5px;">';
        
        // الحالة العامة
        $status_color = $validation_result['approved'] ? '#28a745' : '#dc3545';
        $status_icon = $validation_result['approved'] ? '✅' : '❌';
        $html .= "<h3 style='color:$status_color;margin-top:0;'>$status_icon حالة التحقق: " . 
                ($validation_result['approved'] ? 'مقبول للنشر' : 'مرفوض للنشر') . "</h3>";
        
        // النقاط
        $html .= "<p><strong>النقاط:</strong> {$validation_result['score']}%</p>";
        
        // الرسالة
        $html .= "<p>{$validation_result['message']}</p>";
        
        // العناصر الناقصة
        if (!empty($validation_result['missing_elements'])) {
            $html .= '<div style="background:#fff3cd;padding:10px;margin:10px 0;border-radius:3px;">';
            $html .= '<strong>⚠️ العناصر الناقصة:</strong><ul>';
            foreach ($validation_result['missing_elements'] as $element) {
                $html .= "<li>$element</li>";
            }
            $html .= '</ul></div>';
        }
        
        // التحذيرات
        if (!empty($validation_result['warnings'])) {
            $html .= '<div style="background:#ffeeba;padding:10px;margin:10px 0;border-radius:3px;">';
            $html .= '<strong>⚡ تحذيرات:</strong><ul>';
            foreach ($validation_result['warnings'] as $warning) {
                $html .= "<li>$warning</li>";
            }
            $html .= '</ul></div>';
        }
        
        // الفاعل المقترح
        if (!empty($validation_result['suggested_actor'])) {
            $html .= '<div style="background:#d4edda;padding:10px;margin:10px 0;border-radius:3px;">';
            $html .= "<strong>💡 الفاعل المقترح:</strong> {$validation_result['suggested_actor']}";
            $html .= '</div>';
        }
        
        // التفاصيل
        $html .= '<details style="margin-top:15px;"><summary><strong>📊 تفاصيل التحقق</strong></summary>';
        $html .= '<table style="width:100%;border-collapse:collapse;margin-top:10px;">';
        $html .= '<tr style="background:#f8f9fa;"><th style="border:1px solid #ddd;padding:8px;">العنصر</th>';
        $html .= '<th style="border:1px solid #ddd;padding:8px;">الحالة</th>';
        $html .= '<th style="border:1px solid #ddd;padding:8px;">النقاط</th>';
        $html .= '<th style="border:1px solid #ddd;padding:8px;">القيمة المستخرجة</th></tr>';
        
        foreach ($validation_result['details'] as $element => $detail) {
            if (!is_array($detail)) continue;
            
            $status = $detail['found'] ? '✅ موجود' : '❌ مفقود';
            $status_bg = $detail['found'] ? '#d4edda' : '#f8d7da';
            $value = htmlspecialchars($detail['extracted_value'] ?? '-');
            
            $html .= "<tr><td style='border:1px solid #ddd;padding:8px;'>{$element}</td>";
            $html .= "<td style='border:1px solid #ddd;padding:8px;background:$status_bg;'>$status</td>";
            $html .= "<td style='border:1px solid #ddd;padding:8px;'>{$detail['score']}</td>";
            $html .= "<td style='border:1px solid #ddd;padding:8px;'>$value</td></tr>";
        }
        
        $html .= '</table></details>';
        $html .= '</div>';
        
        return $html;
    }
}

// ==========================================
// التكامل مع نظام النشر
// ==========================================

/**
 * اعتراض عملية النشر للتحقق
 */
function sod_intercept_news_publish(array $news_data): array {
    $validation = Sod_5W1H_Validator::validate_before_publish($news_data);
    
    if (!$validation['approved']) {
        // حفظ في مسودة للمراجعة
        $news_data['status'] = 'pending_review';
        $news_data['validation_report'] = $validation;
        
        // تسجيل الرفض
        error_log("[5W1H Validator] REJECTED: {$news_data['title']} | Score: {$validation['score']} | Missing: " . 
                 implode(',', $validation['missing_elements']));
        
        return [
            'success' => false,
            'action' => 'blocked',
            'validation' => $validation,
            'message' => $validation['message']
        ];
    }
    
    // تطبيق التصحيحات المقترحة
    if (!empty($validation['suggested_actor'])) {
        $news_data['actor'] = $validation['suggested_actor'];
    }
    
    $news_data['validation_score'] = $validation['score'];
    $news_data['status'] = 'publish';
    
    return [
        'success' => true,
        'action' => 'published',
        'validation' => $validation,
        'corrected_data' => $news_data
    ];
}

/**
 * تهيئة Hooks نظام 5W1H - تُستدعى فقط داخل ووردبريس
 */
function sod_init_5w1h_hooks() {
    // إضافة عمود في لوحة التحكم لعرض حالة التحقق
    add_filter('manage_edit-news_columns', function($columns) {
        $columns['validation_score'] = 'درجة التحقق';
        $columns['missing_elements'] = 'العناصر الناقصة';
        return $columns;
    });

    add_action('manage_news_posts_custom_column', function($column, $post_id) {
        if ($column === 'validation_score') {
            $score = get_post_meta($post_id, '_validation_score', true);
            echo $score ? "$score%" : 'غير متوفر';
        }
        if ($column === 'missing_elements') {
            $missing = get_post_meta($post_id, '_missing_elements', true);
            echo is_array($missing) ? implode(', ', $missing) : '-';
        }
    }, 10, 2);
}

// استدعاء hooks فقط إذا كان ABSPATH معرفاً (أي داخل ووردبريس)
if (defined('ABSPATH')) {
    sod_init_5w1h_hooks();
}
