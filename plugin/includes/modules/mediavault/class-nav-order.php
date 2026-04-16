<?php
/**
 * MediaVault Navigation Ordering
 *
 * Admin-defined ordering for the "junction level" folders shown in:
 * - Left sidebar navigation
 * - Home screen (default view when entering MediaVault)
 *
 * This is intentionally stored as a simple WP option to keep it portable.
 */

namespace JetpackStore\MediaVault;

use JetpackStore\Auth;
use JetpackStore\API_Contract;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

class Nav_Order
{
    const OPTION_KEY = 'jpsm_mediavault_sidebar_order';

    /**
     * Canonicalize folder paths:
     * - no leading/trailing slashes
     * - folder paths always end with '/'
     * - empty string stays empty
     */
    public static function normalize_folder_path($path)
    {
        $path = (string) $path;
        $path = trim($path);
        $path = preg_replace('#^/+|/+$#', '', $path);
        if ($path === '') {
            return '';
        }
        return $path . '/';
    }

    public static function get_saved_order()
    {
        $raw = get_option(self::OPTION_KEY, array());
        if (!is_array($raw)) {
            return array();
        }

        $out = array();
        foreach ($raw as $p) {
            $n = self::normalize_folder_path(is_string($p) ? $p : '');
            if ($n === '') {
                continue;
            }
            $out[$n] = true;
        }
        return array_keys($out);
    }

    /**
     * Apply saved ordering to a list of folder prefixes.
     * Unknown/new folders are appended in alphabetical order.
     */
    public static function apply_order($folders, $saved_order = null)
    {
        $folders = is_array($folders) ? $folders : array();

        $dedup = array();
        foreach ($folders as $f) {
            $n = self::normalize_folder_path(is_string($f) ? $f : '');
            if ($n === '') {
                continue;
            }
            $dedup[$n] = true;
        }
        $folders_norm = array_keys($dedup);

        if ($saved_order === null) {
            $saved_order = self::get_saved_order();
        }

        $pos = array();
        foreach ((array) $saved_order as $i => $p) {
            $p = self::normalize_folder_path(is_string($p) ? $p : '');
            if ($p === '') {
                continue;
            }
            if (!isset($pos[$p])) {
                $pos[$p] = (int) $i;
            }
        }

        usort($folders_norm, function ($a, $b) use ($pos) {
            $pa = isset($pos[$a]) ? $pos[$a] : PHP_INT_MAX;
            $pb = isset($pos[$b]) ? $pos[$b] : PHP_INT_MAX;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcasecmp($a, $b);
        });

        return $folders_norm;
    }

    /**
     * Discover the "junction" folder (the level used for sidebar navigation).
     * Wrapper folders (exactly 1 folder and 0 files) are drilled automatically.
     *
     * @return string|WP_Error Junction prefix (may be empty)
     */
    public static function detect_junction_folder($s3 = null, $max_depth = 10)
    {
        $drill_path = '';
        $depth_safety = 0;

        while ($depth_safety < (int) $max_depth) {
            $check = self::list_folder_structure($drill_path, $s3);
            if (is_wp_error($check)) {
                return $check;
            }

            $folders = isset($check['folders']) && is_array($check['folders']) ? $check['folders'] : array();
            $files = isset($check['files']) && is_array($check['files']) ? $check['files'] : array();

            if (count($folders) === 1 && empty($files)) {
                $drill_path = (string) $folders[0];
                $depth_safety++;
                continue;
            }

            return (string) $drill_path;
        }

        return (string) $drill_path;
    }

    /**
     * Unified listing helper: prefers the local index for speed, falls back to S3.
     */
    public static function list_folder_structure($folder, $s3 = null)
    {
        $folder = is_string($folder) ? $folder : '';
        if ($folder !== '' && substr($folder, -1) !== '/') {
            $folder .= '/';
        }

        if (
            class_exists(Index_Manager::class)
            && method_exists(Index_Manager::class, 'has_index_data')
            && method_exists(Index_Manager::class, 'list_folder_structure')
            && Index_Manager::has_index_data()
        ) {
            $idx = Index_Manager::list_folder_structure($folder);
            if (is_array($idx) && isset($idx['folders']) && isset($idx['files'])) {
                return $idx;
            }
        }

        if (!$s3) {
            if (!class_exists(S3_Client::class)) {
                require_once JPSM_PLUGIN_DIR . 'includes/modules/mediavault/class-s3-client.php';
            }

            $s3 = new S3_Client(
                JPSM_B2_KEY_ID,
                JPSM_B2_APP_KEY,
                JPSM_B2_REGION,
                JPSM_B2_BUCKET
            );
        }

        return $s3->list_objects($folder);
    }

    public static function get_available_sidebar_folders($s3 = null)
    {
        $junction = self::detect_junction_folder($s3);
        if (is_wp_error($junction)) {
            return $junction;
        }

        $content = self::list_folder_structure($junction, $s3);
        if (is_wp_error($content)) {
            return $content;
        }

        $folders = isset($content['folders']) && is_array($content['folders']) ? $content['folders'] : array();
        $ordered = self::apply_order($folders);

        return array(
            'junction_folder' => (string) $junction,
            'folders' => array_values($ordered),
            'saved_order' => self::get_saved_order(),
        );
    }

    private static function authorize_admin_request()
    {
        if (class_exists(Auth::class)) {
            return Auth::authorize_request(array(
                'require_nonce' => true,
                'nonce_actions' => array('jpsm_mediavault_nonce', 'jpsm_nonce', 'jpsm_access_nonce'),
                'allow_admin' => true,
                'allow_secret_key' => false,
                'allow_user_session' => false,
            ));
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        return new WP_Error('unauthorized', 'Unauthorized');
    }

    public static function ajax_get_sidebar_folders()
    {
        $auth = self::authorize_admin_request();
        if (is_wp_error($auth)) {
            API_Contract::send_wp_error($auth, 'No autorizado', 'unauthorized', 403);
        }

        $res = self::get_available_sidebar_folders();
        if (is_wp_error($res)) {
            API_Contract::send_wp_error($res, 'Error obteniendo carpetas', 'mv_nav_folders_error', 500);
        }

        API_Contract::send_success($res, 'mv_nav_folders_ok', 'OK');
    }

    public static function ajax_save_sidebar_order()
    {
        $auth = self::authorize_admin_request();
        if (is_wp_error($auth)) {
            API_Contract::send_wp_error($auth, 'No autorizado', 'unauthorized', 403);
        }

        $order = array();
        if (isset($_POST['order'])) {
            $order = (array) wp_unslash($_POST['order']);
        }

        $available = self::get_available_sidebar_folders();
        if (is_wp_error($available)) {
            API_Contract::send_wp_error($available, 'No se pudo validar la lista de carpetas', 'mv_nav_validate_error', 500);
        }

        $allowed = array();
        foreach (($available['folders'] ?? array()) as $f) {
            $n = self::normalize_folder_path($f);
            if ($n !== '') {
                $allowed[$n] = true;
            }
        }

        $clean = array();
        foreach ($order as $p) {
            $p = sanitize_text_field((string) $p);
            $n = self::normalize_folder_path($p);
            if ($n === '' || !isset($allowed[$n])) {
                continue;
            }
            $clean[$n] = true;
        }

        // Ensure all available folders are present, even if the client sent a partial list.
        foreach (array_keys($allowed) as $n) {
            if (!isset($clean[$n])) {
                $clean[$n] = true;
            }
        }

        $final = array_keys($clean);
        update_option(self::OPTION_KEY, array_values($final), 'no');

        API_Contract::send_success(array(
            'saved' => array_values($final),
        ), 'mv_nav_order_saved', 'OK');
    }

    public static function ajax_reset_sidebar_order()
    {
        $auth = self::authorize_admin_request();
        if (is_wp_error($auth)) {
            API_Contract::send_wp_error($auth, 'No autorizado', 'unauthorized', 403);
        }

        delete_option(self::OPTION_KEY);

        $res = self::get_available_sidebar_folders();
        if (is_wp_error($res)) {
            API_Contract::send_wp_error($res, 'Error obteniendo carpetas', 'mv_nav_folders_error', 500);
        }

        API_Contract::send_success($res, 'mv_nav_order_reset', 'OK');
    }
}

// Backward compatibility alias.
class_alias(Nav_Order::class, 'JPSM_MediaVault_Nav_Order');

