<?php
/**
 * Location privacy controls.
 *
 * Handles user opt-in/opt-out for location sharing and coordinate fuzzing.
 */
class ZAOBank_Location_Privacy {

	/**
	 * User meta key for location enabled.
	 */
	const META_ENABLED = 'zaobank_location_enabled';

	/**
	 * User meta key for location precision.
	 */
	const META_PRECISION = 'zaobank_location_precision';

	/**
	 * User meta key for last latitude.
	 */
	const META_LAST_LAT = 'zaobank_last_lat';

	/**
	 * User meta key for last longitude.
	 */
	const META_LAST_LNG = 'zaobank_last_lng';

	/**
	 * User meta key for last location update.
	 */
	const META_LAST_UPDATE = 'zaobank_location_updated_at';

	/**
	 * Precision levels and their offset ranges (in meters).
	 */
	const PRECISION_OFFSETS = array(
		'exact' => 0,        // No fuzzing
		'block' => 200,      // ~200m offset (neighborhood level)
		'city' => 5000,      // ~5km offset (city level)
	);

	/**
	 * Check if a user has location sharing enabled.
	 *
	 * @param int $user_id User ID.
	 * @return bool True if enabled.
	 */
	public static function is_location_enabled($user_id) {
		return (bool) get_user_meta($user_id, self::META_ENABLED, true);
	}

	/**
	 * Get user's location precision setting.
	 *
	 * @param int $user_id User ID.
	 * @return string Precision level (exact, block, city).
	 */
	public static function get_precision($user_id) {
		$precision = get_user_meta($user_id, self::META_PRECISION, true);
		return in_array($precision, array_keys(self::PRECISION_OFFSETS)) ? $precision : 'block';
	}

	/**
	 * Update user's location settings.
	 *
	 * @param int    $user_id   User ID.
	 * @param bool   $enabled   Enable location sharing.
	 * @param string $precision Precision level.
	 * @return bool Success.
	 */
	public static function update_settings($user_id, $enabled, $precision = 'block') {
		update_user_meta($user_id, self::META_ENABLED, (bool) $enabled);

		if (in_array($precision, array_keys(self::PRECISION_OFFSETS))) {
			update_user_meta($user_id, self::META_PRECISION, $precision);
		}

		return true;
	}

	/**
	 * Update user's location.
	 *
	 * Applies fuzzing based on precision setting before storing.
	 *
	 * @param int   $user_id   User ID.
	 * @param float $latitude  Latitude.
	 * @param float $longitude Longitude.
	 * @return array|WP_Error Fuzzed coordinates or error.
	 */
	public static function update_location($user_id, $latitude, $longitude) {
		if (!self::is_location_enabled($user_id)) {
			return new WP_Error(
				'location_disabled',
				__('Location sharing is not enabled for this user.', 'zaobank-mobile')
			);
		}

		$precision = self::get_precision($user_id);
		$fuzzed = self::fuzz_coordinates($latitude, $longitude, $precision);

		update_user_meta($user_id, self::META_LAST_LAT, $fuzzed['latitude']);
		update_user_meta($user_id, self::META_LAST_LNG, $fuzzed['longitude']);
		update_user_meta($user_id, self::META_LAST_UPDATE, current_time('mysql', true));

		// Also save to locations table for consistent querying
		$geocoder = new ZAOBank_Geocoder();
		$geocoder->save_location('user', $user_id, array(
			'latitude' => $fuzzed['latitude'],
			'longitude' => $fuzzed['longitude'],
			'accuracy' => $precision,
			'geocode_source' => 'device',
		));

		return $fuzzed;
	}

	/**
	 * Get user's last known location.
	 *
	 * @param int $user_id User ID.
	 * @return array|null Location data or null.
	 */
	public static function get_user_location($user_id) {
		if (!self::is_location_enabled($user_id)) {
			return null;
		}

		$lat = get_user_meta($user_id, self::META_LAST_LAT, true);
		$lng = get_user_meta($user_id, self::META_LAST_LNG, true);
		$updated = get_user_meta($user_id, self::META_LAST_UPDATE, true);

		if (empty($lat) || empty($lng)) {
			return null;
		}

		return array(
			'latitude' => (float) $lat,
			'longitude' => (float) $lng,
			'precision' => self::get_precision($user_id),
			'updated_at' => $updated,
		);
	}

	/**
	 * Clear user's location data.
	 *
	 * @param int $user_id User ID.
	 * @return bool Success.
	 */
	public static function clear_location($user_id) {
		delete_user_meta($user_id, self::META_LAST_LAT);
		delete_user_meta($user_id, self::META_LAST_LNG);
		delete_user_meta($user_id, self::META_LAST_UPDATE);

		// Remove from locations table
		$geocoder = new ZAOBank_Geocoder();
		$geocoder->delete_location('user', $user_id);

		return true;
	}

	/**
	 * Disable location sharing for a user.
	 *
	 * @param int $user_id User ID.
	 * @return bool Success.
	 */
	public static function disable_location($user_id) {
		update_user_meta($user_id, self::META_ENABLED, false);
		self::clear_location($user_id);
		return true;
	}

	/**
	 * Apply fuzzing to coordinates based on precision.
	 *
	 * @param float  $latitude  Original latitude.
	 * @param float  $longitude Original longitude.
	 * @param string $precision Precision level.
	 * @return array Fuzzed coordinates.
	 */
	public static function fuzz_coordinates($latitude, $longitude, $precision) {
		if (!isset(self::PRECISION_OFFSETS[$precision]) || $precision === 'exact') {
			return array(
				'latitude' => $latitude,
				'longitude' => $longitude,
			);
		}

		$offset_meters = self::PRECISION_OFFSETS[$precision];

		// Convert meters to approximate degrees
		// 1 degree latitude = ~111km
		// 1 degree longitude = ~111km * cos(latitude)
		$lat_offset = $offset_meters / 111000;
		$lng_offset = $offset_meters / (111000 * cos(deg2rad($latitude)));

		// Apply random offset within range
		$fuzzed_lat = $latitude + (mt_rand(-100, 100) / 100) * $lat_offset;
		$fuzzed_lng = $longitude + (mt_rand(-100, 100) / 100) * $lng_offset;

		return array(
			'latitude' => round($fuzzed_lat, 6),
			'longitude' => round($fuzzed_lng, 6),
		);
	}

	/**
	 * Get location settings for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array Settings data.
	 */
	public static function get_settings($user_id) {
		return array(
			'enabled' => self::is_location_enabled($user_id),
			'precision' => self::get_precision($user_id),
			'has_location' => self::get_user_location($user_id) !== null,
		);
	}
}
