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

// Composer autoloader (classmap: scans includes/ for all namespaced classes).
require_once JPSM_PLUGIN_DIR . 'vendor/autoload.php';

// Module loaders (procedural bootstrap, not class-based).
// require_once JPSM_PLUGIN_DIR . 'includes/modules/downloader/loader.php';
require_once JPSM_PLUGIN_DIR . 'includes/modules/mediavault/loader.php';

// Namespace imports for this bootstrap file.
use JetpackStore\Data_Layer;
use JetpackStore\REST;
use JetpackStore\Behavior_Service;
use JetpackStore\Admin;
use JetpackStore\MediaVault\Index_Manager;

// Backblaze B2 Constants (MediaVault)
//
// RECOMMENDED: Define these in wp-config.php for security:
//   define('JPSM_B2_KEY_ID',          'your-key-id');
//   define('JPSM_B2_APP_KEY',         'your-app-key');
//   define('JPSM_B2_BUCKET',          'your-bucket');
//   define('JPSM_B2_REGION',          'us-west-004');
//   define('JPSM_CLOUDFLARE_DOMAIN',  'cdn.example.com');
//
// Fallback: reads from wp_options if not defined in wp-config.php.
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
 * Resolve the asset path, swapping to the minified version in production.
 *
 * When SCRIPT_DEBUG is false (default on production) and a .min.js/.min.css
 * file exists on disk, the minified path is returned. In development
 * (SCRIPT_DEBUG = true) the original source file is always used.
 *
 * @param string $relative_path Relative path from the plugin root, e.g. 'assets/js/admin.js'.
 * @return string               The resolved relative path (e.g. 'assets/js/admin.min.js').
 */
function jpsm_asset_path( $relative_path ) {
	// In debug mode, always serve the unminified source.
	if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
		return $relative_path;
	}

	// Build the .min variant: assets/js/admin.js → assets/js/admin.min.js
	$ext = pathinfo( $relative_path, PATHINFO_EXTENSION );
	$min_path = preg_replace( '/\.' . preg_quote( $ext, '/' ) . '$/', '.min.' . $ext, $relative_path );

	// Only use min if the file actually exists on disk.
	if ( file_exists( JPSM_PLUGIN_DIR . $min_path ) ) {
		return $min_path;
	}

	return $relative_path;
}

/**
 * Simple transient-based rate limiter for public AJAX endpoints.
 *
 * Tracks request count per IP + action in a transient. Returns true when the
 * caller is within the allowed rate, false when rate-limited.
 *
 * @param string $action   AJAX action name (used as part of the transient key).
 * @param int    $max      Maximum requests allowed in the window.
 * @param int    $window   Time window in seconds (default 60).
 * @return bool            True if allowed, false if rate-limited.
 */
function jpsm_rate_limit( $action, $max = 30, $window = 60 ) {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
	$ip = preg_replace( '/[^a-fA-F0-9\.\:]/', '', $ip );
	$key = 'jpsm_rl_' . md5( $action . '|' . $ip );

	$data = get_transient( $key );
	if ( $data === false ) {
		set_transient( $key, 1, $window );
		return true;
	}

	$count = intval( $data );
	if ( $count >= $max ) {
		return false;
	}

	set_transient( $key, $count + 1, $window );
	return true;
}

/**
 * Send a 429 Too Many Requests response and die.
 *
 * @param string $action The action that was rate-limited (for logging).
 */
function jpsm_rate_limit_die( $action = '' ) {
	status_header( 429 );
	wp_send_json_error(
		array( 'message' => 'Too many requests. Please wait a moment.' ),
		429
	);
}

/**
 * Show admin notice when B2 credentials are stored in the database
 * instead of wp-config.php (less secure).
 */
add_action('admin_notices', function () {
	if (!current_user_can('manage_options')) {
		return;
	}
	// Only warn if credentials exist in wp_options (not in wp-config.php).
	$key_in_db = get_option('jpsm_b2_key_id', '');
	$app_in_db = get_option('jpsm_b2_app_key', '');
	if (empty($key_in_db) && empty($app_in_db)) {
		return;
	}
	// Don't nag if user already dismissed (stored for 30 days).
	if (get_transient('jpsm_b2_config_notice_dismissed')) {
		return;
	}
	?>
	<div class="notice notice-warning is-dismissible" id="jpsm-b2-config-notice">
		<p><strong>MediaVault Manager:</strong> Las credenciales de Backblaze B2 est&aacute;n almacenadas en la base de datos.
		   Para mayor seguridad, defin&iacute;las como constantes en <code>wp-config.php</code> y elimina los valores de Configuraci&oacute;n.
		   <a href="https://developer.wordpress.org/apis/security/" target="_blank" rel="noopener">M&aacute;s info</a></p>
	</div>
	<script>
	jQuery(document).on('click', '#jpsm-b2-config-notice .notice-dismiss', function(){
		jQuery.post(ajaxurl, {action:'jpsm_dismiss_b2_notice', _wpnonce:'<?php echo wp_create_nonce('jpsm_dismiss_b2'); ?>'});
	});
	</script>
	<?php
});
add_action('wp_ajax_jpsm_dismiss_b2_notice', function () {
	check_ajax_referer('jpsm_dismiss_b2', '_wpnonce');
	set_transient('jpsm_b2_config_notice_dismissed', 1, 30 * DAY_IN_SECONDS);
	wp_die();
});
/**
 * Add Subresource Integrity (SRI) to CDN-loaded scripts.
 *
 * Prevents execution of tampered scripts if the CDN is compromised.
 */
add_filter('script_loader_tag', function ($tag, $handle) {
	$sri_hashes = array(
		'chart-js' => 'sha384-9MhbyIRcBVQiiC7FSd7T38oJNj2Zh+EfxS7/vjhBi4OOT78NlHSnzM31EZRWR1LZ',
	);
	if (isset($sri_hashes[$handle]) && strpos($tag, 'integrity') === false) {
		$tag = str_replace(' src=', ' integrity="' . $sri_hashes[$handle] . '" crossorigin="anonymous" src=', $tag);
	}
	return $tag;
}, 10, 2);

/**
 * Rate-limit the most exposed public (nopriv) AJAX endpoints.
 *
 * Login / search / play-tracking are the primary abuse vectors.
 * Other endpoints either require a secret key or are less sensitive.
 */
$jpsm_rate_limited_actions = array(
	'jpsm_login'          => array( 'max' => 10, 'window' => 60 ),
	'jpsm_process_sale'   => array( 'max' => 15, 'window' => 60 ),
	'jpsm_log_play'       => array( 'max' => 60, 'window' => 60 ),
	'mv_search_global'    => array( 'max' => 30, 'window' => 60 ),
);
foreach ( $jpsm_rate_limited_actions as $action => $limits ) {
	add_action( 'wp_ajax_nopriv_' . $action, function () use ( $action, $limits ) {
		if ( ! jpsm_rate_limit( $action, $limits['max'], $limits['window'] ) ) {
			jpsm_rate_limit_die( $action );
		}
	}, 1 ); // Priority 1: run before the real handler.
}

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
 * Plugin activation: create/update database tables and schedule cron jobs.
 *
 * Runs only once when the plugin is activated (not on every page load).
 */
function jpsm_activate()
{
	// Create/update core tables.
	if (class_exists(Data_Layer::class)) {
		Data_Layer::bootstrap();
	}

	// Create/update MediaVault index tables.
	if (class_exists(Index_Manager::class)) {
		Index_Manager::create_table();
	}

	// Schedule behavior analytics cron.
	if (class_exists(Behavior_Service::class)) {
		Behavior_Service::bootstrap();
	}
}
register_activation_hook(__FILE__, 'jpsm_activate');

/**
 * Plugin deactivation: clean up cron jobs.
 *
 * Transients and options are preserved so data is not lost on
 * accidental deactivation. Full cleanup happens in uninstall.php.
 */
function jpsm_deactivate()
{
	// Unschedule behavior analytics rollup.
	wp_clear_scheduled_hook('jpsm_behavior_daily_rollup');

	// Unschedule MediaVault sync (if legacy cron still present).
	wp_clear_scheduled_hook('jpsm_mediavault_sync_index');
}
register_deactivation_hook(__FILE__, 'jpsm_deactivate');

/**
 * Initialize the plugin on every page load.
 */
function jpsm_init()
{
	// Schema bootstrap: only runs when schema version changes (has internal guard).
	if (class_exists(Data_Layer::class)) {
		Data_Layer::bootstrap();
	}

	// REST API endpoints.
	if (class_exists(REST::class)) {
		add_action('rest_api_init', array(REST::class, 'register_routes'));
	}

	// Behavior analytics rollups (schedules cron if not already scheduled).
	if (class_exists(Behavior_Service::class)) {
		Behavior_Service::bootstrap();
	}

	// Initialize Admin Interface.
	$admin = new Admin();
	$admin->run();
}
add_action('plugins_loaded', 'jpsm_init');
