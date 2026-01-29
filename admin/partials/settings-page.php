<?php
/**
 * Admin settings page template.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}
?>
<div class="wrap">
	<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

	<div class="zaobank-mobile-admin">
		<div class="zaobank-mobile-settings">
			<form method="post" action="options.php">
				<?php
				settings_fields('zaobank_mobile_settings');
				do_settings_sections('zaobank-mobile');
				submit_button();
				?>
			</form>
		</div>

		<div class="zaobank-mobile-tools">
			<h2><?php esc_html_e('Tools', 'zaobank-mobile'); ?></h2>

			<div class="card">
				<h3><?php esc_html_e('Batch Geocode Jobs', 'zaobank-mobile'); ?></h3>
				<p><?php esc_html_e('Geocode existing jobs that have location text but no coordinates.', 'zaobank-mobile'); ?></p>
				<button type="button" id="zaobank-batch-geocode" class="button button-secondary">
					<?php esc_html_e('Start Geocoding', 'zaobank-mobile'); ?>
				</button>
				<div id="zaobank-geocode-status" class="notice notice-info hidden" style="margin-top: 10px;">
					<p></p>
				</div>
			</div>

			<div class="card">
				<h3><?php esc_html_e('API Information', 'zaobank-mobile'); ?></h3>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('REST API Base URL', 'zaobank-mobile'); ?></th>
						<td><code><?php echo esc_url(rest_url('zaobank-mobile/v1')); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e('JWT Secret', 'zaobank-mobile'); ?></th>
						<td>
							<code><?php echo esc_html(substr(get_option('zaobank_mobile_jwt_secret', ''), 0, 10)); ?>...</code>
							<button type="button" id="zaobank-regenerate-secret" class="button button-link-delete">
								<?php esc_html_e('Regenerate', 'zaobank-mobile'); ?>
							</button>
							<p class="description"><?php esc_html_e('Warning: Regenerating will invalidate all existing tokens.', 'zaobank-mobile'); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="card">
				<h3><?php esc_html_e('Google Maps API', 'zaobank-mobile'); ?></h3>
				<?php
				$geocoder = new ZAOBank_Geocoder();
				$api_key = $geocoder->get_api_key();
				?>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Status', 'zaobank-mobile'); ?></th>
						<td>
							<?php if ($api_key): ?>
								<span class="dashicons dashicons-yes-alt" style="color: green;"></span>
								<?php esc_html_e('API key configured', 'zaobank-mobile'); ?>
							<?php else: ?>
								<span class="dashicons dashicons-warning" style="color: orange;"></span>
								<?php esc_html_e('No API key found', 'zaobank-mobile'); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Source', 'zaobank-mobile'); ?></th>
						<td>
							<?php
							$frm_geo_settings = get_option('frm_geo_options');
							if (!empty($frm_geo_settings['google_api_key'])) {
								esc_html_e('Formidable Geo', 'zaobank-mobile');
							} elseif (get_option('zaobank_mobile_google_api_key')) {
								esc_html_e('ZAO Bank Mobile settings', 'zaobank-mobile');
							} else {
								esc_html_e('Not configured', 'zaobank-mobile');
							}
							?>
						</td>
					</tr>
				</table>
			</div>

			<div class="card">
				<h3><?php esc_html_e('Statistics', 'zaobank-mobile'); ?></h3>
				<?php
				global $wpdb;
				$refresh_tokens_table = $wpdb->prefix . 'zaobank_mobile_refresh_tokens';
				$locations_table = $wpdb->prefix . 'zaobank_locations';

				$active_tokens = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM $refresh_tokens_table WHERE expires_at > %s AND revoked_at IS NULL",
						current_time('mysql', true)
					)
				);

				$geocoded_jobs = $wpdb->get_var(
					"SELECT COUNT(*) FROM $locations_table WHERE object_type = 'job'"
				);

				$user_locations = $wpdb->get_var(
					"SELECT COUNT(*) FROM $locations_table WHERE object_type = 'user'"
				);
				?>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Active Refresh Tokens', 'zaobank-mobile'); ?></th>
						<td><?php echo esc_html($active_tokens); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Geocoded Jobs', 'zaobank-mobile'); ?></th>
						<td><?php echo esc_html($geocoded_jobs); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Users with Location', 'zaobank-mobile'); ?></th>
						<td><?php echo esc_html($user_locations); ?></td>
					</tr>
				</table>
			</div>
		</div>
	</div>
</div>

<style>
	.zaobank-mobile-admin {
		display: flex;
		gap: 20px;
		margin-top: 20px;
	}

	.zaobank-mobile-settings {
		flex: 2;
	}

	.zaobank-mobile-tools {
		flex: 1;
	}

	.zaobank-mobile-tools .card {
		max-width: 100%;
		margin-bottom: 20px;
	}

	.zaobank-mobile-tools .card h3 {
		margin-top: 0;
	}

	.zaobank-mobile-tools .form-table th {
		padding: 10px 10px 10px 0;
		width: auto;
	}

	.zaobank-mobile-tools .form-table td {
		padding: 10px 0;
	}

	@media screen and (max-width: 1200px) {
		.zaobank-mobile-admin {
			flex-direction: column;
		}
	}
</style>
