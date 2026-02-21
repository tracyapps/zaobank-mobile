<?php
/**
 * REST API: Jobs endpoints with distance filtering.
 *
 * Extends the core jobs endpoint with geolocation-based filtering.
 */
class ZAOBank_Mobile_REST_Jobs {

	/**
	 * Namespace for REST API routes.
	 */
	protected $namespace = 'zaobank-mobile/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Jobs with distance filtering
		register_rest_route($this->namespace, '/jobs', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_jobs'),
			'permission_callback' => '__return_true',
			'args' => $this->get_collection_params(),
		));

		// Single job with distance
		register_rest_route($this->namespace, '/jobs/(?P<id>[\d]+)', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_job'),
			'permission_callback' => '__return_true',
			'args' => array(
				'id' => array(
					'validate_callback' => function($param) {
						return is_numeric($param);
					},
				),
				'lat' => array(
					'type' => 'number',
					'description' => __('User latitude for distance calculation.', 'zaobank-mobile'),
				),
				'lng' => array(
					'type' => 'number',
					'description' => __('User longitude for distance calculation.', 'zaobank-mobile'),
				),
			),
		));

		// Nearby jobs (requires location)
		register_rest_route($this->namespace, '/jobs/nearby', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'get_nearby_jobs'),
			'permission_callback' => array($this, 'check_authentication'),
			'args' => array(
				'radius' => array(
					'type' => 'number',
					'description' => __('Search radius.', 'zaobank-mobile'),
				),
				'unit' => array(
					'type' => 'string',
					'enum' => array('miles', 'km'),
					'default' => 'miles',
				),
			),
		));
	}

	/**
	 * Get jobs with optional distance filtering.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_jobs($request) {
		$security_check = $this->ensure_security_available();
		if (is_wp_error($security_check)) {
			return $security_check;
		}

		$lat = $request->get_param('lat');
		$lng = $request->get_param('lng');
		$radius = $request->get_param('radius');
		$unit = $request->get_param('unit') ?: get_option('zaobank_mobile_distance_unit', 'miles');

		$page = max(1, (int) $request->get_param('page') ?: 1);
		$per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 20));

		// If location provided, use distance filtering
		if ($lat !== null && $lng !== null) {
			$radius = $radius ?: get_option('zaobank_mobile_default_radius', 25);
			$max_radius = get_option('zaobank_mobile_max_radius', 100);
			$radius = min($radius, $max_radius);

			$args = array(
				'posts_per_page' => $per_page,
				'paged' => $page,
			);

			// Add status filter
			$status = $request->get_param('status');
			if ($status) {
				$args['meta_query'] = $this->get_status_meta_query($status);
			}

			// Add region filter
			$region = $request->get_param('region');
			if ($region) {
				$args['tax_query'] = array(
					array(
						'taxonomy' => 'zaobank_region',
						'field' => 'term_id',
						'terms' => (int) $region,
					),
				);
			}

			$result = ZAOBank_Distance_Calculator::get_jobs_within_radius(
				(float) $lat,
				(float) $lng,
				(float) $radius,
				$unit,
				$args
			);

			$response = new WP_REST_Response(array(
				'jobs' => $result['jobs'],
				'total' => $result['total'],
				'pages' => $result['pages'],
				'search' => array(
					'lat' => (float) $lat,
					'lng' => (float) $lng,
					'radius' => (float) $radius,
					'unit' => $unit,
				),
			), 200);

			$response->header('X-WP-Total', $result['total']);
			$response->header('X-WP-TotalPages', $result['pages']);

			return $response;
		}

		// No location - return jobs without distance
		return $this->get_jobs_without_distance($request);
	}

	/**
	 * Get jobs without distance filtering.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	private function get_jobs_without_distance($request) {
		$page = max(1, (int) $request->get_param('page') ?: 1);
		$per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 20));

		$args = array(
			'post_type' => 'timebank_job',
			'post_status' => 'publish',
			'posts_per_page' => $per_page,
			'paged' => $page,
		);

		// Add status filter
		$status = $request->get_param('status');
		if ($status) {
			$args['meta_query'] = $this->get_status_meta_query($status);
		}

		// Add region filter
		$region = $request->get_param('region');
		if ($region) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'zaobank_region',
					'field' => 'term_id',
					'terms' => (int) $region,
				),
			);
		}

		$hidden_job_ids = $this->get_hidden_job_ids();
		if (!empty($hidden_job_ids)) {
			$args['post__not_in'] = $hidden_job_ids;
		}

		$query = new WP_Query($args);
		$jobs = array();

		if ($query->have_posts()) {
			while ($query->have_posts()) {
				$query->the_post();
				$job_id = get_the_ID();
				if (!$this->is_job_visible($job_id)) {
					continue;
				}

				$jobs[] = $this->format_job($job_id);
			}
			wp_reset_postdata();
		}

		$response = new WP_REST_Response(array(
			'jobs' => $jobs,
			'total' => $query->found_posts,
			'pages' => $query->max_num_pages,
		), 200);

		$response->header('X-WP-Total', $query->found_posts);
		$response->header('X-WP-TotalPages', $query->max_num_pages);

		return $response;
	}

	/**
	 * Get single job with optional distance.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function get_job($request) {
		$job_id = (int) $request['id'];
		$lat = $request->get_param('lat');
		$lng = $request->get_param('lng');

		$post = get_post($job_id);

		if (!$post || $post->post_type !== 'timebank_job') {
			return new WP_Error(
				'job_not_found',
				__('Job not found.', 'zaobank-mobile'),
				array('status' => 404)
			);
		}

		$security_check = $this->ensure_security_available();
		if (is_wp_error($security_check)) {
			return $security_check;
		}

		// Check visibility
		if (!$this->is_job_visible($job_id)) {
			return new WP_Error(
				'job_not_available',
				__('This job is not available.', 'zaobank-mobile'),
				array('status' => 403)
			);
		}

		$job = $this->format_job($job_id);

		// Add distance if location provided
		if ($lat !== null && $lng !== null) {
			$geocoder = new ZAOBank_Geocoder();
			$job_location = $geocoder->get_location('job', $job_id);

			if ($job_location) {
				$unit = get_option('zaobank_mobile_distance_unit', 'miles');
				$distance = ZAOBank_Distance_Calculator::calculate_distance(
					(float) $lat,
					(float) $lng,
					$job_location['latitude'],
					$job_location['longitude'],
					$unit
				);

				$job['distance'] = array(
					'value' => round($distance, 2),
					'unit' => $unit,
					'display' => ZAOBank_Distance_Calculator::get_distance_display($distance, $unit),
				);
			}
		}

		return new WP_REST_Response($job, 200);
	}

	/**
	 * Get nearby jobs for authenticated user.
	 *
	 * Uses the user's saved location if available.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function get_nearby_jobs($request) {
		$user_id = get_current_user_id();
		$user_location = ZAOBank_Location_Privacy::get_user_location($user_id);

		if (!$user_location) {
			return new WP_Error(
				'no_location',
				__('Location not available. Please enable location sharing.', 'zaobank-mobile'),
				array('status' => 400)
			);
		}

		// Forward to main get_jobs with user's location
		$request->set_param('lat', $user_location['latitude']);
		$request->set_param('lng', $user_location['longitude']);

		return $this->get_jobs($request);
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

	/**
	 * Format job data.
	 *
	 * @param int $job_id Job post ID.
	 * @return array Formatted job data.
	 */
	private function format_job($job_id) {
		// Use ZAOBank_Jobs if available
		if (class_exists('ZAOBank_Jobs') && method_exists('ZAOBank_Jobs', 'format_job_data')) {
			$job = ZAOBank_Jobs::format_job_data($job_id);
		} else {
			$post = get_post($job_id);
			$author = get_user_by('ID', $post->post_author);

			$job = array(
				'id' => $job_id,
				'title' => get_the_title($job_id),
				'content' => apply_filters('the_content', $post->post_content),
				'excerpt' => $post->post_excerpt ?: wp_trim_words($post->post_content, 30),
				'author' => array(
					'id' => (int) $post->post_author,
					'display_name' => $author ? $author->display_name : '',
					'avatar_url' => get_avatar_url($post->post_author),
				),
				'status' => $post->post_status,
				'created_at' => $post->post_date,
				'modified_at' => $post->post_modified,
			);

			// Add ACF fields
			if (function_exists('get_field')) {
				$job['hours'] = (float) get_field('hours', $job_id);
				$job['location'] = get_field('location', $job_id);
				$job['provider_id'] = get_field('provider_user_id', $job_id);
				$job['completed_at'] = get_field('completed_at', $job_id);
				$job['preferred_date'] = get_field('preferred_date', $job_id);
				$job['flexible_timing'] = (bool) get_field('flexible_timing', $job_id);
			}

			// Add regions
			$regions = wp_get_post_terms($job_id, 'zaobank_region');
			$job['regions'] = array_map(function($term) {
				return array(
					'id' => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}, $regions);
		}

		// Add coordinates if available
		$geocoder = new ZAOBank_Geocoder();
		$location = $geocoder->get_location('job', $job_id);
		if ($location) {
			$job['coordinates'] = array(
				'latitude' => $location['latitude'],
				'longitude' => $location['longitude'],
				'accuracy' => $location['accuracy'],
			);
		}

		return $job;
	}

	/**
	 * Get meta query for status filter.
	 *
	 * @param string $status Status filter.
	 * @return array Meta query array.
	 */
	private function get_status_meta_query($status) {
		switch ($status) {
			case 'available':
				return array(
					array(
						'key' => 'provider_user_id',
						'compare' => 'NOT EXISTS',
					),
				);

			case 'claimed':
				return array(
					array(
						'key' => 'provider_user_id',
						'compare' => 'EXISTS',
					),
					array(
						'key' => 'completed_at',
						'compare' => 'NOT EXISTS',
					),
				);

			case 'completed':
				return array(
					array(
						'key' => 'completed_at',
						'compare' => 'EXISTS',
					),
				);

			default:
				return array();
		}
	}

	/**
	 * Ensure required core security functionality is available.
	 *
	 * @return true|WP_Error True when available.
	 */
	private function ensure_security_available() {
		if (class_exists('ZAOBank_Security') && method_exists('ZAOBank_Security', 'is_content_visible')) {
			return true;
		}

		return new WP_Error(
			'core_dependency_missing',
			__('ZAO Bank Core security module is required for jobs endpoints.', 'zaobank-mobile'),
			array('status' => 503)
		);
	}

	/**
	 * Check whether a job should be visible in API responses.
	 *
	 * @param int $job_id Job post ID.
	 * @return bool True when visible.
	 */
	private function is_job_visible($job_id) {
		return ZAOBank_Security::is_content_visible('job', (int) $job_id);
	}

	/**
	 * Get IDs of jobs currently hidden by moderation flags.
	 *
	 * @return int[] Job IDs to exclude.
	 */
	private function get_hidden_job_ids() {
		global $wpdb;

		if (!get_option('zaobank_auto_hide_flagged', true)) {
			return array();
		}

		if (!class_exists('ZAOBank_Database') || !method_exists('ZAOBank_Database', 'get_flags_table')) {
			return array();
		}

		$flags_table = ZAOBank_Database::get_flags_table();
		$hidden_ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT flagged_item_id
			FROM $flags_table
			WHERE flagged_item_type = %s
			AND status = %s",
			'job',
			'open'
		));

		if (empty($hidden_ids)) {
			return array();
		}

		return array_map('intval', $hidden_ids);
	}

	/**
	 * Get collection parameters.
	 *
	 * @return array Parameters definition.
	 */
	public function get_collection_params() {
		return array(
			'page' => array(
				'description' => __('Current page.', 'zaobank-mobile'),
				'type' => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'description' => __('Items per page.', 'zaobank-mobile'),
				'type' => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			),
			'lat' => array(
				'description' => __('Latitude for distance filtering.', 'zaobank-mobile'),
				'type' => 'number',
			),
			'lng' => array(
				'description' => __('Longitude for distance filtering.', 'zaobank-mobile'),
				'type' => 'number',
			),
			'radius' => array(
				'description' => __('Search radius.', 'zaobank-mobile'),
				'type' => 'number',
			),
			'unit' => array(
				'description' => __('Distance unit.', 'zaobank-mobile'),
				'type' => 'string',
				'enum' => array('miles', 'km'),
			),
			'status' => array(
				'description' => __('Filter by status.', 'zaobank-mobile'),
				'type' => 'string',
				'enum' => array('available', 'claimed', 'completed'),
			),
			'region' => array(
				'description' => __('Filter by region ID.', 'zaobank-mobile'),
				'type' => 'integer',
			),
		);
	}
}
