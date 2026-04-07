<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class JPSM_Dashboard
 *
 * Renders the mobile-friendly Frontend Dashboard (shortcode) and is also used as
 * the admin wrapper view.
 *
 * Phase 5: keep this file as a thin view-composer; heavy aggregation lives in
 * services (JPSM_Stats_Service / JPSM_Access_Service) and templates.
 */
class JPSM_Dashboard
{
    /**
     * Render the Mobile-Friendly Frontend Interface.
     */
    public static function render()
    {
        if (!self::verify_dashboard_access()) {
            return self::render_login_form();
        }

        $inline_styles = self::get_inline_style_fallback();

        $stats = array();
        if (class_exists('JPSM_Stats_Service')) {
            $stats = JPSM_Stats_Service::get_frontend_dashboard_stats();
        }
        if (!is_array($stats)) {
            $stats = array();
        }
        $stats = array_merge(self::get_default_stats(), $stats);

        $sale_package_options = self::get_sale_package_options();
        $vip_variant_options = self::get_vip_variant_options();

        // Nonces + config for SPA.
        $ajax_url = admin_url('admin-ajax.php');
        $ajax_nonce = wp_create_nonce('jpsm_nonce');
        $login_nonce = wp_create_nonce('jpsm_login_nonce');
        $logout_nonce = wp_create_nonce('jpsm_logout_nonce');
        $sales_nonce = wp_create_nonce('jpsm_sales_nonce');
        $access_nonce = wp_create_nonce('jpsm_access_nonce');
        $index_nonce = wp_create_nonce('jpsm_index_nonce');
        $mediavault_nonce = wp_create_nonce('jpsm_mediavault_nonce');

        $auth_method = class_exists('JPSM_Access_Service')
            ? JPSM_Access_Service::get_dashboard_auth_method()
            : 'none';

        $operator_label = class_exists('JPSM_Access_Service')
            ? JPSM_Access_Service::get_operator_label()
            : (wp_get_current_user()->display_name ?: 'Admin');

        // Debug logging (no secrets).
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[JPSM Dashboard] auth_method=' . $auth_method);
        }

        // Enqueue required scripts (JS is safe to enqueue during shortcode render).
        self::enqueue_dashboard_assets();

        $script_data = array(
            'ajax_url' => $ajax_url,
            'nonce' => $ajax_nonce,
            'nonces' => array(
                'sales' => $sales_nonce,
                'access' => $access_nonce,
                'index' => $index_nonce,
                'login' => $login_nonce,
                'logout' => $logout_nonce,
                'mediavault' => $mediavault_nonce,
            ),
            'auth_method' => $auth_method,
            'today' => current_time('Y-m-d'),
            'tier_options' => class_exists('JPSM_Domain_Model') ? JPSM_Domain_Model::get_tier_options_for_ui() : array(),
            'dashboard_stats' => array(
                'package_bucket_labels' => $stats['package_bucket_labels'],
                'packages' => $stats['packages'],
                'regions' => $stats['regions'],
                // Ensure JSON arrays (not objects) for numeric-keyed data.
                'weekday_averages' => array_values((array) $stats['weekday_averages']),
                'hourly_sales' => array_values((array) $stats['hourly_sales']),
                'day_month_sales' => array_values((array) $stats['day_month_sales']),
            ),
        );

        wp_localize_script('jpsm-app', 'jpsm_vars', $script_data);

        return self::render_template('jpsm-dashboard.php', array_merge($stats, array(
            'inline_styles' => $inline_styles,
            'sale_package_options' => $sale_package_options,
            'vip_variant_options' => $vip_variant_options,
            'operator_label' => $operator_label,
        )));
    }

    private static function get_default_stats()
    {
        return array(
            'log' => array(),
            'sales_today' => 0,
            'rev_today_mxn' => 0,
            'rev_today_usd' => 0,
            'rev_month_mxn' => 0,
            'rev_month_usd' => 0,
            'rev_total_mxn' => 0,
            'rev_total_usd' => 0,
            'lifetime_stats' => array('total_sales' => 0, 'rev_mxn' => 0, 'rev_usd' => 0, 'packages' => array('basic' => 0, 'vip' => 0, 'full' => 0)),
            'package_bucket_labels' => array('basic' => 'Básico', 'vip' => 'VIP', 'full' => 'Full'),
            'packages' => array('basic' => 0, 'vip' => 0, 'full' => 0),
            'regions' => array('national' => 0, 'international' => 0),
            'hourly_sales' => array_fill(0, 24, 0),
            'weekday_averages' => array_fill(1, 7, 0),
            'day_month_sales' => array_fill(1, 31, 0),
            'top_customers' => array(),
            'avg_ticket_mxn' => 0,
            'avg_ticket_usd' => 0,
            'unique_clients' => 0,
            'recurring_clients' => 0,
            'new_clients' => 0,
        );
    }

    /**
     * Helper to verify Access via WP Admin or signed session.
     */
    private static function verify_dashboard_access()
    {
        if (class_exists('JPSM_Access_Service')) {
            return JPSM_Access_Service::can_access_dashboard();
        }

        // 1. WP Admin
        if (current_user_can('manage_options')) {
            return true;
        }

        // 2. Signed session
        if (class_exists('JPSM_Access_Manager')) {
            return JPSM_Access_Manager::verify_session();
        }

        return false;
    }

    /**
     * Reduce inline CSS by default. Only inline the stylesheet when enqueue is not
     * available (e.g. shortcode rendered after head and enqueue hook didn't run).
     */
    private static function get_inline_style_fallback()
    {
        if (function_exists('wp_style_is') && (wp_style_is('jpsm-admin-css', 'enqueued') || wp_style_is('jpsm-admin-css', 'done'))) {
            return '';
        }

        // If head not printed yet, enqueue is still viable.
        if (!did_action('wp_head') && defined('JPSM_PLUGIN_URL')) {
            wp_enqueue_style('jpsm-admin-css', JPSM_PLUGIN_URL . 'assets/css/admin.css', array(), JPSM_VERSION, 'all');
            return '';
        }

        $css_file = defined('JPSM_PLUGIN_DIR') ? JPSM_PLUGIN_DIR . 'assets/css/admin.css' : '';
        if (!$css_file || !file_exists($css_file)) {
            return '';
        }

        $contents = file_get_contents($css_file);
        if ($contents === false || $contents === '') {
            return '';
        }

        return '<style>' . $contents . '</style>';
    }

    private static function enqueue_dashboard_assets()
    {
        $app_url = plugins_url('../assets/js/jpsm-app.js', __FILE__);
        wp_enqueue_script('jpsm-app', $app_url, array(), JPSM_VERSION, true);

        // Chart.js (reuse admin handle if already registered).
        if (!wp_script_is('chart-js', 'registered')) {
            wp_register_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);
        }
        wp_enqueue_script('chart-js');

        $charts_url = plugins_url('../assets/js/jpsm-dashboard-charts.js', __FILE__);
        wp_enqueue_script('jpsm-dashboard-charts', $charts_url, array('jpsm-app', 'chart-js'), JPSM_VERSION, true);
    }

    private static function get_sale_package_options()
    {
        if (class_exists('JPSM_Domain_Model')) {
            return JPSM_Domain_Model::get_sale_package_options();
        }

        return array(
            array('id' => 'basic', 'label' => 'Básico', 'icon' => '📦'),
            array('id' => 'vip', 'label' => 'VIP', 'icon' => '⭐'),
            array('id' => 'full', 'label' => 'Full', 'icon' => '💎'),
        );
    }

    private static function get_vip_variant_options()
    {
        if (class_exists('JPSM_Domain_Model')) {
            return JPSM_Domain_Model::get_vip_variant_options();
        }

        return array(
            array('id' => 'vip_videos', 'label' => 'VIP + Videos'),
            array('id' => 'vip_pelis', 'label' => 'VIP + Películas'),
            array('id' => 'vip_basic', 'label' => 'VIP + Básico'),
        );
    }

    /**
     * Render Login Form (no inline JS; uses assets/js/jpsm-dashboard-login.js).
     */
    private static function render_login_form()
    {
        $inline_styles = self::get_inline_style_fallback();

        $login_js_url = plugins_url('../assets/js/jpsm-dashboard-login.js', __FILE__);
        wp_enqueue_script('jpsm-dashboard-login', $login_js_url, array(), JPSM_VERSION, true);
        wp_localize_script('jpsm-dashboard-login', 'jpsm_login_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jpsm_login_nonce'),
        ));

        return self::render_template('jpsm-login.php', array(
            'inline_styles' => $inline_styles,
        ));
    }

    /**
     * Isolate view rendering into templates/.
     */
    private static function render_template($template, $vars = array())
    {
        $base = defined('JPSM_PLUGIN_DIR') ? JPSM_PLUGIN_DIR : (plugin_dir_path(__FILE__) . '../');
        $path = $base . 'templates/' . ltrim((string) $template, '/');

        if (!file_exists($path)) {
            return '<div class="wrap"><h1>Error</h1><p>Template missing: ' . esc_html((string) $template) . '</p></div>';
        }

        if (!is_array($vars)) {
            $vars = array();
        }

        ob_start();
        extract($vars, EXTR_SKIP);
        include $path;
        return ob_get_clean();
    }
}
