<?php
/**
 * Plugin Name: ZAO Bank Mobile
 * Plugin URI: https://zaobank.org
 * Description: Mobile app backend infrastructure for ZAO Bank - provides JWT authentication, geolocation services, and mobile-optimized REST API endpoints.
 * Version: 1.0.1
 * Author: ZAO Bank
 * Author URI: https://zaobank.org
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zaobank-mobile
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

// Define plugin constants
define('ZAOBANK_MOBILE_VERSION', '1.0.1');
define('ZAOBANK_MOBILE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZAOBANK_MOBILE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZAOBANK_MOBILE_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * The core plugin class.
 */
require_once ZAOBANK_MOBILE_PLUGIN_DIR . 'includes/class-zaobank-mobile.php';

/**
 * Begins execution of the plugin.
 */
function run_zaobank_mobile() {
	$plugin = new ZAOBank_Mobile();
	$plugin->run();
}

/**
 * Activation hook
 */
function activate_zaobank_mobile() {
	require_once ZAOBANK_MOBILE_PLUGIN_DIR . 'includes/class-zaobank-mobile-activator.php';
	ZAOBank_Mobile_Activator::activate();
}

/**
 * Deactivation hook
 */
function deactivate_zaobank_mobile() {
	require_once ZAOBANK_MOBILE_PLUGIN_DIR . 'includes/class-zaobank-mobile-deactivator.php';
	ZAOBank_Mobile_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_zaobank_mobile');
register_deactivation_hook(__FILE__, 'deactivate_zaobank_mobile');

// Run the plugin
run_zaobank_mobile();
