<?php
/**
 * MediaVault Loader
 */

if (!defined('ABSPATH'))
    exit;

define('JPSM_MV_DIR', plugin_dir_path(__FILE__));
define('JPSM_MV_URL', plugin_dir_url(__FILE__));

/**
 * Return the resolved mediavault-client asset filename (source or .min).
 */
function jpsm_mv_client_asset_file()
{
    $src = 'assets/js/mediavault-client.js';
    if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
        return $src;
    }
    $min = 'assets/js/mediavault-client.min.js';
    return file_exists( JPSM_MV_DIR . $min ) ? $min : $src;
}

function jpsm_mv_client_asset_version()
{
    $client_file = JPSM_MV_DIR . jpsm_mv_client_asset_file();
    $version = defined('JPSM_VERSION') ? JPSM_VERSION : '1.0.0';

    if (file_exists($client_file)) {
        $mtime = filemtime($client_file);
        if ($mtime) {
            $version = (string) $mtime;
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        $version .= '-debug-' . time();
    }

    return $version;
}

// Classes are loaded via Composer autoload (classmap in includes/).
// No require_once needed for class files.

use JetpackStore\Access_Manager;
use JetpackStore\Auth;
use JetpackStore\MediaVault\Traffic_Manager;
use JetpackStore\MediaVault\Index_Manager;
use JetpackStore\MediaVault\UI;

// Initialize Module
function jpsm_mv_init()
{
    // Install DB Tables on first run
    Traffic_Manager::install_table();
    Index_Manager::create_table();

    // AJAX Handler (Runs early for clean JSON)
    add_action('init', [UI::class, 'handle_ajax']);
    add_action('wp_ajax_mv_search_global', [UI::class, 'handle_ajax']);
    add_action('wp_ajax_nopriv_mv_search_global', [UI::class, 'handle_ajax']);

    // Register Shortcode
    $mv_shortcode_cb = function ($atts) {
        // Enqueue Client Script (only once)
        if (!wp_script_is('jpsm-mediavault-client', 'enqueued')) {
            $ver = jpsm_mv_client_asset_version();
            wp_enqueue_script(
                'jpsm-mediavault-client',
                JPSM_MV_URL . jpsm_mv_client_asset_file(),
                [],
                $ver,
                true
            );
            $email = Access_Manager::get_current_email();
            $tier = Access_Manager::get_user_tier($email);
            wp_localize_script('jpsm-mediavault-client', 'mv_params', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'user_tier' => $tier,
                'remaining_plays' => Access_Manager::get_remaining_plays($email),
                'nonce' => wp_create_nonce('jpsm_mediavault_nonce'),
            ]);
        }

        // Render UI (class is loaded via Composer autoload)
        return UI::render();
    };
    // Backward-compatible shortcode (kept for existing installs/content).
    add_shortcode('jpsm_media_vault', $mv_shortcode_cb);
    // Preferred branding alias (Option A: visible rebrand only; internal keys/slug stay the same).
    add_shortcode('mediavault_vault', $mv_shortcode_cb);
}
add_action('plugins_loaded', 'jpsm_mv_init');


// ========== WP-CRON REMOVED (MANUAL SYNC ONLY) ==========

// Clean up any previously scheduled legacy cron jobs
add_action('init', function () {
    if (wp_next_scheduled('jpsm_mediavault_sync_index')) {
        error_log('[MediaVault] Removing legacy automated sync cron.');
        wp_clear_scheduled_hook('jpsm_mediavault_sync_index');
    }
});

/**
 * Session actions MUST run before any HTML is emitted, otherwise cookies/redirects
 * can fail (headers already sent) when MediaVault is rendered inside templates.
 *
 * This keeps the access/lock model intact while making login/logout/guest flows reliable.
 */
add_action('template_redirect', function () {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }

    $post = get_post();
    $has_mv_shortcode = ($post instanceof WP_Post) && (
        has_shortcode($post->post_content, 'jpsm_media_vault')
        || has_shortcode($post->post_content, 'mediavault_vault')
    );
    $mv_page_id = intval(get_option('jpsm_mediavault_page_id', 0));
    $is_mv_page = $has_mv_shortcode || ($mv_page_id > 0 && is_page($mv_page_id));

    if (!$is_mv_page) {
        return;
    }

    // Full-screen vault pages are session-driven and must never be served from full-page cache.
    // Many managed hosts cache anonymous pages and won't vary on our custom session cookie.
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCACHEOBJECT')) {
        define('DONOTCACHEOBJECT', true);
    }
    if (!defined('DONOTMINIFY')) {
        define('DONOTMINIFY', true);
    }
    if (function_exists('nocache_headers')) {
        nocache_headers();
    }
    // LiteSpeed/Hostinger: explicit response header to prevent LSCache storing this page.
    // Safe even if LSCache isn't present.
    if (!headers_sent()) {
        header('X-LiteSpeed-Cache-Control: no-cache');
    }

    // Guest access URL trigger.
    if (isset($_GET['invitado']) && $_GET['invitado'] == '1') {
        $guest_id = 'invitado_' . uniqid() . '@example.invalid';
        if (class_exists(Access_Manager::class)) {
            Access_Manager::set_access_cookie($guest_id);
        }
        wp_safe_redirect(remove_query_arg('invitado'));
        exit;
    }

    // Logout action.
    if (isset($_GET['action']) && $_GET['action'] === 'mv_logout') {
        if (class_exists(Auth::class)) {
            Auth::clear_user_session_cookie();
        } else {
            setcookie('jdd_access_token', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
            setcookie('jdd_access_token', '', time() - 3600, '/');
        }
        wp_safe_redirect(remove_query_arg(['action', 'folder']));
        exit;
    }

    // Login POST (email only).
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['jdd_login'], $_POST['jdd_email'])) {
        $email = sanitize_email(wp_unslash($_POST['jdd_email']));
        if (!empty($email) && is_email($email) && class_exists(Access_Manager::class)) {
            if (Access_Manager::set_access_cookie($email)) {
                // Keep redirects on the same host to avoid losing the session cookie on www/non-www mismatches.
                $redirect = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
                if (!is_string($redirect) || $redirect === '') {
                    $redirect = '/';
                }
                wp_safe_redirect($redirect);
                exit;
            }
        }
    }
}, 0);

/**
 * Template Override Logic
 * If the current page contains the [jpsm_media_vault] shortcode, 
 * swap the theme template for our full-screen wrapper.
 */
add_filter('template_include', function ($template) {
    $post = get_post();
    $has_mv_shortcode = ($post instanceof WP_Post) && (
        has_shortcode($post->post_content, 'jpsm_media_vault')
        || has_shortcode($post->post_content, 'mediavault_vault')
    );

    $mv_page_id = intval(get_option('jpsm_mediavault_page_id', 0));
    if ($has_mv_shortcode || ($mv_page_id > 0 && is_page($mv_page_id))) {
        $new_template = JPSM_MV_DIR . 'template-fullscreen.php';
        if (file_exists($new_template)) {
            // Enqueue scripts manually here since we bypass the shortcode callback
            add_action('wp_enqueue_scripts', function () {
                // Enqueue SweetAlert2
                wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);

                if (!wp_script_is('jpsm-mediavault-client', 'enqueued')) {
                    $ver = jpsm_mv_client_asset_version();
                    wp_enqueue_script(
                        'jpsm-mediavault-client',
                        JPSM_MV_URL . jpsm_mv_client_asset_file(),
                        [],
                        $ver,
                        true
                    );
                    $email = Access_Manager::get_current_email();
                    $tier = Access_Manager::get_user_tier($email);
                    wp_localize_script('jpsm-mediavault-client', 'mv_params', [
                        'ajaxurl' => admin_url('admin-ajax.php'),
                        'user_tier' => $tier,
                        'remaining_plays' => Access_Manager::get_remaining_plays($email),
                        'nonce' => wp_create_nonce('jpsm_mediavault_nonce'),
                    ]);
                }
            });
            return $new_template;
        }
    }
    return $template;
});
