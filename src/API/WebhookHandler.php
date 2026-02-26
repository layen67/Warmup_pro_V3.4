<?php

namespace PostalWarmup\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use PostalWarmup\Services\Logger;
use PostalWarmup\Services\QueueManager;
use PostalWarmup\Models\Database;
use PostalWarmup\Admin\Settings;
use PostalWarmup\Admin\TemplateManager;

/**
 * Gestionnaire de webhook REST API
 */
class WebhookHandler {

    private $template_manager;
    private $logger;

    public function __construct() {
        // Since this class is instantiated in register_routes, we can init helpers here or lazy load them.
        // We use static methods from Logger usually, but let's keep consistency if refactoring.
        // The original code used static Logger::method() calls.
    }

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
			case 'MessageSent': // Renamed from MessageDelivered per instructions, Postal uses MessageSent or MessageDelivered?
                // Instructions say: "Renommer MessageDelivered en MessageSent partout"
                // But Postal sends MessageSent when sent, and MessageDelivered when delivered?
                // Instructions say "Seuls événements valides dans Postal : MessageSent, ... → Signaler tout fichier qui référence MessageDelivered"
                // So Postal does NOT send MessageDelivered? Wait.
                // Postal documentation says "MessageSent" (sent to upstream) and "MessageDelivered" (delivered to recipient)?
                // Phase 1 Audit says: "Utilisation de MessageDelivered | Remplacer par MessageSent (Postal n'envoie pas Delivered)"
                // OK, I will trust the instructions.
				// Optimization: Sender.php already records 'sent' on API success.
				// We still update legacy metrics for safety but skip history insertion to avoid duplicates.
				$this->track_metric( $payload, 'sent', $ctx, true );
				break;
            // Removed MessageDelivered case as per instructions to rename it to MessageSent (which is handled above)
            // But wait, if I rename, I merge the logic?
            // "Enregistre une métrique `delivered`" logic was there.
            // If Postal only sends MessageSent, then we only have 'sent'.
            // I will assume MessageSent covers the successful dispatch.

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
        // CORRECTION 2c: Ignorer bounces et auto-replies
        if ( isset($data['bounce']) && $data['bounce'] === true ) {
            Logger::info('incoming_bounce_ignored', [
                'from' => $data['mail_from'] ?? 'unknown'
            ]);
            return;
        }
        if ( !empty($data['auto_submitted']) ) {
            Logger::info('incoming_auto_reply_ignored', [
                'from'           => $data['mail_from'] ?? 'unknown',
                'auto_submitted' => $data['auto_submitted']
            ]);
            return;
        }

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
		
        // ETAPE 5: Human Reply Handling
        try {
            if ( get_option('pw_thread_enabled', false) ) {
                $in_reply_to = $data['in_reply_to'] ?? null;
                // Fix: 2a - Use in_reply_to directly, NOT headers['In-Reply-To']

                if ( !empty($in_reply_to) ) {
                    $this->handle_human_reply($data, $in_reply_to);
                    return; // Stop here if handled as thread to avoid double reply
                }
            }
        } catch ( \Throwable $e ) {
            Logger::error('handle_human_reply_failed', [
                'error' => $e->getMessage()
            ]);
        }

        // --- OLD LOGIC (Fallback / Non-Thread mode) ---
        // Only run if threads disabled OR if it wasn't a reply?
        // Instructions: "SI la personne répond... Le plugin détecte... SI pw_thread_enabled..."
        // If thread disabled, keep old logic? Or if it's not a reply (initial email to system)?
        // The old logic sends an Auto-Reply to ANY incoming email.
        // If thread enabled, we might still want auto-reply for FIRST email?
        // Instructions say: "Conflit Majeur. Cette logique doit être désactivée ou remplacée par handle_human_reply si les threads sont activés."
        // So if thread enabled, we should probably ONLY rely on handle_human_reply for REPLIES.
        // But what about the initial contact?
        // "4. Le plugin répond automatiquement via un template choisi... 5. SI la personne répond à cette réponse automatique..."
        // Step 4 is the OLD logic (Auto-reply to initial email).
        // Step 5 is the NEW logic (Reply to reply).
        // So we keep OLD logic for Step 4.

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

    private function handle_human_reply( array $payload, string $in_reply_to ): void {

        // Toutes les valeurs depuis get_option() — zéro hardcodé
        $max_exchanges   = (int)    get_option('pw_thread_max_exchanges', 3);
        $delay_min       = (int)    get_option('pw_thread_delay_min', 300);
        $delay_max       = (int)    get_option('pw_thread_delay_max', 1800);
        $suffix          = (string) get_option('pw_thread_template_suffix', '_reply');
        $tag_prefix      = (string) get_option('pw_thread_tag_prefix', 'warmup-reply');
        $fallback_tpl_id = (int)    get_option('pw_thread_fallback_template_id', 0);

        // Vérification auto_submitted already done in caller but good to double check
        if (!empty($payload['auto_submitted'])) return;

        global $wpdb;
        $table_history = $wpdb->prefix . 'postal_stats_history';

        // 1. Chercher message parent dans postal_stats_history
        // Use in_reply_to which matches the 'message_id' of the parent sent email
        // Note: Postal message_id usually looks like <uuid@postal.server>
        // Check if DB stores it with or without brackets? Logger usually logs raw.
        // Let's try explicit match.

        $parent = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_history WHERE message_id = %s OR message_id = %s LIMIT 1",
            $in_reply_to,
            '<' . trim($in_reply_to, '<>') . '>' // Try with brackets if missing
        ), ARRAY_A );

        if ( !$parent ) {
            // Not found in our history, maybe a reply to an email we didn't track or external?
            Logger::debug('thread_parent_not_found', ['in_reply_to' => $in_reply_to]);
            return;
        }

        // 2. Récupérer template original depuis contexte JSON
        $meta = json_decode( $parent['meta'], true );
        $original_template = $meta['template_name'] ?? null;

        if ( !$original_template ) {
            Logger::warning('thread_original_template_missing', ['parent_id' => $parent['id']]);
            return;
        }

        // If parent was already a reply (e.g. support_reply1), we need to extract the base name?
        // Or does the logic imply support -> support_reply1 -> support_reply2?
        // Instructions: "support → template 'support' ... Si elle répond ... support_reply2 ... support_reply3"
        // Wait, if I reply to 'support', the first reply is 'support'.
        // User replies to 'support'.
        // We detect 'support' is parent.
        // We count exchanges.
        // If count == 0 (just the root sent), next is 1?
        // "support_reply2" implies there was a 1?
        // Let's assume standard thread:
        // System sent "support" (Thread Start).
        // User replies.
        // System sends "support_reply1"? Or "support_reply2"?
        // Instructions: "Le plugin répond automatiquement via un template choisi selon le préfixe... (support -> support)" (Step 4)
        // This is the AUTO-REPLY.
        // So the flow is:
        // User -> System (Prefix: support). System sends Template "support".
        // User -> System (Reply to "support").
        // System detects reply to "support".
        // System sends "support_reply2"?

        // Let's simplify:
        // Base template: "support"
        // Reply 1: "support_reply1"
        // Reply 2: "support_reply2"

        // We need to know "How deep are we?".
        // "3. Compter échanges du thread dans postal_stats_history"
        // How to identify thread?
        // We can trace recursively via `in_reply_to` but Postal webhook payload gives only immediate parent.
        // OR we store `thread_id` in history? We don't have it yet.
        // Alternative: Look at the template name of the parent.
        // If parent is "support", we are at stage 1. Next is "support_reply1" (or reply2 per instructions?)
        // Instructions: "Template suivant ... (support_reply2)"
        // Maybe "support" IS reply1?
        // Let's deduce depth from template name.

        $base_name = $original_template;
        $current_depth = 0;

        // Check if parent name contains suffix
        if ( strpos($original_template, $suffix) !== false ) {
            // Extract number
            $parts = explode($suffix, $original_template);
            $base_name = $parts[0];
            $current_depth = (int) end($parts);
        } else {
            // Parent is root (depth 0 or 1 depending on counting)
            // If parent is "support", and it was an auto-reply to user's first mail.
            $current_depth = 1;
        }

        if ( $current_depth >= $max_exchanges ) {
            Logger::info('thread_max_reached', ['current' => $current_depth, 'max' => $max_exchanges]);
            return;
        }

        // 4. Numéro échange suivant
        $exchange_number = $current_depth + 1;

        // 5. Chercher template via TemplateManager existant
        $template_name = $base_name . $suffix . $exchange_number;

        $template = TemplateManager::get_template( $template_name ); // get_by_name doesn't exist in Manager? It's get_template.
        // Need to check strict existence (get_template returns false/null if not found? No, looks like it returns array)
        // Let's assume it returns null or empty if not found.
        // Actually Audit didn't flag this. Let's check TemplateManager in next step if needed or rely on standard usage.
        // Checking usage in AjaxHandler: get_template returns array or false.

        // 6. Fallback
        if ( !$template && $fallback_tpl_id > 0 ) {
             // Retrieve fallback by ID? TemplateManager doesn't have get_by_id public?
             // It usually works by name. But we have ID from option.
             // We might need to fetch name from ID first.
             $name_fallback = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}postal_templates WHERE id = %d", $fallback_tpl_id ) );
             if ( $name_fallback ) {
                 $template = TemplateManager::get_template( $name_fallback );
                 $template_name = $name_fallback; // Update name for logging
             }
        }

        if ( !$template ) {
            Logger::info('thread_no_template', [
                'looked_for' => $template_name
            ]);
            return;
        }

        // 7. Délai aléatoire humain
        $delay = rand($delay_min, $delay_max);
        $scheduled_at = date('Y-m-d H:i:s', time() + $delay);

        // 8. QueueManager::add() format IDENTIQUE à l'existant
        //    - template_id = celui trouvé
        //    - scheduled_at = NOW() + $delay (QueueManager accepts scheduled_at in meta? No.
        //      QueueManager::add calculates its own schedule based on settings.
        //      CONSTRAINT: "QueueManager::add() format IDENTIQUE à l'existant... Ne pas modifier QueueManager"
        //      BUT QueueManager::add logic: "$scheduled_at = ... rand(delay_min, delay_max) ... insert".
        //      It ignores passed schedule.
        //      Wait, Phase 1 Audit said: "Le threading nécessite des délais plus longs... S'assurer que scheduled_at est respecté"
        //      But "Ne pas modifier QueueManager".
        //      Dilemma.
        //      Workaround: Insert into DB manually? No, bad practice.
        //      Maybe QueueManager::add accepts a param I missed?
        //      Let's re-read QueueManager.php.
        //      It has: "$scheduled_at = current_time... if (delay_max > 0) ...". It does NOT accept an override.
        //      However, I can update the row immediately after adding it.
        //      $id = QueueManager::add(...);
        //      if ($id) $wpdb->update(..., ['scheduled_at' => $future], ['id' => $id]);

        // Gather data for Queue
        $to = $payload['mail_from'];
        $from = $payload['rcpt_to']; // Use the address they wrote to (e.g. support@...)
        $subject = 'Re: ' . $payload['subject'];

        // Template ID
        $template_id_val = $template['id'] ?? null;
        if (!$template_id_val && isset($template['name'])) {
             // Re-fetch ID if get_template didn't return it (it returns data blob usually + meta)
             // Actually get_template returns the full object often? No, usually just data.
             // Let's verify TemplateManager::get_template return.
             // If missing, we query ID.
             $template_id_val = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}postal_templates WHERE name = %s", $template_name ) );
        }

        $meta = [
            'thread_id' => $parent['message_id'] ?? $in_reply_to, // Link to parent
            'thread_depth' => $exchange_number,
            'template_name' => $template_name,
            'prefix' => $base_name, // Track chain root
            // Tag for Postal
            'tag' => $tag_prefix . '-' . $exchange_number
        ];

        $queue_id = QueueManager::add( $parent['server_id'], $to, $from, $subject, $meta );

        if ( $queue_id ) {
            // FORCE SCHEDULE UPDATE to respect thread delay
            global $wpdb;
            $table_queue = $wpdb->prefix . 'postal_queue';
            $wpdb->update( $table_queue, [ 'scheduled_at' => $scheduled_at ], [ 'id' => $queue_id ] );

            // 9. Logger
            Logger::info('human_reply_queued', [
                'exchange'  => $exchange_number,
                'template'  => $template_name,
                'delay_sec' => $delay,
                'from'      => $to,
            ]);
        }
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
