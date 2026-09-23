<?php
/**
 * Roles and capabilities.
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_Roles
 */
class WPSD_Roles {

	/**
	 * All custom capabilities registered by the plugin.
	 *
	 * @return string[]
	 */
	public static function capabilities() {
		return array(
			'wpsd_view_tickets',
			'wpsd_manage_tickets',
			'wpsd_assign_tickets',
			'wpsd_delete_tickets',
		);
	}

	/**
	 * Register the Support Agent role and grant admin caps.
	 */
	public static function activate() {
		$caps_agent = array(
			'read'                 => true,
			'wpsd_view_tickets'    => true,
			'wpsd_manage_tickets'  => true,
			'wpsd_assign_tickets'  => true,
			'wpsd_delete_tickets'  => false,
		);

		if ( null === get_role( 'wpsd_support_agent' ) ) {
			add_role( 'wpsd_support_agent', __( 'Support Agent', 'affiniti-wp-support' ), $caps_agent );
		} else {
			$role = get_role( 'wpsd_support_agent' );
			foreach ( $caps_agent as $cap => $grant ) {
				$role->add_cap( $cap, $grant );
			}
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::capabilities() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove caps on deactivation (keeps the role so assignments survive).
	 */
	public static function deactivate() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::capabilities() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	/**
	 * Fully remove the role + caps (used only on uninstall when opted in).
	 */
	public static function uninstall() {
		remove_role( 'wpsd_support_agent' );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::capabilities() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	/**
	 * Can the current user view tickets at all?
	 *
	 * @return bool
	 */
	public static function can_view() {
		return current_user_can( 'wpsd_view_tickets' );
	}

	/**
	 * Can the current user manage (create/update status/priority/replies) tickets?
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'wpsd_manage_tickets' );
	}
}
