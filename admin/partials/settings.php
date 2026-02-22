<?php
/**
 * Vue de la page des paramètres (Modernized Tabbed Interface)
 */

use PostalWarmup\Admin\Settings;

if (!defined('ABSPATH')) {
    exit;
}

$settings_handler = new Settings();
$tabs = $settings_handler->get_tabs_config();
$active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

if (!array_key_exists($active_tab, $tabs)) {
    $active_tab = 'general';
}
?>

<div class="wrap pw-settings-page">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <h2 class="nav-tab-wrapper">
        <?php foreach ($tabs as $tab_id => $tab): ?>
            <a href="?page=postal-warmup-settings&tab=<?php echo esc_attr($tab_id); ?>" class="nav-tab <?php echo $active_tab == $tab_id ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($tab['label']); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <?php settings_errors('postal-warmup-settings'); ?>
    
    <form method="post" action="options.php" class="pw-settings-form">
        <?php
        // Security fields for the registered setting
        settings_fields('postal-warmup-settings');
        
        // Output sections for the current tab
        // The page slug matches what we used in add_settings_section
        do_settings_sections('postal-warmup-settings-' . $active_tab);
        
        // Special logic for Webhook Secret regeneration (only on Security tab)
        if ($active_tab === 'security') {
            ?>
            <script>
            jQuery(document).ready(function($) {
                // Find the webhook secret input by name and append button
                var $secretInput = $('input[name="pw_settings[webhook_secret]"]');
                if ($secretInput.length) {
                    $secretInput.attr('type', 'password'); // Mask by default
                    var $btn = $('<button type="button" class="button button-secondary" id="pw-regenerate-secret" style="margin-left: 10px;">' + 
                        '<?php _e("Régénérer", "postal-warmup"); ?>' + 
                    '</button>');
                    $secretInput.after($btn);
                    
                    $btn.on('click', function() {
                        if(!confirm('<?php _e("Attention: L\'ancienne clé ne fonctionnera plus. Continuer ?", "postal-warmup"); ?>')) return;
                        
                        $.post(ajaxurl, {
                            action: 'pw_regenerate_secret',
                            nonce: '<?php echo wp_create_nonce("pw_admin_nonce"); ?>'
                        }, function(res) {
                            if(res.success) {
                                $secretInput.val(res.data.secret);
                                alert(res.data.message);
                            } else {
                                alert(res.data.message);
                            }
                        });
                    });
                }
            });
            </script>
            <?php
        }
        
        submit_button();
        ?>
    </form>

    <!-- Advanced Tools (Only on General or Advanced tab) -->
    <?php if ($active_tab === 'general' || $active_tab === 'advanced'): ?>
    <div class="pw-form-section" style="margin-top: 40px; border-top: 1px solid #ddd; padding-top: 20px;">
        <h3><?php _e('Outils Avancés', 'postal-warmup'); ?></h3>
        <p>
            <button type="button" class="button" id="pw-export-settings"><?php _e('Exporter les réglages (JSON)', 'postal-warmup'); ?></button>
            <button type="button" class="button" id="pw-import-settings-btn"><?php _e('Importer les réglages', 'postal-warmup'); ?></button>
            <input type="file" id="pw-import-settings-file" style="display:none;" accept=".json">
            <button type="button" class="button button-link-delete" id="pw-reset-settings"><?php _e('Réinitialiser les réglages', 'postal-warmup'); ?></button>
        </p>
        
        <?php if ($active_tab === 'advanced'): ?>
        <p style="margin-top: 20px;">
            <button type="button" class="button button-link-delete" id="pw-purge-data" style="color: #d63638; border-color: #d63638;">
                ⚠️ <?php _e('Purger TOUTES les données (Logs, Queue, Stats)', 'postal-warmup'); ?>
            </button>
        </p>
        <?php endif; ?>
    </div>

    <!-- Sticky Save Bar -->
    <div class="pw-sticky-save" style="display:none; position: fixed; bottom: 0; left: 160px; right: 0; background: #fff; padding: 15px; border-top: 1px solid #ddd; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); z-index: 999; display: flex; align-items: center; justify-content: space-between;">
        <span style="font-weight: 500; color: #333; padding-left: 20px;">⚠️ <?php _e('Vous avez des modifications non enregistrées.', 'postal-warmup'); ?></span>
        <div style="padding-right: 20px;">
            <button type="button" class="button" onclick="location.reload();"><?php _e('Annuler', 'postal-warmup'); ?></button>
            <button type="button" class="button button-primary" onclick="jQuery('.pw-settings-form').submit();"><?php _e('Enregistrer les modifications', 'postal-warmup'); ?></button>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Sticky Save Logic
        var $form = $('.pw-settings-form');
        var $bar = $('.pw-sticky-save');
        var initialData = $form.serialize();

        $form.on('change input', function() {
            if ($form.serialize() !== initialData) {
                $bar.show();
            } else {
                $bar.hide();
            }
        });

        // Export
        $('#pw-export-settings').on('click', function() {
            window.location.href = ajaxurl + '?action=pw_export_settings&nonce=<?php echo wp_create_nonce("pw_admin_nonce"); ?>';
        });

        // Import
        $('#pw-import-settings-btn').on('click', function() {
            $('#pw-import-settings-file').click();
        });

        $('#pw-import-settings-file').on('change', function() {
            var file = this.files[0];
            if (!file) return;
            
            var formData = new FormData();
            formData.append('file', file);
            formData.append('action', 'pw_import_settings');
            formData.append('nonce', '<?php echo wp_create_nonce("pw_admin_nonce"); ?>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(res) {
                    if(res.success) {
                        alert(res.data.message);
                        location.reload();
                    } else {
                        alert(res.data.message);
                    }
                }
            });
        });

        // Reset
        $('#pw-reset-settings').on('click', function() {
            if(!confirm('Voulez-vous vraiment réinitialiser tous les réglages par défaut ?')) return;
            
            $.post(ajaxurl, {
                action: 'pw_reset_settings',
                nonce: '<?php echo wp_create_nonce("pw_admin_nonce"); ?>'
            }, function(res) {
                if(res.success) {
                    alert(res.data.message);
                    location.reload();
                } else {
                    alert(res.data.message);
                }
            });
        });

        // Purge Data
        $('#pw-purge-data').on('click', function() {
            if(!confirm('ATTENTION: Cela va supprimer TOUS les logs, la file d\'attente et les statistiques. Cette action est irréversible. Continuer ?')) return;
            
            $.post(ajaxurl, {
                action: 'pw_purge_all_data',
                nonce: '<?php echo wp_create_nonce("pw_admin_nonce"); ?>'
            }, function(res) {
                alert(res.data.message);
                location.reload();
            });
        });
    });
    </script>
    <?php endif; ?>
    
    <!-- System Info (Only on Advanced tab or General?) Let's put it on Advanced or keep it visible at bottom? -->
    <?php if ($active_tab === 'advanced' || $active_tab === 'general'): ?>
    <div class="pw-form-section" style="margin-top: 40px; border-top: 1px solid #ddd; padding-top: 20px;">
        <h3><?php _e('Informations Système', 'postal-warmup'); ?></h3>
        <table class="form-table" style="max-width: 600px;">
            <tr>
                <th scope="row"><?php _e('Version du plugin', 'postal-warmup'); ?></th>
                <td><code><?php echo PW_VERSION; ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('PHP', 'postal-warmup'); ?></th>
                <td><?php echo PHP_VERSION; ?></td>
            </tr>
        </table>
    </div>
    <?php endif; ?>
</div>
