<?php

namespace PostalWarmup\Models;

/**
 * Modèle pour les stratégies de warmup
 */
class Strategy {

    /**
     * Récupère toutes les stratégies
     */
    public static function get_all() {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_strategies';
        $results = $wpdb->get_results( "SELECT * FROM $table ORDER BY name ASC", ARRAY_A );
        
        foreach ( $results as &$row ) {
            $row['config'] = json_decode( $row['config_json'], true ) ?: [];
        }
        return $results;
    }

    /**
     * Récupère une stratégie par ID
     */
    public static function get( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_strategies';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
        
        if ( $row ) {
            $row['config'] = json_decode( $row['config_json'], true ) ?: [];
        }
        return $row;
    }

    /**
     * Enregistre une stratégie
     */
    public static function save( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_strategies';
        
        $db_data = [
            'name'        => sanitize_text_field( $data['name'] ),
            'description' => sanitize_textarea_field( $data['description'] ?? '' ),
            'config_json' => json_encode( $data['config'] ?? [] ), // Assumes structured array passed
        ];

        if ( ! empty( $data['id'] ) ) {
            $db_data['updated_at'] = current_time( 'mysql' );
            $wpdb->update( $table, $db_data, [ 'id' => (int) $data['id'] ] );
            return (int) $data['id'];
        } else {
            $db_data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $db_data );
            return $wpdb->insert_id;
        }
    }

    /**
     * Supprime une stratégie
     */
    public static function delete( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_strategies';
        
        // Reset associated ISPs to NULL strategy
        $wpdb->update( 
            $wpdb->prefix . 'postal_isps', 
            [ 'strategy_id' => null ], 
            [ 'strategy_id' => $id ] 
        );
        
        return $wpdb->delete( $table, [ 'id' => $id ] );
    }
}
