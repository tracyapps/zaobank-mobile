<?php
/**
 * Admin-specific functionality for ZAO Bank Mobile.
 */
class ZAOBank_Mobile_Admin {

	/**
	 * Plugin name.
	 */
	private $plugin_name;

	/**
	 * Plugin version.
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_name Plugin name.
	 * @param string $version     Plugin version.
	 */
	public function __construct($plugin_name, $version) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	/**
	 * Add admin menu pages.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'zaobank',
			__('Mobile App', 'zaobank-mobile'),
			__('Mobile App', 'zaobank-mobile'),
			'manage_options',
			'zaobank-mobile',
			array($this, 'display_settings_page')
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// JWT Settings
		register_setting('zaobank_mobile_settings', 'zaobank_mobile_jwt_expiration', array(
			'type' => 'integer',
			'default' => 30,
			'sanitize_callback' => 'absint',
		));

		register_setting('zaobank_mobile_settings', 'zaobank_mobile_refresh_expiration', array(
			'type' => 'integer',
			'default' => 90,
			'sanitize_callback' => 'absint',
		));

		// Location Settings
		register_setting('zaobank_mobile_settings', 'zaobank_mobile_default_radius', array(
			'type' => 'integer',
			'default' => 25,
			'sanitize_callback' => 'absint',
		));

		register_setting('zaobank_mobile_settings', 'zaobank_mobile_max_radius', array(
			'type' => 'integer',
			'default' => 100,
			'sanitize_callback' => 'absint',
		));

		register_setting('zaobank_mobile_settings', 'zaobank_mobile_distance_unit', array(
			'type' => 'string',
			'default' => 'miles',
			'sanitize_callback' => 'sanitize_text_field',
		));

		register_setting('zaobank_mobile_settings', 'zaobank_mobile_google_api_key', array(
			'type' => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		));

		// App Distribution Settings
		register_setting('zaobank_mobile_settings', 'zaobank_mobile_min_app_version', array(
			'type' => 'string',
			'default' => '1.0.0',
			'sanitize_callback' => 'sanitize_text_field',
		));

		register_setting('zaobank_mobile_settings', 'zaobank_mobile_testflight_url', array(
			'type' => 'string',
			'sanitize_callback' => 'esc_url_raw',
		));

		register_setting('zaobank_mobile_settings', 'zaobank_mobile_appstore_url', array(
			'type' => 'string',
			'sanitize_callback' => 'esc_url_raw',
		));

		register_setting('zaobank_mobile_settings', 'zaobank_mobile_playstore_url', array(
			'type' => 'string',
			'sanitize_callback' => 'esc_url_raw',
		));

		// Settings Sections
		add_settings_section(
			'zaobank_mobile_auth_section',
			__('Authentication Settings', 'zaobank-mobile'),
			array($this, 'auth_section_callback'),
			'zaobank-mobile'
		);

		add_settings_section(
			'zaobank_mobile_location_section',
			__('Location Settings', 'zaobank-mobile'),
			array($this, 'location_section_callback'),
			'zaobank-mobile'
		);

		add_settings_section(
			'zaobank_mobile_distribution_section',
			__('App Distribution', 'zaobank-mobile'),
			array($this, 'distribution_section_callback'),
			'zaobank-mobile'
		);

		// Auth Fields
		add_settings_field(
			'zaobank_mobile_jwt_expiration',
			__('JWT Token Expiration (days)', 'zaobank-mobile'),
			array($this, 'number_field_callback'),
			'zaobank-mobile',
			'zaobank_mobile_auth_section',
			array('name' => 'zaobank_mobile_jwt_expiration', 'default' => 30)
		);

		add_settings_field(
			'zaobank_mobile_refresh_expiration',
			__('Refresh Token Expiration (days)', 'zaobank-mobile'),
			array($this, 'number_field_callback'),
			'zaobank-mobile',
			'zaobank_mobile_auth_section',
			array('name' => 'zaobank_mobile_refresh_expiration', 'default' => 90)
		);

		// Location Fields
		add_settings_field(
			'zaobank_mobile_default_radius',
			__('Default Search Radius', 'zaobank-mobile'),
			array($this, 'number_field_callback'),
			'zaobank-mobile',
			'zaobank_mobile_location_section',
			array('name' => 'zaobank_mobile_default_radius', 'default' => 25)
		);

		add_settings_field(
			'zaobank_mobile_max_radius',
			__('Maximum Search Radius', 'zaobank-mobile'),
			array($this, 'number_field_callback'),
			'zaobank-mobile',
			'zaobank_mobile_location_section',
			array('name' => 'zaobank_mobile_max_radius', 'default' => 100)
		);

		add_settings_field(
			'zaobank_mobile_distance_unit',
			__('Distance Unit', 'zaobank-mobile'),
			array($this, 'select_field_callback'),
			'zaobank-mobile',
			'zaobank_mobile_location_section',
			array(
				'name' => 'zaobank_mobile_distance_unit',
				'options' => array(
					'miles' => __('Miles', 'zaobank-mobile'),
					'km' => __('Kilometers', 'zaobank-mobile'),
				),
			)
		);

		add_settings_field(
			'zaobank_mobile_google_api_key',
			__('Google Maps API Key', 'zaobank-mobile'),
			array($this, 'text_field_callback'),
			'zaobank-mobile',
			'zaobank_mobile_location_section',
			array(
				'name' => 'zaobank_mobile_google_api_key',
				'description' => __('Leave empty to use Formidable Geo API key.', 'zaobank-mobile'),
			)
		);

		// Distribution Fields
		add_settings_field(
			'zaobank_mobile_min_app_version',
			__('Minimum App Version', 'zaobank-mobile'),
			array($this, 'text_field_callback'),
			'zaobank-mobile',
			'zaobank_mobile_distribution_section',
			array(
				'name' => 'zaobank_mobile_min_app_version',
				'placeholder' => '1.0.0',
			)
		);

		add_settings_field(
			'zaobank_mobile_testflight_url',
			__('TestFlight URL', 'zaobank-mobile'),
			array($this, 'url_field_callback'),
			'zaobank-mobile',
			'zaobank_mobile_distribution_section',
			array('name' => 'zaobank_mobile_testflight_url')
		);

		add_settings_field(
			'zaobank_mobile_appstore_url',
			__('App Store URL', 'zaobank-mobile'),
			array($this, 'url_field_callback'),
			'zaobank-mobile',
			'zaobank_mobile_distribution_section',
			array('name' => 'zaobank_mobile_appstore_url')
		);

		add_settings_field(
			'zaobank_mobile_playstore_url',
			__('Play Store URL', 'zaobank-mobile'),
			array($this, 'url_field_callback'),
			'zaobank-mobile',
			'zaobank_mobile_distribution_section',
			array('name' => 'zaobank_mobile_playstore_url')
		);
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_scripts($hook) {
		if ($hook !== 'zao-bank_page_zaobank-mobile') {
			return;
		}

		wp_enqueue_script(
			$this->plugin_name . '-admin',
			ZAOBANK_MOBILE_PLUGIN_URL . 'assets/js/admin.js',
			array('jquery'),
			$this->version,
			true
		);

		wp_localize_script($this->plugin_name . '-admin', 'zaobankMobileAdmin', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('zaobank_mobile_admin'),
			'strings' => array(
				'geocoding' => __('Geocoding jobs...', 'zaobank-mobile'),
				'complete' => __('Geocoding complete!', 'zaobank-mobile'),
				'error' => __('An error occurred.', 'zaobank-mobile'),
			),
		));
	}

	/**
	 * Display settings page.
	 */
	public function display_settings_page() {
		include ZAOBANK_MOBILE_PLUGIN_DIR . 'admin/partials/settings-page.php';
	}

	/**
	 * Auth section callback.
	 */
	public function auth_section_callback() {
		echo '<p>' . esc_html__('Configure JWT token settings for mobile authentication.', 'zaobank-mobile') . '</p>';
	}

	/**
	 * Location section callback.
	 */
	public function location_section_callback() {
		echo '<p>' . esc_html__('Configure location and distance settings.', 'zaobank-mobile') . '</p>';
	}

	/**
	 * Distribution section callback.
	 */
	public function distribution_section_callback() {
		echo '<p>' . esc_html__('Configure app store URLs and version requirements.', 'zaobank-mobile') . '</p>';
	}

	/**
	 * Number field callback.
	 *
	 * @param array $args Field arguments.
	 */
	public function number_field_callback($args) {
		$value = get_option($args['name'], $args['default'] ?? '');
		printf(
			'<input type="number" id="%s" name="%s" value="%s" class="small-text" min="1" />',
			esc_attr($args['name']),
			esc_attr($args['name']),
			esc_attr($value)
		);
	}

	/**
	 * Text field callback.
	 *
	 * @param array $args Field arguments.
	 */
	public function text_field_callback($args) {
		$value = get_option($args['name'], '');
		printf(
			'<input type="text" id="%s" name="%s" value="%s" class="regular-text" placeholder="%s" />',
			esc_attr($args['name']),
			esc_attr($args['name']),
			esc_attr($value),
			esc_attr($args['placeholder'] ?? '')
		);

		if (!empty($args['description'])) {
			printf('<p class="description">%s</p>', esc_html($args['description']));
		}
	}

	/**
	 * URL field callback.
	 *
	 * @param array $args Field arguments.
	 */
	public function url_field_callback($args) {
		$value = get_option($args['name'], '');
		printf(
			'<input type="url" id="%s" name="%s" value="%s" class="regular-text" />',
			esc_attr($args['name']),
			esc_attr($args['name']),
			esc_url($value)
		);
	}

	/**
	 * Select field callback.
	 *
	 * @param array $args Field arguments.
	 */
	public function select_field_callback($args) {
		$value = get_option($args['name'], '');
		printf('<select id="%s" name="%s">', esc_attr($args['name']), esc_attr($args['name']));

		foreach ($args['options'] as $key => $label) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr($key),
				selected($value, $key, false),
				esc_html($label)
			);
		}

		echo '</select>';
	}

	/**
	 * AJAX handler for batch geocoding.
	 */
	public function ajax_batch_geocode() {
		check_ajax_referer('zaobank_mobile_admin', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'zaobank-mobile')));
		}

		$geocoder = new ZAOBank_Geocoder();
		$results = $geocoder->batch_geocode_jobs(50);

		wp_send_json_success(array(
			'message' => sprintf(
				__('Processed %d jobs: %d geocoded, %d skipped, %d errors.', 'zaobank-mobile'),
				$results['processed'],
				$results['success'],
				$results['skipped'],
				$results['errors']
			),
			'results' => $results,
		));
	}
}
