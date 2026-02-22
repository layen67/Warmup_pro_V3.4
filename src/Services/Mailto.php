<?php

namespace PostalWarmup\Services;

use PostalWarmup\Models\Database;
use PostalWarmup\Services\TemplateLoader;
use PostalWarmup\Services\LoadBalancer;
use PostalWarmup\Services\ISPDetector;
use PostalWarmup\Core\TemplateEngine;

class Mailto {

	const DEFAULT_SUBJECTS = [
		'Bonjour',
		'Question',
		'Prise de contact',
		'Demande d\'information',
		'Renseignement',
	];

	const DEFAULT_BODIES = [
		"Bonjour,\n\nJe vous contacte pour un renseignement.\n\nCordialement",
		"Bonjour,\n\nPourriez-vous m'aider avec une question ?\n\nMerci d'avance",
		"Bonjour,\n\nJe souhaiterais obtenir plus d'informations.\n\nBien cordialement",
		"Bonjour,\n\nAvez-vous un moment pour répondre à une question ?\n\nCordialement",
	];

	public function init() {
		add_shortcode( 'postal_warmup', array( $this, 'render_shortcode' ) );
		add_shortcode( 'warmup_mailto', array( $this, 'render_shortcode' ) );
		add_shortcode( 'warmup_auto_link', array( $this, 'render_auto_link' ) );
		add_action( 'wp_ajax_pw_mailto_click', array( $this, 'track_click' ) );
		add_action( 'wp_ajax_nopriv_pw_mailto_click', array( $this, 'track_click' ) );
		// New AJAX endpoint for full link rotation
		add_action( 'wp_ajax_pw_get_mailto_url', array( $this, 'ajax_get_mailto_url' ) );
		add_action( 'wp_ajax_nopriv_pw_get_mailto_url', array( $this, 'ajax_get_mailto_url' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'pw-mailto', PW_PLUGIN_URL . 'public/js/mailto-tracker.js', array( 'jquery' ), PW_VERSION, true );
		wp_localize_script( 'pw-mailto', 'pwMailto', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'pw_mailto_click' )
		) );
	}

	public function render_shortcode( $atts, $content = null ) {
		// Support 'name' alias for 'template'
		if ( isset( $atts['name'] ) && empty( $atts['template'] ) ) {
			$atts['template'] = $atts['name'];
		}

		$atts = shortcode_atts( array(
			'template' => 'support',
			'name'     => '', // Alias for template
			'label'    => 'Nous contacter',
			'email'    => '', // Override destination email
			'subject'  => '', // Override subject
			'body'     => '', // Override body
			'style'    => '', // Custom CSS style
			'class'    => '', // Custom CSS class
			'track'    => 'true', // Enable click tracking
			'display'  => 'button', // link, button, badge
			'preset'   => 'primary', // primary, success, danger, warning, minimal, gradient
			'server'   => '', // Force a specific server domain
			'prefix'   => '', // Override local part of email
			'emails'   => '', // Comma-separated list of prefixes
			'subjects' => '', // Inline subject variants (semicolon separated)
			'bodies'   => '', // Inline body variants (pipe separated)
			'spintax'  => 'auto', // auto|true|false
			'rotate'   => 'false', // Enable JS rotation on hover
		), $atts, 'postal_warmup' );

		// 1. Load Template (Cached in TemplateLoader)
		$tpl = TemplateLoader::load( $atts['template'] );
		if ( ! $tpl ) {
			$tpl = TemplateLoader::get_fallback();
		}

		if ( ! $tpl ) return '<!-- Postal Warmup: Template not found -->';

		// 2. Select Server (Load Balancer)
		$server = null;
		if ( ! empty( $atts['server'] ) ) {
			$server = Database::get_server_by_domain( $atts['server'] );
		} else {
			$context = [ 'ignore_limits' => true ];
			if ( is_user_logged_in() ) {
				$user = wp_get_current_user();
				if ( ! empty( $user->user_email ) ) {
					$context['isp'] = ISPDetector::detect( $user->user_email );
				}
			}
			$server = LoadBalancer::select_server( $atts['template'], $context );
		}
		
		if ( ! $server ) {
			$server = [ 'id' => 0, 'domain' => 'system.local', 'metrics' => [ 'usage_today' => 0, 'limit' => 0 ] ];
		}

		// 3. Prepare Email Address (Prefix Logic)
		$email_prefix = 'contact';
		
		// Priority 1: 'prefix' param
		if ( ! empty( $atts['prefix'] ) ) {
			$email_prefix = $atts['prefix'];
		}
		// Priority 2: 'emails' param (random selection)
		elseif ( ! empty( $atts['emails'] ) ) {
			$prefixes = array_map( 'trim', explode( ',', $atts['emails'] ) );
			$prefixes = array_filter( $prefixes, fn($p) => preg_match('/^[a-z0-9._-]+$/i', $p) );
			if ( ! empty( $prefixes ) ) {
				$email_prefix = $prefixes[ array_rand( $prefixes ) ];
			}
		}
		// Priority 3: Template 'mailto_email_prefix' (New feature)
		elseif ( ! empty( $tpl['mailto_email_prefix'] ) ) {
			$email_prefix = $tpl['mailto_email_prefix'];
		}
		// Priority 4: Template name (if simple slug)
		elseif ( ! empty( $atts['template'] ) && preg_match( '/^[a-z0-9_.-]+$/i', $atts['template'] ) && $atts['template'] !== 'null' ) {
			$email_prefix = $atts['template'];
		}

		$email_to = ! empty( $atts['email'] ) ? $atts['email'] : $email_prefix . '@' . $server['domain'];

		// 4. Prepare Subject & Body
		$raw_subject_pool = $tpl['mailto_subject'] ?? [];
		$raw_body_pool    = $tpl['mailto_body']    ?? [];

		// Fallbacks if empty
		if ( empty( $raw_subject_pool ) ) $raw_subject_pool = self::DEFAULT_SUBJECTS;
		if ( empty( $raw_body_pool ) )    $raw_body_pool    = self::DEFAULT_BODIES;

		// Subject Priority: Shortcode 'subject' > Shortcode 'subjects' > Template (Weighted)
		$subject = '';
		if ( ! empty( $atts['subject'] ) ) {
			$subject = $atts['subject'];
		} elseif ( ! empty( $atts['subjects'] ) ) {
			$subjects_list = array_map( 'trim', explode( ';', $atts['subjects'] ) );
			$subject = $subjects_list[ array_rand( $subjects_list ) ];
		} else {
			$subject = TemplateLoader::pick_weighted( $raw_subject_pool );
		}

		// Body Priority: Shortcode 'body' > Shortcode 'bodies' > Template (Weighted)
		$body = '';
		if ( ! empty( $atts['body'] ) ) {
			$body = $atts['body'];
		} elseif ( ! empty( $atts['bodies'] ) ) {
			$bodies_list = array_map( 'trim', explode( '|', $atts['bodies'] ) );
			$body = $bodies_list[ array_rand( $bodies_list ) ];
		} else {
			$body = TemplateLoader::pick_weighted( $raw_body_pool );
		}

		// 5. Cleanup DB corruption (Backslashes)
		$subject = $this->clean_content( $subject );
		$body    = $this->clean_content( $body );

		// 6. Processing (Spintax -> Vars -> Encoding)
		$use_spintax = ( $atts['spintax'] === 'true' )
			|| ( $atts['spintax'] === 'auto' && ( ! empty( $tpl['spintax_enabled'] ) || strpos( $subject . $body, '{' ) !== false ) );

		if ( $use_spintax ) {
			$subject = TemplateEngine::process_spintax( $subject );
			$body    = TemplateEngine::process_spintax( $body );
		}

		$subject = $this->process_variables( $subject );
		$body    = $this->process_variables( $body );

		// Fix Encoding: Clean HTML -> Decode Entities -> Raw URL Encode
		// Removes manual newline replacement as rawurlencode handles %0A correctly
		$body_clean = wp_strip_all_tags( $body );
		$body_clean = html_entity_decode( $body_clean, ENT_QUOTES, 'UTF-8' );
		
		$mailto_url = 'mailto:' . sanitize_email( $email_to ) . 
		              '?subject=' . rawurlencode( $subject ) . 
		              '&body=' . rawurlencode( $body_clean );

		// 6. Label Logic
		if ( ! empty( $tpl['mailto_label'] ) ) {
			$atts['label'] = $tpl['mailto_label']; // New field alias
		} elseif ( ! empty( $tpl['default_label'] ) ) {
			$atts['label'] = $tpl['default_label'];
		} elseif ( ! empty( $content ) ) {
			$atts['label'] = do_shortcode( $content );
		}

		// 7. Rotation Data
		// Logic: If rotate=true, we pass data attributes. 
		// If emails list is provided, existing JS handles it locally.
		// If NO emails list but rotate=true, we might want full AJAX rotation (new feature).
		
		// If emails param is present, we prefer local rotation (faster).
		if ( $atts['rotate'] === 'true' && ! empty( $atts['emails'] ) ) {
			$atts['data-rotate-emails'] = $atts['emails'];
			$atts['data-server-domain'] = $server['domain'];
		} 
		// If rotate=true and NO local emails list, we use AJAX rotation for full refresh
		elseif ( $atts['rotate'] === 'true' ) {
			$atts['data-rotate'] = 'true'; // Used by JS to trigger AJAX
			// We need to pass nonce for the new AJAX action
			$atts['data-nonce'] = wp_create_nonce( 'pw_rotate' );
		}

		return $this->generate_html( $mailto_url, $atts, $server );
	}
	
	public function render_auto_link( $atts, $content = null ) {
		// Defaults specific to auto link
		$atts = shortcode_atts( [
			'template' => 'support',
			'track'    => 'false',
			'display'  => 'link',
			// Pass-through other potentially useful ones
			'prefix' => '', 'emails' => '', 'subject' => '', 'body' => '',
			'subjects' => '', 'bodies' => '', 'spintax' => 'auto'
		], $atts, 'warmup_auto_link' );
		
		// Re-use main render logic
		return $this->render_shortcode( $atts, $content );
	}

	public function build_mailto_url( $template_slug ) {
		// Public helper for AJAX usage
		// Replicates logic of render_shortcode but returns just the URL string
		
		$tpl = TemplateLoader::load( $template_slug );
		if ( ! $tpl ) $tpl = TemplateLoader::get_fallback();
		if ( ! $tpl ) return '';

		// Select Server
		$context = [ 'ignore_limits' => true ];
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( ! empty( $user->user_email ) ) $context['isp'] = ISPDetector::detect( $user->user_email );
		}
		$server = LoadBalancer::select_server( $template_slug, $context );
		if ( ! $server ) $server = [ 'domain' => 'system.local' ];

		// Prefix
		$email_prefix = 'contact';
		if ( ! empty( $tpl['mailto_email_prefix'] ) ) $email_prefix = $tpl['mailto_email_prefix'];
		elseif ( preg_match( '/^[a-z0-9_.-]+$/i', $template_slug ) ) $email_prefix = $template_slug;
		
		$email_to = $email_prefix . '@' . $server['domain'];

		// Subject & Body
		$raw_subject_pool = !empty($tpl['mailto_subject']) ? $tpl['mailto_subject'] : self::DEFAULT_SUBJECTS;
		$raw_body_pool    = !empty($tpl['mailto_body'])    ? $tpl['mailto_body']    : self::DEFAULT_BODIES;

		$subject = TemplateLoader::pick_weighted( $raw_subject_pool );
		$body    = TemplateLoader::pick_weighted( $raw_body_pool );

		// Cleanup
		$subject = $this->clean_content( $subject );
		$body    = $this->clean_content( $body );

		// Spintax
		if ( ! empty( $tpl['spintax_enabled'] ) || strpos( $subject . $body, '{' ) !== false ) {
			$subject = TemplateEngine::process_spintax( $subject );
			$body    = TemplateEngine::process_spintax( $body );
		}

		$subject = $this->process_variables( $subject );
		$body    = $this->process_variables( $body );

		$body_clean = wp_strip_all_tags( $body );
		$body_clean = html_entity_decode( $body_clean, ENT_QUOTES, 'UTF-8' );

		return 'mailto:' . sanitize_email( $email_to ) . 
		       '?subject=' . rawurlencode( $subject ) . 
		       '&body=' . rawurlencode( $body_clean );
	}

	public function ajax_get_mailto_url() {
		check_ajax_referer( 'pw_rotate', 'nonce' );
		$template = sanitize_key( $_POST['template'] ?? 'support' );
		$url = $this->build_mailto_url( $template );
		wp_send_json_success( [ 'url' => $url ] );
	}

	private function get_server_random( $force_domain = '' ) {
		if ( ! empty( $force_domain ) ) {
			return Database::get_server_by_domain( $force_domain );
		}
		
		$servers = Database::get_servers( true ); // Only active
		if ( empty( $servers ) ) return null;

		// Sort by usage (least used first)
		usort( $servers, function( $a, $b ) {
			return $a['sent_count'] - $b['sent_count'];
		} );

		// Pick from top 3
		$pool_size = min( 3, count( $servers ) );
		$pool = array_slice( $servers, 0, $pool_size );
		
		return $pool[ array_rand( $pool ) ];
	}

	private function process_variables( $text ) {
		if ( empty( $text ) ) return $text;
		
		$variables = [
			'{{page_title}}' => get_the_title(),
			'{{page_url}}'   => get_permalink(),
			'{{site_name}}'  => get_bloginfo( 'name' ),
			'{{site_url}}'   => get_bloginfo( 'url' ),
			'{{date}}'       => current_time( 'd/m/Y' ),
			'{{time}}'       => current_time( 'H:i' ),
			'{{year}}'       => current_time( 'Y' ),
			// Natural Variables
			'{{heure_fr}}'     => current_time( 'H\hi' ),
			'{{jour_semaine}}' => date_i18n( 'l' ),
			'{{mois}}'         => date_i18n( 'F' ),
			'{{civilite}}'     => ( (int) current_time( 'H' ) >= 5 && (int) current_time( 'H' ) < 18 ) ? 'Bonjour' : 'Bonsoir',
			'{{ref}}'          => strtoupper( wp_generate_password( 8, false ) ),
		];

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$variables['{{user_name}}'] = $user->display_name;
			$variables['{{user_email}}'] = $user->user_email;
			$variables['{{user_firstname}}'] = $user->first_name;
			$variables['{{user_lastname}}'] = $user->last_name;
			$variables['{{prénom}}'] = $user->first_name; // Alias
			$variables['{{prenom}}'] = $user->first_name; // Alias sans accent
		} else {
			$variables['{{user_name}}'] = '';
			$variables['{{user_email}}'] = '';
			$variables['{{user_firstname}}'] = '';
			$variables['{{user_lastname}}'] = '';
			$variables['{{prénom}}'] = '';
			$variables['{{prenom}}'] = '';
		}

		return str_replace( array_keys( $variables ), array_values( $variables ), $text );
	}

	/**
	 * Nettoie le contenu (strip slashes récursif pour réparer les données corrompues)
	 */
	private function clean_content( $text ) {
		if ( empty( $text ) ) return $text;
		
		// Unslash jusqu'à ce que ce soit propre (max 5 niveaux pour éviter boucle infinie)
		// Utile si les données ont été sauvegardées plusieurs fois avec des slashes
		$limit = 5;
		while ( strpos( $text, '\\' ) !== false && $limit > 0 ) {
			$original = $text;
			$text = wp_unslash( $text );
			if ( $original === $text ) break; // Plus rien à nettoyer
			$limit--;
		}
		
		return $text;
	}

	private function generate_html( $mailto_url, $atts, $server ) {
		$label = esc_html( $atts['label'] );
		
		$display = $atts['display'];
		$custom_style = $atts['style'];
		$custom_class = $atts['class'];
		$preset = $atts['preset'];
		$track = $atts['track'] === 'true';

		// Server Health Check for CSS
		// Note: LoadBalancer V3 uses 'lb_metrics', V2 used 'metrics'
		$metrics = $server['lb_metrics'] ?? ( $server['metrics'] ?? [] );
		
		// Map metrics
		$usage = $metrics['usage_today'] ?? ( $metrics['isp_usage'] ?? 0 );
		$limit = $metrics['limit'] ?? ( $metrics['isp_limit'] ?? 0 );
		$is_full = ( $limit > 0 && $usage >= $limit );
		
		$status_class = 'pw-server-ok';
		if ( $is_full ) $status_class = 'pw-server-full';
		elseif ( $limit > 0 && ($usage / $limit) > 0.8 ) $status_class = 'pw-server-warn';

		$preset_styles = [
			'primary' => 'background: #2271b1; color: white; padding: 12px 24px; border-radius: 4px; text-decoration: none; display: inline-block; font-weight: 600; transition: background 0.3s;',
			'success' => 'background: #46b450; color: white; padding: 12px 24px; border-radius: 4px; text-decoration: none; display: inline-block; font-weight: 600; transition: background 0.3s;',
			'danger'  => 'background: #dc3232; color: white; padding: 12px 24px; border-radius: 4px; text-decoration: none; display: inline-block; font-weight: 600; transition: background 0.3s;',
			'warning' => 'background: #f0b849; color: white; padding: 12px 24px; border-radius: 4px; text-decoration: none; display: inline-block; font-weight: 600; transition: background 0.3s;',
			'minimal' => 'background: transparent; color: #2271b1; padding: 12px 24px; border: 2px solid #2271b1; border-radius: 4px; text-decoration: none; display: inline-block; font-weight: 600; transition: all 0.3s;',
			'gradient'=> 'background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; display: inline-block; font-weight: 600; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4); transition: all 0.3s;',
		];

		if ( $display === 'link' ) {
			$base_style = 'color: #2271b1; text-decoration: underline;';
		} elseif ( $display === 'badge' ) {
			$base_style = 'background: #f0f6fc; color: #2271b1; padding: 4px 12px; border-radius: 12px; font-size: 12px; text-decoration: none; display: inline-block;';
		} else {
			$base_style = $preset_styles[$preset] ?? $preset_styles['primary'];
		}

		$final_style = $base_style . ( ! empty( $custom_style ) ? ' ' . $custom_style : '' );
		$classes = 'pw-mailto-link ' . $status_class . ( ! empty( $custom_class ) ? ' ' . esc_attr( $custom_class ) : '' );

		$data_attrs = '';
		if ( $track ) {
			$data_attrs = sprintf(
				'data-track="true" data-template="%s" data-server="%s"',
				esc_attr( $atts['template'] ),
				esc_attr( $server['domain'] )
			);
		}

		if ( isset( $atts['data-rotate-emails'] ) ) {
			$data_attrs .= sprintf(
				' data-rotate="true" data-rotate-emails="%s" data-server-domain="%s"',
				esc_attr( $atts['data-rotate-emails'] ),
				esc_attr( $atts['data-server-domain'] )
			);
		}
		
		// Tooltip Logic (Only for admins or debug mode?)
		// Allowing public visibility might leak server stats. 
		// The prompt asks to add it. Let's add it as 'title' attribute for now.
		$tooltip = sprintf(
			"Server: %s | Usage: %d/%s | ISP: %s",
			$server['domain'],
			$usage,
			$limit > 0 ? $limit : '∞',
			'Auto'
		);

		return sprintf(
			'<a href="%s" class="%s" style="%s" title="%s" %s>%s</a>',
			esc_url( $mailto_url ),
			$classes,
			esc_attr( $final_style ),
			esc_attr( $tooltip ),
			$data_attrs,
			$label
		);
	}

	public function track_click() {
		check_ajax_referer( 'pw_mailto_click', 'nonce' );
		
		$template = isset( $_POST['template'] ) ? sanitize_text_field( $_POST['template'] ) : '';
		$server_domain = isset( $_POST['server'] ) ? sanitize_text_field( $_POST['server'] ) : '';
		$page_url = isset( $_POST['page_url'] ) ? esc_url_raw( $_POST['page_url'] ) : '';
		
		if ( empty( $template ) || empty( $server_domain ) ) {
			wp_send_json_error( array( 'message' => 'Invalid data' ) );
		}
		
		global $wpdb;
		$table = $wpdb->prefix . 'postal_mailto_clicks';

		// GDPR : Anonymisation ou désactivation
		$ip_address = '';
		if ( ! get_option( 'pw_disable_ip_logging', false ) ) {
			$raw_ip = $this->get_client_ip();
			$ip_address = function_exists( 'wp_privacy_anonymize_ip' ) ? wp_privacy_anonymize_ip( $raw_ip ) : $raw_ip;
		}
		
		$result = $wpdb->insert( $table, array(
			'template'      => $template,
			'server_domain' => $server_domain,
			'page_url'      => $page_url,
			'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '',
			'ip_address'    => $ip_address,
			'clicked_at'    => current_time( 'mysql' )
		) );
		
		if ( $result ) {
			wp_send_json_success( array( 'message' => 'Click tracked' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Database error' ) );
		}
	}

	private function get_client_ip() {
		$ip = '';
		if ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) $ip = $_SERVER['HTTP_CLIENT_IP'];
		elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) $ip = $_SERVER['REMOTE_ADDR'];
		return sanitize_text_field( $ip );
	}

	public static function get_clicks_by_template( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'postal_mailto_clicks';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT template, COUNT(*) as total_clicks, COUNT(DISTINCT page_url) as pages_used, MAX(clicked_at) as last_click
			FROM $table
			WHERE clicked_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			GROUP BY template
			ORDER BY total_clicks DESC",
			$days
		), ARRAY_A );
	}

	public static function get_clicks_by_page( $days = 30, $limit = 10 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'postal_mailto_clicks';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT page_url, COUNT(*) as clicks
			FROM $table
			WHERE clicked_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			GROUP BY page_url
			ORDER BY clicks DESC
			LIMIT %d",
			$days,
			$limit
		), ARRAY_A );
	}

	public static function get_click_stats( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'postal_mailto_clicks';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(clicked_at) as click_date, COUNT(*) as clicks
			FROM $table
			WHERE clicked_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			GROUP BY click_date
			ORDER BY click_date ASC",
			$days
		), ARRAY_A );
	}
}
