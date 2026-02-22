<?php
/**
 * Vue de la file d'attente - Modernized
 * Implements "Queue & Warmup Dashboard" from UI_UX_MOCKUP_PROPOSAL.md
 */

if (!defined('ABSPATH')) exit;

use PostalWarmup\Models\Database;
use PostalWarmup\Services\TemplateLoader;

// Pagination
$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

global $wpdb;
$table = $wpdb->prefix . 'postal_queue';
$table_tpl = $wpdb->prefix . 'postal_templates';

$total = $wpdb->get_var("SELECT COUNT(*) FROM $table");

// Note: Strategy table might not be joined if column doesn't exist in queue table yet (v3.2 feature)
// Assuming strategy_id is present as per recent updates
$items = $wpdb->get_results($wpdb->prepare(
    "SELECT q.*, t.name as template_name
     FROM $table q 
     LEFT JOIN $table_tpl t ON q.template_id = t.id 
     ORDER BY q.scheduled_at DESC 
     LIMIT %d OFFSET %d", 
    $per_page, $offset
), ARRAY_A);

$total_pages = ceil($total / $per_page);
$health = \PostalWarmup\Services\QueueManager::get_health_stats();
$next_batch = \PostalWarmup\Services\QueueManager::get_next_batch_info();
?>

<div class="wrap pw-page-wrapper">
    <div class="pw-header">
        <h1>
            <span class="dashicons dashicons-hourglass"></span>
            <?php _e('Queue & Warmup Dashboard', 'postal-warmup'); ?>
        </h1>
        <div class="pw-actions">
            <button type="button" class="pw-btn pw-btn-primary" id="pw-process-queue-btn">
                <span class="dashicons dashicons-controls-play"></span>
                <?php _e('Forcer l\'envoi (Cron)', 'postal-warmup'); ?>
            </button>
        </div>
    </div>

    <!-- 1. Health Monitor Cards -->
    <div class="pw-stats-grid">
        <div class="pw-stat-widget">
            <div class="pw-stat-icon warning">
                <span class="dashicons dashicons-clock"></span>
            </div>
            <div class="pw-stat-content">
                <span class="pw-stat-label"><?php _e('En Attente', 'postal-warmup'); ?></span>
                <span class="pw-stat-value"><?php echo $health['pending']; ?></span>
            </div>
        </div>

        <div class="pw-stat-widget">
            <div class="pw-stat-icon info">
                <span class="dashicons dashicons-update"></span>
            </div>
            <div class="pw-stat-content">
                <span class="pw-stat-label"><?php _e('En Cours', 'postal-warmup'); ?></span>
                <span class="pw-stat-value"><?php echo $health['processing']; ?></span>
            </div>
        </div>

        <div class="pw-stat-widget">
            <div class="pw-stat-icon success">
                <span class="dashicons dashicons-yes"></span>
            </div>
            <div class="pw-stat-content">
                <span class="pw-stat-label"><?php _e('Envoyés (24h)', 'postal-warmup'); ?></span>
                <span class="pw-stat-value"><?php echo $health['sent_24h']; ?></span>
            </div>
        </div>

        <div class="pw-stat-widget">
            <div class="pw-stat-icon error">
                <span class="dashicons dashicons-warning"></span>
            </div>
            <div class="pw-stat-content">
                <span class="pw-stat-label"><?php _e('Échecs (24h)', 'postal-warmup'); ?></span>
                <span class="pw-stat-value"><?php echo $health['failed_24h']; ?></span>
            </div>
        </div>
    </div>

    <!-- 2. Next Batch Info -->
    <?php if ($next_batch): 
        $is_now = $next_batch['is_now'];
        $time_str = $is_now ? __('Immédiatement', 'postal-warmup') : date_i18n(get_option('time_format'), $next_batch['timestamp']);
        $count = $next_batch['count'];
    ?>
    <div class="pw-card" style="border-left: 4px solid var(--pw-primary);">
        <div class="pw-card-body" style="display: flex; align-items: center; gap: 16px; padding: 16px 24px;">
            <span class="dashicons dashicons-info" style="color: var(--pw-primary); font-size: 24px;"></span>
            <div>
                <strong><?php _e('Prochaine Vague :', 'postal-warmup'); ?></strong>
                <span class="pw-text-primary" style="font-weight: 700; font-size: 1.1em; color: var(--pw-primary);">
                    <?php echo $count; ?>
                </span> 
                <?php _e('emails prévus', 'postal-warmup'); ?> 
                <strong><?php echo $time_str; ?></strong>.
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 3. Queue List -->
    <div class="pw-card">
        <div class="pw-card-header">
            <h3><?php _e('File d\'attente', 'postal-warmup'); ?></h3>
            <div class="pw-pagination">
                <?php if ($total_pages > 1): ?>
                    <span class="description"><?php echo $page; ?> / <?php echo $total_pages; ?></span>
                    <div class="pw-btn-group">
                        <?php if ($page > 1): ?>
                            <a class="pw-btn pw-btn-secondary pw-btn-sm" href="?page=postal-warmup-queue&paged=<?php echo $page - 1; ?>">‹</a>
                        <?php endif; ?>
                        <?php if ($page < $total_pages): ?>
                            <a class="pw-btn pw-btn-secondary pw-btn-sm" href="?page=postal-warmup-queue&paged=<?php echo $page + 1; ?>">›</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="pw-card-body" style="padding: 0;">
            <div class="pw-table-responsive">
                <table class="pw-table">
                    <thead>
                        <tr>
                            <th width="60">ID</th>
                            <th><?php _e('Template / Stratégie', 'postal-warmup'); ?></th>
                            <th><?php _e('Serveur', 'postal-warmup'); ?></th>
                            <th><?php _e('Destinataire / ISP', 'postal-warmup'); ?></th>
                            <th><?php _e('Sujet', 'postal-warmup'); ?></th>
                            <th><?php _e('Statut', 'postal-warmup'); ?></th>
                            <th><?php _e('Prévu pour', 'postal-warmup'); ?></th>
                            <th><?php _e('Essais', 'postal-warmup'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 32px; color: var(--pw-text-muted);">
                                    <?php _e('File d\'attente vide.', 'postal-warmup'); ?>
                                </td>
                            </tr>
                        <?php else: foreach ($items as $item): 
                            $server = Database::get_server($item['server_id']);
                            $server_name = $server ? esc_html($server['domain']) : '<span class="pw-badge pw-badge-neutral">Auto</span>';
                            
                            $template_name = !empty($item['template_name']) ? esc_html($item['template_name']) : '<em>Système</em>';
                            $isp = !empty($item['isp']) ? esc_html($item['isp']) : 'Other';
                            
                            $status_badge = 'pw-badge-neutral';
                            $status_label = ucfirst($item['status']);
                            
                            switch($item['status']) {
                                case 'pending': $status_badge = 'pw-badge-neutral'; $status_label = __('En attente', 'postal-warmup'); break;
                                case 'processing': $status_badge = 'pw-badge-warning'; $status_label = __('En cours', 'postal-warmup'); break;
                                case 'sent': $status_badge = 'pw-badge-success'; $status_label = __('Envoyé', 'postal-warmup'); break;
                                case 'failed': $status_badge = 'pw-badge-error'; $status_label = __('Échoué', 'postal-warmup'); break;
                            }
                            
                            $scheduled_ts = strtotime($item['scheduled_at']);
                            $time_diff = human_time_diff($scheduled_ts);
                            $time_display = ($scheduled_ts > time()) ? "Dans $time_diff" : "Il y a $time_diff";
                        ?>
                        <tr>
                            <td>#<?php echo $item['id']; ?></td>
                            <td>
                                <strong><?php echo $template_name; ?></strong>
                                <?php if (!empty($item['warmup_day'])): ?>
                                    <div style="font-size: 11px; color: var(--pw-primary);">J<?php echo $item['warmup_day']; ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $server_name; ?></td>
                            <td>
                                <div><?php echo esc_html($item['to_email']); ?></div>
                                <span class="pw-badge pw-badge-info" style="font-size: 9px; padding: 1px 5px;"><?php echo $isp; ?></span>
                            </td>
                            <td><?php echo esc_html(mb_strimwidth($item['subject'], 0, 30, '...')); ?></td>
                            <td>
                                <span class="pw-badge <?php echo $status_badge; ?>"><?php echo $status_label; ?></span>
                            </td>
                            <td>
                                <div style="font-size: 12px;"><?php echo date_i18n('H:i', $scheduled_ts); ?></div>
                                <div style="font-size: 10px; color: var(--pw-text-muted);"><?php echo $time_display; ?></div>
                            </td>
                            <td><?php echo $item['attempts']; ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#pw-process-queue-btn').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var originalText = btn.html();
        
        if (btn.hasClass('disabled')) return;
        
        btn.addClass('disabled').html('<span class="dashicons dashicons-update spin"></span> Traitement...');
        
        $.post(ajaxurl, {
            action: 'pw_process_queue_manual',
            nonce: '<?php echo wp_create_nonce("pw_admin_nonce"); ?>'
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Erreur: ' + (response.data.message || 'Inconnue'));
                btn.removeClass('disabled').html(originalText);
            }
        }).fail(function() {
            alert('Erreur réseau');
            btn.removeClass('disabled').html(originalText);
        });
    });
});
</script>
