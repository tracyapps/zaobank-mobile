<?php
/**
 * JWT Authentication handler.
 *
 * Integrates JWT authentication with WordPress REST API.
 */
class ZAOBank_JWT_Auth {

	/**
	 * The error message from authentication.
	 */
	protected $auth_error = null;

	/**
	 * Authenticate a user via JWT token.
	 *
	 * @param int|bool $user_id The user ID or false.
	 * @return int|bool User ID if authenticated, false otherwise.
	 */
	public function authenticate($user_id) {
		// Don't override existing authentication
		if ($user_id) {
			return $user_id;
		}

		// Only apply to REST API requests
		if (!$this->is_rest_request()) {
			return $user_id;
		}

		$token = $this->get_token_from_request();

		if (!$token) {
			return $user_id;
		}

		$payload = ZAOBank_JWT_Tokens::validate_token($token);

		if (is_wp_error($payload)) {
			$this->auth_error = $this->normalize_auth_error($payload);
			return $user_id;
		}

		if (!isset($payload['sub'])) {
			$this->auth_error = $this->normalize_auth_error(new WP_Error(
				'missing_user_id',
				__('Token does not contain user ID.', 'zaobank-mobile')
			));
			return $user_id;
		}

		$user = get_user_by('ID', $payload['sub']);

		if (!$user) {
			$this->auth_error = $this->normalize_auth_error(new WP_Error(
				'user_not_found',
				__('User not found.', 'zaobank-mobile')
			));
			return $user_id;
		}

		return $user->ID;
	}

	/**
	 * Check for authentication errors.
	 *
	 * @param WP_Error|null|bool $error Current error state.
	 * @return WP_Error|null|bool Error or current state.
	 */
	public function check_authentication_error($error) {
		// Pass through existing errors
		if (!empty($error)) {
			return $error;
		}

		if (is_wp_error($this->auth_error)) {
			return $this->normalize_auth_error($this->auth_error);
		}

		return $this->auth_error;
	}

	/**
	 * Ensure authentication errors return an HTTP 401 status.
	 *
	 * @param WP_Error $error Error object.
	 * @return WP_Error Normalized error with status code.
	 */
	private function normalize_auth_error($error) {
		if (!is_wp_error($error)) {
			return new WP_Error(
				'auth_failed',
				__('Authentication failed.', 'zaobank-mobile'),
				array('status' => 401)
			);
		}

		$code = $error->get_error_code();
		$data = $error->get_error_data($code);

		if (!is_array($data) || !isset($data['status'])) {
			$error->add_data(array('status' => 401), $code);
		}

		return $error;
	}

	/**
	 * Check if this is a REST API request.
	 *
	 * @return bool True if REST request.
	 */
	private function is_rest_request() {
		if (defined('REST_REQUEST') && REST_REQUEST) {
			return true;
		}

		// Check if we're requesting a REST URL
		$rest_url = rest_url();
		$current_url = home_url(add_query_arg(array()));

		return strpos($current_url, $rest_url) !== false;
	}

	/**
	 * Extract JWT token from request.
	 *
	 * @return string|null Token or null.
	 */
	private function get_token_from_request() {
		// Check Authorization header first
		$auth_header = $this->get_authorization_header();

		if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
			return $matches[1];
		}

		// Fallback to query parameter (not recommended but useful for testing)
		if (isset($_GET['jwt_token'])) {
			return sanitize_text_field(wp_unslash($_GET['jwt_token']));
		}

		return null;
	}

	/**
	 * Get the Authorization header.
	 *
	 * @return string|null Header value or null.
	 */
	private function get_authorization_header() {
		// Apache/nginx with mod_rewrite
		if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
			return sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
		}

		// Apache with mod_setenvif
		if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
			return sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
		}

		// PHP-CGI/FastCGI
		if (function_exists('apache_request_headers')) {
			$headers = apache_request_headers();
			if (isset($headers['Authorization'])) {
				return $headers['Authorization'];
			}
			// Case-insensitive fallback
			foreach ($headers as $key => $value) {
				if (strtolower($key) === 'authorization') {
					return $value;
				}
			}
		}

		return null;
	}

	/**
	 * Get the current authenticated user from JWT.
	 *
	 * @return WP_User|null User or null.
	 */
	public static function get_current_user() {
		$token = (new self())->get_token_from_request();

		if (!$token) {
			return null;
		}

		$payload = ZAOBank_JWT_Tokens::validate_token($token);

		if (is_wp_error($payload)) {
			return null;
		}

		if (!isset($payload['sub'])) {
			return null;
		}

		return get_user_by('ID', $payload['sub']);
	}
}
