<?php

namespace PostalWarmup\Services;

use PostalWarmup\Models\Database;
use PostalWarmup\Services\Logger;

class HealthScoreCalculator {

    /**
     * Calcule le score de santé (0-100) pour un serveur donné
     */
    public static function calculate_score( $server_id ) {
        // Cache le score pour 1 heure
        $cache_key = 'pw_server_health_score_' . $server_id;
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            return (int) $cached;
        }

        global $wpdb;
        $stats_table = $wpdb->prefix . 'postal_stats';
        
        // 1. Récupérer les stats des dernières 24h
        $yesterday = date( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );
        $stats = $wpdb->get_row( $wpdb->prepare( "
            SELECT 
                SUM(sent_count) as total_sent,
                SUM(error_count) as total_errors,
                AVG(avg_response_time) as avg_latency
            FROM $stats_table 
            WHERE server_id = %d AND date >= %s
        ", $server_id, $yesterday ), ARRAY_A );

        if ( ! $stats || $stats['total_sent'] == 0 ) {
            // Pas assez de données = Score neutre 100 ou 80 ? Disons 100 (bénéfice du doute)
            set_transient( $cache_key, 100, 3600 );
            return 100;
        }

        $score = 100;
        
        // 2. Pénalité d'erreurs
        $error_rate = ( $stats['total_errors'] / $stats['total_sent'] ) * 100;
        $score -= ( $error_rate * 5 ); // -5 points par % d'erreur
        
        // 3. Pénalité de latence (> 1s)
        $latency = (float) $stats['avg_latency'];
        if ( $latency > 1000 ) {
            $over = ( $latency - 1000 ) / 100; // 1 point par 100ms au dessus
            $score -= $over;
        }

        // 4. Pénalité de Blacklist (Si détecté via autre module - placeholder)
        // if ( is_blacklisted($server_id) ) $score -= 30;

        // Limites
        $score = max( 0, min( 100, $score ) );
        
        set_transient( $cache_key, (int) $score, 3600 );
        return (int) $score;
    }

    /**
     * Vérifie si une chute de réputation est détectée (Open Rate)
     */
    public static function check_reputation_drop( $server_id ) {
        if ( ! get_option( 'pw_reputation_monitoring_enabled', true ) ) {
            return false;
        }

        // TODO: Implémenter la comparaison Open Rate J vs J-3
        // Nécessite des stats détaillées d'ouverture par jour.
        // Pour l'instant, placeholder qui retourne false.
        
        return false;
    }

    /**
     * Récupère le taux d'erreur par ISP pour le Smart Routing
     */
    public static function get_isp_error_rates( $server_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_server_isp_stats';
        
        // Stats du jour
        $results = $wpdb->get_results( $wpdb->prepare( "
            SELECT isp_key, sent_today, fails_today 
            FROM $table 
            WHERE server_id = %d
        ", $server_id ), ARRAY_A );
        
        $rates = [];
        foreach ( $results as $row ) {
            if ( $row['sent_today'] > 10 ) { // Minimum de volume pour être significatif
                $rates[ $row['isp_key'] ] = ( $row['fails_today'] / $row['sent_today'] ) * 100;
            }
        }
        
        return $rates;
    }
}
