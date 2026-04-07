<?php
/**
 * MediaVault Index Manager
 *
 * Manages local WordPress database indexes of S3 files
 * to enable fast browse/search without timeout issues.
 *
 * @package JetPack Store Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class JPSM_Index_Manager
{
    const BASE_TABLE_NAME = 'jpsm_mediavault_index';
    const SHADOW_SUFFIX = '_shadow';
    const BPM_OVERRIDES_TABLE_NAME = 'jpsm_mediavault_bpm_overrides';
    const BPM_BATCHES_TABLE_NAME = 'jpsm_mediavault_bpm_batches';
    const BPM_BATCH_ROWS_TABLE_NAME = 'jpsm_mediavault_bpm_batch_rows';
    const DESKTOP_TOKEN_HASH_OPTION = 'jpsm_desktop_api_token_hash';
    const DESKTOP_TOKEN_CREATED_AT_OPTION = 'jpsm_desktop_api_token_created_at';
    const DESKTOP_TOKEN_LAST_USED_OPTION = 'jpsm_desktop_api_token_last_used_at';
    const AUTO_BPM_SCAN_BATCH_LIMIT = 25;
    const AUTO_BPM_SCAN_HEAD_BYTES = 262143; // 256 KiB
    const AUTO_BPM_ACOUSTIC_SECONDS = 45;
    const AUTO_BPM_ACOUSTIC_SAMPLE_RATE = 11025;
    const FFMPEG_PATH_OPTION = 'jpsm_bpm_ffmpeg_path';
    const ACTIVE_TABLE_OPTION = 'jpsm_mediavault_index_active_table';
    const LAST_SYNC_OPTION = 'jpsm_mediavault_last_sync';
    const TABLE_VERSION_OPTION = 'jpsm_mediavault_index_version';
    const TABLE_VERSION = '2.2';
    const SYNC_STATE_OPTION = 'jpsm_mediavault_sync_state';

    private static $has_index_data_cache = array();

    private static $media_extensions = array(
        'audio' => array('mp3', 'wav', 'flac', 'm4a', 'ogg', 'aac'),
        'video' => array('mp4', 'mov', 'mkv', 'avi', 'webm', 'wmv'),
    );

    /**
     * Get media extension map used by sync/search/UI filters.
     */
    public static function get_media_extension_map()
    {
        return self::$media_extensions;
    }

    /**
     * Get active table alias (primary|shadow).
     */
    public static function get_active_table_alias()
    {
        $alias = (string) get_option(self::ACTIVE_TABLE_OPTION, 'primary');
        if ($alias !== 'primary' && $alias !== 'shadow') {
            $alias = 'primary';
            update_option(self::ACTIVE_TABLE_OPTION, $alias);
        }
        return $alias;
    }

    /**
     * Get inactive table alias (primary|shadow).
     */
    public static function get_inactive_table_alias($active_alias = null)
    {
        $active_alias = $active_alias ?: self::get_active_table_alias();
        return $active_alias === 'primary' ? 'shadow' : 'primary';
    }

    /**
     * Set active table alias.
     */
    private static function set_active_table_alias($alias)
    {
        $alias = ($alias === 'shadow') ? 'shadow' : 'primary';
        update_option(self::ACTIVE_TABLE_OPTION, $alias);
    }

    /**
     * Get table name by role: active|inactive|primary|shadow.
     */
    public static function get_table_name($role = 'active')
    {
        global $wpdb;

        $primary = $wpdb->prefix . self::BASE_TABLE_NAME;
        $shadow = $primary . self::SHADOW_SUFFIX;

        if ($role === 'primary') {
            return $primary;
        }
        if ($role === 'shadow') {
            return $shadow;
        }

        $active = self::get_active_table_alias();
        if ($role === 'inactive') {
            return (self::get_inactive_table_alias($active) === 'shadow') ? $shadow : $primary;
        }

        return ($active === 'shadow') ? $shadow : $primary;
    }

    /**
     * Get BPM override table name.
     */
    public static function get_bpm_overrides_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . self::BPM_OVERRIDES_TABLE_NAME;
    }

    /**
     * Get BPM batches table name.
     */
    public static function get_bpm_batches_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . self::BPM_BATCHES_TABLE_NAME;
    }

    /**
     * Get BPM batch rows table name.
     */
    public static function get_bpm_batch_rows_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . self::BPM_BATCH_ROWS_TABLE_NAME;
    }

    /**
     * Create or update both index tables.
     */
    public static function create_table()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $primary_table = self::get_table_name('primary');
        $shadow_table = self::get_table_name('shadow');
        $bpm_overrides_table = self::get_bpm_overrides_table_name();
        $bpm_batches_table = self::get_bpm_batches_table_name();
        $bpm_batch_rows_table = self::get_bpm_batch_rows_table_name();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(self::build_create_table_sql($primary_table, $charset_collate));
        dbDelta(self::build_create_table_sql($shadow_table, $charset_collate));
        dbDelta(self::build_create_bpm_overrides_table_sql($bpm_overrides_table, $charset_collate));
        dbDelta(self::build_create_bpm_batches_table_sql($bpm_batches_table, $charset_collate));
        dbDelta(self::build_create_bpm_batch_rows_table_sql($bpm_batch_rows_table, $charset_collate));

        // Best effort: remove legacy UNIQUE(path(191)) index so long keys don't collide.
        self::drop_legacy_unique_path_index($primary_table);
        self::drop_legacy_unique_path_index($shadow_table);

        if (!get_option(self::ACTIVE_TABLE_OPTION)) {
            update_option(self::ACTIVE_TABLE_OPTION, 'primary');
        }

        update_option(self::TABLE_VERSION_OPTION, self::TABLE_VERSION);
    }

    /**
     * Build schema SQL.
     */
    private static function build_create_table_sql($table_name, $charset_collate)
    {
        return "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            path VARCHAR(500) NOT NULL,
            path_hash CHAR(32) NOT NULL,
            path_norm VARCHAR(1024) NOT NULL DEFAULT '',
            name VARCHAR(255) NOT NULL,
            name_norm VARCHAR(255) NOT NULL DEFAULT '',
            folder VARCHAR(500) NOT NULL,
            folder_norm VARCHAR(1024) NOT NULL DEFAULT '',
            size BIGINT(20) UNSIGNED DEFAULT 0,
            extension VARCHAR(20) DEFAULT '',
            media_kind VARCHAR(16) DEFAULT 'other',
            bpm SMALLINT(5) UNSIGNED DEFAULT 0,
            bpm_source VARCHAR(32) DEFAULT '',
            last_modified DATETIME NULL,
            etag VARCHAR(64) DEFAULT '',
            depth SMALLINT(5) UNSIGNED DEFAULT 0,
            synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY path_hash (path_hash),
            KEY path (path(191)),
            KEY name (name(100)),
            KEY name_norm (name_norm(100)),
            KEY folder (folder(100)),
            KEY extension (extension),
            KEY media_kind (media_kind),
            KEY bpm (bpm),
            KEY last_modified (last_modified)
        ) $charset_collate;";
    }

    /**
     * Build schema SQL for persistent BPM overrides.
     *
     * This table keeps manual/imported BPM values stable across index re-syncs.
     */
    private static function build_create_bpm_overrides_table_sql($table_name, $charset_collate)
    {
        return "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            path_hash CHAR(32) NOT NULL,
            path VARCHAR(500) NOT NULL,
            bpm SMALLINT(5) UNSIGNED NOT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'manual_csv',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY path_hash (path_hash),
            KEY path (path(191)),
            KEY bpm (bpm),
            KEY updated_at (updated_at)
        ) $charset_collate;";
    }

    /**
     * Build schema SQL for desktop-import BPM batches (idempotency + audit).
     */
    private static function build_create_bpm_batches_table_sql($table_name, $charset_collate)
    {
        return "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id VARCHAR(64) NOT NULL,
            payload_hash CHAR(64) NOT NULL DEFAULT '',
            profile VARCHAR(32) NOT NULL DEFAULT 'balanced',
            status VARCHAR(32) NOT NULL DEFAULT 'applied',
            metrics_json LONGTEXT NULL,
            created_by VARCHAR(128) NOT NULL DEFAULT 'desktop_api',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            rolled_back_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY batch_id (batch_id),
            KEY profile (profile),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
    }

    /**
     * Build schema SQL for per-row audit linked to desktop-import batches.
     */
    private static function build_create_bpm_batch_rows_table_sql($table_name, $charset_collate)
    {
        return "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id VARCHAR(64) NOT NULL,
            path_hash CHAR(32) NOT NULL,
            path VARCHAR(500) NOT NULL,
            old_bpm SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
            new_bpm SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
            old_source VARCHAR(32) NOT NULL DEFAULT '',
            new_source VARCHAR(32) NOT NULL DEFAULT '',
            confidence DECIMAL(6,4) NULL,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY batch_id (batch_id),
            KEY path_hash (path_hash),
            KEY applied_at (applied_at)
        ) $charset_collate;";
    }

    /**
     * Remove old UNIQUE KEY path(path(191)) when present.
     */
    private static function drop_legacy_unique_path_index($table_name)
    {
        global $wpdb;

        if (!self::is_sqlite_db()) {
            $mysql_idx = $wpdb->get_row(
                $wpdb->prepare(
                    "SHOW INDEX FROM $table_name WHERE Key_name = %s",
                    'path'
                ),
                ARRAY_A
            );
            if (is_array($mysql_idx) && isset($mysql_idx['Non_unique']) && intval($mysql_idx['Non_unique']) === 0) {
                $wpdb->query("ALTER TABLE $table_name DROP INDEX path");
            }
            return;
        }

        // SQLite path (wp-now integration).
        $idx_rows = $wpdb->get_results("PRAGMA index_list('$table_name')", ARRAY_A);
        if (!is_array($idx_rows) || empty($idx_rows)) {
            return;
        }

        foreach ($idx_rows as $idx) {
            $idx_name = (string) ($idx['name'] ?? '');
            $is_unique = intval($idx['unique'] ?? 0) === 1;
            if (!$is_unique || $idx_name === '') {
                continue;
            }
            if ($idx_name !== 'path') {
                continue;
            }

            $wpdb->query("DROP INDEX IF EXISTS $idx_name");
            break;
        }
    }

    /**
     * Sync files from S3 to local shadow/primary index in batches.
     *
     * Atomic behavior:
     * - Batch 1 truncates only inactive table.
     * - Active table switches only when final batch completes.
     */
    public static function sync_batch($continuation_token = null)
    {
        global $wpdb;

        if (!defined('JPSM_B2_KEY_ID') || !defined('JPSM_B2_APP_KEY')) {
            return new WP_Error('config_error', 'B2 credentials not configured');
        }

        if (!class_exists('JPSM_S3_Client')) {
            require_once JPSM_PLUGIN_DIR . 'includes/modules/mediavault/class-s3-client.php';
        }

        $s3 = new JPSM_S3_Client(
            JPSM_B2_KEY_ID,
            JPSM_B2_APP_KEY,
            JPSM_B2_REGION,
            JPSM_B2_BUCKET
        );

        $now = current_time('mysql');
        $is_first_batch = empty($continuation_token);

        $state = self::get_sync_state();

        if ($is_first_batch) {
            $target_alias = self::get_inactive_table_alias();
            $target_table = self::get_table_name($target_alias);

            self::truncate_table($target_table);

            $state = array(
                'sync_id' => self::generate_sync_id(),
                'status' => 'running',
                'target_table' => $target_alias,
                'started_at' => $now,
                'updated_at' => $now,
                'next_token' => null,
                'scanned' => 0,
                'inserted' => 0,
                'updated' => 0,
                'skipped_invalid' => 0,
                'errors' => 0,
                'last_error' => '',
            );
            self::set_sync_state($state);
        }

        $target_alias = (string) ($state['target_table'] ?? self::get_inactive_table_alias());
        if ($target_alias !== 'primary' && $target_alias !== 'shadow') {
            $target_alias = self::get_inactive_table_alias();
        }
        $target_table = self::get_table_name($target_alias);

        $result = self::list_objects_page_with_retry($s3, '', $continuation_token, 1000);
        if (is_wp_error($result)) {
            $state['status'] = 'failed';
            $state['updated_at'] = $now;
            $state['next_token'] = $continuation_token;
            $state['last_error'] = $result->get_error_message();
            self::set_sync_state($state);
            return $result;
        }

        $files = (array) ($result['files'] ?? array());
        $next_token = isset($result['next_token']) ? (string) $result['next_token'] : null;

        // Preload override map for this page to avoid per-row SQL calls.
        $page_hashes = array();
        foreach ($files as $file) {
            $candidate_path = self::normalize_object_key($file['path'] ?? '');
            if ($candidate_path === '' || substr($candidate_path, -1) === '/') {
                continue;
            }
            $page_hashes[md5($candidate_path)] = true;
        }
        $bpm_override_map = self::get_bpm_overrides_by_hashes(array_keys($page_hashes));

        $scanned = 0;
        $inserted = 0;
        $updated = 0;
        $skipped_invalid = 0;
        $errors = 0;

        foreach ($files as $file) {
            $scanned++;

            $path = self::normalize_object_key($file['path'] ?? '');
            if ($path === '' || substr($path, -1) === '/') {
                $skipped_invalid++;
                continue;
            }

            $name = self::normalize_file_name($file['name'] ?? basename($path));
            if ($name === '') {
                $name = basename($path);
            }

            $folder = self::folder_from_path($path);
            $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            $media_kind = self::detect_media_kind($extension);

            $path_norm = self::normalize_search_text($path);
            $name_norm = self::normalize_search_text($name);
            $folder_norm = self::normalize_search_text($folder);
            $depth = self::compute_depth($folder);

            $last_modified = self::normalize_last_modified($file['date'] ?? ($file['last_modified'] ?? ''));
            $etag = self::normalize_etag($file['etag'] ?? '');
            $size = max(0, intval($file['size'] ?? 0));
            $path_hash = md5($path);

            $resolved_bpm = self::resolve_bpm_for_object(
                $path,
                $name,
                $folder,
                $extension,
                $bpm_override_map[$path_hash] ?? null
            );
            $bpm = max(0, intval($resolved_bpm['bpm'] ?? 0));
            $bpm_source = sanitize_key((string) ($resolved_bpm['source'] ?? ''));

            $row = array(
                'path' => $path,
                'path_hash' => $path_hash,
                'path_norm' => $path_norm,
                'name' => $name,
                'name_norm' => $name_norm,
                'folder' => $folder,
                'folder_norm' => $folder_norm,
                'size' => $size,
                'extension' => $extension,
                'media_kind' => $media_kind,
                'bpm' => $bpm,
                'bpm_source' => $bpm_source,
                'last_modified' => $last_modified,
                'etag' => $etag,
                'depth' => $depth,
                'synced_at' => $now,
            );

            $replaced = $wpdb->replace(
                $target_table,
                $row,
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s')
            );

            if ($replaced === false) {
                $errors++;
                continue;
            }

            // REPLACE may return 2 when replacing an existing row.
            if (intval($replaced) > 1) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        $finished = empty($next_token);

        $state['status'] = $finished ? 'completed' : 'running';
        $state['updated_at'] = $now;
        $state['next_token'] = $next_token;
        $state['scanned'] = intval($state['scanned'] ?? 0) + $scanned;
        $state['inserted'] = intval($state['inserted'] ?? 0) + $inserted;
        $state['updated'] = intval($state['updated'] ?? 0) + $updated;
        $state['skipped_invalid'] = intval($state['skipped_invalid'] ?? 0) + $skipped_invalid;
        $state['errors'] = intval($state['errors'] ?? 0) + $errors;
        if ($finished) {
            $state['last_error'] = '';
        }

        if ($finished) {
            self::set_active_table_alias($target_alias);
            update_option(self::LAST_SYNC_OPTION, $now);
            self::$has_index_data_cache = array();
        }

        self::set_sync_state($state);

        return array(
            'success' => true,
            'sync_id' => (string) ($state['sync_id'] ?? ''),
            'target_table' => $target_alias,
            'active_table' => self::get_active_table_alias(),
            'count' => $inserted + $updated,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped_invalid' => $skipped_invalid,
            'errors' => $errors,
            'scanned' => $scanned,
            'next_token' => $next_token,
            'finished' => $finished,
            'state' => $state,
        );
    }

    /**
     * Perform a full sync from S3 (loops through all batches).
     */
    public static function sync_from_s3()
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $token = null;
        $total_count = 0;
        $total_errors = 0;
        $total_scanned = 0;
        $total_skipped = 0;
        $sync_id = '';

        do {
            $result = self::sync_batch($token);
            if (is_wp_error($result)) {
                return $result;
            }

            $sync_id = (string) ($result['sync_id'] ?? $sync_id);
            $total_count += intval($result['count'] ?? 0);
            $total_errors += intval($result['errors'] ?? 0);
            $total_scanned += intval($result['scanned'] ?? 0);
            $total_skipped += intval($result['skipped_invalid'] ?? 0);
            $token = isset($result['next_token']) ? (string) $result['next_token'] : null;

            usleep(100000);
        } while (!empty($token));

        return array(
            'success' => true,
            'sync_id' => $sync_id,
            'synced' => $total_count,
            'errors' => $total_errors,
            'scanned' => $total_scanned,
            'skipped_invalid' => $total_skipped,
            'active_table' => self::get_active_table_alias(),
        );
    }

    /**
     * Legacy search API (array-only).
     */
    public static function search($query, $type_filter = null, $limit = 100)
    {
        $payload = self::search_v2($query, $type_filter, $limit, 0, false);
        return (array) ($payload['items'] ?? array());
    }

    /**
     * Search local index with ranking + optional light fuzzy fallback.
     *
     * Returns:
     * - items[]
     * - total
     * - offset
     * - limit
     * - suggestions[]
     */
    public static function search_v2($query, $type_filter = null, $limit = 100, $offset = 0, $with_fuzzy = true, $bpm_min = null, $bpm_max = null)
    {
        global $wpdb;

        $table_name = self::get_table_name('active');
        $limit = max(1, min(100, intval($limit)));
        $offset = max(0, intval($offset));

        $query_norm = self::normalize_search_text($query);
        $tokens = self::tokenize_query($query_norm);
        if (empty($tokens)) {
            return array(
                'items' => array(),
                'total' => 0,
                'offset' => $offset,
                'limit' => $limit,
                'suggestions' => array(),
            );
        }

        list($where_sql, $params) = self::build_exact_search_where($tokens, $type_filter, $bpm_min, $bpm_max);

        $count_sql = "SELECT COUNT(*) FROM $table_name WHERE $where_sql";
        $exact_total = intval($wpdb->get_var($wpdb->prepare($count_sql, $params)));

        $candidate_limit = min(800, max(150, $limit * 6));
        $sql = "SELECT path, name, folder, size, extension,
                       path_norm, name_norm, folder_norm, media_kind, bpm, bpm_source
                FROM $table_name
                WHERE $where_sql
                LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, array($candidate_limit))), ARRAY_A);
        if (!is_array($rows)) {
            $rows = array();
        }

        $scored = self::score_rows($rows, $query_norm, $tokens, false);

        if ($with_fuzzy && count($scored) < ($offset + $limit)) {
            $fuzzy_rows = self::fetch_fuzzy_candidates($query_norm, $type_filter, 400, $bpm_min, $bpm_max);
            $existing = array();
            foreach ($scored as $entry) {
                $existing[(string) ($entry['path'] ?? '')] = true;
            }

            foreach ($fuzzy_rows as $row) {
                $path = (string) ($row['path'] ?? '');
                if ($path === '' || isset($existing[$path])) {
                    continue;
                }
                $entry = self::score_single_row($row, $query_norm, $tokens, true);
                if ($entry === null) {
                    continue;
                }
                $existing[$path] = true;
                $scored[] = $entry;
            }

            usort($scored, array(__CLASS__, 'compare_scored_items'));
        }

        $total = count($scored);
        $items = array_slice($scored, $offset, $limit);

        $suggestions = array();
        if ($total === 0) {
            $suggestions = self::build_suggestions($query_norm, $type_filter, 3, $bpm_min, $bpm_max);
        }

        return array(
            'items' => array_values($items),
            'total' => max($exact_total, $total),
            'offset' => $offset,
            'limit' => $limit,
            'suggestions' => $suggestions,
        );
    }

    /**
     * Quick readiness check for browse/search features that rely on the local index.
     */
    public static function has_index_data($role = 'active')
    {
        $alias = $role;
        if ($role !== 'primary' && $role !== 'shadow') {
            $alias = self::get_active_table_alias();
        }

        if (array_key_exists($alias, self::$has_index_data_cache)) {
            return (bool) self::$has_index_data_cache[$alias];
        }

        global $wpdb;
        $table_name = self::get_table_name($alias);

        $has = (bool) $wpdb->get_var("SELECT 1 FROM $table_name LIMIT 1");
        self::$has_index_data_cache[$alias] = $has;
        return $has;
    }

    /**
     * List a folder's immediate children using the active local index.
     */
    public static function list_folder_structure($folder)
    {
        global $wpdb;

        $table_name = self::get_table_name('active');

        $prefix = ltrim((string) $folder, '/');
        if ($prefix !== '' && substr($prefix, -1) !== '/') {
            $prefix .= '/';
        }

        $folder_key = rtrim($prefix, '/');

        if ($folder_key === '') {
            $files_sql = "SELECT path, name, size,
                                 COALESCE(last_modified, synced_at) AS best_date,
                                 bpm, bpm_source
                          FROM $table_name
                          WHERE folder IN ('.','')
                          ORDER BY name ASC";
            $files_rows = $wpdb->get_results($files_sql, ARRAY_A);
        } else {
            $files_sql = $wpdb->prepare(
                "SELECT path, name, size,
                        COALESCE(last_modified, synced_at) AS best_date,
                        bpm, bpm_source
                 FROM $table_name
                 WHERE folder = %s
                 ORDER BY name ASC",
                $folder_key
            );
            $files_rows = $wpdb->get_results($files_sql, ARRAY_A);
        }

        $files = array();
        if (is_array($files_rows)) {
            foreach ($files_rows as $r) {
                $files[] = array(
                    'name' => (string) ($r['name'] ?? ''),
                    'path' => (string) ($r['path'] ?? ''),
                    'size' => (int) ($r['size'] ?? 0),
                    'date' => (string) ($r['best_date'] ?? ''),
                    'bpm' => max(0, intval($r['bpm'] ?? 0)),
                    'bpm_source' => sanitize_key((string) ($r['bpm_source'] ?? '')),
                );
            }
        }

        if ($folder_key === '') {
            $folders_rows = $wpdb->get_col("SELECT DISTINCT folder FROM $table_name WHERE folder NOT IN ('.','') ORDER BY folder ASC");
        } else {
            $like_folder = $wpdb->esc_like($folder_key) . '/%';
            $folders_rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT folder FROM $table_name WHERE folder LIKE %s ORDER BY folder ASC",
                    $like_folder
                )
            );
        }

        $children = array();
        if (is_array($folders_rows)) {
            foreach ($folders_rows as $f) {
                $f = ltrim((string) $f, '/');
                if ($f === '' || $f === '.') {
                    continue;
                }

                if ($folder_key === '') {
                    $parts = explode('/', $f);
                    $child = trim((string) ($parts[0] ?? ''));
                } else {
                    $rest = substr($f, strlen($folder_key) + 1);
                    $parts = explode('/', (string) $rest);
                    $child = trim((string) ($parts[0] ?? ''));
                }

                if ($child === '' || $child === '.') {
                    continue;
                }
                $children[$child] = true;
            }
        }

        $folders = array();
        foreach (array_keys($children) as $child) {
            $folders[] = $prefix . $child . '/';
        }
        sort($folders, SORT_STRING);

        return array(
            'folders' => $folders,
            'files' => $files,
        );
    }

    /**
     * Get index statistics from active + sync-state metadata.
     */
    public static function get_stats()
    {
        global $wpdb;

        $active_alias = self::get_active_table_alias();
        $active_table = self::get_table_name('active');
        $shadow_table = self::get_table_name('inactive');

        $count = intval($wpdb->get_var("SELECT COUNT(*) FROM $active_table"));
        $last_sync = get_option(self::LAST_SYNC_OPTION, null);

        $audio_exts = "'" . implode("','", self::$media_extensions['audio']) . "'";
        $video_exts = "'" . implode("','", self::$media_extensions['video']) . "'";
        $audio_count = intval($wpdb->get_var(
            "SELECT COUNT(*) FROM $active_table
             WHERE media_kind = 'audio'
                OR ((media_kind IS NULL OR media_kind = '') AND extension IN ($audio_exts))"
        ));
        $video_count = intval($wpdb->get_var(
            "SELECT COUNT(*) FROM $active_table
             WHERE media_kind = 'video'
                OR ((media_kind IS NULL OR media_kind = '') AND extension IN ($video_exts))"
        ));
        $other_count = max(0, $count - $audio_count - $video_count);
        $audio_with_bpm = intval($wpdb->get_var(
            "SELECT COUNT(*) FROM $active_table
             WHERE bpm > 0
               AND (media_kind = 'audio'
                    OR ((media_kind IS NULL OR media_kind = '') AND extension IN ($audio_exts)))"
        ));
        $audio_pending_bpm_scan = intval($wpdb->get_var(
            "SELECT COUNT(*) FROM $active_table
             WHERE bpm = 0
               AND (bpm_source = '' OR bpm_source IS NULL)
               AND (media_kind = 'audio'
                    OR ((media_kind IS NULL OR media_kind = '') AND extension IN ($audio_exts)))"
        ));
        $audio_without_bpm = max(0, $audio_count - $audio_with_bpm);
        $bpm_coverage_pct = $audio_count > 0 ? round(($audio_with_bpm / $audio_count) * 100, 2) : 0.0;

        $sync_state = self::get_sync_state();

        return array(
            'total' => $count,
            'audio' => $audio_count,
            'video' => $video_count,
            'other' => $other_count,
            'audio_with_bpm' => $audio_with_bpm,
            'audio_without_bpm' => $audio_without_bpm,
            'audio_pending_bpm_scan' => $audio_pending_bpm_scan,
            'bpm_coverage_pct' => $bpm_coverage_pct,
            'last_sync' => $last_sync,
            'active_table' => $active_alias,
            'table_exists' => self::table_exists($active_table),
            'shadow_table_exists' => self::table_exists($shadow_table),
            'stale' => self::is_stale(),
            'sync_state' => $sync_state,
            'quality' => array(
                'scanned' => intval($sync_state['scanned'] ?? 0),
                'inserted' => intval($sync_state['inserted'] ?? 0),
                'updated' => intval($sync_state['updated'] ?? 0),
                'skipped_invalid' => intval($sync_state['skipped_invalid'] ?? 0),
                'errors' => intval($sync_state['errors'] ?? 0),
            ),
        );
    }

    /**
     * Clear index data.
     *
     * @param string $scope active|inactive|primary|shadow|all
     * @param bool $delete_last_sync Whether to delete last sync option.
     * @return int Number of affected rows (best effort)
     */
    public static function clear_index($scope = 'active', $delete_last_sync = true)
    {
        $total = 0;
        $tables = array();

        if ($scope === 'all') {
            $tables[] = self::get_table_name('primary');
            $tables[] = self::get_table_name('shadow');
        } elseif (in_array($scope, array('active', 'inactive', 'primary', 'shadow'), true)) {
            $tables[] = self::get_table_name($scope);
        } else {
            $tables[] = self::get_table_name('active');
        }

        foreach (array_unique($tables) as $table) {
            $result = self::truncate_table($table);
            if ($result !== false) {
                $total += intval($result);
            }
        }

        if ($delete_last_sync) {
            delete_option(self::LAST_SYNC_OPTION);
        }

        self::$has_index_data_cache = array();
        return $total;
    }

    /**
     * Check if active index is stale (older than 6 hours).
     */
    public static function is_stale()
    {
        $last_sync = get_option(self::LAST_SYNC_OPTION, null);

        if (!$last_sync) {
            return true;
        }

        $last_sync_time = strtotime((string) $last_sync);
        if (!$last_sync_time) {
            return true;
        }

        $six_hours_ago = time() - (6 * 60 * 60);
        return $last_sync_time < $six_hours_ago;
    }

    /**
     * AJAX: get index stats.
     */
    public static function get_stats_ajax()
    {
        if (!class_exists('JPSM_Auth')) {
            JPSM_API_Contract::send_error('Auth service unavailable', 'auth_unavailable', 500);
            return;
        }

        $auth = JPSM_Auth::authorize_request(array(
            'require_nonce' => true,
            'nonce_actions' => array('jpsm_nonce', 'jpsm_index_nonce'),
            'allow_admin' => true,
            'allow_secret_key' => true,
            'allow_user_session' => false,
        ));
        if (is_wp_error($auth)) {
            $message = $auth->get_error_code() === 'invalid_nonce' ? 'Invalid nonce' : 'Unauthorized';
            $code = $auth->get_error_code() === 'invalid_nonce' ? 'invalid_nonce' : 'unauthorized';
            $status = $auth->get_error_code() === 'invalid_nonce' ? 401 : 403;
            JPSM_API_Contract::send_error($message, $code, $status);
            return;
        }

        $stats = self::get_stats();
        $sync_state = (array) ($stats['sync_state'] ?? array());
        $stats['sync_status'] = (string) ($sync_state['status'] ?? 'idle');

        JPSM_API_Contract::send_success($stats, 'index_stats_fetched', 'Estadísticas del índice cargadas.', 200);
    }

    /**
     * AJAX: sync mediavault index in batches.
     */
    public static function sync_mediavault_index_ajax()
    {
        @ini_set('display_errors', 0);
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        try {
            if (!class_exists('JPSM_Auth')) {
                JPSM_API_Contract::send_error('Auth service unavailable', 'auth_unavailable', 500);
                return;
            }

            $auth = JPSM_Auth::authorize_request(array(
                'require_nonce' => true,
                'nonce_actions' => array('jpsm_nonce', 'jpsm_index_nonce'),
                'allow_admin' => true,
                'allow_secret_key' => true,
                'allow_user_session' => false,
            ));
            if (is_wp_error($auth)) {
                $message = $auth->get_error_code() === 'invalid_nonce' ? 'Invalid nonce' : 'Unauthorized';
                $code = $auth->get_error_code() === 'invalid_nonce' ? 'invalid_nonce' : 'unauthorized';
                $status = $auth->get_error_code() === 'invalid_nonce' ? 401 : 403;
                JPSM_API_Contract::send_error($message, $code, $status);
                return;
            }

            $next_token = isset($_POST['next_token']) ? sanitize_text_field(wp_unslash($_POST['next_token'])) : null;
            $result = self::sync_batch($next_token);

            if (is_wp_error($result)) {
                JPSM_API_Contract::send_wp_error($result, 'Error de sincronización', 'index_sync_failed', 502);
                return;
            }
            if (isset($result['success']) && !$result['success']) {
                JPSM_API_Contract::send_error($result['error'] ?? 'Error de sincronización', 'index_sync_failed', 500, $result);
                return;
            }

            JPSM_API_Contract::send_success($result, 'index_sync_batch_completed', 'Lote de sincronización procesado.', 200);
        } catch (Exception $e) {
            error_log('MediaVault Sync Exception: ' . $e->getMessage());
            JPSM_API_Contract::send_error('Server Error: ' . $e->getMessage(), 'server_error', 500);
        } catch (Error $e) {
            error_log('MediaVault Sync Fatal Error: ' . $e->getMessage());
            JPSM_API_Contract::send_error('Fatal Error: ' . $e->getMessage(), 'fatal_error', 500);
        }
    }

    /**
     * AJAX: auto-detect BPM in batches (software extraction from embedded tags).
     */
    public static function auto_detect_bpm_batch_ajax()
    {
        @ini_set('display_errors', 0);
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');

        if (!class_exists('JPSM_Auth')) {
            JPSM_API_Contract::send_error('Auth service unavailable', 'auth_unavailable', 500);
            return;
        }

        $auth = JPSM_Auth::authorize_request(array(
            'require_nonce' => true,
            'nonce_actions' => array('jpsm_nonce', 'jpsm_index_nonce'),
            'allow_admin' => true,
            'allow_secret_key' => false,
            'allow_user_session' => false,
        ));
        if (is_wp_error($auth)) {
            $message = $auth->get_error_code() === 'invalid_nonce' ? 'Invalid nonce' : 'Unauthorized';
            $code = $auth->get_error_code() === 'invalid_nonce' ? 'invalid_nonce' : 'unauthorized';
            $status = $auth->get_error_code() === 'invalid_nonce' ? 401 : 403;
            JPSM_API_Contract::send_error($message, $code, $status);
            return;
        }

        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : self::AUTO_BPM_SCAN_BATCH_LIMIT;
        $mode = isset($_POST['mode']) ? sanitize_key((string) wp_unslash($_POST['mode'])) : 'deep';
        $allow_acoustic = ($mode !== 'meta');
        $result = self::auto_detect_bpm_batch($limit, $allow_acoustic);
        if (is_wp_error($result)) {
            JPSM_API_Contract::send_wp_error($result, 'No se pudo procesar la extracción BPM automática.', 'auto_bpm_failed', 502);
            return;
        }

        JPSM_API_Contract::send_success($result, 'auto_bpm_batch_completed', 'Lote BPM automático procesado.', 200);
    }

    /**
     * AJAX: clear previous automatic scan marks to allow a full re-run.
     */
    public static function reset_auto_bpm_scan_marks_ajax()
    {
        if (!class_exists('JPSM_Auth')) {
            JPSM_API_Contract::send_error('Auth service unavailable', 'auth_unavailable', 500);
            return;
        }

        $auth = JPSM_Auth::authorize_request(array(
            'require_nonce' => true,
            'nonce_actions' => array('jpsm_nonce', 'jpsm_index_nonce'),
            'allow_admin' => true,
            'allow_secret_key' => false,
            'allow_user_session' => false,
        ));
        if (is_wp_error($auth)) {
            $message = $auth->get_error_code() === 'invalid_nonce' ? 'Invalid nonce' : 'Unauthorized';
            $code = $auth->get_error_code() === 'invalid_nonce' ? 'invalid_nonce' : 'unauthorized';
            $status = $auth->get_error_code() === 'invalid_nonce' ? 401 : 403;
            JPSM_API_Contract::send_error($message, $code, $status);
            return;
        }

        $counts = self::reset_auto_scan_marks();
        JPSM_API_Contract::send_success(array(
            'primary' => intval($counts['primary'] ?? 0),
            'shadow' => intval($counts['shadow'] ?? 0),
            'total' => intval($counts['primary'] ?? 0) + intval($counts['shadow'] ?? 0),
        ), 'auto_bpm_marks_reset', 'Marcas automáticas reiniciadas.', 200);
    }

    /**
     * AJAX: issue/rotate desktop API token.
     */
    public static function desktop_issue_token_ajax()
    {
        if (!class_exists('JPSM_Auth')) {
            JPSM_API_Contract::send_error('Auth service unavailable', 'auth_unavailable', 500);
            return;
        }

        $auth = JPSM_Auth::authorize_request(array(
            'require_nonce' => true,
            'nonce_actions' => array('jpsm_nonce', 'jpsm_index_nonce'),
            'allow_admin' => true,
            'allow_secret_key' => false,
            'allow_user_session' => false,
        ));
        if (is_wp_error($auth)) {
            $message = $auth->get_error_code() === 'invalid_nonce' ? 'Invalid nonce' : 'Unauthorized';
            $code = $auth->get_error_code() === 'invalid_nonce' ? 'invalid_nonce' : 'unauthorized';
            $status = $auth->get_error_code() === 'invalid_nonce' ? 401 : 403;
            JPSM_API_Contract::send_error($message, $code, $status);
            return;
        }

        try {
            $token = self::issue_desktop_token();
        } catch (Exception $e) {
            JPSM_API_Contract::send_error('No se pudo generar token desktop.', 'token_issue_failed', 500);
            return;
        }

        JPSM_API_Contract::send_success(array(
            'token' => $token,
            'created_at' => (string) get_option(self::DESKTOP_TOKEN_CREATED_AT_OPTION, ''),
        ), 'desktop_token_issued', 'Token desktop generado.', 200);
    }

    /**
     * AJAX: revoke desktop API token.
     */
    public static function desktop_revoke_token_ajax()
    {
        if (!class_exists('JPSM_Auth')) {
            JPSM_API_Contract::send_error('Auth service unavailable', 'auth_unavailable', 500);
            return;
        }

        $auth = JPSM_Auth::authorize_request(array(
            'require_nonce' => true,
            'nonce_actions' => array('jpsm_nonce', 'jpsm_index_nonce'),
            'allow_admin' => true,
            'allow_secret_key' => false,
            'allow_user_session' => false,
        ));
        if (is_wp_error($auth)) {
            $message = $auth->get_error_code() === 'invalid_nonce' ? 'Invalid nonce' : 'Unauthorized';
            $code = $auth->get_error_code() === 'invalid_nonce' ? 'invalid_nonce' : 'unauthorized';
            $status = $auth->get_error_code() === 'invalid_nonce' ? 401 : 403;
            JPSM_API_Contract::send_error($message, $code, $status);
            return;
        }

        delete_option(self::DESKTOP_TOKEN_HASH_OPTION);
        delete_option(self::DESKTOP_TOKEN_CREATED_AT_OPTION);
        delete_option(self::DESKTOP_TOKEN_LAST_USED_OPTION);

        JPSM_API_Contract::send_success(array(
            'revoked' => true,
        ), 'desktop_token_revoked', 'Token desktop revocado.', 200);
    }

    /**
     * AJAX: desktop API health check (token auth).
     */
    public static function desktop_api_health_ajax()
    {
        $token = self::get_bearer_token_from_request();
        if (!self::verify_desktop_token($token)) {
            JPSM_API_Contract::send_error('Unauthorized', 'unauthorized', 401);
            return;
        }

        JPSM_API_Contract::send_success(array(
            'ok' => true,
            'server_time' => current_time('mysql'),
        ), 'desktop_api_health_ok', 'Desktop API disponible.', 200);
    }

    /**
     * AJAX: import BPM rows from desktop app (token auth, idempotent by batch_id).
     */
    public static function import_bpm_batch_api_ajax()
    {
        $token = self::get_bearer_token_from_request();
        if (!self::verify_desktop_token($token)) {
            JPSM_API_Contract::send_error('Unauthorized', 'unauthorized', 401);
            return;
        }

        $payload = self::read_json_request_body();
        if (is_wp_error($payload)) {
            JPSM_API_Contract::send_wp_error($payload, 'Body JSON inválido.', 'invalid_json', 422);
            return;
        }

        $batch_id = sanitize_text_field((string) ($payload['batch_id'] ?? ''));
        if ($batch_id === '' || strlen($batch_id) > 64) {
            JPSM_API_Contract::send_error('batch_id inválido.', 'invalid_batch_id', 422);
            return;
        }

        $profile = self::normalize_desktop_profile($payload['profile'] ?? 'balanced');
        $rows = isset($payload['rows']) && is_array($payload['rows']) ? $payload['rows'] : array();
        if (empty($rows)) {
            JPSM_API_Contract::send_error('rows requerido.', 'missing_rows', 422);
            return;
        }
        if (count($rows) > 5000) {
            JPSM_API_Contract::send_error('rows excede máximo por lote (5000).', 'rows_limit_exceeded', 422);
            return;
        }

        $batch_hash = hash('sha256', wp_json_encode($rows));
        $existing_batch = self::get_bpm_batch($batch_id);
        if (is_array($existing_batch)) {
            $existing_hash = (string) ($existing_batch['payload_hash'] ?? '');
            $existing_status = sanitize_key((string) ($existing_batch['status'] ?? ''));
            if ($existing_hash === $batch_hash && $existing_status === 'applied') {
                JPSM_API_Contract::send_success(array(
                    'processed_rows' => 0,
                    'upserted' => 0,
                    'invalid_rows' => 0,
                    'manual_protected' => 0,
                    'duplicate_batch' => true,
                    'batch_version' => (string) ($existing_batch['created_at'] ?? ''),
                    'applied' => array('primary' => 0, 'shadow' => 0),
                ), 'bpm_batch_duplicate', 'Lote ya aplicado previamente.', 200);
                return;
            }
            JPSM_API_Contract::send_error('batch_id ya existe con payload distinto.', 'batch_conflict', 409);
            return;
        }

        self::insert_bpm_batch($batch_id, $batch_hash, $profile, 'processing', array(), 'desktop_api');

        $processed = 0;
        $upserted = 0;
        $invalid = 0;
        $manual_protected = 0;
        $error_rows = 0;
        $applied_rows = 0;
        $confidence_sum = 0.0;
        $confidence_count = 0;

        foreach ($rows as $row) {
            $processed++;
            if (!is_array($row)) {
                $invalid++;
                continue;
            }

            $path = self::normalize_object_key((string) ($row['path'] ?? ''));
            $bpm = max(0, intval($row['bpm'] ?? 0));
            $source = sanitize_key((string) ($row['source'] ?? 'desktop_api'));
            if ($source === '') {
                $source = 'desktop_api';
            }

            $confidence = null;
            if (isset($row['confidence']) && $row['confidence'] !== '' && $row['confidence'] !== null) {
                $confidence = max(0.0, min(1.0, floatval($row['confidence'])));
                $confidence_sum += $confidence;
                $confidence_count++;
            }

            if ($path === '' || $bpm < 40 || $bpm > 260) {
                $invalid++;
                continue;
            }

            $path_hash = md5($path);
            $existing_override = self::get_bpm_override_by_hash($path_hash);
            $old_bpm = max(0, intval($existing_override['bpm'] ?? 0));
            $old_source = sanitize_key((string) ($existing_override['source'] ?? ''));

            if ($old_source === 'manual_csv' && $old_bpm > 0 && $source !== 'manual_csv') {
                $manual_protected++;
                continue;
            }

            $save_status = self::upsert_bpm_override_row($path_hash, $path, $bpm, $source);
            if ($save_status !== 'ok') {
                $error_rows++;
                continue;
            }

            self::apply_bpm_to_indexes_by_hash($path_hash, $bpm, $source);
            self::insert_bpm_batch_row($batch_id, $path_hash, $path, $old_bpm, $bpm, $old_source, $source, $confidence);
            $upserted++;
            $applied_rows++;
        }

        $applied = self::apply_bpm_overrides_to_indexes();
        $metrics = array(
            'processed_rows' => $processed,
            'upserted' => $upserted,
            'invalid_rows' => $invalid,
            'manual_protected' => $manual_protected,
            'error_rows' => $error_rows,
            'applied_rows' => $applied_rows,
            'confidence_avg' => $confidence_count > 0 ? round($confidence_sum / $confidence_count, 4) : null,
        );
        self::update_bpm_batch_status($batch_id, 'applied', $metrics);

        JPSM_API_Contract::send_success(array(
            'processed_rows' => $processed,
            'upserted' => $upserted,
            'invalid_rows' => $invalid,
            'manual_protected' => $manual_protected,
            'duplicate_batch' => false,
            'batch_version' => current_time('mysql'),
            'applied' => $applied,
            'metrics' => $metrics,
        ), 'bpm_batch_imported', 'Lote BPM importado.', 200);
    }

    /**
     * AJAX: rollback one previously applied desktop batch by batch_id.
     */
    public static function rollback_bpm_batch_api_ajax()
    {
        $token = self::get_bearer_token_from_request();
        if (!self::verify_desktop_token($token)) {
            JPSM_API_Contract::send_error('Unauthorized', 'unauthorized', 401);
            return;
        }

        $payload = self::read_json_request_body();
        if (is_wp_error($payload)) {
            JPSM_API_Contract::send_wp_error($payload, 'Body JSON inválido.', 'invalid_json', 422);
            return;
        }

        $batch_id = sanitize_text_field((string) ($payload['batch_id'] ?? ''));
        if ($batch_id === '') {
            JPSM_API_Contract::send_error('batch_id requerido.', 'missing_batch_id', 422);
            return;
        }

        $batch = self::get_bpm_batch($batch_id);
        if (!is_array($batch)) {
            JPSM_API_Contract::send_error('batch_id no encontrado.', 'batch_not_found', 404);
            return;
        }

        $rows = self::get_bpm_batch_rows($batch_id);
        if (empty($rows)) {
            JPSM_API_Contract::send_error('No hay filas para revertir en ese batch.', 'empty_batch_rows', 409);
            return;
        }

        $reverted = 0;
        foreach ($rows as $row) {
            $path_hash = trim((string) ($row['path_hash'] ?? ''));
            $path = self::normalize_object_key((string) ($row['path'] ?? ''));
            $old_bpm = max(0, intval($row['old_bpm'] ?? 0));
            $old_source = sanitize_key((string) ($row['old_source'] ?? ''));
            if ($path_hash === '' || $path === '') {
                continue;
            }

            if ($old_bpm > 0) {
                self::upsert_bpm_override_row(
                    $path_hash,
                    $path,
                    $old_bpm,
                    $old_source !== '' ? $old_source : 'desktop_api_rollback'
                );
                self::apply_bpm_to_indexes_by_hash($path_hash, $old_bpm, $old_source !== '' ? $old_source : 'desktop_api_rollback');
            } else {
                self::delete_bpm_override_by_hash($path_hash);
                self::clear_bpm_on_indexes_by_hash($path_hash);
            }
            $reverted++;
        }

        $applied = self::apply_bpm_overrides_to_indexes();
        self::update_bpm_batch_status($batch_id, 'rolled_back', array(
            'rolled_back_rows' => $reverted,
            'rolled_back_at' => current_time('mysql'),
        ), true);

        JPSM_API_Contract::send_success(array(
            'batch_id' => $batch_id,
            'rolled_back_rows' => $reverted,
            'applied' => $applied,
        ), 'bpm_batch_rolled_back', 'Rollback de lote BPM completado.', 200);
    }

    /**
     * AJAX: import BPM overrides from CSV.
     *
     * CSV columns supported (header optional):
     * - path,file_path,object_path,file
     * - bpm,tempo
     */
    public static function import_bpm_csv_ajax()
    {
        if (!class_exists('JPSM_Auth')) {
            JPSM_API_Contract::send_error('Auth service unavailable', 'auth_unavailable', 500);
            return;
        }

        $auth = JPSM_Auth::authorize_request(array(
            'require_nonce' => true,
            'nonce_actions' => array('jpsm_nonce', 'jpsm_index_nonce'),
            'allow_admin' => true,
            'allow_secret_key' => false,
            'allow_user_session' => false,
        ));
        if (is_wp_error($auth)) {
            $message = $auth->get_error_code() === 'invalid_nonce' ? 'Invalid nonce' : 'Unauthorized';
            $code = $auth->get_error_code() === 'invalid_nonce' ? 'invalid_nonce' : 'unauthorized';
            $status = $auth->get_error_code() === 'invalid_nonce' ? 401 : 403;
            JPSM_API_Contract::send_error($message, $code, $status);
            return;
        }

        if (empty($_FILES['bpm_csv']) || !is_array($_FILES['bpm_csv'])) {
            JPSM_API_Contract::send_error('Archivo CSV requerido.', 'missing_file', 422);
            return;
        }

        $upload = $_FILES['bpm_csv'];
        $tmp_name = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
        $error_code = isset($upload['error']) ? intval($upload['error']) : UPLOAD_ERR_NO_FILE;
        if ($error_code !== UPLOAD_ERR_OK || $tmp_name === '' || !file_exists($tmp_name)) {
            JPSM_API_Contract::send_error('No se pudo leer el archivo CSV.', 'invalid_upload', 422);
            return;
        }

        $sample = (string) file_get_contents($tmp_name, false, null, 0, 4096);
        $delimiter = (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';

        $fh = fopen($tmp_name, 'r');
        if ($fh === false) {
            JPSM_API_Contract::send_error('No se pudo abrir el archivo CSV.', 'open_failed', 422);
            return;
        }

        $now = current_time('mysql');
        $table = self::get_bpm_overrides_table_name();
        $row_num = 0;
        $processed = 0;
        $upserted = 0;
        $invalid = 0;
        $missing = 0;
        $max_rows = 50000;
        $path_col = 0;
        $bpm_col = 1;

        $first = fgetcsv($fh, 0, $delimiter);
        if (!is_array($first)) {
            fclose($fh);
            JPSM_API_Contract::send_error('CSV vacío.', 'empty_csv', 422);
            return;
        }
        $row_num++;

        $header_map = array();
        foreach ($first as $idx => $raw_header) {
            $key = sanitize_key(strtolower(trim((string) $raw_header)));
            if ($key !== '') {
                $header_map[$key] = intval($idx);
            }
        }
        $has_header = isset($header_map['path']) || isset($header_map['file_path']) || isset($header_map['object_path']) || isset($header_map['file']);
        if ($has_header) {
            $path_col = isset($header_map['path']) ? intval($header_map['path'])
                : (isset($header_map['file_path']) ? intval($header_map['file_path'])
                    : (isset($header_map['object_path']) ? intval($header_map['object_path']) : intval($header_map['file'])));
            if (isset($header_map['bpm'])) {
                $bpm_col = intval($header_map['bpm']);
            } elseif (isset($header_map['tempo'])) {
                $bpm_col = intval($header_map['tempo']);
            } else {
                fclose($fh);
                JPSM_API_Contract::send_error('CSV inválido: falta columna bpm/tempo.', 'invalid_csv_header', 422);
                return;
            }
        } else {
            // First row is data (header-less CSV), process it as row data.
            if (self::import_bpm_csv_row($table, $first, $path_col, $bpm_col, $now)) {
                $upserted++;
            } else {
                $invalid++;
            }
            $processed++;
        }

        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
            $row_num++;
            if ($processed >= $max_rows) {
                break;
            }

            if (!is_array($row) || empty($row)) {
                continue;
            }

            $processed++;
            $result = self::import_bpm_csv_row($table, $row, $path_col, $bpm_col, $now, true);
            if ($result === 'ok') {
                $upserted++;
            } elseif ($result === 'missing') {
                $missing++;
            } else {
                $invalid++;
            }
        }
        fclose($fh);

        $applied = self::apply_bpm_overrides_to_indexes();

        JPSM_API_Contract::send_success(array(
            'processed_rows' => $processed,
            'upserted' => $upserted,
            'missing_or_not_found' => $missing,
            'invalid_rows' => $invalid,
            'applied' => $applied,
            'max_rows' => $max_rows,
            'truncated' => $processed >= $max_rows,
        ), 'bpm_csv_imported', 'Importación BPM completada.', 200);
    }

    /**
     * Process one automatic BPM extraction batch.
     *
     * Strategy:
     * - Target pending audio rows (`bpm=0` and empty `bpm_source`).
     * - For MP3 files, inspect ID3 `TBPM` tag using a byte-range request.
     * - Persist detected values to overrides + index tables.
     * - Mark non-detected rows to avoid reprocessing loops (`auto_none`/`auto_error`).
     *
     * @return array<string,mixed>|WP_Error
     */
    private static function auto_detect_bpm_batch($limit, $allow_acoustic = true)
    {
        if (!defined('JPSM_B2_KEY_ID') || !defined('JPSM_B2_APP_KEY')) {
            return new WP_Error('config_error', 'B2 credentials not configured');
        }

        if (!class_exists('JPSM_S3_Client')) {
            require_once JPSM_PLUGIN_DIR . 'includes/modules/mediavault/class-s3-client.php';
        }

        $allow_acoustic = (bool) $allow_acoustic;
        $requested_mode = $allow_acoustic ? 'deep' : 'meta';
        $warnings = array();
        $limit = max(1, min(100, intval($limit ?: self::AUTO_BPM_SCAN_BATCH_LIMIT)));
        $rows = self::fetch_auto_bpm_candidates($limit);
        if (is_wp_error($rows)) {
            return $rows;
        }
        if (empty($rows)) {
            return array(
                'scanned' => 0,
                'detected' => 0,
                'no_bpm' => 0,
                'unsupported' => 0,
                'errors' => 0,
                'remaining' => 0,
                'done' => true,
                'mode' => $allow_acoustic ? 'deep' : 'meta',
                'requested_mode' => $requested_mode,
                'warnings' => $warnings,
            );
        }

        $ffmpeg_binary = '';
        if ($allow_acoustic) {
            $ffmpeg_binary = self::resolve_ffmpeg_binary();
            if (is_wp_error($ffmpeg_binary)) {
                $warnings[] = sanitize_text_field((string) $ffmpeg_binary->get_error_message());
                $allow_acoustic = false;
                $ffmpeg_binary = '';
            }
        }

        $s3 = new JPSM_S3_Client(
            JPSM_B2_KEY_ID,
            JPSM_B2_APP_KEY,
            JPSM_B2_REGION,
            JPSM_B2_BUCKET
        );

        $scanned = 0;
        $detected = 0;
        $no_bpm = 0;
        $unsupported = 0;
        $errors = 0;
        $first_error_message = '';
        $error_breakdown = array();

        foreach ($rows as $row) {
            $scanned++;

            $path = self::normalize_object_key((string) ($row['path'] ?? ''));
            $path_hash = (string) ($row['path_hash'] ?? '');
            if ($path_hash === '') {
                $path_hash = md5($path);
            }

            if ($path === '' || $path_hash === '') {
                self::mark_auto_scan_source($path_hash, 'auto_invalid');
                $errors++;
                continue;
            }

            $extension = strtolower((string) ($row['extension'] ?? ''));
            if ($extension === '') {
                $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            }

            if (!$allow_acoustic && $extension !== 'mp3') {
                self::mark_auto_scan_source($path_hash, 'auto_unsupported');
                $unsupported++;
                continue;
            }

            if ($allow_acoustic && !self::is_auto_audio_extension_supported($extension)) {
                self::mark_auto_scan_source($path_hash, 'auto_unsupported');
                $unsupported++;
                continue;
            }

            $bpm = 0;
            $source = '';
            $meta_error = null;
            $acoustic_error = null;

            if ($extension === 'mp3') {
                $head_bytes = self::fetch_audio_head_bytes($s3, $path, self::AUTO_BPM_SCAN_HEAD_BYTES);
                if (is_wp_error($head_bytes)) {
                    $meta_error = $head_bytes;
                } else {
                    $bpm = self::extract_bpm_from_mp3_head((string) $head_bytes);
                    if ($bpm > 0) {
                        $source = 'auto_id3_tbpm';
                    }
                }
            }

            if ($bpm <= 0 && $allow_acoustic) {
                $acoustic_result = self::extract_bpm_from_audio_acoustic($s3, $path, $ffmpeg_binary);
                if (is_wp_error($acoustic_result)) {
                    $acoustic_error = $acoustic_result;
                } else {
                    $acoustic_bpm = max(0, intval($acoustic_result));
                    if ($acoustic_bpm > 0) {
                        $bpm = $acoustic_bpm;
                        $source = 'auto_acoustic_ffmpeg';
                    }
                }
            }

            if ($bpm > 0) {
                $save_status = self::upsert_auto_bpm_override($path_hash, $path, $bpm, $source);
                if ($save_status === 'manual_protected') {
                    $existing = self::get_bpm_override_by_hash($path_hash);
                    $existing_bpm = max(0, intval($existing['bpm'] ?? 0));
                    if ($existing_bpm > 0) {
                        self::apply_bpm_to_indexes_by_hash($path_hash, $existing_bpm, 'manual_csv');
                        self::mark_auto_scan_source($path_hash, 'manual_csv');
                        $detected++;
                        continue;
                    }

                    self::mark_auto_scan_source($path_hash, 'auto_error');
                    $errors++;
                    continue;
                }
                if ($save_status !== 'ok') {
                    self::mark_auto_scan_source($path_hash, 'auto_error');
                    $errors++;
                    if ($first_error_message === '') {
                        $first_error_message = 'No se pudo persistir BPM detectado.';
                    }
                    $error_breakdown['save_error'] = intval($error_breakdown['save_error'] ?? 0) + 1;
                    continue;
                }

                self::apply_bpm_to_indexes_by_hash($path_hash, $bpm, $source);
                $detected++;
                continue;
            }

            $effective_error = is_wp_error($acoustic_error)
                ? $acoustic_error
                : (!$allow_acoustic && is_wp_error($meta_error) ? $meta_error : null);
            if (is_wp_error($effective_error)) {
                self::mark_auto_scan_source($path_hash, 'auto_error');
                $errors++;
                self::register_auto_scan_error($effective_error, $first_error_message, $error_breakdown);
                continue;
            }

            self::mark_auto_scan_source($path_hash, 'auto_none');
            $no_bpm++;
        }

        $remaining = self::count_auto_bpm_pending();

        return array(
            'scanned' => $scanned,
            'detected' => $detected,
            'no_bpm' => $no_bpm,
            'unsupported' => $unsupported,
            'errors' => $errors,
            'first_error' => $first_error_message,
            'error_breakdown' => $error_breakdown,
            'remaining' => $remaining,
            'done' => $remaining <= 0,
            'batch_limit' => $limit,
            'mode' => $allow_acoustic ? 'deep' : 'meta',
            'requested_mode' => $requested_mode,
            'warnings' => $warnings,
        );
    }

    /**
     * Get pending audio rows for auto BPM extraction.
     *
     * @return array<int,array<string,mixed>>|WP_Error
     */
    private static function fetch_auto_bpm_candidates($limit)
    {
        global $wpdb;

        $table = self::get_table_name('active');
        if (!self::table_exists($table)) {
            return new WP_Error('index_missing', 'MediaVault index table not found');
        }

        $audio_exts = "'" . implode("','", self::$media_extensions['audio']) . "'";
        $sql = "SELECT id, path_hash, path, extension
                FROM $table
                WHERE bpm = 0
                  AND (bpm_source = '' OR bpm_source IS NULL)
                  AND (media_kind = 'audio'
                       OR ((media_kind IS NULL OR media_kind = '') AND extension IN ($audio_exts)))
                ORDER BY id ASC
                LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, max(1, intval($limit))), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /**
     * Count remaining audio rows pending auto BPM scan.
     */
    private static function count_auto_bpm_pending()
    {
        global $wpdb;

        $table = self::get_table_name('active');
        if (!self::table_exists($table)) {
            return 0;
        }

        $audio_exts = "'" . implode("','", self::$media_extensions['audio']) . "'";
        return intval($wpdb->get_var(
            "SELECT COUNT(*) FROM $table
             WHERE bpm = 0
               AND (bpm_source = '' OR bpm_source IS NULL)
               AND (media_kind = 'audio'
                    OR ((media_kind IS NULL OR media_kind = '') AND extension IN ($audio_exts)))"
        ));
    }

    /**
     * Reset transient automatic scan marks to allow a full deep re-run.
     *
     * @return array{primary:int,shadow:int}
     */
    private static function reset_auto_scan_marks()
    {
        global $wpdb;

        $marks = array('auto_none', 'auto_error', 'auto_unsupported', 'auto_invalid');
        $placeholders = implode(',', array_fill(0, count($marks), '%s'));
        $counts = array('primary' => 0, 'shadow' => 0);

        foreach (array('primary', 'shadow') as $alias) {
            $table = self::get_table_name($alias);
            if (!self::table_exists($table)) {
                continue;
            }

            $sql = "UPDATE $table
                    SET bpm_source = ''
                    WHERE bpm = 0
                      AND bpm_source IN ($placeholders)";
            $updated = $wpdb->query($wpdb->prepare($sql, $marks));
            $counts[$alias] = max(0, intval($updated));
        }

        return $counts;
    }

    /**
     * Register an extraction error in summary counters.
     *
     * @param string|WP_Error $error
     * @param array<string,int> $error_breakdown
     */
    private static function register_auto_scan_error($error, &$first_error_message, &$error_breakdown)
    {
        $message = '';
        $code = '';

        if (is_wp_error($error)) {
            $message = (string) $error->get_error_message();
            $code = sanitize_key((string) $error->get_error_code());
        } else {
            $message = sanitize_text_field((string) $error);
        }

        if ($message === '') {
            $message = 'Error de extracción BPM automática.';
        }
        if ($code === '') {
            $code = 'unknown_error';
        }

        if ($first_error_message === '') {
            $first_error_message = $message;
        }
        if (!is_array($error_breakdown)) {
            $error_breakdown = array();
        }
        $error_breakdown[$code] = intval($error_breakdown[$code] ?? 0) + 1;
    }

    /**
     * Mark scan status for a specific path hash on index tables.
     */
    private static function mark_auto_scan_source($path_hash, $source)
    {
        global $wpdb;

        $path_hash = trim((string) $path_hash);
        $source = sanitize_key((string) $source);
        if ($path_hash === '' || $source === '') {
            return;
        }

        foreach (array('primary', 'shadow') as $alias) {
            $table = self::get_table_name($alias);
            if (!self::table_exists($table)) {
                continue;
            }
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table
                     SET bpm_source = %s
                     WHERE path_hash = %s
                       AND bpm = 0
                       AND (bpm_source = '' OR bpm_source IS NULL)",
                    $source,
                    $path_hash
                )
            );
        }
    }

    /**
     * Persist automatic BPM into override table.
     *
     * @return string 'ok'|'manual_protected'|'error'
     */
    private static function upsert_auto_bpm_override($path_hash, $path, $bpm, $source)
    {
        global $wpdb;

        $path_hash = trim((string) $path_hash);
        $path = self::normalize_object_key((string) $path);
        $bpm = max(0, intval($bpm));
        $source = sanitize_key((string) $source);
        if ($path_hash === '' || $path === '' || $bpm <= 0 || $source === '') {
            return 'error';
        }

        $table = self::get_bpm_overrides_table_name();
        if (!self::table_exists($table)) {
            return 'error';
        }

        $existing = self::get_bpm_override_by_hash($path_hash);
        if (is_array($existing)) {
            $existing_source = sanitize_key((string) ($existing['source'] ?? ''));
            $existing_bpm = max(0, intval($existing['bpm'] ?? 0));
            if ($existing_source === 'manual_csv' && $existing_bpm > 0) {
                return 'manual_protected';
            }
        }

        $replaced = $wpdb->replace(
            $table,
            array(
                'path_hash' => $path_hash,
                'path' => $path,
                'bpm' => $bpm,
                'source' => $source,
                'updated_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%d', '%s', '%s')
        );

        if ($replaced === false) {
            return 'error';
        }
        return 'ok';
    }

    /**
     * Fetch one override row by path hash.
     *
     * @return array<string,mixed>|null
     */
    private static function get_bpm_override_by_hash($path_hash)
    {
        global $wpdb;

        $path_hash = trim((string) $path_hash);
        if ($path_hash === '') {
            return null;
        }

        $table = self::get_bpm_overrides_table_name();
        if (!self::table_exists($table)) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT path_hash, bpm, source
                 FROM $table
                 WHERE path_hash = %s
                 LIMIT 1",
                $path_hash
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Upsert one BPM override row.
     *
     * @return string 'ok'|'error'
     */
    private static function upsert_bpm_override_row($path_hash, $path, $bpm, $source)
    {
        global $wpdb;

        $path_hash = trim((string) $path_hash);
        $path = self::normalize_object_key((string) $path);
        $bpm = max(0, intval($bpm));
        $source = sanitize_key((string) $source);
        if ($path_hash === '' || $path === '' || $bpm <= 0 || $source === '') {
            return 'error';
        }

        $table = self::get_bpm_overrides_table_name();
        if (!self::table_exists($table)) {
            return 'error';
        }

        $ok = $wpdb->replace(
            $table,
            array(
                'path_hash' => $path_hash,
                'path' => $path,
                'bpm' => $bpm,
                'source' => $source,
                'updated_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%d', '%s', '%s')
        );

        return ($ok === false) ? 'error' : 'ok';
    }

    /**
     * Delete override row by path hash.
     */
    private static function delete_bpm_override_by_hash($path_hash)
    {
        global $wpdb;

        $path_hash = trim((string) $path_hash);
        if ($path_hash === '') {
            return;
        }

        $table = self::get_bpm_overrides_table_name();
        if (!self::table_exists($table)) {
            return;
        }
        $wpdb->delete($table, array('path_hash' => $path_hash), array('%s'));
    }

    /**
     * Clear bpm values in index tables for one object.
     */
    private static function clear_bpm_on_indexes_by_hash($path_hash)
    {
        global $wpdb;

        $path_hash = trim((string) $path_hash);
        if ($path_hash === '') {
            return;
        }

        foreach (array('primary', 'shadow') as $alias) {
            $table = self::get_table_name($alias);
            if (!self::table_exists($table)) {
                continue;
            }
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table
                     SET bpm = 0, bpm_source = ''
                     WHERE path_hash = %s",
                    $path_hash
                )
            );
        }
    }

    /**
     * Apply one detected BPM value to both index tables.
     */
    private static function apply_bpm_to_indexes_by_hash($path_hash, $bpm, $source)
    {
        global $wpdb;

        $path_hash = trim((string) $path_hash);
        $bpm = max(0, intval($bpm));
        $source = sanitize_key((string) $source);
        if ($path_hash === '' || $bpm <= 0 || $source === '') {
            return;
        }

        foreach (array('primary', 'shadow') as $alias) {
            $table = self::get_table_name($alias);
            if (!self::table_exists($table)) {
                continue;
            }
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table
                     SET bpm = %d, bpm_source = %s
                     WHERE path_hash = %s",
                    $bpm,
                    $source,
                    $path_hash
                )
            );
        }
    }

    /**
     * Fetch object head bytes using a short-lived presigned URL.
     *
     * @return string|WP_Error
     */
    private static function fetch_audio_head_bytes($s3, $path, $max_bytes)
    {
        $path = self::normalize_object_key((string) $path);
        if ($path === '') {
            return new WP_Error('invalid_path', 'Invalid object path');
        }

        $max_bytes = max(1023, min(1048575, intval($max_bytes))); // 1 KiB .. 1 MiB
        $url = $s3->get_presigned_url($path, 600);
        $response = wp_remote_get($url, array(
            'timeout' => 25,
            'redirection' => 2,
            'limit_response_size' => $max_bytes + 1,
            'headers' => array(
                'Range' => 'bytes=0-' . $max_bytes,
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = intval(wp_remote_retrieve_response_code($response));
        if (!in_array($code, array(200, 206), true)) {
            if ($code === 403) {
                return new WP_Error('forbidden_read', 'B2 key sin permiso de lectura de archivos (Read Files).', array('http_status' => $code));
            }
            return new WP_Error('head_fetch_failed', 'No se pudieron leer bytes del archivo de audio.', array('http_status' => $code));
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || $body === '') {
            return new WP_Error('empty_body', 'Empty audio metadata response');
        }

        if (strlen($body) > ($max_bytes + 1)) {
            $body = substr($body, 0, $max_bytes + 1);
        }
        return $body;
    }

    /**
     * Validate extension support for deep acoustic extraction.
     */
    private static function is_auto_audio_extension_supported($extension)
    {
        $extension = strtolower(trim((string) $extension));
        if ($extension === '') {
            return true;
        }
        return in_array($extension, self::$media_extensions['audio'], true);
    }

    /**
     * Resolve ffmpeg binary from configured option or PATH.
     *
     * @return string|WP_Error
     */
    private static function resolve_ffmpeg_binary()
    {
        $configured = trim((string) get_option(self::FFMPEG_PATH_OPTION, ''));
        $candidates = array();
        if ($configured !== '') {
            $candidates[] = $configured;
        }
        $candidates[] = 'ffmpeg';

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            if (strpos($candidate, '/') !== false && !is_executable($candidate)) {
                continue;
            }

            $status = 1;
            $output = self::run_shell_command_capture(
                escapeshellarg($candidate) . ' -version 2>/dev/null',
                $status
            );
            if ($status === 0 && is_string($output) && stripos($output, 'ffmpeg') !== false) {
                return $candidate;
            }
        }

        return new WP_Error(
            'ffmpeg_missing',
            'Modo profundo requiere FFmpeg en el servidor. Instálalo o configura la opción jpsm_bpm_ffmpeg_path.'
        );
    }

    /**
     * Estimate BPM acoustically from audio waveform via ffmpeg decode.
     *
     * @return int|WP_Error
     */
    private static function extract_bpm_from_audio_acoustic($s3, $path, $ffmpeg_binary)
    {
        $path = self::normalize_object_key((string) $path);
        if ($path === '') {
            return new WP_Error('invalid_path', 'Invalid object path');
        }

        $ffmpeg_binary = trim((string) $ffmpeg_binary);
        if ($ffmpeg_binary === '') {
            return new WP_Error('ffmpeg_missing', 'FFmpeg no disponible para análisis acústico.');
        }

        $url = $s3->get_presigned_url($path, 900);
        if (!is_string($url) || $url === '') {
            return new WP_Error('presign_failed', 'No se pudo generar URL firmada para análisis acústico.');
        }

        $seconds = max(10, min(90, intval(self::AUTO_BPM_ACOUSTIC_SECONDS)));
        $sample_rate = max(8000, min(22050, intval(self::AUTO_BPM_ACOUSTIC_SAMPLE_RATE)));
        $command = escapeshellarg($ffmpeg_binary)
            . ' -hide_banner -loglevel error -nostdin'
            . ' -rw_timeout 20000000'
            . ' -t ' . $seconds
            . ' -i ' . escapeshellarg($url)
            . ' -vn -ac 1 -ar ' . $sample_rate
            . ' -f s16le - 2>/dev/null';

        $status = 1;
        $pcm = self::run_shell_command_capture($command, $status);
        if (!is_string($pcm) || $pcm === '' || $status !== 0) {
            return new WP_Error('acoustic_decode_failed', 'FFmpeg no pudo decodificar el audio para estimar BPM.');
        }

        return self::estimate_bpm_from_pcm($pcm, $sample_rate);
    }

    /**
     * Estimate BPM from mono 16-bit PCM using onset flux autocorrelation.
     */
    private static function estimate_bpm_from_pcm($pcm, $sample_rate)
    {
        $sample_rate = max(1000, intval($sample_rate));
        $pcm = (string) $pcm;
        $byte_count = strlen($pcm);
        if ($byte_count < ($sample_rate * 8 * 2)) {
            return 0;
        }

        $sample_count = intdiv($byte_count, 2);
        if ($sample_count < ($sample_rate * 8)) {
            return 0;
        }

        $samples = unpack('v*', substr($pcm, 0, $sample_count * 2));
        if (!is_array($samples) || count($samples) < 128) {
            return 0;
        }

        $frame_samples = max(256, min(1024, intval(round($sample_rate / 43))));
        $envelope = array();
        $sum_sq = 0.0;
        $count_in_frame = 0;

        foreach ($samples as $raw_u16) {
            $raw_u16 = intval($raw_u16);
            $signed = ($raw_u16 > 32767) ? ($raw_u16 - 65536) : $raw_u16;
            $amp = $signed / 32768.0;
            $sum_sq += ($amp * $amp);
            $count_in_frame++;

            if ($count_in_frame >= $frame_samples) {
                $envelope[] = sqrt($sum_sq / max(1, $count_in_frame));
                $sum_sq = 0.0;
                $count_in_frame = 0;
            }
        }

        if ($count_in_frame > 0) {
            $envelope[] = sqrt($sum_sq / max(1, $count_in_frame));
        }

        $env_count = count($envelope);
        if ($env_count < 64) {
            return 0;
        }

        $mean = array_sum($envelope) / max(1, $env_count);
        $flux = array();
        $prev = 0.0;
        for ($i = 0; $i < $env_count; $i++) {
            $value = max(0.0, floatval($envelope[$i]) - $mean);
            $flux[] = max(0.0, $value - $prev);
            $prev = $value;
        }

        if (array_sum($flux) <= 0.001) {
            return 0;
        }

        $frame_seconds = $frame_samples / max(1, $sample_rate);
        $lag_min = max(1, intval(floor((60.0 / 260.0) / $frame_seconds)));
        $lag_max = min($env_count - 2, intval(ceil((60.0 / 40.0) / $frame_seconds)));
        if ($lag_max <= $lag_min) {
            return 0;
        }

        $best_score = 0.0;
        $best_lag = 0;
        for ($lag = $lag_min; $lag <= $lag_max; $lag++) {
            $score = 0.0;
            $upper = $env_count - $lag;
            for ($i = 0; $i < $upper; $i++) {
                $score += ($flux[$i] * $flux[$i + $lag]);
            }
            if ($score > $best_score) {
                $best_score = $score;
                $best_lag = $lag;
            }
        }

        if ($best_lag <= 0 || $best_score <= 0.0) {
            return 0;
        }

        $bpm = 60.0 / ($best_lag * $frame_seconds);
        if (!is_finite($bpm) || $bpm <= 0) {
            return 0;
        }

        while ($bpm < 70.0) {
            $bpm *= 2.0;
        }
        while ($bpm > 180.0) {
            $bpm /= 2.0;
        }

        return self::parse_bpm_number((string) $bpm);
    }

    /**
     * Execute shell command and capture stdout bytes.
     */
    private static function run_shell_command_capture($command, &$status)
    {
        $status = 1;
        $command = trim((string) $command);
        if ($command === '') {
            return '';
        }

        if (self::is_shell_function_available('proc_open')) {
            $pipes = array();
            $process = @proc_open(
                $command,
                array(
                    0 => array('pipe', 'r'),
                    1 => array('pipe', 'w'),
                    2 => array('pipe', 'w'),
                ),
                $pipes
            );
            if (is_resource($process)) {
                if (isset($pipes[0]) && is_resource($pipes[0])) {
                    fclose($pipes[0]);
                }
                $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
                if (isset($pipes[1]) && is_resource($pipes[1])) {
                    fclose($pipes[1]);
                }
                if (isset($pipes[2]) && is_resource($pipes[2])) {
                    fclose($pipes[2]);
                }
                $status = intval(proc_close($process));
                return is_string($stdout) ? $stdout : '';
            }
        }

        if (self::is_shell_function_available('shell_exec')) {
            $output = shell_exec($command);
            if (is_string($output)) {
                $status = 0;
                return $output;
            }
        }

        return '';
    }

    /**
     * Check whether a shell-related function is enabled by PHP config.
     */
    private static function is_shell_function_available($function_name)
    {
        $function_name = trim((string) $function_name);
        if ($function_name === '' || !function_exists($function_name)) {
            return false;
        }

        $disabled = (string) ini_get('disable_functions');
        if ($disabled === '') {
            return true;
        }

        $disabled_functions = array_map('trim', explode(',', $disabled));
        return !in_array($function_name, $disabled_functions, true);
    }

    /**
     * Extract BPM from MP3 ID3v2 `TBPM` frame.
     */
    private static function extract_bpm_from_mp3_head($head_bytes)
    {
        $head_bytes = (string) $head_bytes;
        if ($head_bytes === '' || strlen($head_bytes) < 10 || substr($head_bytes, 0, 3) !== 'ID3') {
            return 0;
        }

        $version = ord($head_bytes[3]);
        if ($version !== 3 && $version !== 4) {
            return 0;
        }

        $tag_size = self::synchsafe_to_int(substr($head_bytes, 6, 4));
        if ($tag_size <= 0) {
            return 0;
        }

        $tag_end = min(strlen($head_bytes), 10 + $tag_size);
        $pos = 10;
        while (($pos + 10) <= $tag_end) {
            $frame_id = substr($head_bytes, $pos, 4);
            if ($frame_id === "\x00\x00\x00\x00") {
                break;
            }
            if (!preg_match('/^[A-Z0-9]{4}$/', $frame_id)) {
                break;
            }

            $size_raw = substr($head_bytes, $pos + 4, 4);
            $frame_size = ($version === 4)
                ? self::synchsafe_to_int($size_raw)
                : intval(unpack('N', $size_raw)[1] ?? 0);

            if ($frame_size <= 0) {
                $pos += 10;
                continue;
            }

            $frame_data_pos = $pos + 10;
            $frame_end = $frame_data_pos + $frame_size;
            if ($frame_end > strlen($head_bytes) || $frame_end > $tag_end) {
                break;
            }

            if ($frame_id === 'TBPM') {
                $frame_data = substr($head_bytes, $frame_data_pos, $frame_size);
                $text = self::decode_id3_text_frame($frame_data);
                $bpm = self::parse_bpm_number($text);
                if ($bpm > 0) {
                    return $bpm;
                }
            }

            $pos = $frame_end;
        }

        return 0;
    }

    /**
     * Parse ID3 text frame payload.
     */
    private static function decode_id3_text_frame($frame_data)
    {
        $frame_data = (string) $frame_data;
        if ($frame_data === '') {
            return '';
        }

        $encoding = ord($frame_data[0]);
        $raw = (string) substr($frame_data, 1);
        $text = '';

        if ($encoding === 3) {
            $text = $raw; // UTF-8
        } elseif ($encoding === 1) {
            // UTF-16 with BOM
            if (function_exists('mb_convert_encoding')) {
                $text = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
            }
            if ($text === '' || $text === false) {
                $text = str_replace("\x00", '', $raw);
            }
        } elseif ($encoding === 2) {
            // UTF-16BE without BOM
            if (function_exists('mb_convert_encoding')) {
                $text = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
            }
            if ($text === '' || $text === false) {
                $text = str_replace("\x00", '', $raw);
            }
        } else {
            // ISO-8859-1 / latin1
            if (function_exists('mb_convert_encoding')) {
                $text = @mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            }
            if ($text === '' || $text === false) {
                $text = $raw;
            }
        }

        $text = str_replace("\x00", ' ', (string) $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        return trim((string) $text);
    }

    /**
     * Parse BPM number with safe bounds.
     */
    private static function parse_bpm_number($raw)
    {
        $raw = (string) $raw;
        if (!preg_match('/([0-9]{2,3}(?:\.[0-9]{1,2})?)/', $raw, $m)) {
            return 0;
        }

        $value = floatval($m[1] ?? 0);
        if ($value < 40 || $value > 260) {
            return 0;
        }
        return intval(round($value));
    }

    /**
     * Convert 4-byte synchsafe integer to decimal.
     */
    private static function synchsafe_to_int($raw4)
    {
        $raw4 = (string) $raw4;
        if (strlen($raw4) !== 4) {
            return 0;
        }
        return ((ord($raw4[0]) & 0x7F) << 21)
            | ((ord($raw4[1]) & 0x7F) << 14)
            | ((ord($raw4[2]) & 0x7F) << 7)
            | (ord($raw4[3]) & 0x7F);
    }

    /**
     * Normalize raw key into safe object path.
     */
    private static function normalize_object_key($path)
    {
        $path = sanitize_text_field((string) $path);
        if ($path === '') {
            return '';
        }

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        $path = ltrim((string) $path, '/');

        if ($path === '' || strpos($path, "\0") !== false) {
            return '';
        }

        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '.' || $part === '..') {
                return '';
            }
        }

        return trim((string) $path);
    }

    /**
     * Folder path from object key, no trailing slash, '.' for root compatibility.
     */
    private static function folder_from_path($path)
    {
        $dir = dirname((string) $path);
        if ($dir === '.' || $dir === '/' || $dir === '\\') {
            return '.';
        }

        $dir = str_replace('\\', '/', (string) $dir);
        $dir = trim((string) $dir, '/');
        return $dir === '' ? '.' : $dir;
    }

    /**
     * Normalize text for search (lowercase + accents + punctuation).
     */
    public static function normalize_search_text($value)
    {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }

        $value = mb_strtolower($value);
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }

        $value = str_replace('\\', '/', $value);
        $value = preg_replace('/[^a-z0-9\/._\-]+/u', ' ', (string) $value);
        $value = preg_replace('/[\/_\-.]+/u', ' ', (string) $value);
        $value = preg_replace('/\s+/u', ' ', (string) $value);

        return trim((string) $value);
    }

    private static function tokenize_query($query_norm)
    {
        $tokens = array_filter(explode(' ', trim((string) $query_norm)));
        return array_values(array_unique(array_map('trim', $tokens)));
    }

    private static function normalize_file_name($name)
    {
        $name = sanitize_text_field((string) $name);
        return trim((string) $name);
    }

    private static function compute_depth($folder)
    {
        $folder = trim((string) $folder, '/');
        if ($folder === '' || $folder === '.') {
            return 0;
        }
        return count(array_filter(explode('/', $folder)));
    }

    private static function normalize_last_modified($value)
    {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);
        if (!$ts) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function normalize_etag($value)
    {
        $value = sanitize_text_field((string) $value);
        $value = trim($value, "\"' ");
        if ($value === '') {
            return '';
        }

        return substr($value, 0, 64);
    }

    private static function detect_media_kind($extension)
    {
        $ext = strtolower((string) $extension);
        if (in_array($ext, self::$media_extensions['audio'], true)) {
            return 'audio';
        }
        if (in_array($ext, self::$media_extensions['video'], true)) {
            return 'video';
        }
        return 'other';
    }

    /**
     * Normalize optional BPM range bounds.
     *
     * @return array{0:int|null,1:int|null}
     */
    private static function normalize_bpm_range($bpm_min, $bpm_max)
    {
        $min = null;
        $max = null;

        if ($bpm_min !== null && $bpm_min !== '') {
            $min = max(40, min(260, intval($bpm_min)));
        }
        if ($bpm_max !== null && $bpm_max !== '') {
            $max = max(40, min(260, intval($bpm_max)));
        }

        if ($min !== null && $max !== null && $min > $max) {
            $swap = $min;
            $min = $max;
            $max = $swap;
        }

        return array($min, $max);
    }

    /**
     * Infer BPM from object path/name patterns like "100 Bpm Reggaeton" or "Track 128bpm".
     */
    private static function infer_bpm_from_text($path, $name, $folder)
    {
        $candidates = array(
            strtolower((string) $name),
            strtolower((string) $folder),
            strtolower((string) $path),
        );

        $patterns = array(
            '/(?:^|[^0-9])([4-9][0-9]|1[0-9]{2}|2[0-5][0-9])\s*(?:bpm|tempo)(?:[^a-z0-9]|$)/i',
            '/(?:bpm|tempo)\s*[:\\-]?\s*([4-9][0-9]|1[0-9]{2}|2[0-5][0-9])/i',
        );

        foreach ($candidates as $text) {
            if ($text === '') {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (!preg_match($pattern, $text, $m)) {
                    continue;
                }
                $bpm = intval($m[1] ?? 0);
                if ($bpm >= 40 && $bpm <= 260) {
                    return $bpm;
                }
            }
        }

        return 0;
    }

    /**
     * Resolve final BPM for one object (override first, inference second).
     *
     * @param array<string,mixed>|null $override
     * @return array{bpm:int,source:string}
     */
    private static function resolve_bpm_for_object($path, $name, $folder, $extension, $override = null)
    {
        $media_kind = self::detect_media_kind($extension);
        if ($media_kind !== 'audio') {
            return array('bpm' => 0, 'source' => '');
        }

        if (is_array($override)) {
            $override_bpm = max(0, intval($override['bpm'] ?? 0));
            if ($override_bpm > 0) {
                $source = sanitize_key((string) ($override['source'] ?? 'manual_csv'));
                return array(
                    'bpm' => $override_bpm,
                    'source' => $source !== '' ? $source : 'manual_csv',
                );
            }
        }

        $inferred = self::infer_bpm_from_text($path, $name, $folder);
        if ($inferred > 0) {
            return array(
                'bpm' => $inferred,
                'source' => 'path_pattern',
            );
        }

        return array('bpm' => 0, 'source' => '');
    }

    /**
     * Fetch BPM overrides for a set of path hashes.
     *
     * @param array<int,string> $path_hashes
     * @return array<string,array{bpm:int,source:string}>
     */
    private static function get_bpm_overrides_by_hashes($path_hashes)
    {
        global $wpdb;

        $path_hashes = array_values(array_unique(array_filter(array_map('strval', (array) $path_hashes))));
        if (empty($path_hashes)) {
            return array();
        }

        $table = self::get_bpm_overrides_table_name();
        if (!self::table_exists($table)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($path_hashes), '%s'));
        $sql = "SELECT path_hash, bpm, source
                FROM $table
                WHERE path_hash IN ($placeholders)";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $path_hashes), ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }

        $out = array();
        foreach ($rows as $row) {
            $hash = (string) ($row['path_hash'] ?? '');
            if ($hash === '') {
                continue;
            }
            $out[$hash] = array(
                'bpm' => max(0, intval($row['bpm'] ?? 0)),
                'source' => sanitize_key((string) ($row['source'] ?? 'manual_csv')),
            );
        }

        return $out;
    }

    private static function build_exact_search_where($tokens, $type_filter, $bpm_min = null, $bpm_max = null)
    {
        global $wpdb;

        $clauses = array();
        $params = array();

        foreach ($tokens as $token) {
            $like = '%' . $wpdb->esc_like($token) . '%';
            $clauses[] = "(
                COALESCE(NULLIF(name_norm,''), LOWER(name)) LIKE %s
                OR COALESCE(NULLIF(folder_norm,''), LOWER(folder)) LIKE %s
                OR COALESCE(NULLIF(path_norm,''), LOWER(path)) LIKE %s
            )";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($type_filter === 'audio' || $type_filter === 'video') {
            $exts = self::$media_extensions[$type_filter];
            $quoted_exts = "'" . implode("','", $exts) . "'";
            $clauses[] = "(media_kind = %s OR ((media_kind IS NULL OR media_kind = '') AND extension IN ($quoted_exts)))";
            $params[] = $type_filter;
        }

        list($safe_bpm_min, $safe_bpm_max) = self::normalize_bpm_range($bpm_min, $bpm_max);
        if ($safe_bpm_min !== null || $safe_bpm_max !== null) {
            // BPM filtering implies audio scope.
            $audio_exts = "'" . implode("','", self::$media_extensions['audio']) . "'";
            $clauses[] = "(media_kind = 'audio' OR ((media_kind IS NULL OR media_kind = '') AND extension IN ($audio_exts)))";

            if ($safe_bpm_min !== null && $safe_bpm_max !== null) {
                $clauses[] = '(bpm BETWEEN %d AND %d)';
                $params[] = $safe_bpm_min;
                $params[] = $safe_bpm_max;
            } elseif ($safe_bpm_min !== null) {
                $clauses[] = '(bpm >= %d)';
                $params[] = $safe_bpm_min;
            } else {
                $clauses[] = '(bpm <= %d)';
                $params[] = $safe_bpm_max;
            }
        }

        return array(implode(' AND ', $clauses), $params);
    }

    private static function fetch_fuzzy_candidates($query_norm, $type_filter, $limit, $bpm_min = null, $bpm_max = null)
    {
        global $wpdb;

        $table_name = self::get_table_name('active');
        $tokens = self::tokenize_query($query_norm);
        if (empty($tokens)) {
            return array();
        }

        $prefix = substr($tokens[0], 0, min(5, max(3, strlen($tokens[0]))));
        $like = '%' . $wpdb->esc_like($prefix) . '%';

        $sql = "SELECT path, name, folder, size, extension, path_norm, name_norm, folder_norm, media_kind, bpm, bpm_source
                FROM $table_name
                WHERE (
                    COALESCE(NULLIF(name_norm,''), LOWER(name)) LIKE %s
                    OR COALESCE(NULLIF(folder_norm,''), LOWER(folder)) LIKE %s
                    OR COALESCE(NULLIF(path_norm,''), LOWER(path)) LIKE %s
                )";

        $params = array($like, $like, $like);
        if ($type_filter === 'audio' || $type_filter === 'video') {
            $exts = self::$media_extensions[$type_filter];
            $quoted_exts = "'" . implode("','", $exts) . "'";
            $sql .= " AND (media_kind = %s OR ((media_kind IS NULL OR media_kind = '') AND extension IN ($quoted_exts)))";
            $params[] = $type_filter;
        }

        list($safe_bpm_min, $safe_bpm_max) = self::normalize_bpm_range($bpm_min, $bpm_max);
        if ($safe_bpm_min !== null || $safe_bpm_max !== null) {
            $audio_exts = "'" . implode("','", self::$media_extensions['audio']) . "'";
            $sql .= " AND (media_kind = 'audio' OR ((media_kind IS NULL OR media_kind = '') AND extension IN ($audio_exts)))";

            if ($safe_bpm_min !== null && $safe_bpm_max !== null) {
                $sql .= ' AND bpm BETWEEN %d AND %d';
                $params[] = $safe_bpm_min;
                $params[] = $safe_bpm_max;
            } elseif ($safe_bpm_min !== null) {
                $sql .= ' AND bpm >= %d';
                $params[] = $safe_bpm_min;
            } else {
                $sql .= ' AND bpm <= %d';
                $params[] = $safe_bpm_max;
            }
        }

        $sql .= ' LIMIT %d';
        $params[] = max(50, min(800, intval($limit)));

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    private static function score_rows($rows, $query_norm, $tokens, $allow_fuzzy)
    {
        $scored = array();
        foreach ($rows as $row) {
            $entry = self::score_single_row($row, $query_norm, $tokens, $allow_fuzzy);
            if ($entry === null) {
                continue;
            }
            $scored[] = $entry;
        }

        usort($scored, array(__CLASS__, 'compare_scored_items'));
        return $scored;
    }

    private static function score_single_row($row, $query_norm, $tokens, $allow_fuzzy)
    {
        $name_norm = (string) ($row['name_norm'] ?? '');
        $folder_norm = (string) ($row['folder_norm'] ?? '');
        $path_norm = (string) ($row['path_norm'] ?? '');

        if ($name_norm === '') {
            $name_norm = self::normalize_search_text($row['name'] ?? '');
        }
        if ($folder_norm === '') {
            $folder_norm = self::normalize_search_text($row['folder'] ?? '');
        }
        if ($path_norm === '') {
            $path_norm = self::normalize_search_text($row['path'] ?? '');
        }

        $score = 0;
        $fuzzy_used = false;

        if ($query_norm !== '') {
            if ($name_norm === $query_norm) {
                $score += 260;
            } elseif (strpos($name_norm, $query_norm) === 0) {
                $score += 210;
            } elseif (strpos($name_norm, $query_norm) !== false) {
                $score += 160;
            }
        }

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            $token_hit = false;
            if (strpos($name_norm, $token) !== false) {
                $score += 45;
                $token_hit = true;
            }
            if (strpos($folder_norm, $token) !== false) {
                $score += 25;
                $token_hit = true;
            }
            if (strpos($path_norm, $token) !== false) {
                $score += 15;
                $token_hit = true;
            }

            if (!$token_hit && $allow_fuzzy && strlen($token) >= 5) {
                $matched = self::token_matches_fuzzy($token, $name_norm)
                    || self::token_matches_fuzzy($token, $folder_norm)
                    || self::token_matches_fuzzy($token, $path_norm);

                if ($matched) {
                    $fuzzy_used = true;
                    $score += 8;
                    $token_hit = true;
                }
            }

            if (!$token_hit) {
                return null;
            }
        }

        if ($score <= 0) {
            return null;
        }

        $entry = array(
            'name' => (string) ($row['name'] ?? ''),
            'path' => (string) ($row['path'] ?? ''),
            'size' => max(0, intval($row['size'] ?? 0)),
            'folder' => (string) ($row['folder'] ?? ''),
            'extension' => strtolower((string) ($row['extension'] ?? '')),
            'media_kind' => (string) ($row['media_kind'] ?? self::detect_media_kind($row['extension'] ?? '')),
            'bpm' => max(0, intval($row['bpm'] ?? 0)),
            'bpm_source' => sanitize_key((string) ($row['bpm_source'] ?? '')),
            'score' => $score,
            'match_mode' => $fuzzy_used ? 'fuzzy' : 'exact',
        );

        return $entry;
    }

    private static function token_matches_fuzzy($token, $haystack)
    {
        $token = trim((string) $token);
        $haystack = trim((string) $haystack);
        if ($token === '' || $haystack === '') {
            return false;
        }

        $words = preg_split('/\s+/u', $haystack);
        if (!is_array($words) || empty($words)) {
            return false;
        }

        foreach ($words as $word) {
            $word = trim((string) $word);
            if ($word === '' || abs(strlen($word) - strlen($token)) > 1) {
                continue;
            }
            if (function_exists('levenshtein') && levenshtein($token, $word) <= 1) {
                return true;
            }
        }

        return false;
    }

    private static function compare_scored_items($a, $b)
    {
        $sa = intval($a['score'] ?? 0);
        $sb = intval($b['score'] ?? 0);
        if ($sa !== $sb) {
            return $sb <=> $sa;
        }

        $na = strtolower((string) ($a['name'] ?? ''));
        $nb = strtolower((string) ($b['name'] ?? ''));
        if ($na !== $nb) {
            return strcmp($na, $nb);
        }

        $pa = strtolower((string) ($a['path'] ?? ''));
        $pb = strtolower((string) ($b['path'] ?? ''));
        return strcmp($pa, $pb);
    }

    private static function build_suggestions($query_norm, $type_filter, $limit, $bpm_min = null, $bpm_max = null)
    {
        $rows = self::fetch_fuzzy_candidates($query_norm, $type_filter, 120, $bpm_min, $bpm_max);
        if (empty($rows)) {
            return array();
        }

        $seen = array();
        $suggestions = array();
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $suggestions[] = $name;
            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Import one CSV row into persistent BPM overrides table.
     *
     * @return string|bool 'ok'|'missing'|'invalid' or bool for legacy internal calls
     */
    private static function import_bpm_csv_row($table, $row, $path_col, $bpm_col, $now, $labeled = false)
    {
        global $wpdb;

        $raw_path = isset($row[$path_col]) ? (string) $row[$path_col] : '';
        $raw_bpm = isset($row[$bpm_col]) ? (string) $row[$bpm_col] : '';
        $path = self::normalize_object_key($raw_path);
        if ($path === '' || substr($path, -1) === '/') {
            return $labeled ? 'invalid' : false;
        }

        if (!preg_match('/([0-9]{2,3})/', $raw_bpm, $m)) {
            return $labeled ? 'invalid' : false;
        }

        $bpm = intval($m[1]);
        if ($bpm < 40 || $bpm > 260) {
            return $labeled ? 'invalid' : false;
        }

        $replaced = $wpdb->replace(
            $table,
            array(
                'path_hash' => md5($path),
                'path' => $path,
                'bpm' => $bpm,
                'source' => 'manual_csv',
                'updated_at' => $now,
            ),
            array('%s', '%s', '%d', '%s', '%s')
        );

        if ($replaced === false) {
            return $labeled ? 'missing' : false;
        }

        return $labeled ? 'ok' : true;
    }

    /**
     * Apply all persistent BPM overrides to both index tables.
     *
     * @return array{primary:int,shadow:int}
     */
    private static function apply_bpm_overrides_to_indexes()
    {
        global $wpdb;

        $overrides = self::get_bpm_overrides_table_name();
        if (!self::table_exists($overrides)) {
            return array('primary' => 0, 'shadow' => 0);
        }

        $counts = array('primary' => 0, 'shadow' => 0);
        foreach (array('primary', 'shadow') as $alias) {
            $index_table = self::get_table_name($alias);
            if (!self::table_exists($index_table)) {
                continue;
            }

            if (!self::is_sqlite_db()) {
                $sql = "UPDATE $index_table idx
                        INNER JOIN $overrides ovr ON ovr.path_hash = idx.path_hash
                        SET idx.bpm = ovr.bpm,
                            idx.bpm_source = ovr.source";
                $affected = $wpdb->query($sql);
            } else {
                $sql = "UPDATE $index_table
                        SET bpm = COALESCE((SELECT ovr.bpm FROM $overrides ovr WHERE ovr.path_hash = $index_table.path_hash), bpm),
                            bpm_source = COALESCE((SELECT ovr.source FROM $overrides ovr WHERE ovr.path_hash = $index_table.path_hash), bpm_source)
                        WHERE EXISTS (SELECT 1 FROM $overrides ovr WHERE ovr.path_hash = $index_table.path_hash)";
                $affected = $wpdb->query($sql);
            }

            $counts[$alias] = max(0, intval($affected));
        }

        return $counts;
    }

    /**
     * Create and persist a new desktop API token (returns plaintext once).
     */
    private static function issue_desktop_token()
    {
        $token = 'jpsm_' . bin2hex(random_bytes(24));
        $hash = wp_hash_password($token);
        update_option(self::DESKTOP_TOKEN_HASH_OPTION, $hash, false);
        update_option(self::DESKTOP_TOKEN_CREATED_AT_OPTION, current_time('mysql'), false);
        update_option(self::DESKTOP_TOKEN_LAST_USED_OPTION, '', false);
        return $token;
    }

    /**
     * Verify desktop API bearer token.
     */
    private static function verify_desktop_token($token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return false;
        }

        $hash = (string) get_option(self::DESKTOP_TOKEN_HASH_OPTION, '');
        if ($hash === '') {
            return false;
        }

        if (!wp_check_password($token, $hash)) {
            return false;
        }

        update_option(self::DESKTOP_TOKEN_LAST_USED_OPTION, current_time('mysql'), false);
        return true;
    }

    /**
     * Extract bearer token from Authorization header.
     */
    private static function get_bearer_token_from_request()
    {
        $header = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string) wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        } elseif (!empty($_SERVER['Authorization'])) {
            $header = (string) wp_unslash($_SERVER['Authorization']);
        } elseif (function_exists('getallheaders')) {
            $all_headers = getallheaders();
            if (is_array($all_headers)) {
                foreach ($all_headers as $k => $v) {
                    if (strtolower((string) $k) === 'authorization' && is_string($v) && $v !== '') {
                        $header = $v;
                        break;
                    }
                }
            }
        }

        if ($header === '') {
            return '';
        }

        if (stripos($header, 'Bearer ') !== 0) {
            return '';
        }
        return trim(substr($header, 7));
    }

    /**
     * Read JSON body for desktop API endpoints.
     *
     * @return array<string,mixed>|WP_Error
     */
    private static function read_json_request_body()
    {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return new WP_Error('invalid_json', 'No se pudo parsear JSON.');
        }

        if (!empty($_POST) && is_array($_POST)) {
            $post = wp_unslash($_POST);
            if (!empty($post['payload_json']) && is_string($post['payload_json'])) {
                $decoded_payload = json_decode($post['payload_json'], true);
                if (is_array($decoded_payload)) {
                    return $decoded_payload;
                }
                return new WP_Error('invalid_json', 'payload_json inválido.');
            }

            return $post;
        }

        return new WP_Error('empty_json', 'Request body vacío.');
    }

    /**
     * Normalize desktop ingestion profile.
     */
    private static function normalize_desktop_profile($profile)
    {
        $profile = sanitize_key((string) $profile);
        $allowed = array('fast', 'balanced', 'max_coverage');
        if (!in_array($profile, $allowed, true)) {
            return 'balanced';
        }
        return $profile;
    }

    /**
     * Get one BPM batch row by batch_id.
     *
     * @return array<string,mixed>|null
     */
    private static function get_bpm_batch($batch_id)
    {
        global $wpdb;

        $batch_id = sanitize_text_field((string) $batch_id);
        if ($batch_id === '') {
            return null;
        }

        $table = self::get_bpm_batches_table_name();
        if (!self::table_exists($table)) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT batch_id, payload_hash, profile, status, created_at, rolled_back_at
                 FROM $table
                 WHERE batch_id = %s
                 LIMIT 1",
                $batch_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Insert BPM batch summary row.
     */
    private static function insert_bpm_batch($batch_id, $payload_hash, $profile, $status, $metrics, $created_by)
    {
        global $wpdb;

        $table = self::get_bpm_batches_table_name();
        if (!self::table_exists($table)) {
            return;
        }

        $wpdb->insert(
            $table,
            array(
                'batch_id' => sanitize_text_field((string) $batch_id),
                'payload_hash' => sanitize_text_field((string) $payload_hash),
                'profile' => self::normalize_desktop_profile($profile),
                'status' => sanitize_key((string) $status),
                'metrics_json' => wp_json_encode(is_array($metrics) ? $metrics : array()),
                'created_by' => sanitize_text_field((string) $created_by),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Update BPM batch status/metrics.
     */
    private static function update_bpm_batch_status($batch_id, $status, $metrics = array(), $mark_rolled_back = false)
    {
        global $wpdb;

        $batch_id = sanitize_text_field((string) $batch_id);
        if ($batch_id === '') {
            return;
        }

        $table = self::get_bpm_batches_table_name();
        if (!self::table_exists($table)) {
            return;
        }

        $data = array(
            'status' => sanitize_key((string) $status),
            'metrics_json' => wp_json_encode(is_array($metrics) ? $metrics : array()),
        );
        $formats = array('%s', '%s');
        if ($mark_rolled_back) {
            $data['rolled_back_at'] = current_time('mysql');
            $formats[] = '%s';
        }

        $wpdb->update(
            $table,
            $data,
            array('batch_id' => $batch_id),
            $formats,
            array('%s')
        );
    }

    /**
     * Insert one row-level audit entry.
     */
    private static function insert_bpm_batch_row($batch_id, $path_hash, $path, $old_bpm, $new_bpm, $old_source, $new_source, $confidence = null)
    {
        global $wpdb;

        $table = self::get_bpm_batch_rows_table_name();
        if (!self::table_exists($table)) {
            return;
        }

        $confidence_value = ($confidence === null)
            ? 0.0
            : max(0.0, min(1.0, floatval($confidence)));

        $data = array(
            'batch_id' => sanitize_text_field((string) $batch_id),
            'path_hash' => trim((string) $path_hash),
            'path' => self::normalize_object_key((string) $path),
            'old_bpm' => max(0, intval($old_bpm)),
            'new_bpm' => max(0, intval($new_bpm)),
            'old_source' => sanitize_key((string) $old_source),
            'new_source' => sanitize_key((string) $new_source),
            'confidence' => $confidence_value,
            'applied_at' => current_time('mysql'),
        );
        $formats = array('%s', '%s', '%s', '%d', '%d', '%s', '%s', '%f', '%s');

        $wpdb->insert($table, $data, $formats);
    }

    /**
     * Fetch row-level audit for one batch.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function get_bpm_batch_rows($batch_id)
    {
        global $wpdb;

        $batch_id = sanitize_text_field((string) $batch_id);
        if ($batch_id === '') {
            return array();
        }

        $table = self::get_bpm_batch_rows_table_name();
        if (!self::table_exists($table)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT path_hash, path, old_bpm, new_bpm, old_source, new_source, confidence
                 FROM $table
                 WHERE batch_id = %s
                 ORDER BY id ASC",
                $batch_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    private static function generate_sync_id()
    {
        if (function_exists('wp_generate_uuid4')) {
            return (string) wp_generate_uuid4();
        }
        return 'sync_' . substr(md5(uniqid('', true)), 0, 24);
    }

    private static function list_objects_page_with_retry($s3, $prefix, $continuation_token, $max_keys)
    {
        $max_attempts = 3;
        $attempt = 0;
        $last_error = null;

        while ($attempt < $max_attempts) {
            $attempt++;
            $result = $s3->list_objects_page($prefix, $continuation_token, $max_keys);
            if (!is_wp_error($result)) {
                return $result;
            }

            $last_error = $result;
            if ($attempt < $max_attempts) {
                usleep($attempt * 250000);
            }
        }

        return $last_error ?: new WP_Error('sync_failed', 'Unknown sync error');
    }

    private static function table_exists($table_name)
    {
        global $wpdb;

        if (self::is_sqlite_db()) {
            $sqlite = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name = %s",
                    $table_name
                )
            );
            return $sqlite === $table_name;
        }

        $mysql = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        return $mysql === $table_name;
    }

    private static function truncate_table($table_name)
    {
        global $wpdb;

        $result = $wpdb->query("TRUNCATE TABLE $table_name");
        if ($result === false) {
            $result = $wpdb->query("DELETE FROM $table_name");
        }
        return $result;
    }

    private static function get_sync_state()
    {
        $state = get_option(self::SYNC_STATE_OPTION, array());
        return is_array($state) ? $state : array();
    }

    private static function set_sync_state($state)
    {
        if (!is_array($state)) {
            $state = array();
        }
        update_option(self::SYNC_STATE_OPTION, $state);
    }

    private static function is_sqlite_db()
    {
        global $wpdb;

        $info = '';
        if (method_exists($wpdb, 'db_server_info')) {
            $info = (string) $wpdb->db_server_info();
        }
        if ($info !== '' && stripos($info, 'sqlite') !== false) {
            return true;
        }

        if (isset($wpdb->is_mysql) && $wpdb->is_mysql === false) {
            return true;
        }

        return false;
    }
}
