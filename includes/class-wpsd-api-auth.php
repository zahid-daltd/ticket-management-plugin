<?php
/**
 * External API-key authentication (key + secret, optional HMAC, HTTPS enforcement).
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_API_Auth
 */
class WPSD_API_Auth {

	/**
	 * Whether HTTPS is required for external API access. Auto-relaxed for
	 * obviously-local hosts (.local/.test/localhost/127.0.0.1 — the TLDs
	 * every common local dev stack, incl. Local by Flywheel, uses) so API
	 * clients can be exercised from Postman without a TLS cert. Filter
	 * `wpsd_require_ssl` still overrides this either way.
	 *
	 * @return bool
	 */
	public static function ssl_required() {
		$host        = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- host compared against a fixed pattern only, never output or used in a query.
		$host        = preg_replace( '/:\d+$/', '', $host );
		$is_dev_host = '' !== $host && ( 'localhost' === $host || '127.0.0.1' === $host || (bool) preg_match( '/\.(local|test)$/', $host ) );
		return (bool) apply_filters( 'wpsd_require_ssl', ! $is_dev_host );
	}

	/**
	 * Authenticate an external consumer from request headers.
	 *
	 * Headers: X-WPSD-API-Key, X-WPSD-API-Secret. Deliberately NOT the
	 * standard `Authorization: Basic` header — WP core's Application
	 * Passwords support intercepts any Basic Auth attempt at REST bootstrap
	 * time (before routing reaches this plugin at all) and rejects it
	 * outright when the username isn't a real WP user login, so a plugin
	 * cannot repurpose that header for its own key/secret scheme.
	 * Optional replay protection: X-WPSD-Timestamp + X-WPSD-Signature
	 * (HMAC-SHA256 over timestamp + '.' + raw body, keyed with the API secret).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error Client row (with scope) or error. Empty array = no credentials supplied.
	 */
	public static function authenticate( $request ) {
		$key    = trim( (string) $request->get_header( 'X-WPSD-API-Key' ) );
		$secret = (string) $request->get_header( 'X-WPSD-API-Secret' );

		if ( '' === $key && '' === $secret ) {
			return array();
		}
		if ( '' === $key || '' === $secret ) {
			return new WP_Error( 'wpsd_unauthorized', __( 'API key and secret are both required.', 'affiniti-wp-support' ), array( 'status' => 401 ) );
		}

		if ( self::ssl_required() && ! is_ssl() ) {
			return new WP_Error( 'wpsd_https_required', __( 'HTTPS is required for API access.', 'affiniti-wp-support' ), array( 'status' => 403 ) );
		}

		$client = self::get_client_by_key( $key );
		if ( ! $client || empty( $client['is_active'] ) ) {
			return new WP_Error( 'wpsd_unauthorized', __( 'Invalid API credentials.', 'affiniti-wp-support' ), array( 'status' => 401 ) );
		}

		if ( ! password_verify( $secret, $client['api_secret_hash'] ) ) {
			return new WP_Error( 'wpsd_unauthorized', __( 'Invalid API credentials.', 'affiniti-wp-support' ), array( 'status' => 401 ) );
		}

		// Optional IP allow-list.
		if ( ! empty( $client['allowed_ips'] ) ) {
			$allowed = array_filter( array_map( 'trim', explode( ',', (string) $client['allowed_ips'] ) ) );
			if ( ! empty( $allowed ) && ! in_array( WPSD_Rate_Limit::visitor_ip(), $allowed, true ) ) {
				return new WP_Error( 'wpsd_forbidden', __( 'Your IP is not allowed for this API client.', 'affiniti-wp-support' ), array( 'status' => 403 ) );
			}
		}

		// Optional HMAC verification when the caller opts into replay protection.
		$timestamp = trim( (string) $request->get_header( 'X-WPSD-Timestamp' ) );
		$signature = trim( (string) $request->get_header( 'X-WPSD-Signature' ) );
		if ( '' !== $timestamp || '' !== $signature ) {
			$hmac = self::verify_hmac( $secret, $timestamp, $signature, $request->get_body() );
			if ( is_wp_error( $hmac ) ) {
				return $hmac;
			}
		}

		// Rate limit per client configuration.
		$limited = WPSD_Rate_Limit::check_api_client( $client );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		self::touch_last_used( (int) $client['id'] );

		return $client;
	}

	/**
	 * Verify HMAC-SHA256(timestamp.body) with a 5-minute window.
	 *
	 * @param string $secret    Raw API secret from headers.
	 * @param string $timestamp Unix timestamp from headers.
	 * @param string $signature Hex HMAC from headers.
	 * @param string $body      Raw request body.
	 * @return true|WP_Error
	 */
	private static function verify_hmac( $secret, $timestamp, $signature, $body ) {
		if ( '' === $timestamp || '' === $signature ) {
			return new WP_Error( 'wpsd_unauthorized', __( 'Timestamp and signature must be sent together.', 'affiniti-wp-support' ), array( 'status' => 401 ) );
		}
		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 ) {
			return new WP_Error( 'wpsd_unauthorized', __( 'Request timestamp is invalid or expired.', 'affiniti-wp-support' ), array( 'status' => 401 ) );
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . (string) $body, $secret );
		if ( ! hash_equals( $expected, strtolower( $signature ) ) ) {
			return new WP_Error( 'wpsd_unauthorized', __( 'Invalid request signature.', 'affiniti-wp-support' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Fetch an active client row by API key.
	 *
	 * @param string $key API key.
	 * @return array|null
	 */
	public static function get_client_by_key( $key ) {
		global $wpdb;
		$table = WPSD_DB::table( 'api_clients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- auth lookup.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE api_key = %s LIMIT 1", $key ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Update last_used_at (best effort, no user input in query besides id).
	 *
	 * @param int $id Client id.
	 */
	private static function touch_last_used( $id ) {
		global $wpdb;
		$table = WPSD_DB::table( 'api_clients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- internal timestamp update.
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET last_used_at = %s WHERE id = %d", current_time( 'mysql' ), $id ) );
	}

	/**
	 * Generate a new keypair. The secret is returned once and never stored in plain text.
	 *
	 * @return array Array with key, secret, secret_hash.
	 */
	public static function generate_pair() {
		try {
			$key    = 'wpsd_' . bin2hex( random_bytes( 16 ) );
			$secret = 'wpss_' . bin2hex( random_bytes( 32 ) );
		} catch ( Exception $e ) { // Fallback for constrained hosts.
			$key    = 'wpsd_' . md5( uniqid( 'wpsd', true ) . wp_rand() );
			$secret = 'wpss_' . md5( uniqid( 'wpss', true ) . wp_rand() ) . md5( uniqid( 'x', true ) );
		}
		return array(
			'key'         => $key,
			'secret'      => $secret,
			'secret_hash' => password_hash( $secret, PASSWORD_DEFAULT ),
		);
	}

	/**
	 * Does this scope permit reading tickets?
	 *
	 * @param string $scope Scope string.
	 * @return bool
	 */
	public static function scope_can_read( $scope ) {
		return in_array( $scope, array( 'create_and_read', 'full' ), true );
	}

	/**
	 * Does this scope permit listing/searching tickets?
	 * Only the `full` scope may enumerate tickets; create_and_read may
	 * fetch individual tickets it has ownership context for.
	 *
	 * @param string $scope Scope string.
	 * @return bool
	 */
	public static function scope_can_list( $scope ) {
		return 'full' === $scope;
	}
}
