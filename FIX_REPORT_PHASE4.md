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
