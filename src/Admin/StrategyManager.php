<?php

namespace PostalWarmup\Admin;

use PostalWarmup\Models\Strategy;
use PostalWarmup\Services\StrategyEngine;

class StrategyManager {

    public static function get_all() {
        return Strategy::get_all();
    }

    public static function save( $data ) {
        // Sanitize and structure config
        $config = [
            'start_volume' => absint( $data['start_volume'] ),
            'max_volume'   => absint( $data['max_volume'] ),
            'growth_type'  => sanitize_text_field( $data['growth_type'] ),
            'growth_value' => floatval( $data['growth_value'] ),
            'safety_rules' => [
                'max_hard_bounce' => floatval( $data['safety_max_hard_bounce'] ?? 2.0 ),
                'max_complaint'   => floatval( $data['safety_max_complaint'] ?? 0.1 ),
            ]
        ];

        return Strategy::save([
            'id' => $data['id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'],
            'config' => $config
        ]);
    }

    public static function delete( $id ) {
        return Strategy::delete( $id );
    }

    /**
     * Génère les données de prévisualisation du graphique
     */
    public static function get_preview_data( $config ) {
        $data = [];
        $days = 30; // Preview 30 days
        $strategy = [ 'config' => $config ]; // Mock strategy object
        
        for ( $i = 1; $i <= $days; $i++ ) {
            $data[] = StrategyEngine::calculate_daily_limit( $strategy, $i );
        }
        return $data;
    }
}
