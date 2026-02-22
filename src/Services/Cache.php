<?php

namespace PostalWarmup\Services;

use PostalWarmup\Models\Database;
use PostalWarmup\Models\Stats;
use PostalWarmup\Admin\Settings;

/**
 * Classe de gestion du cache
 */
class Cache {

	private static function get_ttl( $type ) {
		// Use defaults aligned with Settings class
		switch ( $type ) {
			case 'server': return (int) Settings::get( 'cache_ttl_server', 300 );
			case 'stats': return (int) Settings::get( 'cache_ttl_stats', 600 );
			case 'api': return (int) Settings::get( 'cache_ttl_api', 300 );
			default: return 300;
		}
	}

	private static function is_enabled() {
		return Settings::get( 'enable_transient_cache', true );
	}

	/**
	 * Récupère les serveurs actifs (avec cache)
	 */
	public static function get_active_servers() {
		if ( ! self::is_enabled() ) return Database::get_servers( true );

		$cache_key = 'pw_active_servers';
		$servers = get_transient( $cache_key );
		
		if ( false === $servers ) {
			$servers = Database::get_servers( true );
			set_transient( $cache_key, $servers, self::get_ttl( 'server' ) );
		}
		
		return $servers;
	}

	/**
	 * Récupère un serveur par domaine (avec cache)
	 */
	public static function get_server_by_domain( $domain ) {
		if ( ! self::is_enabled() ) return Database::get_server_by_domain( $domain );

		$cache_key = 'pw_server_' . md5( $domain );
		$server = get_transient( $cache_key );
		
		if ( false === $server ) {
			$server = Database::get_server_by_domain( $domain );
			if ( $server ) {
				set_transient( $cache_key, $server, self::get_ttl( 'server' ) );
			}
		}
		
		return $server;
	}

	/**
	 * Vide le cache des serveurs
	 */
	public static function clear_servers_cache() {
		global $wpdb;
		
		$wpdb->query(
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_pw_server_%' 
			OR option_name LIKE '_transient_timeout_pw_server_%'
			OR option_name LIKE '_transient_pw_active_servers%'
			OR option_name LIKE '_transient_timeout_pw_active_servers%'"
		);
	}

	/**
	 * Récupère les stats (avec cache)
	 */
	public static function get_stats( $server_id = null, $days = 7 ) {
		if ( ! self::is_enabled() ) {
			// Direct logic copied from below for non-cache path
			global $wpdb;
			if ( $server_id ) {
				$stats_table = $wpdb->prefix . 'postal_stats';
				$date_from = date( 'Y-m-d', strtotime( "-$days days" ) );
				return $wpdb->get_results( $wpdb->prepare(
					"SELECT date, SUM(sent_count) as total_sent, SUM(success_count) as total_success, SUM(error_count) as total_errors 
					FROM $stats_table WHERE server_id = %d AND date >= %s GROUP BY date ORDER BY date ASC",
					$server_id, $date_from
				), ARRAY_A );
			}
			return [];
		}

		$cache_key = 'pw_stats_' . $server_id . '_' . $days;
		$stats = get_transient( $cache_key );
		
		if ( false === $stats ) {
			global $wpdb;
			if ( $server_id ) {
				$stats_table = $wpdb->prefix . 'postal_stats';
				$date_from = date( 'Y-m-d', strtotime( "-$days days" ) );
				$stats = $wpdb->get_results( $wpdb->prepare(
					"SELECT date, SUM(sent_count) as total_sent, SUM(success_count) as total_success, SUM(error_count) as total_errors 
					FROM $stats_table WHERE server_id = %d AND date >= %s GROUP BY date ORDER BY date ASC",
					$server_id, $date_from
				), ARRAY_A );
			} else {
				$stats = [];
			}
			set_transient( $cache_key, $stats, self::get_ttl( 'stats' ) );
		}
		
		return $stats;
	}

	/**
	 * Vide le cache des stats
	 */
	public static function clear_stats_cache() {
		global $wpdb;
		
		$wpdb->query(
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_pw_stats_%' 
			OR option_name LIKE '_transient_timeout_pw_stats_%'"
		);
	}

	/**
	 * Récupère un template (avec cache)
	 */
	public static function get_template( $name ) {
		$cache_key = 'pw_template_' . $name;
		$template = get_transient( $cache_key );
		
		if ( false === $template ) {
			$template = TemplateLoader::load( $name );
			if ( $template ) {
				set_transient( $cache_key, $template, DAY_IN_SECONDS );
			}
		}
		
		return $template;
	}

	/**
	 * Vide le cache des templates
	 */
	public static function clear_templates_cache() {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_pw_template_%' 
			OR option_name LIKE '_transient_timeout_pw_template_%'"
		);
	}

	/**
	 * Vide tout le cache du plugin
	 */
	public static function clear_all_cache() {
		self::clear_servers_cache();
		self::clear_stats_cache();
		self::clear_templates_cache();
		
		Logger::info( 'Cache entièrement vidé' );
	}
}
