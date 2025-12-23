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
define('JPSM_VERSION', '1.1.0');
define('JPSM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JPSM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include helper classes
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-sales.php';
require_once JPSM_PLUGIN_DIR . 'includes/class-jpsm-admin.php';

/**
 * Initialize the plugin
 */
function jpsm_init()
{
	// Initialize Admin Interface
	$admin = new JPSM_Admin();
	$admin->run();
}
add_action('plugins_loaded', 'jpsm_init');
