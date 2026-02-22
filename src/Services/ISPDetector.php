<?php

namespace PostalWarmup\Services;

class ISPDetector {

    /**
     * Détecte l'ISP à partir d'une adresse email.
     * Utilise d'abord les règles DB, puis fallback sur des regex par défaut si la DB est vide.
     * 
     * @param string $email
     * @return string Identifiant de l'ISP (clé normalisée) ou 'other'
     */
    public static function detect( string $email ): string {
        $email = strtolower( trim( $email ) );
        
        // 1. Récupérer les règles depuis la DB
        global $wpdb;
        $table = $wpdb->prefix . 'postal_isps';
        
        // Note: Idéalement, mettre en cache pour perf
        $rules = $wpdb->get_results( "SELECT isp_key, domains FROM $table WHERE active = 1", ARRAY_A );
        
        if ( ! empty( $rules ) ) {
            foreach ( $rules as $rule ) {
                $domains = json_decode( $rule['domains'], true );
                if ( ! is_array( $domains ) ) continue; // Skip invalid JSON
                
                foreach ( $domains as $domain ) {
                    // Match exact domain part (e.g. @gmail.com)
                    // We check if email ENDS with @domain
                    if ( str_ends_with( $email, '@' . strtolower( trim( $domain ) ) ) ) {
                        return $rule['isp_key'];
                    }
                }
            }
        } else {
            // Fallback Legacy Regex (si table vide/installation initiale)
            // Ces règles devraient être migrées en DB lors de l'activation
            $legacy_rules = [
                'gmail' => '/@(gmail|googlemail|google)\./i',
                'yahoo' => '/@(yahoo|ymail|rocketmail)\./i',
                'outlook' => '/@(outlook|hotmail|live|msn|windowslive)\./i',
                'icloud' => '/@(icloud|me|mac)\./i',
                'aol' => '/@(aol|aim)\./i',
                'yandex' => '/@(yandex|ya)\./i',
                'proton' => '/@(protonmail|proton|pm)\./i',
                'zoho' => '/@(zoho|zohomail)\./i',
                'gmx' => '/@(gmx|mail\.com)\./i',
                'orange' => '/@(orange|wanadoo)\./i',
                'sfr' => '/@(sfr|neuf|club-internet)\./i',
                'free' => '/@(free|aliceadsl)\./i',
                't-online' => '/@(t-online)\./i',
                'mailru' => '/@(mail|list|in|bk)\.ru/i',
                'libero' => '/@(libero|inwind|iol)\.it/i',
            ];
            
            foreach ( $legacy_rules as $key => $regex ) {
                if ( preg_match( $regex, $email ) ) {
                    return $key;
                }
            }
        }

        return 'other';
    }

    /**
     * Retourne la liste des ISP connus (clés)
     */
    public static function get_known_isps(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_isps';
        $results = $wpdb->get_col( "SELECT DISTINCT isp_key FROM $table WHERE active = 1" );
        
        return ! empty( $results ) ? $results : [ 'gmail', 'yahoo', 'outlook', 'other' ];
    }
}
