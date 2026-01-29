<?php
/**
 * The core plugin class for ZAO Bank Mobile.
 *
 * Orchestrates JWT authentication, geolocation services, and mobile REST API endpoints.
 */
class ZAOBank_Mobile {

	/**
	 * The unique identifier of this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 */
	public function __construct() {
		$this->version = ZAOBANK_MOBILE_VERSION;
		$this->plugin_name = 'zaobank-mobile';

		$this->load_dependencies();
	}

	/**
	 * Load the required dependencies for this plugin.
	 */
	private function load_dependencies() {
		// JWT Authentication
		require_once ZAOBANK_MOBILE_PLUGIN_DIR . 'includes/auth/class-zaobank-jwt-tokens.php';
		require_once ZAOBANK_MOBILE_PLUGIN_DIR . 'includes/auth/class-zaobank-jwt-auth.php';

		// Geolocation
		require_once ZAOBANK_MOBILE_PLUGIN_DIR . 'includes/geolocation/class-zaobank-geocoder.php';
		require_once ZAOBANK_MOBILE_PLUGIN_DIR . 'includes/geolocation/class-zaobank-distance-calculator.php';
		require_once ZAOBANK_MOBILE_PLUGIN_DIR . 'includes/geolocation/class-zaobank-location-privacy.php';

		// REST API
		require_once ZAOBANK_MOBILE_PLUGIN_DIR . 'includes/rest-api/class-zaobank-mobile-rest-auth.php';
		require_once ZAOBANK_MOBILE_PLUGIN_DIR . 'includes/rest-api/class-zaobank-mobile-rest-jobs.php';
		require_once ZAOBANK_MOBILE_PLUGIN_DIR . 'includes/rest-api/class-zaobank-mobile-rest-location.php';
		require_once ZAOBANK_MOBILE_PLUGIN_DIR . 'includes/rest-api/class-zaobank-mobile-rest-config.php';

		// Admin
		require_once ZAOBANK_MOBILE_PLUGIN_DIR . 'admin/class-zaobank-mobile-admin.php';
	}

	/**
	 * Run the plugin - register all hooks.
	 */
	public function run() {
		// Initialize JWT authentication
		$jwt_auth = new ZAOBank_JWT_Auth();
		add_filter('determine_current_user', array($jwt_auth, 'authenticate'), 20);
		add_filter('rest_authentication_errors', array($jwt_auth, 'check_authentication_error'), 15);

		// Register REST API endpoints
		add_action('rest_api_init', array($this, 'register_rest_routes'));

		// Admin hooks
		if (is_admin()) {
			$admin = new ZAOBank_Mobile_Admin($this->plugin_name, $this->version);
			add_action('admin_menu', array($admin, 'add_admin_menu'));
			add_action('admin_init', array($admin, 'register_settings'));
			add_action('admin_enqueue_scripts', array($admin, 'enqueue_scripts'));
			add_action('wp_ajax_zaobank_mobile_batch_geocode', array($admin, 'ajax_batch_geocode'));
		}

		// Hook into job save to geocode locations
		add_action('save_post_timebank_job', array($this, 'geocode_job_on_save'), 20, 2);
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		$rest_auth = new ZAOBank_Mobile_REST_Auth();
		$rest_auth->register_routes();

		$rest_jobs = new ZAOBank_Mobile_REST_Jobs();
		$rest_jobs->register_routes();

		$rest_location = new ZAOBank_Mobile_REST_Location();
		$rest_location->register_routes();

		$rest_config = new ZAOBank_Mobile_REST_Config();
		$rest_config->register_routes();
	}

	/**
	 * Geocode job location when saved.
	 */
	public function geocode_job_on_save($post_id, $post) {
		// Skip autosave
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// Skip revisions
		if (wp_is_post_revision($post_id)) {
			return;
		}

		$geocoder = new ZAOBank_Geocoder();
		$geocoder->geocode_job($post_id);
	}

	/**
	 * The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The version of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
