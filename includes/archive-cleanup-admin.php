<?php
/**
 * Archive Cleanup Admin Interface - OSINT-LB PRO
 * 
 * واجهة إدارية لأداة تنظيف الأرشيف
 * 
 * @package OSINT_LB_PRO
 */

if (!defined('ABSPATH')) {
    exit;
}

class SO_Archive_Cleanup_Admin {

    /**
     * إضافة قائمة الأدوات الإدارية
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_so_cleanup_batch', [__CLASS__, 'ajax_cleanup_batch']);
        add_action('wp_ajax_so_get_cleanup_stats', [__CLASS__, 'ajax_get_stats']);
        add_action('wp_ajax_so_count_dirty_events', [__CLASS__, 'ajax_count_dirty']);
    }

    /**
     * إضافة صفحة القائمة
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'so-osint-events',
            __('تنظيف الأرشيف', 'beiruttime-osint-pro'),
            __('تنظيف الأرشيف', 'beiruttime-osint-pro'),
            'manage_options',
            'so-archive-cleanup',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * تحميل الأصول (CSS/JS)
     */
    public static function enqueue_assets($hook) {
        if ($hook !== 'so-osint-events_page_so-archive-cleanup') {
            return;
        }

        wp_enqueue_style(
            'so-cleanup-admin',
            plugins_url('../assets/css/cleanup-admin.css', __FILE__),
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'so-cleanup-admin',
            plugins_url('../assets/js/cleanup-admin.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('so-cleanup-admin', 'soCleanup', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('so_cleanup_nonce'),
            'strings' => [
                'confirmStart' => __('هل أنت متأكد من بدء تنظيف الأرشيف؟ هذه العملية قد تستغرق وقتاً.', 'beiruttime-osint-pro'),
                'processing' => __('جاري المعالجة...', 'beiruttime-osint-pro'),
                'completed' => __('اكتمل التنظيف!', 'beiruttime-osint-pro'),
                'error' => __('حدث خطأ', 'beiruttime-osint-pro'),
            ],
        ]);
    }

    /**
     * عرض الصفحة الإدارية
     */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $dirty_count = function_exists('so_count_dirty_events') ? so_count_dirty_events() : 0;
        $stats = function_exists('so_get_cleanup_stats') ? so_get_cleanup_stats() : [];

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('أداة تنظيف أرشيف الفاعلين', 'beiruttime-osint-pro'); ?></h1>
            
            <div class="so-cleanup-dashboard" style="margin-top: 20px;">
                <!-- بطاقة الإحصائيات -->
                <div class="cleanup-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
                    <div class="cleanup-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin: 0 0 10px 0; color: #dc3545;"><?php echo esc_html__('الأحداث الملوثة', 'beiruttime-osint-pro'); ?></h3>
                        <p style="font-size: 32px; font-weight: bold; margin: 0;" id="dirty-count"><?php echo number_format_i18n($dirty_count); ?></p>
                        <p style="color: #666; margin: 10px 0 0 0; font-size: 14px;">
                            <?php echo esc_html__('حدث يحتاج إلى تنظيف', 'beiruttime-osint-pro'); ?>
                        </p>
                    </div>

                    <div class="cleanup-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin: 0 0 10px 0; color: #28a745;"><?php echo esc_html__('تم تنظيفه', 'beiruttime-osint-pro'); ?></h3>
                        <p style="font-size: 32px; font-weight: bold; margin: 0;" id="cleaned-count">
                            <?php echo number_format_i18n($stats['total_cleaned'] ?? 0); ?>
                        </p>
                        <p style="color: #666; margin: 10px 0 0 0; font-size: 14px;">
                            <?php echo esc_html__('حدث تم إصلاحه', 'beiruttime-osint-pro'); ?>
                        </p>
                    </div>
                </div>

                <!-- لوحة التحكم -->
                <div class="cleanup-control-panel" style="background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 30px;">
                    <h2 style="margin-top: 0;"><?php echo esc_html__('لوحة التحكم', 'beiruttime-osint-pro'); ?></h2>
                    
                    <div style="margin: 20px 0;">
                        <label for="batch-size" style="display: block; margin-bottom: 10px; font-weight: bold;">
                            <?php echo esc_html__('حجم الدفعة:', 'beiruttime-osint-pro'); ?>
                        </label>
                        <select id="batch-size" name="batch-size" style="padding: 8px 12px; font-size: 14px;">
                            <option value="10">10 <?php echo esc_html__('أحداث', 'beiruttime-osint-pro'); ?></option>
                            <option value="25">25 <?php echo esc_html__('أحداث', 'beiruttime-osint-pro'); ?></option>
                            <option value="50" selected>50 <?php echo esc_html__('أحداث', 'beiruttime-osint-pro'); ?></option>
                            <option value="100">100 <?php echo esc_html__('أحداث', 'beiruttime-osint-pro'); ?></option>
                            <option value="200">200 <?php echo esc_html__('أحداث', 'beiruttime-osint-pro'); ?></option>
                        </select>
                    </div>

                    <div style="margin: 20px 0;">
                        <button type="button" id="start-cleanup-btn" class="button button-primary button-large" style="background: #007cba; border-color: #007cba;">
                            <?php echo esc_html__('بدء تنظيف الأرشيف', 'beiruttime-osint-pro'); ?>
                        </button>
                        <button type="button" id="stop-cleanup-btn" class="button button-secondary button-large" style="display: none; background: #dc3545; border-color: #dc3545; color: #fff;">
                            <?php echo esc_html__('إيقاف', 'beiruttime-osint-pro'); ?>
                        </button>
                    </div>

                    <!-- شريط التقدم -->
                    <div id="progress-container" style="display: none; margin: 20px 0;">
                        <div style="background: #f0f0f1; border-radius: 4px; height: 30px; overflow: hidden;">
                            <div id="progress-bar" style="background: #007cba; height: 100%; width: 0%; transition: width 0.3s;"></div>
                        </div>
                        <p id="progress-text" style="text-align: center; margin-top: 10px; font-weight: bold;">0%</p>
                    </div>

                    <!-- سجل العمليات -->
                    <div id="log-container" style="margin-top: 20px; max-height: 400px; overflow-y: auto; background: #f9f9f9; padding: 15px; border-radius: 4px; display: none;">
                        <h3 style="margin-top: 0;"><?php echo esc_html__('سجل العمليات', 'beiruttime-osint-pro'); ?></h3>
                        <div id="log-content"></div>
                    </div>
                </div>

                <!-- أحدث العمليات -->
                <div class="recent-cleanups" style="background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h2 style="margin-top: 0;"><?php echo esc_html__('أحدث عمليات التنظيف', 'beiruttime-osint-pro'); ?></h2>
                    <div id="recent-list">
                        <?php if (!empty($stats['recent_cleanups'])): ?>
                            <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__('الحدث', 'beiruttime-osint-pro'); ?></th>
                                        <th><?php echo esc_html__('الفاعل القديم', 'beiruttime-osint-pro'); ?></th>
                                        <th><?php echo esc_html__('الفاعل الجديد', 'beiruttime-osint-pro'); ?></th>
                                        <th><?php echo esc_html__('الثقة', 'beiruttime-osint-pro'); ?></th>
                                        <th><?php echo esc_html__('التاريخ', 'beiruttime-osint-pro'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['recent_cleanups'] as $cleanup): ?>
                                        <tr>
                                            <td>#<?php echo esc_html($cleanup['event_id']); ?></td>
                                            <td><?php echo esc_html($cleanup['old_actor']); ?></td>
                                            <td><strong><?php echo esc_html($cleanup['new_actor']); ?></strong></td>
                                            <td><?php echo esc_html($cleanup['confidence']); ?>%</td>
                                            <td><?php echo esc_html($cleanup['cleaned_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="color: #666;"><?php echo esc_html__('لا توجد عمليات تنظيف مسجلة بعد.', 'beiruttime-osint-pro'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            let isRunning = false;
            let offset = 0;
            let totalProcessed = 0;
            let totalSuccess = 0;
            let totalFailed = 0;

            $('#start-cleanup-btn').on('click', function() {
                if (isRunning) return;
                
                if (!confirm(soCleanup.strings.confirmStart)) {
                    return;
                }

                isRunning = true;
                offset = 0;
                totalProcessed = 0;
                totalSuccess = 0;
                totalFailed = 0;

                $(this).hide();
                $('#stop-cleanup-btn').show();
                $('#progress-container').show();
                $('#log-container').show();
                $('#log-content').html('');

                runBatch();
            });

            $('#stop-cleanup-btn').on('click', function() {
                isRunning = false;
                $(this).hide();
                $('#start-cleanup-btn').show();
                $('#progress-text').text('<?php echo esc_js(__('توقف عن طريق المستخدم', 'beiruttime-osint-pro')); ?>');
            });

            function runBatch() {
                if (!isRunning) return;

                const batchSize = parseInt($('#batch-size').val());

                $.post(soCleanup.ajaxUrl, {
                    action: 'so_cleanup_batch',
                    limit: batchSize,
                    offset: offset,
                    nonce: soCleanup.nonce
                }, function(response) {
                    if (response.success) {
                        const data = response.data;
                        
                        totalProcessed += data.total;
                        totalSuccess += data.success;
                        totalFailed += data.failed;

                        // تحديث السجل
                        if (data.results && data.results.length > 0) {
                            data.results.forEach(function(result) {
                                if (result.result.success && result.result.old_actor !== result.result.new_actor) {
                                    $('#log-content').prepend(
                                        '<div style="padding: 8px; border-bottom: 1px solid #eee; background: #d4edda; margin-bottom: 5px;">' +
                                        '<strong>Event #' + result.event_id + '</strong>: ' + 
                                        result.result.old_actor + ' → ' + result.result.new_actor + 
                                        ' (<span style="color: #28a745;">✓</span> ' + result.result.confidence + '%)</div>'
                                    );
                                } else if (!result.result.success) {
                                    $('#log-content').prepend(
                                        '<div style="padding: 8px; border-bottom: 1px solid #eee; background: #f8d7da; margin-bottom: 5px;">' +
                                        '<strong>Event #' + result.event_id + '</strong>: ' + 
                                        (result.result.error || 'فشل') + 
                                        ' (<span style="color: #dc3545;">✗</span>)</div>'
                                    );
                                }
                            });
                        }

                        // تحديث شريط التقدم
                        const dirtyCount = parseInt($('#dirty-count').text().replace(/,/g, ''));
                        const progress = dirtyCount > 0 ? Math.min(100, Math.round((totalProcessed / dirtyCount) * 100)) : 0;
                        $('#progress-bar').css('width', progress + '%');
                        $('#progress-text').text(progress + '% (' + totalProcessed + '/' + dirtyCount + ')');

                        // تحديث العدادات
                        $('#dirty-count').text(dirtyCount - totalSuccess);
                        $('#cleaned-count').text(parseInt($('#cleaned-count').text().replace(/,/g, '')) + totalSuccess);

                        offset += batchSize;

                        // الاستمرار إذا كانت هناك أحداث أخرى
                        if (data.total === batchSize) {
                            setTimeout(runBatch, 500); // تأخير قصير بين الدفعات
                        } else {
                            isRunning = false;
                            $('#stop-cleanup-btn').hide();
                            $('#start-cleanup-btn').show();
                            $('#progress-text').text(soCleanup.strings.completed);
                            
                            // إعادة تحميل الصفحة بعد ثانية
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        }
                    } else {
                        isRunning = false;
                        $('#stop-cleanup-btn').hide();
                        $('#start-cleanup-btn').show();
                        alert(soCleanup.strings.error + ': ' + (response.data?.message || 'Unknown error'));
                    }
                }).fail(function() {
                    isRunning = false;
                    $('#stop-cleanup-btn').hide();
                    $('#start-cleanup-btn').show();
                    alert(soCleanup.strings.error);
                });
            }

            // تحميل الإحصائيات المحدثة
            function refreshStats() {
                $.post(soCleanup.ajaxUrl, {
                    action: 'so_get_cleanup_stats',
                    nonce: soCleanup.nonce
                }, function(response) {
                    if (response.success) {
                        // تحديث قائمة أحدث العمليات
                        // يمكن إضافة كود هنا لتحديث القائمة ديناميكياً
                    }
                });
            }

            // تحديث دوري للإحصائيات
            setInterval(refreshStats, 30000); // كل 30 ثانية
        });
        </script>
        <?php
    }

    /**
     * معالجة طلب AJAX لتنظيف دفعة
     */
    public static function ajax_cleanup_batch() {
        check_ajax_referer('so_cleanup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'غير مصرح']);
        }

        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

        if (!function_exists('so_cleanup_archive_batch')) {
            wp_send_json_error(['message' => 'أداة التنظيف غير مفعلة']);
        }

        $result = so_cleanup_archive_batch($limit, $offset);

        wp_send_json_success([
            'total' => $result['total'],
            'success' => $result['success'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'],
            'results' => array_slice($result['results'], 0, 10), // أول 10 نتائج فقط لتخفيف البيانات
        ]);
    }

    /**
     * معالجة طلب AJAX للحصول على الإحصائيات
     */
    public static function ajax_get_stats() {
        check_ajax_referer('so_cleanup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'غير مصرح']);
        }

        if (!function_exists('so_get_cleanup_stats')) {
            wp_send_json_error(['message' => 'أداة التنظيف غير مفعلة']);
        }

        $stats = so_get_cleanup_stats();

        wp_send_json_success($stats);
    }

    /**
     * معالجة طلب AJAX لعد الأحداث الملوثة
     */
    public static function ajax_count_dirty() {
        check_ajax_referer('so_cleanup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'غير مصرح']);
        }

        if (!function_exists('so_count_dirty_events')) {
            wp_send_json_error(['message' => 'أداة التنظيف غير مفعلة']);
        }

        $count = so_count_dirty_events();

        wp_send_json_success(['count' => $count]);
    }
}

// تهيئة الواجهة الإدارية
SO_Archive_Cleanup_Admin::init();
