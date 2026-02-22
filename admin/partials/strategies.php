<?php
if ( ! defined( 'ABSPATH' ) ) exit;
use PostalWarmup\Models\Strategy;
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Stratégies de Warmup</h1>
    <button class="page-title-action" id="pw-add-strategy-btn">Créer une Stratégie</button>
    <hr class="wp-header-end">

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Nom</th>
                <th>Type de Croissance</th>
                <th>Volume Départ</th>
                <th>Volume Max</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="pw-strategy-list">
            <?php 
            $strategies = Strategy::get_all();
            if ( empty( $strategies ) ): ?>
                <tr><td colspan="5">Aucune stratégie configurée.</td></tr>
            <?php else: foreach ( $strategies as $s ): 
                $conf = $s['config'];
            ?>
                <tr data-id="<?php echo esc_attr($s['id']); ?>" data-json="<?php echo esc_attr(json_encode($s)); ?>">
                    <td><strong><?php echo esc_html($s['name']); ?></strong><br><small><?php echo esc_html($s['description']); ?></small></td>
                    <td><?php echo esc_html(ucfirst($conf['growth_type'])); ?> (<?php echo $conf['growth_value']; ?>)</td>
                    <td><?php echo esc_html($conf['start_volume']); ?></td>
                    <td><?php echo esc_html($conf['max_volume']); ?></td>
                    <td>
                        <button class="button pw-edit-strategy">Éditer</button>
                        <button class="button pw-delete-strategy" style="color: #b32d2e; border-color: #b32d2e;">Supprimer</button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal Stratégie -->
<div id="pw-strategy-modal" class="pw-modal" style="display:none;">
    <div class="pw-modal-content pw-modal-lg" style="width: 800px;">
        <div class="pw-modal-header">
            <h2 id="pw-strategy-modal-title">Configurer la Stratégie</h2>
            <button class="pw-modal-close">&times;</button>
        </div>
        <div class="pw-modal-body" style="display:flex; gap:20px;">
            <div style="flex:1;">
                <form id="pw-strategy-form">
                    <input type="hidden" name="id" id="pw-st-id">
                    
                    <div class="pw-form-group">
                        <label>Nom de la Stratégie</label>
                        <input type="text" name="name" id="pw-st-name" class="widefat" required>
                    </div>
                    
                    <div class="pw-form-group">
                        <label>Description</label>
                        <textarea name="description" id="pw-st-desc" class="widefat" rows="2"></textarea>
                    </div>

                    <div style="display:flex; gap:10px;">
                        <div style="flex:1;">
                            <label>Volume Départ</label>
                            <input type="number" name="start_volume" id="pw-st-start" class="widefat" value="10">
                        </div>
                        <div style="flex:1;">
                            <label>Volume Max</label>
                            <input type="number" name="max_volume" id="pw-st-max" class="widefat" value="5000">
                        </div>
                    </div>

                    <div class="pw-form-group" style="margin-top:10px;">
                        <label>Type de Croissance</label>
                        <select name="growth_type" id="pw-st-type" class="widefat">
                            <option value="linear">Linéaire (+X par jour)</option>
                            <option value="exponential">Exponentielle (+X % par jour)</option>
                            <option value="mixed">Mixte (Optimisé J1-J20)</option>
                        </select>
                    </div>

                    <div class="pw-form-group">
                        <label>Valeur de Croissance (X)</label>
                        <input type="number" step="0.1" name="growth_value" id="pw-st-value" class="widefat" value="10">
                        <p class="description">Ex: 10 pour +10 emails, ou 30 pour +30%</p>
                    </div>
                    
                    <h3 style="margin-top:20px; border-bottom:1px solid #eee;">Sécurité (Automatique)</h3>
                    <div class="pw-form-group">
                        <label>Arrêt si Hard Bounce > (%)</label>
                        <input type="number" step="0.1" name="safety_max_hard_bounce" id="pw-st-bounce" class="widefat" value="2.0">
                    </div>
                </form>
            </div>
            
            <div style="flex:1; background:#f9f9f9; padding:15px; border-radius:5px;">
                <h3>Prévisualisation</h3>
                <canvas id="pw-strategy-chart" width="400" height="300"></canvas>
            </div>
        </div>
        <div class="pw-modal-footer">
            <button class="button button-secondary pw-modal-close">Annuler</button>
            <button class="button button-primary" id="pw-save-strategy">Enregistrer</button>
        </div>
    </div>
</div>
