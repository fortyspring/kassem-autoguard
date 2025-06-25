<?php
/**
 * Plugin Name: Kassem AutoGuard
 * Description: أداة ذكية لتحسين أداء WordPress من خلال تنظيف خيارات autoload.
 * Version: 1.0.12
 * Author: محمد قاسم
 * License: GPLv2 or later
 */

defined('ABSPATH') or die('No script kiddies please!');

$settings_file = plugin_dir_path(__FILE__) . 'includes/settings.php';
if (file_exists($settings_file)) {
    require_once $settings_file;
} else {
    error_log('Kassem AutoGuard missing file: ' . $settings_file);
}

$cleaner_file = plugin_dir_path(__FILE__) . 'includes/autoload-cleaner.php';
if (file_exists($cleaner_file)) {
    require_once $cleaner_file;
} else {
    error_log('Kassem AutoGuard missing file: ' . $cleaner_file);
}
