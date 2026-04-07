<?php
/**
 * Plugin Name: MediaVault Manager
 * Plugin URI:  https://example.com
 * Description: Administracion de ventas, accesos y MediaVault (Backblaze B2 S3).
 * Version:     1.3.0
 * Author:      MediaVault Manager
 * Author URI:  https://example.com
 * License:     GPL-2.0+
 * Text Domain: jetpack-store-manager
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

// Define plugin constants
// NOTE: bump this when releasing so asset URLs change and browser caches refresh.
define('JPSM_VERSION', '1.3.0');
define('JPSM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JPSM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include helper classes
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-auth.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-api-contract.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-config.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-rest.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-data-layer.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-domain-model.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-sales.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-finance.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-access-service.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-stats-service.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-behavior-service.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-admin.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-dashboard.php';

// Include Modules
// require_once JPSM_PLUGIN_DIR . 'includes/modules/downloader/loader.php';
require_once JPSM_PLUGIN_DIR . 'includes/modules/mediavault/loader.php';

// Backblaze B2 Constants (MediaVault)
if (!defined('JPSM_B2_KEY_ID'))
	define('JPSM_B2_KEY_ID', (string) get_option('jpsm_b2_key_id', ''));
if (!defined('JPSM_B2_APP_KEY'))
	define('JPSM_B2_APP_KEY', (string) get_option('jpsm_b2_app_key', ''));
if (!defined('JPSM_B2_BUCKET'))
	define('JPSM_B2_BUCKET', (string) get_option('jpsm_b2_bucket', ''));
if (!defined('JPSM_B2_REGION'))
	define('JPSM_B2_REGION', (string) get_option('jpsm_b2_region', ''));
if (!defined('JPSM_CLOUDFLARE_DOMAIN'))
	define('JPSM_CLOUDFLARE_DOMAIN', (string) get_option('jpsm_cloudflare_domain', ''));
/**
 * Register custom page template
 */
add_filter('theme_page_templates', function ($templates) {
	$templates['templates/page-fullwidth.php'] = 'MediaVault Full Width';
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

	// Behavior analytics rollups.
	if (class_exists('JPSM_Behavior_Service')) {
		JPSM_Behavior_Service::bootstrap();
	}

	// Initialize Admin Interface
	$admin = new JPSM_Admin();
	$admin->run();
}
add_action('plugins_loaded', 'jpsm_init');
