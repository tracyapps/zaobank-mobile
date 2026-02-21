<?php
/**
 * Geocoding service.
 *
 * Converts addresses to coordinates using Google Maps API.
 * Reuses Formidable Geo's API key configuration.
 */
class ZAOBank_Geocoder {

	/**
	 * Google Maps Geocoding API URL.
	 */
	const API_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

	/**
	 * Get the Google Maps API key.
	 *
	 * Attempts to get from Formidable Geo first, then falls back to our setting.
	 *
	 * @return string|null API key or null.
	 */
	public function get_api_key() {
		// Try Formidable Geo settings first
		$frm_geo_settings = get_option('frm_geo_options');
		if (!empty($frm_geo_settings['google_api_key'])) {
			return $frm_geo_settings['google_api_key'];
		}

		// Fallback to our own setting
		$our_key = get_option('zaobank_mobile_google_api_key');
		if (!empty($our_key)) {
			return $our_key;
		}

		return null;
	}

	/**
	 * Geocode an address.
	 *
	 * @param string $address The address to geocode.
	 * @return array|WP_Error Coordinates array or error.
	 */
	public function geocode_address($address) {
		$api_key = $this->get_api_key();

		if (!$api_key) {
			return new WP_Error(
				'no_api_key',
				__('Google Maps API key not configured.', 'zaobank-mobile')
			);
		}

		$url = add_query_arg(
			array(
				'address' => $address,
				'key' => $api_key,
			),
			self::API_URL
		);

		$response = wp_remote_get($url, array(
			'timeout' => 15,
		));

		if (is_wp_error($response)) {
			return $response;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (!$data || $data['status'] !== 'OK') {
			$status = isset($data['status']) ? $data['status'] : 'UNKNOWN';
			return new WP_Error(
				'geocode_failed',
				sprintf(__('Geocoding failed: %s', 'zaobank-mobile'), $status)
			);
		}

		if (empty($data['results'])) {
			return new WP_Error(
				'no_results',
				__('No results found for address.', 'zaobank-mobile')
			);
		}

		$result = $data['results'][0];
		$location = $result['geometry']['location'];

		return array(
			'latitude' => (float) $location['lat'],
			'longitude' => (float) $location['lng'],
			'formatted_address' => $result['formatted_address'],
			'accuracy' => $this->determine_accuracy($result),
		);
	}

	/**
	 * Geocode a job's location field.
	 *
	 * @param int $job_id Job post ID.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function geocode_job($job_id) {
		$location = get_field('location', $job_id);

		if (empty($location)) {
			return false;
		}

		// Check if already geocoded with same address
		$existing = $this->get_location('job', $job_id);
		if ($existing && isset($existing['address']) && $existing['address'] === $location) {
			return true; // Already geocoded
		}

		$result = $this->geocode_address($location);

		if (is_wp_error($result)) {
			return $result;
		}

		return $this->save_location('job', $job_id, array(
			'latitude' => $result['latitude'],
			'longitude' => $result['longitude'],
			'accuracy' => $result['accuracy'],
			'address' => $location,
			'geocode_source' => 'google',
		));
	}

	/**
	 * Save location data to database.
	 *
	 * @param string $object_type Type of object (job, user).
	 * @param int    $object_id   Object ID.
	 * @param array  $data        Location data.
	 * @return bool Success.
	 */
	public function save_location($object_type, $object_id, $data) {
		global $wpdb;

		$table = $wpdb->prefix . 'zaobank_locations';

		$existing = $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM $table WHERE object_type = %s AND object_id = %d",
			$object_type,
			$object_id
		));

		$location_data = array(
			'object_type' => $object_type,
			'object_id' => $object_id,
			'latitude' => $data['latitude'],
			'longitude' => $data['longitude'],
			'accuracy' => isset($data['accuracy']) ? $data['accuracy'] : 'exact',
			'address' => isset($data['address']) ? $data['address'] : null,
			'geocoded_at' => current_time('mysql', true),
			'geocode_source' => isset($data['geocode_source']) ? $data['geocode_source'] : null,
		);

		if ($existing) {
			return $wpdb->update(
				$table,
				$location_data,
				array('id' => $existing),
				array('%s', '%d', '%f', '%f', '%s', '%s', '%s', '%s'),
				array('%d')
			) !== false;
		}

		return $wpdb->insert(
			$table,
			$location_data,
			array('%s', '%d', '%f', '%f', '%s', '%s', '%s', '%s')
		) !== false;
	}

	/**
	 * Get location data from database.
	 *
	 * @param string $object_type Type of object.
	 * @param int    $object_id   Object ID.
	 * @return array|null Location data or null.
	 */
	public function get_location($object_type, $object_id) {
		global $wpdb;

		$table = $wpdb->prefix . 'zaobank_locations';

		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM $table WHERE object_type = %s AND object_id = %d",
			$object_type,
			$object_id
		), ARRAY_A);

		if (!$row) {
			return null;
		}

		return array(
			'latitude' => (float) $row['latitude'],
			'longitude' => (float) $row['longitude'],
			'accuracy' => $row['accuracy'],
			'address' => $row['address'],
			'geocoded_at' => $row['geocoded_at'],
			'geocode_source' => $row['geocode_source'],
		);
	}

	/**
	 * Delete location data.
	 *
	 * @param string $object_type Type of object.
	 * @param int    $object_id   Object ID.
	 * @return bool Success.
	 */
	public function delete_location($object_type, $object_id) {
		global $wpdb;

		$table = $wpdb->prefix . 'zaobank_locations';

		return $wpdb->delete(
			$table,
			array(
				'object_type' => $object_type,
				'object_id' => $object_id,
			),
			array('%s', '%d')
		) !== false;
	}

	/**
	 * Batch geocode multiple jobs.
	 *
	 * @param int $limit Maximum jobs to process.
	 * @return array Results with success/error counts.
	 */
	public function batch_geocode_jobs($limit = 50) {
		// Get jobs with location field but no geocoded data
		$jobs = get_posts(array(
			'post_type' => 'timebank_job',
			'posts_per_page' => $limit,
			'post_status' => 'publish',
			'meta_query' => array(
				array(
					'key' => 'location',
					'compare' => 'EXISTS',
				),
				array(
					'key' => 'location',
					'compare' => '!=',
					'value' => '',
				),
			),
		));

		$results = array(
			'processed' => 0,
			'success' => 0,
			'errors' => 0,
			'skipped' => 0,
		);

		foreach ($jobs as $job) {
			$results['processed']++;

			// Check if already geocoded
			$existing = $this->get_location('job', $job->ID);
			if ($existing) {
				$results['skipped']++;
				continue;
			}

			$result = $this->geocode_job($job->ID);

			if (is_wp_error($result)) {
				$results['errors']++;
			} elseif ($result) {
				$results['success']++;
			} else {
				$results['skipped']++;
			}

			// Rate limiting - 50 requests per second max
			usleep(20000); // 20ms delay
		}

		return $results;
	}

	/**
	 * Determine accuracy level from geocoding result.
	 *
	 * @param array $result Google geocoding result.
	 * @return string Accuracy level.
	 */
	private function determine_accuracy($result) {
		if (!isset($result['geometry']['location_type'])) {
			return 'unknown';
		}

		switch ($result['geometry']['location_type']) {
			case 'ROOFTOP':
				return 'exact';
			case 'RANGE_INTERPOLATED':
			case 'GEOMETRIC_CENTER':
				return 'address';
			case 'APPROXIMATE':
				return 'city';
			default:
				return 'unknown';
		}
	}
}
