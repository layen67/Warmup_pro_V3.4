<?php

namespace PostalWarmup\Admin;

class WarmupSettings {

    public function register_settings() {
        // === Global Warmup Strategy ===
        add_settings_section( 'pw_warmup_strategy', __( 'Stratégie de Warmup Globale', 'postal-warmup' ), null, 'postal-warmup-settings' );

        add_settings_field( 'pw_warmup_start', __( 'Volume de départ', 'postal-warmup' ), [ $this, 'render_start_field' ], 'postal-warmup-settings', 'pw_warmup_strategy' );
        add_settings_field( 'pw_warmup_growth', __( 'Croissance journalière (%)', 'postal-warmup' ), [ $this, 'render_growth_field' ], 'postal-warmup-settings', 'pw_warmup_strategy' );
        add_settings_field( 'pw_warmup_max_hour', __( 'Max par heure', 'postal-warmup' ), [ $this, 'render_max_hour_field' ], 'postal-warmup-settings', 'pw_warmup_strategy' );
        add_settings_field( 'pw_warmup_schedule', __( 'Créneaux horaires autorisés', 'postal-warmup' ), [ $this, 'render_schedule_field' ], 'postal-warmup-settings', 'pw_warmup_strategy' );
        
        // Register options
        register_setting( 'postal-warmup-settings', 'pw_warmup_settings', [ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ] );
    }

    public function sanitize_settings( $input ) {
        $output = [];
        $output['start_volume'] = absint( $input['start_volume'] ?? 10 );
        $output['growth_rate'] = absint( $input['growth_rate'] ?? 20 );
        $output['max_per_hour'] = absint( $input['max_per_hour'] ?? 0 );
        
        if ( isset( $input['schedule'] ) && is_array( $input['schedule'] ) ) {
            $output['schedule'] = array_map( 'absint', $input['schedule'] );
        } else {
            $output['schedule'] = range( 9, 18 ); // Default 9h-18h
        }
        
        // Remove legacy ISP JSON logic if present, handled by ISPManager now
        // But keep fallback if user provided
        
        return $output;
    }

    public function render_start_field() {
        $settings = get_option( 'pw_warmup_settings', [] );
        $val = $settings['start_volume'] ?? 10;
        echo '<input type="number" name="pw_warmup_settings[start_volume]" value="' . esc_attr( $val ) . '" class="small-text"> emails/jour/serveur';
        echo '<p class="description">Valeur par défaut si non spécifiée dans le serveur ou l\'ISP.</p>';
    }

    public function render_growth_field() {
        $settings = get_option( 'pw_warmup_settings', [] );
        $val = $settings['growth_rate'] ?? 20;
        echo '<input type="number" name="pw_warmup_settings[growth_rate]" value="' . esc_attr( $val ) . '" class="small-text"> %';
        echo '<p class="description">Augmentation quotidienne du volume (ex: 20%).</p>';
    }

    public function render_max_hour_field() {
        $settings = get_option( 'pw_warmup_settings', [] );
        $val = $settings['max_per_hour'] ?? 0;
        echo '<input type="number" name="pw_warmup_settings[max_per_hour]" value="' . esc_attr( $val ) . '" class="small-text"> emails/heure (0 = illimité)';
        echo '<p class="description">Limite globale de sécurité par heure.</p>';
    }

    public function render_schedule_field() {
        $settings = get_option( 'pw_warmup_settings', [] );
        $schedule = $settings['schedule'] ?? range( 9, 18 );
        
        echo '<div style="display: flex; flex-wrap: wrap; gap: 5px; max-width: 600px;">';
        for ( $i = 0; $i < 24; $i++ ) {
            $checked = in_array( $i, $schedule ) ? 'checked' : '';
            echo "<label style='display: inline-block; padding: 4px; border: 1px solid #ddd; border-radius: 3px;'>
                    <input type='checkbox' name='pw_warmup_settings[schedule][]' value='$i' $checked> {$i}h
                  </label>";
        }
        echo '</div>';
        echo '<p class="description">Heures durant lesquelles les envois sont autorisés (selon le fuseau horaire du template si défini, sinon global).</p>';
    }
}
