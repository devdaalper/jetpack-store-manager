<?php
namespace JetpackStore;

use JetpackStore\MediaVault\Index_Manager;
use JetpackStore\MediaVault\Nav_Order;

/**
 * Main admin controller for the JetPack Store Manager plugin.
 *
 * Registers menus, enqueues assets, wires AJAX handlers, and manages
 * plugin settings and page templates.
 *
 * @package JetpackStore
 */
class Admin
{
    const OPTION_MEDIAVAULT_PAGE_ID = 'jpsm_mediavault_page_id';
    const OPTION_MANAGER_PAGE_ID = 'jpsm_manager_page_id';

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

        // Setup / onboarding actions (admin only).
        add_action('admin_post_jpsm_setup_create_pages', array($this, 'handle_setup_create_pages'));
        add_action('wp_ajax_jpsm_process_sale', array(Sales::class, 'process_sale_ajax'));
        add_action('wp_ajax_nopriv_jpsm_process_sale', array(Sales::class, 'process_sale_ajax')); // Enable for Secret Key

        add_action('wp_ajax_jpsm_delete_log', array(Sales::class, 'delete_log_ajax'));
        add_action('wp_ajax_nopriv_jpsm_delete_log', array(Sales::class, 'delete_log_ajax')); // Enable for Secret Key

        add_action('wp_ajax_jpsm_delete_all_logs', array(Sales::class, 'delete_all_logs_ajax'));
        add_action('wp_ajax_nopriv_jpsm_delete_all_logs', array(Sales::class, 'delete_all_logs_ajax')); // Enable for Secret Key

        add_action('wp_ajax_jpsm_delete_bulk_log', array(Sales::class, 'delete_bulk_log_ajax'));
        add_action('wp_ajax_nopriv_jpsm_delete_bulk_log', array(Sales::class, 'delete_bulk_log_ajax'));

        add_action('wp_ajax_jpsm_resend_email', array(Sales::class, 'resend_email_ajax'));
        add_action('wp_ajax_nopriv_jpsm_resend_email', array(Sales::class, 'resend_email_ajax')); // Enable for Secret Key

        add_action('wp_ajax_jpsm_freeze_prices', array(Sales::class, 'freeze_prices_ajax'));

        // Login / Logout
        add_action('wp_ajax_jpsm_login', array(Access_Manager::class, 'login_ajax'));
        add_action('wp_ajax_nopriv_jpsm_login', array(Access_Manager::class, 'login_ajax'));
        add_action('wp_ajax_jpsm_logout', array(Access_Manager::class, 'logout_ajax'));
        add_action('wp_ajax_nopriv_jpsm_logout', array(Access_Manager::class, 'logout_ajax'));

        add_action('wp_ajax_jpsm_get_history', array(Sales::class, 'get_history_ajax'));
        add_action('wp_ajax_nopriv_jpsm_get_history', array(Sales::class, 'get_history_ajax'));

        // Finance
        add_action('wp_ajax_jpsm_record_finance_settlement', array(Finance::class, 'record_settlement_ajax'));
        add_action('wp_ajax_nopriv_jpsm_record_finance_settlement', array(Finance::class, 'record_settlement_ajax'));
        add_action('wp_ajax_jpsm_record_finance_expense', array(Finance::class, 'record_expense_ajax'));
        add_action('wp_ajax_nopriv_jpsm_record_finance_expense', array(Finance::class, 'record_expense_ajax'));
        add_action('wp_ajax_jpsm_delete_finance_settlement', array(Finance::class, 'delete_settlement_ajax'));
        add_action('wp_ajax_nopriv_jpsm_delete_finance_settlement', array(Finance::class, 'delete_settlement_ajax'));
        add_action('wp_ajax_jpsm_delete_finance_expense', array(Finance::class, 'delete_expense_ajax'));
        add_action('wp_ajax_nopriv_jpsm_delete_finance_expense', array(Finance::class, 'delete_expense_ajax'));

        // Access Control / Permissions
        add_action('wp_ajax_jpsm_get_user_tier', array(Access_Manager::class, 'get_user_tier_ajax'));
        add_action('wp_ajax_jpsm_update_user_tier', array(Access_Manager::class, 'update_user_tier_ajax'));
        add_action('wp_ajax_jpsm_get_folders', array(Access_Manager::class, 'get_folders_ajax'));
        add_action('wp_ajax_jpsm_update_folder_tier', array(Access_Manager::class, 'update_folder_tier_ajax'));
        add_action('wp_ajax_jpsm_get_leads', array(Access_Manager::class, 'get_leads_ajax'));
        add_action('wp_ajax_jpsm_log_play', array(Access_Manager::class, 'log_play_ajax'));
        add_action('wp_ajax_nopriv_jpsm_log_play', array(Access_Manager::class, 'log_play_ajax'));

        // MediaVault navigation ordering (admin-configured sidebar + home screen)
        add_action('wp_ajax_jpsm_mv_get_sidebar_folders', array(Nav_Order::class, 'ajax_get_sidebar_folders'));
        add_action('wp_ajax_jpsm_mv_save_sidebar_order', array(Nav_Order::class, 'ajax_save_sidebar_order'));
        add_action('wp_ajax_jpsm_mv_reset_sidebar_order', array(Nav_Order::class, 'ajax_reset_sidebar_order'));

        // MediaVault Index Sync
        add_action('wp_ajax_jpsm_get_index_stats', array(Index_Manager::class, 'get_stats_ajax'));
        add_action('wp_ajax_nopriv_jpsm_get_index_stats', array(Index_Manager::class, 'get_stats_ajax'));
        add_action('wp_ajax_jpsm_sync_mediavault_index', array(Index_Manager::class, 'sync_mediavault_index_ajax'));
        add_action('wp_ajax_nopriv_jpsm_sync_mediavault_index', array(Index_Manager::class, 'sync_mediavault_index_ajax'));
        add_action('wp_ajax_jpsm_auto_detect_bpm_batch', array(Index_Manager::class, 'auto_detect_bpm_batch_ajax'));
        add_action('wp_ajax_jpsm_reset_auto_bpm_scan_marks', array(Index_Manager::class, 'reset_auto_bpm_scan_marks_ajax'));
        add_action('wp_ajax_jpsm_import_bpm_csv', array(Index_Manager::class, 'import_bpm_csv_ajax'));
        add_action('wp_ajax_jpsm_desktop_issue_token', array(Index_Manager::class, 'desktop_issue_token_ajax'));
        add_action('wp_ajax_jpsm_desktop_revoke_token', array(Index_Manager::class, 'desktop_revoke_token_ajax'));
        add_action('wp_ajax_jpsm_import_bpm_batch_api', array(Index_Manager::class, 'import_bpm_batch_api_ajax'));
        add_action('wp_ajax_nopriv_jpsm_import_bpm_batch_api', array(Index_Manager::class, 'import_bpm_batch_api_ajax'));
        add_action('wp_ajax_jpsm_rollback_bpm_batch_api', array(Index_Manager::class, 'rollback_bpm_batch_api_ajax'));
        add_action('wp_ajax_nopriv_jpsm_rollback_bpm_batch_api', array(Index_Manager::class, 'rollback_bpm_batch_api_ajax'));
        add_action('wp_ajax_jpsm_desktop_api_health', array(Index_Manager::class, 'desktop_api_health_ajax'));
        add_action('wp_ajax_nopriv_jpsm_desktop_api_health', array(Index_Manager::class, 'desktop_api_health_ajax'));

        // MediaVault B2 connectivity check (admin only)
        add_action('wp_ajax_jpsm_test_b2_connection', array(Config::class, 'test_b2_connection_ajax'));

        // Behavior analytics (tracking + reporting + CSV export)
        add_action('wp_ajax_jpsm_track_behavior_event', array(Behavior_Service::class, 'track_behavior_event_ajax'));
        add_action('wp_ajax_nopriv_jpsm_track_behavior_event', array(Behavior_Service::class, 'track_behavior_event_ajax'));
        add_action('wp_ajax_jpsm_get_behavior_report', array(Behavior_Service::class, 'get_behavior_report_ajax'));
        add_action('wp_ajax_nopriv_jpsm_get_behavior_report', array(Behavior_Service::class, 'get_behavior_report_ajax'));
        add_action('wp_ajax_jpsm_export_behavior_csv', array(Behavior_Service::class, 'export_behavior_csv_ajax'));
        add_action('wp_ajax_nopriv_jpsm_export_behavior_csv', array(Behavior_Service::class, 'export_behavior_csv_ajax'));
        add_action('wp_ajax_jpsm_get_transfer_report', array(Behavior_Service::class, 'get_transfer_report_ajax'));
        add_action('wp_ajax_nopriv_jpsm_get_transfer_report', array(Behavior_Service::class, 'get_transfer_report_ajax'));
        add_action('wp_ajax_jpsm_export_transfer_csv', array(Behavior_Service::class, 'export_transfer_csv_ajax'));
        add_action('wp_ajax_nopriv_jpsm_export_transfer_csv', array(Behavior_Service::class, 'export_transfer_csv_ajax'));

        // Admin Footer Styles
        add_action('admin_footer', array(Admin_Views::class, 'render_admin_footer'));

        // Shortcode
        // Shortcode - Refactored to JPSM_Dashboard
        add_shortcode('jetpack_manager', array(Dashboard::class, 'render'));
        // Preferred branding alias (Option A: visible rebrand only; internal keys/slug stay the same).
        add_shortcode('mediavault_manager', array(Dashboard::class, 'render'));

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
        $has_shortcode = is_a($post, 'WP_Post') && (
            has_shortcode($post->post_content, 'jetpack_manager')
            || has_shortcode($post->post_content, 'mediavault_manager')
        );

        if ($has_shortcode) {
            $css_path = jpsm_asset_path('assets/css/admin.css');
            $css_file = JPSM_PLUGIN_DIR . $css_path;
            $css_version = file_exists($css_file) ? strval(filemtime($css_file)) : JPSM_VERSION;
            wp_enqueue_style('jpsm-admin-css', JPSM_PLUGIN_URL . $css_path, array(), $css_version, 'all');
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
            'MediaVault Manager',
            'MediaVault Manager',
            'manage_options',
            'jetpack-store-manager',
            array(Admin_Views::class, 'render_dashboard_page'),
            'dashicons-chart-area',
            6
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'jetpack-store-manager',
            array(Admin_Views::class, 'render_dashboard_page')
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Registrar Venta',
            'Registrar Venta',
            'manage_options',
            'jpsm-register-sale',
            array(Admin_Views::class, 'render_registration_page')
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Finanzas',
            'Finanzas',
            'manage_options',
            'jpsm-finance',
            array(Admin_Views::class, 'render_finance_page')
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Sincronizador B2',
            'Sincronizador B2',
            'manage_options',
            'jpsm-synchronizer',
            array(Admin_Views::class, 'render_synchronizer_page')
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Control de Accesos',
            'Control de Accesos',
            'manage_options',
            'jpsm-access-control',
            array(Admin_Views::class, 'render_access_control_page')
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Setup',
            'Setup',
            'manage_options',
            'jpsm-setup',
            array(Admin_Views::class, 'render_setup_page')
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Configuración',
            'Configuración',
            'manage_options',
            'jpsm-settings',
            array(Admin_Views::class, 'render_settings_page')
        );
    }



    /**
     * Register all plugin settings and sanitization callbacks.
     *
     * @return void
     */
    public function register_settings()
    {
        // Package template/price options from domain registry.
        $template_options = array('jpsm_email_template_vip'); // Legacy/fallback key.
        $price_options = array();

        if (class_exists(Domain_Model::class)) {
            foreach (Domain_Model::get_settings_packages() as $package) {
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
        register_setting('jpsm_settings_templates', 'jpsm_access_key', array(
            'sanitize_callback' => array(Admin::class, 'sanitize_access_key'),
        ));

        // Security toggles
        register_setting('jpsm_settings_templates', 'jpsm_wp_admin_only_mode', array(
            'sanitize_callback' => array(Admin::class, 'sanitize_bool'),
        ));
        register_setting('jpsm_settings_templates', 'jpsm_allow_get_key', array(
            'sanitize_callback' => array(Admin::class, 'sanitize_bool'),
        ));

        // Email configuration (non-secret)
        register_setting('jpsm_settings_templates', 'jpsm_reply_to_email', array(
            'sanitize_callback' => array(Admin::class, 'sanitize_reply_to_email'),
        ));
        register_setting('jpsm_settings_templates', 'jpsm_notify_emails', array(
            'sanitize_callback' => array(Admin::class, 'sanitize_email_list'),
        ));

        // MediaVault frontend admin emails (legacy behavior; hardening planned in Phase A3).
        register_setting('jpsm_settings_templates', 'jpsm_admin_emails', array(
            'sanitize_callback' => array(Admin::class, 'sanitize_email_list'),
        ));

        // MediaVault upgrade contact (non-secret)
        register_setting('jpsm_settings_templates', 'jpsm_whatsapp_number', array(
            'sanitize_callback' => array(Admin::class, 'sanitize_whatsapp_number'),
        ));

        foreach (array_unique($price_options) as $price_option) {
            register_setting('jpsm_settings_templates', $price_option);
        }

        // MediaVault (Backblaze B2)
        register_setting('jpsm_settings_templates', 'jpsm_b2_key_id', array(
            'sanitize_callback' => array(Admin::class, 'sanitize_b2_key_id'),
        ));
        register_setting('jpsm_settings_templates', 'jpsm_b2_app_key', array(
            'sanitize_callback' => array(Admin::class, 'sanitize_b2_app_key'),
        ));
        register_setting('jpsm_settings_templates', 'jpsm_b2_bucket');
        register_setting('jpsm_settings_templates', 'jpsm_b2_region');
        register_setting('jpsm_settings_templates', 'jpsm_cloudflare_domain', array(
            'sanitize_callback' => array(Admin::class, 'sanitize_cloudflare_domain'),
        ));

        // Onboarding: page selection (no hardcoded slugs).
        register_setting('jpsm_settings_templates', self::OPTION_MEDIAVAULT_PAGE_ID, array(
            'sanitize_callback' => array(Admin::class, 'sanitize_page_id'),
        ));
        register_setting('jpsm_settings_templates', self::OPTION_MANAGER_PAGE_ID, array(
            'sanitize_callback' => array(Admin::class, 'sanitize_page_id'),
        ));
    }

    /**
     * Sanitize a page ID option value.
     *
     * @param mixed $value Raw page ID value.
     * @return int Valid page ID or 0.
     */
    public static function sanitize_page_id($value)
    {
        $id = intval($value);
        if ($id <= 0) {
            return 0;
        }
        return get_post_status($id) ? $id : 0;
    }

    private static function find_page_id_by_shortcode($shortcodes)
    {
        $shortcodes = (array) $shortcodes;
        $shortcodes = array_values(array_filter(array_map('strval', $shortcodes)));
        if (empty($shortcodes)) {
            return 0;
        }

        $pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => array('publish', 'draft', 'private'),
            'posts_per_page' => 50,
            's' => '[' . $shortcodes[0], // coarse prefilter
            'fields' => 'ids',
        ));

        foreach ($pages as $page_id) {
            $content = (string) get_post_field('post_content', $page_id);
            foreach ($shortcodes as $sc) {
                if (has_shortcode($content, $sc)) {
                    return intval($page_id);
                }
            }
        }
        return 0;
    }

    /**
     * Handle the setup action that creates MediaVault and Manager pages.
     *
     * @return void Redirects to setup page on completion.
     */
    public function handle_setup_create_pages()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('jpsm_setup_create_pages');

        $created = array();

        // MediaVault page
        $mv_page_id = intval(get_option(self::OPTION_MEDIAVAULT_PAGE_ID, 0));
        if ($mv_page_id <= 0 || !get_post_status($mv_page_id)) {
            $existing = self::find_page_id_by_shortcode(array('mediavault_vault', 'jpsm_media_vault'));
            if ($existing > 0) {
                update_option(self::OPTION_MEDIAVAULT_PAGE_ID, $existing);
            } else {
                $new_id = wp_insert_post(array(
                    'post_type' => 'page',
                    'post_status' => 'publish',
                    'post_title' => 'MediaVault',
                    'post_content' => '[mediavault_vault]',
                ));
                if (!is_wp_error($new_id) && $new_id > 0) {
                    update_option(self::OPTION_MEDIAVAULT_PAGE_ID, intval($new_id));
                    $created[] = 'mediavault';
                }
            }
        }

        // Manager page (frontend)
        $mgr_page_id = intval(get_option(self::OPTION_MANAGER_PAGE_ID, 0));
        if ($mgr_page_id <= 0 || !get_post_status($mgr_page_id)) {
            $existing = self::find_page_id_by_shortcode(array('mediavault_manager', 'jetpack_manager'));
            if ($existing > 0) {
                update_option(self::OPTION_MANAGER_PAGE_ID, $existing);
            } else {
                $new_id = wp_insert_post(array(
                    'post_type' => 'page',
                    'post_status' => 'publish',
                    'post_title' => 'MediaVault Manager',
                    'post_content' => '[mediavault_manager]',
                ));
                if (!is_wp_error($new_id) && $new_id > 0) {
                    update_option(self::OPTION_MANAGER_PAGE_ID, intval($new_id));
                    $created[] = 'manager';
                }
            }
        }

        $redirect = add_query_arg(array(
            'page' => 'jpsm-setup',
            'created' => implode(',', $created),
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }



    /**
     * Enqueue admin CSS on plugin pages only.
     *
     * @param string $hook Current admin page hook suffix.
     * @return void
     */
    public function enqueue_styles($hook)
    {
        if (!is_string($hook) || $hook === '') {
            return;
        }
        // Only load on our plugin pages
        if (strpos($hook, 'jetpack-store') === false && strpos($hook, 'jpsm-') === false) {
            return;
        }
        $css_path = jpsm_asset_path('assets/css/admin.css');
        $css_file = JPSM_PLUGIN_DIR . $css_path;
        $css_version = file_exists($css_file) ? strval(filemtime($css_file)) : JPSM_VERSION;
        wp_enqueue_style('jpsm-admin-css', JPSM_PLUGIN_URL . $css_path, array(), $css_version, 'all');
    }

    /**
     * Enqueue admin JS and localize script data on plugin pages only.
     *
     * @param string $hook Current admin page hook suffix.
     * @return void
     */
    public function enqueue_scripts($hook)
    {
        if (!is_string($hook) || $hook === '') {
            return;
        }
        // Only load on our plugin pages
        if (strpos($hook, 'jetpack-store') === false && strpos($hook, 'jpsm-') === false) {
            return;
        }
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1', array(), '3.9.1', true);
        // jQuery UI Sortable is used on Access Control -> "Orden de Carpetas" drag & drop.
        $admin_js = jpsm_asset_path('assets/js/admin.js');
        wp_enqueue_script('jpsm-admin-js', JPSM_PLUGIN_URL . $admin_js, array('jquery', 'chart-js', 'jquery-ui-sortable'), JPSM_VERSION, false);

        // Prepare Dashboard Data
        $stats = Sales::get_dashboard_stats();

        wp_localize_script('jpsm-admin-js', 'jpsm_data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jpsm_nonce'),
            'nonces' => array(
                'sales' => wp_create_nonce('jpsm_sales_nonce'),
                'finance' => wp_create_nonce('jpsm_finance_nonce'),
                'access' => wp_create_nonce('jpsm_access_nonce'),
                'index' => wp_create_nonce('jpsm_index_nonce'),
                'login' => wp_create_nonce('jpsm_login_nonce'),
                'logout' => wp_create_nonce('jpsm_logout_nonce'),
                'mediavault' => wp_create_nonce('jpsm_mediavault_nonce'),
            ),
            'stats' => $stats,
            'tier_options' => class_exists(Domain_Model::class) ? Domain_Model::get_tier_options_for_ui() : array()
        ));
    }






    /**
     * Helper to verify Access via Admin, Key or Cookie
     */


    private function verify_access()
    {
        if (class_exists(Auth::class)) {
            return Auth::is_admin_authenticated(true);
        }

        return current_user_can('manage_options');
    }


    /**
     * Register Custom Page Templates in the Page Attributes dropdown.
     *
     * @param array $templates Existing page templates.
     * @return array Modified templates array.
     */
    public function register_custom_templates($templates)
    {
        $templates['templates/page-fullwidth.php'] = 'MediaVault Full Width';
        return $templates;
    }

    /**
     * Tell WordPress to use the plugin's template file if selected.
     *
     * @param string $template Current template path.
     * @return string Resolved template path.
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

    /**
     * Secret options are write-only in Settings UI. If a user submits an empty value,
     * keep the existing stored value (prevents accidental wipes while values are hidden).
     */
    private static function sanitize_secret_keep_existing($new_value, $option_name)
    {
        $new_value = is_string($new_value) ? trim($new_value) : '';
        if ($new_value === '') {
            $existing = get_option($option_name, '');
            return is_string($existing) ? $existing : '';
        }
        return sanitize_text_field($new_value);
    }

    /**
     * Sanitize the mobile access key, preserving existing value if empty.
     *
     * @param mixed $value Raw access key input.
     * @return string Sanitized access key.
     */
    public static function sanitize_access_key($value)
    {
        return self::sanitize_secret_keep_existing($value, 'jpsm_access_key');
    }

    /**
     * Sanitize Backblaze B2 key ID, preserving existing value if empty.
     *
     * @param mixed $value Raw B2 key ID input.
     * @return string Sanitized key ID.
     */
    public static function sanitize_b2_key_id($value)
    {
        return self::sanitize_secret_keep_existing($value, 'jpsm_b2_key_id');
    }

    /**
     * Sanitize Backblaze B2 application key, preserving existing value if empty.
     *
     * @param mixed $value Raw B2 application key input.
     * @return string Sanitized application key.
     */
    public static function sanitize_b2_app_key($value)
    {
        return self::sanitize_secret_keep_existing($value, 'jpsm_b2_app_key');
    }

    /**
     * Sanitize the Reply-To email address.
     *
     * @param mixed $value Raw email input.
     * @return string Valid email address or empty string.
     */
    public static function sanitize_reply_to_email($value)
    {
        $email = sanitize_email((string) $value);
        return (is_email($email)) ? $email : '';
    }

    /**
     * Sanitize a list of emails from textarea/string or array input.
     *
     * @return array<int, string>
     */
    public static function sanitize_email_list($value)
    {
        $raw = $value;
        if (is_array($raw)) {
            $tokens = $raw;
        } else {
            $raw = (string) $raw;
            $tokens = preg_split('/[\\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }

        $out = array();
        foreach ((array) $tokens as $token) {
            $email = sanitize_email((string) $token);
            if ($email && is_email($email)) {
                $out[] = strtolower($email);
            }
        }

        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /**
     * Sanitize a boolean-ish option stored as 0/1.
     */
    public static function sanitize_bool($value)
    {
        return !empty($value) ? 1 : 0;
    }

    /**
     * Sanitize WhatsApp phone number (digits only, international format recommended).
     */
    public static function sanitize_whatsapp_number($value)
    {
        $digits = preg_replace('/\\D+/', '', (string) $value);
        if ($digits === null) {
            $digits = '';
        }
        $digits = (string) $digits;

        if ($digits === '') {
            return '';
        }

        // Keep a reasonable length window (country code + number).
        $len = strlen($digits);
        if ($len < 8 || $len > 18) {
            return '';
        }

        return $digits;
    }

    /**
     * Accept only HTTPS origin (scheme + host + optional port).
     * Invalid values are cleared to keep runtime fallback safe.
     */
    public static function sanitize_cloudflare_domain($value)
    {
        if (!class_exists(Config::class)) {
            return '';
        }
        return Config::normalize_cloudflare_domain($value);
    }

}

// Backward compatibility alias.
class_alias(Admin::class, 'JPSM_Admin');
