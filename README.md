# Postal Warmup Pro

**Version:** 3.4.0
**Requires:** WordPress 5.8+ | PHP 8.1+
**License:** GPLv2 or later

Postal Warmup Pro is a comprehensive solution for warming up your IP addresses and domains using Postal SMTP. It automates the process of gradually increasing email volume, monitoring reputation, and managing server health.

## 🚀 Key Features

### 🔧 Warmup Engine (New in v3.4)
- **Global Control:** Master switch to start/stop all sending instantly.
- **Smart Scheduling:** Configure sending windows (e.g., 8am - 8pm) and weekend pauses.
- **Automated Strategy:** Linear or Exponential ramp-up modes with customizable daily limits.
- **Bounce Handling:** Automatically removes or flags emails that bounce to protect your reputation.

### 🛡️ Security Hardening (New in v3.2)
- **Webhook Security:** IP Whitelisting, Rate Limiting, and HMAC Signature verification.
- **API Encryption:** AES-256 encryption for all stored API keys.
- **Role Management:** Configurable capability requirements for admin access.

### ⚡ Performance & Caching
- **Transparent Caching:** Reduces database load by caching server lists and stats (configurable TTL).
- **Queue Optimization:** Auto-pauses queues when failure rates spike (>50%).
- **Database Maintenance:** Automated cleanup and optimization of logs and stats tables.

### 🎨 Modern UI/UX
- **Dashboard:** Customizable widgets, auto-refresh, and real-time health monitoring.
- **Theme Support:** Light/Dark mode and custom color themes.
- **Settings Panel:** Centralized configuration for all plugin aspects with Import/Export capabilities.

## 📦 Installation

1. Upload the plugin files to the `/wp-content/plugins/postal-warmup-pro` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Use the **Postal Warmup > Settings** screen to configure your API keys and sending preferences.
4. Add your Postal servers in the **Servers** tab.

## ⚙️ Configuration

Check the `SETTINGS_REFERENCE.md` file for a complete list of available configuration options in the new Settings Panel.

## 📝 Changelog

See `CHANGELOG.md` for full version history.
