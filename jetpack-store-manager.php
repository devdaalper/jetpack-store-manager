<?php
/**
 * Plugin Name: JetPack Store Manager
 * Plugin URI:  https://antigravity.dev
 * Description: Plugin para administrar ventas y enviar correos (Versión Lite).
 * Version:     1.1.0
 * Author:      Antigravity
 * Author URI:  https://antigravity.dev
 * License:     GPL-2.0+
 * Text Domain: jetpack-store-manager
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

// Define plugin constants
define('JPSM_VERSION', '1.1.1');
define('JPSM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JPSM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include helper classes
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-auth.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-api-contract.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-rest.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-data-layer.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-domain-model.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-sales.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-access-service.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-stats-service.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-admin.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-dashboard.php';

// Include Modules
// require_once JPSM_PLUGIN_DIR . 'includes/modules/downloader/loader.php';
require_once JPSM_PLUGIN_DIR . 'includes/modules/mediavault/loader.php';

// Backblaze B2 Constants (MediaVault)
if (!defined('JPSM_B2_KEY_ID'))
	define('JPSM_B2_KEY_ID', get_option('jpsm_b2_key_id', '005d454a99b9dc6000000007'));
if (!defined('JPSM_B2_APP_KEY'))
	define('JPSM_B2_APP_KEY', get_option('jpsm_b2_app_key', 'K005GmPDoudO+10FtoSFxT/Mu4B2dIc'));
if (!defined('JPSM_B2_BUCKET'))
	define('JPSM_B2_BUCKET', get_option('jpsm_b2_bucket', 'jetpack-downloads'));
if (!defined('JPSM_B2_REGION'))
	define('JPSM_B2_REGION', get_option('jpsm_b2_region', 'us-west-004'));
if (!defined('JPSM_CLOUDFLARE_DOMAIN'))
	define('JPSM_CLOUDFLARE_DOMAIN', get_option('jpsm_cloudflare_domain', ''));
/**
 * Register custom page template
 */
add_filter('theme_page_templates', function ($templates) {
	$templates['templates/page-fullwidth.php'] = 'JetPack Full Width';
	return $templates;
});

/**
 * Handle custom page template inclusion
 */
add_filter('template_include', function ($template) {
	if (get_page_template_slug() === 'templates/page-fullwidth.php') {
		$file = JPSM_PLUGIN_DIR . 'templates/page-fullwidth.php';
		if (file_exists($file)) {
			return $file;
		}
	}
	return $template;
});

/**
 * Initialize the plugin
 */
function jpsm_init()
{
	// Phase 2: bootstrap table schema + legacy migration.
	if (class_exists('JPSM_Data_Layer')) {
		JPSM_Data_Layer::bootstrap();
	}

	// Phase 4: REST API consistency endpoint(s).
	if (class_exists('JPSM_REST')) {
		add_action('rest_api_init', array('JPSM_REST', 'register_routes'));
	}

	// Initialize Admin Interface
	$admin = new JPSM_Admin();
	$admin->run();
}
add_action('plugins_loaded', 'jpsm_init');
