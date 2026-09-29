<?php
/**
 * REST API: wpsd/v1 routes, auth, envelopes, errors.
 *
 * Auth model (two schemes, never blurred):
 *  - Admin React app: WP cookie/nonce (X-WP-Nonce), capability-checked.
 *  - Public form: guest create via nonce + rate limits (NOT api-key auth).
 *  - External consumers: X-WPSD-API-Key / X-WPSD-API-Secret (+ optional HMAC).
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_REST
 */
class WPSD_REST {

	/**
	 * Register all routes.
	 */
	public function register() {
		$ns = WPSD_REST_NAMESPACE;

		// Ticket creation (web + public + external, identical validation).
		register_rest_route(
			$ns,
			'/tickets',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_ticket' ),
					'permission_callback' => array( $this, 'can_create_ticket' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_tickets' ),
					'permission_callback' => array( $this, 'can_list_tickets' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/tickets/by-id/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_ticket_by_id' ),
				'permission_callback' => array( $this, 'can_view_tickets' ),
			)
		);

		register_rest_route(
			$ns,
			'/tickets/(?P<ticket_number>[A-Za-z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_ticket' ),
				'permission_callback' => array( $this, 'can_read_ticket' ),
				'args'                => array(
					'ticket_number' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/tickets/(?P<id>\d+)/replies',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_reply' ),
				'permission_callback' => array( $this, 'can_manage_tickets' ),
			)
		);

		register_rest_route(
			$ns,
			'/tickets/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_ticket' ),
					'permission_callback' => array( $this, 'can_manage_tickets' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_ticket' ),
					'permission_callback' => array( $this, 'can_delete_tickets' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/tickets/(?P<id>\d+)/attachments',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_attachment' ),
				'permission_callback' => array( $this, 'can_manage_tickets' ),
			)
		);

		// Lookups: not fully open — see can_use_lookup(). Staff session,
		// external API key, or the same public-form nonce the guest ticket
		// form already carries (an API key can't gate these: it would have
		// to be embedded in public page JS, which defeats the point of a secret).
		register_rest_route(
			$ns,
			'/lookups/districts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_districts' ),
				'permission_callback' => array( $this, 'can_use_lookup' ),
			)
		);
		register_rest_route(
			$ns,
			'/lookups/thanas',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_thanas' ),
				'permission_callback' => array( $this, 'can_use_lookup' ),
			)
		);
		register_rest_route(
			$ns,
			'/lookups/routes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_routes' ),
				'permission_callback' => array( $this, 'can_use_lookup' ),
			)
		);
		register_rest_route(
			$ns,
			'/lookups/service-centers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_centers' ),
				'permission_callback' => array( $this, 'can_use_lookup' ),
			)
		);
		register_rest_route(
			$ns,
			'/lookups/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_products' ),
				'permission_callback' => array( $this, 'can_use_lookup' ),
			)
		);
		register_rest_route(
			$ns,
			'/lookups/problem-types',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_problem_types' ),
				'permission_callback' => array( $this, 'can_use_lookup' ),
			)
		);

		// Admin-only lookup CRUD.
		register_rest_route(
			$ns,
			'/admin/lookups/(?P<type>[a-z\-]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'admin_list_lookup' ),
					'permission_callback' => array( $this, 'can_manage_tickets' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'admin_create_lookup' ),
					'permission_callback' => array( $this, 'can_manage_tickets' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/admin/lookups/(?P<type>[a-z\-]+)/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'admin_update_lookup' ),
					'permission_callback' => array( $this, 'can_manage_tickets' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'admin_delete_lookup' ),
					'permission_callback' => array( $this, 'can_manage_tickets' ),
				),
			)
		);

		// Admin-only API client CRUD.
		register_rest_route(
			$ns,
			'/admin/api-clients',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'admin_list_clients' ),
					'permission_callback' => array( $this, 'can_manage_tickets' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'admin_create_client' ),
					'permission_callback' => array( $this, 'can_manage_tickets' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/admin/api-clients/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'admin_delete_client' ),
					'permission_callback' => array( $this, 'can_manage_tickets' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'admin_rotate_client' ),
					'permission_callback' => array( $this, 'can_manage_tickets' ),
				),
			)
		);

		// Stats (admin dashboard widgets).
		register_rest_route(
			$ns,
			'/admin/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'can_view_tickets' ),
			)
		);
	}

	// ------------------------------------------------------------------
	// Envelope helpers.
	// ------------------------------------------------------------------

	/**
	 * Successful envelope.
	 *
	 * @param mixed $data Data payload.
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	private function ok( $data, $status = 200 ) {
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
				'error'   => null,
			),
			$status
		);
	}

	/**
	 * Error envelope with a correct HTTP status (never 200 + buried error).
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human message.
	 * @param int    $status  HTTP status.
	 * @param mixed  $details Optional details (e.g. per-field errors).
	 * @return WP_Error
	 */
	private function fail( $code, $message, $status = 400, $details = null ) {
		$err = new WP_Error( $code, $message, array( 'status' => $status ) );
		if ( null !== $details ) {
			// add_data() overwrites (not merges) the data already stored for
			// $code, so re-add 'status' here or the constructor's status is
			// silently lost and WP's REST server falls back to a 200/500 default.
			$data = is_array( $details ) ? array_merge( array( 'status' => $status ), $details ) : $details;
			$err->add_data( $data, $code );
		}
		return $err;
	}

	/**
	 * Convert an internal WP_Error into an envelope-shaped WP_Error for REST.
	 *
	 * @param WP_Error $e Error.
	 * @return WP_Error
	 */
	private function from_error( $e ) {
		$status = (int) $e->get_error_data( $e->get_error_code() );
		if ( is_array( $e->get_error_data() ) && isset( $e->get_error_data()['status'] ) ) {
			$status = (int) $e->get_error_data()['status'];
		}
		if ( $status < 400 || $status > 599 ) {
			$data = $e->get_error_data();
			if ( is_array( $data ) && isset( $data['fields'] ) ) {
				$status = 400;
			} else {
				$status = 'wpsd_not_found' === $e->get_error_code() ? 404 : 400;
			}
			if ( 'wpsd_rate_limited' === $e->get_error_code() || 'wpsd_duplicate_ticket' === $e->get_error_code() ) {
				$status = 'wpsd_duplicate_ticket' === $e->get_error_code() ? 409 : 429;
			}
		}
		return $this->fail( $e->get_error_code(), $e->get_error_message(), $status, $e->get_error_data() );
	}

	// ------------------------------------------------------------------
	// Permission callbacks.
	// ------------------------------------------------------------------

	/**
	 * Ticket creation is open (guest form + external API share the endpoint).
	 * Abuse is handled by nonce (web) / API-key auth + rate limits, not by login.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_create_ticket( $request ) {
		return true;
	}

	/**
	 * List/search: staff with view cap, or external `full`-scope clients only.
	 * SPEC DECISION (Open Q6/Q7): partners never enumerate the system by default.
	 * Exception: guest single-ticket lookup via ?id=&phone_number= — ownership
	 * is verified in list_tickets() itself, same pattern as get_ticket()'s
	 * ticket_number+mobile guest path.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_list_tickets( $request ) {
		if ( absint( $request->get_param( 'id' ) ) && '' !== (string) $request->get_param( 'phone_number' ) ) {
			return true;
		}
		if ( current_user_can( 'wpsd_view_tickets' ) ) {
			return true;
		}
		$client = WPSD_API_Auth::authenticate( $request );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		if ( empty( $client ) ) {
			return $this->fail( 'wpsd_forbidden', __( 'Authentication required.', 'affiniti-wp-support' ), 401 );
		}
		if ( ! WPSD_API_Auth::scope_can_list( $client['scope'] ) ) {
			return $this->fail( 'wpsd_forbidden', __( 'Your API scope does not allow listing tickets.', 'affiniti-wp-support' ), 403 );
		}
		$request->set_param( 'wpsd_api_client', $client );
		return true;
	}

	/**
	 * Single-ticket read: staff, or owner via mobile, or scoped API client.
	 * Never an open lookup-by-ID.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_read_ticket( $request ) {
		if ( current_user_can( 'wpsd_view_tickets' ) ) {
			return true;
		}
		$client = WPSD_API_Auth::authenticate( $request );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		if ( ! empty( $client ) ) {
			if ( ! WPSD_API_Auth::scope_can_read( $client['scope'] ) ) {
				return $this->fail( 'wpsd_forbidden', __( 'Your API scope does not allow reading tickets.', 'affiniti-wp-support' ), 403 );
			}
			$request->set_param( 'wpsd_api_client', $client );
			return true;
		}
		// Guest ownership check happens in the callback (mobile required).
		return true;
	}

	/**
	 * Staff-only (nonce-authenticated via core X-WP-Nonce handling).
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage_tickets( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature required by WP.
		if ( current_user_can( 'wpsd_manage_tickets' ) ) {
			return true;
		}
		return $this->fail( 'wpsd_forbidden', __( 'You do not have permission to perform this action.', 'affiniti-wp-support' ), 403 );
	}

	/**
	 * Delete requires the delete cap (admins; agents do not have it).
	 *
	 * @return bool|WP_Error
	 */
	public function can_delete_tickets( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature required by WP.
		if ( current_user_can( 'wpsd_delete_tickets' ) ) {
			return true;
		}
		return $this->fail( 'wpsd_forbidden', __( 'You do not have permission to delete tickets.', 'affiniti-wp-support' ), 403 );
	}

	/**
	 * Stats require view cap.
	 *
	 * @return bool|WP_Error
	 */
	public function can_view_tickets( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature required by WP.
		if ( current_user_can( 'wpsd_view_tickets' ) ) {
			return true;
		}
		return $this->fail( 'wpsd_forbidden', __( 'You do not have permission to view statistics.', 'affiniti-wp-support' ), 403 );
	}

	/**
	 * Lookup reads: staff, an external API client, or the public-form nonce
	 * the guest ticket form already carries. Not `__return_true` — but also
	 * not the API key alone, since that secret can never safely live in
	 * public page JS. This is reference data (place names, product catalog),
	 * so the nonce is about keeping it off random scrapers, not protecting PII.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_use_lookup( $request ) {
		if ( current_user_can( 'wpsd_view_tickets' ) ) {
			return true;
		}
		$client = WPSD_API_Auth::authenticate( $request );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		if ( ! empty( $client ) ) {
			return true;
		}
		$nonce = (string) $request->get_param( 'wpsd_nonce' );
		if ( wp_verify_nonce( $nonce, 'wpsd_public_form' ) ) {
			return true;
		}
		return $this->fail( 'wpsd_forbidden', __( 'Authentication required.', 'affiniti-wp-support' ), 401 );
	}

	// ------------------------------------------------------------------
	// Tickets.
	// ------------------------------------------------------------------

	/**
	 * POST /tickets — identical validation for web, public React UI, and external API.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_ticket( $request ) {
		$params = $request->get_params();
		$is_logged_staff = current_user_can( 'wpsd_manage_tickets' );

		// Determine caller: external API credentials present?
		$client = WPSD_API_Auth::authenticate( $request );
		if ( is_wp_error( $client ) ) {
			return $this->from_error( $client );
		}
		$is_external = ! empty( $client );

		if ( $is_external ) {
			$source = 'api';
		} else {
			$source = 'web';
			// Guest/staff web submissions must carry the public-form nonce,
			// except staff using X-WP-Nonce on the same endpoint (admin app).
			$nonce = isset( $params['wpsd_nonce'] ) ? wp_unslash( $params['wpsd_nonce'] ) : '';
			$has_wp_nonce = ! empty( $request->get_header( 'X-WP-Nonce' ) );
			if ( ! $is_logged_staff || ! $has_wp_nonce ) {
				if ( ! wp_verify_nonce( $nonce, 'wpsd_public_form' ) ) {
					return $this->fail( 'wpsd_bad_nonce', __( 'Security check failed. Please reload the form and try again.', 'affiniti-wp-support' ), 403 );
				}
			}
		}

		// Rate limiting: per key for API, per IP/mobile for web.
		if ( $is_external ) {
			// Already checked inside authenticate().
		} else {
			$limited = WPSD_Rate_Limit::check_public_submission(
				WPSD_Rate_Limit::visitor_ip(),
				isset( $params['mobile'] ) ? preg_replace( '/\D/', '', (string) $params['mobile'] ) : ''
			);
			if ( is_wp_error( $limited ) ) {
				$response = $this->from_error( $limited );
				return $response;
			}
		}

		$clean = WPSD_Validator::validate_ticket_create( $params, $source );
		if ( is_wp_error( $clean ) ) {
			return $this->from_error( $clean );
		}

		$ticket = WPSD_Tickets::create( $clean, array( 'source' => $source ) );
		if ( is_wp_error( $ticket ) ) {
			return $this->from_error( $ticket );
		}

		return $this->ok(
			array(
				'ticket' => $this->public_ticket_shape( $ticket ),
			),
			201
		);
	}

	/**
	 * GET /tickets/{ticket_number} with ownership check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_ticket( $request ) {
		$number = sanitize_text_field( $request->get_param( 'ticket_number' ) );
		$ticket = WPSD_Tickets::get_by_number( $number );
		if ( ! $ticket ) {
			return $this->fail( 'wpsd_not_found', __( 'Ticket not found.', 'affiniti-wp-support' ), 404 );
		}

		// Staff: full access.
		if ( current_user_can( 'wpsd_view_tickets' ) ) {
			return $this->ok(
				array(
					'ticket'      => $ticket,
					'replies'     => WPSD_Tickets::get_replies( (int) $ticket['id'], true ),
					'attachments' => WPSD_Attachments::for_ticket( (int) $ticket['id'] ),
				)
			);
		}

		// External client: scoped read (no mobile needed; scope is the control).
		$client = $request->get_param( 'wpsd_api_client' );
		if ( is_array( $client ) && ! empty( $client ) ) {
			return $this->ok(
				array(
					'ticket'  => $this->public_ticket_shape( $ticket ),
					'replies' => WPSD_Tickets::get_replies( (int) $ticket['id'], false ),
				)
			);
		}

		// Guest: must prove ownership with the matching mobile number.
		$mobile_param = $request->get_param( 'mobile' );
		if ( empty( $mobile_param ) ) {
			return $this->fail( 'wpsd_owner_required', __( 'Provide the mobile number used on this ticket to view it.', 'affiniti-wp-support' ), 403 );
		}
		$mobile = WPSD_Validator::validate_bd_mobile( wp_unslash( $mobile_param ) );
		if ( is_wp_error( $mobile ) ) {
			return $this->from_error( $mobile );
		}
		if ( ! hash_equals( (string) $ticket['mobile'], (string) $mobile ) ) {
			// Generic message to avoid number enumeration.
			return $this->fail( 'wpsd_forbidden', __( 'Ticket not found or mobile does not match.', 'affiniti-wp-support' ), 403 );
		}

		return $this->ok(
			array(
				'ticket'  => $this->public_ticket_shape( $ticket ),
				'replies' => WPSD_Tickets::get_replies( (int) $ticket['id'], false ),
			)
		);
	}

	/**
	 * GET /tickets/by-id/{id} — staff-only single view with full thread
	 * (internal notes included) and attachments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_ticket_by_id( $request ) {
		$ticket = WPSD_Tickets::get_by_id( absint( $request->get_param( 'id' ) ) );
		if ( ! $ticket ) {
			return $this->fail( 'wpsd_not_found', __( 'Ticket not found.', 'affiniti-wp-support' ), 404 );
		}
		return $this->ok(
			array(
				'ticket'      => $ticket,
				'replies'     => WPSD_Tickets::get_replies( (int) $ticket['id'], true ),
				'attachments' => WPSD_Attachments::for_ticket( (int) $ticket['id'] ),
			)
		);
	}

	/**
	 * Public-safe ticket shape (drops nothing sensitive today beyond internal ids,
	 * but keeps a single choke point if the schema grows).
	 *
	 * @param array $ticket Full row.
	 * @return array
	 */
	private function public_ticket_shape( $ticket ) {
		return $ticket;
	}

	/**
	 * GET /tickets — paginated staff/API list, OR (when ?id=&phone_number=
	 * are both present) a guest single-ticket lookup verified by phone
	 * ownership — mirrors get_ticket()'s ticket_number+mobile guest path,
	 * just keyed by numeric id instead.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_tickets( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$phone = (string) $request->get_param( 'phone_number' );
		if ( $id && '' !== $phone ) {
			$ticket = WPSD_Tickets::get_by_id( $id );
			if ( ! $ticket ) {
				return $this->fail( 'wpsd_not_found', __( 'Ticket not found.', 'affiniti-wp-support' ), 404 );
			}
			$mobile = WPSD_Validator::validate_bd_mobile( wp_unslash( $phone ) );
			if ( is_wp_error( $mobile ) ) {
				return $this->from_error( $mobile );
			}
			if ( ! hash_equals( (string) $ticket['mobile'], (string) $mobile ) ) {
				// Generic message to avoid id enumeration.
				return $this->fail( 'wpsd_forbidden', __( 'Ticket not found or phone number does not match.', 'affiniti-wp-support' ), 403 );
			}
			return $this->ok( array( 'ticket' => $this->public_ticket_shape( $ticket ) ) );
		}

		$args = array(
			'status'            => sanitize_key( (string) $request->get_param( 'status' ) ),
			'assigned_agent_id' => absint( $request->get_param( 'assigned_agent_id' ) ),
			'search'            => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'page'              => absint( $request->get_param( 'page' ) ),
			'per_page'          => absint( $request->get_param( 'per_page' ) ),
			'orderby'           => sanitize_key( (string) $request->get_param( 'orderby' ) ),
			'order'             => sanitize_key( (string) $request->get_param( 'order' ) ),
		);
		$args = array_filter(
			$args,
			function ( $v ) {
				return '' !== $v && null !== $v && 0 !== $v;
			}
		);
		if ( isset( $args['status'] ) && ! in_array( $args['status'], WPSD_Validator::statuses(), true ) ) {
			return $this->fail( 'wpsd_bad_status', __( 'Invalid status filter.', 'affiniti-wp-support' ), 400 );
		}
		$result = WPSD_Tickets::search( $args );
		return $this->ok( $result );
	}

	/**
	 * POST /tickets/{id}/replies — staff only in V1.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_reply( $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$params = $request->get_params();
		$clean  = WPSD_Validator::validate_reply( $params );
		if ( is_wp_error( $clean ) ) {
			return $this->from_error( $clean );
		}
		// Only agents/admins may flag internal notes; force public otherwise (defense in depth).
		if ( ! current_user_can( 'wpsd_manage_tickets' ) ) {
			$clean['is_internal_note'] = 0;
		}
		$reply = WPSD_Tickets::add_reply( $id, $clean, get_current_user_id(), 'agent' );
		if ( is_wp_error( $reply ) ) {
			return $this->from_error( $reply );
		}
		return $this->ok( array( 'reply' => $reply ), 201 );
	}

	/**
	 * PATCH /tickets/{id} — staff-only update of status/priority/assignment
	 * plus editable customer, location, and product fields (validated,
	 * hierarchy-checked). NOT exposed to external API clients in V1
	 * (permission callback enforces staff).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_ticket( $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$params = $request->get_params();
		$clean  = WPSD_Validator::validate_ticket_update( $params );
		if ( is_wp_error( $clean ) ) {
			return $this->from_error( $clean );
		}
		if ( array_key_exists( 'assigned_agent_id', $clean ) && ! current_user_can( 'wpsd_assign_tickets' ) ) {
			return $this->fail( 'wpsd_forbidden', __( 'You cannot assign tickets.', 'affiniti-wp-support' ), 403 );
		}
		$updated = WPSD_Tickets::update_admin_fields( $id, $clean, get_current_user_id() );
		if ( is_wp_error( $updated ) ) {
			return $this->from_error( $updated );
		}
		return $this->ok( array( 'ticket' => $updated ) );
	}

	/**
	 * DELETE /tickets/{id} — delete cap only (admins).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_ticket( $request ) {
		$id  = absint( $request->get_param( 'id' ) );
		$res = WPSD_Tickets::delete( $id );
		if ( is_wp_error( $res ) ) {
			return $this->from_error( $res );
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * POST /tickets/{id}/attachments — staff only in V1.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_attachment( $request ) {
		$id = absint( $request->get_param( 'id' ) );
		if ( ! WPSD_Tickets::get_by_id( $id ) ) {
			return $this->fail( 'wpsd_not_found', __( 'Ticket not found.', 'affiniti-wp-support' ), 404 );
		}
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return $this->fail( 'wpsd_bad_upload', __( 'Attach a file using the "file" field.', 'affiniti-wp-support' ), 400 );
		}
		$reply_id = absint( $request->get_param( 'reply_id' ) );
		$row      = WPSD_Attachments::handle_upload( $files['file'], $id, $reply_id ? $reply_id : null, get_current_user_id() );
		if ( is_wp_error( $row ) ) {
			return $this->from_error( $row );
		}
		return $this->ok( array( 'attachment' => $row ), 201 );
	}

	// ------------------------------------------------------------------
	// Lookups.
	// ------------------------------------------------------------------

	/**
	 * GET /lookups/districts
	 *
	 * @return WP_REST_Response
	 */
	public function get_districts( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature required.
		return $this->ok( array( 'items' => WPSD_Lookups::districts() ) );
	}

	/**
	 * GET /lookups/thanas?district_id=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_thanas( $request ) {
		$district_id = absint( $request->get_param( 'district_id' ) );
		if ( ! $district_id ) {
			return $this->fail( 'wpsd_missing_param', __( 'district_id is required.', 'affiniti-wp-support' ), 400 );
		}
		return $this->ok( array( 'items' => WPSD_Lookups::thanas( $district_id ) ) );
	}

	/**
	 * GET /lookups/routes?thana_id=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_routes( $request ) {
		$thana_id = absint( $request->get_param( 'thana_id' ) );
		if ( ! $thana_id ) {
			return $this->fail( 'wpsd_missing_param', __( 'thana_id is required.', 'affiniti-wp-support' ), 400 );
		}
		return $this->ok( array( 'items' => WPSD_Lookups::routes( $thana_id ) ) );
	}

	/**
	 * GET /lookups/service-centers?route_id=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_centers( $request ) {
		$route_id = absint( $request->get_param( 'route_id' ) );
		if ( ! $route_id ) {
			return $this->fail( 'wpsd_missing_param', __( 'route_id is required.', 'affiniti-wp-support' ), 400 );
		}
		return $this->ok( array( 'items' => WPSD_Lookups::service_centers( $route_id ) ) );
	}

	/**
	 * GET /lookups/products?search= — sourced from the live WooCommerce catalog.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_products( $request ) {
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		return $this->ok( array( 'items' => WPSD_Lookups::products( $search ) ) );
	}

	/**
	 * GET /lookups/problem-types?product_id=
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_problem_types( $request ) {
		$product_id = absint( $request->get_param( 'product_id' ) );
		$rows       = WPSD_Lookups::problem_types( $product_id );
		if ( is_wp_error( $rows ) ) {
			return $this->from_error( $rows );
		}
		return $this->ok( array( 'items' => $rows ) );
	}

	// ------------------------------------------------------------------
	// Admin lookup CRUD.
	// ------------------------------------------------------------------

	/**
	 * Normalize the {type} segment to a table key.
	 *
	 * @param string $type URL segment.
	 * @return string|WP_Error
	 */
	private function lookup_type_to_key( $type ) {
		$map = array(
			'districts'       => 'districts',
			'thanas'          => 'thanas',
			'routes'          => 'routes',
			'service-centers' => 'service_centers',
			'products'        => 'products',
			'problem-types'   => 'problem_types',
		);
		$type = sanitize_key( $type );
		if ( ! isset( $map[ $type ] ) ) {
			return new WP_Error( 'wpsd_bad_table', __( 'Unsupported lookup table.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
		}
		return $map[ $type ];
	}

	/**
	 * GET /admin/lookups/{type}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_list_lookup( $request ) {
		global $wpdb;
		$key = $this->lookup_type_to_key( $request->get_param( 'type' ) );
		if ( is_wp_error( $key ) ) {
			return $this->from_error( $key );
		}
		$table = WPSD_DB::table( $key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- table from allow-list.
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT 500", ARRAY_A );
		return $this->ok( array( 'items' => is_array( $rows ) ? $rows : array() ) );
	}

	/**
	 * POST /admin/lookups/{type}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_create_lookup( $request ) {
		global $wpdb;
		$key = $this->lookup_type_to_key( $request->get_param( 'type' ) );
		if ( is_wp_error( $key ) ) {
			return $this->from_error( $key );
		}
		$data = $this->sanitize_lookup_input( $key, $request->get_params() );
		if ( is_wp_error( $data ) ) {
			return $this->from_error( $data );
		}
		$table   = WPSD_DB::table( $key );
		$formats = array_map(
			function ( $v ) {
				return is_int( $v ) ? '%d' : '%s';
			},
			array_values( $data )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- admin-managed insert.
		$ok = $wpdb->insert( $table, $data, $formats );
		if ( false === $ok ) {
			return $this->fail( 'wpsd_db_error', __( 'Could not create the record.', 'affiniti-wp-support' ), 500 );
		}
		WPSD_Lookups::flush_cache();
		return $this->ok( array( 'id' => (int) $wpdb->insert_id ), 201 );
	}

	/**
	 * PUT /admin/lookups/{type}/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_update_lookup( $request ) {
		global $wpdb;
		$key = $this->lookup_type_to_key( $request->get_param( 'type' ) );
		if ( is_wp_error( $key ) ) {
			return $this->from_error( $key );
		}
		$data = $this->sanitize_lookup_input( $key, $request->get_params(), true );
		if ( is_wp_error( $data ) ) {
			return $this->from_error( $data );
		}
		if ( empty( $data ) ) {
			return $this->fail( 'wpsd_validation_failed', __( 'Nothing to update.', 'affiniti-wp-support' ), 400 );
		}
		$table   = WPSD_DB::table( $key );
		$formats = array_map(
			function ( $v ) {
				return is_int( $v ) ? '%d' : '%s';
			},
			array_values( $data )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- admin-managed update.
		$wpdb->update( $table, $data, array( 'id' => absint( $request->get_param( 'id' ) ) ), $formats, array( '%d' ) );
		WPSD_Lookups::flush_cache();
		return $this->ok( array( 'updated' => true ) );
	}

	/**
	 * DELETE /admin/lookups/{type}/{id} (blocked when referenced by tickets).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_delete_lookup( $request ) {
		global $wpdb;
		$key = $this->lookup_type_to_key( $request->get_param( 'type' ) );
		if ( is_wp_error( $key ) ) {
			return $this->from_error( $key );
		}
		$id = absint( $request->get_param( 'id' ) );

		// Referential guard: refuse when tickets reference the row.
		$guards = array(
			'districts'       => array( 'tickets', 'district_id' ),
			'thanas'          => array( 'tickets', 'thana_id' ),
			'routes'          => array( 'tickets', 'route_id' ),
			'service_centers' => array( 'tickets', 'service_center_id' ),
			'products'        => array( 'tickets', 'product_id' ),
			'problem_types'   => array( 'tickets', 'problem_type_id' ),
		);
		if ( isset( $guards[ $key ] ) ) {
			list( $ref_table, $ref_col ) = $guards[ $key ];
			$rt = WPSD_DB::table( $ref_table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- guard check, column allow-listed.
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$rt}` WHERE `{$ref_col}` = %d", $id ) );
			if ( $used > 0 ) {
				return $this->fail( 'wpsd_in_use', __( 'This record is used by tickets and cannot be deleted.', 'affiniti-wp-support' ), 409 );
			}
		}

		$table = WPSD_DB::table( $key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- admin-managed delete.
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		WPSD_Lookups::flush_cache();
		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Sanitize lookup input per table.
	 *
	 * @param string $key     Table key.
	 * @param array  $params  Request params.
	 * @param bool   $partial Allow partial (PUT) payloads.
	 * @return array|WP_Error
	 */
	private function sanitize_lookup_input( $key, $params, $partial = false ) {
		$get = function ( $field ) use ( $params ) {
			return isset( $params[ $field ] ) ? $params[ $field ] : null;
		};
		$data = array();

		switch ( $key ) {
			case 'districts':
				$name = $get( 'name' );
				if ( null === $name && $partial ) {
					break;
				}
				$name = sanitize_text_field( (string) $name );
				if ( '' === $name ) {
					return new WP_Error( 'wpsd_validation_failed', __( 'Name is required.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
				}
				$data['name'] = $name;
				break;
			case 'thanas':
				$district_id = $get( 'district_id' );
				$name        = $get( 'name' );
				if ( null !== $district_id || ! $partial ) {
					$district_id = absint( $district_id );
					if ( ! $district_id ) {
						return new WP_Error( 'wpsd_validation_failed', __( 'district_id is required.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
					}
					$data['district_id'] = $district_id;
				}
				if ( null !== $name || ! $partial ) {
					$name = sanitize_text_field( (string) $name );
					if ( '' === $name ) {
						return new WP_Error( 'wpsd_validation_failed', __( 'Name is required.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
					}
					$data['name'] = $name;
				}
				break;
			case 'routes':
				$thana_id = $get( 'thana_id' );
				$name     = $get( 'name' );
				if ( null !== $thana_id || ! $partial ) {
					$thana_id = absint( $thana_id );
					if ( ! $thana_id ) {
						return new WP_Error( 'wpsd_validation_failed', __( 'thana_id is required.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
					}
					$data['thana_id'] = $thana_id;
				}
				if ( null !== $name || ! $partial ) {
					$name = sanitize_text_field( (string) $name );
					if ( '' === $name ) {
						return new WP_Error( 'wpsd_validation_failed', __( 'Name is required.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
					}
					$data['name'] = $name;
				}
				break;
			case 'service_centers':
				foreach ( array( 'route_id' ) as $f ) {
					$v = $get( $f );
					if ( null !== $v || ! $partial ) {
						$v = absint( $v );
						if ( ! $v ) {
							return new WP_Error( 'wpsd_validation_failed', __( 'route_id is required.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
						}
						$data[ $f ] = $v;
					}
				}
				foreach ( array( 'name', 'address', 'contact_phone' ) as $f ) {
					$v = $get( $f );
					if ( null !== $v || ! $partial ) {
						$v = sanitize_text_field( (string) $v );
						if ( 'name' === $f && '' === $v ) {
							return new WP_Error( 'wpsd_validation_failed', __( 'Name is required.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
						}
						$data[ $f ] = $v;
					}
				}
				break;
			case 'products':
				foreach ( array( 'brand', 'model_name', 'category' ) as $f ) {
					$v = $get( $f );
					if ( null !== $v || ! $partial ) {
						$v = sanitize_text_field( (string) $v );
						if ( '' === $v ) {
							return new WP_Error( 'wpsd_validation_failed', __( 'Brand, model name, and category are required.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
						}
						$data[ $f ] = $v;
					}
				}
				if ( null !== $get( 'is_active' ) ) {
					$data['is_active'] = $get( 'is_active' ) ? 1 : 0;
				}
				break;
			case 'problem_types':
				$v = $get( 'label' );
				if ( null !== $v || ! $partial ) {
					$v = sanitize_text_field( (string) $v );
					if ( '' === $v ) {
						return new WP_Error( 'wpsd_validation_failed', __( 'Label is required.', 'affiniti-wp-support' ), array( 'status' => 400 ) );
					}
					$data['label'] = $v;
				}
				if ( null !== $get( 'product_category' ) ) {
					$cat = sanitize_text_field( (string) $get( 'product_category' ) );
					$data['product_category'] = '' === $cat ? null : $cat;
				} elseif ( ! $partial ) {
					$data['product_category'] = null;
				}
				if ( null !== $get( 'is_active' ) ) {
					$data['is_active'] = $get( 'is_active' ) ? 1 : 0;
				}
				break;
		}
		return $data;
	}

	// ------------------------------------------------------------------
	// Admin API clients.
	// ------------------------------------------------------------------

	/**
	 * GET /admin/api-clients (secrets never returned).
	 *
	 * @return WP_REST_Response
	 */
	public function admin_list_clients( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature required.
		global $wpdb;
		$table = WPSD_DB::table( 'api_clients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- admin list, no secrets selected.
		$rows = $wpdb->get_results( "SELECT id, client_name, api_key, is_active, rate_limit_per_minute, scope, allowed_ips, created_at, last_used_at FROM `{$table}` ORDER BY id DESC LIMIT 200", ARRAY_A );
		return $this->ok( array( 'items' => is_array( $rows ) ? $rows : array() ) );
	}

	/**
	 * POST /admin/api-clients — create + return the secret ONCE.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_create_client( $request ) {
		global $wpdb;
		$name = sanitize_text_field( (string) $request->get_param( 'client_name' ) );
		if ( '' === $name ) {
			return $this->fail( 'wpsd_validation_failed', __( 'Client name is required.', 'affiniti-wp-support' ), 400 );
		}

		// Cap total API clients (revoke deletes the row, so this is a plain
		// COUNT — no soft-disable state to account for).
		$max = (int) apply_filters( 'wpsd_max_api_clients', 2 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- capacity check.
		$existing = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . WPSD_DB::table( 'api_clients' ) . '`' );
		if ( $existing >= $max ) {
			return $this->fail(
				'wpsd_limit_reached',
				sprintf(
					/* translators: %d: maximum number of API clients allowed */
					__( 'Limit of %d API clients reached. Revoke one before creating another.', 'affiniti-wp-support' ),
					$max
				),
				409
			);
		}
		$scope = sanitize_key( (string) $request->get_param( 'scope' ) );
		if ( ! in_array( $scope, array( 'create_only', 'create_and_read', 'full' ), true ) ) {
			$scope = 'create_only';
		}
		$rate = absint( $request->get_param( 'rate_limit_per_minute' ) );
		$rate = $rate > 0 ? min( 1000, $rate ) : 60;
		$ips  = sanitize_text_field( (string) $request->get_param( 'allowed_ips' ) );

		$pair  = WPSD_API_Auth::generate_pair();
		$table = WPSD_DB::table( 'api_clients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- admin insert.
		$ok = $wpdb->insert(
			$table,
			array(
				'client_name'            => $name,
				'api_key'                => $pair['key'],
				'api_secret_hash'        => $pair['secret_hash'],
				'is_active'              => 1,
				'rate_limit_per_minute'  => $rate,
				'scope'                  => $scope,
				'allowed_ips'            => $ips,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		if ( false === $ok ) {
			return $this->fail( 'wpsd_db_error', __( 'Could not create the API client.', 'affiniti-wp-support' ), 500 );
		}
		return $this->ok(
			array(
				'id'         => (int) $wpdb->insert_id,
				'api_key'    => $pair['key'],
				// The secret is shown exactly once; it is stored hashed only.
				'api_secret' => $pair['secret'],
				'warning'    => __( 'Copy the secret now. It cannot be retrieved again — rotate to replace it.', 'affiniti-wp-support' ),
			),
			201
		);
	}

	/**
	 * DELETE /admin/api-clients/{id} — revoke.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function admin_delete_client( $request ) {
		global $wpdb;
		$table = WPSD_DB::table( 'api_clients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- admin revoke.
		$wpdb->delete( $table, array( 'id' => absint( $request->get_param( 'id' ) ) ), array( '%d' ) );
		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * POST /admin/api-clients/{id} with {"action":"rotate"} — rotate secret, return once.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_rotate_client( $request ) {
		global $wpdb;
		if ( 'rotate' !== sanitize_key( (string) $request->get_param( 'action' ) ) ) {
			return $this->fail( 'wpsd_bad_action', __( 'Unknown action. Use {"action":"rotate"}.', 'affiniti-wp-support' ), 400 );
		}
		$pair  = WPSD_API_Auth::generate_pair();
		$table = WPSD_DB::table( 'api_clients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- admin rotate.
		$wpdb->update(
			$table,
			array(
				'api_key'         => $pair['key'],
				'api_secret_hash' => $pair['secret_hash'],
			),
			array( 'id' => absint( $request->get_param( 'id' ) ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return $this->ok(
			array(
				'api_key'    => $pair['key'],
				'api_secret' => $pair['secret'],
				'warning'    => __( 'Copy the secret now. It cannot be retrieved again.', 'affiniti-wp-support' ),
			)
		);
	}

	/**
	 * GET /admin/stats
	 *
	 * @return WP_REST_Response
	 */
	public function get_stats( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature required.
		return $this->ok( WPSD_Tickets::stats() );
	}
}
