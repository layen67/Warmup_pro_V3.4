<?php

namespace PostalWarmup\Services;

use PostalWarmup\Admin\Settings;

class DomScanService {

    private static $api_base = 'https://api.domscan.net/v1';

    /**
     * Lance un audit complet pour un domaine
     */
    public static function audit_domain( $domain ) {
        $api_key = Settings::get( 'domscan_api_key' );
        
        if ( empty( $api_key ) ) {
            return new \WP_Error( 'missing_key', 'Clé API DomScan manquante.' );
        }

        $tools = Settings::get( 'domscan_tools', [ 'health', 'blacklist', 'reputation' ] );
        // Settings::get returns string if sanitized by basic logic, or array?
        // Our Settings::sanitize_settings relies on types. We didn't define 'domscan_tools' in defaults.
        // It might not be saved correctly yet in pw_settings.
        // For Phase 2, we just ensure API key works. Tools might need to be added to Settings defaults if we want them configurable.
        // I will use default here for now.
        if ( ! is_array( $tools ) ) $tools = [ 'health', 'blacklist', 'reputation' ];
        $result = [
            'timestamp' => current_time( 'mysql' ),
            'domain' => $domain,
        ];

        // 1. Check Health (DNS, SSL, etc.)
        if ( in_array( 'health', $tools ) ) {
            $health = self::request( '/health', [ 'domain' => $domain ], $api_key ); // Corrected endpoint
            if ( ! is_wp_error( $health ) ) {
                $result['health_score'] = $health['data']['score'] ?? 0;
                $result['health_issues'] = $health['data']['issues'] ?? [];
                $result['spf_valid'] = $health['data']['spf']['valid'] ?? false;
                $result['dkim_valid'] = $health['data']['dkim']['valid'] ?? false;
                $result['dmarc_valid'] = $health['data']['dmarc']['valid'] ?? false;
            }
        }

        // 2. Check Blacklist
        if ( in_array( 'blacklist', $tools ) ) {
            $blacklist = self::request( '/email/check', [ 'domain' => $domain ], $api_key ); // Corrected endpoint
            if ( ! is_wp_error( $blacklist ) ) {
                $result['blacklist_count'] = $blacklist['data']['listed_count'] ?? 0;
            }
        }

        // 3. Check Reputation
        if ( in_array( 'reputation', $tools ) ) {
            $reputation = self::request( '/reputation', [ 'domain' => $domain ], $api_key ); // Corrected endpoint
            if ( ! is_wp_error( $reputation ) ) {
                $result['reputation_score'] = $reputation['data']['trust_score'] ?? 0;
            }
        }

        // 4. SSL (Optional)
        if ( in_array( 'ssl', $tools ) ) {
            $ssl = self::request( '/ssl/grade', [ 'domain' => $domain ], $api_key );
            if ( ! is_wp_error( $ssl ) ) {
                $result['ssl_grade'] = $ssl['data']['grade'] ?? '-';
            }
        }
        
        // Save to transient for display
        set_transient( 'pw_domscan_' . md5( $domain ), $result, 12 * HOUR_IN_SECONDS );

        return $result;
    }

    private static function request( $endpoint, $params, $api_key ) {
        $url = self::$api_base . $endpoint . '?' . http_build_query( $params );
        
        $response = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Accept' => 'application/json'
            ],
            'timeout' => 15
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 ) {
            return new \WP_Error( 'api_error', 'Erreur DomScan (' . $code . '): ' . ( $data['message'] ?? 'Inconnue' ) );
        }

        return $data;
    }
    
    /**
     * Récupère le dernier audit en cache
     */
    public static function get_cached_audit( $domain ) {
        return get_transient( 'pw_domscan_' . md5( $domain ) );
    }
}
