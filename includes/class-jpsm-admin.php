<?php

class JPSM_Admin
{

    /**
     * Initialize the class and set its properties.
     */
    public function __construct()
    {
        // Constructor
    }

    /**
     * Run the admin class.
     */
    public function run()
    {
        add_action('admin_menu', array($this, 'add_plugin_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Frontend Assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));

        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_jpsm_process_sale', array('JPSM_Sales', 'process_sale_ajax'));
        add_action('wp_ajax_nopriv_jpsm_process_sale', array('JPSM_Sales', 'process_sale_ajax')); // Enable for Secret Key

        add_action('wp_ajax_jpsm_delete_log', array('JPSM_Sales', 'delete_log_ajax'));
        add_action('wp_ajax_nopriv_jpsm_delete_log', array('JPSM_Sales', 'delete_log_ajax')); // Enable for Secret Key

        add_action('wp_ajax_jpsm_delete_all_logs', array('JPSM_Sales', 'delete_all_logs_ajax'));
        add_action('wp_ajax_nopriv_jpsm_delete_all_logs', array('JPSM_Sales', 'delete_all_logs_ajax')); // Enable for Secret Key

        add_action('wp_ajax_jpsm_delete_bulk_log', array('JPSM_Sales', 'delete_bulk_log_ajax'));
        add_action('wp_ajax_nopriv_jpsm_delete_bulk_log', array('JPSM_Sales', 'delete_bulk_log_ajax'));

        add_action('wp_ajax_jpsm_resend_email', array('JPSM_Sales', 'resend_email_ajax'));
        add_action('wp_ajax_nopriv_jpsm_resend_email', array('JPSM_Sales', 'resend_email_ajax')); // Enable for Secret Key

        add_action('wp_ajax_jpsm_freeze_prices', array('JPSM_Sales', 'freeze_prices_ajax'));

        // Login / Logout
        add_action('wp_ajax_jpsm_login', array('JPSM_Access_Manager', 'login_ajax'));
        add_action('wp_ajax_nopriv_jpsm_login', array('JPSM_Access_Manager', 'login_ajax'));
        add_action('wp_ajax_jpsm_logout', array('JPSM_Access_Manager', 'logout_ajax'));
        add_action('wp_ajax_nopriv_jpsm_logout', array('JPSM_Access_Manager', 'logout_ajax'));

        add_action('wp_ajax_jpsm_get_history', array('JPSM_Sales', 'get_history_ajax'));
        add_action('wp_ajax_nopriv_jpsm_get_history', array('JPSM_Sales', 'get_history_ajax'));

        // Access Control / Permissions
        add_action('wp_ajax_jpsm_get_user_tier', array('JPSM_Access_Manager', 'get_user_tier_ajax'));
        add_action('wp_ajax_jpsm_update_user_tier', array('JPSM_Access_Manager', 'update_user_tier_ajax'));
        add_action('wp_ajax_jpsm_get_folders', array('JPSM_Access_Manager', 'get_folders_ajax'));
        add_action('wp_ajax_jpsm_update_folder_tier', array('JPSM_Access_Manager', 'update_folder_tier_ajax'));
        add_action('wp_ajax_jpsm_get_leads', array('JPSM_Access_Manager', 'get_leads_ajax'));
        add_action('wp_ajax_jpsm_log_play', array('JPSM_Access_Manager', 'log_play_ajax'));
        add_action('wp_ajax_nopriv_jpsm_log_play', array('JPSM_Access_Manager', 'log_play_ajax'));

        // MediaVault Index Sync
        add_action('wp_ajax_jpsm_get_index_stats', array('JPSM_Index_Manager', 'get_stats_ajax'));
        add_action('wp_ajax_nopriv_jpsm_get_index_stats', array('JPSM_Index_Manager', 'get_stats_ajax'));
        add_action('wp_ajax_jpsm_sync_mediavault_index', array('JPSM_Index_Manager', 'sync_mediavault_index_ajax'));
        add_action('wp_ajax_nopriv_jpsm_sync_mediavault_index', array('JPSM_Index_Manager', 'sync_mediavault_index_ajax'));

        // Admin Footer Styles
        add_action('admin_footer', array('JPSM_Admin_Views', 'render_admin_footer'));

        // Shortcode
        // Shortcode - Refactored to JPSM_Dashboard
        add_shortcode('jetpack_manager', array('JPSM_Dashboard', 'render'));

        // Page Templates
        add_filter('theme_page_templates', array($this, 'register_custom_templates'));
        add_filter('template_include', array($this, 'load_custom_template'));
    }

    /**
     * Enqueue scripts/styles for Frontend (if shortcode is present)
     */
    public function enqueue_frontend_assets()
    {
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'jetpack_manager')) {
            wp_enqueue_style('jpsm-admin-css', JPSM_PLUGIN_URL . 'assets/css/admin.css', array(), JPSM_VERSION, 'all');
            // Don't enqueue admin scripts (charts, admin.js) on frontend to prevent conflict/double submission.
            // The frontend uses its own inline JS.
        }
    }


    /**
     * Register the administration menu for this plugin into the WordPress Dashboard.
     */
    public function add_plugin_admin_menu()
    {
        // Require View Class (ensure available)
        require_once plugin_dir_path(__FILE__) . 'class-jpsm-admin-views.php';

        add_menu_page(
            'JetPack Store Manager',
            'JetPack Store',
            'manage_options',
            'jetpack-store-manager',
            array('JPSM_Admin_Views', 'render_dashboard_page'),
            'dashicons-chart-area',
            6
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'jetpack-store-manager',
            array('JPSM_Admin_Views', 'render_dashboard_page')
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Registrar Venta',
            'Registrar Venta',
            'manage_options',
            'jpsm-register-sale',
            array('JPSM_Admin_Views', 'render_registration_page')
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Sincronizador B2',
            'Sincronizador B2',
            'manage_options',
            'jpsm-synchronizer',
            array('JPSM_Admin_Views', 'render_synchronizer_page')
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Control de Accesos',
            'Control de Accesos',
            'manage_options',
            'jpsm-access-control',
            array('JPSM_Admin_Views', 'render_access_control_page')
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Configuración',
            'Configuración',
            'manage_options',
            'jpsm-settings',
            array('JPSM_Admin_Views', 'render_settings_page')
        );
    }



    public function register_settings()
    {
        // Package template/price options from domain registry.
        $template_options = array('jpsm_email_template_vip'); // Legacy/fallback key.
        $price_options = array();

        if (class_exists('JPSM_Domain_Model')) {
            foreach (JPSM_Domain_Model::get_settings_packages() as $package) {
                if (!empty($package['template_option'])) {
                    $template_options[] = $package['template_option'];
                }

                if (!empty($package['price_options']) && is_array($package['price_options'])) {
                    foreach ($package['price_options'] as $opt_name) {
                        if (!empty($opt_name)) {
                            $price_options[] = $opt_name;
                        }
                    }
                }
            }
        } else {
            $template_options = array_merge($template_options, array(
                'jpsm_email_template_basic',
                'jpsm_email_template_full',
                'jpsm_email_template_vip_videos',
                'jpsm_email_template_vip_pelis',
                'jpsm_email_template_vip_basic',
            ));
            $price_options = array(
                'jpsm_price_mxn_basic',
                'jpsm_price_mxn_vip_videos',
                'jpsm_price_mxn_vip_pelis',
                'jpsm_price_mxn_vip_basic',
                'jpsm_price_mxn_full',
                'jpsm_price_usd_vip_videos',
                'jpsm_price_usd_vip_pelis',
                'jpsm_price_usd_vip_basic',
                'jpsm_price_usd_full',
            );
        }

        foreach (array_unique($template_options) as $template_option) {
            register_setting('jpsm_settings_templates', $template_option);
        }

        // Access Key for Mobile
        register_setting('jpsm_settings_templates', 'jpsm_access_key');

        foreach (array_unique($price_options) as $price_option) {
            register_setting('jpsm_settings_templates', $price_option);
        }

        // MediaVault (Backblaze B2)
        register_setting('jpsm_settings_templates', 'jpsm_b2_key_id');
        register_setting('jpsm_settings_templates', 'jpsm_b2_app_key');
        register_setting('jpsm_settings_templates', 'jpsm_b2_bucket');
        register_setting('jpsm_settings_templates', 'jpsm_b2_region');
        register_setting('jpsm_settings_templates', 'jpsm_cloudflare_domain');
    }



    public function enqueue_styles($hook)
    {
        if (!is_string($hook) || $hook === '') {
            return;
        }
        // Only load on our plugin pages
        if (strpos($hook, 'jetpack-store') === false && strpos($hook, 'jpsm-') === false) {
            return;
        }
        wp_enqueue_style('jpsm-admin-css', JPSM_PLUGIN_URL . 'assets/css/admin.css', array(), JPSM_VERSION, 'all');
    }

    public function enqueue_scripts($hook)
    {
        if (!is_string($hook) || $hook === '') {
            return;
        }
        // Only load on our plugin pages
        if (strpos($hook, 'jetpack-store') === false && strpos($hook, 'jpsm-') === false) {
            return;
        }
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);
        wp_enqueue_script('jpsm-admin-js', JPSM_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'chart-js'), JPSM_VERSION, false);

        // Prepare Dashboard Data
        $stats = JPSM_Sales::get_dashboard_stats();

        wp_localize_script('jpsm-admin-js', 'jpsm_data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jpsm_nonce'),
            'nonces' => array(
                'sales' => wp_create_nonce('jpsm_sales_nonce'),
                'access' => wp_create_nonce('jpsm_access_nonce'),
                'index' => wp_create_nonce('jpsm_index_nonce'),
                'login' => wp_create_nonce('jpsm_login_nonce'),
                'logout' => wp_create_nonce('jpsm_logout_nonce'),
                'mediavault' => wp_create_nonce('jpsm_mediavault_nonce'),
            ),
            'stats' => $stats,
            'tier_options' => class_exists('JPSM_Domain_Model') ? JPSM_Domain_Model::get_tier_options_for_ui() : array()
        ));
    }






    /**
     * Helper to verify Access via Admin, Key or Cookie
     */


    private function verify_access()
    {
        if (class_exists('JPSM_Auth')) {
            return JPSM_Auth::is_admin_authenticated(true);
        }

        return current_user_can('manage_options');
    }


    /**
     * Register Custom Page Templates in the Page Attributes dropdown.
     */
    public function register_custom_templates($templates)
    {
        $templates['templates/page-fullwidth.php'] = 'JetPack Full Width';
        return $templates;
    }

    /**
     * Tell WordPress to use the plugin's template file if selected.
     */
    public function load_custom_template($template)
    {
        global $post;

        if (!$post) {
            return $template;
        }

        $template_slug = get_post_meta($post->ID, '_wp_page_template', true);

        if ($template_slug === 'templates/page-fullwidth.php') {
            $file = JPSM_PLUGIN_DIR . 'templates/page-fullwidth.php';
            if (file_exists($file)) {
                return $file;
            }
        }

        return $template;
    }

}
