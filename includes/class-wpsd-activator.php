<?php
/**
 * Activation: tables, roles, defaults, seed.
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_Activator
 */
class WPSD_Activator {

	/**
	 * Run activation tasks.
	 */
	public static function activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		WPSD_DB::create_tables();
		WPSD_Roles::activate();
		WPSD_DB::maybe_seed();

		add_option( 'wpsd_public_rate_per_ip', 10 );
		add_option( 'wpsd_public_rate_per_mobile', 5 );
		add_option( 'wpsd_uninstall_cleanup', 0 );

		flush_rewrite_rules();
		delete_transient( 'wpsd_dashboard_stats' );
	}
}
