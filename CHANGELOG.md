# Changelog

## [v3.4.0] - Settings & Architecture Finalization
- **Feature:** Added sticky "Save Changes" bar for better UX.
- **Feature:** Implemented Import/Export/Reset logic for settings.
- **Feature:** Complete Settings Reference documentation.
- **Fix:** Finalized sanitization for array-based settings.

## [v3.3.0] - UI/UX Redesign
- **Feature:** Complete Admin UI overhaul with Cards, Badges, and Progress Bars.
- **Feature:** Customizable Dashboard Widgets and Auto-Refresh.
- **Feature:** Theme customization (Colors, Dark Mode, Density).
- **Feature:** Toast notifications for actions.

## [v3.2.0] - Performance & Security
- **Security:** Webhook Rate Limiting, IP Whitelist, and Strict Mode.
- **Security:** Dynamic Capability checks and Nonce expiration.
- **Performance:** Caching Layer with configurable TTLs.
- **Performance:** Queue Auto-Pause logic and Retry Strategies.
- **Performance:** Database optimization and Asset loading control.

## [v3.1.1] - Critical Fixes
- **Fix:** SQL Injection vulnerability in `ORDER BY` clauses.
- **Fix:** Unbounded database queries capped with `LIMIT`.
- **Fix:** Removed blocking `sleep()` calls.
- **Fix:** Implemented Queue Locking to prevent race conditions.
- **Fix:** Settings API architecture established.
