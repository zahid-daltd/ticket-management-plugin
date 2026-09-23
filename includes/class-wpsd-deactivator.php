<?php
/**
 * Deactivation: lightweight cleanup only (data is preserved).
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_Deactivator
 */
class WPSD_Deactivator {

	/**
	 * Run deactivation tasks.
	 */
	public static function deactivate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		WPSD_Roles::deactivate();
		flush_rewrite_rules();
		delete_transient( 'wpsd_dashboard_stats' );
	}
}
