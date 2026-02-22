# Settings Reference

This document lists all configuration options stored in the `pw_settings` option array.

## General
- `sending_enabled` (bool): Global toggle to enable/disable all sending operations.
- `global_tag` (string): Tag added to all emails sent via Postal.
- `disable_ip_logging` (bool): If true, IP addresses are not logged (GDPR compliance).
- `enable_logging` (bool): Master switch for the logging system.
- `schedule_start_hour` (int): Hour of day to start sending (0-23).
- `schedule_end_hour` (int): Hour of day to stop sending (0-23).
- `send_on_weekends` (bool): If true, sending is allowed on Saturday and Sunday.

## Security
- `webhook_strict_mode` (bool): Enforce signature verification for webhooks.
- `webhook_secret` (string): Secret token for HMAC signature verification.
- `webhook_ip_whitelist` (string): Newline-separated list of allowed IPs/CIDR.
- `webhook_rate_limit_minute` (int): Max webhook requests per minute per IP.
- `webhook_rate_limit_hour` (int): Max webhook requests per hour per IP.
- `webhook_invalid_signature_action` (string): Action on failure (reject/log/notify).
- `nonce_expiration` (int): Lifetime of nonces in hours.
- `required_capability` (string): Capability required to access plugin settings.
- `mask_api_keys_logs` (bool): Mask secrets in log files.
- `mask_api_keys_ui` (bool): Mask secrets in Admin UI.
- `log_sensitive_data` (string): Logging level for PII (full/masked/none).
- `auto_cleanup_debug_files` (bool): Delete debug.log on plugin activation.
- `domscan_api_key` (string): API Key for DomScan integration.

## Queue
- `queue_batch_size` (int): Number of emails processed per minute.
- `queue_interval` (int): Cron interval in minutes (standard: 1).
- `max_queue_workers` (int): Concurrent worker limit (not fully utilized in WP Cron mode).
- `queue_locking_enabled` (bool): Use transients to prevent race conditions.
- `queue_lock_timeout` (int): Lock expiration in seconds.
- `max_retries` (int): Maximum attempts for failed emails.
- `retry_strategy` (string): Retry logic (fixed/linear/exponential).
- `retry_delay_base` (int): Base delay in seconds.
- `retry_delay_max` (int): Max delay cap in seconds.
- `queue_pause_threshold` (int): Pause queue if 24h failure rate exceeds X%.
- `queue_resume_delay` (int): Minutes to wait before resuming after auto-pause.
- `daily_limit_global` (int): Max emails per day across all servers (0 = unlimited).
- `hourly_limit_global` (int): Max emails per hour across all servers.

## Warmup
- `warmup_mode` (string): Default ramp-up mode (linear/exponential).
- `warmup_start` (int): Starting volume for Day 1.
- `warmup_max` (int): Target maximum volume.
- `warmup_days` (int): Duration of the ramp-up phase.
- `warmup_increase_percent` (float): Daily increase percentage.
- `pause_bounce_rate` (float): Threshold to pause sending based on bounces.
- `pause_spam_rate` (float): Threshold to pause based on complaints.
- `default_from_name` (string): Fallback Sender Name.
- `default_from_email` (string): Fallback Sender Email.
- `custom_headers` (string): Headers injected into every email.
- `bounce_handling_action` (string): Action on bounce (mark_failed/remove/notify).

## Performance
- `enable_transient_cache` (bool): Enable object caching for UI/API.
- `cache_backend` (string): Preferred backend (auto/transient/redis).
- `cache_ttl_server` (int): TTL for server list in seconds.
- `cache_ttl_stats` (int): TTL for statistics in seconds.
- `cache_ttl_api` (int): TTL for external API calls in seconds.
- `auto_purge_queue_days` (int): Days to keep queue history.
- `auto_purge_logs_days` (int): Days to keep logs.
- `db_purge_schedule` (string): Frequency of cleanup (daily/weekly).
- `db_optimize_on_purge` (bool): Run OPTIMIZE TABLE after cleanup.
- `assets_load_optimization` (bool): Load admin assets only on plugin pages.
- `db_query_limit` (int): Max rows returned by DB queries.
- `api_timeout` (int): Timeout for HTTP requests in seconds.
- `db_transactions` (bool): Use SQL transactions for critical operations.

## Interface
- `dashboard_refresh` (int): Auto-refresh interval in seconds.
- `default_sort_column` (string): Default sort column for lists.
- `default_sort_order` (string): Default sort order (ASC/DESC).
- `default_rows_per_page` (int): Items per page.
- `color_theme` (string): UI Theme (blue/dark/custom).
- `ui_color_primary` (string): Primary HEX color.
- `ui_color_success` (string): Success HEX color.
- `ui_color_warning` (string): Warning HEX color.
- `ui_color_danger` (string): Danger HEX color.
- `ui_dark_mode` (string): Dark mode setting (auto/always/never).
- `table_density` (string): UI spacing (compact/normal/comfortable).
- `toast_notifications` (string): Toast position or disabled.
- `enable_animations` (bool): Enable UI transitions.
- `dashboard_widgets` (array): Visible widgets on dashboard.

## Notifications
- `notify_email` (string): Recipient for system alerts.
- `notify_on_error` (bool): Alert on critical errors.
- `notify_daily_report` (bool): Send daily summary.
- `notify_stuck_queue` (bool): Alert if queue is stuck.
- `notify_api_error` (bool): Alert on API connectivity issues.
- `notify_stuck_queue_threshold` (int): Minutes before considering queue stuck.
- `notify_failure_rate_threshold` (int): Percent failure before alert.

## Advanced
- `log_mode` (string): Storage for logs (file/db/both).
- `log_level` (string): Minimum log level recorded.
- `encryption_method` (string): Algorithm for secrets (aes-256-cbc).
- `log_max_file_size` (int): Rotation threshold in MB.
- `log_auto_purge_deactivation` (bool): Delete logs on plugin deactivation.
- `debug_mode` (bool): Enable verbose debug logging.
