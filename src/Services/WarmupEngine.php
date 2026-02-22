<?php

namespace PostalWarmup\Services;

use PostalWarmup\Models\Database;
use PostalWarmup\Models\Stats;
use PostalWarmup\Admin\Settings;

/**
 * Moteur décisionnel de warmup adaptatif
 */
class WarmupEngine {

	/**
	 * Exécute la logique de progression journalière pour tous les serveurs actifs.
	 * Cette méthode doit être appelée une fois par jour par le CRON.
	 */
	public static function process_daily_advancement() {
		// 1. Récupérer le mode de stratégie
		$mode = Settings::get( 'warmup_strategy_mode', 'smart' );
		
		// Si mode linéaire (legacy), on utilise l'ancienne logique simplifiée
		if ( $mode === 'linear' ) {
			global $wpdb;
			$table = $wpdb->prefix . 'postal_servers';
			// Only increment for active servers
			$wpdb->query( "UPDATE $table SET warmup_day = warmup_day + 1 WHERE active = 1" );

			// V3: Increment ISP specific warmup days and reset daily counters
			$table_isp = $wpdb->prefix . 'postal_server_isp_stats';
			$wpdb->query( "UPDATE $table_isp SET warmup_day = warmup_day + 1, sent_today = 0, delivered_today = 0, fails_today = 0" );
			return;
		}

		// 2. Mode Smart (Adaptatif)
		$servers = Database::get_servers( true ); // Only active servers

		if ( ! empty( $servers ) ) {
			// Optimisation : Récupérer les ISPs une seule fois
			global $wpdb;
			$table_isps = $wpdb->prefix . 'postal_isps';
			$isps = $wpdb->get_results( "SELECT isp_key FROM $table_isps WHERE active = 1", ARRAY_A );

			foreach ( $servers as $server ) {
				// Utilisation d'Action Scheduler pour éviter le timeout si possible
				if ( function_exists( 'as_schedule_single_action' ) ) {
					// Planifie une action asynchrone pour chaque serveur
					as_schedule_single_action( 'pw_process_server_warmup', [ 'server_id' => $server['id'], 'isps' => $isps ], 'postal-warmup' );
				} else {
					// Fallback synchrone
					self::process_server_advancement( $server, $isps );
				}
			}
		}
	}

	/**
	 * Callback pour Action Scheduler
	 */
	public static function process_server_warmup_action( $server_id, $isps = [] ) {
		$server = Database::get_server( $server_id );
		if ( $server && $server['active'] ) {
			self::process_server_advancement( $server, $isps );
		}
	}

	/**
	 * Traite un serveur spécifique
	 */
	private static function process_server_advancement( $server, $isps = [] ) {
		global $wpdb;
		$server_id = $server['id'];
		
		if ( empty( $isps ) ) {
			$table_isps = $wpdb->prefix . 'postal_isps';
			$isps = $wpdb->get_results( "SELECT isp_key FROM $table_isps WHERE active = 1", ARRAY_A );
		}
		
		$isp_days = [];
		$table_stats = $wpdb->prefix . 'postal_server_isp_stats';

		// Get Settings for Logic
		$threshold_advance = (int) Settings::get('warmup_advance_threshold', 80);
		$threshold_retreat = (int) Settings::get('warmup_retreat_threshold', 3);
		$min_volume = (int) Settings::get('warmup_min_volume', 10);

		foreach ( $isps as $isp ) {
			$key = $isp['isp_key'];
			
			// Récupérer les stats ISP pour ce serveur
			$stats = Stats::get_server_isp_stats( $server_id, $key );
			
			// Analyser la performance d'hier (qui est stockée dans sent_today avant le reset)
			$sent = (int) $stats->sent_today;
			$fails = (int) $stats->fails_today;
			
			// Calcul du quota théorique pour ce jour spécifique
			$current_day = max( 1, (int) $stats->warmup_day ); // Safety check
			$quota = self::get_isp_quota( $server, $key, $current_day );
			
			$new_day = $current_day;
			$action = 'stagnate'; // default

			// === LOGIQUE DE DECISION ===

			// Calcul du Taux d'Erreur
			$error_rate = ($sent > 0) ? ($fails / $sent) * 100 : 0;
			
			// 1. Protection Critique (Frein d'urgence)
			if ( $sent > $min_volume && $error_rate > $threshold_retreat ) {
				// Recul fort (Minimum Day 1)
				$new_day = max( 1, $current_day - 3 );
				$action = 'retreat_critical';
			}
			// 2. Progression (Avance)
			// Si on a rempli le quota à > 80% ET taux d'erreur < 1% (ou threshold safe disons < 1/3 de retreat?)
			// Utilisons < 1% hardcodé ou configurable? Restons sur 1% comme "safe"
			elseif ( $quota > 0 && $sent >= ($quota * ($threshold_advance / 100)) && $error_rate < 1 ) {
				$new_day++;
				$action = 'advance';
			}
			// 3. Régression (Decay) par inactivité
			// Si on a envoyé 0 email alors qu'on est avancé (jour > 5)
			elseif ( $sent === 0 && $current_day > 5 ) {
				// On recule doucement pour ne pas reprendre trop fort
				$new_day = max( 1, $current_day - 1 );
				$action = 'decay';
			}
			// 4. Stagnation (Volume insuffisant ou petites erreurs)
			else {
				// On reste au même jour pour retenter demain
				$action = 'stagnate';
			}

			// Mise à jour en base (Composite Key Update)
			$wpdb->update( 
				$table_stats,
				[ 
					'warmup_day' => $new_day,
					'sent_today' => 0,      // Reset pour le nouveau jour
					'delivered_today' => 0,
					'fails_today' => 0,
					'last_updated' => current_time( 'mysql' )
				],
				[ 
					'server_id' => $server_id,
					'isp_key'   => $key
				]
			);

			$isp_days[] = $new_day;

			// Log de la décision si changement ou erreur
			// Utiliser Database::insert_log ou do_action pour observabilité
			if ( $action !== 'stagnate' || $error_rate > 0 ) {
				if ( Settings::get('enable_logging') ) {
					Database::insert_log([
						'server_id' => $server_id,
						'level' => ($action === 'retreat_critical') ? 'warning' : 'info',
						'message' => "Warmup Engine ($key): Action=$action, Day $current_day -> $new_day (Sent: $sent/$quota, Err: " . round($error_rate, 1) . "%)"
					]);
				}
				
				// Fire hook for external observers (Slack, Email, etc)
				// Arguments: Server ID, ISP Key, Old Day, New Day, Action, Metrics
				do_action( 'pw_warmup_status_change', $server_id, $key, $current_day, $new_day, $action, [
					'sent' => $sent,
					'quota' => $quota,
					'error_rate' => $error_rate
				]);
			}
		}

		// Mise à jour du "Jour Global" du serveur (Moyenne arrondie)
		if ( ! empty( $isp_days ) ) {
			$avg_day = (int) round( array_sum( $isp_days ) / count( $isp_days ) );
			$table_servers = $wpdb->prefix . 'postal_servers';
			$wpdb->update(
				$table_servers,
				[ 'warmup_day' => $avg_day ],
				[ 'id' => $server_id ]
			);
		}
	}

	/**
	 * Calcule le quota théorique pour un ISP à un jour donné
	 */
	public static function get_isp_quota( $server, $isp_key, $day ) {
		// Ici on pourrait utiliser une stratégie spécifique par ISP (table postal_isps)
		// Pour l'instant on utilise les settings globaux ou ceux du serveur
		
		$settings = get_option('pw_warmup_settings', []);
		$start_vol = isset($settings['warmup_start']) ? (int)$settings['warmup_start'] : 10;
		// Use 'growth_rate' from settings or default 30%? 
		// Settings class defines 'warmup_increase_percent' in defaults but 'growth_rate' in previous code?
		// Checking Settings.php defaults: 'warmup_increase_percent' => 20
		// Checking Stats.php logic: uses 'growth_rate'.
		// Need to unify. Let's check Settings.php again.
		
		$growth = isset($settings['warmup_increase_percent']) ? (int)$settings['warmup_increase_percent'] : 20;
		if (isset($settings['growth_rate'])) $growth = (int)$settings['growth_rate']; // Legacy override
		
		// Formula: Start * (1 + Growth/100)^(Day-1)
		if ($day < 1) $day = 1;
		
		return floor( $start_vol * pow( 1 + ($growth / 100), $day - 1 ) );
	}
}
