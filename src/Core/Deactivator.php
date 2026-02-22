<?php

namespace PostalWarmup\Core;

use PostalWarmup\Admin\Settings;
use PostalWarmup\Services\Logger;

/**
 * Fired during plugin deactivation.
 */
class Deactivator {

	public static function deactivate() {
		wp_clear_scheduled_hook( 'pw_cleanup_old_logs' );
		wp_clear_scheduled_hook( 'pw_cleanup_old_stats' );
		wp_clear_scheduled_hook( 'pw_daily_report' );

		if ( Settings::get( 'log_auto_purge_deactivation', false ) ) {
			Logger::clear_all_logs();
		}
	}
}
