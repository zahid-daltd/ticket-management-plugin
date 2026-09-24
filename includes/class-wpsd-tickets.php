<?php
/**
 * Ticket service: creation, retrieval, search, updates, replies, stats.
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_Tickets
 */
class WPSD_Tickets {

	/**
	 * Duplicate window: same mobile + same problem type within N seconds is rejected.
	 *
	 * @return int
	 */
	public static function duplicate_window() {
		return (int) apply_filters( 'wpsd_duplicate_window_seconds', 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Create a ticket from sanitized payload.
	 *
	 * @param array $clean  Sanitized payload from WPSD_Validator.
	 * @param array $context Optional: source override, created_by user id.
	 * @return array|WP_Error Ticket row or error.
	 */
	public static function create( $clean, $context = array() ) {
		global $wpdb;
		$table = WPSD_DB::table( 'tickets' );

		// Duplicate/spam prevention (web AND api equally).
		$dup = self::find_duplicate( $clean['mobile'], $clean['problem_description'] );
		if ( $dup ) {
			return new WP_Error(
				'wpsd_duplicate_ticket',
				sprintf(
					/* translators: %s: existing ticket number */
					__( 'A similar request (%s) was submitted recently from this number. Please wait before submitting again.', 'affiniti-wp-support' ),
					$dup
				),
				array( 'status' => 409 )
			);
		}

		// Insert with a temporary unique number, then stamp the final public number from the id.
		$tmp = 'TMP-' . wp_generate_uuid4();
		$data = array(
			'ticket_number'          => $tmp,
			'customer_name'          => $clean['customer_name'],
			'mobile'                 => $clean['mobile'],
			'alternative_mobile'     => $clean['alternative_mobile'],
			'address'                => $clean['address'],
			'product_id'             => $clean['product_id'],
			'brand_snapshot'         => $clean['brand_snapshot'],
			'product_name_snapshot'  => $clean['product_name_snapshot'],
			'barcode'                => $clean['barcode'],
			'problem_description'    => $clean['problem_description'],
			'comments'               => $clean['comments'],
			'status'                 => 'new',
			'priority'               => $clean['priority'],
			'assigned_agent_id'      => null,
			'source'                 => isset( $context['source'] ) ? $context['source'] : $clean['source'],
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- ticket insert.
		$ok = $wpdb->insert( $table, $data, $formats );
		if ( false === $ok ) {
			return new WP_Error( 'wpsd_db_error', __( 'Could not create the ticket. Please try again.', 'affiniti-wp-support' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;

		$number = self::format_ticket_number( $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- stamp final public number.
		$wpdb->update( $table, array( 'ticket_number' => $number ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

		$ticket = self::get_by_id( $id );

		// System reply recording creation (not internal).
		self::add_reply(
			$id,
			array(
				'message'          => __( 'Ticket created.', 'affiniti-wp-support' ),
				'is_internal_note' => 0,
			),
			0,
			'system'
		);

		// Notifications (filterable gateway; email fallback).
		WPSD_Notifications::on_ticket_created( $ticket );

		return $ticket;
	}

	/**
	 * Format the public ticket number from the row id.
	 *
	 * @param int $id Row id.
	 * @return string e.g. TKT-2026-00123
	 */
	public static function format_ticket_number( $id ) {
		$year = gmdate( 'Y' );
		return sprintf( 'TKT-%s-%05d', $year, absint( $id ) );
	}

	/**
	 * Find a recent duplicate ticket number for mobile + problem description
	 * (e.g. a double-click resubmit of the same form).
	 *
	 * @param string $mobile              Normalized mobile.
	 * @param string $problem_description Free-text problem description.
	 * @return string Empty string when none, else ticket number.
	 */
	private static function find_duplicate( $mobile, $problem_description ) {
		global $wpdb;
		$table  = WPSD_DB::table( 'tickets' );
		$window = self::duplicate_window();
		$since  = gmdate( 'Y-m-d H:i:s', time() - $window );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- duplicate check.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ticket_number FROM `{$table}` WHERE mobile = %s AND problem_description = %s AND created_at >= %s ORDER BY id DESC LIMIT 1",
				$mobile,
				$problem_description,
				$since
			)
		);
		return $found ? (string) $found : '';
	}

	/**
	 * Get a ticket row by numeric id (with joined labels).
	 *
	 * @param int $id Ticket id.
	 * @return array|null
	 */
	public static function get_by_id( $id ) {
		global $wpdb;
		$table = WPSD_DB::table( 'tickets' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- single-row fetch.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", absint( $id ) ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return self::hydrate( $row );
	}

	/**
	 * Get a ticket row by public ticket number.
	 *
	 * @param string $number Ticket number.
	 * @return array|null
	 */
	public static function get_by_number( $number ) {
		global $wpdb;
		$table = WPSD_DB::table( 'tickets' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- single-row fetch.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE ticket_number = %s LIMIT 1", $number ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return self::hydrate( $row );
	}

	/**
	 * Attach human-readable labels to a ticket row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate( $row ) {
		// Products are WooCommerce posts, not a plugin-owned table — the name
		// captured at ticket creation/update time is the durable label (also
		// survives the product being renamed or removed later). Problem is
		// free text on the ticket row itself, no lookup needed.
		$row['product_label'] = $row['product_name_snapshot'];
		return $row;
	}

	/**
	 * Search/list tickets with filters + pagination. Default 20/page, max 100.
	 *
	 * @param array $args Filters: status, product_id, assigned_agent_id, search, page, per_page, orderby, order.
	 * @return array Array with items, total, page, per_page, total_pages.
	 */
	public static function search( $args ) {
		global $wpdb;
		$table = WPSD_DB::table( 'tickets' );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 't.status = %s';
			$params[] = sanitize_key( $args['status'] );
		}
		foreach ( array( 'product_id', 'assigned_agent_id' ) as $f ) {
			if ( ! empty( $args[ $f ] ) ) {
				$where[]  = "t.`{$f}` = %d";
				$params[] = absint( $args[ $f ] );
			}
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[]  = '(t.ticket_number LIKE %s OR t.customer_name LIKE %s OR t.mobile LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$page     = max( 1, absint( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$per_page = absint( isset( $args['per_page'] ) ? $args['per_page'] : 20 );
		$per_page = min( 100, max( 1, $per_page ) );

		$allowed_orderby = array( 'id', 'created_at', 'updated_at', 'status', 'priority' );
		$orderby         = isset( $args['orderby'] ) && in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = isset( $args['order'] ) && 'asc' === strtolower( $args['order'] ) ? 'ASC' : 'DESC';

		$count_sql = "SELECT COUNT(*) FROM `{$table}` t WHERE {$where_sql}";
		if ( $params ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- $where_sql built from allow-listed columns; values prepared.
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- no user input.
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$offset   = ( $page - 1 ) * $per_page;
		$params_p = array_merge( $params, array( $per_page, $offset ) );
		// Single query, no per-row hydration.
		$list_sql = "SELECT t.*, t.product_name_snapshot AS product_label
			FROM `{$table}` t
			WHERE {$where_sql} ORDER BY t.`{$orderby}` {$order} LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- order/columns allow-listed; values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $params_p ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		return array(
			'items'       => $rows, // Labels already joined in; no per-row queries.
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}

	/**
	 * Admin-only update of status/priority/assignment plus editable customer,
	 * location, and product fields (all validated by WPSD_Validator first).
	 * Text optionals normalize empty input to '' ($wpdb cannot store NULL via
	 * formats); assignment NULL is handled with an explicit query.
	 *
	 * @param int   $id    Ticket id.
	 * @param array $clean Sanitized update fields.
	 * @param int   $actor User id performing the change.
	 * @return array|WP_Error Updated ticket or error.
	 */
	public static function update_admin_fields( $id, $clean, $actor = 0 ) {
		global $wpdb;
		$table = WPSD_DB::table( 'tickets' );

		$before = self::get_by_id( $id );
		if ( ! $before ) {
			return new WP_Error( 'wpsd_not_found', __( 'Ticket not found.', 'affiniti-wp-support' ), array( 'status' => 404 ) );
		}

		$data   = array();
		$format = array();
		$formats_by_col = array(
			'status'                 => '%s',
			'priority'               => '%s',
			'assigned_agent_id'      => '%d',
			'customer_name'          => '%s',
			'mobile'                 => '%s',
			'alternative_mobile'     => '%s',
			'address'                => '%s',
			'barcode'                => '%s',
			'comments'               => '%s',
			'product_id'             => '%d',
			'problem_description'    => '%s',
			'brand_snapshot'         => '%s',
			'product_name_snapshot'  => '%s',
		);
		foreach ( $formats_by_col as $col => $fmt ) {
			if ( array_key_exists( $col, $clean ) ) {
				$data[ $col ] = $clean[ $col ];
				$format[]     = 'assigned_agent_id' === $col && null === $clean[ $col ] ? null : $fmt;
			}
		}
		if ( empty( $data ) ) {
			return new WP_Error( 'wpsd_validation_failed', __( 'Nothing to update.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
		}
		// Handle NULL assignment explicitly (wpdb::update cannot set NULL via format).
		$null_assign = array_key_exists( 'assigned_agent_id', $data ) && null === $data['assigned_agent_id'];
		if ( $null_assign ) {
			unset( $data['assigned_agent_id'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- admin update.
		$ok = $wpdb->update( $table, $data, array( 'id' => absint( $id ) ), array_values( array_filter( $format ) ), array( '%d' ) );
		if ( false === $ok ) {
			return new WP_Error( 'wpsd_db_error', __( 'Could not update the ticket.', 'affiniti-wp-support' ), array( 'status' => 500 ) );
		}
		if ( $null_assign ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- NULL assignment.
			$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET assigned_agent_id = NULL WHERE id = %d", absint( $id ) ) );
		}

		$after = self::get_by_id( $id );

		// Log a system reply summarizing the change.
		$changes = array();
		foreach ( array( 'status', 'priority', 'assigned_agent_id', 'customer_name', 'mobile', 'alternative_mobile', 'address', 'barcode', 'comments', 'product_id', 'problem_description' ) as $col ) {
			$b = isset( $before[ $col ] ) ? (string) $before[ $col ] : '';
			$a = isset( $after[ $col ] ) ? (string) $after[ $col ] : '';
			if ( $b !== $a ) {
				$changes[] = $col . ': ' . $b . ' -> ' . $a;
			}
		}
		if ( $changes ) {
			self::add_reply(
				$id,
				array(
					'message'          => sprintf( __( 'Updated by staff: %s', 'affiniti-wp-support' ), implode( '; ', $changes ) ),
					'is_internal_note' => 1,
				),
				absint( $actor ),
				'agent'
			);
		}

		WPSD_Notifications::on_ticket_updated( $before, $after );

		return $after;
	}

	/**
	 * Add a reply/comment to a ticket thread.
	 *
	 * @param int    $ticket_id Ticket id.
	 * @param array  $clean     Sanitized reply (message, is_internal_note).
	 * @param int    $author_id Author user id (0 for guest/system).
	 * @param string $type      agent|customer|system.
	 * @return array|WP_Error Reply row or error.
	 */
	public static function add_reply( $ticket_id, $clean, $author_id = 0, $type = 'agent' ) {
		global $wpdb;
		$ticket_id = absint( $ticket_id );
		if ( ! self::get_by_id( $ticket_id ) ) {
			return new WP_Error( 'wpsd_not_found', __( 'Ticket not found.', 'affiniti-wp-support' ), array( 'status' => 404 ) );
		}
		$type = in_array( $type, array( 'agent', 'customer', 'system' ), true ) ? $type : 'agent';

		$table = WPSD_DB::table( 'replies' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- reply insert.
		$ok = $wpdb->insert(
			$table,
			array(
				'ticket_id'        => $ticket_id,
				'author_id'        => absint( $author_id ),
				'author_type'      => $type,
				'message'          => $clean['message'],
				'is_internal_note' => ! empty( $clean['is_internal_note'] ) ? 1 : 0,
			),
			array( '%d', '%d', '%s', '%s', '%d' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'wpsd_db_error', __( 'Could not add the reply.', 'affiniti-wp-support' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- fetch new reply.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ), ARRAY_A );
	}

	/**
	 * Get the reply thread for a ticket.
	 *
	 * @param int  $ticket_id     Ticket id.
	 * @param bool $include_notes Whether to include internal notes.
	 * @return array
	 */
	public static function get_replies( $ticket_id, $include_notes = true ) {
		global $wpdb;
		$table = WPSD_DB::table( 'replies' );
		if ( $include_notes ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- thread fetch.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE ticket_id = %d ORDER BY id ASC", absint( $ticket_id ) ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- thread fetch.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE ticket_id = %d AND is_internal_note = 0 ORDER BY id ASC", absint( $ticket_id ) ), ARRAY_A );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete a ticket and its thread (admin + capability only).
	 *
	 * @param int $id Ticket id.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! self::get_by_id( $id ) ) {
			return new WP_Error( 'wpsd_not_found', __( 'Ticket not found.', 'affiniti-wp-support' ), array( 'status' => 404 ) );
		}
		foreach ( array( 'attachments', 'replies' ) as $child ) {
			$table = WPSD_DB::table( $child );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- cascade delete.
			$wpdb->delete( $table, array( 'ticket_id' => $id ), array( '%d' ) );
		}
		$table = WPSD_DB::table( 'tickets' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- delete.
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	/**
	 * Dashboard stats (cached 5 minutes per spec).
	 *
	 * @return array
	 */
	public static function stats() {
		$cached = get_transient( 'wpsd_dashboard_stats' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table = WPSD_DB::table( 'tickets' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- aggregate stats.
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM `{$table}` GROUP BY status", ARRAY_A );
		// A status with zero tickets never appears in a GROUP BY result — seed
		// every known status at 0 first so the dashboard always shows all of them.
		$by_status = array_fill_keys( WPSD_Validator::statuses(), 0 );
		$total     = 0;
		foreach ( (array) $counts as $row ) {
			$by_status[ $row['status'] ] = (int) $row['c'];
			$total += (int) $row['c'];
		}
		// Average resolution time (hours) for resolved/closed tickets updated in last 90 days.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- aggregate stats.
		$avg = $wpdb->get_var(
			"SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) FROM `{$table}` WHERE status IN ('resolved','closed') AND updated_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
		);
		$data = array(
			'total'                 => $total,
			'by_status'             => $by_status,
			'avg_resolution_hours'  => null !== $avg ? round( (float) $avg, 1 ) : null,
			'cached_at'             => current_time( 'mysql' ),
		);
		set_transient( 'wpsd_dashboard_stats', $data, 5 * MINUTE_IN_SECONDS );
		return $data;
	}
}
