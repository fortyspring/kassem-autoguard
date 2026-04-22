# Production Hardening Applied

## Applied on uploaded package
- Clean bootstrap kept from uploaded archive
- Added `includes/production-hardening.php`
- Main plugin updated to `22.1.0`

## What changed
### 1) AJAX de-dup cleanup
- Added `sod_register_ajax_once()`
- Converted core dashboard/newslog/reanalyze/manual sync/admin telegram handlers to guarded single registration
- Refactored duplicate-cleaner autoloader registrations to guarded single registration

### 2) Cron unification
- Added `SOD_Production_Kernel`
- Unified schedule creation with `sod_schedule_event_once()` and `sod_schedule_single_once()`
- Added watchdog cron `sod_watchdog_cron`
- Added cleanup for old cron hook `so_cron_fetch_news_v4`
- Guarded fetch-news/daily-cleanup scheduling against duplicate scheduling

### 3) News Pipeline hardening
- Added candidate normalization
- Added stale-item rejection
- Added similarity fingerprinting + probable duplicate check before insert
- Added watchdog heartbeat around pipeline execution

### 4) Legacy cleanup
- Disabled loading of `includes/v24-surgery.php` from bootstrap
- Disabled duplicate `osint-executive-reports.php` loading; `SO_Executive_Reports` in main file remains source of truth
- Restricted `SO_Plugin_Admin` alias behind option `sod_enable_legacy_aliases`

### 5) Performance / Production mode
- Added `SOD_PRODUCTION_MODE`
- Added runtime defaults with non-autoload writes where possible
- Added shortcode alias `osint_live_command_center_v2`
- Required `src/class-autoloader.php` early in bootstrap so refactored services are consistently available

## Files changed
- `osint-pro.php`
- `includes/production-hardening.php` (new)
- `src/class-autoloader.php`
- `src/services/class-batch-reindexer.php`

## Validation
- `php -l osint-pro.php`
- `php -l includes/production-hardening.php`
- `php -l src/class-autoloader.php`
- `php -l src/services/class-batch-reindexer.php`

All syntax checks passed.
