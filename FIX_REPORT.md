# Fix Report - Phase 1 & Settings Architecture

## Phase 1: Critical Bugs + Settings

### Database & SQL
- **SQL Injection Prevention:** 
  - Verified `Database::get_servers` uses a column whitelist for `ORDER BY`.
  - Added `default_sort_column` and `default_sort_order` settings to control defaults.
- **Unbounded Queries:**
  - Added `LIMIT` clause to `get_servers`, `get_logs`, and `get_enriched_activity` queries.
  - Implemented `db_query_limit` setting (default: 500) to cap results.
  - Capped pagination size in `get_logs` against the global limit.

### Logic & Performance
- **Sleep Calls:**
  - Removed `usleep(50000)` from `Logger::cleanup_old_logs` to prevent process blocking.
- **Queue Management:**
  - Implemented **Queue Locking** using WordPress Transients (`pw_queue_lock`).
  - Added settings: `queue_locking_enabled` (default: ON) and `queue_lock_timeout` (default: 60s).
  - Implemented **Retry Logic** in `QueueManager` with configurable strategies:
    - `fixed`: Base delay.
    - `linear`: Base * attempts.
    - `exponential`: Base * (2^attempts).
  - Added `max_retries` setting logic in `QueueManager`.
- **API Reliability:**
  - Added configurable `api_timeout` (default: 15s) to `Sender.php` and `Client.php`.
  - Refactored `Sender::process_queue` to allow external retry handling (preventing duplicate retries when called from `QueueManager`).

### Settings Architecture (Phase 6)
- **New `Settings` Class:**
  - Implemented `src/Admin/Settings.php` handling a single `pw_settings` array option.
  - Support for Tabbed Interface (General, Security, Queue, Performance, Interface, Notifications).
  - Built-in Migration logic to map legacy options (e.g. `pw_global_tag`) to the new array structure.
  - Static `Settings::get($key, $default)` helper for easy access throughout the codebase.
- **UI Updates:**
  - Updated `admin/partials/settings.php` to render the tabbed navigation and dynamic sections.

## Files Modified
- `src/Admin/Settings.php` (Created/Overwritten)
- `admin/partials/settings.php` (Overwritten)
- `src/Models/Database.php`
- `src/Services/Logger.php`
- `src/Services/QueueManager.php`
- `src/API/Sender.php`
- `src/API/Client.php`
# Fix Report - Phase 2: Security & Settings

## Security Hardening

### Authentication
- **Dynamic Capabilities:** Replaced hardcoded `manage_options` checks with `Settings::get('required_capability')` in `AjaxHandler.php` and `Settings.php`.
- **Configurable Nonces:** Implemented `nonce_life` filter in `Plugin.php` controlled by `nonce_expiration` setting.

### Webhook Security
- **Strict Mode:** Enforced signature verification by default in `WebhookHandler.php`.
- **Rate Limiting:** Implemented IP-based rate limiting (minute/hour) using transients.
- **IP Whitelist:** Added IP/CIDR whitelist check before signature verification.
- **Invalid Signature Action:** Configurable action (Log/Notify/Reject) on failure.

### Data Protection
- **Encryption:** `Encryption` service uses HMAC (Encrypt-then-MAC) and supports key rotation via `SECURE_AUTH_KEY`.
- **API Key Masking:** Implemented masking in Logs (`Logger.php`) and UI (`Settings.php` via password field type/logic).
- **File Cleanup:** `Activator.php` now automatically deletes sensitive debug files (`debug.log`, etc.) on activation.

## Settings Architecture Updates

- **Checkbox Fix:** Solved the "cannot disable" bug by injecting hidden inputs with value `0` for boolean fields.
- **DomScan Integration:** Added `domscan_api_key` field to Settings and updated `DomScanService` to use it.
- **Advisor Integration:** Updated `WarmupAdvisor` to use centralized settings for thresholds and notifications.

## Cleanup
- Removed `webhook_receiver_example.php` from root to prevent potential data leaks.
- Standardized namespaces in all modified files.

## Files Modified
- `src/Admin/Settings.php`
- `admin/partials/settings.php`
- `src/Admin/AjaxHandler.php`
- `src/API/WebhookHandler.php`
- `src/Core/Plugin.php`
- `src/Core/Activator.php`
- `src/Core/Deactivator.php`
- `src/Services/Logger.php`
- `src/Services/WarmupAdvisor.php`
- `src/Services/DomScanService.php`


# Fix Report - Phase 3: Performance & Optimization

## Queue Optimization
- **Auto-Pause:** `QueueManager` now monitors failure rates (24h sliding window). If failure rate > 50% (configurable), the queue pauses for 30 minutes (configurable).
- **Process Locking:** Implemented transient-based locking to prevent concurrent cron execution overlaps.
- **Retry Strategy:** Implemented exponential backoff for retries to reduce load on failing servers.

## Caching Layer
- **New Cache Service:** Updated `src/Services/Cache.php` to provide a transparent caching layer.
- **Configurable TTLs:** Server list (5 min), Stats (10 min), and API responses (5 min) are cached with configurable TTLs via Settings.
- **Backend Agnostic:** Uses WordPress Transients API, automatically benefiting from Redis/Memcached if configured on the server.
- **Global Toggle:** Master switch `enable_transient_cache` to disable all caching for debugging.

## Database Maintenance
- **Auto-Optimization:** Added `OPTIMIZE TABLE` command after log purging (if enabled) to reclaim disk space.
- **Query Limits:** All potential large queries are capped by `db_query_limit` (default 500 rows).

## Asset Optimization
- **Conditional Loading:** `Admin.php` now checks `assets_load_optimization` setting. If enabled (default), CSS/JS are only enqueued on `postal-warmup` admin pages, reducing footprint on the rest of the dashboard.

## Files Modified
- `src/Services/QueueManager.php`
- `src/Services/Cache.php`
- `src/Services/Logger.php`
- `src/Admin/Admin.php`
- `src/Admin/Settings.php`


# Fix Report - Phase 4: Warmup Engine

## Warmup Logic
- **Global Sending Rules:** Implemented master switch `sending_enabled` and `send_on_weekends` toggle in `QueueManager`.
- **Scheduling:** Implemented configurable `schedule_start_hour` and `schedule_end_hour` (default 08:00 - 20:00).
- **Strategy Engine:** Updated `StrategyEngine` to use global fallback settings (`warmup_mode`, `warmup_start`, `warmup_max`) if a specific strategy config is missing.

## Email Customization
- **Header Injection:** `Sender.php` now injects `custom_headers` (e.g. List-Unsubscribe) defined in settings.
- **Identity:** Implemented `default_from_name` fallback.

## Bounce Handling
- **Automated Actions:** `WebhookHandler` detects bounce events and triggers actions based on `bounce_handling_action` setting (mark failed, remove, notify).

## Files Modified
- `src/Services/QueueManager.php`
- `src/Services/StrategyEngine.php`
- `src/API/Sender.php`
- `src/API/WebhookHandler.php`
- `src/Admin/Settings.php`


# Fix Report - Phase 5: UI/UX & Notifications

## Dashboard & Visuals
- **Customizable Dashboard:** Implemented `dashboard_widgets` setting to toggle visibility of "Sent", "Success Rate", "Volume", and "Active Servers" cards.
- **Visual Theme:** Added `ui_color_*` settings (Primary, Success, Warning, Danger) injected as CSS variables in `Admin.php`.
- **Dark Mode:** Implemented logic for `ui_dark_mode` (Auto/Always/Never) with appropriate CSS overrides.
- **Table Density:** Configurable padding via `table_density` setting.

## Notifications System
- **New Alerts:** Implemented `send_stuck_queue_alert` and `send_high_failure_alert` in `EmailNotifications.php`.
- **Integration:** Hooked alerts into `QueueManager` auto-pause logic (Phase 3 integration).
- **Thresholds:** Configurable thresholds for failure rate (%) and queue stuck duration (minutes).

## Settings Architecture
- **Phase 5 Fields:** Added all UI and Notification settings to `Settings.php`, including color pickers and select dropdowns.

## Files Modified
- `src/Admin/Settings.php`
- `admin/partials/dashboard.php`
- `src/Admin/Admin.php`
- `src/Services/EmailNotifications.php`
- `src/Services/QueueManager.php`
