<?php
/**
 * MediaVault Loader
 */

if (!defined('ABSPATH'))
    exit;

define('JPSM_MV_DIR', plugin_dir_path(__FILE__));
define('JPSM_MV_URL', plugin_dir_url(__FILE__));

// Load Core Classes
require_once JPSM_MV_DIR . 'class-s3-client.php';
require_once JPSM_MV_DIR . 'class-traffic-manager.php';
require_once JPSM_MV_DIR . 'class-index-manager.php';
require_once JPSM_MV_DIR . 'template-vault.php';

// Initialize Module
function jpsm_mv_init()
{
    // Install DB Tables on first run
    JPSM_Traffic_Manager::install_table();
    JPSM_Index_Manager::create_table();

    // AJAX Handler (Runs early for clean JSON)
    add_action('init', ['JPSM_MediaVault_UI', 'handle_ajax']);
    add_action('wp_ajax_mv_search_global', ['JPSM_MediaVault_UI', 'handle_ajax']);
    add_action('wp_ajax_nopriv_mv_search_global', ['JPSM_MediaVault_UI', 'handle_ajax']);

    // Register Shortcode
    add_shortcode('jpsm_media_vault', function ($atts) {
        // Enqueue Client Script (only once)
        if (!wp_script_is('jpsm-mediavault-client', 'enqueued')) {
            wp_enqueue_script(
                'jpsm-mediavault-client',
                JPSM_MV_URL . 'assets/js/mediavault-client.js',
                [],
                time(), // Hard cache bust for debugging
                true
            );
            $email = JPSM_Access_Manager::get_current_email();
            $tier = JPSM_Access_Manager::get_user_tier($email);
            wp_localize_script('jpsm-mediavault-client', 'mv_params', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'user_tier' => $tier,
                'remaining_plays' => JPSM_Access_Manager::get_remaining_plays($email),
                'nonce' => wp_create_nonce('jpsm_mediavault_nonce'),
            ]);
        }

        // Render UI (class is already loaded above)
        return JPSM_MediaVault_UI::render();
    });
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
    $has_mv_shortcode = ($post instanceof WP_Post) && has_shortcode($post->post_content, 'jpsm_media_vault');
    $is_mv_page = is_page('descargas') || $has_mv_shortcode;

    if (!$is_mv_page) {
        return;
    }

    // Guest access URL trigger.
    if (isset($_GET['invitado']) && $_GET['invitado'] == '1') {
        $guest_id = 'invitado_' . uniqid() . '@jetpackstore.net';
        if (class_exists('JPSM_Access_Manager')) {
            JPSM_Access_Manager::set_access_cookie($guest_id);
        }
        wp_safe_redirect(remove_query_arg('invitado'));
        exit;
    }

    // Logout action.
    if (isset($_GET['action']) && $_GET['action'] === 'mv_logout') {
        if (class_exists('JPSM_Auth')) {
            JPSM_Auth::clear_user_session_cookie();
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
        if (!empty($email) && is_email($email) && class_exists('JPSM_Access_Manager')) {
            if (JPSM_Access_Manager::set_access_cookie($email)) {
                $redirect = home_url(wp_unslash($_SERVER['REQUEST_URI']));
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
    $has_mv_shortcode = ($post instanceof WP_Post) && has_shortcode($post->post_content, 'jpsm_media_vault');

    if (is_page('descargas') || $has_mv_shortcode) {
        $new_template = JPSM_MV_DIR . 'template-fullscreen.php';
        if (file_exists($new_template)) {
            // Enqueue scripts manually here since we bypass the shortcode callback
            add_action('wp_enqueue_scripts', function () {
                // Enqueue SweetAlert2
                wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);

                if (!wp_script_is('jpsm-mediavault-client', 'enqueued')) {
                    wp_enqueue_script(
                        'jpsm-mediavault-client',
                        JPSM_MV_URL . 'assets/js/mediavault-client.js',
                        [],
                        time(),
                        true
                    );
                    $email = JPSM_Access_Manager::get_current_email();
                    $tier = JPSM_Access_Manager::get_user_tier($email);
                    wp_localize_script('jpsm-mediavault-client', 'mv_params', [
                        'ajaxurl' => admin_url('admin-ajax.php'),
                        'user_tier' => $tier,
                        'remaining_plays' => JPSM_Access_Manager::get_remaining_plays($email),
                        'nonce' => wp_create_nonce('jpsm_mediavault_nonce'),
                    ]);
                }
            });
            return $new_template;
        }
    }
    return $template;
});
