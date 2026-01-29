<?php
/**
 * JWT Token generation and validation.
 *
 * Implements stateless JWT tokens for mobile authentication.
 */
class ZAOBank_JWT_Tokens {

	/**
	 * JWT algorithm.
	 */
	const ALGORITHM = 'HS256';

	/**
	 * Generate a JWT token for a user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $extra_claims Additional claims to include.
	 * @return string JWT token.
	 */
	public static function generate_token($user_id, $extra_claims = array()) {
		$secret = get_option('zaobank_mobile_jwt_secret');
		$expiration_days = (int) get_option('zaobank_mobile_jwt_expiration', 30);
		$issued_at = time();
		$expiration = $issued_at + ($expiration_days * DAY_IN_SECONDS);

		$user = get_user_by('ID', $user_id);
		if (!$user) {
			return new WP_Error('invalid_user', __('User not found.', 'zaobank-mobile'));
		}

		$payload = array_merge(array(
			'iss' => get_bloginfo('url'),
			'iat' => $issued_at,
			'exp' => $expiration,
			'sub' => $user_id,
			'email' => $user->user_email,
			'name' => $user->display_name,
		), $extra_claims);

		return self::encode($payload, $secret);
	}

	/**
	 * Validate and decode a JWT token.
	 *
	 * @param string $token JWT token.
	 * @return array|WP_Error Decoded payload or error.
	 */
	public static function validate_token($token) {
		$secret = get_option('zaobank_mobile_jwt_secret');

		if (empty($token)) {
			return new WP_Error('empty_token', __('Token is empty.', 'zaobank-mobile'));
		}

		$decoded = self::decode($token, $secret);

		if (is_wp_error($decoded)) {
			return $decoded;
		}

		// Check expiration
		if (isset($decoded['exp']) && $decoded['exp'] < time()) {
			return new WP_Error('token_expired', __('Token has expired.', 'zaobank-mobile'));
		}

		// Check issuer
		if (isset($decoded['iss']) && $decoded['iss'] !== get_bloginfo('url')) {
			return new WP_Error('invalid_issuer', __('Invalid token issuer.', 'zaobank-mobile'));
		}

		return $decoded;
	}

	/**
	 * Generate a refresh token.
	 *
	 * @param int    $user_id User ID.
	 * @param string $device_info Optional device information.
	 * @return array Token and expiration data.
	 */
	public static function generate_refresh_token($user_id, $device_info = '') {
		global $wpdb;

		$token = wp_generate_password(64, false);
		$token_hash = wp_hash_password($token);
		$expiration_days = (int) get_option('zaobank_mobile_refresh_expiration', 90);
		$expires_at = gmdate('Y-m-d H:i:s', time() + ($expiration_days * DAY_IN_SECONDS));

		$table = $wpdb->prefix . 'zaobank_mobile_refresh_tokens';

		$wpdb->insert(
			$table,
			array(
				'user_id' => $user_id,
				'token_hash' => $token_hash,
				'device_info' => sanitize_text_field($device_info),
				'expires_at' => $expires_at,
				'created_at' => current_time('mysql', true),
			),
			array('%d', '%s', '%s', '%s', '%s')
		);

		return array(
			'token' => $token,
			'expires_at' => $expires_at,
			'id' => $wpdb->insert_id,
		);
	}

	/**
	 * Validate a refresh token.
	 *
	 * @param string $token Refresh token.
	 * @return int|WP_Error User ID or error.
	 */
	public static function validate_refresh_token($token) {
		global $wpdb;

		$table = $wpdb->prefix . 'zaobank_mobile_refresh_tokens';

		// Get all non-revoked, non-expired tokens
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, token_hash FROM $table
				WHERE expires_at > %s
				AND revoked_at IS NULL",
				current_time('mysql', true)
			)
		);

		foreach ($rows as $row) {
			if (wp_check_password($token, $row->token_hash)) {
				// Update last used timestamp
				$wpdb->update(
					$table,
					array('last_used_at' => current_time('mysql', true)),
					array('id' => $row->id),
					array('%s'),
					array('%d')
				);

				return (int) $row->user_id;
			}
		}

		return new WP_Error('invalid_refresh_token', __('Invalid or expired refresh token.', 'zaobank-mobile'));
	}

	/**
	 * Revoke a refresh token.
	 *
	 * @param string $token Refresh token.
	 * @return bool Success.
	 */
	public static function revoke_refresh_token($token) {
		global $wpdb;

		$table = $wpdb->prefix . 'zaobank_mobile_refresh_tokens';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, token_hash FROM $table WHERE revoked_at IS NULL"
			)
		);

		foreach ($rows as $row) {
			if (wp_check_password($token, $row->token_hash)) {
				$wpdb->update(
					$table,
					array('revoked_at' => current_time('mysql', true)),
					array('id' => $row->id),
					array('%s'),
					array('%d')
				);
				return true;
			}
		}

		return false;
	}

	/**
	 * Revoke all refresh tokens for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int Number of tokens revoked.
	 */
	public static function revoke_all_user_tokens($user_id) {
		global $wpdb;

		$table = $wpdb->prefix . 'zaobank_mobile_refresh_tokens';

		return $wpdb->update(
			$table,
			array('revoked_at' => current_time('mysql', true)),
			array('user_id' => $user_id, 'revoked_at' => null),
			array('%s'),
			array('%d', '%s')
		);
	}

	/**
	 * Encode payload to JWT.
	 *
	 * @param array  $payload Data to encode.
	 * @param string $secret  Secret key.
	 * @return string JWT token.
	 */
	private static function encode($payload, $secret) {
		$header = array(
			'typ' => 'JWT',
			'alg' => self::ALGORITHM,
		);

		$segments = array(
			self::base64url_encode(wp_json_encode($header)),
			self::base64url_encode(wp_json_encode($payload)),
		);

		$signing_input = implode('.', $segments);
		$signature = self::sign($signing_input, $secret);
		$segments[] = self::base64url_encode($signature);

		return implode('.', $segments);
	}

	/**
	 * Decode JWT to payload.
	 *
	 * @param string $token  JWT token.
	 * @param string $secret Secret key.
	 * @return array|WP_Error Decoded payload or error.
	 */
	private static function decode($token, $secret) {
		$parts = explode('.', $token);

		if (count($parts) !== 3) {
			return new WP_Error('invalid_token_format', __('Invalid token format.', 'zaobank-mobile'));
		}

		list($header_b64, $payload_b64, $signature_b64) = $parts;

		$header = json_decode(self::base64url_decode($header_b64), true);
		$payload = json_decode(self::base64url_decode($payload_b64), true);
		$signature = self::base64url_decode($signature_b64);

		if (!$header || !$payload) {
			return new WP_Error('invalid_token_data', __('Invalid token data.', 'zaobank-mobile'));
		}

		// Verify algorithm
		if (!isset($header['alg']) || $header['alg'] !== self::ALGORITHM) {
			return new WP_Error('invalid_algorithm', __('Invalid token algorithm.', 'zaobank-mobile'));
		}

		// Verify signature
		$signing_input = $header_b64 . '.' . $payload_b64;
		$expected_signature = self::sign($signing_input, $secret);

		if (!hash_equals($expected_signature, $signature)) {
			return new WP_Error('invalid_signature', __('Invalid token signature.', 'zaobank-mobile'));
		}

		return $payload;
	}

	/**
	 * Sign data with HMAC SHA256.
	 *
	 * @param string $data   Data to sign.
	 * @param string $secret Secret key.
	 * @return string Signature.
	 */
	private static function sign($data, $secret) {
		return hash_hmac('sha256', $data, $secret, true);
	}

	/**
	 * Base64 URL-safe encode.
	 *
	 * @param string $data Data to encode.
	 * @return string Encoded data.
	 */
	private static function base64url_encode($data) {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Base64 URL-safe decode.
	 *
	 * @param string $data Data to decode.
	 * @return string Decoded data.
	 */
	private static function base64url_decode($data) {
		$remainder = strlen($data) % 4;
		if ($remainder) {
			$data .= str_repeat('=', 4 - $remainder);
		}
		return base64_decode(strtr($data, '-_', '+/'));
	}
}
