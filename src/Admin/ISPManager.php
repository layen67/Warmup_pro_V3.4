<?php

namespace PostalWarmup\Admin;

use WP_Error;

/**
 * Manager pour les Profils ISP (Admin Logic)
 */
class ISPManager {

    /**
     * Récupère tous les profils ISP
     */
    public static function get_all() {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_isps';
        $table_str = $wpdb->prefix . 'postal_strategies';
        
        $results = $wpdb->get_results( "
            SELECT i.*, s.name as strategy_name 
            FROM $table i 
            LEFT JOIN $table_str s ON i.strategy_id = s.id 
            ORDER BY i.isp_label ASC
        ", ARRAY_A );
        
        // Decode domains
        foreach ( $results as &$row ) {
            $row['domains'] = json_decode( $row['domains'], true ) ?: [];
        }
        
        return $results;
    }

    public static function get_by_key( $key ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_isps';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE isp_key = %s", $key ), ARRAY_A );
        
        if ( $row ) {
            $row['domains'] = json_decode( $row['domains'], true ) ?: [];
        }
        return $row;
    }

    public static function save( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_isps';
        
        $isp_key = sanitize_title( $data['isp_label'] );
        if ( empty( $isp_key ) ) return new WP_Error( 'invalid_name', 'Label requis' );

        $domains = array_map( 'sanitize_text_field', explode( ',', $data['domains'] ) );
        $domains = array_filter( array_map( 'trim', $domains ) ); // Remove empty

        $db_data = [
            'isp_label'  => sanitize_text_field( $data['isp_label'] ),
            'domains'    => json_encode( $domains ),
            'max_daily'  => absint( $data['max_daily'] ),
            'max_hourly' => absint( $data['max_hourly'] ),
            'strategy_id'=> !empty($data['strategy_id']) ? absint($data['strategy_id']) : null,
            'active'     => isset( $data['active'] ) ? 1 : 0
        ];

        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( $table, $db_data, [ 'id' => (int) $data['id'] ] );
            return (int) $data['id'];
        } else {
            // Check existence
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE isp_key = %s", $isp_key ) );
            if ( $exists ) {
                $isp_key .= '_' . time(); // Unique fallback
            }
            $db_data['isp_key'] = $isp_key;
            $db_data['created_at'] = current_time( 'mysql' );
            
            $wpdb->insert( $table, $db_data );
            return $wpdb->insert_id;
        }
    }

    public static function delete( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_isps';
        return $wpdb->delete( $table, [ 'id' => $id ] );
    }
}
