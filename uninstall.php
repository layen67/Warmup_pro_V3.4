<?php
/**
 * Désinstallation du plugin
 * Supprime toutes les données du plugin
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Liste complète des tables à supprimer
$tables = [
    'postal_servers',
    'postal_logs',
    'postal_stats',
    'postal_mailto_clicks',
    'postal_templates',
    'postal_template_folders',
    'postal_template_tags',
    'postal_template_tag_relations',
    'postal_template_versions',
    'postal_template_saved_searches',
    'postal_metrics',
    'postal_stats_daily',
    'postal_stats_history',
    'postal_queue',
    'postal_isps',
    'postal_server_isp_stats',
    'postal_strategies'
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
}

// Supprimer les options
$options = [
    'pw_version',
    'pw_webhook_secret',
    'pw_webhook_strict_mode',
    'pw_enable_logging',
    'pw_log_mode',
    'pw_log_retention_days',
    'pw_max_retries',
    'pw_stats_enabled',
    'pw_stats_retention_days',
    'pw_queue_retention_days',
    'pw_email_notifications',
    'pw_notification_email',
    'pw_daily_report',
    'pw_notify_on_error',
    'pw_daily_limit',
    'pw_rate_limit_per_hour',
    'pw_default_from_name',
    'pw_default_subject',
    'pw_feature_flags',
    'pw_public_stats',
    'pw_global_tag',
    'pw_warmup_settings'
];

foreach ($options as $option) {
    delete_option($option);
}

// Supprimer tous les transients du plugin
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pw_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_pw_%'");

// Supprimer les fichiers de logs
$upload_dir = wp_upload_dir();
$log_dir = $upload_dir['basedir'] . '/postal-warmup-logs';

if (is_dir($log_dir)) {
    $files = glob($log_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($log_dir);
}

// Supprimer les capabilities
$roles = ['administrator', 'editor'];
foreach ($roles as $role_name) {
    $role = get_role($role_name);
    if ($role) {
        $role->remove_cap('manage_postal_warmup');
        $role->remove_cap('view_postal_stats');
        $role->remove_cap('edit_postal_templates');
    }
}

// Supprimer les cron jobs
wp_clear_scheduled_hook('pw_cleanup_old_logs');
wp_clear_scheduled_hook('pw_cleanup_old_stats');
wp_clear_scheduled_hook('pw_daily_report');
wp_clear_scheduled_hook('pw_daily_stats_aggregation');
wp_clear_scheduled_hook('pw_process_queue');
wp_clear_scheduled_hook('pw_warmup_daily_increment');
wp_clear_scheduled_hook('pw_cleanup_queue');
