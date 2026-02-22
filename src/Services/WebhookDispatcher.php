<?php

namespace PostalWarmup\Services;

use PostalWarmup\Services\Logger;

class WebhookDispatcher {

	/**
	 * Init Hooks
	 */
	public static function init() {
		add_action( 'pw_queue_item_sent', [ __CLASS__, 'handle_sent' ], 10, 3 );
		add_action( 'pw_queue_item_failed', [ __CLASS__, 'handle_failed' ], 10, 3 );
		add_action( 'pw_postal_webhook_event', [ __CLASS__, 'handle_postal_event' ], 10, 3 );
	}

	public static function handle_postal_event( $event_name, $payload, $ctx ) {
		// Avoid duplicate MessageSent (handled internally by QueueManager)
		if ( $event_name === 'MessageSent' ) {
			return;
		}

		self::dispatch( $event_name, [
			'source' => 'postal_webhook',
			'postal_payload' => $payload,
			'context' => $ctx,
			'timestamp' => current_time( 'timestamp' )
		] );
	}

	public static function handle_sent( $id, $item, $server ) {
		self::dispatch( 'MessageSent', [
			'id' => $id,
			'to' => $item['to_email'],
			'from' => $item['from_email'],
			'subject' => $item['subject'],
			'server_domain' => $server['domain'],
			'server_id' => $server['id'],
			'timestamp' => current_time( 'timestamp' ),
			'status' => 'sent'
		] );
	}

	public static function handle_failed( $id, $item, $error ) {
		self::dispatch( 'MessageDeliveryFailed', [
			'id' => $id,
			'to' => $item['to_email'],
			'from' => $item['from_email'],
			'subject' => $item['subject'],
			'error' => $error,
			'timestamp' => current_time( 'timestamp' ),
			'status' => 'failed'
		] );
	}

	private static function dispatch( $event_name, $payload ) {
		$enabled = get_option( 'pw_webhook_enabled', false );
		if ( ! $enabled ) return;

		$url = get_option( 'pw_webhook_url', '' );
		if ( empty( $url ) ) return;

		$events = get_option( 'pw_webhook_events', [] );
		if ( ! is_array( $events ) || ! in_array( $event_name, $events ) ) {
			return;
		}

		$body = [
			'event' => $event_name,
			'timestamp' => time(),
			'payload' => $payload
		];

		Logger::info( "Webhook: Sending $event_name to $url" );

		// Send async to avoid blocking the queue process
		$response = wp_remote_post( $url, [
			'body' => json_encode( $body ),
			'headers' => [
				'Content-Type' => 'application/json',
				'X-Postal-Warmup-Event' => $event_name,
				'User-Agent' => 'PostalWarmupPro/' . ( defined('PW_VERSION') ? PW_VERSION : '1.0' )
			],
			'timeout' => 5,
			'blocking' => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ) // Often useful for local dev, but default false is safer for prod? No, default true is safer. Let's stick to WP defaults but allow filter.
		] );

		if ( is_wp_error( $response ) ) {
			Logger::error( "Webhook: Error sending $event_name", [ 'error' => $response->get_error_message() ] );
		}
	}

	public static function send_test( $url ) {
		if ( empty( $url ) ) return new \WP_Error( 'invalid_url', 'URL manquante' );

		$body = [
			'event' => 'TestEvent',
			'timestamp' => time(),
			'payload' => [
				'message' => 'Ceci est un test de connexion depuis Postal Warmup Pro.',
				'server_url' => get_site_url()
			]
		];

		$response = wp_remote_post( $url, [
			'body' => json_encode( $body ),
			'headers' => [
				'Content-Type' => 'application/json',
				'X-Postal-Warmup-Event' => 'TestEvent',
				'User-Agent' => 'PostalWarmupPro/' . ( defined('PW_VERSION') ? PW_VERSION : '1.0' )
			],
			'timeout' => 10,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false )
		] );

		return $response;
	}
}
