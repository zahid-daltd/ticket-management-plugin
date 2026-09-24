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
		$keys = array( 'districts', 'problem_types_global' );
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
	 * Product search, sourced live from the WooCommerce catalog (not cached —
	 * WooCommerce/WP_Query already handle object caching internally, and
	 * stock/price/availability need to stay current).
	 *
	 * @param string $search Optional search term (matches product title/SKU).
	 * @return array
	 */
	public static function products( $search = '' ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$args = array(
			'status'  => 'publish',
			'limit'   => 20,
			'orderby' => 'title',
			'order'   => 'ASC',
		);
		$search = trim( (string) $search );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$products = wc_get_products( $args );
		$out      = array();
		foreach ( $products as $product ) {
			$out[] = array(
				'id'   => $product->get_id(),
				'name' => $product->get_name(),
				'sku'  => $product->get_sku(),
			);
		}
		return $out;
	}

	/**
	 * WooCommerce category names assigned to a product (a product can carry
	 * more than one — problem-type matching treats any of them as a match).
	 *
	 * @param int $product_id Product (WooCommerce post) id.
	 * @return string[]
	 */
	private static function product_category_names( $product_id ) {
		$terms = wp_get_post_terms( absint( $product_id ), 'product_cat', array( 'fields' => 'names' ) );
		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Problem types. When $product_id is given, returns types matching any
	 * of that WooCommerce product's categories PLUS global types
	 * (product_category NULL/empty).
	 *
	 * @param int $product_id Optional product (WooCommerce post) id.
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

		if ( ! function_exists( 'wc_get_product' ) || ! wc_get_product( absint( $product_id ) ) ) {
			return new WP_Error( 'wpsd_bad_product', __( 'Selected product is invalid.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
		}
		$categories = self::product_category_names( $product_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-only lookup.
		$rows = $wpdb->get_results( "SELECT id, product_category, label FROM `{$problems}` WHERE is_active = 1 ORDER BY label ASC", ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		return array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $categories ) {
					$cat = $r['product_category'];
					return ( null === $cat || '' === $cat ) || in_array( $cat, $categories, true );
				}
			)
		);
	}
}
