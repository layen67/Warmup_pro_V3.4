<?php

namespace PostalWarmup\Models;

use PostalWarmup\Models\Database;
use PostalWarmup\Services\WarmupEngine;

class Stats {

    // ... (Existing methods kept intact)

	/**
	 * Récupère le nombre d'emails envoyés aujourd'hui par un serveur.
	 * Utilisé pour le Load Balancer.
	 */
	public static function get_server_daily_usage( int $server_id ) {
		global $wpdb;
		$stats_table = $wpdb->prefix . 'postal_stats';
		$date = current_time( 'Y-m-d' );
		
		return (int) $wpdb->get_var( $wpdb->prepare( 
			"SELECT SUM(sent_count) FROM $stats_table WHERE server_id = %d AND date = %s", 
			$server_id, 
			$date 
		) );
	}

    // ... (skipping unchanged methods for brevity, assuming full content is preserved in real file write)
    // Wait, write_file overwrites. I must include ALL content.
    // I will read the file first to ensure I have everything, then append new methods.
    // Actually I already read it in Phase 1.
    // I will paste the full file content + new methods.

	public static function get_server_hourly_usage( int $server_id ) {
		global $wpdb;
		$stats_table = $wpdb->prefix . 'postal_stats';
		$date = current_time( 'Y-m-d' );
		$hour = (int) current_time( 'H' );
		
		return (int) $wpdb->get_var( $wpdb->prepare( 
			"SELECT sent_count FROM $stats_table WHERE server_id = %d AND date = %s AND hour = %d", 
			$server_id, 
			$date,
			$hour
		) );
	}

	public static function get_isp_daily_usage( string $isp ) {
		global $wpdb;
		$queue_table = $wpdb->prefix . 'postal_queue';
		$date_start = current_time( 'Y-m-d 00:00:00' );
		
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $queue_table WHERE isp = %s AND status IN ('sent', 'processing') AND updated_at >= %s",
			$isp,
			$date_start
		) );
	}

    public static function get_isp_hourly_usage( string $isp ) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'postal_queue';
        $hour_start = current_time( 'Y-m-d H:00:00' );
        
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $queue_table WHERE isp = %s AND status IN ('sent', 'processing') AND updated_at >= %s",
            $isp,
            $hour_start
        ) );
    }

	public static function get_server_isp_daily_usage( int $server_id, string $isp ) {
		$stats = self::get_server_isp_stats( $server_id, $isp );
		return $stats ? (int) $stats->sent_today : 0;
	}

	public static function get_server_isp_stats( int $server_id, string $isp_key ) {
		global $wpdb;
		$table = $wpdb->prefix . 'postal_server_isp_stats';
		
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table WHERE server_id = %d AND isp_key = %s",
			$server_id,
			$isp_key
		) );
		
		if ( ! $row ) {
			$server_day = $wpdb->get_var( $wpdb->prepare( "SELECT warmup_day FROM {$wpdb->prefix}postal_servers WHERE id = %d", $server_id ) );
			$init_day = $server_day ? (int)$server_day : 1;

			$wpdb->insert( $table, [
				'server_id' => $server_id,
				'isp_key' => $isp_key,
				'warmup_day' => $init_day,
				'sent_today' => 0,
				'score' => 100
			] );
			return (object) [
				'warmup_day' => $init_day,
				'sent_today' => 0,
				'score' => 100,
				'fails_today' => 0
			];
		}
		
		return $row;
	}

	public static function increment_server_isp_usage( int $server_id, string $isp_key, $success = true ) {
		global $wpdb;
		$table = $wpdb->prefix . 'postal_server_isp_stats';
		
		self::get_server_isp_stats($server_id, $isp_key);
		
		$sql = "UPDATE $table SET sent_today = sent_today + 1, last_updated = NOW()";
		if ( $success ) {
			$sql .= ", delivered_today = delivered_today + 1";
		} else {
			$sql .= ", fails_today = fails_today + 1";
			$sql .= ", score = GREATEST(0, score - 5)";
		}
		$sql .= " WHERE server_id = %d AND isp_key = %s";
		
		$wpdb->query( $wpdb->prepare( $sql, $server_id, $isp_key ) );
	}

	public static function get_dynamic_limit( $server ) {
		$limit = (int) $server['daily_limit'];
		
		if ( $limit <= 0 ) {
			$settings = get_option('pw_warmup_settings', []);
			
			$start_vol = isset($settings['warmup_start']) ? (int)$settings['warmup_start'] : 10;
			if (isset($settings['start_volume'])) $start_vol = (int)$settings['start_volume'];

			$growth = isset($settings['warmup_increase_percent']) ? (int)$settings['warmup_increase_percent'] : 20;
			if (isset($settings['growth_rate'])) $growth = (int)$settings['growth_rate'];
			
			$day = isset($server['warmup_day']) ? (int)$server['warmup_day'] : 1;
			if ($day < 1) $day = 1;

			$limit = floor( $start_vol * pow( 1 + ($growth / 100), $day - 1 ) );
		}
		
		return $limit;
	}

	public static function get_dashboard_stats() {
		$cached = get_transient( 'pw_dashboard_stats' );
		if ( $cached !== false ) return $cached;

		global $wpdb;
		$servers_table = $wpdb->prefix . 'postal_servers';
		$stats_table = $wpdb->prefix . 'postal_stats';
		
		$today = current_time( 'Y-m-d' );
		$yesterday = date( 'Y-m-d', strtotime( '-1 day', current_time( 'timestamp' ) ) );
		
		$general = $wpdb->get_row( "SELECT COUNT(*) as total_servers, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_servers FROM $servers_table", ARRAY_A );
		
		if ( ! $general ) {
			$general = [ 'total_servers' => 0, 'active_servers' => 0 ];
		}

		$daily_table = $wpdb->prefix . 'postal_stats_daily';
		
		$history_total = (int) $wpdb->get_var( "SELECT SUM(total_sent) FROM $daily_table" );
		$today_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(sent_count) FROM $stats_table WHERE date = %s", $today ) );
		$total_sent = $history_total + $today_total;

		$history_success = (int) $wpdb->get_var( "SELECT SUM(total_success) FROM $daily_table" );
		$today_success = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(success_count) FROM $stats_table WHERE date = %s", $today ) );
		$total_success = $history_success + $today_success;

		$sent_yesterday = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(total_sent) FROM $daily_table WHERE date = %s", $yesterday ) );
		if ( $sent_yesterday === 0 ) {
			$sent_yesterday = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(sent_count) FROM $stats_table WHERE date = %s", $yesterday ) );
		}
		
		$sent_today = $today_total;
		
		$success_rate = 0;
		if ( $total_sent > 0 ) {
			$success_rate = round( ( (int) ( $general['total_success'] ?? 0 ) / $total_sent ) * 100, 2 );
		}
		
		$evolution = 0;
		if ( $sent_yesterday > 0 ) {
			$evolution = round( ( ( $sent_today - $sent_yesterday ) / $sent_yesterday ) * 100, 1 );
		} elseif ( $sent_today > 0 ) {
			$evolution = 100;
		}
		
		$results = [
			'total_sent'     => $total_sent,
			'total_success'  => $total_success,
			'success_rate'   => $success_rate,
			'total_servers'  => (int) ( $general['total_servers'] ?? 0 ),
			'active_servers' => (int) ( $general['active_servers'] ?? 0 ),
			'sent_today'     => $sent_today,
			'evolution'      => $evolution
		];

		set_transient( 'pw_dashboard_stats', $results, 1 * MINUTE_IN_SECONDS );
		return $results;
	}

	public static function get_recent_errors( $limit = 5 ) {
		global $wpdb;
		$logs_table = $wpdb->prefix . 'postal_logs';
		$servers_table = $wpdb->prefix . 'postal_servers';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT l.*, s.domain as server_domain 
			FROM $logs_table l 
			LEFT JOIN $servers_table s ON l.server_id = s.id 
			WHERE l.level IN ('ERROR', 'CRITICAL') 
			ORDER BY l.created_at DESC 
			LIMIT %d",
			$limit
		), ARRAY_A ) ?: [];
	}

	public static function get_servers_stats() {
		$servers = Database::get_servers();
		$stats = [];
		foreach ( $servers as $server ) {
			$rate = 0;
			if ( $server['sent_count'] > 0 ) {
				$rate = round( ( $server['success_count'] / $server['sent_count'] ) * 100, 1 );
			}
			$server['success_rate'] = $rate;
			$server['quota'] = self::get_dynamic_limit($server);
			$server['ip'] = self::get_server_ip($server['domain']);
			$stats[] = $server;
		}
		return $stats;
	}

	private static function get_server_ip( $domain ) {
		$cache_key = 'pw_server_ip_' . md5( $domain );
		$ip = get_transient( $cache_key );
		
		if ( false === $ip ) {
			$ip = gethostbyname( $domain );
			if ( $ip === $domain ) {
				$ip = null;
			}
			set_transient( $cache_key, $ip, 24 * HOUR_IN_SECONDS );
		}
		
		return $ip;
	}
	
	public static function get_activity_24h() {
		global $wpdb;
		$stats_table = $wpdb->prefix . 'postal_stats';
		$daily_table = $wpdb->prefix . 'postal_stats_daily';
		$date_limit = date( 'Y-m-d', strtotime( '-7 days' ) );
		$today = current_time( 'Y-m-d' );
		
		$sql_past = "
			SELECT date, SUM(total_sent) as total_sent, SUM(total_success) as total_success, SUM(total_error) as total_errors
			FROM $daily_table 
			WHERE date >= %s AND date < %s
			GROUP BY date
		";
		
		$sql_today = "
			SELECT date, SUM(sent_count) as total_sent, SUM(success_count) as total_success, SUM(error_count) as total_errors
			FROM $stats_table
			WHERE date = %s
			GROUP BY date
		";
		
		$sql = "($sql_past) UNION ALL ($sql_today) ORDER BY date ASC";
		
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $date_limit, $today, $today ), ARRAY_A );
		
		return [
			'labels' => array_column( $results, 'date' ),
			'data' => $results
		];
	}

	public static function get_detailed_metrics( $days = 7 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'postal_metrics';
		$date_from = date( 'Y-m-d', strtotime( "-$days days" ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT event_type, SUM(count) as total FROM $table WHERE date >= %s GROUP BY event_type", $date_from ), ARRAY_A ) ?: [];
	}

	public static function get_overall_stats() {
		$stats = self::get_dashboard_stats();
		$detailed = self::get_detailed_metrics( 30 );
		
		$defaults = [ 'bounces' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0, 'complaints' => 0, 'delayed' => 0, 'held' => 0, 'dns_errors' => 0 ];
		$stats = array_merge( $stats, $defaults );

		if ( is_array( $detailed ) ) {
			foreach ( $detailed as $m ) {
				$val = (int) $m['total'];
				switch ( $m['event_type'] ) {
					case 'bounced': $stats['bounces'] = $val; break;
					case 'delivered': 
					case 'sent': $stats['delivered'] += $val; break;
					case 'opened': $stats['opened'] = $val; break;
					case 'clicked': $stats['clicked'] = $val; break;
					case 'complaint': $stats['complaints'] = $val; break;
					case 'delayed': $stats['delayed'] = $val; break;
					case 'held': $stats['held'] = $val; break;
					case 'dns_error': $stats['dns_errors'] = $val; break;
				}
			}
		}
		
		$stats['avg_response_time'] = self::get_avg_response_time();
		return $stats;
	}

	public static function get_avg_response_time( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'postal_stats';
		$date_from = date( 'Y-m-d', strtotime( "-$days days" ) );
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT AVG(avg_response_time) FROM $table WHERE date >= %s", $date_from ) );
	}

	public static function get_templates_global_stats() {
		global $wpdb;
		$logs_table = $wpdb->prefix . 'postal_logs';
		$templates_table = $wpdb->prefix . 'postal_templates';
		$servers_table = $wpdb->prefix . 'postal_servers';
		
		$stats = $wpdb->get_row( "SELECT SUM(sent_count) as total_sent, AVG(CASE WHEN sent_count > 0 THEN (success_count / sent_count) * 100 ELSE 0 END) as avg_success_rate FROM $servers_table", ARRAY_A );
		
		$top_template = 'Aucun';
		$has_usage_count = $wpdb->get_results( "SHOW COLUMNS FROM `$templates_table` LIKE 'usage_count'" );
		if ( ! empty( $has_usage_count ) ) {
			$top_template = $wpdb->get_var( "SELECT name FROM $templates_table ORDER BY usage_count DESC LIMIT 1" ) ?: 'Aucun';
		}
		
		return [
			'total_sent'       => (int) ( $stats['total_sent'] ?? 0 ),
			'avg_success_rate' => round( (float) ( $stats['avg_success_rate'] ?? 0 ), 2 ),
			'top_template'     => $top_template
		];
	}
	
	public static function export_csv( $days = 30 ) {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
		
		global $wpdb;
		$table = $wpdb->prefix . 'postal_stats';
		$date_from = date( 'Y-m-d', strtotime( "-$days days" ) );
		
		$data = $wpdb->get_results( $wpdb->prepare(
			"SELECT date, hour, SUM(sent_count) as sent, SUM(success_count) as success, SUM(error_count) as errors, AVG(avg_time) as avg_time
			FROM $table WHERE date >= %s GROUP BY date, hour ORDER BY date DESC, hour DESC",
			$date_from
		), ARRAY_A );
		
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="postal-stats-' . date( 'Y-m-d' ) . '.csv"' );
		
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'Date', 'Hour', 'Sent', 'Success', 'Errors', 'Avg Time (s)' ] );
		
		foreach ( $data as $row ) {
			fputcsv( $out, $row );
		}
		
		fclose( $out );
		exit;
	}
	
	public static function cleanup_old_stats() {
		$days = get_option( 'pw_stats_retention_days', 90 );
		global $wpdb;
		$table = $wpdb->prefix . 'postal_stats';
		$date = date( 'Y-m-d', strtotime( "-$days days" ) );
		return $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE date < %s", $date ) );
	}

	public static function get_top_templates( $days = 7, $limit = 10 ) {
		global $wpdb;
		$table_stats = $wpdb->prefix . 'postal_stats_history';
		$table_tpl = $wpdb->prefix . 'postal_templates';
		$date_from = date( 'Y-m-d H:i:s', strtotime( "-$days days" ) );

		$count = $wpdb->get_var("SELECT COUNT(*) FROM $table_stats");
		if ($count == 0) {
			$logs_table = $wpdb->prefix . 'postal_logs';
			return $wpdb->get_results( $wpdb->prepare(
				"SELECT template_used, COUNT(*) as usage_count, SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count, AVG(response_time) as avg_response_time
				FROM $logs_table 
				WHERE template_used IS NOT NULL 
				AND created_at >= %s 
				AND message != 'Worker: Traitement envoi email'
				GROUP BY template_used ORDER BY usage_count DESC LIMIT %d",
				$date_from, $limit
			), ARRAY_A ) ?: [];
		}

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT 
				t.name as template_used, 
				COUNT(DISTINCT CASE WHEN h.event_type = 'sent' THEN h.message_id END) as usage_count,
				COUNT(DISTINCT CASE WHEN h.event_type IN ('delivered', 'sent') THEN h.message_id END) as success_count,
				0 as avg_response_time
			FROM $table_stats h
			JOIN $table_tpl t ON h.template_id = t.id
			WHERE h.timestamp >= %s
			GROUP BY t.name 
			ORDER BY usage_count DESC 
			LIMIT %d",
			$date_from, $limit
		), ARRAY_A ) ?: [];
	}

	public static function get_all_templates_summary( $days = 30 ) {
		global $wpdb;
		$table_stats = $wpdb->prefix . 'postal_stats_history';
		$table_tpl = $wpdb->prefix . 'postal_templates';
		$date_from = date( 'Y-m-d H:i:s', strtotime( "-$days days" ) );

		$count = $wpdb->get_var("SELECT COUNT(*) FROM $table_stats");
		if ($count == 0) {
			$logs_table = $wpdb->prefix . 'postal_logs';
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT template_used, COUNT(*) as usage_count, SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count, AVG(response_time) as avg_response_time
				FROM $logs_table 
				WHERE template_used IS NOT NULL 
				AND created_at >= %s
				AND message != 'Worker: Traitement envoi email'
				GROUP BY template_used",
				$date_from
			), ARRAY_A ) ?: [];
		} else {
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT 
					t.name as template_used, 
					COUNT(DISTINCT CASE WHEN h.event_type = 'sent' THEN h.message_id END) as usage_count,
					COUNT(DISTINCT CASE WHEN h.event_type IN ('delivered', 'sent') THEN h.message_id END) as success_count,
					0 as avg_response_time
				FROM $table_stats h
				LEFT JOIN $table_tpl t ON h.template_id = t.id
				WHERE h.timestamp >= %s
				GROUP BY t.name",
				$date_from
			), ARRAY_A ) ?: [];
		}

		$stats = [];
		foreach ( $results as $row ) {
			if (!empty($row['template_used'])) {
				$stats[$row['template_used']] = $row;
			}
		}
		return $stats;
	}

	public static function get_server_stats_summary_filtered( $days = 30 ) {
		global $wpdb;
		$stats_table = $wpdb->prefix . 'postal_stats';
		$servers_table = $wpdb->prefix . 'postal_servers';
		$date_from = date( 'Y-m-d', strtotime( "-$days days" ) );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT 
				s.id,
				s.domain,
				SUM(st.sent_count) as total_sent,
				SUM(st.success_count) as total_success,
				SUM(st.error_count) as total_errors
			FROM $servers_table s
			LEFT JOIN $stats_table st ON s.id = st.server_id AND st.date >= %s
			GROUP BY s.id, s.domain
			ORDER BY total_sent DESC",
			$date_from
		), ARRAY_A ) ?: [];
	}

	public static function get_server_detail_breakdown( $server_id, $days = 30 ) {
		$cache_key = "pw_stats_server_{$server_id}_{$days}";
		$cached = get_transient( $cache_key );
		if ( $cached !== false ) return $cached;

		global $wpdb;
		$history_table = $wpdb->prefix . 'postal_stats_history';
		$templates_table = $wpdb->prefix . 'postal_templates';
		$date_from = date( 'Y-m-d H:i:s', strtotime( "-$days days" ) );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT 
				COALESCE(t.name, 'null') as template_name,
				COUNT(DISTINCT CASE WHEN h.event_type = 'sent' THEN h.message_id END) as total_sent,
				COUNT(DISTINCT CASE WHEN h.event_type IN ('delivered', 'sent') THEN h.message_id END) as success_count,
				COUNT(DISTINCT CASE WHEN h.event_type = 'opened' THEN h.message_id END) as opened_count,
				COUNT(DISTINCT CASE WHEN h.event_type = 'clicked' THEN h.message_id END) as clicked_count,
				COUNT(DISTINCT CASE WHEN h.event_type IN ('failed', 'bounced') THEN h.message_id END) as error_count,
				COUNT(DISTINCT CASE WHEN h.event_type = 'delayed' THEN h.message_id END) as delayed_count,
				COUNT(DISTINCT CASE WHEN h.event_type = 'held' THEN h.message_id END) as held_count,
				0 as avg_response_time
			FROM $history_table h
			LEFT JOIN $templates_table t ON h.template_id = t.id
			WHERE h.server_id = %d AND h.timestamp >= %s
			GROUP BY COALESCE(t.name, 'null')
			ORDER BY total_sent DESC",
			$server_id,
			$date_from
		), ARRAY_A );

		set_transient( $cache_key, $results, 60 );
		return $results;
	}

	public static function get_template_performance( $days = 30 ) {
		global $wpdb;
		$table_metrics = $wpdb->prefix . 'postal_metrics';
		$table_tpl = $wpdb->prefix . 'postal_templates';
		$date_from = date( 'Y-m-d', strtotime( "-$days days" ) );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.name, m.event_type, SUM(m.count) as total
			FROM $table_metrics m
			JOIN $table_tpl t ON m.template_id = t.id
			WHERE m.date >= %s
			GROUP BY t.name, m.event_type
			ORDER BY total DESC",
			$date_from
		), ARRAY_A ) ?: [];

		$performance = [];
		foreach ( $results as $r ) {
			if ( ! isset( $performance[$r['name']] ) ) {
				$performance[$r['name']] = [
					'sent' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0, 'delayed' => 0, 'held' => 0
				];
			}
			$type = $r['event_type'];
			$mapped_type = ( $type === 'sent' ) ? 'delivered' : $type;
			if ( isset( $performance[$r['name']][$mapped_type] ) ) {
				$performance[$r['name']][$mapped_type] += (int) $r['total'];
			}
		}
		return $performance;
	}

	public static function get_template_stats( $template_name, $days = 30 ) {
		global $wpdb;
		$table_metrics = $wpdb->prefix . 'postal_metrics';
		$table_tpl = $wpdb->prefix . 'postal_templates';
		$date_from = date( 'Y-m-d', strtotime( "-$days days" ) );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.event_type, SUM(m.count) as total
			FROM $table_metrics m
			JOIN $table_tpl t ON m.template_id = t.id
			WHERE t.name = %s AND m.date >= %s
			GROUP BY m.event_type",
			$template_name,
			$date_from
		), ARRAY_A ) ?: [];

		$stats = [
			'sent' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0, 'delayed' => 0, 'held' => 0
		];

		foreach ( $results as $r ) {
			$type = $r['event_type'];
			if ( isset( $stats[$type] ) ) {
				$stats[$type] += (int) $r['total'];
			}
			if ( $type === 'sent' ) {
				$stats['delivered'] += (int) $r['total'];
			}
		}
		
		$total_delivered = $stats['delivered'] ?? 0;
		$stats['open_rate'] = $total_delivered > 0 ? round( ( $stats['opened'] / $total_delivered ) * 100, 1 ) : 0;
		$stats['click_rate'] = $total_delivered > 0 ? round( ( $stats['clicked'] / $total_delivered ) * 100, 1 ) : 0;
		$stats['bounce_rate'] = $total_delivered > 0 ? round( ( $stats['bounced'] / $total_delivered ) * 100, 1 ) : 0;

		return $stats;
	}

	public static function get_global_stats( $days = 30 ) {
		global $wpdb;
		$stats_table = $wpdb->prefix . 'postal_stats';
		$daily_table = $wpdb->prefix . 'postal_stats_daily';
		$date_from = date( 'Y-m-d', strtotime( "-$days days" ) );
		
		$today = current_time( 'Y-m-d' );
		
		$sql = "
			(SELECT date, SUM(total_sent) as total_sent, SUM(total_success) as total_success, SUM(total_error) as total_errors, AVG(avg_response_time) as avg_time
			 FROM $daily_table
			 WHERE date >= %s AND date < %s
			 GROUP BY date)
			UNION ALL
			(SELECT date, SUM(sent_count) as total_sent, SUM(success_count) as total_success, SUM(error_count) as total_errors, AVG(avg_response_time) as avg_time
			 FROM $stats_table
			 WHERE date = %s
			 GROUP BY date)
			ORDER BY date ASC
		";
		
		return $wpdb->get_results( $wpdb->prepare( $sql, $date_from, $today, $today ), ARRAY_A ) ?: [];
	}

	public static function increment_warmup_day() {
		WarmupEngine::process_daily_advancement();
	}

	public static function aggregate_daily_stats() {
		global $wpdb;
		$source_table = $wpdb->prefix . 'postal_stats';
		$target_table = $wpdb->prefix . 'postal_stats_daily';
		$yesterday = date( 'Y-m-d', strtotime( 'yesterday' ) );
		
		$sql = "INSERT INTO $target_table (server_id, date, total_sent, total_success, total_error, avg_response_time, updated_at)
				SELECT server_id, date, SUM(sent_count), SUM(success_count), SUM(error_count), AVG(avg_response_time), NOW()
				FROM $source_table
				WHERE date <= %s
				GROUP BY server_id, date
				ON DUPLICATE KEY UPDATE
				total_sent = VALUES(total_sent),
				total_success = VALUES(total_success),
				total_error = VALUES(total_error),
				avg_response_time = VALUES(avg_response_time),
				updated_at = NOW()";
				
		$wpdb->query( $wpdb->prepare( $sql, $yesterday ) );
	}

	public static function get_advanced_charts_data( $days = 30 ) {
		global $wpdb;
		$table_stats = $wpdb->prefix . 'postal_stats_history';
		$date_from = date( 'Y-m-d H:i:s', strtotime( "-$days days" ) );

		$volume = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(timestamp) as date, COUNT(*) as count 
			FROM $table_stats 
			WHERE timestamp >= %s AND event_type = 'sent'
			GROUP BY DATE(timestamp) ORDER BY date ASC",
			$date_from
		), ARRAY_A );

		$daily_events = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(timestamp) as date, event_type, COUNT(DISTINCT message_id) as count
			FROM $table_stats
			WHERE timestamp >= %s
			GROUP BY DATE(timestamp), event_type
			ORDER BY date ASC",
			$date_from
		), ARRAY_A );

		$metrics_by_date = [];
		foreach ($daily_events as $row) {
			$date = $row['date'];
			if (!isset($metrics_by_date[$date])) {
				$metrics_by_date[$date] = ['sent' => 0, 'delivered' => 0, 'opened' => 0, 'bounced' => 0, 'failed' => 0];
			}
			$metrics_by_date[$date][$row['event_type']] += (int)$row['count'];
		}

		$chart_data = [
			'dates' => array_keys($metrics_by_date),
			'sent' => [],
			'deliverability' => [],
			'open_rate' => [],
			'errors' => []
		];

		foreach ($metrics_by_date as $date => $counts) {
			$chart_data['sent'][] = $counts['sent'];
			
			$del_rate = ($counts['sent'] > 0) ? round(($counts['delivered'] / $counts['sent']) * 100, 2) : 0;
			if ($del_rate > 100) $del_rate = 100;
			$chart_data['deliverability'][] = $del_rate;

			$open_rate = ($counts['delivered'] > 0) ? round(($counts['opened'] / $counts['delivered']) * 100, 2) : 0;
			if ($open_rate > 100) $open_rate = 100;
			$chart_data['open_rate'][] = $open_rate;

			$chart_data['errors'][] = $counts['bounced'] + $counts['failed'];
		}

		return $chart_data;
	}

	public static function get_heatmap_data( $days = 30 ) {
		global $wpdb;
		$table_stats = $wpdb->prefix . 'postal_stats_history';
		$table_tpl = $wpdb->prefix . 'postal_templates';
		$date_from = date( 'Y-m-d H:i:s', strtotime( "-$days days" ) );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.name as template, HOUR(h.timestamp) as hour, COUNT(*) as count
			FROM $table_stats h
			JOIN $table_tpl t ON h.template_id = t.id
			WHERE h.timestamp >= %s AND h.event_type = 'sent'
			GROUP BY t.name, hour",
			$date_from
		), ARRAY_A );

		$heatmap = [];
		foreach ($results as $row) {
			$tpl = $row['template'];
			if (!isset($heatmap[$tpl])) {
				$heatmap[$tpl] = array_fill(0, 24, 0);
			}
			$heatmap[$tpl][(int)$row['hour']] = (int)$row['count'];
		}

		return $heatmap;
	}

    /**
     * Stats des threads pour un serveur donné
     */
    public static function get_thread_stats( int $server_id ): array {
        global $wpdb;
        $table_history = $wpdb->prefix . 'postal_stats_history';

        $today_start = current_time('Y-m-d 00:00:00');

        $replies_today = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_history WHERE server_id = %d AND timestamp >= %s AND meta LIKE %s",
            $server_id, $today_start, '%thread_depth%'
        ));

        $active_threads = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT meta) FROM $table_history WHERE server_id = %d AND timestamp >= %s AND meta LIKE %s",
            $server_id, date('Y-m-d H:i:s', strtotime('-24 hours')), '%thread_depth%'
        ));

        return [
            'replies_today' => (int)$replies_today,
            'active_threads' => (int)$active_threads,
            'response_rate' => 0
        ];
    }

    /**
     * Récupère les threads récents
     */
    public static function get_recent_threads( int $limit = 5 ): array {
        global $wpdb;
        $table_history = $wpdb->prefix . 'postal_stats_history';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_history WHERE meta LIKE %s ORDER BY timestamp DESC LIMIT %d",
            '%thread_depth%', $limit
        ), ARRAY_A);

        $threads = [];
        foreach ($results as $row) {
            $meta = json_decode($row['meta'], true);
            $threads[] = [
                'email' => $row['email_from'], // Actually receiver of reply
                'template' => $meta['template_name'] ?? 'Unknown',
                'exchange' => $meta['thread_depth'] ?? 1,
                'status' => $row['event_type'],
                'time' => $row['timestamp']
            ];
        }

        return $threads;
    }

    /**
     * Nettoyage automatique des données historiques
     */
    public static function cleanup_stats_history() {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_stats_history';
        $days = (int) \PostalWarmup\Admin\Settings::get('stats_retention_days', 90);

        $date = date('Y-m-d H:i:s', strtotime("-$days days"));

        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE timestamp < %s", $date));

        // Also optimize if enabled
        if (\PostalWarmup\Admin\Settings::get('db_optimize_on_purge', true)) {
            $wpdb->query("OPTIMIZE TABLE $table");
        }
    }
}
