<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all database tables, options, transients, and cron jobs
 * created by MediaVault Manager. This is irreversible.
 *
 * @link  https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/
 * @since 1.3.0
 */

// Abort if not called by WordPress.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

global $wpdb;

// ─── 1. Drop custom tables ──────────────────────────────────────────
$tables = array(
    // Core Data Layer tables
    $wpdb->prefix . 'jpsm_sales',
    $wpdb->prefix . 'jpsm_user_tiers',
    $wpdb->prefix . 'jpsm_leads',
    $wpdb->prefix . 'jpsm_play_counts',
    $wpdb->prefix . 'jpsm_folder_download_events',
    $wpdb->prefix . 'jpsm_behavior_events',
    $wpdb->prefix . 'jpsm_behavior_daily',
    $wpdb->prefix . 'jpsm_finance_settlements',
    $wpdb->prefix . 'jpsm_finance_settlement_items',
    $wpdb->prefix . 'jpsm_finance_expenses',

    // MediaVault index tables
    $wpdb->prefix . 'jpsm_mediavault_index',
    $wpdb->prefix . 'jpsm_mediavault_index_shadow',
    $wpdb->prefix . 'jpsm_mediavault_bpm_overrides',
    $wpdb->prefix . 'jpsm_mediavault_bpm_batches',
    $wpdb->prefix . 'jpsm_mediavault_bpm_batch_rows',

    // Traffic Manager
    $wpdb->prefix . 'mediavault_logs',
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS `" . esc_sql($table) . "`");
}

// ─── 2. Delete options ──────────────────────────────────────────────
$options = array(
    // Data Layer
    'jpsm_data_layer_schema_version',
    'jpsm_data_layer_migration_state',
    'jpsm_transfer_backfill_state',

    // Legacy sales log (wp_options storage)
    'jpsm_sales_log',
    'jpsm_lifetime_stats',

    // Access & permissions
    'jpsm_user_tiers',
    'jpsm_folder_permissions',
    'jpsm_demo_play_counts',
    'jpsm_leads_list',
    'jpsm_access_key',

    // Settings: email templates
    'jpsm_email_template_basic',
    'jpsm_email_template_vip',
    'jpsm_email_template_vip_videos',
    'jpsm_email_template_vip_pelis',
    'jpsm_email_template_vip_basic',
    'jpsm_email_template_full',

    // Settings: pricing (MXN)
    'jpsm_price_mxn_basic',
    'jpsm_price_mxn_vip_videos',
    'jpsm_price_mxn_vip_pelis',
    'jpsm_price_mxn_vip_basic',
    'jpsm_price_mxn_full',

    // Settings: pricing (USD)
    'jpsm_price_usd_vip_videos',
    'jpsm_price_usd_vip_pelis',
    'jpsm_price_usd_vip_basic',
    'jpsm_price_usd_full',

    // Settings: notifications & admin
    'jpsm_reply_to_email',
    'jpsm_notify_emails',
    'jpsm_admin_emails',
    'jpsm_whatsapp_number',
    'jpsm_wp_admin_only_mode',
    'jpsm_allow_get_key',

    // B2 / Cloudflare (only from wp_options; wp-config.php constants remain)
    'jpsm_b2_key_id',
    'jpsm_b2_app_key',
    'jpsm_b2_bucket',
    'jpsm_b2_region',
    'jpsm_cloudflare_domain',

    // MediaVault index state
    'jpsm_mediavault_index_active_table',
    'jpsm_mediavault_last_sync',
    'jpsm_mediavault_index_version',
    'jpsm_mediavault_sidebar_order',

    // Page IDs
    'jpsm_mediavault_page_id',
    'jpsm_manager_page_id',

    // Downloader module
    'jdd_google_api_key',
    'jdd_root_folder_id',
);

foreach ($options as $option) {
    delete_option($option);
}

// ─── 3. Delete transients ───────────────────────────────────────────
delete_transient('jpsm_b2_config_notice_dismissed');

// Clean any MediaVault preview transients.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_mv_preview_%'
        OR option_name LIKE '_transient_timeout_mv_preview_%'
        OR option_name LIKE '_transient_jpsm_%'
        OR option_name LIKE '_transient_timeout_jpsm_%'"
);

// ─── 4. Clear cron jobs ─────────────────────────────────────────────
wp_clear_scheduled_hook('jpsm_behavior_daily_rollup');
wp_clear_scheduled_hook('jpsm_mediavault_sync_index');
