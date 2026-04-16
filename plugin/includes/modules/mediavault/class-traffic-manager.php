<?php
/**
 * Traffic Manager for JetPack MediaVault
 * Handles Bandwidth Quotas and Logging
 */

namespace JetpackStore\MediaVault;

class Traffic_Manager
{

    // 50GB Daily Limit in Bytes
    const DAILY_LIMIT = 53687091200;

    /**
     * Create or update the mediavault_logs database table.
     *
     * @return void
     */
    public static function install_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mediavault_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id varchar(100) NOT NULL,
            file_name varchar(255) NOT NULL,
            file_size bigint(20) NOT NULL DEFAULT 0,
            download_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            ip_address varchar(45) NOT NULL,
            PRIMARY KEY  (id),
            KEY user_date (user_id, download_date)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Check if user can download a specific file size within daily quota.
     *
     * @param string|int $user_id   User identifier (numeric WP ID or email).
     * @param int        $file_size File size in bytes.
     * @return bool True if the download fits within the daily limit.
     */
    public static function can_download($user_id, $file_size = 0)
    {
        // Bypass for Admins (if numeric ID)
        if (is_numeric($user_id) && user_can((int) $user_id, 'manage_options')) {
            return true;
        }

        $used = self::get_daily_usage($user_id);
        if (($used + $file_size) > self::DAILY_LIMIT) {
            return false;
        }

        return true;
    }

    /**
     * Get total bytes downloaded in the last 24 hours.
     *
     * @param string $user_id User identifier.
     * @return int Total bytes used.
     */
    public static function get_daily_usage($user_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mediavault_logs';

        $query = $wpdb->prepare(
            "SELECT SUM(file_size) FROM $table_name 
             WHERE user_id = %s 
             AND download_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
            $user_id
        );

        $usage = $wpdb->get_var($query);
        return $usage ? (int) $usage : 0;
    }

    /**
     * Log a successful download to the traffic log table.
     *
     * @param string $user_id   User identifier.
     * @param string $file_name Downloaded file name.
     * @param int    $file_size File size in bytes.
     * @return void
     */
    public static function log_download($user_id, $file_name, $file_size)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mediavault_logs';

        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'file_name' => $file_name,
                'file_size' => $file_size, // Bytes
                'download_date' => current_time('mysql'),
                'ip_address' => (isset($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP))
                    ? $_SERVER['REMOTE_ADDR'] : ''
            ),
            array('%s', '%s', '%d', '%s', '%s')
        );
    }
}

// Backward compatibility alias.
class_alias(Traffic_Manager::class, 'JPSM_Traffic_Manager');
