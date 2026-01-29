<?php
/**
 * REST API: Location management endpoints.
 *
 * Handles user location settings and updates.
 */
class ZAOBank_Mobile_REST_Location {

	/**
	 * Namespace for REST API routes.
	 */
	protected $namespace = 'zaobank-mobile/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Get location settings
		register_rest_route($this->namespace, '/location/settings', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_settings'),
			'permission_callback' => array($this, 'check_authentication'),
		));

		// Update location settings
		register_rest_route($this->namespace, '/location/settings', array(
			'methods' => WP_REST_Server::EDITABLE,
			'callback' => array($this, 'update_settings'),
			'permission_callback' => array($this, 'check_authentication'),
			'args' => array(
				'enabled' => array(
					'type' => 'boolean',
					'description' => __('Enable location sharing.', 'zaobank-mobile'),
				),
				'precision' => array(
					'type' => 'string',
					'enum' => array('exact', 'block', 'city'),
					'description' => __('Location precision level.', 'zaobank-mobile'),
				),
			),
		));

		// Update user location
		register_rest_route($this->namespace, '/location/update', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'update_location'),
			'permission_callback' => array($this, 'check_authentication'),
			'args' => array(
				'latitude' => array(
					'required' => true,
					'type' => 'number',
					'description' => __('Latitude coordinate.', 'zaobank-mobile'),
					'validate_callback' => function($value) {
						return is_numeric($value) && $value >= -90 && $value <= 90;
					},
				),
				'longitude' => array(
					'required' => true,
					'type' => 'number',
					'description' => __('Longitude coordinate.', 'zaobank-mobile'),
					'validate_callback' => function($value) {
						return is_numeric($value) && $value >= -180 && $value <= 180;
					},
				),
			),
		));

		// Clear user location
		register_rest_route($this->namespace, '/location/clear', array(
			'methods' => WP_REST_Server::DELETABLE,
			'callback' => array($this, 'clear_location'),
			'permission_callback' => array($this, 'check_authentication'),
		));

		// Get current location
		register_rest_route($this->namespace, '/location', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_location'),
			'permission_callback' => array($this, 'check_authentication'),
		));
	}

	/**
	 * Get location settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_settings($request) {
		$user_id = get_current_user_id();
		$settings = ZAOBank_Location_Privacy::get_settings($user_id);

		return new WP_REST_Response(array(
			'settings' => $settings,
			'precision_options' => array(
				array(
					'value' => 'exact',
					'label' => __('Exact', 'zaobank-mobile'),
					'description' => __('Your precise location.', 'zaobank-mobile'),
				),
				array(
					'value' => 'block',
					'label' => __('Neighborhood', 'zaobank-mobile'),
					'description' => __('Approximate to ~200 meters.', 'zaobank-mobile'),
				),
				array(
					'value' => 'city',
					'label' => __('City', 'zaobank-mobile'),
					'description' => __('Approximate to city level (~5km).', 'zaobank-mobile'),
				),
			),
		), 200);
	}

	/**
	 * Update location settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function update_settings($request) {
		$user_id = get_current_user_id();
		$enabled = $request->get_param('enabled');
		$precision = $request->get_param('precision');

		// Get current settings as defaults
		$current_enabled = ZAOBank_Location_Privacy::is_location_enabled($user_id);
		$current_precision = ZAOBank_Location_Privacy::get_precision($user_id);

		$new_enabled = ($enabled !== null) ? (bool) $enabled : $current_enabled;
		$new_precision = $precision ?: $current_precision;

		// If disabling, clear location data
		if ($current_enabled && !$new_enabled) {
			ZAOBank_Location_Privacy::disable_location($user_id);
		} else {
			ZAOBank_Location_Privacy::update_settings($user_id, $new_enabled, $new_precision);
		}

		return new WP_REST_Response(array(
			'message' => __('Location settings updated.', 'zaobank-mobile'),
			'settings' => ZAOBank_Location_Privacy::get_settings($user_id),
		), 200);
	}

	/**
	 * Update user location.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function update_location($request) {
		$user_id = get_current_user_id();
		$latitude = (float) $request->get_param('latitude');
		$longitude = (float) $request->get_param('longitude');

		// Check if location is enabled
		if (!ZAOBank_Location_Privacy::is_location_enabled($user_id)) {
			return new WP_Error(
				'location_disabled',
				__('Please enable location sharing first.', 'zaobank-mobile'),
				array('status' => 400)
			);
		}

		$result = ZAOBank_Location_Privacy::update_location($user_id, $latitude, $longitude);

		if (is_wp_error($result)) {
			return $result;
		}

		return new WP_REST_Response(array(
			'message' => __('Location updated.', 'zaobank-mobile'),
			'location' => array(
				'latitude' => $result['latitude'],
				'longitude' => $result['longitude'],
				'precision' => ZAOBank_Location_Privacy::get_precision($user_id),
			),
		), 200);
	}

	/**
	 * Clear user location.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function clear_location($request) {
		$user_id = get_current_user_id();

		ZAOBank_Location_Privacy::clear_location($user_id);

		return new WP_REST_Response(array(
			'message' => __('Location cleared.', 'zaobank-mobile'),
		), 200);
	}

	/**
	 * Get current location.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_location($request) {
		$user_id = get_current_user_id();
		$location = ZAOBank_Location_Privacy::get_user_location($user_id);

		if (!$location) {
			return new WP_REST_Response(array(
				'location' => null,
				'enabled' => ZAOBank_Location_Privacy::is_location_enabled($user_id),
			), 200);
		}

		return new WP_REST_Response(array(
			'location' => $location,
			'enabled' => true,
		), 200);
	}

	/**
	 * Check if user is authenticated.
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
}
