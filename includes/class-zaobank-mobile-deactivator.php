<?php
/**
 * Fired during plugin deactivation.
 */
class ZAOBank_Mobile_Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * Clears scheduled events but preserves data.
	 */
	public static function deactivate() {
		// Clear any scheduled events if we add them later
		wp_clear_scheduled_hook('zaobank_mobile_cleanup_tokens');

		// Flush rewrite rules
		flush_rewrite_rules();
	}
}
