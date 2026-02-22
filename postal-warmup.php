<?php
/**
 * Plugin Name: Postal Warmup Pro
 * Plugin URI: https://elianova.com/postal-warmup
 * Description: Plugin professionnel de warmup multi-serveurs Postal avec gestion avancée des templates, statistiques détaillées et monitoring en temps réel.
 * Version: 3.4.0
 * Requires at least: 5.8
 * Requires PHP: 8.1
 * Author: Elianova
 * Author URI: https://elianova.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: postal-warmup
 * Domain Path: /languages
 */

use PostalWarmup\Core\Plugin;
use PostalWarmup\Core\Activator;
use PostalWarmup\Core\Deactivator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constantes
define( 'PW_VERSION', '3.4.0' );
define( 'WARMUP_PRO_VERSION', '3.4.0' ); // Alias for script versioning
define( 'PW_PLUGIN_FILE', __FILE__ );
define( 'PW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PW_TEMPLATES_DIR', PW_PLUGIN_DIR . 'templates/' );
define( 'PW_ADMIN_DIR', PW_PLUGIN_DIR . 'admin/' ); // Maintain admin dir for assets and views if needed

// Autoloading
if ( file_exists( PW_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once PW_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	add_action( 'admin_notices', function() {
		?>
		<div class="notice notice-error">
			<p><strong>Postal Warmup Pro :</strong> Les dépendances Composer sont manquantes. Veuillez exécuter <code>composer install</code>.</p>
		</div>
		<?php
	});
	return;
}

// Initialisation d'Action Scheduler (si présent dans vendor)
if ( file_exists( PW_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once PW_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

/**
 * Activation
 */
function activate_postal_warmup() {
	Activator::activate();
}
register_activation_hook( __FILE__, 'activate_postal_warmup' );

/**
 * Désactivation
 */
function deactivate_postal_warmup() {
	Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'deactivate_postal_warmup' );

/**
 * Démarrage
 */
function run_postal_warmup() {
	if ( ! class_exists( 'PostalWarmup\Core\Plugin' ) ) {
		// Log error if possible or just exit silently to avoid white screen
		error_log( 'Postal Warmup Pro: Plugin class not found. Autoload issue?' );
		return;
	}

	try {
		$plugin = new Plugin();
		$plugin->run();
	} catch ( \Throwable $e ) {
		error_log( 'Postal Warmup Pro Critical Error: ' . $e->getMessage() );
		// Optional: Show admin notice if in admin area
		if ( is_admin() ) {
			add_action( 'admin_notices', function() use ($e) {
				?>
				<div class="notice notice-error">
					<p><strong>Postal Warmup Pro :</strong> Une erreur critique est survenue lors du chargement : <?php echo esc_html( $e->getMessage() ); ?></p>
				</div>
				<?php
			});
		}
	}
}
run_postal_warmup();
