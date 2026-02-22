<?php
/**
 * Vue : Gestion des FAI (ISP) - Modernized
 * Implements "ISP Manager – Gestion des ISPs" from UI_UX_MOCKUP_PROPOSAL.md
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use PostalWarmup\Admin\ISPManager;
use PostalWarmup\Models\Strategy;
use PostalWarmup\Models\Stats;
use PostalWarmup\Models\Database;

$isps = ISPManager::get_all();
$servers = Database::get_servers(true);
$strategies = Strategy::get_all();
?>

<div class="wrap pw-page-wrapper">
    <div class="pw-header">
        <h1>
            <span class="dashicons dashicons-groups"></span>
            <?php _e('Gestion des Profils ISP', 'postal-warmup'); ?>
        </h1>
        <button type="button" class="pw-btn pw-btn-primary" id="pw-add-isp-btn">
            <span class="dashicons dashicons-plus"></span>
            <?php _e('Nouveau Profil', 'postal-warmup'); ?>
        </button>
    </div>

    <div class="pw-card">
        <div class="pw-card-body" style="padding: 0;">
            <div class="pw-table-responsive">
                <table class="pw-table">
                    <thead>
                        <tr>
                            <th><?php _e('Nom du Profil', 'postal-warmup'); ?></th>
                            <th><?php _e('Domaines Associés', 'postal-warmup'); ?></th>
                            <th><?php _e('Stratégie', 'postal-warmup'); ?></th>
                            <th><?php _e('Statut', 'postal-warmup'); ?></th>
                            <th><?php _e('Détails par Serveur (Jauge)', 'postal-warmup'); ?></th>
                            <th><?php _e('Actions', 'postal-warmup'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="pw-isp-list">
                        <?php if ( empty( $isps ) ): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 32px; color: var(--pw-text-muted);">
                                    <?php _e('Aucun profil ISP configuré.', 'postal-warmup'); ?>
                                </td>
                            </tr>
                        <?php else: foreach ( $isps as $isp ): 
                            $domains_list = is_array($isp['domains']) ? implode(', ', $isp['domains']) : $isp['domains'];
                            if (strlen($domains_list) > 60) $domains_list = substr($domains_list, 0, 60) . '...';
                            
                            // Determine Strategy Badge Color
                            $strategy_badge_class = 'pw-badge-neutral';
                            $strategy_name = $isp['strategy_name'] ?? 'Aucune';
                            
                            if (stripos($strategy_name, 'agressive') !== false) $strategy_badge_class = 'pw-badge-warning';
                            elseif (stripos($strategy_name, 'douce') !== false || stripos($strategy_name, 'conservative') !== false) $strategy_badge_class = 'pw-badge-success';
                            elseif (!empty($isp['strategy_id'])) $strategy_badge_class = 'pw-badge-info';
                        ?>
                            <tr data-id="<?php echo esc_attr($isp['id']); ?>" data-json="<?php echo esc_attr(json_encode($isp)); ?>">
                                <td>
                                    <div style="font-weight: 600;"><?php echo esc_html($isp['isp_label']); ?></div>
                                    <div style="font-size: 11px; color: var(--pw-text-muted);"><?php echo esc_html($isp['isp_key']); ?></div>
                                </td>
                                <td>
                                    <div style="font-size: 13px; color: var(--pw-text-body); max-width: 300px; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo esc_html($domains_list); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="pw-badge <?php echo $strategy_badge_class; ?>">
                                        <?php echo esc_html($strategy_name); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isp['active']): ?>
                                        <span class="pw-badge pw-badge-success"><?php _e('Actif', 'postal-warmup'); ?></span>
                                    <?php else: ?>
                                        <span class="pw-badge pw-badge-error"><?php _e('Inactif', 'postal-warmup'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="pw-isp-server-grid">
                                    <?php 
                                    if ($servers) {
                                        foreach ($servers as $srv) {
                                            $stats = Stats::get_server_isp_stats($srv['id'], $isp['isp_key']);
                                            
                                            // Fix critical error: Handle both object and array return types
                                            $warmup_day = is_object($stats) ? ($stats->warmup_day ?? 1) : ($stats['warmup_day'] ?? 1);
                                            $sent_today = is_object($stats) ? ($stats->sent_today ?? 0) : ($stats['sent_today'] ?? 0);
                                            
                                            $limit = 0;
                                            
                                            // Calculate actual limit based on strategy
                                            if (!empty($isp['strategy_id'])) {
                                                $strategy = Strategy::get($isp['strategy_id']);
                                                if ($strategy) {
                                                    $limit = \PostalWarmup\Services\StrategyEngine::calculate_daily_limit($strategy, $warmup_day, $isp['isp_key']);
                                                }
                                            }
                                            
                                            $pct = ($limit > 0) ? min(100, round(($sent_today / $limit) * 100)) : 0;
                                            $bar_color = ($pct >= 90) ? 'danger' : (($pct >= 70) ? 'warning' : 'success');
                                            
                                            echo '<div class="pw-isp-server-item">';
                                            echo '<div class="server-name">' . esc_html($srv['domain']) . '</div>';
                                            echo '<div class="server-progress">';
                                            echo '<div class="pw-progress-wrapper" style="height: 4px;">';
                                            echo '<div class="pw-progress-bar ' . $bar_color . '" style="width: ' . $pct . '%;"></div>';
                                            echo '</div>';
                                            echo '</div>';
                                            echo '<div class="server-meta">J' . $warmup_day . ' • ' . $sent_today . '/' . ($limit ?: '∞') . '</div>';
                                            echo '</div>';
                                        }
                                    }
                                    ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="pw-cell-actions">
                                        <button type="button" class="pw-btn pw-btn-secondary pw-btn-sm pw-edit-isp" title="<?php _e('Modifier', 'postal-warmup'); ?>">
                                            <span class="dashicons dashicons-edit"></span>
                                        </button>
                                        <button type="button" class="pw-btn pw-btn-danger pw-btn-sm pw-delete-isp" title="<?php _e('Supprimer', 'postal-warmup'); ?>">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.pw-isp-server-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.pw-isp-server-item {
    background: var(--pw-surface-alt);
    border: 1px solid var(--pw-border);
    border-radius: 4px;
    padding: 6px 8px;
    width: 140px;
}
.server-name {
    font-size: 11px;
    font-weight: 600;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.server-progress { margin-bottom: 4px; }
.server-meta {
    font-size: 10px;
    color: var(--pw-text-muted);
}
</style>

<!-- Modal: ISP Editor -->
<div id="pw-isp-modal" class="pw-modal" style="display:none;">
    <div class="pw-modal-content">
        <div class="pw-modal-header">
            <h3 id="pw-isp-modal-title"><?php _e('Configurer un Profil ISP', 'postal-warmup'); ?></h3>
            <button class="pw-modal-close">&times;</button>
        </div>
        <div class="pw-modal-body">
            <form id="pw-isp-form">
                <input type="hidden" name="id" id="pw-isp-id">
                
                <div class="pw-form-group">
                    <label for="pw-isp-label"><?php _e('Nom du Profil (Label)', 'postal-warmup'); ?> *</label>
                    <input type="text" name="isp_label" id="pw-isp-label" required placeholder="Ex: Gmail Corporate">
                </div>

                <div class="pw-form-group">
                    <label for="pw-isp-domains"><?php _e('Domaines associés', 'postal-warmup'); ?></label>
                    <textarea name="domains" id="pw-isp-domains" rows="3" placeholder="gmail.com, googlemail.com"></textarea>
                    <p class="description"><?php _e('Séparez les domaines par des virgules.', 'postal-warmup'); ?></p>
                </div>

                <!-- Obsolete fields hidden but preserved for DB compatibility -->
                <input type="hidden" name="max_daily" id="pw-isp-daily" value="0">
                <input type="hidden" name="max_hourly" id="pw-isp-hourly" value="0">

                <div class="pw-form-group">
                    <label for="pw-isp-strategy-id"><?php _e('Stratégie de Warmup', 'postal-warmup'); ?></label>
                    <select name="strategy_id" id="pw-isp-strategy-id">
                        <option value="">-- <?php _e('Aucune (Manuel)', 'postal-warmup'); ?> --</option>
                        <?php foreach ( $strategies as $s ): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo esc_html($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pw-form-group">
                    <label>
                        <input type="checkbox" name="active" id="pw-isp-active" value="1" checked> 
                        <?php _e('Activer ce profil', 'postal-warmup'); ?>
                    </label>
                </div>
            </form>
        </div>
        <div class="pw-modal-footer">
            <button type="button" class="pw-btn pw-btn-secondary pw-modal-close"><?php _e('Annuler', 'postal-warmup'); ?></button>
            <button type="button" class="pw-btn pw-btn-primary" id="pw-save-isp"><?php _e('Enregistrer', 'postal-warmup'); ?></button>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Open Modal Add
    $(document).on('click', '#pw-add-isp-btn', function(e) {
        e.preventDefault();
        $('#pw-isp-form')[0].reset();
        $('#pw-isp-id').val('');
        $('#pw-isp-modal-title').text('<?php _e('Ajouter un Profil ISP', 'postal-warmup'); ?>');
        $('#pw-isp-modal').css('display', 'flex').hide().fadeIn(200);
    });

    // Open Modal Edit
    $('.pw-edit-isp').on('click', function() {
        var tr = $(this).closest('tr');
        var data = tr.data('json');
        
        $('#pw-isp-id').val(data.id);
        $('#pw-isp-label').val(data.isp_label);
        $('#pw-isp-domains').val(Array.isArray(data.domains) ? data.domains.join(', ') : data.domains);
        $('#pw-isp-strategy-id').val(data.strategy_id);
        $('#pw-isp-active').prop('checked', data.active == 1);
        
        $('#pw-isp-modal-title').text('<?php _e('Modifier Profil', 'postal-warmup'); ?>: ' + data.isp_label);
        $('#pw-isp-modal').fadeIn(200);
    });

    // Close Modal
    $('.pw-modal-close').on('click', function() {
        $('#pw-isp-modal').fadeOut(200);
    });

    // Save
    $('#pw-save-isp').on('click', function() {
        var btn = $(this);
        var originalText = btn.html();
        btn.prop('disabled', true).text('Sauvegarde...');
        
        var formData = $('#pw-isp-form').serialize();
        // pwAdmin is globally available
        formData += '&action=pw_save_isp&nonce=' + pwAdmin.nonce;

        $.post(pwAdmin.ajaxurl, formData, function(res) {
            btn.prop('disabled', false).html(originalText);
            if(res.success) {
                location.reload();
            } else {
                alert('Erreur: ' + (res.data.message || 'Inconnue'));
            }
        });
    });

    // Delete
    $('.pw-delete-isp').on('click', function() {
        if(!confirm('<?php _e('Supprimer ce profil ISP ?', 'postal-warmup'); ?>')) return;
        var id = $(this).closest('tr').data('id');
        $.post(pwAdmin.ajaxurl, {
            action: 'pw_delete_isp',
            nonce: pwAdmin.nonce,
            id: id
        }, function(res) {
            if(res.success) location.reload();
            else alert('Erreur');
        });
    });
});
</script>
