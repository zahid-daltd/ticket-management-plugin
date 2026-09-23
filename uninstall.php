<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Data is preserved by default. Tables are dropped only when the site owner
 * explicitly enabled "Delete all ticket data on uninstall" under
 * Support Desk → Import / Settings.
 *
 * @package WPSD
 * @since 1.0.0
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$cleanup = (int) get_option( 'wpsd_uninstall_cleanup', 0 );
if ( 1 !== $cleanup ) {
	return;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpsd-db.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpsd-roles.php';

WPSD_DB::drop_tables();
WPSD_Roles::uninstall();

delete_option( 'wpsd_public_rate_per_ip' );
delete_option( 'wpsd_public_rate_per_mobile' );
delete_option( 'wpsd_uninstall_cleanup' );
delete_transient( 'wpsd_dashboard_stats' );
