<?php

namespace PostalWarmup\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PostalWarmup\Services\Logger;
use PostalWarmup\Services\QueueManager;
use PostalWarmup\Models\Database;
use PostalWarmup\Admin\Settings;

/**
 * Gestionnaire de webhook REST API
 */
class WebhookHandler {

	public function register_routes() {
		register_rest_route( 'postal-warmup/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_webhook' ),
			'permission_callback' => array( $this, 'verify_request' ),
		) );
		
		register_rest_route( 'postal-warmup/v1', '/test', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'test_endpoint' ),
			'permission_callback' => '__return_true',
		) );
	}

	public function verify_request( WP_REST_Request $request ): bool|WP_Error {
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

		// 1. IP Whitelist Check
		$whitelist = Settings::get( 'webhook_ip_whitelist', '' );
		if ( ! empty( $whitelist ) ) {
			$allowed_ips = array_map( 'trim', explode( "\n", $whitelist ) );
			$allowed = false;
			foreach ( $allowed_ips as $allowed_ip ) {
				// Simple IP check or CIDR logic could go here
				if ( $ip === $allowed_ip || $this->cidr_match( $ip, $allowed_ip ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				Logger::warning( "Webhook bloqué par IP Whitelist: $ip" );
				return new WP_Error( 'forbidden', 'IP not allowed', [ 'status' => 403 ] );
			}
		}

		// 2. Rate Limiting
		if ( ! $this->check_rate_limit( $ip ) ) {
			Logger::warning( "Webhook Rate Limit dépassé pour IP: $ip" );
			return new WP_Error( 'too_many_requests', 'Rate limit exceeded', [ 'status' => 429 ] );
		}

		// 3. Strict Mode / Signature Check
		if ( Settings::get( 'webhook_strict_mode', true ) ) {
			return $this->verify_signature( $request );
		}

		return true;
	}

	private function check_rate_limit( $ip ) {
		$minute_limit = (int) Settings::get( 'webhook_rate_limit_minute', 100 );
		$hour_limit = (int) Settings::get( 'webhook_rate_limit_hour', 2000 );

		$transient_min = 'pw_webhook_limit_min_' . md5( $ip );
		$transient_hour = 'pw_webhook_limit_hour_' . md5( $ip );

		$count_min = (int) get_transient( $transient_min );
		$count_hour = (int) get_transient( $transient_hour );

		if ( $count_min >= $minute_limit || $count_hour >= $hour_limit ) {
			return false;
		}

		set_transient( $transient_min, $count_min + 1, 60 );
		set_transient( $transient_hour, $count_hour + 1, 3600 );

		return true;
	}

	private function cidr_match( $ip, $range ) {
		if ( strpos( $range, '/' ) === false ) return false;
		list( $subnet, $bits ) = explode( '/', $range );
		$ip = ip2long( $ip );
		$subnet = ip2long( $subnet );
		$mask = -1 << ( 32 - $bits );
		$subnet &= $mask;
		return ( $ip & $mask ) == $subnet;
	}

	public function verify_signature( WP_REST_Request $request ): bool|WP_Error {
		$secret = get_option( 'pw_webhook_secret' ); // Use option directly as it's the source of truth
		
		if ( empty( $secret ) ) {
			$secret = wp_generate_password( 64, false );
			update_option( 'pw_webhook_secret', $secret );
		}
		
		$params = $request->get_query_params();
		$token = isset( $params['token'] ) ? (string) $params['token'] : '';
		
		if ( empty( $token ) || ! hash_equals( $secret, $token ) ) {
			$action = Settings::get( 'webhook_invalid_signature_action', 'log' );
			
			if ( $action === 'log' || $action === 'notify' ) {
				Logger::warning( 'Webhook : Token invalide ou manquant', [ 
					'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
					'received_token' => substr( $token, 0, 5 ) . '...'
				] );
			}
			
			// Always reject in strict mode
			return new WP_Error( 'forbidden', 'Invalid token', [ 'status' => 403 ] );
		}
		
		return true;
	}

	public function handle_webhook( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		
		if ( empty( $data ) ) {
			return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Invalid JSON' ], 400 );
		}
		
		// Events
		if ( isset( $data['event'] ) ) {
			$this->handle_event( $data );
		} elseif ( isset( $data['rcpt_to'] ) ) {
			// Incoming message (if configured to route to this URL)
			$this->handle_incoming_message( $data );
		}
		
		return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
	}

	private function handle_event( $data ) {
		$event = $data['event'] ?? '';
		$payload = $data['payload'] ?? [];
		
		$ctx = $this->identify_context($payload);
		$server_id = $ctx['server_id'];
		$template = $ctx['template'];
		$log_context = [ 
			'server_id' => $server_id,
			'template'  => $template
		];

		switch ( $event ) {
			case 'MessageSent':
				// Optimization: Sender.php already records 'sent' on API success.
				// We still update legacy metrics for safety but skip history insertion to avoid duplicates.
				$this->track_metric( $payload, 'sent', $ctx, true );
				break;
			case 'MessageDelivered': // Explicitly handle Delivered
				$this->track_metric( $payload, 'delivered', $ctx );
				break;
			case 'MessageDeliveryFailed':
				$this->track_metric( $payload, 'failed', $ctx );
				Logger::error( 'Échec de livraison', array_merge( $log_context, [ 'status' => 'failed' ] ) );
				break;
			case 'MessageBounced':
				$this->track_metric( $payload, 'bounced', $ctx );
				Logger::warning( 'Message rebondi', array_merge( $log_context, [ 'status' => 'bounced' ] ) );
				
				// Handle Bounce Action
				$action = Settings::get( 'bounce_handling_action', 'mark_failed' );
				if ( $action === 'remove' ) {
					// Logic to remove from queue or suppression list integration
					// Assuming QueueManager has a method or direct DB access here
					// Ideally: QueueManager::handle_bounce($email, $server_id);
				} elseif ( $action === 'notify' ) {
					// Trigger notification
				}
				break;
			case 'MessageLinkClicked':
				$this->track_metric( $payload, 'clicked', $ctx );
				break;
			case 'MessageLoaded':
				$this->track_metric( $payload, 'opened', $ctx );
				break;
			case 'DomainDNSError':
				$this->track_metric( $payload, 'dns_error', $ctx );
				Logger::critical( 'Erreur DNS détectée par Postal', $log_context );
				break;
			default:
				// Ignore others
		}

		// Hook for external integrations (e.g. WebhookDispatcher)
		do_action( 'pw_postal_webhook_event', $event, $payload, $ctx );
	}

	private function identify_context( $payload ) {
		$message = $payload['message'] ?? [];
		$server_id = null;
		$template_name = null;
		$domain = null;

		$headers = $message['headers'] ?? [];
		$template_name = $headers['X-Warmup-Template'] ?? null;

		if ( isset( $message['from'] ) ) {
			list( $prefix, $d ) = $this->parse_email( $message['from'] );
			$domain = $d;
			if ( ! $template_name ) {
				$template_name = $prefix; // Fallback
			}
		} elseif ( isset( $payload['domain'] ) ) {
			$domain = $payload['domain'];
		}

		if ( $domain ) {
			$server = Database::get_server_by_domain( $domain );
			if ( $server ) {
				$server_id = $server['id'];
			}
		}
		
		return [ 
			'server_id' => $server_id, 
			'template' => $template_name, 
			'domain' => $domain 
		];
	}

	private function handle_incoming_message( $data ) {
		// Logic from original class-pw-webhook-handler.php
		$id = $data['id'] ?? null;
		$rcpt_to = $data['rcpt_to'] ?? '';
		$mail_from = $data['mail_from'] ?? '';
		$subject = $data['subject'] ?? '';

		if ( empty( $rcpt_to ) ) return;

		// Deduplication: Check if message ID already processed (valid 1 hour)
		if ( $id ) {
			$transient_key = 'pw_webhook_msg_' . $id;
			if ( get_transient( $transient_key ) ) {
				Logger::info( "Webhook ignoré (doublon)", [ 'message_id' => $id ] );
				return;
			}
			set_transient( $transient_key, true, 3600 );
		}

		list( $prefix, $domain ) = $this->parse_email( $rcpt_to );
		if ( ! $domain ) return;

		$server = Database::get_server_by_domain( $domain );
		if ( ! $server ) return;

		// Loop Prevention: Do not reply if sender is one of our own servers
		list( $from_prefix, $from_domain ) = $this->parse_email( $mail_from );
		if ( $from_domain ) {
			$sender_server = Database::get_server_by_domain( $from_domain );
			if ( $sender_server ) {
				Logger::warning( "Boucle détectée : Tentative de réponse à soi-même", [ 'from' => $mail_from, 'to' => $rcpt_to ] );
				return;
			}
		}

		Logger::info( "Message entrant", [ 'server_id' => $server['id'], 'from' => $mail_from, 'subject' => $subject ] );
		
		// Check limits and reply (via Queue)
		// Lookup Template ID (Fix "Système" label issue)
		global $wpdb;
		$table_tpl = $wpdb->prefix . 'postal_templates';
		$template_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_tpl WHERE name = %s", $prefix ) );

		// Meta data for queue
		$meta = [
			'domain' => $domain,
			'prefix' => $prefix,
			'template_id' => $template_id, // Pass ID to queue
			'original_message_id' => $id
		];

		// Add to Queue instead of sending directly
		QueueManager::add( $server['id'], $mail_from, $prefix . '@' . $domain, 'Re: ' . $subject, $meta );
	}

	private function parse_email( $email ) {
		if ( preg_match( '/<(.+?)>/', $email, $matches ) ) {
			$email = $matches[1];
		}
		$parts = explode( '@', trim( $email ), 2 );
		return ( count( $parts ) === 2 ) ? $parts : [ '', '' ];
	}

	private function track_metric( $payload, $event_type, $ctx = null, $skip_history = false ) {
		if ( $ctx === null ) {
			$ctx = $this->identify_context( $payload );
		}
		
		$server_id = $ctx['server_id'];
		$template_name = $ctx['template'];
		$domain = $ctx['domain'];

		if ( $server_id ) {
			// New Stats Architecture: Insert into postal_stats_history
			if ( ! $skip_history ) {
				global $wpdb;
				$table_tpl = $wpdb->prefix . 'postal_templates';
				$template_id = null;
				if ( $template_name ) {
					$template_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_tpl WHERE name = %s", $template_name ) );
				}

				$message_id = $payload['original_message']['id'] ?? $payload['message']['id'] ?? null;

				Database::insert_stat_history( [
					'server_id'   => $server_id,
					'template_id' => $template_id,
					'message_id'  => $message_id,
					'event_type'  => $event_type,
					'timestamp'   => current_time( 'mysql' ),
					'meta'        => json_encode( [ 'template_name' => $template_name ] )
				] );
			}

			// Legacy metrics updates (kept for backward compat or if needed by charts until fully refactored)
			Database::update_detailed_metrics( $template_name, $server_id, $event_type );
			
			// Fix: Also record global stats for relevant events
			if ( $event_type === 'sent' || $event_type === 'delivered' ) {
				Database::increment_sent( $domain, true );
				Database::record_stat( $server_id, true );
			} elseif ( in_array( $event_type, [ 'failed', 'bounced', 'dns_error' ] ) ) {
				Database::increment_sent( $domain, false );
				Database::record_stat( $server_id, false );
			}
		}
	}

	private function check_rate_limits( $server_id ) {
		// Simplified rate limit check from DB logic
		return true; 
	}

	public function test_endpoint() {
		return new WP_REST_Response( [
			'status' => 'ok',
			'message' => 'Postal Warmup API is running',
			'version' => PW_VERSION
		], 200 );
	}
}
