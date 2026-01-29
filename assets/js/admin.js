(function($) {
	'use strict';

	$(document).ready(function() {
		// Batch geocode button
		$('#zaobank-batch-geocode').on('click', function() {
			var $button = $(this);
			var $status = $('#zaobank-geocode-status');

			$button.prop('disabled', true);
			$status.removeClass('hidden notice-info notice-success notice-error')
				.addClass('notice-info')
				.find('p').text(zaobankMobileAdmin.strings.geocoding);

			$.ajax({
				url: zaobankMobileAdmin.ajaxurl,
				type: 'POST',
				data: {
					action: 'zaobank_mobile_batch_geocode',
					nonce: zaobankMobileAdmin.nonce
				},
				success: function(response) {
					$button.prop('disabled', false);

					if (response.success) {
						$status.removeClass('notice-info').addClass('notice-success');
						$status.find('p').text(response.data.message);

						// Reload page after 2 seconds to update stats
						setTimeout(function() {
							window.location.reload();
						}, 2000);
					} else {
						$status.removeClass('notice-info').addClass('notice-error');
						$status.find('p').text(response.data.message || zaobankMobileAdmin.strings.error);
					}
				},
				error: function() {
					$button.prop('disabled', false);
					$status.removeClass('notice-info').addClass('notice-error');
					$status.find('p').text(zaobankMobileAdmin.strings.error);
				}
			});
		});

		// Regenerate secret button
		$('#zaobank-regenerate-secret').on('click', function(e) {
			e.preventDefault();

			if (!confirm('Are you sure? This will invalidate all existing JWT tokens and force all users to log in again.')) {
				return;
			}

			// This would need a separate AJAX handler - for now just show warning
			alert('Please regenerate the secret key via WP-CLI or database for security.');
		});
	});
})(jQuery);
