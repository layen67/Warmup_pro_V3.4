<?php
/**
 * Vue du tableau de bord (Modernized)
 * Implements "Dashboard Principal – Vue d’ensemble" from UI_UX_MOCKUP_PROPOSAL.md
 */

use PostalWarmup\Admin\Settings;
use PostalWarmup\Models\Stats as PW_Stats;
use PostalWarmup\Models\Database as PW_Database;

if (!defined('ABSPATH')) {
    exit;
}

// Retrieve enriched stats
$stats = PW_Stats::get_dashboard_stats();
$servers = PW_Stats::get_servers_stats();

$widgets = Settings::get( 'dashboard_widgets', ['sent', 'success_rate', 'volume', 'active_servers'] );
if ( ! is_array( $widgets ) ) {
    $widgets = [];
}
$refresh_rate = (int) Settings::get( 'dashboard_refresh', 30 );
?>

<div class="wrap pw-dashboard" data-refresh="<?php echo $refresh_rate; ?>">
    <div class="pw-header">
        <h1>
            <span class="dashicons dashicons-chart-area"></span>
            <?php _e('Tableau de Bord', 'postal-warmup'); ?>
        </h1>
        <div class="pw-actions">
             <a href="<?php echo admin_url('admin.php?page=postal-warmup-servers&action=add'); ?>" class="pw-btn pw-btn-primary">
                <span class="dashicons dashicons-plus"></span>
                <?php _e('Ajouter un serveur', 'postal-warmup'); ?>
            </a>
        </div>
    </div>

    <!-- 1. Top Stats Cards -->
    <div class="pw-stats-grid">
        <!-- Total Sent -->
        <?php if ( in_array( 'sent', $widgets ) ): ?>
        <div class="pw-stat-widget">
            <div class="pw-stat-icon primary">
                <span class="dashicons dashicons-email-alt"></span>
            </div>
            <div class="pw-stat-content">
                <span class="pw-stat-label"><?php _e('Total Envoyés', 'postal-warmup'); ?></span>
                <span class="pw-stat-value"><?php echo number_format_i18n($stats['total_sent']); ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Success Rate -->
        <?php if ( in_array( 'success_rate', $widgets ) ): ?>
        <div class="pw-stat-widget">
            <div class="pw-stat-icon <?php echo $stats['success_rate'] >= 90 ? 'success' : ($stats['success_rate'] >= 75 ? 'warning' : 'error'); ?>">
                <span class="dashicons dashicons-yes-alt"></span>
            </div>
            <div class="pw-stat-content">
                <span class="pw-stat-label"><?php _e('Taux de Succès', 'postal-warmup'); ?></span>
                <span class="pw-stat-value"><?php echo $stats['success_rate']; ?>%</span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Today Volume -->
        <?php if ( in_array( 'volume', $widgets ) ): ?>
        <div class="pw-stat-widget">
            <div class="pw-stat-icon info">
                <span class="dashicons dashicons-calendar-alt"></span>
            </div>
            <div class="pw-stat-content">
                <span class="pw-stat-label"><?php _e('Aujourd\'hui', 'postal-warmup'); ?></span>
                <span class="pw-stat-value">
                    <?php echo number_format_i18n($stats['sent_today']); ?>
                    <?php if (isset($stats['evolution']) && $stats['evolution'] != 0): ?>
                        <small style="font-size: 0.6em; color: <?php echo $stats['evolution'] > 0 ? 'var(--pw-success)' : 'var(--pw-danger)'; ?>">
                            <?php echo $stats['evolution'] > 0 ? '+' : ''; ?><?php echo $stats['evolution']; ?>%
                        </small>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Active Servers -->
        <?php if ( in_array( 'active_servers', $widgets ) ): ?>
        <div class="pw-stat-widget">
            <div class="pw-stat-icon warning">
                <span class="dashicons dashicons-networking"></span>
            </div>
            <div class="pw-stat-content">
                <span class="pw-stat-label"><?php _e('Serveurs Actifs', 'postal-warmup'); ?></span>
                <span class="pw-stat-value"><?php echo $stats['active_servers']; ?> / <?php echo $stats['total_servers']; ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- 2. Main Dashboard Table: Servers Overview -->
    <div class="pw-card">
        <div class="pw-card-header">
            <h3><?php _e('État des Serveurs & Quotas', 'postal-warmup'); ?></h3>
            <div class="pw-actions">
                <!-- Filters could go here -->
            </div>
        </div>
        <div class="pw-card-body" style="padding: 0;">
            <div class="pw-table-responsive">
                <table class="pw-table">
                    <thead>
                        <tr>
                            <th><?php _e('Serveur', 'postal-warmup'); ?></th>
                            <th><?php _e('Status', 'postal-warmup'); ?></th>
                            <th style="width: 30%;"><?php _e('Quota Journalier', 'postal-warmup'); ?></th>
                            <th><?php _e('Envoyés (24h)', 'postal-warmup'); ?></th>
                            <th><?php _e('Erreurs (24h)', 'postal-warmup'); ?></th>
                            <th><?php _e('Latence Moy.', 'postal-warmup'); ?></th>
                            <th><?php _e('Actions', 'postal-warmup'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($servers)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 20px; color: var(--pw-text-muted);">
                                    <?php _e('Aucun serveur configuré.', 'postal-warmup'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($servers as $server): 
                                // Data populated by Stats::get_servers_stats()
                                $quota = isset($server['quota']) ? (int)$server['quota'] : 0;
                                $used = isset($server['sent_today']) ? (int)$server['sent_today'] : 0;
                                
                                // If quota is 0 (should not happen with dynamic limit, but just in case), treat as unlimited for display?
                                // Actually get_dynamic_limit returns at least 1 usually.
                                // But if it returns 0, we avoid division by zero.
                                $percentage = $quota > 0 ? min(100, round(($used / $quota) * 100)) : 0;
                                
                                // Color logic
                                $color_class = 'success';
                                if ($percentage >= 90) $color_class = 'danger';
                                elseif ($percentage >= 75) $color_class = 'warning';
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?php echo esc_html($server['domain']); ?></div>
                                    <div style="font-size: 12px; color: var(--pw-text-muted);"><?php echo esc_html($server['ip'] ?? '-'); ?></div>
                                </td>
                                <td>
                                    <?php if ($server['active']): ?>
                                        <span class="pw-badge pw-badge-success"><?php _e('Actif', 'postal-warmup'); ?></span>
                                    <?php else: ?>
                                        <span class="pw-badge pw-badge-danger"><?php _e('Inactif', 'postal-warmup'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!-- Progress Bar -->
                                    <div class="pw-progress-wrapper">
                                        <div class="pw-progress-bar <?php echo $color_class; ?>" style="width: <?php echo $percentage; ?>%;"></div>
                                    </div>
                                    <div class="pw-progress-text">
                                        <span><?php echo $used; ?> / <?php echo $quota > 0 ? $quota : '∞'; ?></span>
                                        <span><?php echo $percentage; ?>%</span>
                                    </div>
                                </td>
                                <td><?php echo number_format_i18n($server['sent_count'] ?? 0); ?></td>
                                <td>
                                    <?php 
                                    $errors = $server['error_count'] ?? 0;
                                    if ($errors > 0): ?>
                                        <span class="pw-badge pw-badge-warning"><?php echo $errors; ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--pw-text-light);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $latency = isset($server['avg_response_time']) ? round($server['avg_response_time'] * 1000) : 0;
                                    echo $latency . ' ms';
                                    ?>
                                </td>
                                <td>
                                    <div class="pw-cell-actions">
                                        <a href="<?php echo admin_url('admin.php?page=postal-warmup-servers&action=edit&id=' . $server['id']); ?>" class="pw-btn pw-btn-secondary pw-btn-sm" title="<?php _e('Configurer', 'postal-warmup'); ?>">
                                            <span class="dashicons dashicons-admin-generic"></span>
                                        </a>
                                        <a href="<?php echo admin_url('admin.php?page=postal-warmup-logs&server_id=' . $server['id']); ?>" class="pw-btn pw-btn-secondary pw-btn-sm" title="<?php _e('Logs', 'postal-warmup'); ?>">
                                            <span class="dashicons dashicons-list-view"></span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="pw-dashboard-grid">
        <!-- Chart Widget -->
        <div class="pw-card">
            <div class="pw-card-header">
                <h3><?php _e('Volume d\'envoi (7 jours)', 'postal-warmup'); ?></h3>
            </div>
            <div class="pw-card-body">
                <canvas id="pw-sends-chart" style="width: 100%; height: 250px;"></canvas>
            </div>
        </div>

        <!-- Thread Stats Widget (New) -->
        <?php
        $threads_enabled = get_option('pw_thread_enabled', false);
        if ($threads_enabled):
            $recent_threads = PW_Stats::get_recent_threads(5);
        ?>
        <div class="pw-card">
            <div class="pw-card-header">
                <h3><?php _e('Threads de Discussion', 'postal-warmup'); ?></h3>
                <span class="pw-badge pw-badge-success" style="margin-left:auto;">Actif</span>
            </div>
            <div class="pw-card-body" style="padding: 0;">
                <?php if (empty($recent_threads)): ?>
                    <div style="padding: 20px; text-align: center; color: var(--pw-text-muted);">
                        <?php _e('Aucun thread actif.', 'postal-warmup'); ?>
                    </div>
                <?php else: ?>
                    <ul class="pw-activity-list">
                    <?php foreach ($recent_threads as $th): ?>
                        <li class="pw-activity-item">
                            <div class="pw-activity-header">
                                <span class="pw-badge info">Échange <?php echo $th['exchange']; ?></span>
                                <span class="pw-activity-time"><?php echo human_time_diff(strtotime($th['time']), current_time('timestamp')); ?></span>
                            </div>
                            <div class="pw-activity-msg">
                                <?php echo esc_html($th['template']); ?>
                                <span style="display:block; font-size:12px; color:#666;">
                                    Vers: <?php echo esc_html($th['email']); ?>
                                </span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Activity / Errors -->
        <div class="pw-card">
            <div class="pw-card-header">
                <h3><?php _e('Activité Récente', 'postal-warmup'); ?></h3>
                <a href="<?php echo admin_url('admin.php?page=postal-warmup-logs'); ?>" class="pw-btn pw-btn-secondary pw-btn-sm"><?php _e('Voir tout', 'postal-warmup'); ?></a>
            </div>
            <div class="pw-card-body" style="padding: 0;">
                <ul class="pw-activity-list" style="max-height: 250px; overflow-y: auto;">
                    <?php 
                    $recent_logs = PW_Database::get_enriched_activity(5);
                    if (empty($recent_logs)): ?>
                        <li class="pw-activity-item" style="padding: 20px; text-align: center; color: var(--pw-text-muted);">
                            <?php _e('Aucune activité récente.', 'postal-warmup'); ?>
                        </li>
                    <?php else: ?>
                        <?php foreach ($recent_logs as $log): 
                            $badge_class = 'pw-badge-neutral';
                            if ($log['level'] === 'error') $badge_class = 'pw-badge-error';
                            elseif ($log['level'] === 'warning') $badge_class = 'pw-badge-warning';
                            elseif ($log['level'] === 'success') $badge_class = 'pw-badge-success';
                        ?>
                        <li class="pw-activity-item" style="padding: 12px 16px;">
                            <div class="pw-activity-header">
                                <span class="pw-badge <?php echo $badge_class; ?>"><?php echo esc_html($log['level']); ?></span>
                                <span class="pw-activity-time"><?php echo human_time_diff(strtotime($log['created_at']), current_time('timestamp')); ?></span>
                            </div>
                            <div class="pw-activity-msg"><?php echo esc_html($log['message']); ?></div>
                            <?php if (!empty($log['server_domain'])): ?>
                                <div class="pw-activity-meta">
                                    <span class="dashicons dashicons-networking" style="font-size: 14px;"></span> <?php echo esc_html($log['server_domain']); ?>
                                </div>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
