<?php
/**
 * Fired during plugin activation.
 */
class ZAOBank_Mobile_Activator {

	/**
	 * Activate the plugin.
	 */
	public static function activate() {
		// Create custom database tables
		self::create_tables();

		// Set default options
		self::set_default_options();

		// Generate JWT secret if not exists
		self::generate_jwt_secret();

		// Flush rewrite rules
		flush_rewrite_rules();

		// Set activation flag
		update_option('zaobank_mobile_activated', true);
		update_option('zaobank_mobile_version', ZAOBANK_MOBILE_VERSION);
	}

	/**
	 * Create custom database tables.
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		// Refresh tokens table
		$table_refresh_tokens = $wpdb->prefix . 'zaobank_mobile_refresh_tokens';
		$sql_refresh_tokens = "CREATE TABLE $table_refresh_tokens (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL,
			token_hash varchar(255) NOT NULL,
			device_info varchar(255) DEFAULT NULL,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_used_at datetime DEFAULT NULL,
			revoked_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY token_hash (token_hash),
			KEY expires_at (expires_at),
			KEY revoked_at (revoked_at)
		) $charset_collate;";

		// Locations table
		$table_locations = $wpdb->prefix . 'zaobank_locations';
		$sql_locations = "CREATE TABLE $table_locations (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			object_type varchar(50) NOT NULL,
			object_id bigint(20) UNSIGNED NOT NULL,
			latitude decimal(10,8) NOT NULL,
			longitude decimal(11,8) NOT NULL,
			accuracy varchar(20) DEFAULT 'exact',
			address text DEFAULT NULL,
			geocoded_at datetime DEFAULT NULL,
			geocode_source varchar(50) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY object_unique (object_type, object_id),
			KEY object_type (object_type),
			KEY object_id (object_id),
			KEY latitude (latitude),
			KEY longitude (longitude)
		) $charset_collate;";

		// Execute table creation
		dbDelta($sql_refresh_tokens);
		dbDelta($sql_locations);

		// Store database version
		update_option('zaobank_mobile_db_version', ZAOBANK_MOBILE_VERSION);
	}

	/**
	 * Set default plugin options.
	 */
	private static function set_default_options() {
		$default_options = array(
			'zaobank_mobile_jwt_expiration' => 30, // days
			'zaobank_mobile_refresh_expiration' => 90, // days
			'zaobank_mobile_default_radius' => 25,
			'zaobank_mobile_max_radius' => 100,
			'zaobank_mobile_distance_unit' => 'miles',
			'zaobank_mobile_min_app_version' => '1.0.0',
			'zaobank_mobile_latest_ios_version' => '1.0.0',
			'zaobank_mobile_latest_android_version' => '1.0.0',
			'zaobank_mobile_testflight_url' => '',
			'zaobank_mobile_appstore_url' => '',
			'zaobank_mobile_playstore_url' => '',
		);

		foreach ($default_options as $key => $value) {
			if (get_option($key) === false) {
				add_option($key, $value);
			}
		}
	}

	/**
	 * Generate JWT secret key if not exists.
	 */
	private static function generate_jwt_secret() {
		if (!get_option('zaobank_mobile_jwt_secret')) {
			$secret = wp_generate_password(64, true, true);
			add_option('zaobank_mobile_jwt_secret', $secret);
		}
	}
}
