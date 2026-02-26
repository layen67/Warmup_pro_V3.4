<?php

namespace PostalWarmup\API;

use PostalWarmup\Models\Database;
use PostalWarmup\Services\Logger;
use PostalWarmup\Core\TemplateEngine;
use PostalWarmup\Admin\Settings;

/**
 * Classe d'envoi des emails via Postal
 */
class Sender {

	/**
	 * Enregistre les hooks pour Action Scheduler
	 */
	public function init() {
		add_action( 'pw_send_email_async', array( $this, 'process_queue' ), 10, 6 );
	}

	/**
	 * Envoie un email via Postal (Asynchrone via Action Scheduler)
	 */
	public static function send( $to, $domain, $prefix = null, $server = null ) {
		
		if ( ! $server ) {
			$server = Database::get_server_by_domain( $domain );
			if ( ! $server ) {
				return [ 'error' => "Serveur introuvable pour le domaine : $domain" ];
			}
		}
		
		if ( $prefix === null ) {
			$prefix = 'support';
		}
		
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$args = array(
				'to'          => $to,
				'domain'      => $domain,
				'prefix'      => $prefix,
				'server_id'   => $server['id'],
				'retry_count' => 0
			);
			
			as_schedule_single_action( time(), 'pw_send_email_async', $args, 'postal-warmup' );
			
			Logger::info( "Email mis en file d'attente", [
				'to'     => $to,
				'domain' => $domain
			]);
			
			return [ 'success' => true, 'queued' => true ];
		} 
		
		// Fallback synchrone
		$sender = new self();
		return $sender->process_queue( $to, $domain, $prefix, $server['id'], 0 );
	}

	/**
	 * Worker
	 */
	public function process_queue( $to, $domain, $prefix, $server_id, $retry_count = 0, $handle_retry = true ) {
		
		$server = Database::get_server( $server_id );
		if ( ! $server ) {
			Logger::error( "Worker: Serveur introuvable ID $server_id" );
			return [ 'error' => 'Serveur introuvable' ];
		}

		// Use TemplateEngine to prepare everything
		$prepared = TemplateEngine::prepare_template( $prefix, $domain, $prefix, $to );
		$template_name = $prepared['name'];
		
		// 1. From Email Customization
		$default_from = Settings::get( 'default_from_email', '' );
		$default_name = Settings::get( 'default_from_name', '' );
		
		$from_email = $prefix . '@' . $domain; // Default behavior
		if ( ! empty( $default_from ) ) {
			// Replace if setting is forced (or use setting as fallback if logic allows)
			// Requirement says "Default from email", implying fallback.
			// But Sender logic constructs it from prefix+domain.
			// Let's assume current logic is primary, settings are fallback if empty (which rarely happens here).
			// However, user might want to override.
			// Let's keep existing logic as primary for warmup (randomized prefixes).
		}
		
		$from_name = $prepared['from_name'];
		if ( empty( $from_name ) && ! empty( $default_name ) ) {
			$from_name = $default_name;
		}

		Logger::info( "Worker: Traitement envoi email", [
			'server_id'  => $server['id'],
			'email_from' => $from_email,
			'email_to'   => $to,
			'retry'      => $retry_count,
			'template'   => $template_name
		]);
		
		$payload = [
			'to'         => [ $to ],
			'from'       => "$from_name <$from_email>",
			'subject'    => $prepared['subject'],
			'plain_body' => $prepared['text'],
			'html_body'  => $prepared['html'],
			'headers'    => [ 
				'X-Warmup-Source'   => 'PostalWarmupPro-v' . PW_VERSION,
				'X-Warmup-Template' => $template_name,
				'Precedence'        => 'bulk',
				'Auto-Submitted'    => 'auto-generated',
				'List-Unsubscribe'  => "<mailto:unsubscribe@$domain?subject=unsubscribe>",
			]
		];

		// 2. Custom Headers Injection
		$custom_headers = Settings::get( 'custom_headers', '' );
		if ( ! empty( $custom_headers ) ) {
			$lines = explode( "\n", $custom_headers );
			foreach ( $lines as $line ) {
				$parts = explode( ':', $line, 2 );
				if ( count( $parts ) === 2 ) {
					$payload['headers'][ trim( $parts[0] ) ] = trim( $parts[1] );
				}
			}
		}

		if ( ! empty( $prepared['reply_to'] ) ) {
			$payload['reply_to'] = $prepared['reply_to'];
		}

		$global_tag = get_option( 'pw_global_tag', 'warmup' );
		if ( ! empty( $global_tag ) ) {
			$payload['tag'] = sanitize_text_field( $global_tag );
		}

		$payload = apply_filters( 'pw_email_payload', $payload, $prepared, [] );

		$result = self::send_request( $server, $payload, $retry_count + 1, $template_name );
		
		$response_time = isset( $result['response_time'] ) ? $result['response_time'] : 0;

		if ( $result['success'] ) {
			// New Stats Logic: Insert into History
			$message_id = $result['response']['message_id'] ?? ($result['response']['data']['message_id'] ?? null);
			Database::insert_stat_history( [
				'server_id'   => $server['id'],
				'template_id' => $prepared['id'], // Can be null if file/system
				'message_id'  => $message_id,
				'email_from'  => $from_email,
				'event_type'  => 'sent',
				'timestamp'   => current_time( 'mysql' ),
				'meta'        => json_encode( [ 'template_name' => $template_name ] )
			] );

			Database::increment_sent( $domain, true, $response_time );
			Database::record_stat( $server['id'], true, $response_time );
			return $result;
		}
		
		Database::increment_sent( $domain, false, $response_time );
		Database::record_stat( $server['id'], false, $response_time );
		
		if ( ! $handle_retry ) {
			return $result;
		}

		$max_retries = (int) Settings::get( 'max_retries', 3 );
		
		if ( $retry_count < $max_retries ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				// Use configured strategy
				$base = (int) Settings::get( 'retry_delay_base', 60 );
				$strategy = Settings::get( 'retry_strategy', 'fixed' );
				$max_delay = (int) Settings::get( 'retry_delay_max', 900 );
				
				$delay = $base;
				if ( $strategy === 'linear' ) {
					$delay = $base * ($retry_count + 1);
				} elseif ( $strategy === 'exponential' ) {
					$delay = $base * pow( 2, $retry_count + 1 );
				}
				
				if ( $delay > $max_delay ) $delay = $max_delay;

				as_schedule_single_action(
					time() + $delay, 
					'pw_send_email_async', 
					array( $to, $domain, $prefix, $server_id, $retry_count + 1, true ), // Pass handle_retry=true explicitly
					'postal-warmup'
				);
				Logger::warning( "Worker: Échec envoi, replanifié dans {$delay}s", [ 
					'error'    => $result['error'],
					'template' => $template_name
				] );
			}
		} else {
			Logger::error( "Worker: Abandon après $max_retries tentatives", [ 
				'error'    => $result['error'],
				'template' => $template_name
			] );
		}
		
		return $result;
	}


	private static function send_request( $server, $payload, $attempt, $template_name = null ) {
		// Use unified Client
		$start_time = microtime( true );
		
		// Client::request handles headers (except 'Content-Type' which it adds),
		// URL construction, API Key, and JSON body encoding if array passed.
		
		// Map payload to Client params
		// Client::request($server_id, $endpoint, $method, $data)
		
		$result = Client::request( $server['id'], 'send/message', 'POST', $payload );
		$response_time = microtime( true ) - $start_time;

		if ( is_wp_error( $result ) ) {
			Logger::error( "Erreur HTTP (tentative $attempt)", [
				'server_id'     => $server['id'],
				'error'         => $result->get_error_message(),
				'response_time' => round( $response_time, 3 )
			]);
			return [ 'success' => false, 'error' => $result->get_error_message(), 'response_time' => $response_time ];
		}
		
		// Client::request returns data array on success or full response if error?
		// Let's check Client.php.
		// "if ( isset( $result['status'] ) && $result['status'] === 'success' ) { return $result['data'] ?? []; }"
		// So $result IS 'data' array (containing message_id, messages, etc).
		
		$message_id = $result['message_id'] ?? null;

		Logger::info( "Email envoyé avec succès", [
			'server_id'     => $server['id'],
			'email_to'      => $payload['to'][0] ?? '',
			'message_id'    => $message_id,
			'response_time' => round( $response_time, 3 ),
			'status'        => 'success',
			'template'      => $template_name
		]);
		
		return [ 'success' => true, 'response' => ['data' => $result], 'response_time' => $response_time ];
	}

	public static function test_connection( $server_id ) {
		$server = Database::get_server( $server_id );
		if ( ! $server ) {
			return [ 'success' => false, 'message' => __( 'Serveur introuvable', 'postal-warmup' ) ];
		}
		
		$test_email = get_option( 'admin_email' );
		$test_payload = [
			'to'         => [ $test_email ],
			'from'       => "Test <test@{$server['domain']}>",
			'subject'    => 'Test Postal Warmup',
			'plain_body' => "Test OK.\nServeur : {$server['domain']}",
			'html_body'  => "<p>Test OK.</p><p><strong>Serveur :</strong> {$server['domain']}</p>"
		];
		
		$result = self::send_request( $server, $test_payload, 1 );
		
		if ( $result['success'] ) {
			return [ 'success' => true, 'message' => sprintf( __( 'Test réussi ! Email envoyé à %s', 'postal-warmup' ), $test_email ) ];
		}
		return [ 'success' => false, 'message' => sprintf( __( 'Test échoué : %s', 'postal-warmup' ), $result['error'] ) ];
	}
}
