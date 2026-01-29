<?php
/**
 * REST API: App configuration endpoint.
 *
 * Provides app configuration, feature flags, and version information.
 */
class ZAOBank_Mobile_REST_Config {

	/**
	 * Namespace for REST API routes.
	 */
	protected $namespace = 'zaobank-mobile/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Get app configuration
		register_rest_route($this->namespace, '/config', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_config'),
			'permission_callback' => '__return_true',
		));

		// Check app version
		register_rest_route($this->namespace, '/config/version-check', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'check_version'),
			'permission_callback' => '__return_true',
			'args' => array(
				'version' => array(
					'required' => true,
					'type' => 'string',
					'description' => __('Current app version.', 'zaobank-mobile'),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'platform' => array(
					'type' => 'string',
					'enum' => array('ios', 'android', 'web'),
					'default' => 'ios',
				),
			),
		));
	}

	/**
	 * Get app configuration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_config($request) {
		$config = array(
			// API info
			'api' => array(
				'version' => '1',
				'base_url' => rest_url($this->namespace),
			),

			// App version requirements
			'app' => array(
				'min_version' => get_option('zaobank_mobile_min_app_version', '1.0.0'),
				'current_version' => ZAOBANK_MOBILE_VERSION,
				'update_url' => array(
					'ios' => get_option('zaobank_mobile_appstore_url', ''),
					'android' => get_option('zaobank_mobile_playstore_url', ''),
				),
				'testflight_url' => get_option('zaobank_mobile_testflight_url', ''),
			),

			// Location settings
			'location' => array(
				'default_radius' => (int) get_option('zaobank_mobile_default_radius', 25),
				'max_radius' => (int) get_option('zaobank_mobile_max_radius', 100),
				'distance_unit' => get_option('zaobank_mobile_distance_unit', 'miles'),
				'precision_options' => array('exact', 'block', 'city'),
			),

			// Feature flags
			'features' => array(
				'location_enabled' => true,
				'push_notifications' => false, // Future feature
				'in_app_messaging' => true,
				'ratings_enabled' => true,
				'regions_enabled' => (bool) get_option('zaobank_enable_regions', true),
			),

			// Authentication settings
			'auth' => array(
				'jwt_expiration_days' => (int) get_option('zaobank_mobile_jwt_expiration', 30),
				'refresh_expiration_days' => (int) get_option('zaobank_mobile_refresh_expiration', 90),
				'registration_enabled' => (bool) get_option('users_can_register'),
			),

			// Site info
			'site' => array(
				'name' => get_bloginfo('name'),
				'url' => home_url(),
				'description' => get_bloginfo('description'),
			),

			// Available regions
			'regions' => $this->get_regions(),
		);

		return new WP_REST_Response($config, 200);
	}

	/**
	 * Check app version compatibility.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function check_version($request) {
		$app_version = $request->get_param('version');
		$platform = $request->get_param('platform');

		$min_version = get_option('zaobank_mobile_min_app_version', '1.0.0');

		$is_compatible = version_compare($app_version, $min_version, '>=');
		$is_current = version_compare($app_version, ZAOBANK_MOBILE_VERSION, '>=');

		$response = array(
			'compatible' => $is_compatible,
			'current' => $is_current,
			'app_version' => $app_version,
			'min_version' => $min_version,
			'latest_version' => ZAOBANK_MOBILE_VERSION,
		);

		if (!$is_compatible) {
			$response['message'] = __('Please update the app to continue.', 'zaobank-mobile');
			$response['update_required'] = true;

			if ($platform === 'ios') {
				$response['update_url'] = get_option('zaobank_mobile_appstore_url') ?: get_option('zaobank_mobile_testflight_url');
			} else {
				$response['update_url'] = get_option('zaobank_mobile_playstore_url');
			}
		} elseif (!$is_current) {
			$response['message'] = __('A new version is available.', 'zaobank-mobile');
			$response['update_available'] = true;
		} else {
			$response['message'] = __('App is up to date.', 'zaobank-mobile');
		}

		return new WP_REST_Response($response, 200);
	}

	/**
	 * Get available regions.
	 *
	 * @return array Regions list.
	 */
	private function get_regions() {
		$terms = get_terms(array(
			'taxonomy' => 'zaobank_region',
			'hide_empty' => false,
		));

		if (is_wp_error($terms)) {
			return array();
		}

		return array_map(function($term) {
			return array(
				'id' => $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
				'description' => $term->description,
				'count' => $term->count,
			);
		}, $terms);
	}
}
