<?php
/**
 * WP-CLI Command for Archive Cleanup - OSINT-LB PRO
 * 
 * سطر أوامر WP-CLI لتنظيف الأرشيف
 * 
 * @package OSINT_LB_PRO
 */

if (!defined('ABSPATH')) {
    exit;
}

WP_CLI::add_command('so-cleanup-archive', 'SO_Cleanup_Archive_CLI');

class SO_Cleanup_Archive_CLI {

    /**
     * تنظيف أرشيف الفاعلين الملوثين
     * 
     * ## OPTIONS
     * 
     * [--limit=<number>]
     * : عدد الأحداث في كل دفعة (الافتراضي: 50)
     * 
     * [--offset=<number>]
     * : نقطة البداية (الافتراضي: 0)
     * 
     * [--max=<number>]
     * : الحد الأقصى للأحداث المراد معالجتها (الافتراضي: غير محدود)
     * 
     * [--dry-run]
     * : تشغيل تجريبي دون إجراء تغييرات فعلية
     * 
     * [--format=<format>]
     * : تنسيق الإخراج (table/json/csv) - الافتراضي: table
     * 
     * ## EXAMPLES
     * 
     *     # تنظيف 100 حدث
     *     wp so-cleanup-archive --limit=50 --max=100
     * 
     *     # تشغيل تجريبي
     *     wp so-cleanup-archive --dry-run
     * 
     *     # تصدير النتائج كـ JSON
     *     wp so-cleanup-archive --format=json > results.json
     * 
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args) {
        if (!function_exists('so_cleanup_archive_batch')) {
            WP_CLI::error('أداة تنظيف الأرشيف غير مفعلة');
        }

        if (!function_exists('so_count_dirty_events')) {
            WP_CLI::error('دالة عد الأحداث الملوثة غير موجودة');
        }

        $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 50;
        $offset = isset($assoc_args['offset']) ? intval($assoc_args['offset']) : 0;
        $max = isset($assoc_args['max']) ? intval($assoc_args['max']) : PHP_INT_MAX;
        $dry_run = isset($assoc_args['dry-run']);
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        $dirty_count = so_count_dirty_events();

        if ($dirty_count === 0) {
            WP_CLI::success('لا توجد أحداث ملوثة تحتاج إلى تنظيف');
            return;
        }

        WP_CLI::line(sprintf(
            'تم العثور على %d حدث ملوث يحتاج إلى تنظيف',
            number_format_i18n($dirty_count)
        ));

        if ($dry_run) {
            WP_CLI::warning('وضع التشغيل التجريبي - لن يتم إجراء أي تغييرات');
        }

        WP_CLI::confirm('هل تريد بدء عملية التنظيف؟', $assoc_args);

        $total_processed = 0;
        $total_success = 0;
        $total_failed = 0;
        $total_skipped = 0;
        $results = [];

        $progress_bar = \WP_CLI\Utils\make_progress_bar(
            'جاري تنظيف الأرشيف',
            min($dirty_count, $max)
        );

        while ($total_processed < $max) {
            $batch = so_cleanup_archive_batch($limit, $offset);

            if (empty($batch['total'])) {
                break;
            }

            foreach ($batch['results'] as $result) {
                $event_id = $result['event_id'];
                $clean_result = $result['result'];

                if ($clean_result['success']) {
                    if ($clean_result['old_actor'] === $clean_result['new_actor']) {
                        $total_skipped++;
                        $status = 'skipped';
                    } else {
                        $total_success++;
                        $status = 'success';
                    }
                } else {
                    $total_failed++;
                    $status = 'failed';
                }

                $results[] = [
                    'event_id' => $event_id,
                    'title' => $result['title'] ?? '',
                    'old_actor' => $clean_result['old_actor'] ?? '',
                    'new_actor' => $clean_result['new_actor'] ?? '',
                    'confidence' => $clean_result['confidence'] ?? 0,
                    'status' => $status,
                    'error' => $clean_result['error'] ?? '',
                ];

                $progress_bar->tick();
            }

            $total_processed += $batch['total'];
            $offset += $limit;

            // إيقاف إذا لم تعد هناك نتائج
            if ($batch['total'] < $limit) {
                break;
            }
        }

        $progress_bar->finish();

        // عرض النتائج
        $this->display_results($results, $format);

        // عرض الملخص
        WP_CLI::line('');
        WP_CLI::line('=== ملخص العملية ===');
        WP_CLI::line(sprintf('تمت المعالجة: %d', number_format_i18n($total_processed)));
        WP_CLI::line(sprintf('ناجح: %d', number_format_i18n($total_success)));
        WP_CLI::line(sprintf('فشل: %d', number_format_i18n($total_failed)));
        WP_CLI::line(sprintf('تم تخطيه: %d', number_format_i18n($total_skipped)));

        if ($total_success > 0) {
            WP_CLI::success(sprintf('تم تنظيف %d حدث بنجاح', number_format_i18n($total_success)));
        }

        if ($total_failed > 0) {
            WP_CLI::warning(sprintf('فشل تنظيف %d حدث', number_format_i18n($total_failed)));
        }
    }

    /**
     * عرض النتائج بالتنسيق المطلوب
     */
    private function display_results($results, $format) {
        switch ($format) {
            case 'json':
                WP_CLI::line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                break;

            case 'csv':
                if (!empty($results)) {
                    $headers = array_keys($results[0]);
                    WP_CLI::line(implode(',', $headers));
                    foreach ($results as $row) {
                        WP_CLI::line(implode(',', array_map('esc_csv', $row)));
                    }
                }
                break;

            case 'table':
            default:
                if (!empty($results)) {
                    $rows = array_map(function($r) {
                        return [
                            $r['event_id'],
                            mb_substr($r['title'] ?? '', 0, 30),
                            $r['old_actor'],
                            $r['new_actor'],
                            $r['confidence'] . '%',
                            $r['status'],
                        ];
                    }, array_slice($results, 0, 20)); // أول 20 نتيجة فقط للجدول

                    \WP_CLI\Utils\format_items('table', $rows, [
                        'ID', 'العنوان', 'الفاعل القديم', 'الفاعل الجديد', 'الثقة', 'الحالة'
                    ]);

                    if (count($results) > 20) {
                        WP_CLI::line(sprintf('... و %d نتيجة إضافية', count($results) - 20));
                    }
                }
                break;
        }
    }

    /**
     * عرض إحصائيات التنظيف
     * 
     * ## OPTIONS
     * 
     * [--format=<format>]
     * : تنسيق الإخراج (table/json) - الافتراضي: table
     * 
     * ## EXAMPLES
     * 
     *     # عرض الإحصائيات
     *     wp so-cleanup-archive stats
     * 
     *     # تصدير الإحصائيات كـ JSON
     *     wp so-cleanup-archive stats --format=json
     * 
     * @subcommand stats
     * @when after_wp_load
     */
    public function stats($args, $assoc_args) {
        if (!function_exists('so_get_cleanup_stats')) {
            WP_CLI::error('دالة الإحصائيات غير موجودة');
        }

        if (!function_exists('so_count_dirty_events')) {
            WP_CLI::error('دالة العد غير موجودة');
        }

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        $stats = so_get_cleanup_stats();
        $dirty_count = so_count_dirty_events();

        $data = [
            'total_cleaned' => $stats['total_cleaned'] ?? 0,
            'remaining_dirty' => $dirty_count,
            'top_actors' => $stats['top_actors'] ?? [],
            'recent_cleanups' => count($stats['recent_cleanups'] ?? []),
        ];

        switch ($format) {
            case 'json':
                WP_CLI::line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                break;

            case 'table':
            default:
                WP_CLI::line('');
                WP_CLI::line('=== إحصائيات تنظيف الأرشيف ===');
                WP_CLI::line('');
                WP_CLI::line(sprintf('إجمالي ما تم تنظيفه: %d', number_format_i18n($data['total_cleaned'])));
                WP_CLI::line(sprintf('الأحداث الملوثة المتبقية: %d', number_format_i18n($data['remaining_dirty'])));
                WP_CLI::line('');

                if (!empty($data['top_actors'])) {
                    WP_CLI::line('أكثر الفاعلين الذين تم تحديدها:');
                    $rows = array_map(function($actor) {
                        return [$actor['new_actor'], number_format_i18n($actor['count'])];
                    }, array_slice($data['top_actors'], 0, 10));

                    \WP_CLI\Utils\format_items('table', $rows, ['الفاعل', 'عدد المرات']);
                    WP_CLI::line('');
                }

                if (!empty($data['recent_cleanups'])) {
                    WP_CLI::line(sprintf('آخر %d عمليات تنظيف:', $data['recent_cleanups']));
                }
                break;
        }
    }

    /**
     * عد الأحداث الملوثة
     * 
     * ## EXAMPLES
     * 
     *     # عد الأحداث الملوثة
     *     wp so-cleanup-archive count
     * 
     * @subcommand count
     * @when after_wp_load
     */
    public function count($args, $assoc_args) {
        if (!function_exists('so_count_dirty_events')) {
            WP_CLI::error('دالة العد غير موجودة');
        }

        $count = so_count_dirty_events();
        WP_CLI::line(number_format_i18n($count));
    }
}

// دالة مساعدة لـ CSV
if (!function_exists('esc_csv')) {
    function esc_csv($value) {
        $value = (string)$value;
        if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
