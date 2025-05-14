<?php
/**
 * Plugin Name: Kassem AutoGuard
 * Description: أداة ذكية لتحسين أداء WordPress من خلال تنظيف خيارات autoload.
 * Version: 1.0.12
 * Author: محمد قاسم
 * License: GPLv2 or later
 */

defined('ABSPATH') or die('No script kiddies please!');

require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/autoload-cleaner.php';
