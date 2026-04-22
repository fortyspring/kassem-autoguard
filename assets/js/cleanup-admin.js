/**
 * Archive Cleanup Admin JavaScript - OSINT-LB PRO
 * 
 * @package OSINT_LB_PRO
 */

jQuery(document).ready(function($) {
    'use strict';

    let isRunning = false;
    let offset = 0;
    let totalProcessed = 0;
    let totalSuccess = 0;
    let totalFailed = 0;
    let userStopped = false;

    // بدء تنظيف الأرشيف
    $('#start-cleanup-btn').on('click', function() {
        if (isRunning) return;
        
        if (!confirm(soCleanup.strings.confirmStart)) {
            return;
        }

        resetCounters();
        isRunning = true;
        userStopped = false;

        toggleButtons(true);
        showProgressUI();
        clearLog();

        runBatch();
    });

    // إيقاف التنظيف
    $('#stop-cleanup-btn').on('click', function() {
        if (!isRunning) return;
        
        userStopped = true;
        isRunning = false;
        
        toggleButtons(false);
        updateProgressText(soCleanup.strings.stopped);
        
        addLogEntry({
            type: 'info',
            message: 'توقف عن طريق المستخدم'
        });
    });

    /**
     * إعادة تعيين العدادات
     */
    function resetCounters() {
        offset = 0;
        totalProcessed = 0;
        totalSuccess = 0;
        totalFailed = 0;
    }

    /**
     * تبديل حالة الأزرار
     */
    function toggleButtons(running) {
        if (running) {
            $('#start-cleanup-btn').hide();
            $('#stop-cleanup-btn').show();
        } else {
            $('#stop-cleanup-btn').hide();
            $('#start-cleanup-btn').show();
        }
    }

    /**
     * إظهار واجهة شريط التقدم
     */
    function showProgressUI() {
        $('#progress-container').fadeIn();
        $('#log-container').fadeIn();
    }

    /**
     * مسح السجل
     */
    function clearLog() {
        $('#log-content').html('');
    }

    /**
     * تحديث نص التقدم
     */
    function updateProgressText(text) {
        $('#progress-text').text(text);
    }

    /**
     * إضافة_entry إلى السجل
     */
    function addLogEntry(entry) {
        let cssClass = '';
        let icon = '';
        
        switch(entry.type) {
            case 'success':
                cssClass = 'background: #d4edda; border-left: 4px solid #28a745;';
                icon = '✓';
                break;
            case 'error':
                cssClass = 'background: #f8d7da; border-left: 4px solid #dc3545;';
                icon = '✗';
                break;
            case 'skipped':
                cssClass = 'background: #fff3cd; border-left: 4px solid #ffc107;';
                icon = '⊘';
                break;
            case 'info':
                cssClass = 'background: #d1ecf1; border-left: 4px solid #17a2b8;';
                icon = 'ℹ';
                break;
            default:
                cssClass = 'background: #e9ecef; border-left: 4px solid #6c757d;';
                icon = '•';
        }

        const html = `<div style="padding: 8px 12px; margin-bottom: 5px; border-radius: 3px; ${cssClass}">
            <strong>${icon}</strong> ${entry.message}
        </div>`;

        $('#log-content').prepend(html);

        // الحفاظ على عدد معقول من الإدراجات في السجل
        const maxEntries = 100;
        const currentEntries = $('#log-content > div').length;
        if (currentEntries > maxEntries) {
            $('#log-content > div').slice(maxEntries).remove();
        }
    }

    /**
     * تشغيل دفعة تنظيف
     */
    function runBatch() {
        if (!isRunning || userStopped) return;

        const batchSize = parseInt($('#batch-size').val()) || 50;

        $.ajax({
            url: soCleanup.ajaxUrl,
            type: 'POST',
            data: {
                action: 'so_cleanup_batch',
                limit: batchSize,
                offset: offset,
                nonce: soCleanup.nonce
            },
            timeout: 60000, // 60 ثانية مهلة
            success: function(response) {
                if (!response.success) {
                    handleError(response.data?.message || 'خطأ غير معروف');
                    return;
                }

                const data = response.data;
                
                // تحديث الإحصائيات
                totalProcessed += data.total;
                totalSuccess += data.success;
                totalFailed += data.failed;

                // معالجة النتائج
                if (data.results && data.results.length > 0) {
                    data.results.forEach(function(result) {
                        if (!result.result) return;

                        if (result.result.success) {
                            if (result.result.old_actor !== result.result.new_actor) {
                                addLogEntry({
                                    type: 'success',
                                    message: `Event #${result.event_id}: ${result.result.old_actor} → <strong>${result.result.new_actor}</strong> (${result.result.confidence}%)`
                                });
                            } else {
                                addLogEntry({
                                    type: 'skipped',
                                    message: `Event #${result.event_id}: ${result.result.reason || 'تم تخطيه'}`
                                });
                            }
                        } else {
                            addLogEntry({
                                type: 'error',
                                message: `Event #${result.event_id}: ${result.result.error || 'فشل'}`
                            });
                        }
                    });
                }

                // تحديث شريط التقدم
                updateProgressBar();

                // تحديث العدادات
                updateCounters(data.success);

                // الاستمرار أو التوقف
                offset += batchSize;

                if (data.total === batchSize && !userStopped) {
                    // هناك المزيد من البيانات، استمر بعد تأخير قصير
                    setTimeout(runBatch, 300);
                } else {
                    // اكتمل التنظيف
                    completeCleanup();
                }
            },
            error: function(xhr, status, error) {
                if (userStopped) return;
                
                let errorMsg = 'خطأ في الاتصال';
                if (status === 'timeout') {
                    errorMsg = 'انتهت مهلة العملية';
                } else if (xhr.responseJSON?.data?.message) {
                    errorMsg = xhr.responseJSON.data.message;
                }
                
                handleError(errorMsg);
            }
        });
    }

    /**
     * تحديث شريط التقدم
     */
    function updateProgressBar() {
        const dirtyCount = parseInt($('#dirty-count').text().replace(/,/g, '')) || 0;
        
        if (dirtyCount > 0) {
            const progress = Math.min(100, Math.round((totalProcessed / dirtyCount) * 100));
            $('#progress-bar').css('width', progress + '%');
            updateProgressText(`${progress}% (${totalProcessed}/${dirtyCount})`);
        } else {
            updateProgressText(`جاري المعالجة... (${totalProcessed})`);
        }
    }

    /**
     * تحديث العدادات
     */
    function updateCounters(successCount) {
        const currentDirty = parseInt($('#dirty-count').text().replace(/,/g, '')) || 0;
        const currentCleaned = parseInt($('#cleaned-count').text().replace(/,/g, '')) || 0;
        
        $('#dirty-count').text(Math.max(0, currentDirty - successCount).toLocaleString());
        $('#cleaned-count').text((currentCleaned + successCount).toLocaleString());
    }

    /**
     * إكمال عملية التنظيف
     */
    function completeCleanup() {
        isRunning = false;
        toggleButtons(false);
        updateProgressText(soCleanup.strings.completed);
        
        addLogEntry({
            type: 'info',
            message: `اكتمل التنظيف! نجح: ${totalSuccess}, فشل: ${totalFailed}`
        });

        // إعادة تحميل الصفحة بعد ثانيتين لتحديث الإحصائيات
        setTimeout(function() {
            location.reload();
        }, 2000);
    }

    /**
     * معالجة الخطأ
     */
    function handleError(message) {
        isRunning = false;
        toggleButtons(false);
        updateProgressText(soCleanup.strings.error);
        
        addLogEntry({
            type: 'error',
            message: `خطأ: ${message}`
        });

        alert(soCleanup.strings.error + ': ' + message);
    }

    /**
     * تحديث الإحصائيات دورياً
     */
    function refreshStats() {
        if (isRunning) return;

        $.ajax({
            url: soCleanup.ajaxUrl,
            type: 'POST',
            data: {
                action: 'so_get_cleanup_stats',
                nonce: soCleanup.nonce
            },
            success: function(response) {
                if (response.success && response.data.recent_cleanups) {
                    updateRecentList(response.data.recent_cleanups);
                }
            }
        });

        $.ajax({
            url: soCleanup.ajaxUrl,
            type: 'POST',
            data: {
                action: 'so_count_dirty_events',
                nonce: soCleanup.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#dirty-count').text(response.data.count.toLocaleString());
                }
            }
        });
    }

    /**
     * تحديث قائمة أحدث العمليات
     */
    function updateRecentList(cleanups) {
        if (!cleanups || cleanups.length === 0) return;

        let html = '<table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">' +
            '<thead>' +
                '<tr>' +
                    '<th>الحدث</th>' +
                    '<th>الفاعل القديم</th>' +
                    '<th>الفاعل الجديد</th>' +
                    '<th>الثقة</th>' +
                    '<th>التاريخ</th>' +
                '</tr>' +
            '</thead>' +
            '<tbody>';

        cleanups.forEach(function(cleanup) {
            html += '<tr>' +
                `<td>#${cleanup.event_id}</td>` +
                `<td>${cleanup.old_actor || '-'}</td>` +
                `<td><strong>${cleanup.new_actor || '-'}</strong></td>` +
                `<td>${cleanup.confidence}%</td>` +
                `<td>${cleanup.cleaned_at}</td>` +
            '</tr>';
        });

        html += '</tbody></table>';

        $('#recent-list').html(html);
    }

    // التحديث الدوري للإحصائيات كل 30 ثانية
    setInterval(refreshStats, 30000);
});
