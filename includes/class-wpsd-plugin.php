<?php
/**
 * Plugin orchestrator: wires hooks, REST, admin, public, i18n.
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_Plugin
 */
class WPSD_Plugin {

	/**
	 * REST controller instance.
	 *
	 * @var WPSD_REST
	 */
	private $rest;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->rest = new WPSD_REST();
	}

	/**
	 * Register all hooks.
	 */
	public function run() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( 'WPSD_DB', 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( $this->rest, 'register' ) );

		// Normalize REST envelope errors into {success,data,error} shape.
		add_filter( 'rest_request_after_callbacks', array( $this, 'normalize_envelope' ), 10, 3 );

		$admin = new WPSD_Admin();
		add_action( 'admin_menu', array( $admin, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );
		add_action( 'wp_dashboard_setup', array( $admin, 'dashboard_widget' ) );

		$public = new WPSD_Public();
		add_action( 'init', array( $public, 'register_shortcodes' ) );
		add_action( 'wp_enqueue_scripts', array( $public, 'enqueue_assets' ) );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'affiniti-wp-support', false, dirname( plugin_basename( WPSD_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Normalize every response/error into the spec envelope:
	 * { success: bool, data: {...}|null, error: {code,message}|null }
	 * while preserving correct HTTP status codes.
	 *
	 * @param mixed           $response Response data.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function normalize_envelope( $response, $handler, $request ) {
		$route = $request->get_route();
		if ( 0 !== strpos( (string) $route, '/wpsd/v1' ) ) {
			return $response;
		}
		if ( $response instanceof WP_REST_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) && array_key_exists( 'success', $data ) ) {
				return $response; // Already enveloped.
			}
			$response->set_data(
				array(
					'success' => true,
					'data'    => $data,
					'error'   => null,
				)
			);
			return $response;
		}
		if ( $response instanceof WP_Error ) {
			$code    = $response->get_error_code();
			$message = $response->get_error_message();
			$status  = $response->get_error_data( $code );
			$details = null;
			$data    = $response->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$status = (int) $data['status'];
			}
			if ( is_array( $data ) && isset( $data['fields'] ) ) {
				$details = $data;
				unset( $details['status'] ); // Internal-only; the HTTP status line already carries it.
			}
			// Retry-After for 429s.
			$headers = array();
			if ( 429 === (int) $status ) {
				$all = $response->get_all_error_data();
				foreach ( (array) $all as $d ) {
					if ( is_array( $d ) && isset( $d['retry_after'] ) ) {
						$headers['Retry-After'] = (int) $d['retry_after'];
					}
				}
				if ( ! isset( $headers['Retry-After'] ) ) {
					$headers['Retry-After'] = 60;
				}
			}
			$body = array(
				'success' => false,
				'data'    => $details,
				'error'   => array(
					'code'    => $code ? $code : 'wpsd_error',
					'message' => $message ? $message : __( 'An error occurred.', 'affiniti-wp-support' ),
				),
			);
			$rest = new WP_REST_Response( $body, $status ? (int) $status : 400 );
			foreach ( $headers as $k => $v ) {
				$rest->header( $k, $v );
			}
			return $rest;
		}
		return $response;
	}
}
