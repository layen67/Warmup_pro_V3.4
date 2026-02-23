<?php

namespace PostalWarmup\Admin;

use PostalWarmup\Models\Database;
use PostalWarmup\Models\Stats;
use PostalWarmup\Services\Logger;
use PostalWarmup\Services\Encryption;
use PostalWarmup\API\Sender;
use PostalWarmup\API\Client;
use PostalWarmup\Admin\Settings;

/**
 * Classe de l'interface d'administration
 */
class Admin {

	private $version;

	public function __construct( $version ) {
		$this->version = $version;
	}

	public function add_admin_menu() {
		$menu_name = get_option( 'pw_wl_menu_name', __( 'Postal Warmup', 'postal-warmup' ) );
		$icon = get_option( 'pw_wl_menu_icon', 'dashicons-email-alt' );

		add_menu_page(
			$menu_name,
			$menu_name,
			'manage_postal_warmup',
			'postal-warmup',
			[ $this, 'display_dashboard' ],
			$icon,
			26
		);
		add_submenu_page( 'postal-warmup', __( 'Tableau de bord', 'postal-warmup' ), __( 'Tableau de bord', 'postal-warmup' ), 'manage_postal_warmup', 'postal-warmup', [ $this, 'display_dashboard' ] );
		add_submenu_page( 'postal-warmup', __( 'Serveurs', 'postal-warmup' ), __( 'Serveurs', 'postal-warmup' ), 'manage_postal_warmup', 'postal-warmup-servers', [ $this, 'display_servers' ] );
		add_submenu_page( 'postal-warmup', __( 'File d\'attente', 'postal-warmup' ), __( 'File d\'attente', 'postal-warmup' ), 'manage_postal_warmup', 'postal-warmup-queue', [ $this, 'display_queue' ] );
		add_submenu_page( 'postal-warmup', __( 'Templates', 'postal-warmup' ), __( 'Templates', 'postal-warmup' ), 'manage_postal_warmup', 'postal-warmup-templates', [ $this, 'display_templates' ] );
		add_submenu_page( 'postal-warmup', __( 'Statistiques', 'postal-warmup' ), __( 'Statistiques', 'postal-warmup' ), 'manage_postal_warmup', 'postal-warmup-stats', [ $this, 'display_stats' ] );
		add_submenu_page( 'postal-warmup', __( 'Stats Mailto', 'postal-warmup' ), __( 'Stats Mailto', 'postal-warmup' ), 'manage_postal_warmup', 'postal-warmup-mailto-stats', [ $this, 'display_mailto_stats' ] );
		add_submenu_page( 'postal-warmup', __( 'Logs', 'postal-warmup' ), __( 'Logs', 'postal-warmup' ), 'manage_postal_warmup', 'postal-warmup-logs', [ $this, 'display_logs' ] );
		add_submenu_page( 'postal-warmup', __( 'Paramètres', 'postal-warmup' ), __( 'Paramètres', 'postal-warmup' ), 'manage_postal_warmup', 'postal-warmup-settings', [ $this, 'display_settings' ] );
		add_submenu_page( 'postal-warmup', __( 'Gestion ISP', 'postal-warmup' ), __( 'Gestion ISP', 'postal-warmup' ), 'manage_postal_warmup', 'postal-warmup-isps', [ $this, 'display_isps' ] );
		add_submenu_page( 'postal-warmup', __( 'Stratégies', 'postal-warmup' ), __( 'Stratégies', 'postal-warmup' ), 'manage_postal_warmup', 'postal-warmup-strategies', [ $this, 'display_strategies' ] );
	}

	public function enqueue_styles( $hook ) {
		// Asset Optimization
		if ( Settings::get( 'assets_load_optimization', true ) ) {
			if ( strpos( $hook, 'postal-warmup' ) === false ) return;
		}
		
		$is_dev = defined('WP_DEBUG') && WP_DEBUG === true;
		$script_version = $is_dev ? time() : WARMUP_PRO_VERSION;

		wp_enqueue_style( 'pw-admin', PW_PLUGIN_URL . 'admin/assets/css/admin.css', [], $script_version );
		if ( strpos( $hook, 'postal-warmup-templates' ) !== false || $hook === 'toplevel_page_postal-warmup' ) {
			wp_enqueue_style( 'pw-templates', PW_PLUGIN_URL . 'admin/assets/css/templates.css', [ 'pw-admin' ], $script_version );
		}

		// Visual Customization Variables
		$primary = Settings::get( 'ui_color_primary', '#2271b1' );
		$success = Settings::get( 'ui_color_success', '#00a32a' );
		$warning = Settings::get( 'ui_color_warning', '#dba617' );
		$danger = Settings::get( 'ui_color_danger', '#d63638' );
		$density = Settings::get( 'table_density', 'normal' );
		$dark_mode = Settings::get( 'ui_dark_mode', 'auto' );

		$density_padding = '12px 16px';
		if ( $density === 'compact' ) $density_padding = '6px 10px';
		if ( $density === 'comfortable' ) $density_padding = '16px 20px';

		$css_vars = ":root {
			--pw-primary: $primary;
			--pw-primary-hover: " . $this->adjust_brightness( $primary, -15 ) . ";
			--pw-primary-light: " . $this->adjust_brightness( $primary, 150 ) . ";
			--pw-success: $success;
			--pw-warning: $warning;
			--pw-danger: $danger;
		}
		.pw-table th, .pw-table td { padding: $density_padding; }
		";

		// Dark Mode Logic
		if ( $dark_mode === 'always' ) {
			$css_vars .= "
			body { background: #1e1e1e; color: #e0e0e0; }
			.pw-card, .pw-stat-widget, .pw-table { background: #2d2d2d; border-color: #444; color: #e0e0e0; }
			.pw-table th { background: #252525; color: #aaa; border-bottom-color: #444; }
			.pw-table td { border-bottom-color: #444; color: #ccc; }
			h1, h2, h3, label { color: #fff; }
			input, select, textarea { background: #333 !important; border-color: #555 !important; color: #fff !important; }
			";
		}

		wp_add_inline_style( 'pw-admin', $css_vars );

		// White Label Custom CSS
		$custom_css = get_option( 'pw_wl_custom_css' );
		if ( ! empty( $custom_css ) ) {
			wp_add_inline_style( 'pw-admin', strip_tags( $custom_css ) );
		}

		// Hide Footer Text if White Label enabled
		if ( get_option( 'pw_wl_hide_footer', false ) ) {
			add_filter( 'admin_footer_text', '__return_empty_string', 999 );
			add_filter( 'update_footer', '__return_empty_string', 999 );
		}
	}

	public function enqueue_scripts( $hook ) {
		// Asset Optimization
		if ( Settings::get( 'assets_load_optimization', true ) ) {
			if ( strpos( $hook, 'postal-warmup' ) === false ) return;
		}
		
		$is_dev = defined('WP_DEBUG') && WP_DEBUG === true;
		$script_version = $is_dev ? time() : WARMUP_PRO_VERSION;

		if ( file_exists( PW_PLUGIN_DIR . 'admin/assets/js/chart.umd.min.js' ) ) {
			wp_enqueue_script( 'pw-chartjs', PW_PLUGIN_URL . 'admin/assets/js/chart.umd.min.js', [], '4.4.0', true );
		} else {
			wp_enqueue_script( 'pw-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true );
		}

		if ( strpos( $hook, 'postal-warmup-stats' ) !== false ) {
			wp_enqueue_script( 'pw-jspdf', 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js', [], '2.5.1', true );
			wp_enqueue_script( 'pw-html2canvas', 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', [], '1.4.1', true );
		}
		
		wp_enqueue_script( 'pw-admin', PW_PLUGIN_URL . 'admin/assets/js/admin.js', [ 'jquery', 'pw-chartjs' ], $script_version, true );
		
		if ( strpos( $hook, 'postal-warmup-templates' ) !== false || $hook === 'toplevel_page_postal-warmup' ) {
			wp_enqueue_script( 'pw-templates', PW_PLUGIN_URL . 'admin/assets/js/templates-manager-v3.1.js', [ 'jquery', 'pw-admin' ], $script_version, true );

            // Phase 2 Step 6: Chains Tab Logic (inline or separate file?)
            // We can add it to templates-manager-v3.1.js or create a new file.
            // Let's assume logic goes into templates-manager or new script if complex.
            // For now, enqueuing existing script is enough if we extend it.
		}

		if ( strpos( $hook, 'postal-warmup-strategies' ) !== false ) {
			wp_enqueue_script( 'pw-strategies', PW_PLUGIN_URL . 'admin/assets/js/strategies.js', [ 'jquery', 'pw-chartjs', 'pw-admin' ], $script_version, true );
		}

		$uncategorized_id = TemplateManager::ensure_uncategorized_folder();

		wp_localize_script( 'pw-admin', 'pwAdmin', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'pw_admin_nonce' ),
			'uncategorized_id' => $uncategorized_id,
			'i18n'    => [
				'confirm_delete' => __( 'Êtes-vous sûr de vouloir supprimer cet élément ?', 'postal-warmup' ),
				'testing'        => __( 'Test en cours...', 'postal-warmup' ),
				'success'        => __( 'Succès !', 'postal-warmup' ),
				'error'          => __( 'Erreur !', 'postal-warmup' ),
			],
            'threads_enabled' => get_option('pw_thread_enabled', false),
		]);
	}

	// Views
	public function display_dashboard() { require_once PW_ADMIN_DIR . 'partials/dashboard.php'; }
	public function display_servers() { require_once PW_ADMIN_DIR . 'partials/servers.php'; }
	public function display_queue() { require_once PW_ADMIN_DIR . 'partials/queue.php'; }
	public function display_templates() { require_once PW_ADMIN_DIR . 'partials/templates.php'; }
	public function display_stats() { require_once PW_ADMIN_DIR . 'partials/stats.php'; }
	public function display_mailto_stats() { require_once PW_ADMIN_DIR . 'partials/mailto-stats.php'; }
	public function display_logs() { require_once PW_ADMIN_DIR . 'partials/logs.php'; }
	public function display_settings() { require_once PW_ADMIN_DIR . 'partials/settings.php'; }
	public function display_isps() { require_once PW_ADMIN_DIR . 'partials/isps.php'; }
	public function display_strategies() { require_once PW_ADMIN_DIR . 'partials/strategies.php'; }

	public function display_admin_notices() {
		if ( get_transient( 'pw_activation_notice' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>Postal Warmup Pro activé !</strong> Ajoutez vos serveurs.</p></div>';
			delete_transient( 'pw_activation_notice' );
		}
	}

	private function adjust_brightness($hex, $steps) {
		$steps = max(-255, min(255, $steps));
		$hex = str_replace('#', '', $hex);
		if (strlen($hex) == 3) $hex = str_repeat(substr($hex,0,1), 2).str_repeat(substr($hex,1,1), 2).str_repeat(substr($hex,2,1), 2);
		$color_parts = str_split($hex, 2);
		$return = '#';
		foreach ($color_parts as $color) {
			$color = hexdec($color);
			$color = max(0, min(255, $color + $steps));
			$return .= str_pad(dechex($color), 2, '0', STR_PAD_LEFT);
		}
		return $return;
	}
}
