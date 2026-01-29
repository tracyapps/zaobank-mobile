<?php
/**
 * Fired when the plugin is uninstalled.
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

// Define table names
$tables = array(
	$wpdb->prefix . 'zaobank_mobile_refresh_tokens',
	$wpdb->prefix . 'zaobank_locations',
);

// Drop custom tables
foreach ($tables as $table) {
	$table_name = esc_sql($table);
	$wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
}

// Delete plugin options
$options = array(
	'zaobank_mobile_version',
	'zaobank_mobile_db_version',
	'zaobank_mobile_activated',
	'zaobank_mobile_jwt_secret',
	'zaobank_mobile_jwt_expiration',
	'zaobank_mobile_refresh_expiration',
	'zaobank_mobile_default_radius',
	'zaobank_mobile_max_radius',
	'zaobank_mobile_distance_unit',
	'zaobank_mobile_google_api_key',
	'zaobank_mobile_min_app_version',
	'zaobank_mobile_testflight_url',
	'zaobank_mobile_appstore_url',
	'zaobank_mobile_playstore_url',
);

foreach ($options as $option) {
	delete_option($option);
}

// Delete user meta related to location
$user_meta_keys = array(
	'zaobank_location_enabled',
	'zaobank_location_precision',
	'zaobank_last_lat',
	'zaobank_last_lng',
	'zaobank_location_updated_at',
);

foreach ($user_meta_keys as $meta_key) {
	delete_metadata('user', 0, $meta_key, '', true);
}

// Clear any cached data
wp_cache_flush();
