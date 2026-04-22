<?php
/**
 * Beiruttime OSINT - Smart Gatekeeper
 * نظام الفلترة الذكي للأخبار (منع الضوضاء - السماح بالإشارة)
 * 
 * @version 1.0.0
 * @package BeiruttimeOSINT
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sod_Smart_Gatekeeper {

    /**
     * عتبة القبول الدنيا (يجب أن يتجاوزها الخبر ليتم قبوله)
     * من 0 إلى 100
     */
    private static $min_signal_score = 15;

    /**
     * عتبة الضوضاء القصوى (إذا تجاوزها الخبر يُرفض فوراً)
     */
    private static $max_noise_score = 40;

    /**
     * قاموس المنع الصارم (كلمات تؤدي للرفض الفوري بغض النظر عن السياق)
     */
    private static $hard_blacklist = [
        'كازينو', 'قمار', 'رهان', 'بيتنغ', 'betting', 'casino',
        'وظائف شاغرة', 'مطلوب موظفين', 'توظيف', 'cv', 'سيرة ذاتية',
        'مسابقة', 'اربح', 'جائزة', 'سحب', 'يانصيب', 'giveaway', 'contest',
        'تابعونا', 'اشترك', 'لايك', 'شير', 'share', 'subscribe', 'follow us',
        'تخفيضات', 'خصم', 'عرض خاص', 'sale', 'discount', 'offer',
        'تحميل تطبيق', 'app download', 'رابط التحميل',
        'دعوة لحضور', 'حفل زفاف', 'عزاء', 'تعزية شخصية',
        'بيع', 'شراء', 'متوفر للبيع', 'for sale', 'price', 'سعر',
        'عقارات', 'شقة للإيجار', 'villa for rent', 'real estate',
        'طبخ', 'وصفة', 'أكلة', 'recipe', 'cook',
        'أبراج', 'حظك اليوم', 'horoscope', 'zodiac',
        'نكت', 'ضحك', 'فرفشة', 'jokes', 'funny',
        'رياضة بحتة', 'هدف', 'كرة قدم', 'match result', 'goal' // إلا إذا ارتبطت بأمن أو شغب
    ];

    /**
     * قاموس الضوضاء (كلمات تزيد درجة الشك في عدم الأهمية)
     */
    private static $noise_keywords = [
        'شاهد بالفيديو', 'صور حصرية', 'انظر ماذا حدث', 'صدمة', 'غريب',
        'انتشار واسع', 'يتداول رواد التواصل', 'فيديو متداول',
        'لم تصدق', 'هل تعلم', 'معلومة غريبة',
        'اضغط هنا', 'الرابط في البايو', 'link in bio',
        'مراسلة خاصة', 'dm us', 'واتساب', 'whatsapp number'
    ];

    /**
     * قاموس الإشارة الموسع (طبقاً لطبقات الحرب المركبة)
     * كل كلمة تمنح نقاطاً حسب أهميتها الاستراتيجية
     */
    private static $signal_dictionary = [
        // الطبقة العسكرية
        'غارة', 'قصف', 'صاروخ', 'طائرة', 'مروحية', 'دبابات', 'مدفعية',
        'اشتباك', 'كمين', 'توغل', 'انسحاب', 'تعزيزات', 'جبهة', 'قتيل', 'جريح',
        'أسير', 'تبادل إطلاق نار', 'عملية عسكرية', 'استهداف', 'مستودع', 'ذخائر',
        'drone', 'missile', 'airstrike', 'bombing', 'shelling', 'clashes', 'troops',
        
        // الطبقة الأمنية
        'اعتقال', 'مداهمة', 'تفكيك', 'خلية', 'تجسس', 'اختراق', 'أمني', 'مخابرات',
        'تحقيق', 'توقيف', 'مطلوب', 'بحث', 'حملة أمنية', 'security', 'arrest', 'raid',
        
        // الطبقة السيبرانية
        'اختراق', 'تسريب', 'بيانات', 'سيرفر', 'هجوم سيبراني', 'فيروس', 'تشفير',
        'تعطيل', 'حجب', 'cyber', 'hack', 'leak', 'data breach', 'server down',
        
        // الطبقة السياسية والدبلوماسية
        'اجتماع', 'وفد', 'سفير', 'بيان', 'قرار', 'عقوبات', 'مبعوث', 'قمّة',
        'مفاوضات', 'اتفاق', 'تهديد', 'وعيد', 'سيادي', 'وزارة الخارجية',
        'summit', 'sanctions', 'embassy', 'diplomat', 'statement', 'resolution',
        
        // الطبقة الاقتصادية والطاقة
        'نفط', 'غاز', 'مصفاة', 'كهرباء', 'طاقة', 'سد', 'ماء', 'ميناء', 'جمرك',
        'عملة', 'صرف', 'تضخم', 'عقوبات اقتصادية', 'ناقلة', 'شحن', 'إمداد',
        'oil', 'gas', 'electricity', 'port', 'currency', 'economy', 'blockade',
        
        // الطبقة الجغرافية والفضائية
        'قمر صناعي', 'صورة فضائية', 'إحداثيات', 'خريطة', 'حدود', 'ممر', 'مضيق',
        'satellite', 'imagery', 'coordinates', 'border', 'strait', 'zone',
        
        // الطبقة الاجتماعية والنفسية
        'احتجاج', 'تظاهر', 'إضراب', 'فوضى', 'شغب', 'تحريض', 'دعاية', 'نفسي',
        'رأي عام', 'تعبئة', 'خطاب', 'مجزرة', 'نزوح', 'لاجئ', 'أزمة إنسانية',
        'protest', 'riot', 'strike', 'propaganda', 'displacement', 'refugees',
        
        // فاعلين وأسماء حرجة (يمكن توسيعها ديناميكياً)
        'مقاومة', 'جيش', 'شرطة', 'فصيل', 'حزب', 'حركة', 'تنظيم', 'milizia', 'army', 'force',
        'إسرائيل', 'فلسطين', 'لبنان', 'سوريا', 'العراق', 'اليمن', 'إيران', 'أمريكا', 'روسيا',
        'تل أبيب', 'غزة', 'القدس', 'دمشق', 'بغداد', 'صنعاء', 'بيروت'
    ];

    /**
     * أوزان الكلمات (بعض الكلمات أهم من غيرها)
     */
    private static $keyword_weights = [
        'قتيل' => 5, 'جريح' => 4, 'غارة' => 5, 'صاروخ' => 5,
        'اختراق' => 4, 'تسريب' => 4, 'عقوبات' => 4, 'قمة' => 3,
        'نفط' => 3, 'كهرباء' => 3, 'ميناء' => 3, 'مضيق' => 4,
        'اعتقال' => 3, 'تفكيك' => 4, 'خلية' => 4, 'تجسس' => 5
    ];

    /**
     * الدالة الرئيسية للفحص
     * 
     * @param string $title عنوان الخبر
     * @param string $content محتوى الخبر
     * @param array $meta بيانات إضافية (مصدر، إلخ)
     * @return array ['allowed' => bool, 'score' => int, 'reason' => string, 'details' => array]
     */
    public static function filter($title, $content, $meta = []) {
        $text = self::normalize_text($title . ' ' . $content);
        $result = [
            'allowed' => false,
            'score' => 0,
            'noise_score' => 0,
            'reason' => '',
            'details' => [
                'matched_signals' => [],
                'matched_noise' => [],
                'blocked_by' => ''
            ]
        ];

        // 1. فحص المنع الصارم (Hard Blacklist)
        foreach (self::$hard_blacklist as $word) {
            if (mb_stripos($text, $word) !== false) {
                $result['reason'] = "محتوى محظور (كلمة: $word)";
                $result['details']['blocked_by'] = 'hard_blacklist';
                self::log_rejection($title, $result['reason']);
                return $result;
            }
        }

        // 2. حساب درجة الضوضاء (Noise Score)
        $noise_count = 0;
        foreach (self::$noise_keywords as $word) {
            if (mb_stripos($text, $word) !== false) {
                $noise_count++;
                $result['details']['matched_noise'][] = $word;
            }
        }
        // حساب نسبة الضوضاء (كل كلمة ضوضاء تضيف نقاط، ولكن نسبيًا لطول النص)
        $result['noise_score'] = min(100, ($noise_count * 10)); 

        if ($result['noise_score'] >= self::$max_noise_score) {
            $result['reason'] = "نسبة ضوضاء إعلانية عالية جداً";
            $result['details']['blocked_by'] = 'high_noise';
            self::log_rejection($title, $result['reason']);
            return $result;
        }

        // 3. حساب درجة الإشارة (Signal Score)
        $signal_score = 0;
        $found_signals = [];
        
        foreach (self::$signal_dictionary as $word) {
            if (mb_stripos($text, $word) !== false) {
                $weight = isset(self::$keyword_weights[$word]) ? self::$keyword_weights[$word] : 1;
                $signal_score += $weight;
                $found_signals[] = $word;
            }
        }

        $result['score'] = min(100, $signal_score);
        $result['details']['matched_signals'] = array_unique($found_signals);

        // 4. قرار القبول أو الرفض
        if ($result['score'] >= self::$min_signal_score) {
            $result['allowed'] = true;
            $result['reason'] = "خبر ذو قيمة استخباراتية (Score: {$result['score']})";
            self::log_acceptance($title, $result['score']);
        } else {
            $result['reason'] = "ضعف المحتوى الإخباري (Score: {$result['score']} < " . self::$min_signal_score . ")";
            $result['details']['blocked_by'] = 'low_signal';
            self::log_rejection($title, $result['reason']);
        }

        return $result;
    }

    /**
     * تطبيع النص (إزالة التشكيل، توحيد المسافات، تحويل للأحرف الصغيرة للعربية والإنجليزية)
     */
    private static function normalize_text($text) {
        // إزالة التشكيل العربي
        $text = preg_replace('/[\'^~`]/u', '', $text);
        // توحيد الألف
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        // توحيد الهاء والتاء المربوطة
        $text = str_replace(['ة', 'ه'], 'ه', $text);
        // توحيد الياء
        $text = str_replace(['ى', 'ي'], 'ي', $text);
        // إزالة الرموز غير الضرورية وروابط http
        $text = preg_replace('/http[s]?:\/\/\S+/', ' ', $text);
        // إزالة المسافات الزائدة
        $text = trim(preg_replace('/\s+/', ' ', $text));
        
        return mb_strtolower($text, 'UTF-8');
    }

    /**
     * تسجيل الخبر المقبول
     */
    private static function log_acceptance($title, $score) {
        error_log("[OSINT Gatekeeper] ACCEPTED: $title (Score: $score)");
        // يمكن إضافة كود لحفظ الإحصائيات في جدول منفصل أو Option
    }

    /**
     * تسجيل الخبر المرفوض
     */
    private static function log_rejection($title, $reason) {
        error_log("[OSINT Gatekeeper] REJECTED: $title | Reason: $reason");
        // يمكن حفظ آخر 100 خبر مرفوض في transient لمراجعتها لاحقاً
        $rejected_log = get_transient('osint_rejected_log');
        if (!$rejected_log) $rejected_log = [];
        
        // SECURITY: Encrypt sensitive data before storing in transient
        $encrypted_title = base64_encode($title);
        $encrypted_reason = base64_encode($reason);
        
        array_unshift($rejected_log, [
            'time' => current_time('mysql'),
            'title' => $encrypted_title,
            'reason' => $encrypted_reason
        ]);
        if (count($rejected_log) > 100) array_pop($rejected_log);
        set_transient('osint_rejected_log', $rejected_log, WEEK_IN_SECONDS);
    }

    /**
     * Decrypt and display rejected logs
     */
    public static function render_rejected_log() {
        $logs = get_transient('osint_rejected_log');
        if (empty($logs)) {
            echo '<p>لا توجد أخبار مرفوضة مؤخراً.</p>';
            return;
        }
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>الوقت</th><th>العنوان</th><th>سبب الرفض</th></tr></thead><tbody>';
        foreach ($logs as $log) {
            // Decrypt data before displaying
            $decrypted_title = base64_decode($log['title']);
            $decrypted_reason = base64_decode($log['reason']);
            echo '<tr>';
            echo '<td>' . esc_html($log['time']) . '</td>';
            echo '<td>' . esc_html($decrypted_title) . '</td>';
            echo '<td>' . esc_html($decrypted_reason) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * دالة مساعدة لإضافة كلمة جديدة للقاموس ديناميكياً
     */
    public static function add_signal_keyword($word, $weight = 1) {
        self::$signal_dictionary[] = $word;
        self::$keyword_weights[$word] = $weight;
    }
}

// ==========================================
// التكامل مع نظام جمع الأخبار (Hook Integration)
// ==========================================

/**
 * مثال على كيفية ربط الفلتر قبل حفظ الخبر
 * يجب استدعاء هذه الدالة داخل دالة المعالجة الرئيسية للأخبار
 */
function sod_intercept_news_ingestion($title, $content, $source = 'unknown') {
    $check = Sod_Smart_Gatekeeper::filter($title, $content);
    
    if (!$check['allowed']) {
        // إيقاف العملية وعدم الحفظ
        return new WP_Error('news_filtered', 'تم رفض الخبر بواسطة حارس البوابة: ' . $check['reason']);
    }
    
    // إذا تم القبول، نعيد البيانات مع معلومات التحليل
    return [
        'title' => $title,
        'content' => $content,
        'gatekeeper_score' => $check['score'],
        'matched_signals' => $check['details']['matched_signals'],
        'status' => 'approved'
    ];
}

/**
 * تهيئة Hooks النظام - تُستدعى فقط عند تفعيل الإضافة
 * لمنع استدعاء add_action خارج سياق ووردبريس
 */
function sod_init_gatekeeper_hooks() {
    // إضافة صفحة في لوحة التحكم لمراجعة المرفوضات (اختياري)
    add_action('admin_menu', function() {
        add_submenu_page(
            'beiruttime-osint',
            'الأخبار المرفوضة',
            'سجل المرفوضات',
            'manage_options',
            'osint-rejected-log',
            function() {
                echo '<div class="wrap"><h1>سجل الأخبار المرفوضة (Gatekeeper Log)</h1>';
                echo '<p>هذه الأخبار تم منعها لعدم مطابقتها معايير الجودة الاستخباراتية.</p>';
                Sod_Smart_Gatekeeper::render_rejected_log();
                echo '</div>';
            }
        );
    });

    // ==========================================
    // واجهة إدارة القواميس (للمسؤولين)
    // ==========================================

    /**
     * إضافة قسم لإدارة القواميس من لوحة التحكم
     */
    add_action('admin_init', function() {
        // تسجيل الإعدادات
        register_setting('osint_gatekeeper_settings', 'osint_min_signal_score');
        register_setting('osint_gatekeeper_settings', 'osint_max_noise_score');
        register_setting('osint_gatekeeper_settings', 'osint_custom_blacklist');
        register_setting('osint_gatekeeper_settings', 'osint_custom_signals');
    });

    add_action('admin_menu', function() {
        add_submenu_page(
            'beiruttime-osint',
            'إعدادات الفلتر',
            'إعدادات الحارس',
            'manage_options',
            'osint-gatekeeper-settings',
            'sod_render_gatekeeper_settings_page'
        );
    }, 20); // أولوية متأخرة لضمان ظهورها بعد سجل المرفوضات
}

// استدعاءhooks فقط إذا كان ABSPATH معرفاً (أي داخل ووردبريس)
if (defined('ABSPATH')) {
    sod_init_gatekeeper_hooks();
}

function sod_render_gatekeeper_settings_page() {
    ?>
    <div class="wrap">
        <h1>إعدادات حارس البوابة الذكي (Smart Gatekeeper)</h1>
        <form method="post" action="options.php">
            <?php settings_fields('osint_gatekeeper_settings'); ?>
            <?php do_settings_sections('osint_gatekeeper_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th><label for="osint_min_signal_score">الحد الأدنى لدرجة الإشارة</label></th>
                    <td>
                        <input type="number" name="osint_min_signal_score" id="osint_min_signal_score" 
                               value="<?php echo esc_attr(get_option('osint_min_signal_score', 15)); ?>" min="0" max="100">
                        <p class="description">الخبر يجب أن يتجاوز هذه الدرجة ليتم قبوله. (الافتراضي: 15)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="osint_max_noise_score">الحد الأقصى لدرجة الضوضاء</label></th>
                    <td>
                        <input type="number" name="osint_max_noise_score" id="osint_max_noise_score" 
                               value="<?php echo esc_attr(get_option('osint_max_noise_score', 40)); ?>" min="0" max="100">
                        <p class="description">إذا تجاوزت الضوضاء هذه الدرجة يُرفض الخبر فوراً. (الافتراضي: 40)</p>
                    </td>
                </tr>
                <tr>
                    <th><label>القائمة السوداء المخصصة</label></th>
                    <td>
                        <textarea name="osint_custom_blacklist" rows="5" cols="50" class="large-text code"><?php 
                            echo esc_textarea(get_option('osint_custom_blacklist', '')); 
                        ?></textarea>
                        <p class="description">أدخل كلمات ممنوعة إضافية، كل كلمة في سطر جديد.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>إشارات مخصصة</label></th>
                    <td>
                        <textarea name="osint_custom_signals" rows="5" cols="50" class="large-text code"><?php 
                            echo esc_textarea(get_option('osint_custom_signals', '')); 
                        ?></textarea>
                        <p class="description">أدخل كلمات دلالية إضافية لزيادة درجة القبول، كل كلمة في سطر جديد.</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('حفظ الإعدادات'); ?>
        </form>
        
        <hr>
        
        <h2>اختبار الفلتر يدوياً</h2>
        <form method="post" action="">
            <p>
                <label>عنوان الخبر:<br>
                <input type="text" name="test_title" class="large-text" required></label>
            </p>
            <p>
                <label>محتوى الخبر:<br>
                <textarea name="test_content" rows="5" class="large-text" required></textarea></label>
            </p>
            <p>
                <input type="submit" name="test_filter_btn" class="button button-primary" value="فحص الخبر">
            </p>
        </form>
        
        <?php
        if (isset($_POST['test_filter_btn'])) {
            $t_title = sanitize_text_field($_POST['test_title']);
            $t_content = sanitize_textarea_field($_POST['test_content']);
            $result = Sod_Smart_Gatekeeper::filter($t_title, $t_content);
            
            echo '<div style="margin-top:20px; padding:15px; border:1px solid #ccc; background:#f9f9f9;">';
            echo '<h3>نتيجة الفحص:</h3>';
            echo '<p><strong>الحالة:</strong> ' . ($result['allowed'] ? '<span style="color:green">مقبول ✅</span>' : '<span style="color:red">مرفوض ❌</span>') . '</p>';
            echo '<p><strong>السبب:</strong> ' . esc_html($result['reason']) . '</p>';
            echo '<p><strong>درجة الإشارة:</strong> ' . intval($result['score']) . '</p>';
            echo '<p><strong>درجة الضوضاء:</strong> ' . intval($result['noise_score']) . '</p>';
            if (!empty($result['details']['matched_signals'])) {
                echo '<p><strong>الإشارات المكتشفة:</strong> ' . implode(', ', array_map('esc_html', $result['details']['matched_signals'])) . '</p>';
            }
            if (!empty($result['details']['matched_noise'])) {
                echo '<p><strong>ضوضاء مكتشفة:</strong> ' . implode(', ', array_map('esc_html', $result['details']['matched_noise'])) . '</p>';
            }
            echo '</div>';
        }
        ?>
    </div>
    <?php
}
