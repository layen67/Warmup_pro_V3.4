<?php
/**
 * Vue de gestion des serveurs - Modernized
 * Implements "Serveur Postal – Gestion Simplifiée" from UI_UX_MOCKUP_PROPOSAL.md
 */

if (!defined('ABSPATH')) {
    exit;
}

$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
$server_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Handle Form Submissions (Add/Edit/Delete)
// (Logic remains similar to original but wrapped in modern containers)
// ... [Retaining existing PHP logic for handling POST requests] ...
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_admin_referer('pw_server_action');
    
    if (isset($_POST['pw_add_server'])) {
        $domain = sanitize_text_field($_POST['domain']);
        $api_url = esc_url_raw($_POST['api_url']);
        $api_key = sanitize_text_field($_POST['api_key']);
        $active = !empty($_POST['active']) ? 1 : 0;
        $timezone = sanitize_text_field($_POST['timezone']);
        
        // Quota is now optional/hidden or handled by strategy, but we keep DB field for compatibility
        $daily_limit = 0; 
        
        if ($domain && $api_url && $api_key) {
            $result = PW_Database::insert_server([
                'domain' => $domain,
                'api_url' => $api_url,
                'api_key' => $api_key,
                'active' => $active,
                'daily_limit' => $daily_limit,
                'timezone' => $timezone
            ]);
            
            if ($result) {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Serveur ajouté avec succès.', 'postal-warmup') . '</p></div>';
                $action = '';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . __('Erreur ajout.', 'postal-warmup') . '</p></div>';
            }
        }
    }
    
    if (isset($_POST['pw_edit_server']) && $server_id) {
        $domain = sanitize_text_field($_POST['domain']);
        $api_url = esc_url_raw($_POST['api_url']);
        $active = !empty($_POST['active']) ? 1 : 0;
        $timezone = sanitize_text_field($_POST['timezone']);
        
        $data = [
            'domain' => $domain,
            'api_url' => $api_url,
            'active' => $active,
            'timezone' => $timezone
        ];
        
        if (!empty($_POST['api_key_modified']) && $_POST['api_key_modified'] === '1') {
            $new_api_key = sanitize_text_field($_POST['api_key_new']);
            if (!empty($new_api_key)) {
                $data['api_key'] = $new_api_key;
            }
        }
        
        if ($domain && $api_url) {
            PW_Database::update_server($server_id, $data);
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Serveur mis à jour.', 'postal-warmup') . '</p></div>';
            $action = '';
        }
    }
}

if ($action === 'delete' && $server_id) {
    check_admin_referer('pw_delete_server_' . $server_id);
    PW_Database::delete_server($server_id);
    echo '<div class="notice notice-success is-dismissible"><p>' . __('Serveur supprimé.', 'postal-warmup') . '</p></div>';
    $action = '';
}
?>

<div class="wrap pw-page-wrapper">
    <div class="pw-header">
        <h1>
            <span class="dashicons dashicons-networking"></span>
            <?php _e('Gestion des Serveurs Postal', 'postal-warmup'); ?>
        </h1>
        <?php if ($action !== 'add' && $action !== 'edit'): ?>
            <a href="<?php echo admin_url('admin.php?page=postal-warmup-servers&action=add'); ?>" class="pw-btn pw-btn-primary">
                <span class="dashicons dashicons-plus"></span>
                <?php _e('Ajouter un serveur', 'postal-warmup'); ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($action === 'add' || ($action === 'edit' && $server_id)): 
        $is_edit = ($action === 'edit');
        $server = $is_edit ? PW_Database::get_server($server_id) : ['domain'=>'', 'api_url'=>'', 'active'=>1, 'timezone'=>'UTC'];
    ?>
        <div class="pw-card" style="max-width: 800px;">
            <div class="pw-card-header">
                <h3><?php echo $is_edit ? __('Modifier le Serveur', 'postal-warmup') : __('Nouveau Serveur', 'postal-warmup'); ?></h3>
            </div>
            <div class="pw-card-body">
                <form method="post" id="pw-server-form">
                    <?php wp_nonce_field('pw_server_action'); ?>
                    <input type="hidden" name="api_key_modified" id="api_key_modified" value="0">
                    <input type="hidden" name="api_key_new" id="api_key_new" value="">

                    <div class="pw-form-group">
                        <label for="domain"><?php _e('Domaine', 'postal-warmup'); ?> *</label>
                        <input type="text" id="domain" name="domain" required placeholder="mail.example.com" value="<?php echo esc_attr($server['domain']); ?>">
                        <p class="description"><?php _e('Le domaine configuré dans Postal.', 'postal-warmup'); ?></p>
                    </div>

                    <div class="pw-form-group">
                        <label for="api_url"><?php _e('API URL', 'postal-warmup'); ?> *</label>
                        <input type="url" id="api_url" name="api_url" required placeholder="https://postal.example.com/api/v1" value="<?php echo esc_attr($server['api_url']); ?>">
                    </div>

                    <div class="pw-form-group">
                        <label><?php _e('API Key', 'postal-warmup'); ?> <?php echo $is_edit ? '' : '*'; ?></label>
                        <?php if ($is_edit): ?>
                            <div class="pw-api-key-secure" style="display: flex; gap: 10px; align-items: center;">
                                <input type="password" value="••••••••••••••••••••••••" readonly disabled style="background: var(--pw-surface-alt); width: auto; flex: 1;">
                                <button type="button" class="pw-btn pw-btn-secondary" id="pw-change-api-key-btn">
                                    <span class="dashicons dashicons-edit"></span> <?php _e('Modifier', 'postal-warmup'); ?>
                                </button>
                            </div>
                        <?php else: ?>
                            <input type="text" id="api_key" name="api_key" required placeholder="server-xxx...">
                        <?php endif; ?>
                    </div>

                    <div class="pw-form-group">
                        <label for="timezone"><?php _e('Fuseau Horaire', 'postal-warmup'); ?></label>
                        <select id="timezone" name="timezone">
                            <option value="UTC" <?php selected($server['timezone'], 'UTC'); ?>>UTC</option>
                            <?php foreach (timezone_identifiers_list() as $tz) { 
                                echo '<option value="' . esc_attr($tz) . '" ' . selected($server['timezone'], $tz, false) . '>' . esc_html($tz) . '</option>';
                            } ?>
                        </select>
                    </div>

                    <div class="pw-form-group">
                        <label>
                            <input type="checkbox" name="active" value="1" <?php checked($server['active'], 1); ?>>
                            <?php _e('Serveur Actif', 'postal-warmup'); ?>
                        </label>
                    </div>

                    <div class="pw-actions" style="margin-top: 24px;">
                        <button type="submit" name="<?php echo $is_edit ? 'pw_edit_server' : 'pw_add_server'; ?>" class="pw-btn pw-btn-primary">
                            <?php _e('Enregistrer', 'postal-warmup'); ?>
                        </button>
                        <a href="<?php echo admin_url('admin.php?page=postal-warmup-servers'); ?>" class="pw-btn pw-btn-secondary">
                            <?php _e('Annuler', 'postal-warmup'); ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>

    <?php else: ?>
        <!-- Servers List -->
        <?php $servers = PW_Database::get_servers(); ?>
        
        <div class="pw-card">
            <div class="pw-card-body" style="padding: 0;">
                <div class="pw-table-responsive">
                    <table class="pw-table">
                        <thead>
                            <tr>
                                <th><?php _e('Domaine', 'postal-warmup'); ?></th>
                                <th><?php _e('API', 'postal-warmup'); ?></th>
                                <th><?php _e('Santé (Health Score)', 'postal-warmup'); ?></th>
                                <th><?php _e('Statut', 'postal-warmup'); ?></th>
                                <th><?php _e('Actions', 'postal-warmup'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($servers)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 32px; color: var(--pw-text-muted);">
                                        <?php _e('Aucun serveur. Ajoutez-en un pour commencer.', 'postal-warmup'); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($servers as $server): 
                                    $health = \PostalWarmup\Services\HealthScoreCalculator::calculate_score($server['id']);
                                    $health_color = ($health >= 80) ? 'success' : (($health >= 50) ? 'warning' : 'error');
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; font-size: 15px;"><?php echo esc_html($server['domain']); ?></div>
                                        <div style="font-size: 12px; color: var(--pw-text-muted);"><?php echo esc_html($server['timezone']); ?></div>
                                    </td>
                                    <td>
                                        <code style="font-size: 11px; color: var(--pw-text-muted);"><?php echo esc_html(parse_url($server['api_url'], PHP_URL_HOST)); ?></code>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div class="pw-progress-wrapper" style="width: 80px;">
                                                <div class="pw-progress-bar <?php echo $health_color; ?>" style="width: <?php echo $health; ?>%;"></div>
                                            </div>
                                            <span style="font-weight: 600; font-size: 12px;"><?php echo $health; ?>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($server['active']): ?>
                                            <span class="pw-badge pw-badge-success"><?php _e('Actif', 'postal-warmup'); ?></span>
                                        <?php else: ?>
                                            <span class="pw-badge pw-badge-error"><?php _e('Désactivé', 'postal-warmup'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="pw-cell-actions">
                                            <a href="<?php echo admin_url('admin.php?page=postal-warmup-servers&action=edit&id=' . $server['id']); ?>" class="pw-btn pw-btn-secondary pw-btn-sm">
                                                <span class="dashicons dashicons-edit"></span>
                                            </a>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=postal-warmup-servers&action=delete&id=' . $server['id']), 'pw_delete_server_' . $server['id']); ?>" 
                                               class="pw-btn pw-btn-danger pw-btn-sm" 
                                               onclick="return confirm('<?php _e('Supprimer ce serveur ?', 'postal-warmup'); ?>');">
                                                <span class="dashicons dashicons-trash"></span>
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
    <?php endif; ?>
</div>

<!-- Modal Logic for API Key Change (Hidden by default, triggered by JS) -->
<div id="pw-api-key-modal" class="pw-modal" style="display: none;">
    <div class="pw-modal-content">
        <div class="pw-modal-header">
            <h3><?php _e('Changer la clé API', 'postal-warmup'); ?></h3>
            <button class="pw-modal-close">&times;</button>
        </div>
        <div class="pw-modal-body">
            <p><?php _e('Entrez la nouvelle clé API. L\'ancienne sera écrasée.', 'postal-warmup'); ?></p>
            <input type="text" id="pw-new-api-key-input" class="regular-text" placeholder="server-..." style="width: 100%;">
        </div>
        <div class="pw-modal-footer">
            <button type="button" class="pw-btn pw-btn-secondary pw-modal-close"><?php _e('Annuler', 'postal-warmup'); ?></button>
            <button type="button" class="pw-btn pw-btn-primary" id="pw-confirm-api-key"><?php _e('Confirmer', 'postal-warmup'); ?></button>
        </div>
    </div>
</div>
