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

		// Problem description: free text, not a curated type list.
		$problem = isset( $input['problem_description'] ) ? sanitize_text_field( (string) $input['problem_description'] ) : '';
		if ( mb_strlen( $problem ) < 3 || mb_strlen( $problem ) > 500 ) {
			$errors['problem_description'] = __( 'Describe the problem in 3 to 500 characters.', 'affiniti-wp-support' );
		} else {
			$out['problem_description'] = $problem;
		}

		// Address: a single free-text field now (no location hierarchy).
		$address = isset( $input['address'] ) ? sanitize_textarea_field( (string) $input['address'] ) : '';
		if ( '' === $address ) {
			$errors['address'] = __( 'Address is required.', 'affiniti-wp-support' );
		} elseif ( mb_strlen( $address ) > 500 ) {
			$errors['address'] = __( 'Address must be 500 characters or fewer.', 'affiniti-wp-support' );
		} else {
			$out['address'] = $address;
		}

		// Warranty ID / barcode (optional free text, max 120).
		$warranty_id = isset( $input['warranty_id'] ) ? sanitize_text_field( (string) $input['warranty_id'] ) : '';
		if ( mb_strlen( $warranty_id ) > 120 ) {
			$errors['warranty_id'] = __( 'Warranty ID must be 120 characters or fewer.', 'affiniti-wp-support' );
		} else {
			$out['warranty_id'] = '' === $warranty_id ? null : $warranty_id;
		}

		// Comments (optional, kses-filtered, max 2000).
		$comments_raw = isset( $input['comments'] ) ? trim( (string) $input['comments'] ) : '';
		if ( mb_strlen( $comments_raw ) > 2000 ) {
			$errors['comments'] = __( 'Comments must be 2000 characters or fewer.', 'affiniti-wp-support' );
		} else {
			$out['comments'] = '' === $comments_raw ? null : wp_kses_post( $comments_raw );
		}

		// Priority (optional; defaults to 'med' when not sent).
		$priority = isset( $input['priority'] ) ? sanitize_key( (string) $input['priority'] ) : 'med';
		if ( ! in_array( $priority, self::priorities(), true ) ) {
			$errors['priority'] = __( 'Invalid priority.', 'affiniti-wp-support' );
		} else {
			$out['priority'] = $priority;
		}

		if ( ! empty( $errors ) ) {
			$err = new WP_Error( 'wpsd_validation_failed', __( 'Validation failed.', 'affiniti-wp-support' ) );
			$err->add_data( array( 'fields' => $errors ), 'wpsd_validation_failed' );
			return $err;
		}

		$out['source'] = in_array( $source, self::sources(), true ) ? $source : 'web';

		return $out;
	}

	/**
	 * Validate admin PATCH payload (status/priority/assignment plus editable
	 * customer and address fields).
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
			$address = sanitize_textarea_field( (string) $input['address'] );
			if ( '' === $address ) {
				$errors['address'] = __( 'Address is required.', 'affiniti-wp-support' );
			} elseif ( mb_strlen( $address ) > 500 ) {
				$errors['address'] = __( 'Address must be 500 characters or fewer.', 'affiniti-wp-support' );
			} else {
				$out['address'] = $address;
			}
		}
		if ( array_key_exists( 'warranty_id', $input ) ) {
			$warranty_id = sanitize_text_field( (string) $input['warranty_id'] );
			if ( mb_strlen( $warranty_id ) > 120 ) {
				$errors['warranty_id'] = __( 'Warranty ID must be 120 characters or fewer.', 'affiniti-wp-support' );
			} else {
				$out['warranty_id'] = $warranty_id;
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
		if ( array_key_exists( 'problem_description', $input ) ) {
			$problem = sanitize_text_field( (string) $input['problem_description'] );
			if ( mb_strlen( $problem ) < 3 || mb_strlen( $problem ) > 500 ) {
				$errors['problem_description'] = __( 'Describe the problem in 3 to 500 characters.', 'affiniti-wp-support' );
			} else {
				$out['problem_description'] = $problem;
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
