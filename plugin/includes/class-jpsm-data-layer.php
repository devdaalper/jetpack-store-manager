<?php
namespace JetpackStore;

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
 * - Folder download events (analytics)
 */
class Data_Layer
{
    const SCHEMA_VERSION = '1.4.0';
    const OPT_SCHEMA_VERSION = 'jpsm_data_layer_schema_version';
    const OPT_MIGRATION_STATE = 'jpsm_data_layer_migration_state';
    const OPT_TRANSFER_BACKFILL_STATE = 'jpsm_transfer_backfill_state';

    const LEGACY_SALES_OPTION = 'jpsm_sales_log';
    const LEGACY_USER_TIERS_OPTION = 'jpsm_user_tiers';
    const LEGACY_LEADS_OPTION = 'jpsm_leads_list';
    const LEGACY_PLAY_COUNTS_OPTION = 'jpsm_demo_play_counts';

    const TABLE_SALES = 'jpsm_sales';
    const TABLE_USER_TIERS = 'jpsm_user_tiers';
    const TABLE_LEADS = 'jpsm_leads';
    const TABLE_PLAY_COUNTS = 'jpsm_play_counts';
    const TABLE_FOLDER_DOWNLOAD_EVENTS = 'jpsm_folder_download_events';
    const TABLE_BEHAVIOR_EVENTS = 'jpsm_behavior_events';
    const TABLE_BEHAVIOR_DAILY = 'jpsm_behavior_daily';
    const TABLE_FINANCE_SETTLEMENTS = 'jpsm_finance_settlements';
    const TABLE_FINANCE_SETTLEMENT_ITEMS = 'jpsm_finance_settlement_items';
    const TABLE_FINANCE_EXPENSES = 'jpsm_finance_expenses';

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
        $folder_events_table = self::folder_download_events_table();
        $behavior_events_table = self::behavior_events_table();
        $behavior_daily_table = self::behavior_daily_table();
        $finance_settlements_table = self::finance_settlements_table();
        $finance_settlement_items_table = self::finance_settlement_items_table();
        $finance_expenses_table = self::finance_expenses_table();

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

        $sql_folder_events = "CREATE TABLE $folder_events_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            folder_path varchar(255) NOT NULL,
            folder_name varchar(191) NOT NULL DEFAULT '',
            downloaded_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY folder_path (folder_path),
            KEY downloaded_at (downloaded_at),
            KEY folder_path_date (folder_path, downloaded_at)
        ) $charset_collate;";

        $sql_behavior_events = "CREATE TABLE $behavior_events_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_uuid char(36) NOT NULL,
            event_time datetime NOT NULL,
            event_name varchar(64) NOT NULL,
            session_id_hash varchar(64) NOT NULL DEFAULT '',
            user_id_hash varchar(64) NOT NULL DEFAULT '',
            tier smallint(6) NOT NULL DEFAULT 0,
            region varchar(32) NOT NULL DEFAULT 'unknown',
            device_class varchar(16) NOT NULL DEFAULT 'unknown',
            source_screen varchar(64) NOT NULL DEFAULT '',
            query_norm varchar(191) NOT NULL DEFAULT '',
            result_count int(11) NOT NULL DEFAULT 0,
            object_type varchar(16) NOT NULL DEFAULT '',
            object_path_norm varchar(255) NOT NULL DEFAULT '',
            status varchar(16) NOT NULL DEFAULT '',
            files_count int(11) NOT NULL DEFAULT 0,
            bytes_authorized bigint(20) unsigned NOT NULL DEFAULT 0,
            bytes_observed bigint(20) unsigned NOT NULL DEFAULT 0,
            meta_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_uuid (event_uuid),
            KEY event_name_time (event_name, event_time),
            KEY query_time (query_norm, event_time),
            KEY object_time (object_path_norm, event_time),
            KEY segment_time (tier, region, device_class, event_time),
            KEY event_time (event_time)
        ) $charset_collate;";

        $sql_behavior_daily = "CREATE TABLE $behavior_daily_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            day_date date NOT NULL,
            metric_key varchar(64) NOT NULL,
            dimension_hash char(40) NOT NULL,
            query_norm varchar(191) NOT NULL DEFAULT '',
            object_type varchar(16) NOT NULL DEFAULT '',
            object_path_norm varchar(255) NOT NULL DEFAULT '',
            tier smallint(6) NOT NULL DEFAULT 0,
            region varchar(32) NOT NULL DEFAULT 'unknown',
            device_class varchar(16) NOT NULL DEFAULT 'unknown',
            metric_count bigint(20) unsigned NOT NULL DEFAULT 0,
            metric_bytes_authorized bigint(20) unsigned NOT NULL DEFAULT 0,
            metric_bytes_observed bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY day_metric_dim (day_date, metric_key, dimension_hash),
            KEY day_metric (day_date, metric_key),
            KEY day_query (day_date, query_norm),
            KEY day_object (day_date, object_path_norm),
            KEY day_segment (day_date, tier, region, device_class)
        ) $charset_collate;";

        $sql_finance_settlements = "CREATE TABLE $finance_settlements_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            settlement_uid varchar(64) NOT NULL,
            settlement_date datetime NOT NULL,
            market varchar(16) NOT NULL DEFAULT '',
            channel varchar(32) NOT NULL DEFAULT '',
            currency varchar(8) NOT NULL DEFAULT '',
            gross_amount decimal(14,2) NOT NULL DEFAULT 0,
            fee_amount decimal(14,2) NOT NULL DEFAULT 0,
            net_amount decimal(14,2) NOT NULL DEFAULT 0,
            fx_rate decimal(14,6) NOT NULL DEFAULT 0,
            net_amount_mxn decimal(14,2) NOT NULL DEFAULT 0,
            sales_count int(11) NOT NULL DEFAULT 0,
            bank_account varchar(191) NOT NULL DEFAULT '',
            external_ref varchar(191) NOT NULL DEFAULT '',
            notes text NULL,
            status varchar(32) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY settlement_uid (settlement_uid),
            KEY settlement_date (settlement_date),
            KEY market_date (market, settlement_date),
            KEY channel_date (channel, settlement_date)
        ) $charset_collate;";

        $sql_finance_settlement_items = "CREATE TABLE $finance_settlement_items_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            item_uid varchar(64) NOT NULL,
            settlement_uid varchar(64) NOT NULL,
            sale_uid varchar(64) NOT NULL DEFAULT '',
            sale_time datetime NULL,
            sale_email varchar(190) NOT NULL DEFAULT '',
            package varchar(191) NOT NULL DEFAULT '',
            sale_region varchar(32) NOT NULL DEFAULT '',
            gross_amount decimal(14,2) NOT NULL DEFAULT 0,
            fee_amount decimal(14,2) NOT NULL DEFAULT 0,
            net_amount decimal(14,2) NOT NULL DEFAULT 0,
            currency varchar(8) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY item_uid (item_uid),
            KEY settlement_uid (settlement_uid),
            KEY sale_uid (sale_uid)
        ) $charset_collate;";

        $sql_finance_expenses = "CREATE TABLE $finance_expenses_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            expense_uid varchar(64) NOT NULL,
            expense_date datetime NOT NULL,
            category varchar(64) NOT NULL DEFAULT '',
            vendor varchar(191) NOT NULL DEFAULT '',
            description text NULL,
            amount decimal(14,2) NOT NULL DEFAULT 0,
            currency varchar(8) NOT NULL DEFAULT '',
            fx_rate decimal(14,6) NOT NULL DEFAULT 0,
            amount_mxn decimal(14,2) NOT NULL DEFAULT 0,
            account_label varchar(191) NOT NULL DEFAULT '',
            notes text NULL,
            status varchar(32) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY expense_uid (expense_uid),
            KEY expense_date (expense_date),
            KEY category_date (category, expense_date),
            KEY currency_date (currency, expense_date)
        ) $charset_collate;";

        dbDelta($sql_sales);
        dbDelta($sql_tiers);
        dbDelta($sql_leads);
        dbDelta($sql_plays);
        dbDelta($sql_folder_events);
        dbDelta($sql_behavior_events);
        dbDelta($sql_behavior_daily);
        dbDelta($sql_finance_settlements);
        dbDelta($sql_finance_settlement_items);
        dbDelta($sql_finance_expenses);

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

    public static function folder_download_events_table()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_FOLDER_DOWNLOAD_EVENTS;
    }

    public static function behavior_events_table()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_BEHAVIOR_EVENTS;
    }

    public static function behavior_daily_table()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_BEHAVIOR_DAILY;
    }

    public static function finance_settlements_table()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_FINANCE_SETTLEMENTS;
    }

    public static function finance_settlement_items_table()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_FINANCE_SETTLEMENT_ITEMS;
    }

    public static function finance_expenses_table()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_FINANCE_EXPENSES;
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

    public static function get_sales_by_uids($sale_uids)
    {
        global $wpdb;
        if (!is_array($sale_uids) || empty($sale_uids)) {
            return array();
        }

        $ids = array_values(array_unique(array_filter(array_map('sanitize_text_field', $sale_uids))));
        if (empty($ids)) {
            return array();
        }

        $map = array();
        $table = self::sales_table();
        if (self::table_exists($table)) {
            $placeholders = implode(',', array_fill(0, count($ids), '%s'));
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT sale_uid, sale_time, email, package, region, amount, currency, status
                     FROM $table
                     WHERE sale_uid IN ($placeholders)",
                    $ids
                ),
                ARRAY_A
            );

            foreach ((array) $rows as $row) {
                $entry = self::row_to_sale_entry($row);
                if (!empty($entry['id'])) {
                    $map[$entry['id']] = $entry;
                }
            }
        }

        if (count($map) < count($ids)) {
            foreach (self::get_legacy_sales_log() as $entry) {
                $normalized = self::normalize_sale_entry($entry);
                if (!empty($normalized['id']) && in_array($normalized['id'], $ids, true) && !isset($map[$normalized['id']])) {
                    $map[$normalized['id']] = $normalized;
                }
            }
        }

        $ordered = array();
        foreach ($ids as $id) {
            if (isset($map[$id])) {
                $ordered[] = $map[$id];
            }
        }

        return $ordered;
    }

    /**
     * ----------------------------
     * Finance CRUD
     * ----------------------------
     */
    public static function create_finance_settlement($settlement, $items = array())
    {
        global $wpdb;

        if (is_array($items) && !empty($items)) {
            $settlement['sales_count'] = count($items);
        }

        $entry = self::normalize_finance_settlement_entry($settlement);
        $table = self::finance_settlements_table();
        if (!self::table_exists($table)) {
            return false;
        }

        $saved = false !== $wpdb->replace(
            $table,
            array(
                'settlement_uid' => $entry['settlement_uid'],
                'settlement_date' => $entry['settlement_date'],
                'market' => $entry['market'],
                'channel' => $entry['channel'],
                'currency' => $entry['currency'],
                'gross_amount' => $entry['gross_amount'],
                'fee_amount' => $entry['fee_amount'],
                'net_amount' => $entry['net_amount'],
                'fx_rate' => $entry['fx_rate'],
                'net_amount_mxn' => $entry['net_amount_mxn'],
                'sales_count' => $entry['sales_count'],
                'bank_account' => $entry['bank_account'],
                'external_ref' => $entry['external_ref'],
                'notes' => $entry['notes'],
                'status' => $entry['status'],
                'created_at' => $entry['created_at'],
                'updated_at' => $entry['updated_at'],
            ),
            array(
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%f',
                '%f',
                '%f',
                '%f',
                '%f',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            )
        );

        if (!$saved) {
            return false;
        }

        $items_table = self::finance_settlement_items_table();
        if (self::table_exists($items_table)) {
            $wpdb->delete($items_table, array('settlement_uid' => $entry['settlement_uid']), array('%s'));

            foreach ((array) $items as $item) {
                $normalized = self::normalize_finance_settlement_item_entry($item, $entry['settlement_uid']);
                $wpdb->insert(
                    $items_table,
                    array(
                        'item_uid' => $normalized['item_uid'],
                        'settlement_uid' => $normalized['settlement_uid'],
                        'sale_uid' => $normalized['sale_uid'],
                        'sale_time' => $normalized['sale_time'],
                        'sale_email' => $normalized['sale_email'],
                        'package' => $normalized['package'],
                        'sale_region' => $normalized['sale_region'],
                        'gross_amount' => $normalized['gross_amount'],
                        'fee_amount' => $normalized['fee_amount'],
                        'net_amount' => $normalized['net_amount'],
                        'currency' => $normalized['currency'],
                        'created_at' => $normalized['created_at'],
                    ),
                    array(
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%f',
                        '%f',
                        '%f',
                        '%s',
                        '%s',
                    )
                );
            }
        }

        return $entry;
    }

    public static function get_finance_settlements($limit = 50)
    {
        global $wpdb;
        $table = self::finance_settlements_table();
        if (!self::table_exists($table)) {
            return array();
        }

        $limit = intval($limit);
        $sql = "SELECT settlement_uid, settlement_date, market, channel, currency, gross_amount, fee_amount, net_amount, fx_rate, net_amount_mxn, sales_count, bank_account, external_ref, notes, status, created_at, updated_at
                FROM $table
                ORDER BY settlement_date DESC, id DESC";

        if ($limit > 0) {
            $limit = min(500, $limit);
            $sql = $wpdb->prepare($sql . ' LIMIT %d', $limit);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return array_map(array(__CLASS__, 'row_to_finance_settlement_entry'), (array) $rows);
    }

    public static function get_finance_settlement_items($settlement_uid = '')
    {
        global $wpdb;
        $table = self::finance_settlement_items_table();
        if (!self::table_exists($table)) {
            return array();
        }

        $where = '';
        $params = array();
        $settlement_uid = sanitize_text_field((string) $settlement_uid);
        if ($settlement_uid !== '') {
            $where = 'WHERE settlement_uid = %s';
            $params[] = $settlement_uid;
        }

        $sql = "SELECT item_uid, settlement_uid, sale_uid, sale_time, sale_email, package, sale_region, gross_amount, fee_amount, net_amount, currency, created_at
                FROM $table
                $where
                ORDER BY created_at DESC, id DESC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return array_map(array(__CLASS__, 'row_to_finance_settlement_item_entry'), (array) $rows);
    }

    public static function get_finance_linked_sale_uids()
    {
        global $wpdb;
        $table = self::finance_settlement_items_table();
        if (!self::table_exists($table)) {
            return array();
        }

        $rows = $wpdb->get_col("SELECT DISTINCT sale_uid FROM $table WHERE sale_uid <> ''");
        $ids = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $rows))));
        sort($ids);
        return $ids;
    }

    public static function delete_finance_settlement($settlement_uid)
    {
        global $wpdb;
        $settlement_uid = sanitize_text_field((string) $settlement_uid);
        if ($settlement_uid === '') {
            return false;
        }

        $deleted = false;
        $table = self::finance_settlements_table();
        if (self::table_exists($table)) {
            $deleted = false !== $wpdb->delete($table, array('settlement_uid' => $settlement_uid), array('%s'));
        }

        $items_table = self::finance_settlement_items_table();
        if (self::table_exists($items_table)) {
            $wpdb->delete($items_table, array('settlement_uid' => $settlement_uid), array('%s'));
        }

        return $deleted;
    }

    public static function create_finance_expense($expense)
    {
        global $wpdb;
        $entry = self::normalize_finance_expense_entry($expense);
        $table = self::finance_expenses_table();
        if (!self::table_exists($table)) {
            return false;
        }

        $saved = false !== $wpdb->replace(
            $table,
            array(
                'expense_uid' => $entry['expense_uid'],
                'expense_date' => $entry['expense_date'],
                'category' => $entry['category'],
                'vendor' => $entry['vendor'],
                'description' => $entry['description'],
                'amount' => $entry['amount'],
                'currency' => $entry['currency'],
                'fx_rate' => $entry['fx_rate'],
                'amount_mxn' => $entry['amount_mxn'],
                'account_label' => $entry['account_label'],
                'notes' => $entry['notes'],
                'status' => $entry['status'],
                'created_at' => $entry['created_at'],
                'updated_at' => $entry['updated_at'],
            ),
            array(
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%f',
                '%s',
                '%f',
                '%f',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            )
        );

        return $saved ? $entry : false;
    }

    public static function get_finance_expenses($limit = 50)
    {
        global $wpdb;
        $table = self::finance_expenses_table();
        if (!self::table_exists($table)) {
            return array();
        }

        $limit = intval($limit);
        $sql = "SELECT expense_uid, expense_date, category, vendor, description, amount, currency, fx_rate, amount_mxn, account_label, notes, status, created_at, updated_at
                FROM $table
                ORDER BY expense_date DESC, id DESC";

        if ($limit > 0) {
            $limit = min(500, $limit);
            $sql = $wpdb->prepare($sql . ' LIMIT %d', $limit);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return array_map(array(__CLASS__, 'row_to_finance_expense_entry'), (array) $rows);
    }

    public static function delete_finance_expense($expense_uid)
    {
        global $wpdb;
        $expense_uid = sanitize_text_field((string) $expense_uid);
        if ($expense_uid === '') {
            return false;
        }

        $table = self::finance_expenses_table();
        if (!self::table_exists($table)) {
            return false;
        }

        return false !== $wpdb->delete($table, array('expense_uid' => $expense_uid), array('%s'));
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
     * Folder Download Events (Analytics)
     * ----------------------------
     */
    public static function record_folder_download_event($folder_path)
    {
        global $wpdb;
        $folder_path = self::normalize_folder_event_path($folder_path);
        if ($folder_path === '') {
            return false;
        }

        $table = self::folder_download_events_table();
        if (!self::table_exists($table)) {
            return false;
        }

        $folder_name = self::folder_event_name_from_path($folder_path);

        return false !== $wpdb->insert(
            $table,
            array(
                'folder_path' => $folder_path,
                'folder_name' => $folder_name,
                'downloaded_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s')
        );
    }

    public static function get_top_folder_downloads($limit = 30)
    {
        global $wpdb;
        $table = self::folder_download_events_table();
        if (!self::table_exists($table)) {
            return array();
        }

        $limit = intval($limit);
        if ($limit <= 0) {
            $limit = 30;
        }
        $limit = min(200, $limit);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT folder_path, MAX(folder_name) AS folder_name, COUNT(*) AS downloads, MAX(downloaded_at) AS last_download_at
                 FROM $table
                 GROUP BY folder_path
                 ORDER BY downloads DESC, last_download_at DESC, folder_path ASC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return array();
        }

        $normalized = array();
        foreach ($rows as $row) {
            $path = self::normalize_folder_event_path(isset($row['folder_path']) ? $row['folder_path'] : '');
            if ($path === '') {
                continue;
            }

            $name = isset($row['folder_name']) ? sanitize_text_field((string) $row['folder_name']) : '';
            if ($name === '') {
                $name = self::folder_event_name_from_path($path);
            }

            $normalized[] = array(
                'folder_path' => $path,
                'folder_name' => $name,
                'downloads' => max(0, intval($row['downloads'] ?? 0)),
                'last_download_at' => isset($row['last_download_at']) ? (string) $row['last_download_at'] : '',
            );
        }

        return $normalized;
    }

    public static function get_top_folder_downloads_by_range($from_date, $to_date, $limit = 30)
    {
        global $wpdb;
        $table = self::folder_download_events_table();
        if (!self::table_exists($table)) {
            return array();
        }

        $from = self::normalize_behavior_date($from_date);
        $to = self::normalize_behavior_date($to_date);
        if ($from === '' || $to === '' || $from > $to) {
            return array();
        }

        $to_plus_one = gmdate('Y-m-d', strtotime($to . ' +1 day'));
        if ($to_plus_one === false) {
            $to_plus_one = $to;
        }

        $limit = intval($limit);
        if ($limit <= 0) {
            $limit = 30;
        }
        $limit = min(200, $limit);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT folder_path, MAX(folder_name) AS folder_name, COUNT(*) AS downloads, MAX(downloaded_at) AS last_download_at
                 FROM $table
                 WHERE downloaded_at >= %s
                   AND downloaded_at < %s
                 GROUP BY folder_path
                 ORDER BY downloads DESC, last_download_at DESC, folder_path ASC
                 LIMIT %d",
                $from . ' 00:00:00',
                $to_plus_one . ' 00:00:00',
                $limit
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return array();
        }

        $normalized = array();
        foreach ($rows as $row) {
            $path = self::normalize_folder_event_path(isset($row['folder_path']) ? $row['folder_path'] : '');
            if ($path === '') {
                continue;
            }

            $name = isset($row['folder_name']) ? sanitize_text_field((string) $row['folder_name']) : '';
            if ($name === '') {
                $name = self::folder_event_name_from_path($path);
            }

            $normalized[] = array(
                'folder_path' => $path,
                'folder_name' => $name,
                'downloads' => max(0, intval($row['downloads'] ?? 0)),
                'last_download_at' => isset($row['last_download_at']) ? (string) $row['last_download_at'] : '',
            );
        }

        return $normalized;
    }

    public static function get_folder_download_totals()
    {
        global $wpdb;
        $table = self::folder_download_events_table();
        if (!self::table_exists($table)) {
            return array(
                'total_downloads' => 0,
                'unique_folders' => 0,
            );
        }

        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS total_downloads, COUNT(DISTINCT folder_path) AS unique_folders
             FROM $table",
            ARRAY_A
        );

        if (!is_array($row)) {
            return array(
                'total_downloads' => 0,
                'unique_folders' => 0,
            );
        }

        return array(
            'total_downloads' => max(0, intval($row['total_downloads'] ?? 0)),
            'unique_folders' => max(0, intval($row['unique_folders'] ?? 0)),
        );
    }

    public static function get_folder_download_totals_by_range($from_date, $to_date)
    {
        global $wpdb;
        $table = self::folder_download_events_table();
        if (!self::table_exists($table)) {
            return array(
                'total_downloads' => 0,
                'unique_folders' => 0,
            );
        }

        $from = self::normalize_behavior_date($from_date);
        $to = self::normalize_behavior_date($to_date);
        if ($from === '' || $to === '' || $from > $to) {
            return array(
                'total_downloads' => 0,
                'unique_folders' => 0,
            );
        }

        $to_plus_one = gmdate('Y-m-d', strtotime($to . ' +1 day'));
        if ($to_plus_one === false) {
            $to_plus_one = $to;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS total_downloads, COUNT(DISTINCT folder_path) AS unique_folders
                 FROM $table
                 WHERE downloaded_at >= %s
                   AND downloaded_at < %s",
                $from . ' 00:00:00',
                $to_plus_one . ' 00:00:00'
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return array(
                'total_downloads' => 0,
                'unique_folders' => 0,
            );
        }

        return array(
            'total_downloads' => max(0, intval($row['total_downloads'] ?? 0)),
            'unique_folders' => max(0, intval($row['unique_folders'] ?? 0)),
        );
    }

    /**
     * ----------------------------
     * Behavior Events (Analytics)
     * ----------------------------
     */
    public static function insert_behavior_event($event)
    {
        global $wpdb;
        $row = self::normalize_behavior_event_row($event);
        if ($row['event_name'] === '') {
            return array(
                'inserted' => false,
                'duplicate' => false,
                'event_uuid' => $row['event_uuid'],
            );
        }

        $table = self::behavior_events_table();
        if (!self::table_exists($table)) {
            return array(
                'inserted' => false,
                'duplicate' => false,
                'event_uuid' => $row['event_uuid'],
            );
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM $table WHERE event_uuid = %s LIMIT 1", $row['event_uuid'])
        );
        if ($existing) {
            return array(
                'inserted' => false,
                'duplicate' => true,
                'event_uuid' => $row['event_uuid'],
            );
        }

        $inserted = false !== $wpdb->insert(
            $table,
            array(
                'event_uuid' => $row['event_uuid'],
                'event_time' => $row['event_time'],
                'event_name' => $row['event_name'],
                'session_id_hash' => $row['session_id_hash'],
                'user_id_hash' => $row['user_id_hash'],
                'tier' => $row['tier'],
                'region' => $row['region'],
                'device_class' => $row['device_class'],
                'source_screen' => $row['source_screen'],
                'query_norm' => $row['query_norm'],
                'result_count' => $row['result_count'],
                'object_type' => $row['object_type'],
                'object_path_norm' => $row['object_path_norm'],
                'status' => $row['status'],
                'files_count' => $row['files_count'],
                'bytes_authorized' => $row['bytes_authorized'],
                'bytes_observed' => $row['bytes_observed'],
                'meta_json' => $row['meta_json'],
                'created_at' => current_time('mysql'),
            ),
            array(
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
            )
        );

        return array(
            'inserted' => $inserted,
            'duplicate' => false,
            'event_uuid' => $row['event_uuid'],
        );
    }

    public static function behavior_daily_has_data($from_date, $to_date)
    {
        global $wpdb;
        $table = self::behavior_daily_table();
        if (!self::table_exists($table)) {
            return false;
        }

        $from = self::normalize_behavior_date($from_date);
        $to = self::normalize_behavior_date($to_date);
        if ($from === '' || $to === '' || $from > $to) {
            return false;
        }

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE day_date >= %s AND day_date <= %s",
                $from,
                $to
            )
        );
        return intval($count) > 0;
    }

    public static function rebuild_behavior_daily($from_date, $to_date)
    {
        global $wpdb;
        $events_table = self::behavior_events_table();
        $daily_table = self::behavior_daily_table();

        if (!self::table_exists($events_table) || !self::table_exists($daily_table)) {
            return 0;
        }

        $from = self::normalize_behavior_date($from_date);
        $to = self::normalize_behavior_date($to_date);
        if ($from === '' || $to === '' || $from > $to) {
            return 0;
        }

        $to_plus_one = gmdate('Y-m-d', strtotime($to . ' +1 day'));
        if ($to_plus_one === false) {
            $to_plus_one = $to;
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $daily_table WHERE day_date >= %s AND day_date <= %s",
                $from,
                $to
            )
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(event_time) AS day_date,
                        event_name AS metric_key,
                        query_norm,
                        object_type,
                        object_path_norm,
                        tier,
                        region,
                        device_class,
                        COUNT(*) AS metric_count,
                        COALESCE(SUM(bytes_authorized), 0) AS metric_bytes_authorized,
                        COALESCE(SUM(bytes_observed), 0) AS metric_bytes_observed
                 FROM $events_table
                 WHERE event_time >= %s
                   AND event_time < %s
                 GROUP BY DATE(event_time), event_name, query_norm, object_type, object_path_norm, tier, region, device_class",
                $from . ' 00:00:00',
                $to_plus_one . ' 00:00:00'
            ),
            ARRAY_A
        );

        $inserted = 0;
        foreach ((array) $rows as $row) {
            $day_date = self::normalize_behavior_date(isset($row['day_date']) ? $row['day_date'] : '');
            if ($day_date === '') {
                continue;
            }

            $metric_key = sanitize_key((string) ($row['metric_key'] ?? ''));
            if ($metric_key === '') {
                continue;
            }

            $query_norm = self::normalize_behavior_query($row['query_norm'] ?? '');
            $object_type = self::normalize_behavior_object_type($row['object_type'] ?? '');
            $object_path_norm = self::normalize_behavior_path($row['object_path_norm'] ?? '');
            $tier = intval($row['tier'] ?? 0);
            $region = self::normalize_behavior_region($row['region'] ?? 'unknown');
            $device_class = self::normalize_behavior_device_class($row['device_class'] ?? 'unknown');
            $metric_count = max(0, intval($row['metric_count'] ?? 0));
            $metric_bytes_authorized = self::normalize_behavior_bytes($row['metric_bytes_authorized'] ?? 0);
            $metric_bytes_observed = self::normalize_behavior_bytes($row['metric_bytes_observed'] ?? 0);

            if ($metric_count <= 0) {
                continue;
            }

            $dimension_hash = self::behavior_dimension_hash(array(
                'query_norm' => $query_norm,
                'object_type' => $object_type,
                'object_path_norm' => $object_path_norm,
                'tier' => $tier,
                'region' => $region,
                'device_class' => $device_class,
            ));

            $ok = $wpdb->replace(
                $daily_table,
                array(
                    'day_date' => $day_date,
                    'metric_key' => $metric_key,
                    'dimension_hash' => $dimension_hash,
                    'query_norm' => $query_norm,
                    'object_type' => $object_type,
                    'object_path_norm' => $object_path_norm,
                    'tier' => $tier,
                    'region' => $region,
                    'device_class' => $device_class,
                    'metric_count' => $metric_count,
                    'metric_bytes_authorized' => $metric_bytes_authorized,
                    'metric_bytes_observed' => $metric_bytes_observed,
                    'updated_at' => current_time('mysql'),
                ),
                array(
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%s',
                    '%s',
                    '%d',
                    '%d',
                    '%d',
                    '%s',
                )
            );

            if ($ok !== false) {
                $inserted++;
            }
        }

        return $inserted;
    }

    public static function get_behavior_metric_sum($metric_keys, $from_date, $to_date, $filters = array())
    {
        global $wpdb;
        $table = self::behavior_daily_table();
        if (!self::table_exists($table)) {
            return 0;
        }

        list($where_sql, $params) = self::build_behavior_daily_where($from_date, $to_date, $filters, $metric_keys);
        if ($where_sql === '') {
            return 0;
        }

        $sql = "SELECT COALESCE(SUM(metric_count), 0) AS total_count FROM $table WHERE $where_sql";
        $prepared = $wpdb->prepare($sql, $params);
        $value = $wpdb->get_var($prepared);
        return max(0, intval($value));
    }

    public static function get_behavior_metric_bytes_sum($metric_keys, $from_date, $to_date, $filters = array(), $mode = 'observed')
    {
        global $wpdb;
        $table = self::behavior_daily_table();
        if (!self::table_exists($table)) {
            return 0;
        }

        list($where_sql, $params) = self::build_behavior_daily_where($from_date, $to_date, $filters, $metric_keys);
        if ($where_sql === '') {
            return 0;
        }

        $column = ($mode === 'authorized') ? 'metric_bytes_authorized' : 'metric_bytes_observed';
        $sql = "SELECT COALESCE(SUM($column), 0) AS total_bytes FROM $table WHERE $where_sql";
        $prepared = $wpdb->prepare($sql, $params);
        $value = $wpdb->get_var($prepared);
        return self::normalize_behavior_bytes($value);
    }

    public static function get_behavior_transfer_daily_series($from_date, $to_date, $filters = array(), $metric_keys = array())
    {
        global $wpdb;
        $table = self::behavior_daily_table();
        if (!self::table_exists($table)) {
            return array();
        }

        if (empty($metric_keys)) {
            $metric_keys = self::default_transfer_metric_keys();
        }

        list($where_sql, $params) = self::build_behavior_daily_where($from_date, $to_date, $filters, $metric_keys);
        if ($where_sql === '') {
            return array();
        }

        $sql = "SELECT day_date,
                       COALESCE(SUM(metric_count), 0) AS event_count,
                       COALESCE(SUM(metric_bytes_authorized), 0) AS bytes_authorized,
                       COALESCE(SUM(metric_bytes_observed), 0) AS bytes_observed
                FROM $table
                WHERE $where_sql
                GROUP BY day_date
                ORDER BY day_date ASC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $normalized = array();
        foreach ((array) $rows as $row) {
            $day_date = self::normalize_behavior_date($row['day_date'] ?? '');
            if ($day_date === '') {
                continue;
            }

            $normalized[] = array(
                'day_date' => $day_date,
                'event_count' => max(0, intval($row['event_count'] ?? 0)),
                'bytes_authorized' => self::normalize_behavior_bytes($row['bytes_authorized'] ?? 0),
                'bytes_observed' => self::normalize_behavior_bytes($row['bytes_observed'] ?? 0),
            );
        }

        return $normalized;
    }

    public static function get_behavior_transfer_monthly_series($from_month, $to_month, $filters = array(), $metric_keys = array())
    {
        global $wpdb;
        $table = self::behavior_daily_table();
        if (!self::table_exists($table)) {
            return array();
        }

        if (empty($metric_keys)) {
            $metric_keys = self::default_transfer_metric_keys();
        }

        $from_month = sanitize_text_field((string) $from_month);
        $to_month = sanitize_text_field((string) $to_month);
        if (!preg_match('/^\d{4}-\d{2}$/', $from_month) || !preg_match('/^\d{4}-\d{2}$/', $to_month)) {
            return array();
        }

        $from_date = $from_month . '-01';
        $to_end = gmdate('Y-m-d', strtotime($to_month . '-01 +1 month -1 day'));
        if ($to_end === false) {
            return array();
        }

        list($where_sql, $params) = self::build_behavior_daily_where($from_date, $to_end, $filters, $metric_keys);
        if ($where_sql === '') {
            return array();
        }

        $sql = "SELECT LEFT(day_date, 7) AS month_key,
                       COALESCE(SUM(metric_count), 0) AS event_count,
                       COALESCE(SUM(metric_bytes_authorized), 0) AS bytes_authorized,
                       COALESCE(SUM(metric_bytes_observed), 0) AS bytes_observed
                FROM $table
                WHERE $where_sql
                GROUP BY LEFT(day_date, 7)
                ORDER BY month_key ASC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $normalized = array();
        foreach ((array) $rows as $row) {
            $month_key = sanitize_text_field((string) ($row['month_key'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}$/', $month_key)) {
                continue;
            }

            $normalized[] = array(
                'month_key' => $month_key,
                'event_count' => max(0, intval($row['event_count'] ?? 0)),
                'bytes_authorized' => self::normalize_behavior_bytes($row['bytes_authorized'] ?? 0),
                'bytes_observed' => self::normalize_behavior_bytes($row['bytes_observed'] ?? 0),
            );
        }

        return $normalized;
    }

    public static function get_behavior_transfer_top_folders($from_date, $to_date, $filters = array(), $limit = 30)
    {
        global $wpdb;
        $table = self::behavior_daily_table();
        if (!self::table_exists($table)) {
            return array();
        }

        $limit = max(1, min(200, intval($limit)));
        $all_metric_keys = array('download_folder_granted', 'download_folder_granted_backfill', 'download_folder_completed');
        $download_metric_keys = array('download_folder_granted', 'download_folder_granted_backfill');

        list($where_sql, $params) = self::build_behavior_daily_where($from_date, $to_date, $filters, $all_metric_keys);
        if ($where_sql === '') {
            return array();
        }

        $download_placeholders = implode(',', array_fill(0, count($download_metric_keys), '%s'));
        foreach ($download_metric_keys as $metric_key) {
            $params[] = $metric_key;
        }
        $params[] = $limit;

        $sql = "SELECT object_path_norm,
                       COALESCE(SUM(CASE WHEN metric_key IN ($download_placeholders) THEN metric_count ELSE 0 END), 0) AS downloads,
                       COALESCE(SUM(metric_bytes_authorized), 0) AS bytes_authorized,
                       COALESCE(SUM(metric_bytes_observed), 0) AS bytes_observed
                FROM $table
                WHERE $where_sql
                  AND object_type = 'folder'
                  AND object_path_norm <> ''
                GROUP BY object_path_norm
                HAVING downloads > 0 OR bytes_authorized > 0 OR bytes_observed > 0
                ORDER BY downloads DESC, bytes_authorized DESC, object_path_norm ASC
                LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $normalized = array();
        foreach ((array) $rows as $row) {
            $path = self::normalize_behavior_path($row['object_path_norm'] ?? '');
            if ($path === '') {
                continue;
            }

            $normalized[] = array(
                'object_path_norm' => $path,
                'downloads' => max(0, intval($row['downloads'] ?? 0)),
                'bytes_authorized' => self::normalize_behavior_bytes($row['bytes_authorized'] ?? 0),
                'bytes_observed' => self::normalize_behavior_bytes($row['bytes_observed'] ?? 0),
            );
        }

        return $normalized;
    }

    public static function get_behavior_transfer_unique_folder_count($from_date, $to_date, $filters = array(), $metric_keys = array())
    {
        global $wpdb;
        $table = self::behavior_daily_table();
        if (!self::table_exists($table)) {
            return 0;
        }

        if (empty($metric_keys)) {
            $metric_keys = array('download_folder_granted', 'download_folder_granted_backfill');
        }

        list($where_sql, $params) = self::build_behavior_daily_where($from_date, $to_date, $filters, $metric_keys);
        if ($where_sql === '') {
            return 0;
        }

        $sql = "SELECT COUNT(DISTINCT object_path_norm) AS total_unique
                FROM $table
                WHERE $where_sql
                  AND object_type = 'folder'
                  AND object_path_norm <> ''";

        $value = $wpdb->get_var($wpdb->prepare($sql, $params));
        return max(0, intval($value));
    }

    public static function get_behavior_transfer_coverage_counts($from_date, $to_date, $filters = array(), $metric_keys = array())
    {
        global $wpdb;
        $table = self::behavior_events_table();
        if (!self::table_exists($table)) {
            return array(
                'total_events' => 0,
                'observed_events' => 0,
            );
        }

        if (empty($metric_keys)) {
            $metric_keys = self::default_transfer_metric_keys();
        }

        list($where_sql, $params) = self::build_behavior_events_where($from_date, $to_date, $filters, $metric_keys);
        if ($where_sql === '') {
            return array(
                'total_events' => 0,
                'observed_events' => 0,
            );
        }

        $sql = "SELECT COUNT(*) AS total_events,
                       COALESCE(SUM(CASE WHEN bytes_observed > 0 THEN 1 ELSE 0 END), 0) AS observed_events
                FROM $table
                WHERE $where_sql";

        $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);
        if (!is_array($row)) {
            return array(
                'total_events' => 0,
                'observed_events' => 0,
            );
        }

        return array(
            'total_events' => max(0, intval($row['total_events'] ?? 0)),
            'observed_events' => max(0, intval($row['observed_events'] ?? 0)),
        );
    }

    public static function count_registered_users_dedup($month = '')
    {
        global $wpdb;

        $cutoff_mysql = '';
        $month = sanitize_text_field((string) $month);
        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month_end = gmdate('Y-m-d', strtotime($month . '-01 +1 month -1 day'));
            if ($month_end !== false) {
                $cutoff_mysql = $month_end . ' 23:59:59';
            }
        }

        $emails = array();

        $tiers_table = self::tiers_table();
        if (self::table_exists($tiers_table)) {
            if ($cutoff_mysql !== '') {
                $rows = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT email FROM $tiers_table WHERE updated_at <= %s",
                        $cutoff_mysql
                    )
                );
            } else {
                $rows = $wpdb->get_col("SELECT email FROM $tiers_table");
            }

            foreach ((array) $rows as $email) {
                $normalized = self::normalize_email($email);
                if ($normalized !== '') {
                    $emails[$normalized] = true;
                }
            }
        } else {
            foreach ((array) self::get_all_user_tiers() as $email => $tier) {
                $normalized = self::normalize_email($email);
                if ($normalized !== '') {
                    $emails[$normalized] = true;
                }
            }
        }

        $leads_table = self::leads_table();
        if (self::table_exists($leads_table)) {
            if ($cutoff_mysql !== '') {
                $rows = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT email FROM $leads_table WHERE registered_at <= %s",
                        $cutoff_mysql
                    )
                );
            } else {
                $rows = $wpdb->get_col("SELECT email FROM $leads_table");
            }

            foreach ((array) $rows as $email) {
                $normalized = self::normalize_email($email);
                if ($normalized !== '') {
                    $emails[$normalized] = true;
                }
            }
        } else {
            foreach ((array) self::get_leads() as $email => $lead) {
                $normalized = self::normalize_email($email);
                if ($normalized !== '') {
                    $emails[$normalized] = true;
                }
            }
        }

        return count($emails);
    }

    public static function backfill_transfer_authorized_from_folder_events($up_to_date = '')
    {
        global $wpdb;

        $state = get_option(self::OPT_TRANSFER_BACKFILL_STATE, array());
        if (is_array($state) && !empty($state['done']) && !empty($state['version']) && $state['version'] === self::SCHEMA_VERSION) {
            return 0;
        }

        $folder_events_table = self::folder_download_events_table();
        $daily_table = self::behavior_daily_table();
        if (!self::table_exists($folder_events_table) || !self::table_exists($daily_table)) {
            return 0;
        }

        $up_to = self::normalize_behavior_date($up_to_date);
        if ($up_to === '') {
            $up_to = current_time('Y-m-d');
        }

        $folder_sizes = self::build_folder_authorized_sizes_from_index();
        if (empty($folder_sizes)) {
            return 0;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(downloaded_at) AS day_date, folder_path, COUNT(*) AS downloads
                 FROM $folder_events_table
                 WHERE downloaded_at <= %s
                 GROUP BY DATE(downloaded_at), folder_path",
                $up_to . ' 23:59:59'
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            update_option(self::OPT_TRANSFER_BACKFILL_STATE, array(
                'done' => true,
                'version' => self::SCHEMA_VERSION,
                'at' => current_time('mysql'),
                'rows' => 0,
            ), false);
            return 0;
        }

        $inserted = 0;
        foreach ($rows as $row) {
            $day_date = self::normalize_behavior_date($row['day_date'] ?? '');
            $path = self::normalize_folder_event_path($row['folder_path'] ?? '');
            $downloads = max(0, intval($row['downloads'] ?? 0));
            if ($day_date === '' || $path === '' || $downloads <= 0) {
                continue;
            }

            $folder_size = self::normalize_behavior_bytes($folder_sizes[$path] ?? 0);
            if ($folder_size <= 0) {
                continue;
            }

            $metric_bytes_authorized = $folder_size * $downloads;
            $dimension_hash = self::behavior_dimension_hash(array(
                'query_norm' => '',
                'object_type' => 'folder',
                'object_path_norm' => $path,
                'tier' => 0,
                'region' => 'unknown',
                'device_class' => 'unknown',
            ));

            $ok = $wpdb->replace(
                $daily_table,
                array(
                    'day_date' => $day_date,
                    'metric_key' => 'download_folder_granted_backfill',
                    'dimension_hash' => $dimension_hash,
                    'query_norm' => '',
                    'object_type' => 'folder',
                    'object_path_norm' => $path,
                    'tier' => 0,
                    'region' => 'unknown',
                    'device_class' => 'unknown',
                    'metric_count' => $downloads,
                    'metric_bytes_authorized' => $metric_bytes_authorized,
                    'metric_bytes_observed' => 0,
                    'updated_at' => current_time('mysql'),
                ),
                array(
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%s',
                    '%s',
                    '%d',
                    '%d',
                    '%d',
                    '%s',
                )
            );

            if ($ok !== false) {
                $inserted++;
            }
        }

        update_option(self::OPT_TRANSFER_BACKFILL_STATE, array(
            'done' => true,
            'version' => self::SCHEMA_VERSION,
            'at' => current_time('mysql'),
            'rows' => $inserted,
        ), false);

        return $inserted;
    }

    public static function get_behavior_top_keywords($from_date, $to_date, $filters = array(), $limit = 20, $zero_only = false)
    {
        global $wpdb;
        $table = self::behavior_daily_table();
        if (!self::table_exists($table)) {
            return array();
        }

        $limit = max(1, min(200, intval($limit)));
        $metric_keys = $zero_only ? array('search_zero_results') : array('search_executed');

        list($where_sql, $params) = self::build_behavior_daily_where($from_date, $to_date, $filters, $metric_keys);
        if ($where_sql === '') {
            return array();
        }

        $where_sql .= " AND query_norm <> ''";
        $params[] = $limit;
        $sql = "SELECT query_norm, SUM(metric_count) AS total_count
                FROM $table
                WHERE $where_sql
                GROUP BY query_norm
                ORDER BY total_count DESC, query_norm ASC
                LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $normalized = array();
        foreach ((array) $rows as $row) {
            $query_norm = self::normalize_behavior_query($row['query_norm'] ?? '');
            if ($query_norm === '') {
                continue;
            }

            $normalized[] = array(
                'query_norm' => $query_norm,
                'total_count' => max(0, intval($row['total_count'] ?? 0)),
            );
        }

        return $normalized;
    }

    public static function get_behavior_top_downloads($from_date, $to_date, $filters = array(), $limit = 20)
    {
        global $wpdb;
        $table = self::behavior_daily_table();
        if (!self::table_exists($table)) {
            return array();
        }

        $limit = max(1, min(200, intval($limit)));
        $metric_keys = array('download_file_granted', 'download_folder_granted');
        list($where_sql, $params) = self::build_behavior_daily_where($from_date, $to_date, $filters, $metric_keys);
        if ($where_sql === '') {
            return array();
        }

        $where_sql .= " AND object_path_norm <> ''";
        $params[] = $limit;
        $sql = "SELECT object_type, object_path_norm, SUM(metric_count) AS total_count
                FROM $table
                WHERE $where_sql
                GROUP BY object_type, object_path_norm
                ORDER BY total_count DESC, object_path_norm ASC
                LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $normalized = array();
        foreach ((array) $rows as $row) {
            $path = self::normalize_behavior_path($row['object_path_norm'] ?? '');
            if ($path === '') {
                continue;
            }

            $normalized[] = array(
                'object_type' => self::normalize_behavior_object_type($row['object_type'] ?? ''),
                'object_path_norm' => $path,
                'total_count' => max(0, intval($row['total_count'] ?? 0)),
            );
        }

        return $normalized;
    }

    public static function get_behavior_segment_preferences($from_date, $to_date, $filters = array(), $metric_keys = array(), $limit = 200)
    {
        global $wpdb;
        $table = self::behavior_daily_table();
        if (!self::table_exists($table)) {
            return array();
        }

        $limit = max(1, min(500, intval($limit)));
        list($where_sql, $params) = self::build_behavior_daily_where($from_date, $to_date, $filters, $metric_keys);
        if ($where_sql === '') {
            return array();
        }

        $params[] = $limit;
        $sql = "SELECT tier, region, device_class, SUM(metric_count) AS total_count
                FROM $table
                WHERE $where_sql
                GROUP BY tier, region, device_class
                ORDER BY total_count DESC, tier ASC, region ASC, device_class ASC
                LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $normalized = array();
        foreach ((array) $rows as $row) {
            $normalized[] = array(
                'tier' => intval($row['tier'] ?? 0),
                'region' => self::normalize_behavior_region($row['region'] ?? ''),
                'device_class' => self::normalize_behavior_device_class($row['device_class'] ?? ''),
                'total_count' => max(0, intval($row['total_count'] ?? 0)),
            );
        }

        return $normalized;
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

    private static function normalize_folder_event_path($folder_path)
    {
        $path = sanitize_text_field((string) $folder_path);
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }

        $path = preg_replace('#/+#', '/', $path);
        $path = ltrim((string) $path, '/');
        $path = rtrim((string) $path, '/');
        if ($path === '') {
            return '';
        }

        return $path . '/';
    }

    private static function folder_event_name_from_path($folder_path)
    {
        $path = trim((string) $folder_path, '/');
        if ($path === '') {
            return '';
        }

        $parts = explode('/', $path);
        $name = end($parts);
        return sanitize_text_field((string) $name);
    }

    private static function normalize_behavior_event_row($event)
    {
        if (!is_array($event)) {
            $event = array();
        }

        $uuid = sanitize_text_field((string) ($event['event_uuid'] ?? ''));
        if ($uuid === '') {
            $uuid = self::generate_behavior_event_uuid();
        }
        if (strlen($uuid) > 36) {
            $uuid = substr($uuid, 0, 36);
        }

        $event_time = sanitize_text_field((string) ($event['event_time'] ?? ''));
        if ($event_time === '') {
            $event_time = current_time('mysql');
        }

        $event_name = sanitize_key((string) ($event['event_name'] ?? ''));
        $session_hash = self::normalize_behavior_hash($event['session_id_hash'] ?? '');
        $user_hash = self::normalize_behavior_hash($event['user_id_hash'] ?? '');
        $tier = intval($event['tier'] ?? 0);
        $region = self::normalize_behavior_region($event['region'] ?? 'unknown');
        $device = self::normalize_behavior_device_class($event['device_class'] ?? 'unknown');
        $source_screen = sanitize_key((string) ($event['source_screen'] ?? ''));
        if ($source_screen === '') {
            $source_screen = 'mediavault';
        }

        $query_norm = self::normalize_behavior_query($event['query_norm'] ?? '');
        $result_count = max(0, intval($event['result_count'] ?? 0));
        $object_type = self::normalize_behavior_object_type($event['object_type'] ?? '');
        $object_path = self::normalize_behavior_path($event['object_path_norm'] ?? '');
        $status = sanitize_key((string) ($event['status'] ?? ''));
        $files_count = max(0, intval($event['files_count'] ?? 0));
        $bytes_authorized = self::normalize_behavior_bytes($event['bytes_authorized'] ?? 0);
        $bytes_observed = self::normalize_behavior_bytes($event['bytes_observed'] ?? 0);
        $meta_json = isset($event['meta_json']) ? (string) $event['meta_json'] : '';

        return array(
            'event_uuid' => $uuid,
            'event_time' => $event_time,
            'event_name' => $event_name,
            'session_id_hash' => $session_hash,
            'user_id_hash' => $user_hash,
            'tier' => $tier,
            'region' => $region,
            'device_class' => $device,
            'source_screen' => $source_screen,
            'query_norm' => $query_norm,
            'result_count' => $result_count,
            'object_type' => $object_type,
            'object_path_norm' => $object_path,
            'status' => $status,
            'files_count' => $files_count,
            'bytes_authorized' => $bytes_authorized,
            'bytes_observed' => $bytes_observed,
            'meta_json' => $meta_json,
        );
    }

    private static function generate_behavior_event_uuid()
    {
        if (function_exists('wp_generate_uuid4')) {
            return (string) wp_generate_uuid4();
        }

        $hash = md5(uniqid('jpsm_behavior_', true));
        return substr($hash, 0, 8) . '-' .
            substr($hash, 8, 4) . '-' .
            substr($hash, 12, 4) . '-' .
            substr($hash, 16, 4) . '-' .
            substr($hash, 20, 12);
    }

    private static function normalize_behavior_date($value)
    {
        $raw = sanitize_text_field((string) $value);
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $raw)) {
            return $raw;
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return '';
        }

        return gmdate('Y-m-d', $ts);
    }

    private static function normalize_behavior_bytes($value)
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return 0;
            }
        }

        if (!is_numeric($value)) {
            return 0;
        }

        $bytes = (int) floor((float) $value);
        return max(0, $bytes);
    }

    private static function normalize_behavior_hash($value)
    {
        $hash = strtolower(trim((string) $value));
        if ($hash === '') {
            return '';
        }
        if (!preg_match('/^[a-f0-9]{32,64}$/', $hash)) {
            return '';
        }
        if (strlen($hash) > 64) {
            return substr($hash, 0, 64);
        }
        return $hash;
    }

    private static function normalize_behavior_query($value)
    {
        $query = sanitize_text_field((string) $value);
        if ($query === '') {
            return '';
        }

        $query = mb_strtolower($query);
        if (function_exists('remove_accents')) {
            $query = remove_accents($query);
        }

        // Redact probable email addresses and phone numbers from search input.
        $query = preg_replace('/[a-z0-9._%+\\-]+@[a-z0-9.\\-]+\\.[a-z]{2,}/i', '[email]', $query);
        $query = preg_replace('/\\+?\\d[\\d\\s\\-()]{7,}\\d/', '[phone]', $query);
        $query = preg_replace('/[[:punct:]]+/u', ' ', (string) $query);
        $query = preg_replace('/\\s+/', ' ', (string) $query);
        $query = trim((string) $query);
        return $query;
    }

    private static function normalize_behavior_path($value)
    {
        $path = sanitize_text_field((string) $value);
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }

        $has_trailing = substr($path, -1) === '/';
        $path = preg_replace('#/+#', '/', $path);
        $path = ltrim((string) $path, '/');
        if ($path === '') {
            return '';
        }

        if ($has_trailing) {
            $path = rtrim((string) $path, '/') . '/';
        }

        return $path;
    }

    private static function normalize_behavior_object_type($value)
    {
        $type = sanitize_key((string) $value);
        $allowed = array('file', 'folder', 'search', 'package');
        if (!in_array($type, $allowed, true)) {
            return '';
        }
        return $type;
    }

    private static function normalize_behavior_region($value)
    {
        $region = sanitize_key((string) $value);
        if (!in_array($region, array('national', 'international', 'unknown'), true)) {
            return 'unknown';
        }
        return $region;
    }

    private static function normalize_behavior_device_class($value)
    {
        $device = sanitize_key((string) $value);
        if (!in_array($device, array('mobile', 'tablet', 'desktop', 'unknown'), true)) {
            return 'unknown';
        }
        return $device;
    }

    private static function normalize_behavior_filters($filters)
    {
        if (!is_array($filters)) {
            $filters = array();
        }

        $tier = null;
        if (array_key_exists('tier', $filters) && $filters['tier'] !== '' && $filters['tier'] !== 'all' && $filters['tier'] !== null) {
            $tier = intval($filters['tier']);
        }

        $region = '';
        if (!empty($filters['region']) && $filters['region'] !== 'all') {
            $region = self::normalize_behavior_region($filters['region']);
        }

        $device = '';
        if (!empty($filters['device_class']) && $filters['device_class'] !== 'all') {
            $device = self::normalize_behavior_device_class($filters['device_class']);
        }

        return array(
            'tier' => $tier,
            'region' => $region,
            'device_class' => $device,
        );
    }

    private static function normalize_behavior_metric_keys($metric_keys)
    {
        $keys = array_values(array_filter(array_map('sanitize_key', (array) $metric_keys)));
        return array_values(array_unique($keys));
    }

    private static function default_transfer_metric_keys()
    {
        return array(
            'download_file_granted',
            'download_folder_granted',
            'download_folder_granted_backfill',
            'download_folder_completed',
            'preview_direct_opened',
            'preview_proxy_streamed',
        );
    }

    private static function build_folder_authorized_sizes_from_index()
    {
        global $wpdb;

        $index_table = $wpdb->prefix . 'jpsm_mediavault_index';
        if (!self::table_exists($index_table)) {
            return array();
        }

        $rows = $wpdb->get_results(
            "SELECT path, size FROM $index_table WHERE path <> ''",
            ARRAY_A
        );
        if (!is_array($rows) || empty($rows)) {
            return array();
        }

        $folder_sizes = array();
        foreach ($rows as $row) {
            $path = self::normalize_behavior_path($row['path'] ?? '');
            $size = self::normalize_behavior_bytes($row['size'] ?? 0);
            if ($path === '' || $size <= 0 || substr($path, -1) === '/') {
                continue;
            }

            $segments = explode('/', $path);
            if (count($segments) <= 1) {
                continue;
            }

            $folder_count = count($segments) - 1; // exclude filename
            for ($i = 1; $i <= $folder_count; $i++) {
                $folder = implode('/', array_slice($segments, 0, $i)) . '/';
                if (!isset($folder_sizes[$folder])) {
                    $folder_sizes[$folder] = 0;
                }
                $folder_sizes[$folder] += $size;
            }
        }

        return $folder_sizes;
    }

    private static function build_behavior_daily_where($from_date, $to_date, $filters = array(), $metric_keys = array())
    {
        $from = self::normalize_behavior_date($from_date);
        $to = self::normalize_behavior_date($to_date);
        if ($from === '' || $to === '' || $from > $to) {
            return array('', array());
        }

        $where = array(
            'day_date >= %s',
            'day_date <= %s',
        );
        $params = array($from, $to);

        $keys = self::normalize_behavior_metric_keys($metric_keys);
        if (!empty($keys)) {
            $where[] = 'metric_key IN (' . implode(',', array_fill(0, count($keys), '%s')) . ')';
            foreach ($keys as $key) {
                $params[] = $key;
            }
        }

        $normalized_filters = self::normalize_behavior_filters($filters);
        if ($normalized_filters['tier'] !== null) {
            $where[] = 'tier = %d';
            $params[] = intval($normalized_filters['tier']);
        }

        if ($normalized_filters['region'] !== '') {
            $where[] = 'region = %s';
            $params[] = $normalized_filters['region'];
        }

        if ($normalized_filters['device_class'] !== '') {
            $where[] = 'device_class = %s';
            $params[] = $normalized_filters['device_class'];
        }

        return array(implode(' AND ', $where), $params);
    }

    private static function build_behavior_events_where($from_date, $to_date, $filters = array(), $metric_keys = array())
    {
        $from = self::normalize_behavior_date($from_date);
        $to = self::normalize_behavior_date($to_date);
        if ($from === '' || $to === '' || $from > $to) {
            return array('', array());
        }

        $to_plus_one = gmdate('Y-m-d', strtotime($to . ' +1 day'));
        if ($to_plus_one === false) {
            return array('', array());
        }

        $where = array(
            'event_time >= %s',
            'event_time < %s',
        );
        $params = array($from . ' 00:00:00', $to_plus_one . ' 00:00:00');

        $keys = self::normalize_behavior_metric_keys($metric_keys);
        if (empty($keys)) {
            $keys = self::default_transfer_metric_keys();
        }
        $where[] = 'event_name IN (' . implode(',', array_fill(0, count($keys), '%s')) . ')';
        foreach ($keys as $key) {
            $params[] = $key;
        }

        $normalized_filters = self::normalize_behavior_filters($filters);
        if ($normalized_filters['tier'] !== null) {
            $where[] = 'tier = %d';
            $params[] = intval($normalized_filters['tier']);
        }

        if ($normalized_filters['region'] !== '') {
            $where[] = 'region = %s';
            $params[] = $normalized_filters['region'];
        }

        if ($normalized_filters['device_class'] !== '') {
            $where[] = 'device_class = %s';
            $params[] = $normalized_filters['device_class'];
        }

        return array(implode(' AND ', $where), $params);
    }

    private static function behavior_dimension_hash($data)
    {
        if (!is_array($data)) {
            $data = array();
        }

        $parts = array(
            self::normalize_behavior_query($data['query_norm'] ?? ''),
            self::normalize_behavior_object_type($data['object_type'] ?? ''),
            self::normalize_behavior_path($data['object_path_norm'] ?? ''),
            strval(intval($data['tier'] ?? 0)),
            self::normalize_behavior_region($data['region'] ?? 'unknown'),
            self::normalize_behavior_device_class($data['device_class'] ?? 'unknown'),
        );

        return sha1(implode('|', $parts));
    }

    private static function normalize_finance_datetime($value, $fallback = '')
    {
        $raw = sanitize_text_field((string) $value);
        if ($raw === '') {
            $raw = $fallback !== '' ? $fallback : current_time('mysql');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw . ' 00:00:00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $raw)) {
            return strlen($raw) === 16 ? ($raw . ':00') : $raw;
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function normalize_finance_decimal($value, $scale = 2)
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return 0.0;
            }

            $value = str_replace(' ', '', $value);
            if (preg_match('/^-?\d+,\d+$/', $value)) {
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        }

        if (!is_numeric($value)) {
            return 0.0;
        }

        return round((float) $value, intval($scale));
    }

    private static function normalize_finance_market($value)
    {
        $market = sanitize_key((string) $value);
        if (!in_array($market, array('mx', 'us', 'manual'), true)) {
            return '';
        }
        return $market;
    }

    private static function normalize_finance_channel($value)
    {
        $channel = sanitize_key((string) $value);
        $allowed = array('bank_transfer', 'paypal', 'cash', 'card', 'manual_adjustment', 'other');
        if (!in_array($channel, $allowed, true)) {
            return 'other';
        }
        return $channel;
    }

    private static function normalize_finance_currency($value)
    {
        $currency = strtoupper(sanitize_text_field((string) $value));
        if (!in_array($currency, array('MXN', 'USD'), true)) {
            return '';
        }
        return $currency;
    }

    private static function normalize_finance_settlement_entry($entry)
    {
        if (!is_array($entry)) {
            $entry = array();
        }

        $uid = sanitize_text_field((string) ($entry['settlement_uid'] ?? ''));
        if ($uid === '') {
            $uid = uniqid('settle_');
        }

        $created_at = self::normalize_finance_datetime($entry['created_at'] ?? '', current_time('mysql'));
        $updated_at = self::normalize_finance_datetime($entry['updated_at'] ?? '', current_time('mysql'));
        $sales_count = max(0, intval($entry['sales_count'] ?? 0));

        return array(
            'settlement_uid' => $uid,
            'settlement_date' => self::normalize_finance_datetime($entry['settlement_date'] ?? '', current_time('mysql')),
            'market' => self::normalize_finance_market($entry['market'] ?? ''),
            'channel' => self::normalize_finance_channel($entry['channel'] ?? ''),
            'currency' => self::normalize_finance_currency($entry['currency'] ?? ''),
            'gross_amount' => max(0, self::normalize_finance_decimal($entry['gross_amount'] ?? 0)),
            'fee_amount' => max(0, self::normalize_finance_decimal($entry['fee_amount'] ?? 0)),
            'net_amount' => max(0, self::normalize_finance_decimal($entry['net_amount'] ?? 0)),
            'fx_rate' => max(0, self::normalize_finance_decimal($entry['fx_rate'] ?? 0, 6)),
            'net_amount_mxn' => max(0, self::normalize_finance_decimal($entry['net_amount_mxn'] ?? 0)),
            'sales_count' => $sales_count,
            'bank_account' => sanitize_text_field((string) ($entry['bank_account'] ?? '')),
            'external_ref' => sanitize_text_field((string) ($entry['external_ref'] ?? '')),
            'notes' => sanitize_text_field((string) ($entry['notes'] ?? '')),
            'status' => sanitize_text_field((string) ($entry['status'] ?? 'recorded')),
            'created_at' => $created_at,
            'updated_at' => $updated_at,
        );
    }

    private static function normalize_finance_settlement_item_entry($entry, $settlement_uid = '')
    {
        if (!is_array($entry)) {
            $entry = array();
        }

        $item_uid = sanitize_text_field((string) ($entry['item_uid'] ?? ''));
        if ($item_uid === '') {
            $item_uid = uniqid('sitem_');
        }

        return array(
            'item_uid' => $item_uid,
            'settlement_uid' => sanitize_text_field((string) ($settlement_uid !== '' ? $settlement_uid : ($entry['settlement_uid'] ?? ''))),
            'sale_uid' => sanitize_text_field((string) ($entry['sale_uid'] ?? '')),
            'sale_time' => self::normalize_finance_datetime($entry['sale_time'] ?? '', current_time('mysql')),
            'sale_email' => self::normalize_email($entry['sale_email'] ?? ''),
            'package' => sanitize_text_field((string) ($entry['package'] ?? '')),
            'sale_region' => sanitize_text_field((string) ($entry['sale_region'] ?? '')),
            'gross_amount' => max(0, self::normalize_finance_decimal($entry['gross_amount'] ?? 0)),
            'fee_amount' => max(0, self::normalize_finance_decimal($entry['fee_amount'] ?? 0)),
            'net_amount' => max(0, self::normalize_finance_decimal($entry['net_amount'] ?? 0)),
            'currency' => self::normalize_finance_currency($entry['currency'] ?? ''),
            'created_at' => self::normalize_finance_datetime($entry['created_at'] ?? '', current_time('mysql')),
        );
    }

    private static function normalize_finance_expense_entry($entry)
    {
        if (!is_array($entry)) {
            $entry = array();
        }

        $uid = sanitize_text_field((string) ($entry['expense_uid'] ?? ''));
        if ($uid === '') {
            $uid = uniqid('expense_');
        }

        return array(
            'expense_uid' => $uid,
            'expense_date' => self::normalize_finance_datetime($entry['expense_date'] ?? '', current_time('mysql')),
            'category' => sanitize_key((string) ($entry['category'] ?? 'otros')),
            'vendor' => sanitize_text_field((string) ($entry['vendor'] ?? '')),
            'description' => sanitize_text_field((string) ($entry['description'] ?? '')),
            'amount' => max(0, self::normalize_finance_decimal($entry['amount'] ?? 0)),
            'currency' => self::normalize_finance_currency($entry['currency'] ?? ''),
            'fx_rate' => max(0, self::normalize_finance_decimal($entry['fx_rate'] ?? 0, 6)),
            'amount_mxn' => max(0, self::normalize_finance_decimal($entry['amount_mxn'] ?? 0)),
            'account_label' => sanitize_text_field((string) ($entry['account_label'] ?? '')),
            'notes' => sanitize_text_field((string) ($entry['notes'] ?? '')),
            'status' => sanitize_text_field((string) ($entry['status'] ?? 'recorded')),
            'created_at' => self::normalize_finance_datetime($entry['created_at'] ?? '', current_time('mysql')),
            'updated_at' => self::normalize_finance_datetime($entry['updated_at'] ?? '', current_time('mysql')),
        );
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

    private static function row_to_finance_settlement_entry($row)
    {
        return array(
            'settlement_uid' => isset($row['settlement_uid']) ? (string) $row['settlement_uid'] : '',
            'settlement_date' => isset($row['settlement_date']) ? (string) $row['settlement_date'] : '',
            'market' => isset($row['market']) ? (string) $row['market'] : '',
            'channel' => isset($row['channel']) ? (string) $row['channel'] : '',
            'currency' => isset($row['currency']) ? (string) $row['currency'] : '',
            'gross_amount' => isset($row['gross_amount']) ? floatval($row['gross_amount']) : 0.0,
            'fee_amount' => isset($row['fee_amount']) ? floatval($row['fee_amount']) : 0.0,
            'net_amount' => isset($row['net_amount']) ? floatval($row['net_amount']) : 0.0,
            'fx_rate' => isset($row['fx_rate']) ? floatval($row['fx_rate']) : 0.0,
            'net_amount_mxn' => isset($row['net_amount_mxn']) ? floatval($row['net_amount_mxn']) : 0.0,
            'sales_count' => isset($row['sales_count']) ? intval($row['sales_count']) : 0,
            'bank_account' => isset($row['bank_account']) ? (string) $row['bank_account'] : '',
            'external_ref' => isset($row['external_ref']) ? (string) $row['external_ref'] : '',
            'notes' => isset($row['notes']) ? (string) $row['notes'] : '',
            'status' => isset($row['status']) ? (string) $row['status'] : '',
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : '',
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
        );
    }

    private static function row_to_finance_settlement_item_entry($row)
    {
        return array(
            'item_uid' => isset($row['item_uid']) ? (string) $row['item_uid'] : '',
            'settlement_uid' => isset($row['settlement_uid']) ? (string) $row['settlement_uid'] : '',
            'sale_uid' => isset($row['sale_uid']) ? (string) $row['sale_uid'] : '',
            'sale_time' => isset($row['sale_time']) ? (string) $row['sale_time'] : '',
            'sale_email' => isset($row['sale_email']) ? (string) $row['sale_email'] : '',
            'package' => isset($row['package']) ? (string) $row['package'] : '',
            'sale_region' => isset($row['sale_region']) ? (string) $row['sale_region'] : '',
            'gross_amount' => isset($row['gross_amount']) ? floatval($row['gross_amount']) : 0.0,
            'fee_amount' => isset($row['fee_amount']) ? floatval($row['fee_amount']) : 0.0,
            'net_amount' => isset($row['net_amount']) ? floatval($row['net_amount']) : 0.0,
            'currency' => isset($row['currency']) ? (string) $row['currency'] : '',
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : '',
        );
    }

    private static function row_to_finance_expense_entry($row)
    {
        return array(
            'expense_uid' => isset($row['expense_uid']) ? (string) $row['expense_uid'] : '',
            'expense_date' => isset($row['expense_date']) ? (string) $row['expense_date'] : '',
            'category' => isset($row['category']) ? (string) $row['category'] : '',
            'vendor' => isset($row['vendor']) ? (string) $row['vendor'] : '',
            'description' => isset($row['description']) ? (string) $row['description'] : '',
            'amount' => isset($row['amount']) ? floatval($row['amount']) : 0.0,
            'currency' => isset($row['currency']) ? (string) $row['currency'] : '',
            'fx_rate' => isset($row['fx_rate']) ? floatval($row['fx_rate']) : 0.0,
            'amount_mxn' => isset($row['amount_mxn']) ? floatval($row['amount_mxn']) : 0.0,
            'account_label' => isset($row['account_label']) ? (string) $row['account_label'] : '',
            'notes' => isset($row['notes']) ? (string) $row['notes'] : '',
            'status' => isset($row['status']) ? (string) $row['status'] : '',
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : '',
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
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

// Backward compatibility alias.
class_alias(Data_Layer::class, 'JPSM_Data_Layer');
