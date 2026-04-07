<?php
/**
 * MediaVault Frontend Template (v2.0 - Premium UI)
 * Renders the File Browser UI with "Spotify-style" aesthetics and features.
 */

if (!defined('ABSPATH'))
    exit;

class JPSM_MediaVault_UI
{
    /**
     * Late-render templates cannot reliably set cookies once HTML output starts.
     * We run login/guest/logout flows before output and store any error here for the form.
     *
     * @var string
     */
    private static $login_error = '';
    private static $index_object_size_cache = null;

    /**
     * Handle actions that must run before any HTML is output (cookies/redirects).
     */
    private static function handle_pre_output_requests()
    {
        if (!class_exists('JPSM_Access_Manager')) {
            require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
        }

        // Guest access URL trigger: set session cookie then redirect to clean URL.
        if (isset($_GET['invitado']) && $_GET['invitado'] == '1') {
            $guest_id = 'invitado_' . uniqid() . '@example.invalid';
            JPSM_Access_Manager::set_access_cookie($guest_id);
            wp_safe_redirect(remove_query_arg('invitado'));
            exit;
        }

        // Logout: clear cookie then redirect back.
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

        // Login POST: set cookie and redirect (PRG).
        if (isset($_POST['jdd_login']) && isset($_POST['jdd_email'])) {
            $email = sanitize_email(wp_unslash($_POST['jdd_email']));
            if (empty($email) || !is_email($email)) {
                self::$login_error = 'Por favor ingresa un correo válido.';
                return;
            }

            $ok = JPSM_Access_Manager::set_access_cookie($email);
            if (!$ok) {
                self::$login_error = 'No se pudo iniciar sesión. Intenta de nuevo.';
                return;
            }

            $redirect_to = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
            wp_safe_redirect($redirect_to ? $redirect_to : home_url('/'));
            exit;
        }
    }
    /**
     * Cache folder structure using WordPress Transients to avoid redundant S3 calls
     * Limited to folders with < 500 items to prevent memory issues
     * 
     * @param string $folder The folder path to list
     * @param JPSM_S3_Client $s3 The S3 client instance
     * @param int $ttl Time-to-live in seconds (default: 5 minutes)
     * @return array|WP_Error Folder contents or error
     */
    private static function get_cached_folder_structure($folder, $s3, $ttl = 300)
    {
        $cache_key = 'mv_folder_' . md5($folder);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $data = $s3->list_objects($folder);

        // Only cache if response is valid and not too large (< 500 items)
        $item_count = 0;
        if (is_array($data)) {
            $item_count = count($data['folders'] ?? []) + count($data['files'] ?? []);
        }
        if (!is_wp_error($data) && is_array($data) && $item_count < 500) {
            set_transient($cache_key, $data, $ttl);
        }

        return $data;
    }

    /**
     * Fast folder listing for UI browsing:
     * - Prefer the local index (DB) when available.
     * - Fallback to cached S3 listing when index is missing/empty.
     */
    private static function get_folder_structure($folder, $s3, $ttl = 300)
    {
        if (class_exists('JPSM_Index_Manager') && method_exists('JPSM_Index_Manager', 'has_index_data') && JPSM_Index_Manager::has_index_data()) {
            $idx = JPSM_Index_Manager::list_folder_structure($folder);
            if (is_array($idx) && isset($idx['folders']) && isset($idx['files'])) {
                return $idx;
            }
        }

        return self::get_cached_folder_structure($folder, $s3, $ttl);
    }

    /**
     * Clear all folder cache transients (call after index sync)
     */
    public static function clear_folder_cache()
    {
        global $wpdb;
        // Delete all transients that start with 'mv_folder_'
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mv_folder_%' OR option_name LIKE '_transient_timeout_mv_folder_%'"
        );
    }

    /**
     * Normalize an S3/B2 object key or folder path into a safe, comparable string.
     *
     * - Removes leading slashes.
     * - Converts backslashes to forward slashes.
     * - Rejects traversal-like segments ('.' / '..') to avoid permission bypass tricks.
     *
     * @return string Normalized key, or empty string if invalid.
     */
    private static function normalize_object_key($key)
    {
        $key = is_string($key) ? $key : (string) $key;
        $key = trim($key);
        if ($key === '') {
            return '';
        }

        if (strpos($key, "\0") !== false) {
            return '';
        }

        $key = str_replace('\\', '/', $key);
        $key = ltrim($key, '/');

        $parts = explode('/', $key);
        foreach ($parts as $part) {
            if ($part === '' || $part === null) {
                continue;
            }
            if ($part === '.' || $part === '..') {
                return '';
            }
        }

        return $key;
    }

    /**
     * Folder path (with trailing slash) for an object key.
     *
     * @return string Folder path ("" for bucket root).
     */
    private static function folder_from_object_key($object_key)
    {
        $object_key = self::normalize_object_key($object_key);
        if ($object_key === '') {
            return '';
        }

        $dir = dirname($object_key);
        if ($dir === '.' || $dir === DIRECTORY_SEPARATOR) {
            return '';
        }

        $dir = str_replace('\\', '/', $dir);
        $dir = trim($dir, '/');
        return ($dir === '') ? '' : ($dir . '/');
    }

    /**
     * Sanitize optional BPM bounds from request values.
     *
     * @return int|null
     */
    private static function sanitize_bpm_bound($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $num = intval($value);
        if ($num < 40 || $num > 260) {
            return null;
        }

        return $num;
    }

    /**
     * Best-effort size lookup from local MediaVault index.
     * Returns 0 when index is unavailable or the file isn't indexed.
     */
    private static function get_indexed_object_size($object_key)
    {
        global $wpdb;
        $object_key = self::normalize_object_key($object_key);
        if ($object_key === '') {
            return 0;
        }

        if (!is_array(self::$index_object_size_cache)) {
            self::$index_object_size_cache = array();
        }
        if (isset(self::$index_object_size_cache[$object_key])) {
            return max(0, intval(self::$index_object_size_cache[$object_key]));
        }

        $table = $wpdb->prefix . 'jpsm_mediavault_index';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ((string) $table_exists !== (string) $table) {
            self::$index_object_size_cache[$object_key] = 0;
            return 0;
        }

        $size = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT size FROM $table WHERE path = %s LIMIT 1",
                $object_key
            )
        );
        $size = max(0, intval($size));
        self::$index_object_size_cache[$object_key] = $size;
        return $size;
    }

    /**
     * Byte cap for proxy previews (locked/demo).
     *
     * This prevents "preview" actions from becoming full downloads when the user
     * is not entitled to download the object.
     *
     * @return int
     */
    private static function preview_max_bytes_for_path($object_key)
    {
        $object_key = self::normalize_object_key($object_key);
        $ext = strtolower((string) pathinfo($object_key, PATHINFO_EXTENSION));

        // Conservative defaults (tunable via filter).
        $bytes = 5 * 1024 * 1024; // 5MB default
        if (in_array($ext, array('mp3', 'wav', 'flac', 'm4a', 'aac', 'ogg'), true)) {
            $bytes = 5 * 1024 * 1024; // audio
        } elseif (in_array($ext, array('mp4', 'mov', 'mkv', 'avi', 'webm'), true)) {
            $bytes = 50 * 1024 * 1024; // video
        } elseif (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)) {
            $bytes = 2 * 1024 * 1024; // images
        }

        if (function_exists('apply_filters')) {
            $bytes = (int) apply_filters('jpsm_mediavault_preview_max_bytes', $bytes, $ext, $object_key);
        }

        return max(1024, (int) $bytes);
    }

    /**
     * Track aggregated mobile notice interactions (no PII).
     *
     * @param string $event Allowed: shown, dismissed, continue_anyway
     * @return array|WP_Error
     */
    private static function track_mobile_notice_event($event)
    {
        $event = sanitize_key((string) $event);
        $allowed_events = array('shown', 'dismissed', 'continue_anyway');
        if (!in_array($event, $allowed_events, true)) {
            return new WP_Error('invalid_event', 'Evento inválido');
        }

        $option_key = 'jpsm_mv_mobile_notice_stats';
        $stats = get_option($option_key, array());
        if (!is_array($stats)) {
            $stats = array();
        }

        $day = (string) current_time('Y-m-d');
        if (!isset($stats[$day]) || !is_array($stats[$day])) {
            $stats[$day] = array();
        }

        $current = intval($stats[$day][$event] ?? 0);
        $stats[$day][$event] = max(0, $current) + 1;

        // Keep a bounded rolling window to avoid unbounded option growth.
        ksort($stats);
        if (count($stats) > 90) {
            $stats = array_slice($stats, -90, null, true);
        }

        update_option($option_key, $stats, false);

        return array(
            'day' => $day,
            'event' => $event,
            'count' => intval($stats[$day][$event] ?? 0),
        );
    }

    /**
     * Passive behavior tracking wrapper (never blocks product flows).
     */
    private static function track_behavior_event_passive($event_name, $fields = array())
    {
        if (!class_exists('JPSM_Behavior_Service')) {
            return;
        }

        try {
            $payload = is_array($fields) ? $fields : array();
            $payload['source_screen'] = 'mediavault_vault';
            JPSM_Behavior_Service::track_event_passive($event_name, $payload);
        } catch (Throwable $t) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MediaVault] Behavior tracking failed: ' . $t->getMessage());
            }
        }
    }

    /**
     * Frontend floating admin panel is disabled by default in [mediavault_vault].
     * This avoids exposing privileged UX/actions in customer-facing pages.
     */
    private static function is_frontend_admin_panel_enabled()
    {
        $enabled = false;
        if (function_exists('apply_filters')) {
            $enabled = (bool) apply_filters('jpsm_mediavault_frontend_admin_panel_enabled', $enabled);
        }
        return $enabled;
    }

    public static function handle_ajax()
    {
        $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
        $is_mv_ajax_payload = isset($_GET['mv_ajax']) && $_GET['mv_ajax'] == '1';
        $nonce_required_actions = array(
            'mv_list_folder',
            'mv_search_global',
            'mv_sync_index',
            'mv_index_stats',
            'mv_get_presigned_url',
            'mv_get_preview_url',
            'mv_track_mobile_notice_event',
            'mv_get_user_meta',
            'mv_update_tier',
            'mv_get_folders',
            'mv_update_folder',
            'mv_get_leads',
        );

        if (($is_mv_ajax_payload || in_array($action, $nonce_required_actions, true)) && class_exists('JPSM_Auth')) {
            $nonce_ok = JPSM_Auth::validate_request_nonce(array('jpsm_nonce', 'jpsm_mediavault_nonce', 'jpsm_access_nonce'));
            if (!$nonce_ok) {
                JPSM_API_Contract::send_error('Invalid nonce');
            }

            // These responses are user/session scoped (nonces, tiers, signed URLs). Prevent caching.
            if (function_exists('nocache_headers')) {
                nocache_headers();
            }
        }

        $frontend_admin_actions = array(
            'mv_sync_index',
            'mv_index_stats',
            'mv_get_user_meta',
            'mv_update_tier',
            'mv_get_folders',
            'mv_update_folder',
            'mv_get_leads',
        );
        if (!self::is_frontend_admin_panel_enabled() && in_array($action, $frontend_admin_actions, true)) {
            JPSM_API_Contract::send_error('No autorizado', 'forbidden', 403);
        }

        if (isset($_GET['action']) && $_GET['action'] === 'mv_list_folder') {
            // Verify Access first
            if (!class_exists('JPSM_Access_Manager')) {
                require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
            }
            if (!JPSM_Access_Manager::check_current_session()) {
                JPSM_API_Contract::send_error('Sesión no válida');
            }

            // Folder downloads return presigned URLs; prevent caching at browser/CDN layers.
            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            $s3 = new JPSM_S3_Client(
                JPSM_B2_KEY_ID,
                JPSM_B2_APP_KEY,
                JPSM_B2_REGION,
                JPSM_B2_BUCKET
            );

            $target_folder = isset($_GET['folder']) ? sanitize_text_field(wp_unslash($_GET['folder'])) : '';
            $target_folder = self::normalize_object_key($target_folder);
            if ($target_folder === '' && isset($_GET['folder']) && (string) $_GET['folder'] !== '') {
                JPSM_API_Contract::send_error('Carpeta inválida.');
            }

            // Ensure prefix ends with / for correct listing
            if ($target_folder && substr($target_folder, -1) !== '/') {
                $target_folder .= '/';
            }

            // --- SECURITY CHECK ---
            // This endpoint is used to download a folder (returns a file list + signed URLs).
            // Viewing/browsing is intentionally open for all tiers (conversion funnel),
            // but downloads must respect tier + folder permissions.
            $current_email = JPSM_Access_Manager::get_current_email();
            $user_tier = $current_email ? JPSM_Access_Manager::get_user_tier($current_email) : 0; // Default to Demo (0)

            // Demo users can browse/preview but cannot download.
            if ($user_tier <= 0) {
                self::track_behavior_event_passive('download_folder_denied', array(
                    'object_type' => 'folder',
                    'object_path_norm' => $target_folder,
                    'status' => 'denied',
                    'meta' => array('error_code' => 'requires_premium'),
                ));
                JPSM_API_Contract::send_error('🔒 Descarga disponible solo en plan Premium.');
            }

            if (!JPSM_Access_Manager::user_can_access($target_folder, $user_tier)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("[MediaVault] Download denied (tier={$user_tier}) to folder: {$target_folder}");
                }
                self::track_behavior_event_passive('download_folder_denied', array(
                    'object_type' => 'folder',
                    'object_path_norm' => $target_folder,
                    'status' => 'denied',
                    'meta' => array('error_code' => 'access_denied'),
                ));
                JPSM_API_Contract::send_error('⛔ Acceso Denegado: No tienes permiso para descargar esta carpeta.');
            }
            // ----------------------

            $files_list = $s3->list_objects_recursive($target_folder);

            if (is_wp_error($files_list)) {
                self::track_behavior_event_passive('download_folder_denied', array(
                    'object_type' => 'folder',
                    'object_path_norm' => $target_folder,
                    'status' => 'error',
                    'meta' => array('error_code' => 'list_failed'),
                ));
                JPSM_API_Contract::send_error($files_list->get_error_message());
            }

            $response = [];
            $bytes_authorized_total = 0;

            foreach ($files_list as $f) {
                // Defense-in-depth: recursive download must not include files from locked subfolders.
                $file_folder = self::folder_from_object_key($f['path']);
                if ($file_folder !== '' && !JPSM_Access_Manager::user_can_access($file_folder, $user_tier)) {
                    continue;
                }

                $download_url = $s3->get_presigned_url($f['path']);
                if (class_exists('JPSM_Config')) {
                    $download_url = JPSM_Config::rewrite_download_url_for_cloudflare((string) $download_url);
                }

                $response[] = [
                    'name' => $f['name'],
                    'path' => $f['path'],
                    'size' => max(0, intval($f['size'] ?? 0)),
                    'url' => $download_url,
                ];
                $bytes_authorized_total += max(0, intval($f['size'] ?? 0));
            }

            // Passive analytics: register successful folder-download intent.
            // This must never block the actual download response.
            if (!empty($response) && class_exists('JPSM_Data_Layer')) {
                try {
                    JPSM_Data_Layer::record_folder_download_event($target_folder);
                } catch (Throwable $t) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[MediaVault] Folder download analytics failed: ' . $t->getMessage());
                    }
                }
            }

            self::track_behavior_event_passive('download_folder_granted', array(
                'object_type' => 'folder',
                'object_path_norm' => $target_folder,
                'status' => 'success',
                'files_count' => count($response),
                'bytes_authorized' => max(0, intval($bytes_authorized_total)),
            ));

            JPSM_API_Contract::send_success($response);
            exit;
        }

        // Global Search Endpoint (uses local index for speed)
        if (isset($_GET['action']) && $_GET['action'] === 'mv_search_global') {
            try {
                if (!class_exists('JPSM_Access_Manager')) {
                    require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
                }
                if (!JPSM_Access_Manager::check_current_session()) {
                    JPSM_API_Contract::send_error('Sesión no válida');
                }

                $query = isset($_GET['query']) ? sanitize_text_field($_GET['query']) : '';
                $type_filter = isset($_GET['type']) ? sanitize_key($_GET['type']) : null;
                if (!in_array($type_filter, array('audio', 'video'), true)) {
                    $type_filter = null;
                }
                $bpm_min = self::sanitize_bpm_bound($_GET['bpm_min'] ?? null);
                $bpm_max = self::sanitize_bpm_bound($_GET['bpm_max'] ?? null);
                if ($bpm_min !== null && $bpm_max !== null && $bpm_min > $bpm_max) {
                    $swap = $bpm_min;
                    $bpm_min = $bpm_max;
                    $bpm_max = $swap;
                }

                $api_version = isset($_REQUEST['api_version']) ? intval($_REQUEST['api_version']) : 1;
                $limit = isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 100;
                $offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;

                if (empty($query)) {
                    if ($api_version >= 2) {
                        $stats = class_exists('JPSM_Index_Manager') ? JPSM_Index_Manager::get_stats() : array();
                        $index_state = 'empty';
                        if (intval($stats['total'] ?? 0) > 0) {
                            $index_state = !empty($stats['stale']) ? 'stale' : 'fresh';
                        }
                        JPSM_API_Contract::send_success(array(
                            'items' => array(),
                            'meta' => array(
                                'total' => 0,
                                'offset' => 0,
                                'limit' => $limit,
                                'has_more' => false,
                                'index_state' => $index_state,
                                'last_sync' => $stats['last_sync'] ?? null,
                            ),
                            'suggestions' => array(),
                        ));
                    }

                    JPSM_API_Contract::send_success(array());
                }

                // Use local index for fast search
                $search_payload = JPSM_Index_Manager::search_v2($query, $type_filter, $limit, $offset, true, $bpm_min, $bpm_max);
                $results = (array) ($search_payload['items'] ?? array());

                // --- SECURITY & FILTERING ---
                $current_email = JPSM_Access_Manager::get_current_email();
                $user_tier = $current_email ? JPSM_Access_Manager::get_user_tier($current_email) : 0;

                $response = [];
                foreach ($results as $r) {
                    // Check access for the folder of this file (download permission only).
                    // Visibility funnel remains: results are always returned, but URLs are never embedded here.
                    $folder_path = isset($r['folder']) ? (string) $r['folder'] : '';
                    // Ensure folder path ends with / for consistency with access checks
                    if ($folder_path && substr($folder_path, -1) !== '/') {
                        $folder_path .= '/';
                    }

                    $can_download = ($user_tier > 0) && JPSM_Access_Manager::user_can_access($folder_path, $user_tier);
                    $response[] = [
                        'name' => $r['name'],
                        'path' => $r['path'],
                        'size' => (int) $r['size'],
                        'folder' => $r['folder'],
                        'extension' => $r['extension'],
                        'bpm' => max(0, intval($r['bpm'] ?? 0)),
                        'bpm_source' => sanitize_key((string) ($r['bpm_source'] ?? '')),
                        'can_download' => $can_download,
                        'score' => isset($r['score']) ? intval($r['score']) : 0,
                        'match_mode' => isset($r['match_mode']) ? sanitize_key($r['match_mode']) : 'exact',
                    ];
                }

                self::track_behavior_event_passive('search_executed', array(
                    'query_norm' => $query,
                    'result_count' => count($response),
                    'object_type' => 'search',
                    'status' => 'success',
                ));
                if (empty($response)) {
                    self::track_behavior_event_passive('search_zero_results', array(
                        'query_norm' => $query,
                        'result_count' => 0,
                        'object_type' => 'search',
                        'status' => 'success',
                    ));
                }

                if ($api_version >= 2) {
                    $stats = class_exists('JPSM_Index_Manager') ? JPSM_Index_Manager::get_stats() : array();
                    $index_state = 'empty';
                    if (intval($stats['total'] ?? 0) > 0) {
                        $index_state = !empty($stats['stale']) ? 'stale' : 'fresh';
                    }

                    $total = max(count($response), intval($search_payload['total'] ?? count($response)));
                    $has_more = ($offset + count($response)) < $total;

                    JPSM_API_Contract::send_success(array(
                        'items' => $response,
                        'meta' => array(
                            'total' => $total,
                            'offset' => $offset,
                            'limit' => $limit,
                            'has_more' => $has_more,
                            'index_state' => $index_state,
                            'last_sync' => $stats['last_sync'] ?? null,
                        ),
                        'suggestions' => array_values((array) ($search_payload['suggestions'] ?? array())),
                    ));
                }

                JPSM_API_Contract::send_success($response);
            } catch (Exception $e) {
                error_log('[MediaVault] Global Search Error: ' . $e->getMessage());
                JPSM_API_Contract::send_error('Error interno: ' . $e->getMessage());
            } catch (Throwable $t) {
                error_log('[MediaVault] Global Search Fatal: ' . $t->getMessage());
                JPSM_API_Contract::send_error('Error fatal: ' . $t->getMessage());
            }
            exit;
        }

        // Mobile desktop-recommendation analytics (aggregated counts only).
        if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'mv_track_mobile_notice_event') {
            if (!class_exists('JPSM_Access_Manager')) {
                require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
            }
            if (!JPSM_Access_Manager::check_current_session()) {
                JPSM_API_Contract::send_error('Sesión no válida', 'invalid_session', 403);
            }

            $event = isset($_REQUEST['event']) ? sanitize_text_field(wp_unslash($_REQUEST['event'])) : '';
            $tracked = self::track_mobile_notice_event($event);
            if (is_wp_error($tracked)) {
                JPSM_API_Contract::send_wp_error($tracked, 'Evento inválido', 'invalid_event', 422);
            }

            JPSM_API_Contract::send_success($tracked, 'ok', 'Evento registrado');
            exit;
        }

        // Sync Index Endpoint (admin only)
        if (isset($_GET['action']) && $_GET['action'] === 'mv_sync_index') {
            $has_access = class_exists('JPSM_Auth') ? JPSM_Auth::is_admin_authenticated(true) : false;

            if (!$has_access) {
                JPSM_API_Contract::send_error('No autorizado');
            }

            $result = JPSM_Index_Manager::sync_from_s3();
            // Clear folder cache so changes in S3 are reflected immediately
            self::clear_folder_cache();
            if (is_wp_error($result)) {
                JPSM_API_Contract::send_error($result->get_error_message());
            }
            JPSM_API_Contract::send_success($result);
            exit;
        }

        // Index Stats Endpoint
        if (isset($_GET['action']) && $_GET['action'] === 'mv_index_stats') {
            $has_access = class_exists('JPSM_Auth') ? JPSM_Auth::is_admin_authenticated(true) : false;
            if (!$has_access) {
                JPSM_API_Contract::send_error('No autorizado');
            }
            $stats = JPSM_Index_Manager::get_stats();
            JPSM_API_Contract::send_success($stats);
            exit;
        }

        // ========== LAZY DOWNLOAD URL ENDPOINT (Entitlement Gated) ==========
        // Generates presigned download URLs on-demand (never embedded into browse/search payloads).
        if (isset($_GET['action']) && $_GET['action'] === 'mv_get_presigned_url') {
            if (!class_exists('JPSM_Access_Manager')) {
                require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
            }
            if (!JPSM_Access_Manager::check_current_session()) {
                JPSM_API_Contract::send_error('Sesión no válida', 'invalid_session', 403);
            }

            // Never cache presigned URL responses.
            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            if (class_exists('JPSM_Config') && !JPSM_Config::is_b2_configured()) {
                JPSM_API_Contract::send_error('MediaVault no está configurado.', 'missing_config', 503);
            }

            $path_raw = isset($_REQUEST['path']) ? sanitize_text_field(wp_unslash($_REQUEST['path'])) : '';
            $path = self::normalize_object_key($path_raw);
            if ($path === '' && $path_raw !== '') {
                JPSM_API_Contract::send_error('Path inválido.', 'invalid_path', 400);
            }
            if ($path === '' || substr($path, -1) === '/') {
                JPSM_API_Contract::send_error('Path requerido.', 'missing_path', 422);
            }

            $current_email = JPSM_Access_Manager::get_current_email();
            $user_tier = $current_email ? JPSM_Access_Manager::get_user_tier($current_email) : 0;

            // Demo users can preview but cannot download.
            if ($user_tier <= 0) {
                self::track_behavior_event_passive('download_file_denied', array(
                    'object_type' => 'file',
                    'object_path_norm' => $path,
                    'status' => 'denied',
                    'meta' => array('error_code' => 'requires_premium'),
                ));
                JPSM_API_Contract::send_error('🔒 Descarga disponible solo en plan Premium.', 'requires_premium', 403);
            }

            $folder_path = self::folder_from_object_key($path);
            if (!JPSM_Access_Manager::user_can_access($folder_path, $user_tier)) {
                self::track_behavior_event_passive('download_file_denied', array(
                    'object_type' => 'file',
                    'object_path_norm' => $path,
                    'status' => 'denied',
                    'meta' => array('error_code' => 'access_denied'),
                ));
                JPSM_API_Contract::send_error('⛔ Acceso Denegado: No tienes permiso para descargar este archivo.', 'access_denied', 403);
            }

            $s3 = new JPSM_S3_Client(
                JPSM_B2_KEY_ID,
                JPSM_B2_APP_KEY,
                JPSM_B2_REGION,
                JPSM_B2_BUCKET
            );

            $presigned_url = $s3->get_presigned_url($path);
            if (!$presigned_url) {
                self::track_behavior_event_passive('download_file_denied', array(
                    'object_type' => 'file',
                    'object_path_norm' => $path,
                    'status' => 'error',
                    'meta' => array('error_code' => 'signing_failed'),
                ));
                JPSM_API_Contract::send_error('Error generando URL.', 'signing_failed', 500);
            }
            if (class_exists('JPSM_Config')) {
                $presigned_url = JPSM_Config::rewrite_download_url_for_cloudflare((string) $presigned_url);
            }

            $indexed_size = self::get_indexed_object_size($path);

            self::track_behavior_event_passive('download_file_granted', array(
                'object_type' => 'file',
                'object_path_norm' => $path,
                'status' => 'success',
                'bytes_authorized' => max(0, intval($indexed_size)),
            ));

            JPSM_API_Contract::send_success(['url' => $presigned_url]);
            exit;
        }

        // ========== PREVIEW URL ENDPOINT (No Direct Download URLs For Locked/Demo) ==========
        // For demo/locked content: return a short-lived proxy URL that streams only a limited byte-range.
        // For entitled users: return a direct presigned URL (they can already download).
        if (isset($_GET['action']) && $_GET['action'] === 'mv_get_preview_url') {
            if (!class_exists('JPSM_Access_Manager')) {
                require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
            }
            if (!JPSM_Access_Manager::check_current_session()) {
                JPSM_API_Contract::send_error('Sesión no válida', 'invalid_session', 403);
            }

            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            if (class_exists('JPSM_Config') && !JPSM_Config::is_b2_configured()) {
                JPSM_API_Contract::send_error('MediaVault no está configurado.', 'missing_config', 503);
            }

            $path_raw = isset($_REQUEST['path']) ? sanitize_text_field(wp_unslash($_REQUEST['path'])) : '';
            $path = self::normalize_object_key($path_raw);
            if ($path === '' && $path_raw !== '') {
                JPSM_API_Contract::send_error('Path inválido.', 'invalid_path', 400);
            }
            if ($path === '' || substr($path, -1) === '/') {
                JPSM_API_Contract::send_error('Path requerido.', 'missing_path', 422);
            }

            $current_email = JPSM_Access_Manager::get_current_email();
            $user_tier = $current_email ? JPSM_Access_Manager::get_user_tier($current_email) : 0;

            // Demo play-limit is enforced server-side to prevent bypass.
            $remaining_plays = -1;
            if ($user_tier <= 0) {
                if (!JPSM_Access_Manager::can_play($current_email)) {
                    JPSM_API_Contract::send_error('Límite de vistas previas alcanzado', 'limit_reached', 403);
                }
                $meta = JPSM_Access_Manager::log_play($current_email); // increments + returns remaining
                $remaining_plays = isset($meta['remaining']) ? (int) $meta['remaining'] : 0;
            } else {
                $remaining_plays = (int) JPSM_Access_Manager::get_remaining_plays($current_email);
            }

            $folder_path = self::folder_from_object_key($path);
            $can_download = ($user_tier > 0) && JPSM_Access_Manager::user_can_access($folder_path, $user_tier);

            // Entitled users can preview using direct S3 URL (performance).
            if ($can_download) {
                $s3 = new JPSM_S3_Client(
                    JPSM_B2_KEY_ID,
                    JPSM_B2_APP_KEY,
                    JPSM_B2_REGION,
                    JPSM_B2_BUCKET
                );
                $presigned_url = $s3->get_presigned_url($path, 1800, array(
                    'response_content_disposition' => 'inline',
                ));
                if (!$presigned_url) {
                    JPSM_API_Contract::send_error('Error generando URL.', 'signing_failed', 500);
                }
                if (class_exists('JPSM_Config')) {
                    $presigned_url = JPSM_Config::rewrite_download_url_for_cloudflare((string) $presigned_url);
                }

                self::track_behavior_event_passive('preview_direct_opened', array(
                    'object_type' => 'file',
                    'object_path_norm' => $path,
                    'status' => 'ok',
                ));
                JPSM_API_Contract::send_success([
                    'url' => $presigned_url,
                    'mode' => 'direct',
                    'remaining_plays' => $remaining_plays,
                ]);
                exit;
            }

            // Locked/demo: issue a short-lived preview token bound to the signed user session.
            try {
                $token = bin2hex(random_bytes(16));
            } catch (Throwable $t) {
                $token = preg_replace('/[^a-zA-Z0-9]/', '', wp_generate_password(32, false, false));
            }

            $max_bytes = self::preview_max_bytes_for_path($path);
            set_transient('mv_preview_' . $token, [
                'email' => strtolower((string) $current_email),
                'path' => $path,
                'max_bytes' => (int) $max_bytes,
                'issued_at' => time(),
            ], 5 * MINUTE_IN_SECONDS);

            $stream_url = add_query_arg([
                'action' => 'mv_stream_preview',
                'token' => $token,
            ], admin_url('admin-ajax.php'));

            JPSM_API_Contract::send_success([
                'url' => $stream_url,
                'mode' => 'proxy',
                'remaining_plays' => $remaining_plays,
            ]);
            exit;
        }

        // ========== PREVIEW STREAM PROXY (Token + Session Bound) ==========
        // Returns only a limited byte-range so locked/demo users cannot extract full downloads.
        if (isset($_GET['action']) && $_GET['action'] === 'mv_stream_preview') {
            if (!class_exists('JPSM_Access_Manager')) {
                require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
            }

            // Session required (token alone is not enough).
            if (!JPSM_Access_Manager::check_current_session()) {
                if (function_exists('status_header')) {
                    status_header(403);
                } else {
                    http_response_code(403);
                }
                echo 'Unauthorized';
                exit;
            }

            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            if (class_exists('JPSM_Config') && !JPSM_Config::is_b2_configured()) {
                if (function_exists('status_header')) {
                    status_header(503);
                } else {
                    http_response_code(503);
                }
                echo 'MediaVault not configured';
                exit;
            }

            $token_raw = isset($_GET['token']) ? (string) wp_unslash($_GET['token']) : '';
            $token = preg_replace('/[^a-zA-Z0-9]/', '', $token_raw);
            if ($token === '') {
                if (function_exists('status_header')) {
                    status_header(400);
                } else {
                    http_response_code(400);
                }
                echo 'Token requerido';
                exit;
            }

            $payload = get_transient('mv_preview_' . $token);
            if (!is_array($payload) || empty($payload['path']) || empty($payload['email'])) {
                if (function_exists('status_header')) {
                    status_header(404);
                } else {
                    http_response_code(404);
                }
                echo 'Token expirado';
                exit;
            }

            $current_email = strtolower((string) JPSM_Access_Manager::get_current_email());
            if ($current_email === '' || strtolower((string) $payload['email']) !== $current_email) {
                if (function_exists('status_header')) {
                    status_header(403);
                } else {
                    http_response_code(403);
                }
                echo 'Unauthorized';
                exit;
            }

            $path = self::normalize_object_key((string) $payload['path']);
            if ($path === '' || substr($path, -1) === '/') {
                if (function_exists('status_header')) {
                    status_header(400);
                } else {
                    http_response_code(400);
                }
                echo 'Invalid path';
                exit;
            }

            $max_bytes = isset($payload['max_bytes']) ? (int) $payload['max_bytes'] : (5 * 1024 * 1024);
            if ($max_bytes < 1024) {
                $max_bytes = 1024;
            }
            $observed_bytes = 0;

            // Cap any client-provided Range header to the allowed preview window.
            $start = 0;
            $end = $max_bytes - 1;
            $range_header = isset($_SERVER['HTTP_RANGE']) ? (string) $_SERVER['HTTP_RANGE'] : '';
            if (preg_match('/bytes=(\\d+)-(\\d*)/i', $range_header, $m)) {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = (int) $m[2];
                }
            }
            if ($start >= $max_bytes) {
                if (function_exists('status_header')) {
                    status_header(416);
                } else {
                    http_response_code(416);
                }
                header('Content-Range: bytes */' . $max_bytes);
                exit;
            }
            if ($end >= $max_bytes) {
                $end = $max_bytes - 1;
            }
            if ($end < $start) {
                $end = $start;
            }

            $s3 = new JPSM_S3_Client(
                JPSM_B2_KEY_ID,
                JPSM_B2_APP_KEY,
                JPSM_B2_REGION,
                JPSM_B2_BUCKET
            );
            $presigned_url = $s3->get_presigned_url($path, 600);
            if (!$presigned_url) {
                if (function_exists('status_header')) {
                    status_header(502);
                } else {
                    http_response_code(502);
                }
                echo 'Error generando URL';
                exit;
            }

            $filetype = function_exists('wp_check_filetype') ? wp_check_filetype(basename($path)) : ['type' => 'application/octet-stream'];
            $mime = is_array($filetype) && !empty($filetype['type']) ? (string) $filetype['type'] : 'application/octet-stream';

            if (!function_exists('curl_init')) {
                // Fallback: use WP HTTP API (buffers the response). Keep the payload capped by Range.
                $res = wp_remote_get($presigned_url, [
                    'headers' => [
                        'Range' => 'bytes=' . $start . '-' . $end,
                    ],
                    'timeout' => 60,
                ]);

                if (is_wp_error($res)) {
                    if (function_exists('status_header')) {
                        status_header(502);
                    } else {
                        http_response_code(502);
                    }
                    echo 'Upstream error';
                    exit;
                }

                $code = (int) wp_remote_retrieve_response_code($res);
                if (function_exists('status_header')) {
                    status_header($code ?: 206);
                } else {
                    http_response_code($code ?: 206);
                }
                header('Content-Type: ' . $mime);
                header('X-Content-Type-Options: nosniff');
                header('Accept-Ranges: bytes');
                header('Content-Length: ' . (($end - $start) + 1));
                $body = (string) wp_remote_retrieve_body($res);
                $observed_bytes = strlen($body);
                self::track_behavior_event_passive('preview_proxy_streamed', array(
                    'object_type' => 'file',
                    'object_path_norm' => $path,
                    'status' => 'success',
                    'bytes_observed' => max(0, intval($observed_bytes)),
                ));
                echo $body;
                exit;
            }

            // Stream from B2 using curl with a capped Range request (no buffering full body in PHP memory).
            $headers_sent = false;
            $up_status = 200;
            $up_content_type = '';
            $up_content_range = '';
            $up_content_length = '';

            $ch = curl_init($presigned_url);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Range: bytes=' . $start . '-' . $end,
            ]);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$headers_sent, &$up_status, &$up_content_type, &$up_content_range, &$up_content_length, $mime, $start, $end) {
                $len = strlen($header);
                $line = trim($header);

                if (preg_match('/^HTTP\\//i', $line)) {
                    if (preg_match('/\\s(\\d{3})\\s/', $line, $m)) {
                        $up_status = (int) $m[1];
                    }
                    return $len;
                }

                if ($line === '' && !$headers_sent) {
                    $headers_sent = true;
                    if (function_exists('status_header')) {
                        status_header($up_status ?: 206);
                    } else {
                        http_response_code($up_status ?: 206);
                    }

                    header('Content-Type: ' . ($up_content_type ?: $mime));
                    header('X-Content-Type-Options: nosniff');
                    header('Accept-Ranges: bytes');

                    if ($up_content_range) {
                        header('Content-Range: ' . $up_content_range);
                    } else {
                        header('Content-Range: bytes ' . $start . '-' . $end . '/*');
                    }

                    if ($up_content_length) {
                        header('Content-Length: ' . $up_content_length);
                    } else {
                        header('Content-Length: ' . (($end - $start) + 1));
                    }

                    return $len;
                }

                if (stripos($line, 'Content-Type:') === 0) {
                    $up_content_type = trim(substr($line, strlen('Content-Type:')));
                } elseif (stripos($line, 'Content-Range:') === 0) {
                    $up_content_range = trim(substr($line, strlen('Content-Range:')));
                } elseif (stripos($line, 'Content-Length:') === 0) {
                    $up_content_length = trim(substr($line, strlen('Content-Length:')));
                }

                return $len;
            });
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use (&$headers_sent, &$observed_bytes) {
                // Safety: if upstream didn't send an empty-line header terminator for some reason, send headers now.
                if (!$headers_sent) {
                    $headers_sent = true;
                    if (!headers_sent()) {
                        if (function_exists('status_header')) {
                            status_header(206);
                        } else {
                            http_response_code(206);
                        }
                        header('Accept-Ranges: bytes');
                        header('X-Content-Type-Options: nosniff');
                    }
                }

                $observed_bytes += strlen($data);
                echo $data;
                if (function_exists('flush')) {
                    flush();
                }
                return strlen($data);
            });

            $ok = curl_exec($ch);
            if ($ok === false) {
                // If we haven't sent headers/body yet, respond with an error. Otherwise, just stop.
                if (!headers_sent()) {
                    if (function_exists('status_header')) {
                        status_header(502);
                    } else {
                        http_response_code(502);
                    }
                    echo 'Upstream error';
                }
            }
            curl_close($ch);
            self::track_behavior_event_passive('preview_proxy_streamed', array(
                'object_type' => 'file',
                'object_path_norm' => $path,
                'status' => ($ok === false) ? 'error' : 'success',
                'bytes_observed' => max(0, intval($observed_bytes)),
                'meta' => ($ok === false) ? array('error_code' => 'upstream_error') : array(),
            ));
            exit;
        }

        // ========== ADMIN API ENDPOINTS ==========

        // Get user metadata (tier, plays, etc)
        if (isset($_GET['action']) && $_GET['action'] === 'mv_get_user_meta') {
            if (!class_exists('JPSM_Access_Manager')) {
                require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
            }

            $has_access = class_exists('JPSM_Auth') ? JPSM_Auth::is_admin_authenticated(false) : current_user_can('manage_options');
            if (!$has_access) {
                JPSM_API_Contract::send_error('No autorizado');
            }

            $target_email = isset($_GET['email']) ? sanitize_email($_GET['email']) : '';
            if (empty($target_email)) {
                JPSM_API_Contract::send_error('Email requerido');
            }

            $tier = JPSM_Access_Manager::get_user_tier($target_email);
            $plays = JPSM_Access_Manager::get_play_count($target_email);
            $is_customer = JPSM_Access_Manager::is_customer($target_email);

            JPSM_API_Contract::send_success([
                'email' => $target_email,
                'tier' => $tier,
                'tier_name' => JPSM_Access_Manager::get_tier_name($tier),
                'plays' => $plays,
                'remaining_plays' => JPSM_Access_Manager::get_remaining_plays($target_email),
                'is_customer' => $is_customer
            ]);
            exit;
        }

        // Update user tier
        if (isset($_POST['action']) && $_POST['action'] === 'mv_update_tier') {
            if (!class_exists('JPSM_Access_Manager')) {
                require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
            }

            $has_access = class_exists('JPSM_Auth') ? JPSM_Auth::is_admin_authenticated(false) : current_user_can('manage_options');
            if (!$has_access) {
                JPSM_API_Contract::send_error('No autorizado');
            }

            $target_email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
            $new_tier = isset($_POST['tier']) ? intval($_POST['tier']) : 0;

            if (empty($target_email)) {
                JPSM_API_Contract::send_error('Email requerido');
            }

            JPSM_Access_Manager::set_user_tier($target_email, $new_tier);

            JPSM_API_Contract::send_success([
                'email' => $target_email,
                'tier' => $new_tier,
                'tier_name' => JPSM_Access_Manager::get_tier_name($new_tier)
            ]);
            exit;
        }

        // Get all folder permissions
        if (isset($_GET['action']) && $_GET['action'] === 'mv_get_folders') {
            if (!class_exists('JPSM_Access_Manager')) {
                require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
            }

            $has_access = class_exists('JPSM_Auth') ? JPSM_Auth::is_admin_authenticated(false) : current_user_can('manage_options');
            if (!$has_access) {
                JPSM_API_Contract::send_error('No autorizado');
            }

            JPSM_API_Contract::send_success(JPSM_Access_Manager::get_all_folder_permissions());
            exit;
        }

        // Update folder permission
        if (isset($_POST['action']) && $_POST['action'] === 'mv_update_folder') {
            if (!class_exists('JPSM_Access_Manager')) {
                require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
            }

            $has_access = class_exists('JPSM_Auth') ? JPSM_Auth::is_admin_authenticated(false) : current_user_can('manage_options');
            if (!$has_access) {
                JPSM_API_Contract::send_error('No autorizado');
            }

            $folder_path = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : '';
            $folder_path = self::normalize_object_key($folder_path);
            $tier = isset($_POST['tier']) ? intval($_POST['tier']) : 1;

            if (empty($folder_path)) {
                JPSM_API_Contract::send_error('Carpeta requerida');
            }

            // Normalize to trailing slash for folder permissions consistency.
            if (substr($folder_path, -1) !== '/') {
                $folder_path .= '/';
            }

            JPSM_Access_Manager::set_folder_tier($folder_path, $tier);

            JPSM_API_Contract::send_success(['folder' => $folder_path, 'tier' => $tier]);
            exit;
        }

        // Get all leads
        if (isset($_GET['action']) && $_GET['action'] === 'mv_get_leads') {
            if (!class_exists('JPSM_Access_Manager')) {
                require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
            }

            $has_access = class_exists('JPSM_Auth') ? JPSM_Auth::is_admin_authenticated(false) : current_user_can('manage_options');
            if (!$has_access) {
                JPSM_API_Contract::send_error('No autorizado');
            }

            JPSM_API_Contract::send_success(JPSM_Access_Manager::get_leads());
            exit;
        }
    }

    /**
     * Renders the full page shell (skipping theme).
     * Used by template-fullscreen.php
     */
    public static function render_full_page()
    {
        // Must run before output: cookies/redirects won't work after HTML starts streaming.
        self::handle_pre_output_requests();

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>

        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php wp_title('|', true, 'right'); ?></title>
            <?php wp_head(); ?>
            <style>
                html,
                body {
                    margin: 0;
                    padding: 0;
                    height: 100%;
                    /* overflow: hidden; Removed to fix scrolling/interaction */
                }
            </style>
        </head>

        <body <?php body_class(); ?>>
            <?php echo self::render(); ?>
            <?php wp_footer(); ?>
        </body>

        </html>
        <?php
    }

    public static function render()
    {
        // 1. Verify Access
        if (!class_exists('JPSM_Access_Manager')) {
            require_once JPSM_PLUGIN_DIR . 'includes/class-access-manager.php';
        }

        if (!JPSM_Access_Manager::check_current_session()) {
            return self::render_login_form();
        }

        $user_email = JPSM_Access_Manager::get_current_email();
        $user_tier = JPSM_Access_Manager::get_user_tier($user_email);
        // Used by both initial render and mv_ajax JSON payload; define early to keep mv_ajax responses clean.
        $remaining_plays = JPSM_Access_Manager::get_remaining_plays($user_email);

        // 2. Initialize S3 Client
        $s3 = new JPSM_S3_Client(
            JPSM_B2_KEY_ID,
            JPSM_B2_APP_KEY,
            JPSM_B2_REGION,
            JPSM_B2_BUCKET
        );

        // Auto-fix CORS if requested
        if (isset($_GET['fix_cors'])) {
            $res = $s3->enable_cors();
            if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) == 200) {
                echo '<div class="jpsm-mv-alert" style="background:green; color:white;">✅ CORS Configurado con éxito en B2.</div>';
            } else {
                $err = is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_body($res);
                echo '<div class="jpsm-mv-alert">❌ Error configurando CORS: ' . esc_html($err) . '</div>';
            }
        }

        // 3. Navigation & Data
        // Home screen (no folder param) must show the same "junction-level" folders as the sidebar.
        // This improves mobile navigation without needing to open the left sidebar.
        $requested_folder = isset($_GET['folder']) ? sanitize_text_field($_GET['folder']) : '';
        if ($requested_folder !== '' && substr($requested_folder, -1) !== '/') {
            $requested_folder .= '/';
        }
        $is_home_screen = ($requested_folder === '');

        // UI current folder reflects the URL parameter (empty on home screen).
        $current_folder = $requested_folder;

        // --- STEP 1: Junction Detection (Recursive finding of the Sidebar Level) ---
        $junction_folder = '';
        $drill_path = '';
        $depth_safety = 0;

        while ($depth_safety < 10) {
            $check = self::get_folder_structure($drill_path, $s3, 300); // Prefer index; fallback to cached S3
            if (is_wp_error($check))
                break;

            // If it's a "Wrapper" (1 folder, 0 files), drill down
            if (count($check['folders']) === 1 && empty($check['files'])) {
                $drill_path = $check['folders'][0];
                $depth_safety++;
            } else {
                // We reached a junction or content
                $junction_folder = $drill_path;
                break;
            }
        }

        // --- STEP 2: Home Screen Listing (Junction View) ---
        // No auto-redirect into the first folder. The junction view is the MediaVault "Inicio".
        $list_folder = $is_home_screen ? $junction_folder : $requested_folder;

        // --- STEP 3: Breadcrumb Data Pruning (for initial load) ---
        $data = self::get_folder_structure($list_folder, $s3);

        // Prune the Junction Folder prefix from path strings for display
        $effective_path = $current_folder;
        if (!empty($junction_folder) && strpos($current_folder, $junction_folder) === 0) {
            $effective_path = trim(substr($current_folder, strlen($junction_folder)), '/');
        }

        // Relative depth (from junction) used for UI decisions (e.g. when to show folder download buttons).
        $folder_depth = $effective_path ? count(array_filter(explode('/', trim($effective_path, '/')))) : 0;

        if (is_wp_error($data)) {
            return '<div class="jpsm-mv-error">' .
                '<strong>B2 Error:</strong> ' . esc_html($data->get_error_message()) .
                '</div>';
        }

        // (Hard Block Removed for Funnel Mode)

        // 4. Catalog Metadata (Images)
        $folder_covers = self::get_folder_covers();

        // Home screen should follow the admin-defined sidebar order for top-level folders.
        if ($is_home_screen && class_exists('JPSM_MediaVault_Nav_Order') && isset($data['folders']) && is_array($data['folders'])) {
            $data['folders'] = JPSM_MediaVault_Nav_Order::apply_order($data['folders']);
        }

        // --- AJAX RESPONSE HANDLER ---
        if (isset($_GET['mv_ajax']) && $_GET['mv_ajax'] == '1') {
            // Clean output buffer to prevent Admin UI pollution
            while (ob_get_level()) {
                ob_end_clean();
            }

            // Calculate Depth for JS logic (junction-relative)
            $current_depth = $folder_depth;

            $response = [
                'current_folder' => $current_folder,
                'current_depth' => $current_depth,
                'hero_title' => $current_folder ? basename($current_folder) : 'Mi Biblioteca',
                // Handle file count in description logic
                'hero_desc_prefix' => $current_folder ? 'Explorando colección' : 'Bienvenido a tu bóveda personal',
                'hero_icon' => $current_folder ? '📂' : '🚀',
                'file_count' => count($data['files']),
                // Server-side user meta: allows the client to reflect tier changes without requiring logout/relogin.
                'user_email' => $user_email,
                'user_tier' => intval($user_tier),
                'user_tier_name' => JPSM_Access_Manager::get_tier_name($user_tier),
                'remaining_plays' => intval($remaining_plays),
                'folders' => [],
                'files' => [],
                'breadcrumbs' => []
            ];

            // Build Breadcrumbs for response (Pruned by Junction)
            if ($effective_path) {
                $b_parts = explode('/', trim($effective_path, '/'));
                $b_accum = $junction_folder;
                foreach ($b_parts as $bp) {
                    $b_accum = trim($b_accum, '/') . '/' . $bp . '/';
                    $response['breadcrumbs'][] = [
                        'name' => $bp,
                        'path' => trim($b_accum, '/')
                    ];
                }
            }

            // Process Folders
            $folders_for_response = $data['folders'];
            if ($is_home_screen && class_exists('JPSM_MediaVault_Nav_Order')) {
                $folders_for_response = JPSM_MediaVault_Nav_Order::apply_order($folders_for_response);
            }

            foreach ($folders_for_response as $folder) {
                $folder_name = trim($folder, '/');
                $display_name = basename($folder_name);
                $can_download_folder = ($user_tier > 0) && JPSM_Access_Manager::user_can_access($folder, $user_tier);
                // Cover Logic
                $cover_html = '<div class="jpsm-mv-cover-icon">📁</div>';
                if (isset($folder_covers[$folder_name])) {
                    $imgs = $folder_covers[$folder_name]; // [img1, img2]
                    if (!empty($imgs)) {
                        $cover_html = '';
                        foreach ($imgs as $img_url) {
                            $cover_html .= '<img src="' . esc_url($img_url) . '" alt="Cover">';
                        }
                    }
                }

                $response['folders'][] = [
                    'path' => $folder,
                    'name' => $display_name,
                    'sort_name' => strtolower($display_name),
                    'cover_html' => $cover_html,
                    'can_download' => $can_download_folder,
                ];
            }

            // Process Files
            foreach ($data['files'] as $file) {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $file_folder = self::folder_from_object_key($file['path']);
                $can_download = ($user_tier > 0) && JPSM_Access_Manager::user_can_access($file_folder, $user_tier);

                // Icon Logic
                $icon = '📄';
                $ext_lower = strtolower($ext);
                if (in_array($ext_lower, ['mp3', 'wav', 'flac']))
                    $icon = '🎵';
                elseif (in_array($ext_lower, ['mp4', 'mov', 'mkv', 'avi']))
                    $icon = '🎬';
                elseif (in_array($ext_lower, ['jpg', 'png', 'jpeg', 'gif', 'webp']))
                    $icon = '🖼️';
                elseif (in_array($ext_lower, ['zip', 'rar', '7z']))
                    $icon = '📦';

                $response['files'][] = [
                    'name' => $file['name'],
                    'path' => $file['path'],
                    'date' => $file['date'],
                    'size' => $file['size'],
                    'size_fmt' => size_format($file['size']),
                    'bpm' => max(0, intval($file['bpm'] ?? 0)),
                    'bpm_source' => sanitize_key((string) ($file['bpm_source'] ?? '')),
                    'type' => 'file', // logic needed? default file
                    'ext' => $ext,
                    'icon' => $icon,
                    // Never embed direct download URLs in browse payloads.
                    // Downloads are resolved on-demand after entitlement checks.
                    'can_download' => $can_download,
                ];
            }

            JPSM_API_Contract::send_success($response);
        }
        // -----------------------------

        // 5. Usage stats
        $usage = JPSM_Traffic_Manager::get_daily_usage($user_email);
        $limit_reached = !JPSM_Traffic_Manager::can_download($user_email);
        // $remaining_plays already computed above (and used by mv_ajax).

        // 6. Sidebar Data (Use the already detected Junction)
        $sidebar_content = self::get_folder_structure($junction_folder, $s3, 300);
        $sidebar_folders = !is_wp_error($sidebar_content) ? $sidebar_content['folders'] : [];
        if (class_exists('JPSM_MediaVault_Nav_Order') && is_array($sidebar_folders)) {
            $sidebar_folders = JPSM_MediaVault_Nav_Order::apply_order($sidebar_folders);
        }
        $effective_root_path = $junction_folder;

        ob_start();
        ?>
        <!-- MediaVault CSS -->
        <style>
            :root {
                --mv-bg: #f8fafc;
                --mv-surface: #ffffff;
                --mv-surface-hover: #fff7ed;
                --mv-border: #e2e8f0;
                --mv-border-hover: #fb923c;
                --mv-accent: #ea580c;
                /* JetPack Orange */
                --mv-accent-hover: #c2410c;
                --mv-success: #16a34a;
                --mv-danger: #dc2626;
                --mv-text: #0f172a;
                /* Slate 900 */
                --mv-text-muted: #64748b;
                /* Slate 500 */
            }

            .jpsm-mv-container {
                max-width: 1400px;
                /* Increased max-width for sidebar layout */
                margin: 0 auto;
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                background: var(--mv-bg);
                min-height: 100vh;
                /* Full height */
                color: var(--mv-text);
                padding-bottom: 0;
                display: flex;
                flex-direction: row;
                position: relative;
                overflow-x: hidden;
            }

            /* --- Sidebar & Main Content Layout --- */
            .mv-sidebar {
                width: 260px;
                flex-shrink: 0;
                background: white;
                border-right: 1px solid var(--mv-border);
                position: sticky;
                top: 0;
                height: 100vh;
                overflow-y: auto;
                display: flex;
                flex-direction: column;
                z-index: 50;
                transition: transform 0.3s ease;
            }

            .mv-main-content {
                flex: 1;
                display: flex;
                flex-direction: column;
                min-width: 0;
                /* Prevent flex overflow */
                position: relative;
            }

            /* --- Responsive Breadcrumbs --- */
            #mv-breadcrumbs {
                padding: 20px 40px 0;
                display: flex;
                gap: 8px;
                align-items: center;
                color: var(--mv-text-muted);
                font-size: 0.9rem;
                overflow-x: auto;
                white-space: nowrap;
                scrollbar-width: none;
                /* Hide scrollbar Firefox */
                -ms-overflow-style: none;
                /* Hide scrollbar IE/Edge */
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
            }

            #mv-breadcrumbs::-webkit-scrollbar {
                display: none;
                /* Hide scrollbar Chrome/Safari */
            }

            #mv-breadcrumbs .breadcrumb-item {
                flex-shrink: 0;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            #mv-breadcrumbs a {
                color: var(--mv-text-muted);
                text-decoration: none;
                transition: color 0.2s;
            }

            #mv-breadcrumbs a:hover {
                color: var(--mv-accent);
            }

            #mv-breadcrumbs .separator {
                opacity: 0.5;
            }

            @media (max-width: 768px) {
                #mv-breadcrumbs {
                    padding: 80px 20px 15px;
                    /* Added significant top padding for hamburger clearance */
                    font-size: 0.85rem;
                    mask-image: linear-gradient(to right, black 85%, transparent 100%);
                    -webkit-mask-image: linear-gradient(to right, black 85%, transparent 100%);
                }
            }

            /* Mobile Toggle (Top-Left Hamburger) */
            .mv-mobile-toggle {
                display: none;
                /* Hidden on desktop */
                position: fixed;
                top: 15px;
                left: 15px;
                width: 44px;
                height: 44px;
                background: white;
                color: var(--mv-text);
                border-radius: 12px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                border: 1px solid var(--mv-border);
                z-index: 1001;
                /* Above sidebar */
                cursor: pointer;
                align-items: center;
                justify-content: center;
                font-size: 20px;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .mv-sidebar.active~.mv-mobile-toggle {
                background: transparent;
                border: none;
                box-shadow: none;
                color: #ef4444;
                /* Red color for close state */
                font-size: 28px;
                width: 32px;
                height: 32px;
                top: 20px;
                left: 20px;
            }

            .mv-mobile-toggle:active {
                transform: scale(0.95);
                background: var(--mv-surface-hover);
            }

            .mv-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 40;
                backdrop-filter: blur(2px);
            }


            /* Sidebar Content Styles */
            .mv-sidebar-header {
                padding: 24px;
                border-bottom: 1px solid var(--mv-border);
            }

            .mv-sidebar-nav {
                padding: 20px 0;
                flex: 1;
            }

            .mv-nav-item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 24px;
                color: var(--mv-text-muted);
                text-decoration: none;
                font-weight: 500;
                transition: all 0.2s;
            }

            .mv-nav-item:hover,
            .mv-nav-item.active {
                background: var(--mv-surface-hover);
                color: var(--mv-accent);
                border-right: 3px solid var(--mv-accent);
            }

            /* Mobile Styles (< 768px) */
            @media (max-width: 768px) {
                .mv-sidebar {
                    position: fixed;
                    left: 0;
                    top: 0;
                    bottom: 0;
                    transform: translateX(-100%);
                    box-shadow: 4px 0 12px rgba(0, 0, 0, 0.1);
                }

                .mv-sidebar.active {
                    transform: translateX(0);
                }

                .mv-mobile-toggle {
                    display: flex;
                }

                .mv-overlay.active {
                    display: block;
                }

                .mv-sidebar-header {
                    padding: 100px 30px 40px !important;
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                }
            }

            .mv-sidebar-footer {
                padding: 20px 24px;
                border-top: 1px solid var(--mv-border);
                background: #f8fafc;
            }

            /* Header Section */
            .jpsm-mv-header-hero {
                padding: 40px 40px 20px;
                background: linear-gradient(180deg, rgba(59, 130, 246, 0.1) 0%, rgba(9, 9, 11, 0) 100%);
                display: flex;
                align-items: flex-end;
                gap: 24px;
            }

            .jpsm-mv-cover-icon {
                font-size: 12px;
                font-weight: 700;
                letter-spacing: 0.5px;
                color: var(--mv-text-muted);
                background: var(--mv-surface-hover);
                padding: 8px 12px;
                border-radius: 4px;
                text-transform: uppercase;
            }

            .jpsm-mv-hero-icon {
                font-size: 64px;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
                border-radius: 12px;
                background: #ffffff;
                border: 1px solid var(--mv-border);
                color: var(--mv-accent);
                width: 100px;
                height: 100px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .jpsm-mv-hero-content h1 {
                font-size: 36px;
                font-weight: 800;
                letter-spacing: -0.5px;
                margin: 0 0 8px 0;
                color: var(--mv-text);
            }

            .jpsm-mv-hero-meta {
                color: var(--mv-text-muted);
                font-size: 14px;
                display: flex;
                align-items: center;
                gap: 12px;
            }

            /* --- View Toggles --- */
            .mv-view-toggles {
                display: flex;
                background: var(--mv-surface);
                border: 1px solid var(--mv-border);
                border-radius: 8px;
                padding: 2px;
                gap: 2px;
            }

            .mv-view-btn {
                background: transparent;
                border: 0;
                color: var(--mv-text-muted);
                padding: 6px 10px;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.2s;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .mv-view-btn.active,
            .mv-view-btn:hover {
                background: var(--mv-accent);
                color: white;
            }

            /* --- List View Logic --- */
            .jpsm-mv-grid.view-list,
            .jpsm-mv-grid.force-list-view {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .jpsm-mv-grid.view-list .jpsm-mv-card,
            .jpsm-mv-grid.force-list-view .jpsm-mv-card {
                flex-direction: row;
                align-items: center;
                padding: 10px 20px;
                gap: 20px;
            }

            .jpsm-mv-grid.view-list .jpsm-mv-card-link,
            .jpsm-mv-grid.force-list-view .jpsm-mv-card-link {
                flex-direction: row;
                align-items: center;
                gap: 16px;
                padding: 0;
            }

            .jpsm-mv-grid.view-list .jpsm-mv-card-link>div:not(.jpsm-mv-cover),
            .jpsm-mv-grid.force-list-view .jpsm-mv-card-link>div:not(.jpsm-mv-cover) {
                display: flex;
                flex-direction: row;
                align-items: center;
                flex: 1;
                gap: 16px;
                overflow: hidden;
            }

            .jpsm-mv-grid.view-list .jpsm-mv-cover,
            .jpsm-mv-grid.force-list-view .jpsm-mv-cover {
                width: 48px;
                height: 48px;
                margin-bottom: 0;
                flex-shrink: 0;
            }

            .jpsm-mv-grid.view-list .jpsm-mv-cover-icon,
            .jpsm-mv-grid.force-list-view .jpsm-mv-cover-icon {
                font-size: 1.5rem;
            }

            .jpsm-mv-grid.view-list .jpsm-mv-title,
            .jpsm-mv-grid.force-list-view .jpsm-mv-title {
                flex: 1;
                font-size: 1rem;
                margin: 0;
                text-align: left;
                white-space: normal;
                word-break: break-word;
            }

            .jpsm-mv-grid.view-list .jpsm-mv-meta,
            .jpsm-mv-grid.force-list-view .jpsm-mv-meta {
                width: auto;
                min-width: 120px;
                text-align: right;
                margin-left: auto;
                flex-shrink: 0;
            }

            .jpsm-mv-grid.view-list .mv-btn-explicit,
            .jpsm-mv-grid.force-list-view .mv-btn-explicit {
                margin-top: 0;
                margin-left: 20px;
                flex-shrink: 0;
            }

            /* (Already handled above with .jpsm-mv-grid.view-list .mv-btn-explicit) */

            /* Add label content via CSS for List View allows cleaner HTML? No, let's span it */

            /* --- Action Button Styles (Always Visible) --- */
            .mv-btn-explicit {
                background: rgba(59, 130, 246, 0.15);
                color: var(--mv-accent);
                border: 1px solid rgba(59, 130, 246, 0.3);
                padding: 8px 16px;
                border-radius: 8px;
                font-weight: 500;
                font-size: 13px;
                line-height: 1.2;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                white-space: nowrap;
                cursor: pointer;
                transition: all 0.2s ease;
                margin-top: auto;
                /* Push to bottom in flex column */
            }

            .mv-btn-explicit:hover {
                background: var(--mv-accent);
                color: white;
                border-color: var(--mv-accent);
            }

            .mv-btn-explicit.primary {
                background: var(--mv-accent);
                color: white;
                border-color: var(--mv-accent);
            }

            .mv-btn-explicit.primary:hover {
                background: #2563eb;
                box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            }

            /* --- List View Button Adjustments --- */
            .jpsm-mv-grid.view-list .mv-btn-explicit {
                margin-top: 0;
                margin-left: auto;
                flex-shrink: 0;
            }

            /* Toolbar */
            .jpsm-mv-toolbar {
                padding: 15px 40px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: rgba(255, 255, 255, 0.9);
                position: sticky;
                top: 0;
                z-index: 20;
                backdrop-filter: blur(8px);
                border-bottom: 1px solid var(--mv-border);
            }

            /* Center status host for the global loader (must remain visible on scroll). */
            .mv-toolbar-status {
                position: absolute;
                left: 50%;
                top: 50%;
                transform: translate(-50%, -50%);
                pointer-events: none;
                z-index: 30;
            }

            .jpsm-mv-tools-left {
                display: flex;
                gap: 16px;
                align-items: center;
            }

            /* In-app navigation (Back/Forward) to reduce reliance on browser controls. */
            .mv-nav-controls {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 4px;
                border-radius: 14px;
                border: 1px solid var(--mv-border);
                background: var(--mv-surface-hover);
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
            }

            .mv-nav-btn {
                width: 38px;
                height: 38px;
                border-radius: 10px;
                border: 1px solid var(--mv-border);
                background: var(--mv-surface);
                color: var(--mv-text);
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                transition: all 0.2s ease;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            }

            .mv-nav-btn svg {
                width: 18px;
                height: 18px;
                flex: 0 0 auto;
            }

            .mv-nav-btn:focus-visible {
                outline: 3px solid rgba(234, 88, 12, 0.18);
                outline-offset: 2px;
            }

            .mv-nav-btn.mv-nav-home {
                width: auto;
                padding: 0 12px;
                font-weight: 700;
                font-size: 13px;
            }

            .mv-nav-btn.mv-nav-home .mv-nav-home-text {
                display: inline-block;
                line-height: 1;
                white-space: nowrap;
            }

            .mv-nav-btn:hover:not(:disabled) {
                background: var(--mv-surface-hover);
                border-color: var(--mv-border-hover);
            }

            .mv-nav-btn:disabled {
                opacity: 0.45;
                cursor: not-allowed;
            }

            @media (max-width: 768px) {
                .mv-nav-btn {
                    width: 44px;
                    height: 44px;
                    border-radius: 12px;
                }

                .mv-nav-btn.mv-nav-home {
                    height: 44px;
                }
            }

            .jpsm-mv-search {
                background: white;
                border: 1px solid var(--mv-border);
                border-radius: 99px;
                padding: 10px 20px 10px 44px;
                display: flex;
                align-items: center;
                width: 320px;
                position: relative;
                transition: all 0.2s ease;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            }

            .jpsm-mv-search:focus-within {
                border-color: #f59e0b;
                box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
            }

            .jpsm-mv-search input {
                background: transparent;
                border: none;
                color: var(--mv-text);
                outline: none;
                width: 100%;
                font-size: 15px;
                /* Bigger font */
            }

            .mv-search-clear {
                background: transparent;
                border: none;
                color: #9ca3af;
                cursor: pointer;
                padding: 4px;
                display: none;
                /* JS toggles this */
                font-size: 16px;
                margin-left: 4px;
            }

            .mv-search-clear:hover {
                color: #ef4444;
            }

            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
            }

            .mv-search-icon {
                position: absolute;
                left: 14px;
                top: 50%;
                transform: translateY(-50%);
                opacity: 0.5;
            }

            .mv-sort-controls select {
                background: white;
                color: var(--mv-text);
                border: 1px solid var(--mv-border);
                padding: 10px 32px 10px 14px;
                border-radius: 8px;
                cursor: pointer;
                font-size: 14px;
                -webkit-appearance: none;
                -moz-appearance: none;
                appearance: none;
                background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23333' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
                background-repeat: no-repeat;
                background-position: right 10px center;
                background-size: 16px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            }

            /* --- Filter Pills --- */
            .mv-filter-group {
                display: flex;
                gap: 8px;
                margin-left: 16px;
            }

            .mv-filter-pill {
                background: white;
                border: 1px solid var(--mv-border);
                padding: 6px 14px;
                border-radius: 99px;
                font-size: 13px;
                font-weight: 500;
                color: var(--mv-text-muted);
                cursor: pointer;
                transition: all 0.2s ease;
            }

            .mv-filter-pill:hover {
                background: var(--mv-surface-hover);
                color: var(--mv-text);
            }

            .mv-filter-pill.active {
                background: #fff7ed;
                border-color: #f59e0b;
                color: #d97706;
                font-weight: 600;
            }

            /* --- Grid Layout --- */
            .jpsm-mv-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 24px;
                padding: 30px 40px;
            }

            .jpsm-mv-card {
                background: var(--mv-surface);
                border-radius: 12px;
                /* overflow: hidden; REMOVED to prevent button cutoff */
                position: relative;
                display: flex;
                flex-direction: column;
                border: 1px solid var(--mv-border);
                transition: all 0.25s ease;
                text-decoration: none;
                color: inherit;
                padding-bottom: 12px;
                /* Ensure space for buttons */
            }

            .jpsm-mv-card-link {
                display: flex;
                flex-direction: column;
                flex: 1;
                text-decoration: none;
                color: inherit;
                padding: 16px;
                /* Added back padding here */
            }

            .jpsm-mv-card:hover {
                background: var(--mv-surface-hover);
                border-color: var(--mv-border-hover);
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            }

            .jpsm-mv-cover {
                aspect-ratio: 1;
                background: #000;
                border-radius: 8px;
                overflow: hidden;
                margin-bottom: 16px;
                position: relative;
                box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            }

            .jpsm-mv-cover img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                transition: transform 0.3s;
            }

            .jpsm-mv-card:hover .jpsm-mv-cover img {
                transform: scale(1.05);
            }

            .jpsm-mv-cover-icon {
                width: 100%;
                height: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 2rem;
                background: #fff7ed;
                /* Orange 50 */
                color: #fb923c;
                /* Orange 400 */
                border: 1px solid #fed7aa;
                /* Orange 200 */
            }

            .jpsm-mv-card:hover .jpsm-mv-cover-icon {
                background: #ffedd5;
                /* Orange 100 */
                color: #ea580c;
                /* Brand Orange */
                border-color: #fb923c;
            }

            .jpsm-mv-card.locked .jpsm-mv-cover-icon,
            .jpsm-mv-card.locked .jpsm-mv-cover img {
                filter: grayscale(1);
                opacity: 0.5;
            }

            .jpsm-mv-card.locked .locked-icon {
                display: inline !important;
                opacity: 0.8;
            }

            .jpsm-mv-title {
                font-weight: 600;
                font-size: 1rem;
                margin-bottom: 4px;
                /* Allow wrapping up to 2 lines */
                white-space: normal;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
                line-height: 1.3;
                color: var(--mv-text);
                transition: color 0.2s ease;
            }

            .jpsm-mv-card:hover .jpsm-mv-title {
                color: var(--mv-accent);
            }

            .jpsm-mv-meta {
                font-size: 0.85rem;
                color: var(--mv-text-muted);
                transition: color 0.2s ease;
            }

            .jpsm-mv-card:hover .jpsm-mv-meta {
                color: var(--mv-text);
            }

            /* Forced List View Styles */
            .jpsm-mv-grid.force-list-view {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .jpsm-mv-grid.force-list-view .jpsm-mv-card {
                flex-direction: row;
                align-items: center;
                padding: 10px 20px;
                gap: 20px;
            }

            .jpsm-mv-grid.force-list-view .jpsm-mv-cover {
                display: flex !important;
                width: 48px;
                height: 48px;
                margin-bottom: 0;
                flex-shrink: 0;
            }

            .jpsm-mv-grid.force-list-view .jpsm-mv-cover-icon {
                font-size: 1.5rem;
            }

            .jpsm-mv-grid.force-list-view .jpsm-mv-title {
                -webkit-line-clamp: 1;
                /* Single line in list view */
                margin: 0;
            }

            /* Shared List View Compact Button Styles */
            .jpsm-mv-grid.force-list-view .mv-folder-view-btn,
            .jpsm-mv-grid.force-list-view .mv-folder-download-btn,
            .jpsm-mv-grid.view-list .mv-folder-view-btn,
            .jpsm-mv-grid.view-list .mv-folder-download-btn,
            .jpsm-mv-grid.view-list .mv-download-btn {
                width: auto !important;
                margin: 0;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                white-space: nowrap;
                margin-left: auto;
                /* Push to right */
                flex-shrink: 0;
            }

            .jpsm-mv-grid.view-list .jpsm-mv-card,
            .jpsm-mv-grid.force-list-view .jpsm-mv-card {
                justify-content: space-between;
            }

            .mv-preview-btn:hover {
                background: var(--mv-accent) !important;
                color: white !important;
                border-color: var(--mv-accent) !important;
            }

            /* Adjust placeholder text size */
            /* Adjust placeholder text size and use icons */
            .jpsm-mv-cover-icon {
                font-size: 3.5rem;
                /* Larger for emojis */
                display: flex;
                align-items: center;
                justify-content: center;
                background: linear-gradient(135deg, #27272a 0%, #09090b 100%);
            }

            .mv-folder-download-btn {
                background: #ffffff;
                color: var(--mv-text);
                border: 1px solid var(--mv-border);
                padding: 10px 16px;
                border-radius: 6px;
                font-size: 13px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s ease;
                margin-top: 12px;
                display: block;
                width: 100%;
                text-align: center;
                z-index: 5;
            }

            .mv-folder-download-btn:hover {
                background: var(--mv-surface-hover);
                color: white;
            }

            .mv-folder-view-btn {
                background: #ffffff;
                color: var(--mv-text);
                border: 1px solid var(--mv-border);
                padding: 10px 16px;
                border-radius: 6px;
                font-size: 13px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s ease;
                margin-top: 12px;
                display: block;
                width: calc(100% - 32px);
                /* Correct width accounting for margins */
                margin-left: 16px;
                margin-right: 16px;
                text-align: center;
                z-index: 5;
                text-decoration: none;
            }

            .mv-folder-view-btn:hover {
                background: var(--mv-surface-hover);
                color: white;
                border-color: var(--mv-accent);
            }

            .mv-sticky-unlock-bar {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                transform: translateY(105%);
                /* Hidden by default (pushed down) */
                background: rgba(255, 255, 255, 0.98);
                backdrop-filter: blur(12px);
                border-top: 1px solid #f59e0b;
                /* Only top border */
                border-radius: 16px 16px 0 0;
                /* Top corners rounded */
                padding: 16px 24px;
                /* More comfortable padding */
                display: flex;
                align-items: center;
                justify-content: center;
                /* Center content */
                gap: 20px;
                z-index: 10002;
                /* Above everything else */
                box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.1);
                transition: transform 0.4s cubic-bezier(0.19, 1, 0.22, 1);
                width: 100%;
                max-width: 100%;
            }

            .mv-sticky-unlock-bar.visible {
                transform: translateY(0);
            }

            .mv-sticky-text {
                font-size: 14px;
                color: var(--mv-text);
                flex: 1;
            }

            .mv-sticky-btn {
                background: linear-gradient(135deg, #f59e0b, #d97706);
                color: white;
                border: none;
                padding: 8px 20px;
                border-radius: 99px;
                font-weight: 600;
                font-size: 14px;
                cursor: pointer;
                box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
                white-space: nowrap;
            }

            .mv-sticky-btn:hover {
                transform: scale(1.05);
                box-shadow: 0 6px 16px rgba(245, 158, 11, 0.5);
            }

            /* Mobile Optimization for Banner */
            @media (max-width: 768px) {
                .mv-sticky-unlock-bar {
                    padding: 8px 16px;
                    gap: 10px;
                    bottom: 0;
                    border-top: 1px solid rgba(0, 0, 0, 0.05);
                }

                .mv-sticky-unlock-bar>span {
                    font-size: 18px !important;
                }

                .mv-sticky-text strong {
                    font-size: 13px;
                    display: block;
                    line-height: 1.2;
                }

                .mv-sticky-text span {
                    display: none;
                    /* Hide subtitle */
                }

                .mv-sticky-btn {
                    padding: 6px 12px;
                    font-size: 11px;
                }
            }

            /* Restricted Overlay Styles */
            .mv-playback-restricted-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(255, 255, 255, 0.98);
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-align: center;
                border-radius: 16px;
                z-index: 100;
                padding: 30px;
                box-sizing: border-box;
            }

            .mv-restricted-content {
                width: 100%;
                max-width: 500px;
                margin: 0 auto;
                box-sizing: border-box;
            }

            .mv-restricted-content h4 {
                margin: 0 0 16px;
                color: #0f172a;
                font-size: 22px;
                line-height: 1.2;
                font-weight: 800;
            }

            .mv-restricted-content p {
                margin: 0 0 24px;
                color: #64748b;
                font-size: 15px;
                line-height: 1.6;
            }

            .mv-close-restricted-btn {
                padding: 12px 24px;
                background: #ea580c;
                color: #ffffff;
                border: none;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s;
                font-size: 14px;
                box-shadow: 0 4px 12px rgba(234, 88, 12, 0.2);
            }

            .mv-close-restricted-btn:hover {
                background: #c2410c;
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(234, 88, 12, 0.3);
            }

            /* Mobile tweaks for Restricted Overlay */
            @media (max-width: 600px) {
                .mv-playback-restricted-overlay {
                    padding: 20px;
                }

                .mv-restricted-content h4 {
                    font-size: 18px;
                    margin-bottom: 12px;
                }

                .mv-restricted-content p {
                    font-size: 14px;
                    margin-bottom: 20px;
                }

                .mv-close-restricted-btn {
                    padding: 10px 20px;
                    font-size: 13px;
                }
            }

            .mv-preview-btn {
                background: var(--mv-accent);
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 6px;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s ease;
                flex: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
            }

            .mv-preview-btn:hover {
                background: var(--mv-accent-hover);
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
            }

            .mv-download-btn {
                background: var(--mv-surface-hover);
                color: var(--mv-text);
                border: 1px solid var(--mv-border);
                padding: 8px 16px;
                border-radius: 6px;
                font-size: 13px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s ease;
                flex: 1;
                text-align: center;
                text-decoration: none;
            }

            .mv-download-btn:hover {
                background: white;
                color: black;
            }

            .mv-unlock-btn {
                background: linear-gradient(135deg, #f59e0b, #d97706) !important;
                color: white !important;
                border: none !important;
                box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3) !important;
            }

            .mv-unlock-btn:hover {
                transform: translateY(-2px) scale(1.02);
                box-shadow: 0 6px 20px rgba(245, 158, 11, 0.5) !important;
            }

            .jpsm-mv-action-overlay {
                position: absolute;
                bottom: 10px;
                right: 10px;
                width: 48px;
                height: 48px;
                border-radius: 50%;
                background: var(--mv-accent);
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 24px;
                opacity: 0;
                transform: translateY(10px);
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                border: none;
                cursor: pointer;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            }

            .jpsm-mv-card:hover .jpsm-mv-action-overlay {
                opacity: 1;
                transform: translateY(0);
            }

            .jpsm-mv-action-overlay:hover {
                background: #2563eb;
                transform: scale(1.1);
            }

            .mv-desktop-recommendation-banner {
                display: none;
                align-items: flex-start;
                justify-content: space-between;
                gap: 16px;
                margin: 0 0 16px 0;
                padding: 14px 16px;
                border-radius: 12px;
                border: 1px solid var(--mv-border-hover);
                background: var(--mv-surface-hover);
                color: var(--mv-text);
            }

            .mv-desktop-recommendation-copy {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }

            .mv-desktop-recommendation-title {
                font-weight: 700;
                font-size: 14px;
                line-height: 1.25;
            }

            .mv-desktop-recommendation-text {
                color: var(--mv-text-muted);
                font-size: 13px;
                line-height: 1.4;
            }

            .mv-desktop-recommendation-btn {
                border: 1px solid var(--mv-border);
                background: #fff;
                color: var(--mv-text);
                border-radius: 10px;
                padding: 8px 12px;
                font-size: 12px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s ease;
                white-space: nowrap;
            }

            .mv-desktop-recommendation-btn:hover {
                border-color: var(--mv-border-hover);
                color: var(--mv-accent);
            }

            .mv-desktop-recommendation-modal {
                position: fixed;
                inset: 0;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 20px;
                background: rgba(15, 23, 42, 0.7);
                backdrop-filter: blur(5px);
                z-index: 10020;
            }

            .mv-desktop-recommendation-dialog {
                width: min(480px, 100%);
                background: var(--mv-surface);
                border: 1px solid var(--mv-border);
                border-radius: 16px;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
                padding: 24px;
            }

            .mv-desktop-recommendation-dialog h3 {
                margin: 0 0 8px;
                font-size: 20px;
                line-height: 1.2;
                color: var(--mv-text);
            }

            .mv-desktop-recommendation-dialog p {
                margin: 0;
                color: var(--mv-text-muted);
                line-height: 1.45;
                font-size: 14px;
            }

            .mv-desktop-recommendation-actions {
                margin-top: 18px;
                display: flex;
                justify-content: flex-end;
                gap: 10px;
            }

            .mv-desktop-recommendation-secondary,
            .mv-desktop-recommendation-primary {
                border-radius: 10px;
                padding: 10px 14px;
                border: 1px solid var(--mv-border);
                background: #fff;
                color: var(--mv-text);
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s ease;
            }

            .mv-desktop-recommendation-primary {
                border-color: var(--mv-accent);
                background: var(--mv-accent);
                color: #fff;
            }

            .mv-desktop-recommendation-secondary:hover {
                border-color: var(--mv-border-hover);
                color: var(--mv-accent);
            }

            .mv-desktop-recommendation-primary:hover {
                background: var(--mv-accent-hover);
                border-color: var(--mv-accent-hover);
            }


            /* --- Toast Notifications (Solid & Robust) --- */
            #mv-toast-container {
                position: fixed;
                bottom: 30px;
                right: 30px;
                z-index: 99999;
                display: flex;
                flex-direction: column;
                gap: 12px;
                pointer-events: none;
            }

            .mv-toast {
                background: #18181b;
                color: #ffffff;
                padding: 14px 24px;
                border-radius: 8px;
                border: 1px solid #27272a;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.6);
                font-size: 14px;
                font-weight: 500;
                min-width: 280px;
                pointer-events: auto;
                animation: mvSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .mv-toast.success {
                border-left: 5px solid #22c55e;
            }

            .mv-toast.info {
                border-left: 5px solid #3b82f6;
            }

            .mv-toast.error {
                border-left: 5px solid #ef4444;
            }

            @keyframes mvSlideIn {
                from {
                    transform: translateX(120%);
                    opacity: 0;
                }

                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }

            /* Mobile Responsiveness */
            @media (max-width: 768px) {
                .jpsm-mv-header-hero {
                    padding: 20px;
                }

                .jpsm-mv-toolbar {
                    flex-direction: column;
                    gap: 15px;
                    padding: 15px 20px;
                }

                .mv-search-box {
                    max-width: 100%;
                    width: 100%;
                }

                .mv-sort-controls {
                    width: 100%;
                }

                .mv-sort-controls select {
                    width: 100%;
                }

                .mv-desktop-recommendation-banner {
                    flex-direction: column;
                    align-items: stretch;
                }

                .mv-desktop-recommendation-btn {
                    width: 100%;
                }

                .mv-desktop-recommendation-actions {
                    flex-direction: column;
                }

                .mv-desktop-recommendation-secondary,
                .mv-desktop-recommendation-primary {
                    width: 100%;
                }

                .mv-job-item {
                    flex-direction: column;
                    align-items: flex-start;
                }

                .mv-job-progress {
                    width: 100%;
                    max-width: none;
                    margin: 10px 0;
                }
            }

            /* ============================================ */
            /* DOWNLOAD MANAGER PANEL                       */
            /* ============================================ */

            /* Floating Toggle Button */
            .mv-dm-toggle {
                position: fixed;
                bottom: 24px;
                right: 24px;
                width: 56px;
                height: 56px;
                border-radius: 50%;
                background: linear-gradient(135deg, var(--mv-accent), #1d4ed8);
                border: none;
                cursor: pointer;
                z-index: 1000;
                display: none;
                /* Hidden by default, shown via JS */
                align-items: center;
                justify-content: center;
                box-shadow: 0 8px 24px rgba(59, 130, 246, 0.4);
                transition: all 0.3s ease;
            }

            .mv-dm-toggle:hover {
                transform: scale(1.1);
                box-shadow: 0 12px 32px rgba(59, 130, 246, 0.6);
            }

            .mv-dm-toggle.visible {
                display: flex;
            }

            .mv-dm-toggle svg {
                width: 28px;
                height: 28px;
                fill: white;
            }

            .mv-dm-badge {
                position: absolute;
                top: -4px;
                right: -4px;
                background: var(--mv-danger);
                color: white;
                font-size: 11px;
                font-weight: 700;
                width: 22px;
                height: 22px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            /* Panel Container */
            .mv-dm-panel {
                position: fixed;
                bottom: 90px;
                right: 24px;
                width: 420px;
                max-height: 60vh;
                background: var(--mv-surface);
                border: 1px solid var(--mv-border);
                border-radius: 16px;
                z-index: 999;
                display: none;
                /* Hidden by default */
                flex-direction: column;
                box-shadow: 0 16px 48px rgba(0, 0, 0, 0.5);
                overflow: hidden;
            }

            .mv-dm-panel.open {
                display: flex;
            }

            /* Panel Header */
            .mv-dm-header {
                padding: 16px 20px;
                background: rgba(0, 0, 0, 0.3);
                border-bottom: 1px solid var(--mv-border);
                display: flex;
                align-items: center;
                justify-content: space-between;
            }

            .mv-dm-header h3 {
                margin: 0;
                font-size: 15px;
                font-weight: 600;
                color: var(--mv-text);
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .mv-dm-header-actions {
                display: flex;
                gap: 8px;
            }

            .mv-dm-header-btn {
                background: transparent;
                border: none;
                color: var(--mv-text-muted);
                cursor: pointer;
                padding: 4px 8px;
                border-radius: 6px;
                font-size: 18px;
                transition: all 0.2s;
            }

            .mv-dm-header-btn:hover {
                background: rgba(255, 255, 255, 0.1);
                color: white;
            }

            /* Panel Body (scrollable list) */
            .mv-dm-body {
                flex: 1;
                overflow-y: auto;
                padding: 12px;
            }

            .mv-dm-empty {
                text-align: center;
                color: var(--mv-text-muted);
                padding: 40px 20px;
                font-size: 14px;
            }

            /* Download Item */
            .mv-dm-item {
                background: rgba(0, 0, 0, 0.2);
                border: 1px solid var(--mv-border);
                border-radius: 12px;
                padding: 14px;
                margin-bottom: 10px;
            }

            .mv-dm-item:last-child {
                margin-bottom: 0;
            }

            .mv-dm-item-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 10px;
            }

            .mv-dm-item-name {
                font-weight: 600;
                font-size: 14px;
                color: var(--mv-text);
                display: flex;
                align-items: center;
                gap: 8px;
                flex: 1;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .mv-dm-item-actions {
                display: flex;
                gap: 6px;
                flex-shrink: 0;
            }

            .mv-dm-action-btn {
                background: rgba(255, 255, 255, 0.1);
                border: none;
                color: var(--mv-text-muted);
                padding: 6px 12px;
                border-radius: 6px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.2s;
                font-size: 12px;
                font-weight: 500;
                white-space: nowrap;
            }

            .mv-dm-action-btn:hover {
                background: rgba(255, 255, 255, 0.2);
                color: white;
            }

            .mv-dm-action-btn.pause {
                color: var(--mv-accent);
            }

            .mv-dm-action-btn.delete {
                color: var(--mv-danger);
            }

            /* Progress Bar */
            .mv-dm-progress-container {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 8px;
            }

            .mv-dm-progress-bar {
                flex: 1;
                height: 8px;
                background: rgba(255, 255, 255, 0.1);
                border-radius: 4px;
                overflow: hidden;
            }

            .mv-dm-progress-fill {
                height: 100%;
                background: linear-gradient(90deg, var(--mv-accent), #60a5fa);
                border-radius: 4px;
                transition: width 0.3s ease;
            }

            .mv-dm-progress-percent {
                font-size: 13px;
                font-weight: 600;
                color: var(--mv-text);
                min-width: 42px;
                text-align: right;
            }

            /* Stats Line */
            .mv-dm-stats {
                display: flex;
                gap: 12px;
                font-size: 12px;
                color: var(--mv-text-muted);
            }

            .mv-dm-stats span {
                display: flex;
                align-items: center;
                gap: 4px;
            }

            /* Status States */
            .mv-dm-item.completed .mv-dm-progress-fill {
                background: var(--mv-success);
            }

            .mv-dm-item.paused .mv-dm-progress-fill {
                background: #f59e0b;
            }

            .mv-dm-item.error .mv-dm-progress-fill {
                background: var(--mv-danger);
            }

            .mv-dm-item.queued .mv-dm-progress-fill {
                background: var(--mv-text-muted);
            }

            /* ============================================ */
            /* ADMIN PANEL (Mobile Dashboard)               */
            /* ============================================ */
            .mv-admin-toggle {
                position: fixed;
                bottom: 90px;
                right: 24px;
                width: 48px;
                height: 48px;
                border-radius: 50%;
                background: linear-gradient(135deg, #f59e0b, #d97706);
                border: none;
                cursor: pointer;
                z-index: 1001;
                display: none;
                align-items: center;
                justify-content: center;
                box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
                font-size: 20px;
            }

            .mv-admin-toggle.visible {
                display: flex;
            }

            .mv-admin-toggle:hover {
                transform: scale(1.1);
            }

            .mv-admin-panel {
                position: fixed;
                bottom: 150px;
                right: 24px;
                width: 380px;
                max-height: 70vh;
                background: var(--mv-surface);
                border: 1px solid var(--mv-border);
                border-radius: 16px;
                z-index: 1000;
                display: none;
                flex-direction: column;
                box-shadow: 0 16px 48px rgba(0, 0, 0, 0.5);
                overflow: hidden;
            }

            .mv-admin-panel.open {
                display: flex;
            }

            .mv-admin-header {
                padding: 16px;
                background: rgba(245, 158, 11, 0.1);
                border-bottom: 1px solid var(--mv-border);
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .mv-admin-header h3 {
                margin: 0;
                font-size: 15px;
                color: #f59e0b;
            }

            .mv-admin-tabs {
                display: flex;
                border-bottom: 1px solid var(--mv-border);
            }

            .mv-admin-tab {
                flex: 1;
                padding: 12px;
                background: transparent;
                border: none;
                color: var(--mv-text-muted);
                cursor: pointer;
                font-size: 13px;
                transition: all 0.2s;
            }

            .mv-admin-tab.active {
                color: #f59e0b;
                border-bottom: 2px solid #f59e0b;
                background: rgba(245, 158, 11, 0.05);
            }

            .mv-admin-body {
                padding: 16px;
                overflow-y: auto;
                flex: 1;
            }

            .mv-admin-search {
                width: 100%;
                padding: 10px 14px;
                border-radius: 8px;
                border: 1px solid var(--mv-border);
                background: rgba(0, 0, 0, 0.2);
                color: white;
                margin-bottom: 12px;
            }

            .mv-admin-user-card {
                background: rgba(0, 0, 0, 0.2);
                padding: 14px;
                border-radius: 10px;
                margin-bottom: 10px;
            }

            .mv-admin-user-email {
                font-size: 13px;
                color: var(--mv-text);
                margin-bottom: 8px;
            }

            .mv-admin-tier-select {
                width: 100%;
                padding: 8px;
                border-radius: 6px;
                border: 1px solid var(--mv-border);
                background: var(--mv-surface);
                color: white;
            }

            /* ============================================ */
            /* LOCKED FOLDER STYLES                         */
            /* ============================================ */
            .jpsm-mv-card.locked {
                opacity: 0.7;
                position: relative;
            }

            .mv-unlock-btn {
                background: linear-gradient(135deg, #22c55e, #16a34a) !important;
                color: white !important;
                border: none !important;
            }

            .mv-unlock-btn:hover {
                box-shadow: 0 4px 12px rgba(34, 197, 94, 0.4);
            }

            /* --- Enhanced Mobile Responsiveness --- */
            @media (max-width: 768px) {

                /* Container & Header */
                .jpsm-mv-container {
                    padding-bottom: 120px;
                    /* More space for sticky bars */
                }

                .jpsm-mv-header-hero {
                    flex-direction: column;
                    align-items: flex-start !important;
                    gap: 16px;
                    padding: 20px 20px 10px;
                }

                .jpsm-mv-header-hero>div:first-child {
                    width: 100%;
                }

                /* Hero Icon & Title */
                .jpsm-mv-hero-icon {
                    width: 64px;
                    height: 64px;
                    font-size: 32px;
                    min-width: 64px;
                    /* Prevent shrinking */
                }

                .jpsm-mv-hero-content h1 {
                    font-size: 24px;
                    line-height: 1.2;
                }

                .jpsm-mv-hero-meta {
                    font-size: 12px;
                    flex-wrap: wrap;
                }

                /* User Info Box - Stacked */
                .jpsm-mv-header-hero>div:last-child {
                    width: 100%;
                    text-align: left !important;
                    margin-top: 10px;
                }

                /* Toolbar */
                .jpsm-mv-toolbar {
                    flex-direction: column;
                    align-items: stretch;
                    gap: 12px;
                    padding: 16px;
                    top: 0;
                }

                .jpsm-mv-tools-left {
                    flex-direction: column;
                    width: 100%;
                    gap: 12px;
                }

                .jpsm-mv-search {
                    width: 100%;
                }

                .mv-sort-controls,
                .mv-sort-controls select {
                    width: 100%;
                }

                .mv-view-toggles {
                    width: 100%;
                    justify-content: center;
                }

                .mv-view-btn {
                    flex: 1;
                }

                /* Grid Layout - 2 Columns */
                .jpsm-mv-grid {
                    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                    gap: 12px;
                    padding: 16px;
                }

                .jpsm-mv-card {
                    padding-bottom: 8px;
                }

                .jpsm-mv-card-link {
                    padding: 12px;
                }

                .jpsm-mv-cover {
                    margin-bottom: 10px;
                }

                /* List View on Mobile suitable adjustments */
                .jpsm-mv-grid.view-list .jpsm-mv-card {
                    padding: 10px;
                    gap: 12px;
                }

                .jpsm-mv-grid.view-list .jpsm-mv-cover {
                    width: 40px;
                    height: 40px;
                }

                .jpsm-mv-grid.view-list .jpsm-mv-title {
                    font-size: 0.95rem;
                }

                .jpsm-mv-grid.view-list .jpsm-mv-meta {
                    display: none;
                }

                /* Breadcrumbs */
                .jpsm-mv-container>div[style*="padding: 20px"] {
                    padding: 16px 20px 0 !important;
                    flex-wrap: wrap;
                }
            }
        </style>

        <div class="jpsm-mv-container">
            <!-- Sidebar -->
            <aside class="mv-sidebar" id="mv-sidebar">
                <div class="mv-sidebar-header">
                    <div style="font-weight:700; font-size:18px; color:var(--mv-text); margin-bottom:4px;">Mi Biblioteca</div>
                    <div style="font-size:12px; color:var(--mv-text-muted);">Media Vault</div>
                </div>

                <nav class="mv-sidebar-nav">
                    <!-- Home link removed to prevent navigation to high-hierarchy levels -->

                    <!-- Quick Access Section -->
                    <?php if (!empty($sidebar_folders)): ?>
                        <div
                            style="padding: 16px 24px 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.5px;">
                            Navegación
                        </div>

                        <?php foreach ($sidebar_folders as $s_folder): ?>
                            <?php
                            // Conversion funnel rule: do NOT hide folders in the sidebar based on download permissions.
                            // All tiers can browse/preview everything; locks apply only to downloads.

                            $s_name = trim($s_folder, '/');
                            $s_display = basename($s_name);
                            $s_link = add_query_arg('folder', $s_folder);

                            // Check if active (if current folder starts with this folder).
                            // Normalize slashes to avoid flakiness when some links omit the trailing "/".
                            $current_norm = trim((string) $current_folder, '/') . '/';
                            $sidebar_norm = trim((string) $s_folder, '/') . '/';
                            $is_active = ($current_norm === $sidebar_norm) || (strpos($current_norm, $sidebar_norm) === 0);
                            ?>
                            <a href="<?php echo esc_url($s_link); ?>" class="mv-nav-item <?php echo $is_active ? 'active' : ''; ?>">
                                <span>📁</span> <?php echo esc_html($s_display); ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>

                </nav>

                <div class="mv-sidebar-footer">
                    <div style="font-size: 0.85rem; color: var(--mv-text-muted); margin-bottom: 8px;">
                        <?php
                        if (strpos($user_email, 'invitado_') === 0) {
                            echo "👤 Invitado (Demo)";
                        } else {
                            echo esc_html($user_email);
                        }
                        ?>
	                        <span
	                            style="background:#fff7ed; padding:2px 8px; border-radius:99px; font-size:0.75rem; margin-left:6px; color:#c2410c; border: 1px solid #fdba74;">
	                            <?php echo esc_html(JPSM_Access_Manager::get_tier_name($user_tier)); ?>
	                        </span>
                    </div>
                    <?php if ($user_tier == 0): ?>
                        <div style="background: #fff7ed; color: #c2410c; padding: 4px 12px; border-radius: 99px; font-size: 0.8rem; font-weight: 700; margin-bottom: 8px; display: inline-block; border: 1px solid #fdba74;"
                            id="mv-demo-usage">
                            🔥 <?php echo max(0, $remaining_plays); ?>/15 Restantes
                        </div>
                        <br>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(add_query_arg('action', 'mv_logout')); ?>"
                        style="color: var(--mv-danger); font-size: 0.85rem; text-decoration: none; font-weight: 500; display:inline-block; margin-top: 8px;">
                        Cerrar Sesión
                    </a>
                </div>
            </aside>

            <!-- Mobile Toggle & Overlay -->
            <button class="mv-mobile-toggle" id="mv-mobile-toggle" title="Abrir menú">☰</button>
            <div class="mv-overlay" id="mv-sidebar-overlay"></div>

            <!-- Main Content Wrapper -->
            <div class="mv-main-content">

                <!-- UPGRADE BANNER (Demo Users) -->
                <?php
                $email_banner = JPSM_Access_Manager::get_current_email();
                $tier_banner = JPSM_Access_Manager::get_user_tier($email_banner);
                if ($tier_banner === 0):
                    ?>
                    <div class="mv-upgrade-banner" style="
                        background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);
                        color: white;
                        padding: 12px 20px;
                        border-radius: 8px;
                        margin: 0 0 20px 0;
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        box-shadow: 0 4px 6px -1px rgba(245, 158, 11, 0.2);
                        flex-wrap: wrap;
                        gap: 12px;
                    ">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <span style="font-size:20px;">🔒</span>
                            <div>
                                <div style="font-weight:700; font-size:15px;">Modo Demo: Acceso Limitado</div>
                                <div style="font-size:13px; opacity:0.9;">Solo puedes visualizar el contenido. Actualiza tu plan
                                    para descargar.</div>
                            </div>
                        </div>
                        <?php
                        $mv_whatsapp_banner = (string) get_option('jpsm_whatsapp_number', '');
                        $mv_whatsapp_banner = preg_replace('/\\D+/', '', $mv_whatsapp_banner);
                        $banner_msg = 'Hola, quiero actualizar mi plan para obtener acceso completo. Mi correo es: ' . (string) $email_banner;
                        $banner_href = '';
                        if ($mv_whatsapp_banner !== '') {
                            $banner_href = 'https://wa.me/' . $mv_whatsapp_banner . '?text=' . rawurlencode($banner_msg);
                        }
                        ?>
                        <?php if ($banner_href !== ''): ?>
                            <a href="<?php echo esc_url($banner_href); ?>" target="_blank" style="
                                background: white;
                                color: #d97706;
                                padding: 8px 16px;
                                border-radius: 6px;
                                font-weight: 700;
                                font-size: 13px;
                                text-decoration: none;
                                white-space: nowrap;
                                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                                transition: opacity 0.2s;
                            " onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                                Obtener Acceso Completo
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div id="mv-desktop-recommendation-banner" class="mv-desktop-recommendation-banner" role="status"
                    aria-live="polite" aria-hidden="true">
                    <div class="mv-desktop-recommendation-copy">
                        <div class="mv-desktop-recommendation-title">Mejor experiencia en computadora</div>
                        <div class="mv-desktop-recommendation-text">
                            Para descargas avanzadas, recomendamos usar <strong>Google Chrome</strong> o
                            <strong>Microsoft Edge</strong> en computadora.
                        </div>
                    </div>
                    <button type="button" class="mv-desktop-recommendation-btn" id="mv-desktop-recommendation-dismiss">
                        Entendido
                    </button>
                </div>

                <!-- Breadcrumbs / Back Navigation -->
                <div id="mv-breadcrumbs">
                    <!-- High-hierarchy Inicio link removed to prevent download-cancelling reloads -->

                    <?php if ($effective_path): ?>
                        <?php
                        $parts = explode('/', trim($effective_path, '/'));
                        $path_accum = $junction_folder; // Start with the junction prefix
                        $last_key = array_key_last($parts);

                        foreach ($parts as $key => $part):
                            $path_accum = trim($path_accum, '/') . '/' . $part . '/';
                            $is_last = ($key === $last_key);
                            $link = add_query_arg('folder', trim($path_accum, '/'));
                            ?>
                            <?php if ($key > 0): ?>
                                <span class="separator">/</span>
                            <?php endif; ?>

                            <?php if ($is_last): ?>
                                <span style="color: var(--mv-text); font-weight: 600;"><?php echo esc_html($part); ?></span>
                            <?php else: ?>
                                <a href="<?php echo esc_url($link); ?>">
                                    <?php echo esc_html($part); ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Hero Header -->
                <div class="jpsm-mv-header-hero"
                    style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 20px;">

                    <div style="display:flex; align-items:center; gap:24px;">
                        <?php
                        $hero_title = $current_folder ? basename($current_folder) : 'Mi Biblioteca';
                        $hero_desc = $current_folder ? 'Explorando colección' : 'Bienvenido a tu bóveda personal';
                        ?>
                        <div class="jpsm-mv-hero-icon" id="mv-hero-icon">
                            <?php echo $current_folder ? '📂' : '🚀'; ?>
                        </div>
                        <div class="jpsm-mv-hero-content">
                            <div class="jpsm-mv-hero-meta" id="mv-hero-desc"><?php echo esc_html($hero_desc); ?> •
                                <?php echo count($data['files']); ?>
                                Archivos
                            </div>
                            <h1 id="mv-hero-title"><?php echo esc_html($hero_title); ?></h1>
                        </div>
                    </div>

                    <!-- User Info & Logout (Relative Flow) -->
                    <!-- Search Bar (Moved from Toolbar) -->
                    <div class="jpsm-mv-search"
                        style="background: white; border: 1px solid var(--mv-border); border-radius: 99px; padding: 10px 20px 10px 44px; display: flex; align-items: center; width: 320px; position: relative;">
                        <span class="mv-search-icon" style="position: absolute; left: 14px; opacity: 0.5;">🔍</span>
                        <input type="text" id="mv-search-input" placeholder="Buscar..." autocomplete="off"
                            style="border: none; outline: none; width: 100%; background: transparent; font-size: 15px; color: var(--mv-text);">
                        <button class="mv-search-clear" id="mv-search-clear" title="Limpiar"
                            style="display:none; background: transparent; border: none; font-size: 18px; cursor: pointer; color: #9ca3af;">×</button>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="jpsm-mv-toolbar">
                    <div class="jpsm-mv-tools-left">
                        <!-- In-app folder navigation (no page reload) -->
                        <div class="mv-nav-controls" aria-label="Navegación de carpetas">
                            <button type="button" class="mv-nav-btn" id="mv-nav-back" title="Atrás" aria-label="Atrás" disabled>
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="2" fill="none"
                                        stroke-linecap="round" stroke-linejoin="round"></path>
                                </svg>
                            </button>
                            <button type="button" class="mv-nav-btn" id="mv-nav-forward" title="Adelante" aria-label="Adelante" disabled>
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" fill="none"
                                        stroke-linecap="round" stroke-linejoin="round"></path>
                                </svg>
                            </button>
                            <button type="button" class="mv-nav-btn mv-nav-home" id="mv-nav-home" title="Inicio" aria-label="Inicio">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M3 11.5l9-7 9 7" stroke="currentColor" stroke-width="2" fill="none"
                                        stroke-linecap="round" stroke-linejoin="round"></path>
                                    <path d="M5 10.5V20h14v-9.5" stroke="currentColor" stroke-width="2" fill="none"
                                        stroke-linecap="round" stroke-linejoin="round"></path>
                                </svg>
                                <span class="mv-nav-home-text">Inicio</span>
                            </button>
                        </div>
                        <!-- Type Filters -->
                        <div class="mv-filter-group">
                            <button class="mv-filter-pill active" data-filter="all">Todo</button>
                            <button class="mv-filter-pill" data-filter="audio">🎵 Audio</button>
                            <button class="mv-filter-pill" data-filter="video">🎬 Video</button>
                        </div>
                        <div class="mv-bpm-filter-wrap" style="display:flex; align-items:center; gap:8px;">
                            <label for="mv-bpm-filter" style="font-size:12px; color:var(--mv-text-muted);">BPM</label>
                            <select id="mv-bpm-filter" style="height:34px; border:1px solid var(--mv-border); border-radius:8px; background:#fff; color:var(--mv-text);">
                                <option value="all">Todos</option>
                                <option value="80-99">80-99</option>
                                <option value="100-109">100-109</option>
                                <option value="110-119">110-119</option>
                                <option value="120-129">120-129</option>
                                <option value="130-139">130-139</option>
                                <option value="140-159">140-159</option>
                                <option value="160-180">160-180</option>
                            </select>
                        </div>
                    </div>
                    <!-- Global loader host (JS injects content); centered and always visible due to sticky toolbar -->
                    <div class="mv-toolbar-status" id="mv-toolbar-status" aria-live="polite"></div>
                    <div class="mv-sort-controls">
                        <?php if (!$force_list_view): ?>
                            <div class="mv-view-toggles">
                                <button type="button" class="mv-view-btn" data-view="grid" title="Vista Mosaico">⊞</button>
                                <button type="button" class="mv-view-btn active" data-view="list" title="Vista Lista">☰</button>
                            </div>
                        <?php endif; ?>
                        <select id="mv-sort-select">
                            <option value="name_asc">Nombre (A-Z)</option>
                            <option value="name_desc">Nombre (Z-A)</option>
                            <option value="date_desc">Más recientes</option>
                            <option value="size_desc">Tamaño</option>
                        </select>
                    </div>
                </div>

                <?php if ($limit_reached): ?>
                    <div style="background:var(--mv-danger); color:white; padding:15px; text-align:center; font-weight: bold;">
                        ⚠️ Límite de tráfico diario alcanzado. Vuelve mañana.
                    </div>
                <?php endif; ?>

                <!-- Content Grid -->
                <!-- Add force-list-view class if needed -->
                <div class="jpsm-mv-grid view-list <?php echo $force_list_view ? 'force-list-view' : ''; ?>" id="mv-grid">
                    <!-- FOLDERS -->
                    <?php foreach ($data['folders'] as $folder): ?>
                        <?php
                        $folder_name = trim($folder, '/');
                        $display_name = basename($folder_name);
                        $link = add_query_arg('folder', $folder);

                        // Stats attributes for sorting
                        $sort_name = strtolower($display_name);
                        ?>

                        <div class="jpsm-mv-card mv-item-folder" data-name="<?php echo esc_attr($sort_name); ?>" data-date=""
                            data-type="folder" data-path="<?php echo esc_attr($folder); ?>">
                            <a href="<?php echo esc_url($link); ?>" class="jpsm-mv-card-link">
                                <div class="jpsm-mv-cover">
                                    <div class="jpsm-mv-cover-icon">📁</div>
                                </div>
                                <div style="flex:1;">
                                    <div class="jpsm-mv-title">
                                        <span class="locked-icon"
                                            style="display:none; font-size: 0.9em; margin-right: 4px;">🔒</span>
                                        <?php echo esc_html($display_name); ?>
                                    </div>
                                    <div class="jpsm-mv-meta">Carpeta</div>
                                </div>
                            </a>

                            <?php if ($folder_depth >= 2): ?>
                                <!-- Level 3+ folders show Download button -->
                                <?php
                                $tier_check = JPSM_Access_Manager::get_user_tier(JPSM_Access_Manager::get_current_email());
                                if ($tier_check > 0):
                                    ?>
                                    <button type="button" class="mv-folder-download-btn" data-folder="<?php echo esc_attr($folder); ?>"
                                        data-name="<?php echo esc_attr($display_name); ?>" title="Descargar contenido de la carpeta">
                                        Descargar
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="mv-folder-download-btn locked"
                                        style="background:#3f3f46; cursor:not-allowed; opacity:0.7;"
                                        title="Descarga disponible solo en plan Premium">
                                        🔒 Descargar
                                    </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- Level 1 and 2 folders show View button -->
                                <a href="<?php echo esc_url($link); ?>" class="mv-folder-view-btn" title="Ver contenido de la carpeta">
                                    Ver contenido
                                </a>
                            <?php endif; ?>
                        </div>

                    <?php endforeach; ?>

                    <!-- FILES -->
                    <?php foreach ($data['files'] as $file): ?>
                        <?php
                        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $file_bpm = max(0, intval($file['bpm'] ?? 0));
                        ?>
                        <div class="jpsm-mv-card mv-item-file" data-name="<?php echo esc_attr(strtolower($file['name'])); ?>"
                            data-date="<?php echo esc_attr($file['date']); ?>" data-size="<?php echo esc_attr($file['size']); ?>"
                            data-bpm="<?php echo esc_attr($file_bpm); ?>" data-type="file" data-path="<?php echo esc_attr($file['path']); ?>">

                            <div class="jpsm-mv-cover">
                                <!-- Icon logic -->
                                <div class="jpsm-mv-cover-icon">
                                    <?php
                                    $icon = '📄';
                                    if (in_array(strtolower($ext), ['mp3', 'wav', 'flac']))
                                        $icon = '🎵';
                                    elseif (in_array(strtolower($ext), ['mp4', 'mov', 'mkv', 'avi']))
                                        $icon = '🎬';
                                    elseif (in_array(strtolower($ext), ['jpg', 'png', 'jpeg', 'gif', 'webp']))
                                        $icon = '🖼️';
                                    elseif (in_array(strtolower($ext), ['zip', 'rar', '7z']))
                                        $icon = '📦';
                                    echo $icon;
                                    ?>
                                </div>
                            </div>

                            <div style="flex:1;">
                                <div class="jpsm-mv-title">
                                    <span class="locked-icon" style="display:none; font-size: 0.9em; margin-right: 4px;">🔒</span>
                                    <?php echo esc_html($file['name']); ?>
                                </div>
	                                <div class="jpsm-mv-meta"><?php echo esc_html(strtoupper($ext)); ?> •
	                                    <?php echo size_format($file['size']); ?>
                                        <?php if ($file_bpm > 0): ?>
                                            • <?php echo esc_html($file_bpm); ?> BPM
                                        <?php endif; ?>
	                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div style="display:flex; gap:8px; margin-top:12px; padding: 0 16px;">
                                <?php if (in_array(strtolower($ext), ['mp3', 'wav', 'mp4', 'mov'])): ?>
                                    <button type="button" class="mv-preview-btn" data-path="<?php echo esc_attr($file['path']); ?>"
                                        data-name="<?php echo esc_attr($file['name']); ?>" data-type="<?php echo esc_attr($ext); ?>"
                                        title="Reproducir demostración">
                                        Reproducir
                                    </button>
                                <?php endif; ?>

                                <?php
                                if ($user_tier > 0):
                                    ?>
                                    <a href="#" class="mv-download-btn" data-path="<?php echo esc_attr($file['path']); ?>"
                                        data-name="<?php echo esc_attr($file['name']); ?>" data-type="file" title="Descargar archivo">
                                        Descargar
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="mv-download-btn locked"
                                        style="background:#3f3f46; cursor:not-allowed; opacity:0.7; border:none; color:white; padding:6px 12px; border-radius:6px;"
                                        title="Descarga disponible solo en plan Premium">
                                        🔒 Descargar
                                    </button>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div> <!-- End Grid -->
            </div> <!-- End Main Content -->
        </div> <!-- End Container -->

        <div id="mv-toast-container"></div>

        <!-- Sticky Unlock Bar (Demo Users) -->
        <div id="mv-sticky-unlock-bar" class="mv-sticky-unlock-bar">
            <span style="font-size:20px;">🔓</span>
            <div class="mv-sticky-text">
                <strong>¿Quieres todo el contenido?</strong><br>
                <span style="font-size:12px;opacity:0.8;">Accede a todas las descargas sin límites.</span>
            </div>
            <button class="mv-sticky-btn" id="mv-sticky-cta">Desbloquear contenido</button>
        </div>

        <!-- Download Manager Panel -->
        <button class="mv-dm-toggle" id="mv-dm-toggle" title="Descargas">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                <path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z" />
            </svg>
            <span class="mv-dm-badge" id="mv-dm-badge">0</span>
        </button>

        <div class="mv-dm-panel" id="mv-dm-panel">
            <div class="mv-dm-header">
                <h3>📥 Descargas <span id="mv-dm-count">(0)</span></h3>
                <div class="mv-dm-header-actions">
                    <button class="mv-dm-header-btn" id="mv-dm-minimize" title="Minimizar">−</button>
                    <button class="mv-dm-header-btn" id="mv-dm-close" title="Cerrar">×</button>
                </div>
            </div>

            <!-- Download Warning -->
            <div
                style="background:#ea580c; color:#fff; padding:12px; font-weight:700; text-align:center; font-size:13px; margin: 0; border-bottom: 1px solid rgba(255,255,255,0.1);">
                ⚠️ NO RECARGUES LA PÁGINA
                <div style="font-weight:400; font-size:11px; opacity:0.9; margin-top:2px;">
                    Puedes seguir navegando, pero tus descargas activas se perderán si sales o recargas.
                </div>
            </div>

            <div class="mv-dm-body" id="mv-dm-body">
                <div class="mv-dm-empty">No hay descargas activas</div>
            </div>
        </div>

        <?php if (self::is_frontend_admin_panel_enabled()): ?>
            <!-- Admin Panel (Mobile Dashboard) -->
            <button class="mv-admin-toggle" id="mv-admin-toggle" title="Panel Admin">⚙️</button>

            <div class="mv-admin-panel" id="mv-admin-panel">
                <div class="mv-admin-header">
                    <h3>⚙️ Panel de Admin</h3>
                    <button class="mv-dm-header-btn" id="mv-admin-close" title="Cerrar">×</button>
                </div>
                <div class="mv-admin-tabs">
                    <button class="mv-admin-tab active" data-tab="users">👥 Usuarios</button>
                    <button class="mv-admin-tab" data-tab="folders">📁 Carpetas</button>
                    <button class="mv-admin-tab" data-tab="leads">📊 Leads</button>
                    <button class="mv-admin-tab" data-tab="index">🔄 Índice</button>
                </div>
                <div class="mv-admin-body" id="mv-admin-body">
                    <!-- Content loaded dynamically by JS -->
                    <div id="mv-admin-tab-users">
                        <input type="text" class="mv-admin-search" id="mv-admin-user-search" placeholder="Buscar por email...">
                        <div id="mv-admin-user-results"></div>
                    </div>
                    <div id="mv-admin-tab-folders" style="display:none;">
                        <p style="color:#a1a1aa; font-size:13px;">Selecciona nivel mínimo para cada carpeta:</p>
                        <div id="mv-admin-folder-list"></div>
                    </div>
                    <div id="mv-admin-tab-leads" style="display:none;">
                        <div id="mv-admin-leads-list"></div>
                    </div>
                    <div id="mv-admin-tab-index" style="display:none;">
                        <div style="padding:12px 0;">
                            <p style="color:#a1a1aa; font-size:13px; margin-bottom:16px;">
                                El índice local permite búsquedas rápidas sin tiempos de espera.
                            </p>
                            <div id="mv-index-stats"
                                style="background:#27272a; border-radius:8px; padding:16px; margin-bottom:16px;">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; text-align:center;">
                                    <div>
                                        <div style="font-size:24px; font-weight:600; color:#f59e0b;" id="mv-index-total">-</div>
                                        <div style="font-size:11px; color:#a1a1aa;">Archivos</div>
                                    </div>
                                    <div>
                                        <div style="font-size:24px; font-weight:600; color:#22c55e;" id="mv-index-audio">-</div>
                                        <div style="font-size:11px; color:#a1a1aa;">Audio</div>
                                    </div>
                                    <div>
                                        <div style="font-size:24px; font-weight:600; color:#3b82f6;" id="mv-index-video">-</div>
                                        <div style="font-size:11px; color:#a1a1aa;">Video</div>
                                    </div>
                                    <div>
                                        <div style="font-size:14px; color:#a1a1aa;" id="mv-index-lastsync">Nunca</div>
                                        <div style="font-size:11px; color:#a1a1aa;">Última sync</div>
                                    </div>
                                </div>
                            </div>
                            <button id="mv-sync-index-btn" style="
                                width:100%; padding:14px; 
                                background:linear-gradient(135deg,#f59e0b,#d97706); 
                                border:none; border-radius:8px; 
                                color:#fff; font-weight:600; font-size:14px;
                                cursor:pointer; transition:all 0.2s ease;
                            ">
                                🔄 Sincronizar Índice
                            </button>
                            <p id="mv-sync-status" style="color:#a1a1aa; font-size:12px; margin-top:12px; text-align:center;">
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div id="mv-desktop-recommendation-modal" class="mv-desktop-recommendation-modal" aria-hidden="true">
            <div class="mv-desktop-recommendation-dialog" role="dialog" aria-modal="true"
                aria-labelledby="mv-desktop-recommendation-modal-title">
                <h3 id="mv-desktop-recommendation-modal-title">Antes de descargar desde móvil</h3>
                <p>
                    Esta función funciona mejor en computadora usando <strong>Google Chrome</strong> o
                    <strong>Microsoft Edge</strong>. En navegadores móviles algunas capacidades de descarga pueden no
                    estar disponibles.
                </p>
                <div class="mv-desktop-recommendation-actions">
                    <button type="button" class="mv-desktop-recommendation-secondary"
                        id="mv-desktop-recommendation-cancel">Cancelar</button>
                    <button type="button" class="mv-desktop-recommendation-primary"
                        id="mv-desktop-recommendation-continue">Continuar de todos modos</button>
                </div>
            </div>
        </div>

        <!-- Guidance Modal -->
        <div id="mv-guidance-modal" style="
            display:none; position:fixed; top:0; left:0; width:100%; height:100%; 
            background:rgba(15, 23, 42, 0.6); z-index:10000; 
            justify-content:center; align-items:center; backdrop-filter:blur(8px);
        ">
            <div style="
                background:#ffffff; border:1px solid #e2e8f0; border-radius:24px; 
                padding:40px; width:90%; max-width:480px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.15);
                color:#1e293b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; text-align:center;
            ">
                <div style="font-size:56px; margin-bottom:24px; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.05));">📂</div>
                <h3 style="margin:0 0 16px; font-size:22px; font-weight:800; color:#0f172a; line-height:1.3;">IMPORTANTE: LEER
                    ANTES DE CONTINUAR</h3>
                <div
                    style="color:#475569; font-size:15px; line-height:1.6; margin-bottom:32px; text-align:left; background:#f8fafc; padding:20px; border-radius:16px;">
                    <p style="margin-bottom:12px;">
                        Por seguridad, el navegador <strong>BLOQUEA</strong> el uso directo de carpetas del sistema como
                        <i>'Descargas'</i> o <i>'Documentos'</i>.
                    </p>
                    <p style="margin-bottom:8px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;">
                        <span
                            style="background:#22c55e; color:white; width:20px; height:20px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:10px;">✓</span>
                        SOLUCIÓN:
                    </p>
                    <ol style="margin:0; padding-left:20px; color:#334155;">
                        <li>Crea una <strong>NUEVA CARPETA</strong> en tu Escritorio (ej: 'Mis Packs').</li>
                        <li>Selecciona <strong>ESA</strong> carpeta nueva en el siguiente paso.</li>
                    </ol>
                </div>
                <div style="display:flex; gap:16px;">
                    <button id="mv-guidance-cancel" style="
                        flex:1; padding:14px; background:#f1f5f9; border:none; 
                        border-radius:12px; color:#64748b; font-weight:600; cursor:pointer;
                        transition: all 0.2s;
                    " onmouseover="this.style.background='#e2e8f0'"
                        onmouseout="this.style.background='#f1f5f9'">Cancelar</button>
                    <button id="mv-guidance-confirm" style="
                        flex:1.8; padding:14px; background:linear-gradient(135deg,#f59e0b,#ea580c); 
                        border:none; border-radius:12px; color:#fff; font-weight:700; cursor:pointer;
                        box-shadow:0 4px 12px rgba(245,158,11,0.3); transition: transform 0.2s, box-shadow 0.2s;
                    "
                        onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(245,158,11,0.4)';"
                        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(245,158,11,0.3)';">
                        Entendido, elegir carpeta
                    </button>
                </div>
            </div>
        </div>

        <?php
        // Pass user metadata to JavaScript
        $current_email = JPSM_Access_Manager::get_current_email();
        $user_tier = $current_email ? JPSM_Access_Manager::get_user_tier($current_email) : 0;
        $is_admin = class_exists('JPSM_Auth') ? JPSM_Auth::is_admin_authenticated(false) : false;
        $frontend_admin_panel_enabled = self::is_frontend_admin_panel_enabled();
        $remaining_plays = $current_email ? JPSM_Access_Manager::get_remaining_plays($current_email) : 0;
        $mv_whatsapp = (string) get_option('jpsm_whatsapp_number', '');
        $mv_whatsapp = preg_replace('/\\D+/', '', $mv_whatsapp);
        ?>
	        <?php
	        // Inline JSON inside <script> must escape tags to avoid `</script>` injection.
	        $mv_user_data = [
	            'email' => $current_email,
	            'tier' => intval($user_tier),
	            'tierName' => JPSM_Access_Manager::get_tier_name($user_tier),
	            'isAdmin' => ($frontend_admin_panel_enabled && $is_admin) ? true : false,
	            'frontendAdminPanelEnabled' => $frontend_admin_panel_enabled ? true : false,
	            'remainingPlays' => intval($remaining_plays),
	            'whatsappNumber' => $mv_whatsapp,
	            'folderPerms' => JPSM_Access_Manager::get_all_folder_permissions(),
	            'ajax_url' => admin_url('admin-ajax.php'),
	            'nonce' => wp_create_nonce('jpsm_mediavault_nonce'),
	            'access_nonce' => wp_create_nonce('jpsm_access_nonce'),
	            'sidebarFolders' => array_values($sidebar_folders),
	            'mediaExtensions' => class_exists('JPSM_Index_Manager')
	                ? JPSM_Index_Manager::get_media_extension_map()
	                : array(
	                    'audio' => array('mp3', 'wav', 'flac', 'm4a', 'ogg', 'aac'),
	                    'video' => array('mp4', 'mov', 'mkv', 'avi', 'webm', 'wmv'),
	                ),
	        ];
	        $mv_json_opts = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
	        ?>
	        <script>
	            window.MV_USER_DATA = <?php echo wp_json_encode($mv_user_data, $mv_json_opts); ?>;
	        </script>

                                <?php
                                return ob_get_clean();
    }

    /**
     * Map B2 folders to Images using jdd_catalog_item CPT
     */
    private static function get_folder_covers()
    {
        $covers = [];
        $args = [
            'post_type' => 'jdd_catalog_item',
            'posts_per_page' => -1,
            'meta_query' => [
                ['key' => '_jdd_drive_folder_id', 'compare' => 'EXISTS']
            ]
        ];
        $query = new WP_Query($args);

        foreach ($query->posts as $post) {
            $path = get_post_meta($post->ID, '_jdd_drive_folder_id', true);
            $img = get_post_meta($post->ID, '_jdd_image_1', true);
            if ($path && $img) {
                $covers[$path] = $img;
            }
        }
        return $covers;
    }

    private static function render_login_form()
    {
        ob_start();
        ?>
                                <div
                                    style="display:flex; justify-content:center; align-items:center; min-height:80vh; background-color:#f8fafc; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                                    <div
                                        style="background:white; padding:48px; border-radius:16px; box-shadow:0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); width:100%; max-width:420px; border:1px solid #e2e8f0;">
                                        <div style="text-align:center; margin-bottom:32px;">
                                            <h2 style="color:#0f172a; margin:0 0 12px 0; font-size:28px; font-weight:700;">🎵 MediaVault</h2>
                                            <p style="color:#64748b; margin:0; font-size:16px; line-height:1.5;">Ingresa tu correo para acceder al
                                                catálogo completo.</p>
                                        </div>

                                        <form method="post">
                                            <div style="margin-bottom:24px;">
                                                <label style="display:block; color:#334155; font-weight:600; font-size:14px; margin-bottom:8px;">Correo
                                                    Electrónico</label>
	                                                <input type="email" name="jdd_email" placeholder="tucorreo@example.com" required
	                                                    style="width:100%; padding:12px 16px; border-radius:8px; border:1px solid #cbd5e1; background:white; color:#0f172a; font-size:16px; outline:none; transition:all 0.2s;"
	                                                    onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)';"
	                                                    onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none';">
                                            </div>

                                            <button type="submit" name="jdd_login"
                                                style="width:100%; padding:14px; background:#2563eb; color:white; border:none; border-radius:8px; font-weight:600; font-size:16px; cursor:pointer; box-shadow:0 4px 6px -1px rgba(37, 99, 235, 0.2); transition:background 0.2s;"
                                                onmouseover="this.style.background='#1d4ed8';" onmouseout="this.style.background='#2563eb';">
                                                Entrar a la Bóveda
                                            </button>
                                        </form>

                                        <div style="margin-top:24px; text-align:center; border-top:1px solid #f1f5f9; padding-top:24px;">
                                            <p style="color:#64748b; font-size:14px; margin:0;">
                                                ¿No tienes cuenta? <span style="color:#2563eb;">Explora el catálogo demo</span> ingresando tu correo
                                                arriba.
                                            </p>
	                                        </div>
	                                    </div>
	                                </div>
	                                <?php if (!empty(self::$login_error)): ?>
	                                    <p style="color:#ef4444; text-align:center; margin-top:16px; font-weight:500;">
	                                        <?php echo esc_html(self::$login_error); ?>
	                                    </p>
	                                <?php endif; ?>
	                                <?php
	                                return ob_get_clean();
	    }
}
