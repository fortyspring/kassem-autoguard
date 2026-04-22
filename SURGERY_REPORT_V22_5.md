# V22.5 Surgery Build 1

## What was cleaned
- Removed duplicate `wp_ajax_so_manual_sync` registration from the main plugin file.
- Replaced anonymous `so_instant_alerts_cron` callback with a named bridge callback for safer deduplication and debugging.
- Verified the whole plugin passes PHP syntax lint after surgery.

## Files identified as unused in this package snapshot
These files are not directly required by the current loader and were removed from the shipped package to reduce dead weight:
- performance-config.php
- includes/class-db-updater.php
- includes/class-modular-core.php
- includes/class-osint-migrator.php
- refactored/repositories/EventRepository.php
- src/core/class-activation.php
- src/core/class-deactivation.php
- src/core/class-plugin.php
- src/services/class-classifier.php
- src/services/class-newslog.php
- src/utils/class-text-utils.php
- src/utils/class-validation.php

## Remaining hot spots to clean next
- multiple `admin_init` registrations
- multiple `admin_menu` registrations
- multiple `wp_footer` feature injectors
- large monolithic main plugin file
