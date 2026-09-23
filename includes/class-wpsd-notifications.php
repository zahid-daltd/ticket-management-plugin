<?php
/**
 * Notifications: pluggable SMS gateway + email fallback + agent notices.
 *
 * SPEC DECISION (Open Q4): the gateway is NOT hardcoded. Out of the box the
 * plugin sends email via wp_mail and fires the `wpsd_send_sms` action so a
 * site can wire SSL Wireless / Alpha SMS / D7 / Twilio without editing core.
 * See docs/api.md for an integration snippet.
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_Notifications
 */
class WPSD_Notifications {

	/**
	 * Fired after a ticket is created.
	 *
	 * @param array|null $ticket Ticket row.
	 */
	public static function on_ticket_created( $ticket ) {
		if ( empty( $ticket ) || ! is_array( $ticket ) ) {
			return;
		}

		// Customer confirmation (SMS via pluggable gateway, email fallback).
		$message = sprintf(
			/* translators: %s: ticket number */
			__( 'Your service request %s has been received. We will contact you shortly.', 'affiniti-wp-support' ),
			$ticket['ticket_number']
		);
		self::send_customer_sms( $ticket['mobile'], $message );

		$admin_email = get_option( 'admin_email' );
		if ( $admin_email ) {
			wp_mail(
				$admin_email,
				sprintf( __( 'New ticket %s', 'affiniti-wp-support' ), $ticket['ticket_number'] ),
				sprintf( "Name: %s\nMobile: %s\nTicket: %s\n", $ticket['customer_name'], $ticket['mobile'], $ticket['ticket_number'] )
			);
		}

		/**
		 * Fires when a ticket is created.
		 *
		 * @param array $ticket Ticket row.
		 */
		do_action( 'wpsd_ticket_created', $ticket );
	}

	/**
	 * Fired after an admin update (status/priority/assignment).
	 *
	 * @param array $before Ticket row before.
	 * @param array $after  Ticket row after.
	 */
	public static function on_ticket_updated( $before, $after ) {
		if ( empty( $after ) || ! is_array( $after ) ) {
			return;
		}
		// SMS on status change (spec requirement).
		if ( isset( $before['status'], $after['status'] ) && $before['status'] !== $after['status'] ) {
			$message = sprintf(
				/* translators: 1: ticket number, 2: new status */
				__( 'Your service request %1$s status is now: %2$s.', 'affiniti-wp-support' ),
				$after['ticket_number'],
				$after['status']
			);
			self::send_customer_sms( $after['mobile'], $message );
		}

		// Notify newly assigned agent.
		if ( isset( $after['assigned_agent_id'] ) && (int) $after['assigned_agent_id'] > 0 && (int) $before['assigned_agent_id'] !== (int) $after['assigned_agent_id'] ) {
			$user = get_user_by( 'id', (int) $after['assigned_agent_id'] );
			if ( $user && $user->user_email ) {
				wp_mail(
					$user->user_email,
					sprintf( __( 'Ticket %s assigned to you', 'affiniti-wp-support' ), $after['ticket_number'] ),
					sprintf( __( 'Ticket %s has been assigned to you.', 'affiniti-wp-support' ), $after['ticket_number'] )
				);
			}
		}

		/**
		 * Fires when a ticket is updated by staff.
		 *
		 * @param array $before Row before.
		 * @param array $after  Row after.
		 */
		do_action( 'wpsd_ticket_updated', $before, $after );
	}

	/**
	 * Send an SMS (pluggable) with email fallback disabled for customer mobiles.
	 * A gateway plugin/theme should hook `wpsd_send_sms` and return true.
	 *
	 * @param string $to      Normalized mobile.
	 * @param string $message Message text.
	 * @return bool
	 */
	public static function send_customer_sms( $to, $message ) {
		/**
		 * Filters whether an SMS was sent by a custom gateway.
		 * Return true from your gateway integration to mark as sent.
		 *
		 * @param bool   $sent    Default false.
		 * @param string $to      Recipient mobile.
		 * @param string $message Message text.
		 */
		$sent = (bool) apply_filters( 'wpsd_send_sms', false, $to, $message );

		/**
		 * Fires to allow SMS gateway integrations (action style).
		 *
		 * @param string $to      Recipient mobile.
		 * @param string $message Message text.
		 */
		do_action( 'wpsd_sms_gateway', $to, $message );

		return $sent;
	}
}
