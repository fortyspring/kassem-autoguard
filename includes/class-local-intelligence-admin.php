<?php
/**
 * Local Intelligence Admin Interface - واجهة المحرك الذكي المحلي
 * 
 * توفر واجهة إدارة لفحص وتصنيف الأحداث الملوثة على دفعات
 * 
 * @version 1.0.0
 * @package OSINT-LB-PRO
 */

if (!defined('ABSPATH')) exit;

class SO_Local_Intelligence_Admin {
    private const MIN_SCAN_LIMIT = 10;
    private const MAX_SCAN_LIMIT = 2000;
    private const MIN_BATCH_SIZE = 10;
    private const MAX_BATCH_SIZE = 200;
    private const MIN_STATS_DAYS = 1;
    private const MAX_STATS_DAYS = 365;
    private const ALLOWED_DIRTY_TYPES = ['all', 'media', 'person', 'country_only', 'vague', 'empty', 'regex'];
    
    /**
     * تهيئة الواجهة
     */
    public static function init() {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_so_intel_scan_dirty', [__CLASS__, 'ajax_scan_dirty']);
        add_action('wp_ajax_so_intel_clean_batch', [__CLASS__, 'ajax_clean_batch']);
        add_action('wp_ajax_so_intel_get_stats', [__CLASS__, 'ajax_get_stats']);
    }
    
    /**
     * تحميل الأصول (CSS/JS)
     */
    public static function enqueue_assets($hook) {
        if ($hook !== 'beiruttime-osint-pro_page_strategic-osint-reindex') {
            return;
        }
        
        // تحميل CSS فقط؛ JavaScript الخاص بهذه الصفحة مضمّن أدناه لتفادي تعارضات الملف القديم cleanup-admin.js
        wp_enqueue_style(
            'so-intel-admin-css',
            plugins_url('../assets/css/cleanup-admin.css', __FILE__),
            [],
            '1.0.1'
        );

        wp_register_script('so-intel-admin-js', '', ['jquery'], '1.0.1', true);
        wp_enqueue_script('so-intel-admin-js');

        wp_localize_script('so-intel-admin-js', 'SOIntelAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('so_intel_admin_nonce'),
            'strings' => [
                'scanning' => 'جاري الفحص...',
                'cleaning' => 'جاري التنظيف...',
                'completed' => 'اكتمل',
                'error' => 'خطأ',
                'dirtyFound' => 'تم العثور على أحداث ملوثة',
                'cleanEvents' => 'الأحداث نظيفة',
                'confirmClean' => 'هل أنت متأكد من بدء عملية تنظيف؟',
            ]
        ]);
    }
    
    /**
     * مسح الأحداث الملوثة
     */
    public static function ajax_scan_dirty() {
        check_ajax_referer('so_intel_admin_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'غير مصرح لك'], 403);
        }
        
        $limit = self::clamp_int($_POST['limit'] ?? 100, self::MIN_SCAN_LIMIT, self::MAX_SCAN_LIMIT, 100);
        
        if (!class_exists('SO_Local_Intelligence_Engine')) {
            wp_send_json_error(['message' => 'المحرك غير متوفر']);
        }

        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }
        
        try {
            $engine = SO_Local_Intelligence_Engine::get_instance();
            $results = $engine->count_dirty_events($limit);
            wp_send_json_success($results);
        } catch (\Throwable $e) {
            error_log('SO_Local_Intelligence_Admin::ajax_scan_dirty fatal: ' . $e->getMessage());
            wp_send_json_error([
                'message' => 'فشل فحص التلوث: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * تنظيف دفعة من الأحداث
     */
    public static function ajax_clean_batch() {
        check_ajax_referer('so_intel_admin_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'غير مصرح لك'], 403);
        }
        
        $batch_size = self::clamp_int($_POST['batch_size'] ?? 50, self::MIN_BATCH_SIZE, self::MAX_BATCH_SIZE, 50);
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $cursor = isset($_POST['cursor']) ? intval($_POST['cursor']) : $offset;
        $dirty_type = self::normalize_dirty_type($_POST['dirty_type'] ?? '');
        
        if (!class_exists('SO_Local_Intelligence_Engine')) {
            wp_send_json_error(['message' => 'المحرك غير متوفر']);
        }
        
        $filters = [];
        if (!empty($dirty_type) && $dirty_type !== 'all') {
            $filters['dirty_type'] = $dirty_type;
        }
        
        $filters['cursor'] = max(0, (int) $cursor);

        $engine = SO_Local_Intelligence_Engine::get_instance();
        $results = $engine->process_batch($batch_size, $offset, $filters);
        
        wp_send_json_success($results);
    }
    
    /**
     * الحصول على الإحصائيات
     */
    public static function ajax_get_stats() {
        check_ajax_referer('so_intel_admin_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'غير مصرح لك'], 403);
        }
        
        $days = self::clamp_int($_POST['days'] ?? 30, self::MIN_STATS_DAYS, self::MAX_STATS_DAYS, 30);
        
        if (!class_exists('SO_Local_Intelligence_Engine')) {
            wp_send_json_error(['message' => 'المحرك غير متوفر']);
        }
        
        $engine = SO_Local_Intelligence_Engine::get_instance();
        $stats = $engine->get_cleanup_stats($days);
        
        wp_send_json_success($stats);
    }
    
    /**
     * عرض قسم المحرك الذكي في صفحة إعادة الأرشفة
     */
    public static function render_section() {
        if (!class_exists('SO_Local_Intelligence_Engine')) {
            echo '<div class="notice notice-error"><p>⚠️ محرك الذكاء المحلي غير متوفر</p></div>';
            return;
        }
        
        $engine = SO_Local_Intelligence_Engine::get_instance();
        ?>
        <div class="so-intel-section" style="margin-top: 30px;">
            <h2 style="border-bottom: 2px solid #2271b1; padding-bottom: 10px;">
                🧠 المحرك الذكي المحلي - فحص وتنظيف الأحداث الملوثة
            </h2>
            
            <div class="so-intel-dashboard" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
                <!-- بطاقة المسح -->
                <div class="so-intel-card" style="background: #f0f6fc; border: 1px solid #2271b1; border-radius: 8px; padding: 20px;">
                    <h3 style="margin-top: 0; color: #2271b1;">🔍 فحص التلوث</h3>
                    <div id="so-scan-results" style="margin: 15px 0;">
                        <p class="description">اضغط على زر الفحص لتحليل الأحداث الملوثة</p>
                    </div>
                    <button type="button" id="so-scan-btn" class="button button-primary" style="width: 100%;">
                        🔍 بدء الفحص
                    </button>
                </div>
                
                <!-- بطاقة الإحصائيات -->
                <div class="so-intel-card" style="background: #f6f0fc; border: 1px solid #7e44a3; border-radius: 8px; padding: 20px;">
                    <h3 style="margin-top: 0; color: #7e44a3;">📊 إحصائيات التنظيف</h3>
                    <div id="so-stats-results" style="margin: 15px 0;">
                        <p class="description">آخر 30 يوم</p>
                        <div style="margin: 10px 0;">
                            <label for="so-stats-days"><strong>الفترة (يوم):</strong></label>
                            <input type="number" id="so-stats-days" value="30" min="1" max="365" style="width: 100%; margin-top: 5px; padding: 8px;">
                        </div>
                        <div id="so-stats-content"></div>
                    </div>
                    <button type="button" id="so-stats-btn" class="button" style="width: 100%;">
                        🔄 تحديث الإحصائيات
                    </button>
                </div>
            </div>
            
            <!-- لوحة التحكم بالتنظيف -->
            <div class="so-intel-clean-panel" style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px; margin-top: 20px;">
                <h3 style="margin-top: 0;">🧹 تنظيف الدفعات</h3>
                
                <form id="so-clean-form" style="display: grid; gap: 15px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <div>
                            <label for="so-batch-size">
                                <strong>حجم الدفعة:</strong>
                            </label>
                            <input type="number" id="so-batch-size" value="50" min="10" max="200" style="width: 100%; margin-top: 5px; padding: 8px;">
                            <p class="description">عدد الأحداث في كل دفعة (10-200)</p>
                        </div>
                        
                        <div>
                            <label for="so-dirty-type">
                                <strong>نوع التلوث:</strong>
                            </label>
                            <select id="so-dirty-type" style="width: 100%; margin-top: 5px; padding: 8px;">
                                <option value="all">جميع الأنواع</option>
                                <option value="media">مصادر إعلامية</option>
                                <option value="person">أشخاص/مسؤولون</option>
                                <option value="country_only">دول فقط</option>
                                <option value="vague">غامض/غير محدد</option>
                                <option value="empty">فارغ</option>
                                <option value="regex">أنماط Regex</option>
                            </select>
                            <p class="description">فلتر نوع التلوث المستهدف</p>
                        </div>
                        
                        <div>
                            <label for="so-max-batches">
                                <strong>عدد الدفعات:</strong>
                            </label>
                            <input type="number" id="so-max-batches" value="10" min="1" max="100" style="width: 100%; margin-top: 5px; padding: 8px;">
                            <p class="description">عدد الدفعات المتتالية (1-100)</p>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button type="button" id="so-start-clean-btn" class="button button-primary button-large">
                            🚀 بدء التنظيف
                        </button>
                        <button type="button" id="so-stop-clean-btn" class="button button-large" style="display: none;">
                            ⏹ إيقاف
                        </button>
                        <span id="so-progress-info" style="color: #50575e; font-size: 14px;"></span>
                    </div>
                </form>
                
                <!-- شريط التقدم -->
                <div id="so-progress-container" style="display: none; margin-top: 20px;">
                    <div style="width: 100%; background: #e5e7eb; border-radius: 999px; overflow: hidden; height: 20px; margin: 10px 0;">
                        <div id="so-progress-bar" style="width: 0%; height: 20px; background: linear-gradient(90deg, #2271b1, #3582c4); transition: width 0.3s ease;"></div>
                    </div>
                    <p id="so-progress-text" style="text-align: center; margin: 8px 0; font-size: 14px;"></p>
                </div>
                
                <!-- سجل العمليات -->
                <div id="so-log-container" style="margin-top: 20px;">
                    <h4 style="margin-bottom: 10px;">📝 سجل العمليات</h4>
                    <div id="so-log" style="max-height: 400px; overflow-y: auto; background: #f8fafc; border: 1px solid #dcdcde; border-radius: 6px; padding: 10px; font-family: monospace; font-size: 12px;">
                        <p class="description">سيظهر سجل العمليات هنا...</p>
                    </div>
                </div>
            </div>
            
            <!-- عينات من الأحداث الملوثة -->
            <div id="so-samples-section" style="display: none; margin-top: 20px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px;">
                <h3 style="margin-top: 0;">📋 عينات من الأحداث الملوثة</h3>
                <div id="so-samples-list" style="display: grid; gap: 10px;"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            let isScanning = false;
            let isCleaning = false;
            let currentOffset = 0;
            let currentCursor = 0;
            let totalProcessed = 0;
            let totalUpdated = 0;
            let totalSkipped = 0;
            let totalErrors = 0;
            let maxBatches = 10;
            let batchCount = 0;
            let shouldStop = false;
            let retryCount = 0;
            
            // فحص التلوث
            $('#so-scan-btn').on('click', function() {
                if (isScanning) return;
                
                isScanning = true;
                $(this).prop('disabled', true).text('جاري الفحص...');
                $('#so-scan-results').html('<p class="description">جاري تحليل الأرشيف...</p>');
                
                $.ajax({
                    url: SOIntelAdmin.ajaxUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'so_intel_scan_dirty',
                        _ajax_nonce: SOIntelAdmin.nonce,
                        limit: 1000
                    }
                }).done(function(response) {
                    isScanning = false;
                    $('#so-scan-btn').prop('disabled', false).text('🔍 بدء الفحص');
                    
                    if (response.success) {
                        const data = response.data;
                        const total = parseInt(data.total || 0, 10);
                        const scanned = parseInt(data.scanned || 0, 10);
                        const ratio = scanned > 0 ? ((total / scanned) * 100).toFixed(1) : '0.0';
                        const byType = data.by_type || {};
                        const dominantType = getDominantDirtyType(byType);

                        let html = '<div style="font-size: 18px; margin: 10px 0;">';
                        html += '<strong>إجمالي الأحداث الملوثة: </strong><span style="color: #b32d2e;">' + formatNumber(total) + '</span><br>';
                        html += '<small>تم فحص: ' + formatNumber(scanned) + ' سجل</small><br>';
                        html += '<small>نسبة التلوث: <strong>' + ratio + '%</strong></small><br>';
                        html += '<small>النوع الأكثر شيوعًا: <strong>' + escapeHtml(dominantType.label) + '</strong> (' + formatNumber(dominantType.count) + ')</small><br>';
                        html += '<small>فارغ: ' + formatNumber(byType.empty || 0) + ' | ';
                        html += 'غامض: ' + formatNumber(byType.vague || 0) + ' | ';
                        html += 'إعلامي: ' + formatNumber(byType.media || 0) + ' | ';
                        html += 'أشخاص: ' + formatNumber(byType.person || 0) + ' | ';
                        html += 'دول: ' + formatNumber(byType.country_only || 0) + ' | ';
                        html += 'Regex: ' + formatNumber(byType.regex || 0) + '</small>';
                        html += '</div>';
                        
                        if (data.samples && data.samples.length > 0) {
                            html += '<details style="margin-top: 15px;"><summary style="cursor: pointer; color: #2271b1;">عرض العينات (' + data.samples.length + ')</summary>';
                            html += '<div style="margin-top: 10px;">';
                            data.samples.forEach(function(sample) {
                                html += '<div style="background: #f0f6fc; border-left: 3px solid #2271b1; padding: 10px; margin: 5px 0; font-size: 13px;">';
                                html += '<strong>ID:</strong> ' + formatNumber(sample.id) + ' | ';
                                html += '<strong>النوع:</strong> ' + escapeHtml(sample.type || '') + ' | ';
                                html += '<strong>الفاعل:</strong> <span style="color: #b32d2e;">' + escapeHtml(sample.actor || '') + '</span><br>';
                                html += '<small>' + escapeHtml(sample.title || '') + '</small>';
                                html += '</div>';
                            });
                            html += '</div></details>';
                            
                            // إظهار قسم العينات
                            $('#so-samples-section').show();
                            $('#so-samples-list').html(renderSamples(data.samples));
                        }
                        
                        $('#so-scan-results').html(html);
                    } else {
                        $('#so-scan-results').html('<p style="color: #b32d2e;">❌ ' + response.data.message + '</p>');
                    }
                }).fail(function(xhr) {
                    isScanning = false;
                    $('#so-scan-btn').prop('disabled', false).text('🔍 بدء الفحص');
                    $('#so-scan-results').html('<p style="color: #b32d2e;">❌ خطأ في الاتصال أو استجابة غير صالحة</p>');
                    addLog('فشل الفحص: ' + (xhr && xhr.responseText ? xhr.responseText.substring(0, 220) : 'unknown'), true);
                });
            });
            
            // تحديث الإحصائيات
            $('#so-stats-btn').on('click', function() {
                $(this).prop('disabled', true).text('جاري التحديث...');
                const selectedDays = parseInt($('#so-stats-days').val(), 10);
                const days = Number.isFinite(selectedDays) ? Math.min(365, Math.max(1, selectedDays)) : 30;
                
                $.ajax({
                    url: SOIntelAdmin.ajaxUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'so_intel_get_stats',
                        _ajax_nonce: SOIntelAdmin.nonce,
                        days
                    }
                }).done(function(response) {
                    $('#so-stats-btn').prop('disabled', false).text('🔄 تحديث الإحصائيات');
                    
                    if (response.success) {
                        const data = response.data;
                        let html = '<div style="font-size: 14px;">';
                        if (data.summary) {
                            html += '<div>إجمالي عمليات التنظيف: <strong>' + (data.summary.total_cleanups || 0) + '</strong></div>';
                            html += '<div>أحداث فريدة: <strong>' + (data.summary.unique_events || 0) + '</strong></div>';
                            html += '<div>متوسط الثقة: <strong>' + (parseFloat(data.summary.avg_confidence) || 0).toFixed(1) + '%</strong></div>';
                            html += '<div style="margin-top: 6px; color: #50575e;">آخر ' + days + ' يوم</div>';
                        }
                        html += '</div>';
                        $('#so-stats-content').html(html);
                    }
                }).fail(function(xhr) {
                    $('#so-stats-btn').prop('disabled', false).text('🔄 تحديث الإحصائيات');
                    $('#so-stats-content').html('<div style="color:#b32d2e;">تعذر جلب الإحصائيات</div>');
                    addLog('فشل جلب الإحصائيات: ' + (xhr && xhr.responseText ? xhr.responseText.substring(0, 220) : 'unknown'), true);
                });
            });
            
            // بدء التنظيف
            $('#so-start-clean-btn').on('click', function() {
                if (isCleaning) return;
                
                const batchSize = parseInt($('#so-batch-size').val()) || 50;
                const dirtyType = $('#so-dirty-type').val();
                maxBatches = parseInt($('#so-max-batches').val()) || 10;
                
                if (!confirm(SOIntelAdmin.strings.confirmClean)) return;
                
                isCleaning = true;
                shouldStop = false;
                currentOffset = 0;
                currentCursor = 0;
                totalProcessed = 0;
                totalUpdated = 0;
                totalSkipped = 0;
                totalErrors = 0;
                batchCount = 0;
                
                $('#so-start-clean-btn').hide();
                $('#so-stop-clean-btn').show();
                $('#so-progress-container').show();
                $('#so-log').html('');
                
                processNextBatch(batchSize, dirtyType);
            });
            
            // إيقاف التنظيف
            $('#so-stop-clean-btn').on('click', function() {
                shouldStop = true;
                isCleaning = false;
                $('#so-stop-clean-btn').hide();
                $('#so-start-clean-btn').show().text('▶️ استئناف');
                addLog('⏹ تم الإيقاف بواسطة المستخدم');
            });
            
            function processNextBatch(batchSize, dirtyType) {
                if (shouldStop || batchCount >= maxBatches) {
                    finishCleaning();
                    return;
                }
                
                batchCount++;
                const progress = ((batchCount - 1) / maxBatches) * 100;
                $('#so-progress-bar').css('width', progress + '%');
                $('#so-progress-text').text('دفعة ' + batchCount + ' من ' + maxBatches + ' (' + progress.toFixed(1) + '%) | Cursor: ' + currentCursor);
                
                $.post(SOIntelAdmin.ajaxUrl, {
                    action: 'so_intel_clean_batch',
                    _ajax_nonce: SOIntelAdmin.nonce,
                    batch_size: batchSize,
                    offset: currentOffset,
                    cursor: currentCursor,
                    dirty_type: dirtyType
                }, function(response) {
                    if (response.success) {
                        retryCount = 0;
                        const data = response.data;
                        totalProcessed += data.processed || 0;
                        totalUpdated += data.updated || 0;
                        totalSkipped += data.skipped || 0;
                        totalErrors += data.errors || 0;
                        
                        addLog('✅ دفعة #' + batchCount + ': عولجت ' + data.processed + ', حدثت ' + data.updated + ', تخطت ' + data.skipped);
                        
                        if (data.details && data.details.length > 0) {
                            data.details.forEach(function(detail) {
                                if (detail.status === 'updated') {
                                    addLog('   ↳ ID ' + detail.id + ': ' + detail.old_actor + ' → ' + detail.new_actor, false, true);
                                }
                            });
                        }
                        
                        currentOffset += batchSize;
                        currentCursor = parseInt(data.next_cursor || currentCursor || 0);
                        if (parseInt(data.done || 0) === 1 || parseInt(data.processed || 0) === 0) {
                            addLog('✅ لا توجد دفعات إضافية مطلوبة، تم إنهاء العملية.');
                            finishCleaning();
                            return;
                        }
                        setTimeout(() => processNextBatch(batchSize, dirtyType), 300);
                    } else {
                        handleBatchFailure(response?.data?.message || 'استجابة غير صالحة');
                    }
                }).fail(function() {
                    handleBatchFailure('خطأ في الاتصال');
                });
            }
            
            function handleBatchFailure(message) {
                if (retryCount < 2 && !shouldStop) {
                    retryCount++;
                    addLog('⚠️ ' + message + ' - إعادة المحاولة (' + retryCount + '/2)', true);
                    setTimeout(() => {
                        const batchSize = parseInt($('#so-batch-size').val()) || 50;
                        const dirtyType = $('#so-dirty-type').val();
                        processNextBatch(batchSize, dirtyType);
                    }, 600);
                    return;
                }
                addLog('❌ ' + message, true);
                finishCleaning();
            }
            
            function finishCleaning() {
                isCleaning = false;
                $('#so-stop-clean-btn').hide();
                $('#so-start-clean-btn').show().text('🚀 بدء التنظيف');
                $('#so-progress-bar').css('width', '100%');
                $('#so-progress-text').text('اكتمل! عولجت: ' + totalProcessed + '، حدثت: ' + totalUpdated + '، تخطت: ' + totalSkipped + '، أخطاء: ' + totalErrors);
                addLog('─────────────────────────────');
                addLog('📊 النتيجة النهائية:');
                addLog('   • عولجت: ' + totalProcessed);
                addLog('   • حدثت: ' + totalUpdated);
                addLog('   • تخطت: ' + totalSkipped);
                addLog('   • أخطاء: ' + totalErrors);
            }
            
            function addLog(message, isError = false, isDetail = false) {
                const timestamp = new Date().toLocaleTimeString('ar');
                const color = isError ? '#b32d2e' : (isDetail ? '#50575e' : '#2271b1');
                const prefix = isDetail ? '   ' : '';
                const logEntry = $('<div style="color: ' + color + '; border-bottom: 1px solid #e5e7eb; padding: 4px 0;">' + prefix + '[' + timestamp + '] ' + message + '</div>');
                $('#so-log').prepend(logEntry);
            }

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function formatNumber(value) {
                const parsed = parseInt(value, 10);
                return Number.isFinite(parsed) ? parsed.toLocaleString('ar') : '0';
            }

            function getDominantDirtyType(byType) {
                const map = {
                    empty: 'فارغ',
                    vague: 'غامض',
                    media: 'إعلامي',
                    person: 'أشخاص',
                    country_only: 'دول',
                    regex: 'Regex'
                };
                let winnerKey = 'empty';
                let winnerCount = 0;
                Object.keys(map).forEach(function(key) {
                    const count = parseInt(byType[key] || 0, 10);
                    if (count > winnerCount) {
                        winnerKey = key;
                        winnerCount = count;
                    }
                });
                return {
                    key: winnerKey,
                    label: map[winnerKey] || winnerKey,
                    count: winnerCount
                };
            }

            function renderSamples(samples) {
                if (!Array.isArray(samples) || samples.length === 0) {
                    return '<p class="description">لا توجد عينات متاحة.</p>';
                }
                return samples.map(function(sample) {
                    return '<div style="background: #f0f6fc; border-left: 3px solid #2271b1; padding: 10px; margin: 5px 0; font-size: 13px;">'
                        + '<strong>ID:</strong> ' + formatNumber(sample.id) + ' | '
                        + '<strong>النوع:</strong> ' + escapeHtml(sample.type || '') + ' | '
                        + '<strong>الفاعل:</strong> <span style="color: #b32d2e;">' + escapeHtml(sample.actor || '') + '</span><br>'
                        + '<small>' + escapeHtml(sample.title || '') + '</small>'
                        + '</div>';
                }).join('');
            }
            
            // تحميل الإحصائيات الأولية
            $('#so-stats-btn').click();
        });
        </script>
        <?php
    }

    private static function clamp_int($value, int $min, int $max, int $default): int {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => $default]]);
        return max($min, min($max, (int) $number));
    }

    private static function normalize_dirty_type($dirty_type): string {
        $value = sanitize_text_field((string) $dirty_type);
        return in_array($value, self::ALLOWED_DIRTY_TYPES, true) ? $value : 'all';
    }
}

// تهيئة الواجهة
SO_Local_Intelligence_Admin::init();
