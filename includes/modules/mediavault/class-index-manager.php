<?php
/**
 * MediaVault Index Manager
 * 
 * Manages a local WordPress database index of S3 files
 * to enable fast global search without timeout issues.
 * 
 * @package JetPack Store Manager
 */

if (!defined('ABSPATH'))
    exit;

class JPSM_Index_Manager
{

    private static $table_name = 'jpsm_mediavault_index';

    /**
     * Get the full table name with WordPress prefix
     */
    public static function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . self::$table_name;
    }

    /**
     * Create or update the database table
     */
    public static function create_table()
    {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            path VARCHAR(500) NOT NULL,
            name VARCHAR(255) NOT NULL,
            folder VARCHAR(500) NOT NULL,
            size BIGINT(20) UNSIGNED DEFAULT 0,
            extension VARCHAR(20) DEFAULT '',
            synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY path (path(191)),
            KEY name (name(100)),
            KEY folder (folder(100)),
            KEY extension (extension)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Store table version for future upgrades
        update_option('jpsm_mediavault_index_version', '1.0');
    }

    /**
     * Sync files from S3 to local index in batches
     * 
     * @param string|null $continuation_token Token for pagination
     * @return array|WP_Error Stats on success
     */
    public static function sync_batch($continuation_token = null)
    {
        global $wpdb;

        // Check/Load Dependencies
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

        // Initialize logging
        $batch_id = substr(md5(microtime()), 0, 6);
        error_log("[MediaVault] Sync Batch $batch_id START. Token: " . ($continuation_token ? 'YES' : 'NO'));

        // First batch: Prepare table
        if (empty($continuation_token)) {
            error_log("[MediaVault] Clearing index table for fresh sync.");
            self::clear_index();
            update_option('jpsm_mediavault_last_sync', current_time('mysql'));
        }

        // Fetch page from S3
        $result = $s3->list_objects_page('', $continuation_token);

        if (is_wp_error($result)) {
            error_log("[MediaVault] Sync Batch $batch_id ERROR: " . $result->get_error_message());
            return $result;
        }

        $files = $result['files'];
        $next_token = $result['next_token'];
        $table_name = self::get_table_name();
        $synced_at = current_time('mysql');
        $count = 0;
        $errors = 0;

        error_log("[MediaVault] Sync Batch $batch_id: Retrieved " . count($files) . " files from S3.");

        // Insert batch
        foreach ($files as $file) {
            $path = $file['path'];
            $name = $file['name'];
            $size = isset($file['size']) ? $file['size'] : 0;
            $folder = dirname($path);
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            $insert = $wpdb->insert(
                $table_name,
                [
                    'path' => $path,
                    'name' => $name,
                    'folder' => $folder,
                    'size' => $size,
                    'extension' => $extension,
                    'synced_at' => $synced_at
                ],
                ['%s', '%s', '%s', '%d', '%s', '%s']
            );

            if ($insert !== false) {
                $count++;
            } else {
                $errors++;
            }
        }

        error_log("[MediaVault] Sync Batch $batch_id: Inserted $count files. Errors: $errors. Next Token: " . ($next_token ? 'YES' : 'NONE'));

        return [
            'success' => true,
            'count' => $count,
            'errors' => $errors,
            'next_token' => $next_token,
            'finished' => empty($next_token)
        ];
    }

    /**
     * Deprecated: Use sync_batch instead
     */
    /**
     * Performs a full sync from S3 (Loops through all batches)
     * Used by Cron Job
     */
    public static function sync_from_s3()
    {
        // Increase time limit for full sync
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $token = null;
        $total_count = 0;
        $total_errors = 0;

        do {
            $result = self::sync_batch($token);

            if (is_wp_error($result)) {
                return $result;
            }

            $total_count += $result['count'];
            $total_errors += $result['errors'];
            $token = $result['next_token'];

            // Small pause to be nice to the server (100ms)
            usleep(100000);

        } while (!empty($token));

        return [
            'success' => true,
            'synced' => $total_count,
            'errors' => $total_errors
        ];
    }

    /**
     * Search the local index
     * 
     * @param string $query Search query (space-separated tokens)
     * @param string|null $type_filter 'audio', 'video', or null for all
     * @param int $limit Maximum results to return
     * @return array Array of matching files
     */
    public static function search($query, $type_filter = null, $limit = 100)
    {
        global $wpdb;

        $table_name = self::get_table_name();

        // Tokenize query
        $tokens = array_filter(explode(' ', strtolower(trim($query))));

        if (empty($tokens)) {
            return [];
        }

        // Build WHERE clause for token matching
        $where_clauses = [];
        $params = [];

        foreach ($tokens as $token) {
            $where_clauses[] = "LOWER(name) LIKE %s";
            $params[] = '%' . $wpdb->esc_like($token) . '%';
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Add type filter
        if ($type_filter === 'audio') {
            $where_sql .= " AND extension IN ('mp3', 'wav', 'flac', 'm4a', 'ogg', 'aac')";
        } elseif ($type_filter === 'video') {
            $where_sql .= " AND extension IN ('mp4', 'mov', 'mkv', 'avi', 'webm', 'wmv')";
        }

        $sql = $wpdb->prepare(
            "SELECT path, name, folder, size, extension 
             FROM $table_name 
             WHERE $where_sql 
             ORDER BY name ASC 
             LIMIT %d",
            array_merge($params, [$limit])
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        return $results ? $results : [];
    }

    /**
     * Get index statistics
     * 
     * @return array Stats including count and last sync time
     */
    public static function get_stats()
    {
        global $wpdb;

        $table_name = self::get_table_name();

        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $last_sync = get_option('jpsm_mediavault_last_sync', null);

        // Get breakdown by type
        $audio_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name 
             WHERE extension IN ('mp3', 'wav', 'flac', 'm4a', 'ogg', 'aac')"
        );
        $video_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name 
             WHERE extension IN ('mp4', 'mov', 'mkv', 'avi', 'webm', 'wmv')"
        );

        return [
            'total' => (int) $count,
            'audio' => (int) $audio_count,
            'video' => (int) $video_count,
            'last_sync' => $last_sync,
            'table_exists' => $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name
        ];
    }

    /**
     * Clear the entire index
     * 
     * @return int|false Number of rows deleted or false on error
     */
    public static function clear_index()
    {
        global $wpdb;

        $table_name = self::get_table_name();
        $result = $wpdb->query("TRUNCATE TABLE $table_name");

        delete_option('jpsm_mediavault_last_sync');

        return $result;
    }

    /**
     * Check if index is stale (older than 6 hours)
     * 
     * @return bool True if index needs refresh
     */
    public static function is_stale()
    {
        $last_sync = get_option('jpsm_mediavault_last_sync', null);

        if (!$last_sync) {
            return true;
        }

        $last_sync_time = strtotime($last_sync);
        $six_hours_ago = time() - (6 * 60 * 60);

        return $last_sync_time < $six_hours_ago;
    }

    /**
     * Get MediaVault index statistics for the Manager (AJAX)
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

        // Check if sync is running
        $sync_status = 'idle'; // TODO: Implement robust lock check if needed
        $stats['sync_status'] = $sync_status;

        JPSM_API_Contract::send_success($stats, 'index_stats_fetched', 'Estadísticas del índice cargadas.', 200);
    }

    /**
     * Trigger MediaVault index sync from the Manager (AJAX)
     */
    public static function sync_mediavault_index_ajax()
    {
        // Increase resources for sync - preventing 500 errors
        @ini_set('display_errors', 0);
        @set_time_limit(600); // 10 minutes
        @ini_set('memory_limit', '512M'); // 512MB RAM

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

            // Get token for batch processing
            $next_token = isset($_POST['next_token']) ? sanitize_text_field(wp_unslash($_POST['next_token'])) : null;

            $result = self::sync_batch($next_token);

            if (is_wp_error($result)) {
                JPSM_API_Contract::send_wp_error($result, 'Error de sincronización', 'index_sync_failed', 502);
            } elseif (isset($result['success']) && !$result['success']) {
                JPSM_API_Contract::send_error($result['error'], 'index_sync_failed', 500, $result);
            } else {
                JPSM_API_Contract::send_success($result, 'index_sync_batch_completed', 'Lote de sincronización procesado.', 200);
            }

        } catch (Exception $e) {
            error_log('MediaVault Sync Exception: ' . $e->getMessage());
            JPSM_API_Contract::send_error('Server Error: ' . $e->getMessage(), 'server_error', 500);
        } catch (Error $e) {
            error_log('MediaVault Sync Fatal Error: ' . $e->getMessage());
            JPSM_API_Contract::send_error('Fatal Error: ' . $e->getMessage(), 'fatal_error', 500);
        }
    }
}
