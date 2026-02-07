<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Phase 2 data layer for core JPSM entities.
 *
 * Entities moved to tables:
 * - Sales log
 * - User tiers
 * - Leads
 * - Demo play counts
 */
class JPSM_Data_Layer
{
    const SCHEMA_VERSION = '1.0.0';
    const OPT_SCHEMA_VERSION = 'jpsm_data_layer_schema_version';
    const OPT_MIGRATION_STATE = 'jpsm_data_layer_migration_state';

    const LEGACY_SALES_OPTION = 'jpsm_sales_log';
    const LEGACY_USER_TIERS_OPTION = 'jpsm_user_tiers';
    const LEGACY_LEADS_OPTION = 'jpsm_leads_list';
    const LEGACY_PLAY_COUNTS_OPTION = 'jpsm_demo_play_counts';

    const TABLE_SALES = 'jpsm_sales';
    const TABLE_USER_TIERS = 'jpsm_user_tiers';
    const TABLE_LEADS = 'jpsm_leads';
    const TABLE_PLAY_COUNTS = 'jpsm_play_counts';

    /**
     * Bootstrap schema and one-time migrations.
     */
    public static function bootstrap()
    {
        self::install_schema();
        self::run_migrations();
    }

    /**
     * Install/upgrade tables using dbDelta.
     */
    public static function install_schema()
    {
        global $wpdb;

        $current = (string) get_option(self::OPT_SCHEMA_VERSION, '');
        if ($current === self::SCHEMA_VERSION) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sales_table = self::sales_table();
        $tiers_table = self::tiers_table();
        $leads_table = self::leads_table();
        $plays_table = self::plays_table();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_sales = "CREATE TABLE $sales_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sale_uid varchar(64) NOT NULL,
            sale_time datetime NOT NULL,
            email varchar(190) NOT NULL,
            package varchar(191) NOT NULL,
            region varchar(32) NOT NULL DEFAULT '',
            amount decimal(12,2) NOT NULL DEFAULT 0,
            currency varchar(8) NOT NULL DEFAULT '',
            status varchar(32) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY sale_uid (sale_uid),
            KEY email_time (email, sale_time),
            KEY sale_time (sale_time)
        ) $charset_collate;";

        $sql_tiers = "CREATE TABLE $tiers_table (
            email varchar(190) NOT NULL,
            tier smallint(6) NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (email),
            KEY tier (tier)
        ) $charset_collate;";

        $sql_leads = "CREATE TABLE $leads_table (
            email varchar(190) NOT NULL,
            registered_at datetime NOT NULL,
            source varchar(100) NOT NULL DEFAULT '',
            PRIMARY KEY  (email),
            KEY registered_at (registered_at)
        ) $charset_collate;";

        $sql_plays = "CREATE TABLE $plays_table (
            email varchar(190) NOT NULL,
            play_count int(11) NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (email)
        ) $charset_collate;";

        dbDelta($sql_sales);
        dbDelta($sql_tiers);
        dbDelta($sql_leads);
        dbDelta($sql_plays);

        update_option(self::OPT_SCHEMA_VERSION, self::SCHEMA_VERSION, false);
    }

    /**
     * ----------------------------
     * Table Name Helpers
     * ----------------------------
     */
    public static function sales_table()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SALES;
    }

    public static function tiers_table()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_USER_TIERS;
    }

    public static function leads_table()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_LEADS;
    }

    public static function plays_table()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_PLAY_COUNTS;
    }

    /**
     * ----------------------------
     * Sales Log CRUD
     * ----------------------------
     */
    public static function get_sales_log($limit = 0)
    {
        global $wpdb;
        $table = self::sales_table();
        $limit = intval($limit);

        if (self::table_exists($table)) {
            $sql = "SELECT sale_uid, sale_time, email, package, region, amount, currency, status
                    FROM $table
                    ORDER BY sale_time DESC, id DESC";
            if ($limit > 0) {
                $sql .= $wpdb->prepare(' LIMIT %d', $limit);
            }

            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (!empty($rows) || self::is_migrated('sales')) {
                $entries = array_map(array(__CLASS__, 'row_to_sale_entry'), $rows);
                return is_array($entries) ? $entries : array();
            }
        }

        $legacy = self::get_legacy_sales_log();
        if ($limit > 0) {
            return array_slice($legacy, 0, $limit);
        }
        return $legacy;
    }

    public static function get_sale_by_uid($sale_uid)
    {
        global $wpdb;
        $sale_uid = sanitize_text_field((string) $sale_uid);
        if ($sale_uid === '') {
            return null;
        }

        $table = self::sales_table();
        if (self::table_exists($table)) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT sale_uid, sale_time, email, package, region, amount, currency, status
                     FROM $table
                     WHERE sale_uid = %s
                     LIMIT 1",
                    $sale_uid
                ),
                ARRAY_A
            );
            if (is_array($row) && !empty($row)) {
                return self::row_to_sale_entry($row);
            }
            if (self::is_migrated('sales')) {
                return null;
            }
        }

        $legacy = self::get_legacy_sales_log();
        foreach ($legacy as $entry) {
            if (isset($entry['id']) && (string) $entry['id'] === $sale_uid) {
                return self::normalize_sale_entry($entry);
            }
        }
        return null;
    }

    public static function get_sales_by_email($email)
    {
        global $wpdb;
        $email = sanitize_email($email);
        if (!is_email($email)) {
            return array();
        }

        $table = self::sales_table();
        if (self::table_exists($table)) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT sale_uid, sale_time, email, package, region, amount, currency, status
                     FROM $table
                     WHERE email = %s
                     ORDER BY sale_time DESC, id DESC",
                    strtolower($email)
                ),
                ARRAY_A
            );

            if (!empty($rows) || self::is_migrated('sales')) {
                return array_map(array(__CLASS__, 'row_to_sale_entry'), $rows);
            }
        }

        $legacy = self::get_legacy_sales_log();
        $filtered = array();
        foreach ($legacy as $entry) {
            if (isset($entry['email']) && strcasecmp((string) $entry['email'], $email) === 0) {
                $filtered[] = self::normalize_sale_entry($entry);
            }
        }
        return $filtered;
    }

    public static function create_sale_entry($entry, $mirror_legacy = true)
    {
        global $wpdb;
        $entry = self::normalize_sale_entry($entry);
        $table = self::sales_table();

        $saved = false;
        if (self::table_exists($table)) {
            $row_id = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM $table WHERE sale_uid = %s LIMIT 1", $entry['id'])
            );

            $row_data = array(
                'sale_uid' => $entry['id'],
                'sale_time' => $entry['time'],
                'email' => strtolower($entry['email']),
                'package' => $entry['package'],
                'region' => $entry['region'],
                'amount' => floatval($entry['amount']),
                'currency' => strtoupper($entry['currency']),
                'status' => $entry['status'],
            );

            if ($row_id) {
                $saved = false !== $wpdb->update(
                    $table,
                    $row_data,
                    array('id' => intval($row_id)),
                    array('%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s'),
                    array('%d')
                );
            } else {
                $saved = false !== $wpdb->insert(
                    $table,
                    $row_data,
                    array('%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s')
                );
            }
        }

        if ($mirror_legacy || !$saved) {
            self::mirror_sale_entry_to_option($entry);
        }

        return $entry;
    }

    public static function delete_sale_by_uid($sale_uid, $mirror_legacy = true)
    {
        global $wpdb;
        $sale_uid = sanitize_text_field((string) $sale_uid);
        if ($sale_uid === '') {
            return false;
        }

        $deleted = false;
        $table = self::sales_table();
        if (self::table_exists($table)) {
            $deleted = false !== $wpdb->delete($table, array('sale_uid' => $sale_uid), array('%s'));
        }

        if ($mirror_legacy || !$deleted) {
            self::mirror_delete_sale_from_option($sale_uid);
        }

        return $deleted;
    }

    public static function delete_sales_by_uids($sale_uids, $mirror_legacy = true)
    {
        global $wpdb;
        if (!is_array($sale_uids) || empty($sale_uids)) {
            return 0;
        }

        $ids = array_values(array_unique(array_filter(array_map('sanitize_text_field', $sale_uids))));
        if (empty($ids)) {
            return 0;
        }

        $deleted_count = 0;
        $table = self::sales_table();
        if (self::table_exists($table)) {
            $placeholders = implode(',', array_fill(0, count($ids), '%s'));
            $sql = $wpdb->prepare("DELETE FROM $table WHERE sale_uid IN ($placeholders)", $ids);
            $result = $wpdb->query($sql);
            if (is_numeric($result)) {
                $deleted_count = intval($result);
            }
        }

        if ($mirror_legacy || $deleted_count === 0) {
            self::mirror_delete_sales_from_option($ids);
        }

        return $deleted_count;
    }

    public static function clear_sales($mirror_legacy = true)
    {
        global $wpdb;
        $table = self::sales_table();
        if (self::table_exists($table)) {
            $wpdb->query("DELETE FROM $table");
        }

        if ($mirror_legacy) {
            update_option(self::LEGACY_SALES_OPTION, array(), false);
        }
    }

    public static function replace_sales_log($entries, $mirror_legacy = true)
    {
        global $wpdb;
        $normalized = array();
        if (!is_array($entries)) {
            $entries = array();
        }

        foreach ($entries as $entry) {
            $normalized[] = self::normalize_sale_entry($entry);
        }

        $table = self::sales_table();
        if (self::table_exists($table)) {
            $wpdb->query("DELETE FROM $table");
            foreach ($normalized as $entry) {
                self::create_sale_entry($entry, false);
            }
        }

        if ($mirror_legacy) {
            update_option(self::LEGACY_SALES_OPTION, $normalized, false);
        }

        return count($normalized);
    }

    /**
     * ----------------------------
     * User Tiers CRUD
     * ----------------------------
     */
    public static function get_user_tier($email)
    {
        global $wpdb;
        $email = self::normalize_email($email);
        if ($email === '') {
            return 0;
        }

        $table = self::tiers_table();
        if (self::table_exists($table)) {
            $tier = $wpdb->get_var(
                $wpdb->prepare("SELECT tier FROM $table WHERE email = %s LIMIT 1", $email)
            );
            if ($tier !== null) {
                return intval($tier);
            }
            if (self::is_migrated('tiers')) {
                return 0;
            }
        }

        $legacy = self::get_legacy_user_tiers();
        return isset($legacy[$email]) ? intval($legacy[$email]) : 0;
    }

    public static function set_user_tier($email, $tier, $mirror_legacy = true)
    {
        global $wpdb;
        $email = self::normalize_email($email);
        if ($email === '') {
            return false;
        }

        $tier = intval($tier);
        $saved = false;
        $table = self::tiers_table();
        if (self::table_exists($table)) {
            $saved = false !== $wpdb->replace(
                $table,
                array(
                    'email' => $email,
                    'tier' => $tier,
                    'updated_at' => current_time('mysql'),
                ),
                array('%s', '%d', '%s')
            );
        }

        if ($mirror_legacy || !$saved) {
            $legacy = self::get_legacy_user_tiers();
            $legacy[$email] = $tier;
            update_option(self::LEGACY_USER_TIERS_OPTION, $legacy, false);
        }

        return true;
    }

    public static function get_all_user_tiers()
    {
        global $wpdb;
        $table = self::tiers_table();

        if (self::table_exists($table)) {
            $rows = $wpdb->get_results("SELECT email, tier FROM $table", ARRAY_A);
            if (!empty($rows) || self::is_migrated('tiers')) {
                $map = array();
                foreach ($rows as $row) {
                    $email = self::normalize_email(isset($row['email']) ? $row['email'] : '');
                    if ($email !== '') {
                        $map[$email] = intval($row['tier']);
                    }
                }
                return $map;
            }
        }

        return self::get_legacy_user_tiers();
    }

    /**
     * ----------------------------
     * Leads CRUD
     * ----------------------------
     */
    public static function register_lead($email, $source = 'mediavault_demo', $registered_at = null, $mirror_legacy = true)
    {
        global $wpdb;
        $email = self::normalize_email($email);
        if ($email === '') {
            return false;
        }

        if ($registered_at === null || $registered_at === '') {
            $registered_at = current_time('mysql');
        }
        $registered_at = sanitize_text_field((string) $registered_at);
        $source = sanitize_text_field((string) $source);

        $saved = false;
        $table = self::leads_table();
        if (self::table_exists($table)) {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT email FROM $table WHERE email = %s LIMIT 1", $email)
            );

            if (!$exists) {
                $saved = false !== $wpdb->insert(
                    $table,
                    array(
                        'email' => $email,
                        'registered_at' => $registered_at,
                        'source' => $source,
                    ),
                    array('%s', '%s', '%s')
                );
            } else {
                $saved = true;
            }
        }

        if ($mirror_legacy || !$saved) {
            $legacy = self::get_legacy_leads();
            if (!isset($legacy[$email])) {
                $legacy[$email] = array(
                    'email' => $email,
                    'registered' => $registered_at,
                    'source' => $source,
                );
                update_option(self::LEGACY_LEADS_OPTION, $legacy, false);
            }
        }

        return true;
    }

    public static function get_leads()
    {
        global $wpdb;
        $table = self::leads_table();

        if (self::table_exists($table)) {
            $rows = $wpdb->get_results(
                "SELECT email, registered_at, source
                 FROM $table
                 ORDER BY registered_at DESC",
                ARRAY_A
            );
            if (!empty($rows) || self::is_migrated('leads')) {
                $map = array();
                foreach ($rows as $row) {
                    $email = self::normalize_email(isset($row['email']) ? $row['email'] : '');
                    if ($email === '') {
                        continue;
                    }
                    $map[$email] = array(
                        'email' => $email,
                        'registered' => isset($row['registered_at']) ? (string) $row['registered_at'] : '',
                        'source' => isset($row['source']) ? (string) $row['source'] : '',
                    );
                }
                return $map;
            }
        }

        return self::get_legacy_leads();
    }

    /**
     * ----------------------------
     * Play Counts CRUD
     * ----------------------------
     */
    public static function get_play_count($email)
    {
        global $wpdb;
        $email = self::normalize_email($email);
        if ($email === '') {
            return 0;
        }

        $table = self::plays_table();
        if (self::table_exists($table)) {
            $count = $wpdb->get_var(
                $wpdb->prepare("SELECT play_count FROM $table WHERE email = %s LIMIT 1", $email)
            );
            if ($count !== null) {
                return intval($count);
            }
            if (self::is_migrated('plays')) {
                return 0;
            }
        }

        $legacy = self::get_legacy_play_counts();
        return isset($legacy[$email]) ? intval($legacy[$email]) : 0;
    }

    public static function increment_play_count($email, $mirror_legacy = true)
    {
        global $wpdb;
        $email = self::normalize_email($email);
        if ($email === '') {
            return 0;
        }

        $new_count = 0;
        $table = self::plays_table();
        if (self::table_exists($table)) {
            $current = $wpdb->get_var(
                $wpdb->prepare("SELECT play_count FROM $table WHERE email = %s LIMIT 1", $email)
            );

            $new_count = is_numeric($current) ? intval($current) + 1 : 1;
            $wpdb->replace(
                $table,
                array(
                    'email' => $email,
                    'play_count' => $new_count,
                    'updated_at' => current_time('mysql'),
                ),
                array('%s', '%d', '%s')
            );
        }

        if ($mirror_legacy || $new_count === 0) {
            $legacy = self::get_legacy_play_counts();
            $legacy[$email] = isset($legacy[$email]) ? intval($legacy[$email]) + 1 : 1;
            $new_count = intval($legacy[$email]);
            update_option(self::LEGACY_PLAY_COUNTS_OPTION, $legacy, false);
        }

        return $new_count;
    }

    /**
     * ----------------------------
     * Migration
     * ----------------------------
     */
    public static function run_migrations()
    {
        self::maybe_migrate('sales', 'migrate_sales_from_legacy');
        self::maybe_migrate('tiers', 'migrate_tiers_from_legacy');
        self::maybe_migrate('leads', 'migrate_leads_from_legacy');
        self::maybe_migrate('plays', 'migrate_play_counts_from_legacy');
    }

    private static function maybe_migrate($entity, $method)
    {
        if (self::is_migrated($entity)) {
            return;
        }

        if (!is_callable(array(__CLASS__, $method))) {
            error_log("[JPSM DataLayer] Migration method not callable: {$method}");
            return;
        }

        try {
            $result = call_user_func(array(__CLASS__, $method));
            if (!is_array($result)) {
                $result = array();
            }
            if (isset($result['skipped'])) {
                error_log("[JPSM DataLayer] Migration skipped for {$entity}: " . $result['skipped']);
                return;
            }
            self::mark_migrated($entity, $result);
        } catch (Throwable $e) {
            error_log("[JPSM DataLayer] Migration failed for {$entity}: " . $e->getMessage());
        }
    }

    private static function migrate_sales_from_legacy()
    {
        $table = self::sales_table();
        if (!self::table_exists($table)) {
            return array('skipped' => 'table_missing');
        }

        $legacy = self::get_legacy_sales_log();
        $migrated = 0;

        foreach ($legacy as $entry) {
            self::create_sale_entry($entry, false);
            $migrated++;
        }

        error_log("[JPSM DataLayer] Sales migrated: {$migrated}");
        return array('migrated' => $migrated, 'legacy_total' => count($legacy));
    }

    private static function migrate_tiers_from_legacy()
    {
        $table = self::tiers_table();
        if (!self::table_exists($table)) {
            return array('skipped' => 'table_missing');
        }

        $legacy = self::get_legacy_user_tiers();
        $migrated = 0;

        foreach ($legacy as $email => $tier) {
            if (!is_email($email)) {
                continue;
            }
            self::set_user_tier($email, intval($tier), false);
            $migrated++;
        }

        error_log("[JPSM DataLayer] Tiers migrated: {$migrated}");
        return array('migrated' => $migrated, 'legacy_total' => count($legacy));
    }

    private static function migrate_leads_from_legacy()
    {
        $table = self::leads_table();
        if (!self::table_exists($table)) {
            return array('skipped' => 'table_missing');
        }

        $legacy = self::get_legacy_leads();
        $migrated = 0;

        foreach ($legacy as $email => $data) {
            $lead_email = is_array($data) && !empty($data['email']) ? $data['email'] : $email;
            $source = is_array($data) && isset($data['source']) ? $data['source'] : 'mediavault_demo';
            $registered = is_array($data) && isset($data['registered']) ? $data['registered'] : current_time('mysql');

            if (!is_email($lead_email)) {
                continue;
            }

            self::register_lead($lead_email, $source, $registered, false);
            $migrated++;
        }

        error_log("[JPSM DataLayer] Leads migrated: {$migrated}");
        return array('migrated' => $migrated, 'legacy_total' => count($legacy));
    }

    private static function migrate_play_counts_from_legacy()
    {
        global $wpdb;
        $legacy = self::get_legacy_play_counts();
        $migrated = 0;
        $table = self::plays_table();

        if (!self::table_exists($table)) {
            return array('migrated' => 0, 'legacy_total' => count($legacy), 'skipped' => 'table_missing');
        }

        foreach ($legacy as $email => $count) {
            $email = self::normalize_email($email);
            if ($email === '') {
                continue;
            }

            $wpdb->replace(
                $table,
                array(
                    'email' => $email,
                    'play_count' => intval($count),
                    'updated_at' => current_time('mysql'),
                ),
                array('%s', '%d', '%s')
            );
            $migrated++;
        }

        error_log("[JPSM DataLayer] Play counts migrated: {$migrated}");
        return array('migrated' => $migrated, 'legacy_total' => count($legacy));
    }

    /**
     * ----------------------------
     * Internal Helpers
     * ----------------------------
     */
    private static function table_exists($table_name)
    {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        return (string) $result === (string) $table_name;
    }

    private static function get_migration_state()
    {
        $state = get_option(self::OPT_MIGRATION_STATE, array());
        return is_array($state) ? $state : array();
    }

    private static function is_migrated($entity)
    {
        $state = self::get_migration_state();
        return !empty($state[$entity]['done']);
    }

    private static function mark_migrated($entity, $meta = array())
    {
        $state = self::get_migration_state();
        $state[$entity] = array_merge(
            array(
                'done' => true,
                'at' => current_time('mysql'),
            ),
            is_array($meta) ? $meta : array()
        );
        update_option(self::OPT_MIGRATION_STATE, $state, false);
    }

    private static function normalize_email($email)
    {
        $email = sanitize_email((string) $email);
        if (!is_email($email)) {
            return '';
        }
        return strtolower($email);
    }

    private static function normalize_sale_entry($entry)
    {
        if (!is_array($entry)) {
            $entry = array();
        }

        $id = isset($entry['id']) ? sanitize_text_field((string) $entry['id']) : '';
        if ($id === '') {
            $id = uniqid('sale_');
        }

        $time = isset($entry['time']) ? sanitize_text_field((string) $entry['time']) : current_time('mysql');
        if ($time === '') {
            $time = current_time('mysql');
        }

        $email = isset($entry['email']) ? self::normalize_email($entry['email']) : '';
        $package = isset($entry['package']) ? sanitize_text_field((string) $entry['package']) : '';
        $region = isset($entry['region']) ? sanitize_text_field((string) $entry['region']) : '';
        $amount = isset($entry['amount']) ? floatval($entry['amount']) : 0.0;
        $currency = isset($entry['currency']) ? sanitize_text_field((string) $entry['currency']) : '';
        $status = isset($entry['status']) ? sanitize_text_field((string) $entry['status']) : '';

        return array(
            'id' => $id,
            'time' => $time,
            'email' => $email,
            'package' => $package,
            'region' => $region,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
        );
    }

    private static function row_to_sale_entry($row)
    {
        return array(
            'id' => isset($row['sale_uid']) ? (string) $row['sale_uid'] : '',
            'time' => isset($row['sale_time']) ? (string) $row['sale_time'] : '',
            'email' => isset($row['email']) ? (string) $row['email'] : '',
            'package' => isset($row['package']) ? (string) $row['package'] : '',
            'region' => isset($row['region']) ? (string) $row['region'] : '',
            'amount' => isset($row['amount']) ? floatval($row['amount']) : 0.0,
            'currency' => isset($row['currency']) ? (string) $row['currency'] : '',
            'status' => isset($row['status']) ? (string) $row['status'] : '',
        );
    }

    private static function get_legacy_sales_log()
    {
        $legacy = get_option(self::LEGACY_SALES_OPTION, array());
        return is_array($legacy) ? array_values($legacy) : array();
    }

    private static function get_legacy_user_tiers()
    {
        $legacy = get_option(self::LEGACY_USER_TIERS_OPTION, array());
        if (!is_array($legacy)) {
            return array();
        }

        $normalized = array();
        foreach ($legacy as $email => $tier) {
            $email_key = self::normalize_email($email);
            if ($email_key !== '') {
                $normalized[$email_key] = intval($tier);
            }
        }
        return $normalized;
    }

    private static function get_legacy_leads()
    {
        $legacy = get_option(self::LEGACY_LEADS_OPTION, array());
        return is_array($legacy) ? $legacy : array();
    }

    private static function get_legacy_play_counts()
    {
        $legacy = get_option(self::LEGACY_PLAY_COUNTS_OPTION, array());
        if (!is_array($legacy)) {
            return array();
        }

        $normalized = array();
        foreach ($legacy as $email => $count) {
            $email_key = self::normalize_email($email);
            if ($email_key !== '') {
                $normalized[$email_key] = intval($count);
            }
        }
        return $normalized;
    }

    private static function mirror_sale_entry_to_option($entry)
    {
        $entry = self::normalize_sale_entry($entry);
        $legacy = self::get_legacy_sales_log();
        $replaced = false;

        foreach ($legacy as $idx => $row) {
            if (isset($row['id']) && (string) $row['id'] === $entry['id']) {
                $legacy[$idx] = $entry;
                $replaced = true;
                break;
            }
        }

        if (!$replaced) {
            array_unshift($legacy, $entry);
        }

        $legacy = array_slice($legacy, 0, 1000);
        update_option(self::LEGACY_SALES_OPTION, $legacy, false);
    }

    private static function mirror_delete_sale_from_option($sale_uid)
    {
        $legacy = self::get_legacy_sales_log();
        $new = array();
        foreach ($legacy as $entry) {
            if (isset($entry['id']) && (string) $entry['id'] === $sale_uid) {
                continue;
            }
            $new[] = $entry;
        }
        update_option(self::LEGACY_SALES_OPTION, $new, false);
    }

    private static function mirror_delete_sales_from_option($sale_uids)
    {
        $sale_uids = array_values(array_unique(array_filter(array_map('strval', (array) $sale_uids))));
        $lookup = array_fill_keys($sale_uids, true);
        $legacy = self::get_legacy_sales_log();
        $new = array();

        foreach ($legacy as $entry) {
            $id = isset($entry['id']) ? (string) $entry['id'] : '';
            if ($id !== '' && isset($lookup[$id])) {
                continue;
            }
            $new[] = $entry;
        }

        update_option(self::LEGACY_SALES_OPTION, $new, false);
    }
}
