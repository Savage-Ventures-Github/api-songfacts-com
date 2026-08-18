<?php
/**
 * REST endpoint that receives relayed interest-form submissions.
 *
 * Today n8n calls this after the Cloudflare Worker forwards it the payload
 * (see helpers-savage-ventures/README.md, Milestone 1). In Milestone 2 the
 * Worker will call this endpoint directly instead of n8n — the route and
 * its JWT auth are designed to need no changes when that happens, only the
 * caller changes.
 */

defined( 'ABSPATH' ) || exit;

class SF_LP_REST_Controller {

	const NAMESPACE_ = 'songfacts-crm/v1';

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/submissions',
			array(
				'methods'             => WP_REST_Server::CREATABLE, // POST
				'callback'            => array( __CLASS__, 'handle_create' ),
				'permission_callback' => array( __CLASS__, 'check_auth' ),
			)
		);
	}

	/**
	 * Verify the bearer JWT against the configured shared secret.
	 */
	public static function check_auth( WP_REST_Request $request ) {
		$auth_header = $request->get_header( 'authorization' );

		if ( empty( $auth_header ) || 0 !== stripos( $auth_header, 'Bearer ' ) ) {
			return new WP_Error( 'sf_missing_token', 'Missing bearer token.', array( 'status' => 401 ) );
		}

		$token  = trim( substr( $auth_header, 7 ) );
		$secret = get_option( SF_LP_Admin::OPTION_JWT_SECRET, '' );

		$result = SF_LP_JWT::verify( $token, $secret );

		return is_wp_error( $result ) ? $result : true;
	}

	public static function handle_create( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'sf_invalid_body', 'Expected a JSON object body.', array( 'status' => 400 ) );
		}

		$first_name = sanitize_text_field( $body['firstName'] ?? '' );
		$last_name  = sanitize_text_field( $body['lastName'] ?? '' );
		$email      = sanitize_email( $body['email'] ?? '' );
		$company    = sanitize_text_field( $body['company'] ?? '' );
		$message    = sanitize_textarea_field( $body['message'] ?? '' );
		$submitted  = $body['submittedAt'] ?? '';

		if ( '' === $first_name || '' === $last_name ) {
			return new WP_Error( 'sf_missing_name', 'firstName and lastName are required.', array( 'status' => 400 ) );
		}

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'sf_invalid_email', 'A valid email is required.', array( 'status' => 400 ) );
		}

		foreach ( array( 'first_name' => $first_name, 'last_name' => $last_name, 'company' => $company ) as $field => $value ) {
			if ( strlen( $value ) > 191 ) {
				return new WP_Error( 'sf_field_too_long', "{$field} exceeds the maximum length.", array( 'status' => 400 ) );
			}
		}

		if ( strlen( $message ) > 5000 ) {
			return new WP_Error( 'sf_field_too_long', 'message exceeds the maximum length.', array( 'status' => 400 ) );
		}

		$submitted_at = self::to_mysql_datetime( $submitted );

		$id = SF_LP_DB::insert(
			array(
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'email'        => $email,
				'company'      => $company,
				'message'      => $message,
				'submitted_at' => $submitted_at,
				// Stored for audit/forward-compat; never includes the bearer token itself.
				'raw_payload'  => wp_json_encode( array_diff_key( $body, array( 'turnstileToken' => true ) ) ),
			)
		);

		if ( ! $id ) {
			return new WP_Error( 'sf_insert_failed', 'Could not save submission.', array( 'status' => 500 ) );
		}

		// Admin notifications hang off this, reading the row back so they see
		// exactly what was stored (received_at, defaults) rather than the
		// pre-insert array. Fired only for real submissions — the sample-data
		// buttons go straight to SF_LP_DB::insert() and never reach here.
		$stored = SF_LP_DB::get( $id );
		do_action( 'sf_lp_submission_received', (int) $id, is_array( $stored ) ? $stored : array() );

		return new WP_REST_Response( array( 'id' => $id, 'status' => 'received' ), 201 );
	}

	private static function to_mysql_datetime( $iso8601 ) {
		if ( empty( $iso8601 ) ) {
			return null;
		}

		try {
			$date = new DateTime( $iso8601 );
			$date->setTimezone( new DateTimeZone( 'UTC' ) );
			return $date->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return null;
		}
	}
}
