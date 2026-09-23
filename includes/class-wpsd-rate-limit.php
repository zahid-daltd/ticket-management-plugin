<?php
/**
 * Transient-based rate limiting (per IP / mobile / API key).
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_Rate_Limit
 */
class WPSD_Rate_Limit {

	/**
	 * Check whether a bucket is over its limit and consume one token.
	 *
	 * @param string $bucket     Bucket namespace, e.g. 'public_ip'.
	 * @param string $identifier Unique id within the bucket (IP, mobile, api key id).
	 * @param int    $limit      Max hits per window.
	 * @param int    $window     Window in seconds.
	 * @return true|WP_Error True when allowed, 429 error when limited.
	 */
	public static function check( $bucket, $identifier, $limit, $window ) {
		$bucket     = sanitize_key( $bucket );
		$identifier = substr( preg_replace( '/[^a-zA-Z0-9_:\.\-]/', '_', (string) $identifier ), 0, 100 );
		$key        = 'wpsd_rl_' . $bucket . '_' . md5( $identifier );

		$hits = (int) get_transient( $key );
		if ( $hits >= $limit ) {
			$err = new WP_Error(
				'wpsd_rate_limited',
				__( 'Too many requests. Please slow down and try again shortly.', 'affiniti-wp-support' ),
				array( 'status' => 429 )
			);
			$err->add_data( array( 'retry_after' => $window ), 'wpsd_rate_limited' );
			return $err;
		}

		set_transient( $key, $hits + 1, $window );
		return true;
	}

	/**
	 * Public-form limits: per IP and per mobile (filters allow tuning).
	 *
	 * @param string $ip     Visitor IP.
	 * @param string $mobile Normalized mobile (may be empty pre-validation).
	 * @return true|WP_Error
	 */
	public static function check_public_submission( $ip, $mobile = '' ) {
		$ip_limit     = (int) apply_filters( 'wpsd_public_rate_limit_per_ip', 10 );
		$ip_window    = (int) apply_filters( 'wpsd_public_rate_window_ip', HOUR_IN_SECONDS );
		$mobile_limit = (int) apply_filters( 'wpsd_public_rate_limit_per_mobile', 5 );

		$result = self::check( 'public_ip', $ip, $ip_limit, $ip_window );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( '' !== $mobile ) {
			$result = self::check( 'public_mobile', $mobile, $mobile_limit, $ip_window );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * Per-API-key limit using the client's configured rate.
	 *
	 * @param array $client API client row.
	 * @return true|WP_Error
	 */
	public static function check_api_client( $client ) {
		$limit = isset( $client['rate_limit_per_minute'] ) ? max( 1, (int) $client['rate_limit_per_minute'] ) : 60;
		return self::check( 'api_key', (string) $client['id'], $limit, MINUTE_IN_SECONDS );
	}

	/**
	 * Best-effort visitor IP (for abuse prevention only, never for auth).
	 *
	 * @return string
	 */
	public static function visitor_ip() {
		$keys = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $keys as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
				$raw = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_unslash + sanitize applied.
				// X-Forwarded-For may be a list; take the first.
				$parts = explode( ',', $raw );
				$ip    = trim( $parts[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}
