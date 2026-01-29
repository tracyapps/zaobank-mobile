<?php
/**
 * Distance calculation using Haversine formula.
 *
 * Provides distance calculations and bounding box filtering for location-based queries.
 */
class ZAOBank_Distance_Calculator {

	/**
	 * Earth's radius in miles.
	 */
	const EARTH_RADIUS_MILES = 3959;

	/**
	 * Earth's radius in kilometers.
	 */
	const EARTH_RADIUS_KM = 6371;

	/**
	 * Calculate distance between two points using Haversine formula.
	 *
	 * @param float  $lat1 Latitude of point 1.
	 * @param float  $lng1 Longitude of point 1.
	 * @param float  $lat2 Latitude of point 2.
	 * @param float  $lng2 Longitude of point 2.
	 * @param string $unit Distance unit (miles or km).
	 * @return float Distance in specified unit.
	 */
	public static function calculate_distance($lat1, $lng1, $lat2, $lng2, $unit = 'miles') {
		$radius = ($unit === 'km') ? self::EARTH_RADIUS_KM : self::EARTH_RADIUS_MILES;

		$lat1_rad = deg2rad($lat1);
		$lat2_rad = deg2rad($lat2);
		$delta_lat = deg2rad($lat2 - $lat1);
		$delta_lng = deg2rad($lng2 - $lng1);

		$a = sin($delta_lat / 2) * sin($delta_lat / 2) +
			cos($lat1_rad) * cos($lat2_rad) *
			sin($delta_lng / 2) * sin($delta_lng / 2);

		$c = 2 * atan2(sqrt($a), sqrt(1 - $a));

		return $radius * $c;
	}

	/**
	 * Calculate a bounding box for initial filtering.
	 *
	 * This provides a rough square around a point for efficient database queries
	 * before applying the precise Haversine distance filter.
	 *
	 * @param float  $lat    Center latitude.
	 * @param float  $lng    Center longitude.
	 * @param float  $radius Radius in specified unit.
	 * @param string $unit   Distance unit (miles or km).
	 * @return array Bounding box with min/max lat/lng.
	 */
	public static function get_bounding_box($lat, $lng, $radius, $unit = 'miles') {
		$earth_radius = ($unit === 'km') ? self::EARTH_RADIUS_KM : self::EARTH_RADIUS_MILES;

		// Angular radius
		$angular_radius = $radius / $earth_radius;

		$lat_rad = deg2rad($lat);
		$lng_rad = deg2rad($lng);

		$min_lat = $lat_rad - $angular_radius;
		$max_lat = $lat_rad + $angular_radius;

		// Longitude delta varies with latitude
		$delta_lng = asin(sin($angular_radius) / cos($lat_rad));
		$min_lng = $lng_rad - $delta_lng;
		$max_lng = $lng_rad + $delta_lng;

		return array(
			'min_lat' => rad2deg($min_lat),
			'max_lat' => rad2deg($max_lat),
			'min_lng' => rad2deg($min_lng),
			'max_lng' => rad2deg($max_lng),
		);
	}

	/**
	 * Get jobs within a radius from a point.
	 *
	 * Uses bounding box pre-filter for performance, then Haversine for accuracy.
	 *
	 * @param float $lat    Center latitude.
	 * @param float $lng    Center longitude.
	 * @param float $radius Radius to search.
	 * @param string $unit  Distance unit.
	 * @param array $args   Additional WP_Query args.
	 * @return array Jobs with distance data.
	 */
	public static function get_jobs_within_radius($lat, $lng, $radius, $unit = 'miles', $args = array()) {
		global $wpdb;

		$locations_table = $wpdb->prefix . 'zaobank_locations';

		// Get bounding box for initial filter
		$bbox = self::get_bounding_box($lat, $lng, $radius, $unit);

		// Query locations within bounding box
		$location_rows = $wpdb->get_results($wpdb->prepare(
			"SELECT object_id, latitude, longitude
			FROM $locations_table
			WHERE object_type = 'job'
			AND latitude BETWEEN %f AND %f
			AND longitude BETWEEN %f AND %f",
			$bbox['min_lat'],
			$bbox['max_lat'],
			$bbox['min_lng'],
			$bbox['max_lng']
		));

		if (empty($location_rows)) {
			return array();
		}

		// Calculate precise distances and filter
		$jobs_with_distance = array();

		foreach ($location_rows as $row) {
			$distance = self::calculate_distance(
				$lat, $lng,
				(float) $row->latitude, (float) $row->longitude,
				$unit
			);

			// Only include if within actual radius (bounding box is approximate)
			if ($distance <= $radius) {
				$jobs_with_distance[$row->object_id] = array(
					'job_id' => (int) $row->object_id,
					'distance' => round($distance, 2),
					'latitude' => (float) $row->latitude,
					'longitude' => (float) $row->longitude,
				);
			}
		}

		if (empty($jobs_with_distance)) {
			return array();
		}

		// Sort by distance
		uasort($jobs_with_distance, function($a, $b) {
			return $a['distance'] <=> $b['distance'];
		});

		// Query the actual job posts
		$default_args = array(
			'post_type' => 'timebank_job',
			'post_status' => 'publish',
			'post__in' => array_keys($jobs_with_distance),
			'orderby' => 'post__in', // Maintain our distance order
			'posts_per_page' => isset($args['posts_per_page']) ? $args['posts_per_page'] : 20,
			'paged' => isset($args['paged']) ? $args['paged'] : 1,
		);

		$query_args = array_merge($default_args, $args);
		$query_args['post__in'] = array_keys($jobs_with_distance); // Ensure we don't override this

		$query = new WP_Query($query_args);
		$jobs = array();

		if ($query->have_posts()) {
			while ($query->have_posts()) {
				$query->the_post();
				$job_id = get_the_ID();

				$job_data = self::format_job_with_distance(
					$job_id,
					$jobs_with_distance[$job_id]['distance'],
					$unit
				);

				$jobs[] = $job_data;
			}
			wp_reset_postdata();
		}

		return array(
			'jobs' => $jobs,
			'total' => $query->found_posts,
			'pages' => $query->max_num_pages,
		);
	}

	/**
	 * Format a job with distance data.
	 *
	 * @param int    $job_id   Job post ID.
	 * @param float  $distance Distance value.
	 * @param string $unit     Distance unit.
	 * @return array Formatted job data.
	 */
	public static function format_job_with_distance($job_id, $distance, $unit = 'miles') {
		$unit_abbrev = ($unit === 'km') ? 'km' : 'mi';
		$display_distance = ($distance < 1) ?
			sprintf('%.1f %s away', $distance, $unit_abbrev) :
			sprintf('%.1f %s away', $distance, $unit_abbrev);

		// Use ZAOBank_Jobs if available, otherwise build basic data
		if (class_exists('ZAOBank_Jobs') && method_exists('ZAOBank_Jobs', 'format_job_data')) {
			$job = ZAOBank_Jobs::format_job_data($job_id);
		} else {
			$post = get_post($job_id);
			$job = array(
				'id' => $job_id,
				'title' => get_the_title($job_id),
				'content' => $post->post_content,
				'excerpt' => $post->post_excerpt,
				'author_id' => (int) $post->post_author,
				'status' => $post->post_status,
				'created_at' => $post->post_date,
				'hours' => (float) get_field('hours', $job_id),
				'location' => get_field('location', $job_id),
			);
		}

		$job['distance'] = array(
			'value' => $distance,
			'unit' => $unit,
			'display' => $display_distance,
		);

		return $job;
	}

	/**
	 * Get distance display string.
	 *
	 * @param float  $distance Distance value.
	 * @param string $unit     Unit (miles or km).
	 * @return string Formatted display string.
	 */
	public static function get_distance_display($distance, $unit = 'miles') {
		$unit_abbrev = ($unit === 'km') ? 'km' : 'mi';

		if ($distance < 0.1) {
			return __('Very close', 'zaobank-mobile');
		} elseif ($distance < 1) {
			return sprintf('%.1f %s away', $distance, $unit_abbrev);
		} else {
			return sprintf('%.1f %s away', $distance, $unit_abbrev);
		}
	}
}
