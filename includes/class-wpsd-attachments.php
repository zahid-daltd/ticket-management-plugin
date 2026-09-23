<?php
/**
 * Attachment uploads: MIME whitelist, randomized names, no PHP execution.
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_Attachments
 */
class WPSD_Attachments {

	/**
	 * Allowed mime types for ticket attachments.
	 *
	 * @return array ext => mime
	 */
	public static function allowed_mimes() {
		return (array) apply_filters(
			'wpsd_allowed_attachment_mimes',
			array(
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				'webp' => 'image/webp',
				'pdf'  => 'application/pdf',
			)
		);
	}

	/**
	 * Max upload size in bytes (default 5MB).
	 *
	 * @return int
	 */
	public static function max_size() {
		return (int) apply_filters( 'wpsd_max_attachment_bytes', 5 * MB_IN_BYTES );
	}

	/**
	 * Handle an upload from $_FILES for a ticket reply.
	 *
	 * @param array $file    Single $_FILES entry.
	 * @param int   $ticket_id Ticket id.
	 * @param int   $reply_id  Reply id (nullable).
	 * @param int   $user_id   Uploader user id.
	 * @return array|WP_Error Attachment row or error.
	 */
	public static function handle_upload( $file, $ticket_id, $reply_id, $user_id ) {
		if ( ! isset( $file['tmp_name'], $file['name'], $file['size'], $file['error'] ) ) {
			return new WP_Error( 'wpsd_bad_upload', __( 'No file was uploaded.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'wpsd_bad_upload', __( 'File upload failed.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > self::max_size() ) {
			return new WP_Error( 'wpsd_bad_upload', __( 'File is too large (max 5MB).', 'affiniti-wp-support' ), array( 'status' => 400 ) );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::allowed_mimes() );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new WP_Error( 'wpsd_bad_upload', __( 'File type not allowed. Use JPG, PNG, WEBP, or PDF.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
		}

		$dir = self::upload_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		// Randomized filename: never trust the client name for storage.
		$stored = 'wpsd-' . absint( $ticket_id ) . '-' . wp_generate_uuid4() . '.' . $check['ext'];
		$dest   = trailingslashit( $dir ) . $stored;

		// Move via WP API surface where possible; fallback to move_uploaded_file.
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) { // phpcs:ignore WordPress.Security.EscapeOutput -- local move, paths internal.
			return new WP_Error( 'wpsd_bad_upload', __( 'Could not store the uploaded file.', 'affiniti-wp-support' ), array( 'status' => 500 ) );
		}

		// Double-check the stored file's mime after moving.
		$real = wp_check_filetype( $dest );
		if ( empty( $real['type'] ) || ! in_array( $real['type'], self::allowed_mimes(), true ) ) {
			wp_delete_file( $dest );
			return new WP_Error( 'wpsd_bad_upload', __( 'File type not allowed.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$table = WPSD_DB::table( 'attachments' );
		$rel   = 'wpsd/' . $stored;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- attachment record.
		$ok = $wpdb->insert(
			$table,
			array(
				'ticket_id'         => absint( $ticket_id ),
				'reply_id'          => $reply_id ? absint( $reply_id ) : null,
				'file_path'         => $rel,
				'original_filename' => sanitize_file_name( wp_unslash( $file['name'] ) ),
				'mime_type'         => $check['type'],
				'uploaded_by'       => absint( $user_id ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d' )
		);
		if ( false === $ok ) {
			wp_delete_file( $dest );
			return new WP_Error( 'wpsd_db_error', __( 'Could not record the attachment.', 'affiniti-wp-support' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- fetch new row.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ), ARRAY_A );
	}

	/**
	 * Ensure the wpsd upload directory exists with PHP-execution protection.
	 *
	 * @return string|WP_Error Absolute dir path or error.
	 */
	public static function upload_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'wpsd_upload_error', __( 'Upload directory is not available.', 'affiniti-wp-support' ), array( 'status' => 500 ) );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'wpsd';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Block direct PHP execution + directory listing.
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "Options -Indexes\n<FilesMatch \"\\.php$\">\n  Require all denied\n</FilesMatch>\nForceType text/plain\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local hardening file.
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local hardening file.
		}
		return $dir;
	}

	/**
	 * List attachments for a ticket (includes a direct file URL).
	 *
	 * @param int $ticket_id Ticket id.
	 * @return array
	 */
	public static function for_ticket( $ticket_id ) {
		global $wpdb;
		$table = WPSD_DB::table( 'attachments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- list.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, ticket_id, reply_id, file_path, original_filename, mime_type, uploaded_by, created_at FROM `{$table}` WHERE ticket_id = %d ORDER BY id ASC", absint( $ticket_id ) ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$uploads = wp_upload_dir();
		$baseurl = isset( $uploads['baseurl'] ) ? trailingslashit( $uploads['baseurl'] ) : '';
		foreach ( $rows as &$row ) {
			$row['file_url'] = $baseurl . ltrim( (string) $row['file_path'], '/' );
			unset( $row['file_path'] );
		}
		return $rows;
	}
}
