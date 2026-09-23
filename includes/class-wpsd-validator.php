<?php
/**
 * Server-side validation (authoritative; client validation is UX only).
 *
 * @package WPSD
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPSD_Validator
 */
class WPSD_Validator {

	/**
	 * Allowed ticket statuses.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'new', 'assigned', 'in_progress', 'resolved', 'closed', 'cancelled' );
	}

	/**
	 * Allowed priorities.
	 *
	 * @return string[]
	 */
	public static function priorities() {
		return array( 'low', 'med', 'high' );
	}

	/**
	 * Allowed sources.
	 *
	 * @return string[]
	 */
	public static function sources() {
		return array( 'web', 'api' );
	}

	/**
	 * Validate a Bangladesh mobile number.
	 * Accepts 01XXXXXXXXX (11 digits) or +8801XXXXXXXXX.
	 *
	 * @param string $phone Raw input.
	 * @return string|WP_Error Normalized 01XXXXXXXXX number or error.
	 */
	public static function validate_bd_mobile( $phone ) {
		$phone = trim( (string) $phone );
		$phone = preg_replace( '/[\s\-()]/', '', $phone );

		if ( strpos( $phone, '+880' ) === 0 ) {
			$phone = '0' . substr( $phone, 4 );
		} elseif ( strpos( $phone, '880' ) === 0 && strlen( $phone ) === 13 ) {
			$phone = '0' . substr( $phone, 3 );
		}

		if ( ! preg_match( '/^01[3-9]\d{8}$/', $phone ) ) {
			return new WP_Error(
				'wpsd_invalid_mobile',
				__( 'Mobile number must be a valid Bangladeshi number (e.g. 01712345678).', 'affiniti-wp-support' )
			);
		}
		return $phone;
	}

	/**
	 * Validate the full ticket payload for creation.
	 * Includes the full district -> thana -> route -> service-center hierarchy check.
	 *
	 * @param array  $input  Raw input (already unslashed by caller where applicable).
	 * @param string $source 'web' or 'api'.
	 * @return array|WP_Error Sanitized payload or error (with per-field details in error data).
	 */
	public static function validate_ticket_create( $input, $source = 'web' ) {
		$errors = array();
		$out    = array();

		// Customer name.
		$name = isset( $input['customer_name'] ) ? sanitize_text_field( (string) $input['customer_name'] ) : '';
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 150 ) {
			$errors['customer_name'] = __( 'Name must be between 2 and 150 characters.', 'affiniti-wp-support' );
		} else {
			$out['customer_name'] = $name;
		}

		// Mobile.
		$mobile = self::validate_bd_mobile( isset( $input['mobile'] ) ? $input['mobile'] : '' );
		if ( is_wp_error( $mobile ) ) {
			$errors['mobile'] = $mobile->get_error_message();
		} else {
			$out['mobile'] = $mobile;
		}

		// Alternative mobile (optional).
		$alt_raw = isset( $input['alternative_mobile'] ) ? trim( (string) $input['alternative_mobile'] ) : '';
		if ( '' !== $alt_raw ) {
			$alt = self::validate_bd_mobile( $alt_raw );
			if ( is_wp_error( $alt ) ) {
				$errors['alternative_mobile'] = $alt->get_error_message();
			} else {
				$out['alternative_mobile'] = $alt;
			}
		} else {
			$out['alternative_mobile'] = null;
		}

		// Location ids.
		foreach ( array( 'district_id', 'thana_id', 'route_id', 'service_center_id', 'product_id', 'problem_type_id' ) as $field ) {
			$val = isset( $input[ $field ] ) ? absint( $input[ $field ] ) : 0;
			if ( $val <= 0 ) {
				$errors[ $field ] = __( 'This field is required.', 'affiniti-wp-support' );
			} else {
				$out[ $field ] = $val;
			}
		}

		// Address (max 50 chars, server-enforced).
		$address = isset( $input['address'] ) ? sanitize_text_field( (string) $input['address'] ) : '';
		if ( '' === $address ) {
			$errors['address'] = __( 'Address is required.', 'affiniti-wp-support' );
		} elseif ( mb_strlen( $address ) > 50 ) {
			$errors['address'] = __( 'Address must be 50 characters or fewer.', 'affiniti-wp-support' );
		} else {
			$out['address'] = $address;
		}

		// Barcode (optional free text, max 120).
		$barcode = isset( $input['barcode'] ) ? sanitize_text_field( (string) $input['barcode'] ) : '';
		if ( mb_strlen( $barcode ) > 120 ) {
			$errors['barcode'] = __( 'Barcode must be 120 characters or fewer.', 'affiniti-wp-support' );
		} else {
			$out['barcode'] = '' === $barcode ? null : $barcode;
		}

		// Comments (optional, kses-filtered, max 2000).
		$comments_raw = isset( $input['comments'] ) ? trim( (string) $input['comments'] ) : '';
		if ( mb_strlen( $comments_raw ) > 2000 ) {
			$errors['comments'] = __( 'Comments must be 2000 characters or fewer.', 'affiniti-wp-support' );
		} else {
			$out['comments'] = '' === $comments_raw ? null : wp_kses_post( $comments_raw );
		}

		if ( ! empty( $errors ) ) {
			$err = new WP_Error( 'wpsd_validation_failed', __( 'Validation failed.', 'affiniti-wp-support' ) );
			$err->add_data( array( 'fields' => $errors ), 'wpsd_validation_failed' );
			return $err;
		}

		// Hierarchy check: never trust client-sent IDs.
		$hierarchy = self::validate_location_hierarchy(
			$out['district_id'],
			$out['thana_id'],
			$out['route_id'],
			$out['service_center_id']
		);
		if ( is_wp_error( $hierarchy ) ) {
			return $hierarchy;
		}

		// Product + problem-type check (incl. category-specific vs global).
		$product_check = self::validate_product_problem( $out['product_id'], $out['problem_type_id'] );
		if ( is_wp_error( $product_check ) ) {
			return $product_check;
		}
		$out['brand_snapshot']          = $product_check['brand'];
		$out['product_name_snapshot']   = $product_check['model_name'];

		$out['source'] = in_array( $source, self::sources(), true ) ? $source : 'web';

		return $out;
	}

	/**
	 * Verify district -> thana -> route -> service center chain in the DB.
	 *
	 * @param int $district_id District id.
	 * @param int $thana_id    Thana id.
	 * @param int $route_id    Route id.
	 * @param int $center_id   Service center id.
	 * @return true|WP_Error
	 */
	public static function validate_location_hierarchy( $district_id, $thana_id, $route_id, $center_id ) {
		global $wpdb;

		$thanas  = WPSD_DB::table( 'thanas' );
		$routes  = WPSD_DB::table( 'routes' );
		$centers = WPSD_DB::table( 'service_centers' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- validation lookup.
		$thana_ok = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `{$thanas}` WHERE id = %d AND district_id = %d LIMIT 1", $thana_id, $district_id )
		);
		if ( ! $thana_ok ) {
			return new WP_Error( 'wpsd_bad_hierarchy', __( 'Thana does not belong to the selected district.', 'affiniti-wp-support' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- validation lookup.
		$route_ok = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `{$routes}` WHERE id = %d AND thana_id = %d LIMIT 1", $route_id, $thana_id )
		);
		if ( ! $route_ok ) {
			return new WP_Error( 'wpsd_bad_hierarchy', __( 'Route does not belong to the selected thana.', 'affiniti-wp-support' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- validation lookup.
		$center_ok = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `{$centers}` WHERE id = %d AND route_id = %d LIMIT 1", $center_id, $route_id )
		);
		if ( ! $center_ok ) {
			return new WP_Error( 'wpsd_bad_hierarchy', __( 'Service center does not serve the selected route.', 'affiniti-wp-support' ) );
		}

		return true;
	}

	/**
	 * Validate product exists and problem type applies (category-specific or global).
	 *
	 * @param int $product_id      Product id.
	 * @param int $problem_type_id Problem type id.
	 * @return array|WP_Error Array with brand + model_name, or error.
	 */
	public static function validate_product_problem( $product_id, $problem_type_id ) {
		global $wpdb;
		$products = WPSD_DB::table( 'products' );
		$problems = WPSD_DB::table( 'problem_types' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- validation lookup.
		$product = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, brand, model_name, category FROM `{$products}` WHERE id = %d AND is_active = 1 LIMIT 1", $product_id ),
			ARRAY_A
		);
		if ( ! $product ) {
			return new WP_Error( 'wpsd_bad_product', __( 'Selected product is invalid.', 'affiniti-wp-support' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- validation lookup.
		$problem = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, product_category FROM `{$problems}` WHERE id = %d AND is_active = 1 LIMIT 1", $problem_type_id ),
			ARRAY_A
		);
		if ( ! $problem ) {
			return new WP_Error( 'wpsd_bad_problem', __( 'Selected problem type is invalid.', 'affiniti-wp-support' ) );
		}

		$cat = $problem['product_category'];
		if ( null !== $cat && '' !== $cat && $cat !== $product['category'] ) {
			return new WP_Error( 'wpsd_bad_problem', __( 'Selected problem type does not apply to this product.', 'affiniti-wp-support' ) );
		}

		return array(
			'brand'      => $product['brand'],
			'model_name' => $product['model_name'],
		);
	}

	/**
	 * Validate admin PATCH payload (status/priority/assignment plus editable
	 * customer, location, and product fields). Location ids must be sent as a
	 * complete set of four so the hierarchy can be revalidated; product and
	 * problem type must be sent together so snapshots stay consistent.
	 *
	 * @param array $input Raw input.
	 * @return array|WP_Error
	 */
	public static function validate_ticket_update( $input ) {
		$out    = array();
		$errors = array();

		if ( array_key_exists( 'status', $input ) ) {
			$status = sanitize_key( $input['status'] );
			if ( ! in_array( $status, self::statuses(), true ) ) {
				$errors['status'] = __( 'Invalid status.', 'affiniti-wp-support' );
			} else {
				$out['status'] = $status;
			}
		}

		if ( array_key_exists( 'priority', $input ) ) {
			$priority = sanitize_key( $input['priority'] );
			if ( ! in_array( $priority, self::priorities(), true ) ) {
				$errors['priority'] = __( 'Invalid priority.', 'affiniti-wp-support' );
			} else {
				$out['priority'] = $priority;
			}
		}

		if ( array_key_exists( 'assigned_agent_id', $input ) ) {
			$agent = $input['assigned_agent_id'];
			if ( null === $agent || '' === $agent ) {
				$out['assigned_agent_id'] = null;
			} else {
				$agent_id = absint( $agent );
				$user     = get_user_by( 'id', $agent_id );
				if ( ! $user || ! user_can( $user, 'wpsd_view_tickets' ) ) {
					$errors['assigned_agent_id'] = __( 'Assignee must be a support agent or administrator.', 'affiniti-wp-support' );
				} else {
					$out['assigned_agent_id'] = $agent_id;
				}
			}
		}

		// Customer name.
		if ( array_key_exists( 'customer_name', $input ) ) {
			$name = sanitize_text_field( (string) $input['customer_name'] );
			if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 150 ) {
				$errors['customer_name'] = __( 'Name must be between 2 and 150 characters.', 'affiniti-wp-support' );
			} else {
				$out['customer_name'] = $name;
			}
		}

		// Mobile numbers.
		if ( array_key_exists( 'mobile', $input ) ) {
			$mobile = self::validate_bd_mobile( $input['mobile'] );
			if ( is_wp_error( $mobile ) ) {
				$errors['mobile'] = $mobile->get_error_message();
			} else {
				$out['mobile'] = $mobile;
			}
		}
		if ( array_key_exists( 'alternative_mobile', $input ) ) {
			$alt_raw = trim( (string) $input['alternative_mobile'] );
			if ( '' === $alt_raw ) {
				$out['alternative_mobile'] = '';
			} else {
				$alt = self::validate_bd_mobile( $alt_raw );
				if ( is_wp_error( $alt ) ) {
					$errors['alternative_mobile'] = $alt->get_error_message();
				} else {
					$out['alternative_mobile'] = $alt;
				}
			}
		}

		// Address / barcode / comments.
		if ( array_key_exists( 'address', $input ) ) {
			$address = sanitize_text_field( (string) $input['address'] );
			if ( '' === $address ) {
				$errors['address'] = __( 'Address is required.', 'affiniti-wp-support' );
			} elseif ( mb_strlen( $address ) > 50 ) {
				$errors['address'] = __( 'Address must be 50 characters or fewer.', 'affiniti-wp-support' );
			} else {
				$out['address'] = $address;
			}
		}
		if ( array_key_exists( 'barcode', $input ) ) {
			$barcode = sanitize_text_field( (string) $input['barcode'] );
			if ( mb_strlen( $barcode ) > 120 ) {
				$errors['barcode'] = __( 'Barcode must be 120 characters or fewer.', 'affiniti-wp-support' );
			} else {
				$out['barcode'] = $barcode;
			}
		}
		if ( array_key_exists( 'comments', $input ) ) {
			$comments_raw = trim( (string) $input['comments'] );
			if ( mb_strlen( $comments_raw ) > 2000 ) {
				$errors['comments'] = __( 'Comments must be 2000 characters or fewer.', 'affiniti-wp-support' );
			} else {
				$out['comments'] = '' === $comments_raw ? '' : wp_kses_post( $comments_raw );
			}
		}

		// Location: partial moves are rejected; a complete set is revalidated
		// against the hierarchy (never trust client-sent IDs).
		$loc_fields = array( 'district_id', 'thana_id', 'route_id', 'service_center_id' );
		$loc_given  = array();
		foreach ( $loc_fields as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$loc_given[ $field ] = absint( $input[ $field ] );
			}
		}
		if ( ! empty( $loc_given ) ) {
			$missing = array();
			foreach ( $loc_fields as $field ) {
				if ( ! isset( $loc_given[ $field ] ) || $loc_given[ $field ] <= 0 ) {
					$missing[] = $field;
				}
			}
			if ( ! empty( $missing ) ) {
				$errors['location'] = __( 'Location must be updated as a complete District / Thana / Route / Service Center set.', 'affiniti-wp-support' );
			} else {
				$hierarchy = self::validate_location_hierarchy(
					$loc_given['district_id'],
					$loc_given['thana_id'],
					$loc_given['route_id'],
					$loc_given['service_center_id']
				);
				if ( is_wp_error( $hierarchy ) ) {
					$errors['location'] = $hierarchy->get_error_message();
				} else {
					$out = array_merge( $out, $loc_given );
				}
			}
		}

		// Product + problem type: must travel together; snapshots refresh.
		$prod_given = array_key_exists( 'product_id', $input );
		$prob_given = array_key_exists( 'problem_type_id', $input );
		if ( $prod_given || $prob_given ) {
			$product_id = $prod_given ? absint( $input['product_id'] ) : 0;
			$problem_id = $prob_given ? absint( $input['problem_type_id'] ) : 0;
			if ( $product_id <= 0 || $problem_id <= 0 ) {
				$errors['product'] = __( 'Product and problem type must be updated together.', 'affiniti-wp-support' );
			} else {
				$check = self::validate_product_problem( $product_id, $problem_id );
				if ( is_wp_error( $check ) ) {
					$errors['product'] = $check->get_error_message();
				} else {
					$out['product_id']            = $product_id;
					$out['problem_type_id']       = $problem_id;
					$out['brand_snapshot']        = $check['brand'];
					$out['product_name_snapshot'] = $check['model_name'];
				}
			}
		}

		if ( empty( $out ) && empty( $errors ) ) {
			$errors['general'] = __( 'Nothing to update.', 'affiniti-wp-support' );
		}

		if ( ! empty( $errors ) ) {
			$err = new WP_Error( 'wpsd_validation_failed', __( 'Validation failed.', 'affiniti-wp-support' ) );
			$err->add_data( array( 'fields' => $errors ), 'wpsd_validation_failed' );
			return $err;
		}

		return $out;
	}

	/**
	 * Validate a reply payload.
	 *
	 * @param array $input Raw input.
	 * @return array|WP_Error
	 */
	public static function validate_reply( $input ) {
		$message = isset( $input['message'] ) ? trim( (string) $input['message'] ) : '';
		if ( '' === $message ) {
			return new WP_Error( 'wpsd_validation_failed', __( 'Message is required.', 'affiniti-wp-support' ) );
		}
		if ( mb_strlen( $message ) > 5000 ) {
			return new WP_Error( 'wpsd_validation_failed', __( 'Message must be 5000 characters or fewer.', 'affiniti-wp-support' ) );
		}
		$internal = ! empty( $input['is_internal_note'] ) ? 1 : 0;
		return array(
			'message'          => wp_kses_post( $message ),
			'is_internal_note' => $internal,
		);
	}
}
