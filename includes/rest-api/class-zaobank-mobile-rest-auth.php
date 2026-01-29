<?php
/**
 * REST API: Authentication endpoints.
 *
 * Provides JWT-based login, registration, token refresh, and logout.
 */
class ZAOBank_Mobile_REST_Auth {

	/**
	 * Namespace for REST API routes.
	 */
	protected $namespace = 'zaobank-mobile/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Login
		register_rest_route($this->namespace, '/auth/login', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'login'),
			'permission_callback' => '__return_true',
			'args' => array(
				'username' => array(
					'required' => true,
					'type' => 'string',
					'description' => __('Username or email address.', 'zaobank-mobile'),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'password' => array(
					'required' => true,
					'type' => 'string',
					'description' => __('User password.', 'zaobank-mobile'),
				),
				'device_info' => array(
					'type' => 'string',
					'description' => __('Device information for token tracking.', 'zaobank-mobile'),
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		));

		// Register
		register_rest_route($this->namespace, '/auth/register', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'register'),
			'permission_callback' => '__return_true',
			'args' => array(
				'username' => array(
					'required' => true,
					'type' => 'string',
					'description' => __('Desired username.', 'zaobank-mobile'),
					'sanitize_callback' => 'sanitize_user',
				),
				'email' => array(
					'required' => true,
					'type' => 'string',
					'format' => 'email',
					'description' => __('Email address.', 'zaobank-mobile'),
					'sanitize_callback' => 'sanitize_email',
				),
				'password' => array(
					'required' => true,
					'type' => 'string',
					'description' => __('Password (minimum 8 characters).', 'zaobank-mobile'),
					'validate_callback' => function($value) {
						return strlen($value) >= 8;
					},
				),
				'display_name' => array(
					'type' => 'string',
					'description' => __('Display name.', 'zaobank-mobile'),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'device_info' => array(
					'type' => 'string',
					'description' => __('Device information.', 'zaobank-mobile'),
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		));

		// Refresh token
		register_rest_route($this->namespace, '/auth/refresh', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'refresh_token'),
			'permission_callback' => '__return_true',
			'args' => array(
				'refresh_token' => array(
					'required' => true,
					'type' => 'string',
					'description' => __('Refresh token.', 'zaobank-mobile'),
				),
			),
		));

		// Logout
		register_rest_route($this->namespace, '/auth/logout', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'logout'),
			'permission_callback' => '__return_true',
			'args' => array(
				'refresh_token' => array(
					'type' => 'string',
					'description' => __('Refresh token to revoke.', 'zaobank-mobile'),
				),
				'all_devices' => array(
					'type' => 'boolean',
					'default' => false,
					'description' => __('Revoke all tokens for this user.', 'zaobank-mobile'),
				),
			),
		));

		// Get current user (verify token)
		register_rest_route($this->namespace, '/auth/me', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_current_user'),
			'permission_callback' => array($this, 'check_authentication'),
		));
	}

	/**
	 * Login endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function login($request) {
		$username = $request->get_param('username');
		$password = $request->get_param('password');
		$device_info = $request->get_param('device_info');

		// Try to authenticate
		$user = wp_authenticate($username, $password);

		if (is_wp_error($user)) {
			return new WP_Error(
				'invalid_credentials',
				__('Invalid username or password.', 'zaobank-mobile'),
				array('status' => 401)
			);
		}

		return $this->generate_auth_response($user, $device_info);
	}

	/**
	 * Register endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function register($request) {
		$username = $request->get_param('username');
		$email = $request->get_param('email');
		$password = $request->get_param('password');
		$display_name = $request->get_param('display_name');
		$device_info = $request->get_param('device_info');

		// Check if registration is allowed
		if (!get_option('users_can_register')) {
			return new WP_Error(
				'registration_disabled',
				__('User registration is currently disabled.', 'zaobank-mobile'),
				array('status' => 403)
			);
		}

		// Check if username exists
		if (username_exists($username)) {
			return new WP_Error(
				'username_exists',
				__('Username already exists.', 'zaobank-mobile'),
				array('status' => 400)
			);
		}

		// Check if email exists
		if (email_exists($email)) {
			return new WP_Error(
				'email_exists',
				__('Email address already registered.', 'zaobank-mobile'),
				array('status' => 400)
			);
		}

		// Create user
		$user_id = wp_create_user($username, $password, $email);

		if (is_wp_error($user_id)) {
			return new WP_Error(
				'registration_failed',
				$user_id->get_error_message(),
				array('status' => 400)
			);
		}

		// Update display name if provided
		if ($display_name) {
			wp_update_user(array(
				'ID' => $user_id,
				'display_name' => $display_name,
			));
		}

		$user = get_user_by('ID', $user_id);

		// Trigger action for other plugins
		do_action('zaobank_mobile_user_registered', $user_id, $request);

		return $this->generate_auth_response($user, $device_info, 201);
	}

	/**
	 * Refresh token endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function refresh_token($request) {
		$refresh_token = $request->get_param('refresh_token');

		$user_id = ZAOBank_JWT_Tokens::validate_refresh_token($refresh_token);

		if (is_wp_error($user_id)) {
			return new WP_Error(
				'invalid_refresh_token',
				__('Invalid or expired refresh token.', 'zaobank-mobile'),
				array('status' => 401)
			);
		}

		$user = get_user_by('ID', $user_id);

		if (!$user) {
			return new WP_Error(
				'user_not_found',
				__('User not found.', 'zaobank-mobile'),
				array('status' => 404)
			);
		}

		// Generate new JWT token (keep existing refresh token valid)
		$jwt = ZAOBank_JWT_Tokens::generate_token($user->ID);

		if (is_wp_error($jwt)) {
			return $jwt;
		}

		return new WP_REST_Response(array(
			'token' => $jwt,
			'user' => $this->format_user($user),
		), 200);
	}

	/**
	 * Logout endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function logout($request) {
		$refresh_token = $request->get_param('refresh_token');
		$all_devices = $request->get_param('all_devices');

		// Get current user from JWT
		$user = ZAOBank_JWT_Auth::get_current_user();

		if ($all_devices && $user) {
			ZAOBank_JWT_Tokens::revoke_all_user_tokens($user->ID);
			return new WP_REST_Response(array(
				'message' => __('Logged out from all devices.', 'zaobank-mobile'),
			), 200);
		}

		if ($refresh_token) {
			ZAOBank_JWT_Tokens::revoke_refresh_token($refresh_token);
		}

		return new WP_REST_Response(array(
			'message' => __('Logged out successfully.', 'zaobank-mobile'),
		), 200);
	}

	/**
	 * Get current user endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_current_user($request) {
		$user = wp_get_current_user();

		return new WP_REST_Response(array(
			'user' => $this->format_user($user),
		), 200);
	}

	/**
	 * Check if user is authenticated via JWT.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if authenticated.
	 */
	public function check_authentication($request) {
		if (!is_user_logged_in()) {
			return new WP_Error(
				'rest_forbidden',
				__('You must be logged in to perform this action.', 'zaobank-mobile'),
				array('status' => 401)
			);
		}

		return true;
	}

	/**
	 * Generate authentication response with tokens.
	 *
	 * @param WP_User $user        User object.
	 * @param string  $device_info Device information.
	 * @param int     $status_code HTTP status code.
	 * @return WP_REST_Response Response.
	 */
	private function generate_auth_response($user, $device_info = '', $status_code = 200) {
		$jwt = ZAOBank_JWT_Tokens::generate_token($user->ID);

		if (is_wp_error($jwt)) {
			return $jwt;
		}

		$refresh = ZAOBank_JWT_Tokens::generate_refresh_token($user->ID, $device_info);

		return new WP_REST_Response(array(
			'token' => $jwt,
			'refresh_token' => $refresh['token'],
			'refresh_expires_at' => $refresh['expires_at'],
			'user' => $this->format_user($user),
		), $status_code);
	}

	/**
	 * Format user data for response.
	 *
	 * @param WP_User $user User object.
	 * @return array Formatted user data.
	 */
	private function format_user($user) {
		$data = array(
			'id' => $user->ID,
			'username' => $user->user_login,
			'email' => $user->user_email,
			'display_name' => $user->display_name,
			'registered' => $user->user_registered,
			'avatar_url' => get_avatar_url($user->ID),
		);

		// Add ACF profile fields if available
		if (function_exists('get_field')) {
			$data['skills'] = get_field('user_skills', 'user_' . $user->ID);
			$data['availability'] = get_field('user_availability', 'user_' . $user->ID);
			$data['bio'] = get_field('user_bio', 'user_' . $user->ID);
		}

		// Add location settings
		$data['location'] = ZAOBank_Location_Privacy::get_settings($user->ID);

		return $data;
	}
}
