<?php
/**
 * Minimal HS256 JWT verifier.
 *
 * Verifies tokens produced by the Cloudflare Worker (songfacts-api-interest-submission)
 * using the same shared secret (JWT_SIGNING_SECRET on the Worker side). No JWT library
 * dependency, in keeping with the rest of this project's no-build-step approach.
 */

defined( 'ABSPATH' ) || exit;

class SF_LP_JWT {

	/**
	 * Verify a JWT and return its decoded payload, or a WP_Error on failure.
	 *
	 * @param string $token  Raw JWT string (no "Bearer " prefix).
	 * @param string $secret Shared HMAC secret.
	 * @return array|WP_Error
	 */
	public static function verify( $token, $secret ) {
		if ( empty( $secret ) ) {
			return new WP_Error( 'sf_jwt_no_secret', 'JWT secret is not configured.', array( 'status' => 500 ) );
		}

		$parts = explode( '.', (string) $token );
		if ( count( $parts ) !== 3 ) {
			return new WP_Error( 'sf_jwt_malformed', 'Malformed token.', array( 'status' => 401 ) );
		}

		list( $header_b64, $payload_b64, $signature_b64 ) = $parts;

		$header = json_decode( self::base64url_decode( $header_b64 ), true );
		if ( ! is_array( $header ) || strtoupper( $header['alg'] ?? '' ) !== 'HS256' ) {
			return new WP_Error( 'sf_jwt_bad_alg', 'Unsupported or missing token algorithm.', array( 'status' => 401 ) );
		}

		$expected_signature = hash_hmac( 'sha256', $header_b64 . '.' . $payload_b64, $secret, true );
		$actual_signature   = self::base64url_decode( $signature_b64 );

		if ( ! hash_equals( $expected_signature, $actual_signature ) ) {
			return new WP_Error( 'sf_jwt_bad_signature', 'Token signature verification failed.', array( 'status' => 401 ) );
		}

		$payload = json_decode( self::base64url_decode( $payload_b64 ), true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'sf_jwt_bad_payload', 'Malformed token payload.', array( 'status' => 401 ) );
		}

		if ( isset( $payload['exp'] ) && time() > (int) $payload['exp'] ) {
			return new WP_Error( 'sf_jwt_expired', 'Token has expired.', array( 'status' => 401 ) );
		}

		if ( isset( $payload['nbf'] ) && time() < (int) $payload['nbf'] ) {
			return new WP_Error( 'sf_jwt_not_yet_valid', 'Token not yet valid.', array( 'status' => 401 ) );
		}

		return $payload;
	}

	private static function base64url_decode( $data ) {
		$remainder = strlen( $data ) % 4;
		if ( $remainder ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}
		return base64_decode( strtr( $data, '-_', '+/' ) );
	}
}
