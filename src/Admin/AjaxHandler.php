<?php

namespace PostalWarmup\Admin;

use PostalWarmup\Models\Database;
use PostalWarmup\Models\Stats;
use PostalWarmup\Services\Logger;
use PostalWarmup\API\Sender;
use PostalWarmup\API\Client;
use PostalWarmup\Admin\TemplateManager;
use PostalWarmup\Admin\Settings;

/**
 * Gestionnaire des requêtes AJAX
 */
class AjaxHandler {

	private function check_permission() {
		$cap = Settings::get( 'required_capability', 'manage_options' );
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ] );
			exit;
		}
	}

    // --- ETAPE 8: Nouvelles Actions Threads ---

    public function ajax_pw_create_chain_template() {
        check_ajax_referer( 'pw_admin_nonce', 'nonce' );
        $this->check_permission();

        $name = sanitize_text_field( $_POST['name'] ?? '' );
        if ( empty($name) ) wp_send_json_error( [ 'message' => 'Nom manquant' ] );

        // Create empty template
        $id = TemplateManager::save_template($name, [
            'subject' => ['Re: Sujet'],
            'text' => ['Réponse...'],
            'html' => ['<p>Réponse...</p>'],
            'from_name' => [],
            'mailto_subject' => [],
            'mailto_body' => [],
            'mailto_from_name' => [],
            'default_label' => ''
        ], [
            'folder_id' => TemplateManager::ensure_uncategorized_folder(),
            'status' => 'active'
        ]);

        if ( is_wp_error($id) ) wp_send_json_error( [ 'message' => $id->get_error_message() ] );

        wp_send_json_success( [ 'id' => $id, 'name' => $name, 'message' => 'Template créé' ] );
    }

    public function ajax_pw_copy_template() {
        check_ajax_referer( 'pw_admin_nonce', 'nonce' );
        $this->check_permission();

        $source_id = (int) $_POST['source_id'];
        $target_id = (int) $_POST['target_id'];

        // Get source data
        global $wpdb;
        $table = $wpdb->prefix . 'postal_templates';
        $source = $wpdb->get_row( $wpdb->prepare("SELECT data FROM $table WHERE id = %d", $source_id), ARRAY_A );

        if ( !$source ) wp_send_json_error(['message' => 'Source introuvable']);

        $wpdb->update($table, ['data' => $source['data']], ['id' => $target_id]);

        wp_send_json_success(['message' => 'Contenu copié']);
    }

    public function ajax_pw_duplicate_chain() {
        check_ajax_referer( 'pw_admin_nonce', 'nonce' );
        $this->check_permission();
        // Not implemented fully yet as complex logic required to clone multiple templates.
        wp_send_json_error(['message' => 'Non implémenté']);
    }

    public function ajax_pw_stop_thread() {
        check_ajax_referer( 'pw_admin_nonce', 'nonce' );
        $this->check_permission();

        $thread_id = sanitize_text_field($_POST['thread_id']);
        // Logic to stop thread (e.g. add to suppression or update meta)
        // For now, we don't have a "stop list" for threads specifically.
        // We could use a transient "pw_thread_stop_THREADID".
        set_transient('pw_thread_stop_' . $thread_id, true, 7 * DAY_IN_SECONDS);

        wp_send_json_success(['message' => 'Thread arrêté']);
    }

    public function ajax_pw_send_thread_now() {
        check_ajax_referer( 'pw_admin_nonce', 'nonce' );
        $this->check_permission();

        $queue_id = (int) $_POST['queue_id'];
        global $wpdb;
        $table = $wpdb->prefix . 'postal_queue';
        $wpdb->update($table, ['scheduled_at' => current_time('mysql')], ['id' => $queue_id]);

        // Trigger queue process immediately?
        \PostalWarmup\Services\QueueManager::process_queue();

        wp_send_json_success(['message' => 'Envoyé']);
    }

    public function ajax_send_test_email() {
        check_ajax_referer( 'pw_admin_nonce', 'nonce' );
        $this->check_permission();

        $to = sanitize_email( $_POST['email'] );
        $content = wp_unslash( $_POST['content'] );
        $subject = sanitize_text_field( $_POST['subject'] );
        $server_id = (int) $_POST['server_id'];

        if ( !is_email($to) ) wp_send_json_error(['message' => 'Email invalide']);

        $server = \PostalWarmup\Models\Database::get_server($server_id);
        if (!$server) wp_send_json_error(['message' => 'Serveur invalide']);

        // Send immediately (bypass queue for test)
        $payload = [
            'to' => [$to],
            'from' => 'Test <test@' . $server['domain'] . '>',
            'subject' => $subject,
            'plain_body' => strip_tags($content),
            'html_body' => $content
        ];

        // Using Sender's internal method via reflection or just call process_queue?
        // process_queue expects queued item or constructs it.
        // We can reuse Sender::send_request but it's private.
        // Public method test_connection does it.
        // Let's use Client::request directly as we are Admin testing.

        $result = Client::request($server_id, 'send/message', 'POST', $payload);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success(['message' => 'Envoyé ! ID: ' . ($result['message_id'] ?? 'N/A')]);
        }
    }

    // ... (Existing methods below)

	public function ajax_test_server() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		try {
			$server_id = (int) $_POST['server_id'];
			$result = Sender::test_connection( $server_id );
			
			if ( $result['success'] ) {
				wp_send_json_success( $result );
			} else {
				wp_send_json_error( $result );
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'Exception lors du test serveur : ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => 'Erreur critique : ' . $e->getMessage() ] );
		}
	}

	public function ajax_regenerate_secret() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		$secret = wp_generate_password( 64, false );
		update_option( 'pw_webhook_secret', $secret );
		wp_send_json_success( [ 'secret' => $secret, 'message' => __( 'Secret régénéré.', 'postal-warmup' ) ] );
	}

	public function ajax_get_dashboard_data() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		$days = isset( $_POST['days'] ) ? (int) $_POST['days'] : 7;
		
		$logs = Database::get_enriched_activity( 15 );
		$summary = Stats::get_dashboard_stats();
		$chart = Stats::get_global_stats( $days );
		$errors = Stats::get_recent_errors( 5 );
        $threads = Stats::get_recent_threads( 5 ); // New
		
		wp_send_json_success( [ 
			'logs' => $logs, 
			'summary' => $summary, 
			'chart' => $chart,
			'errors' => $errors,
            'threads' => $threads // New
		] );
	}

	public function ajax_clear_logs() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		Logger::clear_all_logs();
		wp_send_json_success( [ 'message' => __( 'Logs supprimés.', 'postal-warmup' ) ] );
	}

	public function ajax_get_all_templates() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		wp_send_json_success( [ 'templates' => TemplateManager::get_all_with_meta() ] );
	}

	public function ajax_save_template() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		// Fix: Unslash POST data before processing to prevent backslash accumulation
		$variants = isset($_POST['variants']) ? wp_unslash($_POST['variants']) : [];
		
		$name = sanitize_text_field( $_POST['name'] ?? '' );
		$data = [
			'subject'   => array_map( 'sanitize_text_field', $variants['subject'] ?? [] ),
			'text'      => array_map( 'sanitize_textarea_field', $variants['text'] ?? [] ),
			// Fix HTML preservation: Use stripslashes to handle magic quotes but avoid wp_kses/sanitize to keep raw HTML intact
			'html'      => $variants['html'] ?? [], // Already unslashed
			'from_name' => array_map( 'sanitize_text_field', $variants['from_name'] ?? [] ),
			'mailto_subject'   => array_map( 'sanitize_text_field', $variants['mailto_subject'] ?? [] ),
			'mailto_body'      => array_map( 'sanitize_textarea_field', $variants['mailto_body'] ?? [] ),
			'mailto_from_name' => array_map( 'sanitize_text_field', $variants['mailto_from_name'] ?? [] ),
			'default_label' => sanitize_text_field( $_POST['default_label'] ?? '' ),
		];
		$meta = [
			'id'        => (int) ( $_POST['id'] ?? 0 ),
			'folder_id' => (int) ( $_POST['folder_id'] ?? 0 ),
			'status'    => sanitize_text_field( $_POST['status'] ?? 'active' ),
			'tags'      => array_map( 'sanitize_text_field', explode( ',', $_POST['tags'] ?? '' ) ),
			'timezone'  => sanitize_text_field( $_POST['timezone'] ?? '' )
		];
		
		$result = TemplateManager::save_template( $name, $data, $meta );
		if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		else wp_send_json_success( [ 'message' => 'Saved' ] );
	}

	public function ajax_delete_template() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		$result = TemplateManager::delete_template( sanitize_text_field( $_POST['name'] ) );
		if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		else wp_send_json_success();
	}

	public function ajax_duplicate_template() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		$name = sanitize_text_field( $_POST['name'] ?? '' );
		$new_name = sanitize_text_field( $_POST['new_name'] ?? '' );

		$result = TemplateManager::duplicate_template( $name, $new_name );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		} else {
			wp_send_json_success( [ 'id' => $result ] );
		}
	}
	
	public function ajax_get_template() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		$tpl = TemplateManager::get_template( sanitize_text_field( $_POST['name'] ) );
		if ( $tpl ) wp_send_json_success( $tpl );
		else wp_send_json_error( [ 'message' => 'Not found' ] );
	}

	public function ajax_get_template_stats() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		$name = sanitize_text_field( $_POST['template_name'] );
		$days = isset( $_POST['days'] ) ? (int) $_POST['days'] : 30;
		
		$stats = Stats::get_template_stats( $name, $days );
		
		wp_send_json_success( [ 'stats' => $stats ] );
	}

	public function ajax_save_category() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		$id = TemplateManager::save_category( 
			sanitize_text_field( $_POST['name'] ), 
			(int)$_POST['parent_id'], 
			sanitize_hex_color( $_POST['color'] ), 
			(int)$_POST['id'] 
		);
		wp_send_json_success( [ 'id' => $id ] );
	}

	public function ajax_delete_category() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		TemplateManager::delete_category( (int)$_POST['id'] );
		wp_send_json_success();
	}

	public function ajax_get_categories() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		wp_send_json_success( [ 'tree' => TemplateManager::get_folders_tree() ] );
	}

	public function ajax_toggle_favorite() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		TemplateManager::toggle_favorite( (int)$_POST['template_id'], filter_var( $_POST['favorite'], FILTER_VALIDATE_BOOLEAN ) );
		wp_send_json_success();
	}

	public function ajax_move_template() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		TemplateManager::move_template( (int)$_POST['template_id'], (int)$_POST['folder_id'] );
		wp_send_json_success();
	}

	public function ajax_update_template_status() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		TemplateManager::update_status( (int)$_POST['template_id'], sanitize_key( $_POST['status'] ) );
		wp_send_json_success();
	}

	public function ajax_get_template_versions() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		$versions = TemplateManager::get_versions( (int)$_POST['template_id'] );
		wp_send_json_success( [ 'versions' => $versions ] );
	}

	public function ajax_restore_template_version() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		$result = TemplateManager::restore_version( (int)$_POST['version_id'] );
		if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		else wp_send_json_success();
	}

	public function ajax_clear_cache() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		global $wpdb;
		$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_pw_%'" );
		$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_pw_%'" );
		
		wp_send_json_success( [ 'message' => __( 'Cache vidé.', 'postal-warmup' ) ] );
	}

	public function ajax_export_stats() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();

		global $wpdb;
		$stats_table = $wpdb->prefix . 'postal_stats';
		$servers_table = $wpdb->prefix . 'postal_servers';
		
		$results = $wpdb->get_results( "
			SELECT s.date, s.hour, s.sent_count, s.success_count, s.error_count, s.avg_response_time, sv.domain
			FROM $stats_table s
			LEFT JOIN $servers_table sv ON s.server_id = sv.id
			ORDER BY s.date DESC, s.hour DESC
		", ARRAY_A );

		$filename = 'postal-stats-' . date('Y-m-d') . '.csv';
		
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		
		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, [ 'Date', 'Hour', 'Server', 'Sent', 'Success', 'Error', 'Avg Latency (ms)' ] );
		
		foreach ( $results as $row ) {
			fputcsv( $output, $row );
		}
		
		fclose( $output );
		exit;
	}

	public function ajax_reorder_templates() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		wp_send_json_success();
	}

	public function ajax_bulk_action_templates() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		wp_send_json_success();
	}

	public function ajax_export_template() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();

		$name = sanitize_text_field( $_POST['name'] );
		$template = TemplateManager::get_template( $name );
		
		if ( ! $template ) wp_send_json_error( [ 'message' => 'Template not found' ] );
		
		$filename = $name . '.json';
		
		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		
		echo json_encode( $template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public function ajax_import_templates() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();

		$file = $_FILES['file'] ?? ( $_FILES['import_file'] ?? null );

		if ( empty( $file ) ) wp_send_json_error( [ 'message' => 'No file uploaded' ] );
		
		$content = file_get_contents( $file['tmp_name'] );
		$data = json_decode( $content, true );
		
		if ( ! $data ) wp_send_json_error( [ 'message' => 'Invalid JSON' ] );
		
		// Validation stricte de la structure
		$required = [ 'subject', 'text', 'html' ]; // Basic requirements
		foreach ( $required as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				wp_send_json_error( [ 'message' => "Missing field: $field" ] );
			}
		}
		
		// Validation des types (Doit être array pour les variants)
		if ( ! is_array( $data['subject'] ) || ! is_array( $data['text'] ) || ! is_array( $data['html'] ) ) {
			wp_send_json_error( [ 'message' => 'Invalid format: fields must be arrays' ] );
		}
		
		$name = sanitize_title( pathinfo( $file['name'], PATHINFO_FILENAME ) );
		$uncat = TemplateManager::ensure_uncategorized_folder();
		
		$meta = [
			'id' => 0,
			'folder_id' => $uncat,
			'status' => 'active',
			'tags' => []
		];
		
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}postal_templates WHERE name = %s", $name ) );
		if ( $exists ) $name .= '-' . time();
		
		TemplateManager::save_template( $name, $data, $meta );
		wp_send_json_success( [ 'message' => 'Imported as ' . $name ] );
	}

	public function ajax_get_suppression_list() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		$server_id = (int) $_POST['server_id'];
		$result = Client::request( $server_id, 'suppressions' );
		
		if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		else wp_send_json_success( [ 'list' => $result ] );
	}

	public function ajax_delete_suppression() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		$server_id = (int) $_POST['server_id'];
		$email = sanitize_email( $_POST['email'] );
		
		$result = Client::request( $server_id, 'suppressions/delete', 'POST', [ 'email' => $email ] );
		
		if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		else wp_send_json_success();
	}

	public function ajax_get_server_health() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		$server_id = (int) $_POST['server_id'];
		$start = microtime( true );
		$result = Client::request( $server_id, 'messages', 'GET', [ 'count' => 1 ] ); // Light request
		$duration = round( ( microtime( true ) - $start ) * 1000, 2 );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'status' => 'error', 'latency' => $duration, 'message' => $result->get_error_message() ] );
		} else {
			wp_send_json_success( [ 'status' => 'ok', 'latency' => $duration, 'message' => 'Connected' ] );
		}
	}

	public function ajax_get_advanced_stats() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();

		$days = isset( $_POST['days'] ) ? (int) $_POST['days'] : 30;

		$charts = Stats::get_advanced_charts_data( $days );
		$heatmap = Stats::get_heatmap_data( $days );

		wp_send_json_success( [ 'charts' => $charts, 'heatmap' => $heatmap ] );
	}

	public function ajax_get_stats_table() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();

		// Deprecated for new accordion, but kept if needed for fallback? 
		// Actually, we replace it with get_server_detail as per plan.
		// But let's add the new one and remove this old call.
		// Wait, frontend still calls this? I will update frontend.
		
		wp_send_json_error( [ 'message' => 'Endpoint deprecated. Use pw_get_server_detail.' ] );
	}

	public function ajax_get_server_detail() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();

		$server_id = (int) $_POST['server_id'];
		$days = isset( $_POST['days'] ) ? (int) $_POST['days'] : 30;
		
		if ( ! $server_id ) wp_send_json_error( [ 'message' => 'Missing server ID' ] );

		$stats = Stats::get_server_detail_breakdown( $server_id, $days );
		
		wp_send_json_success( [ 'stats' => $stats ] );
	}

	public function ajax_process_queue_manual() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		\PostalWarmup\Services\QueueManager::process_queue();
		
		wp_send_json_success( [ 'message' => 'File d\'attente traitée' ] );
	}

	public function ajax_save_isp() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		// Map $_POST to ISPManager::save expected format
		// Note: The form sends 'isp_label', 'domains' (string), etc.
		
		$result = ISPManager::save( $_POST );
		
		if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		else wp_send_json_success( [ 'id' => $result ] );
	}

	public function ajax_delete_isp() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		ISPManager::delete( (int)$_POST['id'] );
		wp_send_json_success();
	}

	public function ajax_save_strategy() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		$result = StrategyManager::save( $_POST );
		
		if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		else wp_send_json_success( [ 'id' => $result ] );
	}

	public function ajax_delete_strategy() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		StrategyManager::delete( (int)$_POST['id'] );
		wp_send_json_success();
	}

	public function ajax_test_webhook() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();

		$url = isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : '';
		
		if ( empty( $url ) ) wp_send_json_error( [ 'message' => 'Veuillez saisir une URL valide.' ] );
		
		$result = \PostalWarmup\Services\WebhookDispatcher::send_test( $url );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => 'Erreur : ' . $result->get_error_message() ] );
		}
		
		$code = wp_remote_retrieve_response_code( $result );
		$body = wp_remote_retrieve_body( $result );
		
		if ( $code >= 200 && $code < 300 ) {
			wp_send_json_success( [ 'message' => "Succès ! Le serveur a répondu HTTP $code." ] );
		} else {
			wp_send_json_error( [ 'message' => "Échec : Le serveur a répondu HTTP $code. ($body)" ] );
		}
	}

	public function ajax_run_domscan_audit() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();

		$domain = sanitize_text_field( $_POST['domain'] );
		
		if ( empty( $domain ) ) wp_send_json_error( [ 'message' => 'Domaine manquant' ] );
		
		$result = \PostalWarmup\Services\DomScanService::audit_domain( $domain );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		} else {
			wp_send_json_success( [ 'data' => $result ] );
		}
	}

	public function ajax_render_preview() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();

		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';
		$context_type = sanitize_key( $_POST['context_type'] ?? 'male' );
		
		// Base context
		$context = [
			'email'        => 'jean.dupont@example.com',
			'domain'       => 'example.com',
			'local'        => 'jean.dupont',
			'date'         => current_time( 'd/m/Y' ),
			'time'         => current_time( 'H:i' ),
			'heure_fr'     => current_time( 'H\hi' ),
			'jour_semaine' => date_i18n( 'l' ),
			'mois'         => date_i18n( 'F' ),
			'civilite'     => ( (int) current_time( 'H' ) >= 18 || (int) current_time( 'H' ) < 5 ) ? 'Bonsoir' : 'Bonjour',
			'ref'          => 'REF-' . strtoupper( substr( md5( uniqid() ), 0, 8 ) ),
			'site_url'     => get_site_url(),
			'site_name'    => get_bloginfo( 'name' ),
			'admin_email'  => get_option( 'admin_email' ),
			'user_ip'      => '192.168.1.1',
			'user_agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
		];

		// Context simulation
		switch ( $context_type ) {
			case 'female':
				$context['email'] = 'marie.curie@example.com';
				$context['local'] = 'marie.curie';
				$context['prenom'] = 'Marie';
				$context['prénom'] = 'Marie';
				break;
			case 'company':
				$context['email'] = 'contact@societe.com';
				$context['local'] = 'contact';
				$context['prenom'] = 'L\'équipe';
				$context['prénom'] = 'L\'équipe';
				break;
			case 'male':
			default:
				$context['email'] = 'jean.dupont@example.com';
				$context['local'] = 'jean.dupont';
				$context['prenom'] = 'Jean';
				$context['prénom'] = 'Jean';
				break;
		}
		
		$rendered = \PostalWarmup\Core\TemplateEngine::render_string( $content, $context );
		
		wp_send_json_success( [ 'rendered' => $rendered ] );
	}

	public function ajax_export_settings() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();

		$settings = get_option( 'pw_settings', [] );
		$filename = 'pw-settings-' . date('Y-m-d') . '.json';

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		
		echo json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public function ajax_import_settings() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();

		$file = $_FILES['file'] ?? null;
		if ( empty( $file ) ) wp_send_json_error( [ 'message' => 'No file uploaded' ] );

		$content = file_get_contents( $file['tmp_name'] );
		$data = json_decode( $content, true );

		if ( ! is_array( $data ) ) wp_send_json_error( [ 'message' => 'Invalid JSON' ] );

		// Validate known keys to prevent garbage injection
		// We use sanitization from Settings class
		$settings_instance = new Settings();
		$sanitized = $settings_instance->sanitize_settings( $data );

		update_option( 'pw_settings', $sanitized );
		
		wp_send_json_success( [ 'message' => 'Settings imported successfully.' ] );
	}

	public function ajax_reset_settings() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();

		delete_option( 'pw_settings' );
		// Trigger migration to restore defaults
		$settings_instance = new Settings();
		$settings_instance->register_settings(); // Triggers migration/defaults

		wp_send_json_success( [ 'message' => 'Settings reset to defaults.' ] );
	}
	
	public function ajax_purge_all_data() {
		check_ajax_referer( 'pw_admin_nonce', 'nonce' );
		$this->check_permission();
		
		global $wpdb;
		// Purge Logs, Queue, Stats
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}postal_logs" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}postal_queue" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}postal_stats" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}postal_stats_daily" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}postal_stats_history" );
		
		wp_send_json_success( [ 'message' => 'All data purged.' ] );
	}
}
