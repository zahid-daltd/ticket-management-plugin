<?php
/**
 * Cached lookup-table access (shared by REST, admin, and public UI).
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_Lookups
 */
class WPSD_Lookups {

	/**
	 * Transient TTL for lookup data (1 hour). Dashboard stats use their own 5-min TTL.
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Flush all lookup caches.
	 */
	public static function flush_cache() {
		$keys = array( 'districts', 'products', 'problem_types_global' );
		foreach ( $keys as $k ) {
			delete_transient( 'wpsd_lookup_' . $k );
		}
		global $wpdb;
		// Child-keyed caches are stored with hashed keys; delete the known query variants.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- cache invalidation, option names controlled internally.
		$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '_transient_wpsd_lookup_%' OR option_name LIKE '_transient_timeout_wpsd_lookup_%'" );
	}

	/**
	 * All districts (cached).
	 *
	 * @return array
	 */
	public static function districts() {
		$cached = get_transient( 'wpsd_lookup_districts' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table = WPSD_DB::table( 'districts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-only lookup.
		$rows = $wpdb->get_results( "SELECT id, name FROM `{$table}` ORDER BY name ASC", ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		set_transient( 'wpsd_lookup_districts', $rows, self::CACHE_TTL );
		return $rows;
	}

	/**
	 * Thanas for a district (cached per district).
	 *
	 * @param int $district_id District id.
	 * @return array
	 */
	public static function thanas( $district_id ) {
		$district_id = absint( $district_id );
		$key         = 'wpsd_lookup_thanas_' . $district_id;
		$cached      = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table = WPSD_DB::table( 'thanas' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-only lookup.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, district_id, name FROM `{$table}` WHERE district_id = %d ORDER BY name ASC", $district_id ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		set_transient( $key, $rows, self::CACHE_TTL );
		return $rows;
	}

	/**
	 * Routes for a thana (cached per thana).
	 *
	 * @param int $thana_id Thana id.
	 * @return array
	 */
	public static function routes( $thana_id ) {
		$thana_id = absint( $thana_id );
		$key      = 'wpsd_lookup_routes_' . $thana_id;
		$cached   = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table = WPSD_DB::table( 'routes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-only lookup.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, thana_id, name FROM `{$table}` WHERE thana_id = %d ORDER BY name ASC", $thana_id ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		set_transient( $key, $rows, self::CACHE_TTL );
		return $rows;
	}

	/**
	 * Service centers for a route (cached per route).
	 * SPEC DECISION (Open Q1): a route may map to MANY centers, so this
	 * returns a filtered list. When exactly one center exists the UI
	 * pre-selects it, but the API still validates center-in-route.
	 *
	 * @param int $route_id Route id.
	 * @return array
	 */
	public static function service_centers( $route_id ) {
		$route_id = absint( $route_id );
		$key      = 'wpsd_lookup_centers_' . $route_id;
		$cached   = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table = WPSD_DB::table( 'service_centers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-only lookup.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, route_id, name, address, contact_phone FROM `{$table}` WHERE route_id = %d ORDER BY name ASC", $route_id ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		set_transient( $key, $rows, self::CACHE_TTL );
		return $rows;
	}

	/**
	 * All active products (cached). Optionally filtered by brand.
	 *
	 * @param string $brand Optional brand filter.
	 * @return array
	 */
	public static function products( $brand = '' ) {
		$cached = get_transient( 'wpsd_lookup_products' );
		if ( false === $cached ) {
			global $wpdb;
			$table = WPSD_DB::table( 'products' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-only lookup.
			$cached = $wpdb->get_results( "SELECT id, brand, model_name, category FROM `{$table}` WHERE is_active = 1 ORDER BY brand ASC, model_name ASC", ARRAY_A );
			$cached = is_array( $cached ) ? $cached : array();
			set_transient( 'wpsd_lookup_products', $cached, self::CACHE_TTL );
		}
		if ( '' !== $brand ) {
			return array_values(
				array_filter(
					$cached,
					function ( $p ) use ( $brand ) {
						return isset( $p['brand'] ) && 0 === strcasecmp( (string) $p['brand'], (string) $brand );
					}
				)
			);
		}
		return $cached;
	}

	/**
	 * Distinct brands (derived from products, cached with products).
	 *
	 * @return string[]
	 */
	public static function brands() {
		$brands = array();
		foreach ( self::products() as $p ) {
			if ( isset( $p['brand'] ) && '' !== $p['brand'] ) {
				$brands[] = $p['brand'];
			}
		}
		$brands = array_unique( $brands );
		sort( $brands );
		return array_values( $brands );
	}

	/**
	 * Problem types. When $product_id is given, returns types for that
	 * product's category PLUS global types (product_category NULL).
	 * SPEC DECISION (Open Q3): category-specific with global fallback.
	 *
	 * @param int $product_id Optional product id.
	 * @return array|WP_Error
	 */
	public static function problem_types( $product_id = 0 ) {
		global $wpdb;
		$problems = WPSD_DB::table( 'problem_types' );

		if ( empty( $product_id ) ) {
			$cached = get_transient( 'wpsd_lookup_problem_types_global' );
			if ( false !== $cached ) {
				return $cached;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-only lookup.
			$rows = $wpdb->get_results( "SELECT id, product_category, label FROM `{$problems}` WHERE is_active = 1 ORDER BY label ASC", ARRAY_A );
			$rows = is_array( $rows ) ? $rows : array();
			set_transient( 'wpsd_lookup_problem_types_global', $rows, self::CACHE_TTL );
			return $rows;
		}

		$products = WPSD_DB::table( 'products' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-only lookup.
		$category = $wpdb->get_var( $wpdb->prepare( "SELECT category FROM `{$products}` WHERE id = %d LIMIT 1", absint( $product_id ) ) );
		if ( ! $category ) {
			return new WP_Error( 'wpsd_bad_product', __( 'Selected product is invalid.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-only lookup.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, product_category, label FROM `{$problems}` WHERE is_active = 1 AND (product_category IS NULL OR product_category = '' OR product_category = %s) ORDER BY label ASC",
				$category
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
