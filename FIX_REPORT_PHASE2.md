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
