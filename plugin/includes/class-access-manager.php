<?php
namespace JetpackStore;

use JetpackStore\MediaVault\Index_Manager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JPSM Access Manager v2.0
 * Manages user access tiers and folder permissions.
 *
 * Tiers:
 *   0 = demo (Lead - can browse, limited playback, no downloads)
 *   1 = basic
 *   2 = vip
 *   3 = full
 */
class Access_Manager
{
    // Tier constants
    const TIER_DEMO = 0;
    const TIER_BASIC = 1;         // Básico
    const TIER_VIP_BASIC = 2;     // VIP + Básico
    const TIER_VIP_VIDEOS = 3;    // VIP + Videos Musicales
    const TIER_VIP_PELIS = 4;     // VIP + Pelis
    const TIER_FULL = 5;          // Full

    // Options keys
    const OPT_USER_TIERS = 'jpsm_user_tiers';
    const OPT_FOLDER_PERMS = 'jpsm_folder_permissions';
    const OPT_PLAY_COUNTS = 'jpsm_demo_play_counts';
    const OPT_LEADS = 'jpsm_leads_list';

    const DEMO_PLAY_LIMIT = 15;

    /**
     * Get tier name from integer value.
     *
     * @param int $tier Tier integer value.
     * @return string Tier slug name.
     */
    public static function get_tier_name($tier)
    {
        if (class_exists(Domain_Model::class)) {
            return Domain_Model::get_tier_name($tier);
        }

        $names = [
            self::TIER_DEMO => 'demo',
            self::TIER_BASIC => 'basic',
            self::TIER_VIP_BASIC => 'vip_basic',
            self::TIER_VIP_VIDEOS => 'vip_videos',
            self::TIER_VIP_PELIS => 'vip_pelis',
            self::TIER_FULL => 'full'
        ];
        return $names[$tier] ?? 'demo';
    }

    /**
     * Get tier integer from name.
     *
     * @param string $name Tier slug name.
     * @return int Tier integer value.
     */
    public static function get_tier_value($name)
    {
        if (class_exists(Domain_Model::class)) {
            return Domain_Model::get_tier_value($name);
        }

        $values = [
            'demo' => self::TIER_DEMO,
            'basic' => self::TIER_BASIC,
            'vip_basic' => self::TIER_VIP_BASIC,
            'vip_videos' => self::TIER_VIP_VIDEOS,
            'vip_pelis' => self::TIER_VIP_PELIS,
            'full' => self::TIER_FULL
        ];
        // Legacy fallback
        if ($name === 'vip')
            return self::TIER_VIP_VIDEOS; // Default to videos for old VIP

        return $values[strtolower($name)] ?? self::TIER_DEMO;
    }

    /**
     * Check if email exists in the sales log (purchased something).
     *
     * @param string $email User email address.
     * @return bool True if the email has at least one sale.
     */
    public static function is_customer($email)
    {
        if (!is_email($email)) {
            return false;
        }

        if (class_exists(Data_Layer::class)) {
            $sales = Data_Layer::get_sales_by_email($email);
            return !empty($sales);
        }

        $log = get_option('jpsm_sales_log', array());
        foreach ($log as $entry) {
            if (isset($entry['email']) && strcasecmp($entry['email'], $email) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get user's tier. If not found, returns DEMO (0).
     *
     * @param string $email User email address.
     * @return int Tier integer value.
     */
    public static function get_user_tier($email)
    {
        if (!is_email($email)) {
            return self::TIER_DEMO;
        }

        $email_lower = strtolower($email);

        if (class_exists(Data_Layer::class)) {
            // Preserve previous behavior: explicit tier wins (including explicit demo).
            $tiers = Data_Layer::get_all_user_tiers();
            if (isset($tiers[$email_lower])) {
                $tier = intval($tiers[$email_lower]);
                return $tier;
            }
        } else {
            wp_cache_delete(self::OPT_USER_TIERS, 'options');
            $tiers = get_option(self::OPT_USER_TIERS, []);
            if (isset($tiers[$email_lower])) {
                $tier = intval($tiers[$email_lower]);
                return $tier;
            }
        }

        // Lazy Sync: If not set, check sales log
        $calculated = self::calculate_tier_from_sales($email);
        if ($calculated > self::TIER_DEMO) {
            // Save for future speed
            self::set_user_tier($email, $calculated);
            return $calculated;
        }

        return self::TIER_DEMO;
    }

    /**
     * Set user's tier.
     *
     * @param string $email User email address.
     * @param int    $tier  Tier integer value.
     * @return bool True on success.
     */
    public static function set_user_tier($email, $tier)
    {
        if (!is_email($email)) {
            return false;
        }

        if (class_exists(Data_Layer::class)) {
            return Data_Layer::set_user_tier($email, intval($tier));
        }

        $tiers = get_option(self::OPT_USER_TIERS, []);
        $tiers[strtolower($email)] = intval($tier);
        return update_option(self::OPT_USER_TIERS, $tiers);
    }

    /**
     * Register a new lead (Demo user).
     * Called when someone enters an email that isn't a customer.
     *
     * @param string $email User email address.
     * @return bool True on success.
     */
    public static function register_lead($email)
    {
        if (!is_email($email)) {
            return false;
        }

        $email_lower = strtolower($email);

        // Skip saving "Guest Mode" temporary emails to keep the DB clean
        if (strpos($email_lower, 'invitado_') === 0) {
            // distinct from leads list, also do NOT save to user_tiers
            // get_user_tier() returns DEMO (0) by default which is what we want
            return true;
        }

        if (class_exists(Data_Layer::class)) {
            Data_Layer::register_lead($email, 'mediavault_demo', current_time('mysql'));
        } else {
            // Add to leads list
            $leads = get_option(self::OPT_LEADS, []);
            if (!isset($leads[$email_lower])) {
                $leads[$email_lower] = [
                    'email' => $email,
                    'registered' => current_time('mysql'),
                    'source' => 'mediavault_demo'
                ];
                update_option(self::OPT_LEADS, $leads);
            }
        }

        // Set tier to demo
        self::set_user_tier($email, self::TIER_DEMO);

        return true;
    }

    /**
     * Get all registered leads.
     *
     * @return array Leads list keyed by lowercase email.
     */
    public static function get_leads()
    {
        if (class_exists(Data_Layer::class)) {
            return Data_Layer::get_leads();
        }
        return get_option(self::OPT_LEADS, []);
    }

    /**
     * Calculate tier based on sales log purchase history.
     *
     * @param string $email User email address.
     * @return int Highest tier found in sales history.
     */
    public static function calculate_tier_from_sales($email)
    {
        if (!is_email($email)) {
            return self::TIER_DEMO;
        }

        $log = class_exists(Data_Layer::class)
            ? Data_Layer::get_sales_by_email($email)
            : get_option('jpsm_sales_log', array());
        $max_tier = self::TIER_DEMO; // Start at Demo, upgrade if purchase found

        foreach ($log as $entry) {
            if (isset($entry['email']) && strcasecmp($entry['email'], $email) === 0) {
                $package = isset($entry['package']) ? $entry['package'] : '';
                $tier = self::get_tier_from_package($package);
                if ($tier > $max_tier) {
                    $max_tier = $tier;
                }
            }
        }

        return $max_tier;
    }

    /**
     * Map package name to tier integer.
     */
    private static function get_tier_from_package($package_name)
    {
        if (class_exists(Domain_Model::class)) {
            return Domain_Model::get_package_tier($package_name);
        }

        $pkg = mb_strtolower($package_name);

        if (strpos($pkg, 'full') !== false || strpos($pkg, 'active') !== false) {
            return self::TIER_FULL;
        }

        // Specific VIP sub-types
        if (strpos($pkg, 'videos') !== false) {
            return self::TIER_VIP_VIDEOS;
        }

        if (strpos($pkg, 'películas') !== false || strpos($pkg, 'pelis') !== false) {
            return self::TIER_VIP_PELIS;
        }

        if (strpos($pkg, 'vip') !== false && strpos($pkg, 'básico') !== false) {
            return self::TIER_VIP_BASIC;
        }

        // Generic VIP fallback (default to logic or assume something mid-range)
        // Let's assume generic VIP means VIP+Videos as it was the most common "VIP" previously
        if (strpos($pkg, 'vip') !== false) {
            return self::TIER_VIP_VIDEOS;
        }

        return self::TIER_BASIC;
    }

    /**
     * Check and handle login. Returns tier.
     * If user is a customer, returns their tier (or sets to basic if not set).
     * If user is not a customer, registers as lead and returns demo.
     *
     * @param string $email User email address.
     * @return int|false Tier integer on success, false on invalid email.
     */
    public static function process_login($email)
    {
        if (!is_email($email)) {
            return false;
        }

        $email_lower = strtolower($email);
        $current_tier = self::get_user_tier($email);

        // 1. If user ALREADY has a tier > DEMO (e.g. manually assigned), respect it.
        if ($current_tier > self::TIER_DEMO) {
            return $current_tier;
        }

        // 2. Check Sales Log (Auto-upgrade if found)
        if (self::is_customer($email)) {
            // Existing customer
            // Calculate from sales log since we are currently at DEMO
            $calculated_tier = self::calculate_tier_from_sales($email);

            // Only set if calculated is valid, otherwise keep as is
            if ($calculated_tier > self::TIER_DEMO) {
                self::set_user_tier($email, $calculated_tier);
                return $calculated_tier;
            }

            return self::TIER_DEMO;
        } else {
            // 3. New lead (only if effectively Demo)
            // Register lead if not already there, but DO NOT overwrite if manually set top-level (covered by step 1)
            self::register_lead($email);
            return self::TIER_DEMO;
        }
    }

    /**
     * Check if user has access to a folder.
     *
     * @param string $email       User email address.
     * @param string $folder_path Folder path to check access for.
     * @return bool True if the user's tier is allowed.
     */
    public static function can_access_folder($email, $folder_path)
    {
        $user_tier = self::get_user_tier($email);
        $allowed = self::get_folder_allowed_tiers($folder_path);

        $can_access = self::user_can_access($folder_path, $user_tier);

        // Debug only (avoid PII in logs by default).
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                "[JPSM Access] Tier %d | Folder: %s | Allowed: [%s] | Access: %s",
                $user_tier,
                $folder_path,
                implode(',', $allowed),
                $can_access ? 'YES' : 'NO'
            ));
        }

        // return $user_tier >= $required_tier; // REMOVED: Legacy check that ignored specific tier lists
        return $can_access;
    }

    /**
     * Get allowed tiers for a specific folder.
     * Returns array of integers, e.g. [1, 3, 5]
     *
     * @param string $folder_path Folder path to look up.
     * @return int[] Array of allowed tier integers.
     */
    public static function get_folder_allowed_tiers($folder_path)
    {
        $perms = get_option(self::OPT_FOLDER_PERMS, []);

        $folder_path = (string) $folder_path;

        // NOTE: Some callers use a trailing "/" and some don't. Try both formats consistently.
        $trimmed = trim($folder_path, '/');
        $keys_to_try = array_unique(array_filter([
            $folder_path,
            $trimmed,
            $trimmed !== '' ? $trimmed . '/' : '',
            rtrim($folder_path, '/'),
            rtrim($folder_path, '/') !== '' ? rtrim($folder_path, '/') . '/' : '',
        ], function ($key) {
            return $key !== '';
        }));

        // Exact match first (normalized) - supports legacy ints and arrays.
        foreach ($keys_to_try as $key) {
            if (!array_key_exists($key, $perms)) {
                continue;
            }
            $normalized = self::normalize_folder_permission_value($perms[$key]);
            if (is_array($normalized)) {
                return $normalized;
            }
        }

        // Check parent folders RECURSIVELY
        $parts = explode('/', trim($folder_path, '/'));
        while (count($parts) > 0) {
            array_pop($parts); // Remove current level to check parent
            if (empty($parts))
                break;

            $test_path = implode('/', $parts); // e.g. "Music"
            // Note: Use matching check on keys. Some keys might have trailing slash.
            // Best to normalize keys in DB, but for now exact match.

            // Check for permissions on the parent folder (supports legacy ints and arrays).
            foreach ([$test_path, $test_path . '/'] as $parent_key) {
                if (!array_key_exists($parent_key, $perms)) {
                    continue;
                }
                $normalized = self::normalize_folder_permission_value($perms[$parent_key]);
                if (is_array($normalized)) {
                    return $normalized;
                }
            }
        }

        // Default: Soft Restriction (All Paid Users)
        // Reinstating "Soft Restrictions" as requested.
        return [
            self::TIER_DEMO,
            self::TIER_BASIC,
            self::TIER_VIP_BASIC,
            self::TIER_VIP_VIDEOS,
            self::TIER_VIP_PELIS,
            self::TIER_FULL
        ];
    }

    /**
     * Set allowed tiers for a folder.
     *
     * @param string $folder_path Folder path.
     * @param int[]  $tiers_array Array of allowed tier integers.
     * @return bool True on success.
     */
    public static function set_folder_allowed_tiers($folder_path, $tiers_array)
    {
        $perms = get_option(self::OPT_FOLDER_PERMS, []);
        // Ensure unique integers
        $perms[$folder_path] = array_unique(array_map('intval', $tiers_array));
        return update_option(self::OPT_FOLDER_PERMS, $perms);
    }

    /**
     * Verify if a user (by Tier) can access a folder.
     *
     * @param string $folder_path Folder path.
     * @param int    $user_tier   User's tier integer.
     * @return bool True if the tier is allowed for this folder.
     */
    public static function user_can_access($folder_path, $user_tier)
    {
        // Full Tier always has access
        if ($user_tier === self::TIER_FULL) {
            return true;
        }

        $allowed = self::get_folder_allowed_tiers($folder_path);
        return in_array($user_tier, $allowed);
    }

    /**
     * Legacy Wrapper: Returns "minimum" tier for backward compat if needed.
     * But we should move away from this.
     *
     * @param string $folder_path Folder path.
     * @return int Minimum allowed tier.
     */
    public static function get_folder_tier($folder_path)
    {
        $allowed = self::get_folder_allowed_tiers($folder_path);
        if (empty($allowed))
            return self::TIER_BASIC;
        return min($allowed);
    }

    /**
     * Deprecated setter (Legacy single value).
     *
     * @param string $folder_path Folder path.
     * @param int    $tier        Minimum tier value.
     * @return bool True on success.
     */
    public static function set_folder_tier($folder_path, $tier)
    {
        // If legacy set called (e.g. from unprocessed ajax), convert to range
        return self::set_folder_allowed_tiers($folder_path, self::expand_min_tier_to_allowed_tiers($tier));
    }

    /**
     * Convert a minimum tier to a full allowed tier list: [min..TIER_FULL].
     *
     * @return array<int>
     */
    private static function expand_min_tier_to_allowed_tiers($min_tier)
    {
        $min_tier = intval($min_tier);
        $allowed = [];
        for ($i = $min_tier; $i <= self::TIER_FULL; $i++) {
            $allowed[] = $i;
        }
        return $allowed;
    }

    /**
     * Normalize a stored folder permission value (legacy int or array) into an allowed tier list.
     *
     * Backward-compat: a singleton array is treated as a "minimum tier" (same semantics as legacy ints),
     * since older UIs post a single `tier` value meaning "tier or higher".
     *
     * @return array<int>|null
     */
    private static function normalize_folder_permission_value($val)
    {
        if (is_numeric($val)) {
            return self::expand_min_tier_to_allowed_tiers($val);
        }

        if (is_array($val)) {
            $tiers = array_values(array_unique(array_map('intval', $val)));
            $tiers = array_values(array_filter($tiers, function ($tier) {
                return $tier >= self::TIER_DEMO && $tier <= self::TIER_FULL;
            }));
            sort($tiers);

            if (count($tiers) === 1) {
                return self::expand_min_tier_to_allowed_tiers($tiers[0]);
            }

            return $tiers;
        }

        return null;
    }

    /**
     * Get all folder permissions, normalized to allowed-tiers arrays.
     *
     * @return array<string, int[]> Folder path => allowed tiers map.
     */
    public static function get_all_folder_permissions()
    {
        $perms = get_option(self::OPT_FOLDER_PERMS, []);
        if (!is_array($perms)) {
            return [];
        }

        // Ensure frontend consumers always receive an allowed-tiers array (no legacy ints / buggy singleton arrays).
        foreach ($perms as $path => $val) {
            $normalized = self::normalize_folder_permission_value($val);
            if (is_array($normalized)) {
                $perms[$path] = $normalized;
            }
        }

        return $perms;
    }

    /**
     * Get the demo play count for a user.
     *
     * @param string $email User email address.
     * @return int Current play count.
     */
    public static function get_play_count($email)
    {
        if (class_exists(Data_Layer::class)) {
            return Data_Layer::get_play_count($email);
        }

        $counts = get_option(self::OPT_PLAY_COUNTS, []);
        return isset($counts[strtolower($email)]) ? intval($counts[strtolower($email)]) : 0;
    }

    /**
     * Increment the demo play count for a user.
     *
     * @param string $email User email address.
     * @return int Updated play count.
     */
    public static function increment_play_count($email)
    {
        if (class_exists(Data_Layer::class)) {
            return Data_Layer::increment_play_count($email);
        }

        $counts = get_option(self::OPT_PLAY_COUNTS, []);
        $email_lower = strtolower($email);
        $counts[$email_lower] = isset($counts[$email_lower]) ? $counts[$email_lower] + 1 : 1;
        update_option(self::OPT_PLAY_COUNTS, $counts);
        return $counts[$email_lower];
    }

    /**
     * Log a play event and return updated count and remaining plays.
     *
     * @param string $email User email address.
     * @return array{count: int, remaining: int} Play status.
     */
    public static function log_play($email)
    {
        self::increment_play_count($email);
        return [
            'count' => self::get_play_count($email),
            'remaining' => self::get_remaining_plays($email)
        ];
    }

    /**
     * Check if a user can play (paid users unlimited, demo users capped).
     *
     * @param string $email User email address.
     * @return bool True if the user has remaining plays.
     */
    public static function can_play($email)
    {
        $tier = self::get_user_tier($email);
        if ($tier > self::TIER_DEMO) {
            return true; // Paid users have unlimited plays
        }
        return self::get_play_count($email) < self::DEMO_PLAY_LIMIT;
    }

    /**
     * Get the number of remaining demo plays for a user.
     *
     * @param string $email User email address.
     * @return int Remaining plays, or -1 for unlimited (paid users).
     */
    public static function get_remaining_plays($email)
    {
        $tier = self::get_user_tier($email);
        if ($tier > self::TIER_DEMO) {
            return -1; // Unlimited
        }
        return max(0, self::DEMO_PLAY_LIMIT - self::get_play_count($email));
    }

    /**
     * Check if the current request has a valid session.
     *
     * @return bool True if a valid session email exists.
     */
    public static function check_current_session()
    {
        return !empty(self::get_current_email());
    }

    /**
     * Get current user email from session.
     *
     * @return string|null Email address or null if no session.
     */
    public static function get_current_email()
    {
        if (class_exists(Auth::class)) {
            return Auth::get_current_user_email();
        }
        return null;
    }

    /**
     * Set access cookie and process login.
     *
     * @param string $email User email address.
     * @return bool True on success, false on failure.
     */
    public static function set_access_cookie($email)
    {
        if (!is_email($email)) {
            return false;
        }

        // Process login (registers lead if needed)
        $tier = self::process_login($email);

        if ($tier === false) {
            return false;
        }

        if (class_exists(Auth::class)) {
            return Auth::set_user_session_cookie($email);
        }

        return false;
    }

    /**
     * Check if user is admin (can manage permissions).
     *
     * @param string $email User email address.
     * @return bool True if the email is in the admin list.
     */
    public static function is_admin($email)
    {
        $admin_emails = get_option('jpsm_admin_emails', array());
        if (!is_array($admin_emails)) {
            $admin_emails = array();
        }

        $normalized = array();
        foreach ($admin_emails as $addr) {
            $addr = sanitize_email((string) $addr);
            if ($addr && is_email($addr)) {
                $normalized[] = strtolower($addr);
            }
        }

        return in_array(strtolower((string) $email), $normalized, true);
    }

    /**
     * Verify if the current user has a valid Admin Session via Cookie.
     *
     * @return bool True if the user is a WP admin or has a signed admin session.
     */
    public static function verify_session()
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        // Signed admin session
        if (class_exists(Auth::class) && Auth::verify_admin_session()) {
            return true;
        }

        return false;
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * Shared admin endpoint guard: nonce + admin auth.
     */
    private static function authorize_admin_ajax($allow_secret_key = false, $nonce_actions = array('jpsm_nonce'))
    {
        if (!class_exists(Auth::class)) {
            API_Contract::send_error('Auth service unavailable', 'auth_unavailable', 500);
        }

        $accepted_nonce_actions = array_unique(array_merge((array) $nonce_actions, array('jpsm_mediavault_nonce')));

        $auth = Auth::authorize_request(array(
            'require_nonce' => true,
            'nonce_actions' => $accepted_nonce_actions,
            'allow_admin' => true,
            'allow_secret_key' => (bool) $allow_secret_key,
            'allow_user_session' => false,
        ));

        if (is_wp_error($auth)) {
            $message = $auth->get_error_code() === 'invalid_nonce' ? 'Invalid nonce' : 'Unauthorized';
            $code = $auth->get_error_code() === 'invalid_nonce' ? 'invalid_nonce' : 'unauthorized';
            $status = $auth->get_error_code() === 'invalid_nonce' ? 401 : 403;
            API_Contract::send_error($message, $code, $status);
        }
    }

    /**
     * AJAX handler for admin login via access key.
     *
     * @return void Sends JSON response and exits.
     */
    public static function login_ajax()
    {
        if (!class_exists(Auth::class) || !Auth::validate_request_nonce(array('jpsm_nonce', 'jpsm_login_nonce'))) {
            API_Contract::send_error('Invalid nonce', 'invalid_nonce', 401);
        }

        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        $validation = API_Contract::validate_required_fields(array('key' => $key));
        if (is_wp_error($validation)) {
            API_Contract::send_wp_error($validation, 'Clave requerida', 'missing_required_fields', 422);
        }

        if (Auth::set_admin_session_from_key($key)) {
            API_Contract::send_success('Bienvenido', 'login_success', 'Sesión iniciada.', 200);
        } else {
            API_Contract::send_error('Clave incorrecta', 'invalid_access_key', 401);
        }
    }

    /**
     * AJAX handler for admin logout.
     *
     * @return void Sends JSON response and exits.
     */
    public static function logout_ajax()
    {
        if (!class_exists(Auth::class) || !Auth::validate_request_nonce(array('jpsm_nonce', 'jpsm_logout_nonce'))) {
            API_Contract::send_error('Invalid nonce', 'invalid_nonce', 401);
        }

        Auth::clear_admin_session_cookie();
        API_Contract::send_success('Bye', 'logout_success', 'Sesión cerrada.', 200);
    }

    /**
     * AJAX handler to get user tier information by email.
     *
     * @return void Sends JSON response and exits.
     */
    public static function get_user_tier_ajax()
    {
        self::authorize_admin_ajax(false, array('jpsm_nonce', 'jpsm_access_nonce'));

        $email = isset($_GET['email']) ? sanitize_email(wp_unslash($_GET['email'])) : '';
        $validation = API_Contract::validate_required_fields(array('email' => $email));
        if (is_wp_error($validation)) {
            API_Contract::send_wp_error($validation, 'Email requerido', 'missing_required_fields', 422);
        }

        $tier = self::get_user_tier($email);
        $is_customer = self::is_customer($email);
        $plays = self::get_remaining_plays($email);

        API_Contract::send_success([
            'email' => $email,
            'tier' => $tier,
            'tier_name' => self::get_tier_name($tier),
            'is_customer' => $is_customer,
            'plays' => $plays
        ], 'tier_fetched', 'Nivel de usuario obtenido.', 200);
    }

    /**
     * AJAX handler to update a user's tier.
     *
     * @return void Sends JSON response and exits.
     */
    public static function update_user_tier_ajax()
    {
        self::authorize_admin_ajax(false, array('jpsm_nonce', 'jpsm_access_nonce'));

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $tier = isset($_POST['tier']) ? intval($_POST['tier']) : 0;

        $validation = API_Contract::validate_required_fields(array('email' => $email));
        if (is_wp_error($validation)) {
            API_Contract::send_wp_error($validation, 'Email inválido', 'missing_required_fields', 422);
        }
        if ($tier < self::TIER_DEMO || $tier > self::TIER_FULL) {
            API_Contract::send_error('Tier fuera de rango permitido.', 'invalid_tier', 422, array(
                'min' => self::TIER_DEMO,
                'max' => self::TIER_FULL,
                'provided' => $tier,
            ));
        }

        $res = self::set_user_tier($email, $tier);
        if ($res) {
            API_Contract::send_success(['saved' => true, 'new_value' => $tier], 'tier_updated', 'Nivel actualizado.', 200);
        } else {
            API_Contract::send_error('Error guardando en BD', 'tier_update_failed', 500);
        }
    }

    /**
     * AJAX handler to log a demo play event.
     *
     * @return void Sends JSON response and exits.
     */
    public static function log_play_ajax()
    {
        if (!class_exists(Auth::class)) {
            API_Contract::send_error('Auth service unavailable', 'auth_unavailable', 500);
        }

        $auth = Auth::authorize_request(array(
            'require_nonce' => true,
            'nonce_actions' => array('jpsm_nonce', 'jpsm_mediavault_nonce'),
            'allow_admin' => true,
            'allow_secret_key' => false,
            'allow_user_session' => true,
        ));
        if (is_wp_error($auth)) {
            $message = $auth->get_error_code() === 'invalid_nonce' ? 'Invalid nonce' : 'Unauthorized';
            $code = $auth->get_error_code() === 'invalid_nonce' ? 'invalid_nonce' : 'unauthorized';
            $status = $auth->get_error_code() === 'invalid_nonce' ? 401 : 403;
            API_Contract::send_error($message, $code, $status);
        }

        $session_email = self::get_current_email();
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if ($session_email && !self::is_admin($session_email)) {
            // Enforce session identity for non-admin callers.
            $email = $session_email;
        }

        if (!$email) {
            API_Contract::send_error('Email inválido', 'invalid_email', 422);
        }

        $res = self::log_play($email);
        API_Contract::send_success($res, 'play_logged', 'Reproducción registrada.', 200);
    }

    /**
     * AJAX handler to get all leads (demo users).
     *
     * @return void Sends JSON response and exits.
     */
    public static function get_leads_ajax()
    {
        self::authorize_admin_ajax(false, array('jpsm_nonce', 'jpsm_access_nonce'));

        $leads = self::get_leads();
        API_Contract::send_success($leads, 'leads_fetched', 'Prospectos cargados.', 200);
    }

    /**
     * AJAX handler to update folder tier requirement (matrix or legacy single tier).
     *
     * @return void Sends JSON response and exits.
     */
    public static function update_folder_tier_ajax()
    {
        self::authorize_admin_ajax(false, array('jpsm_nonce', 'jpsm_access_nonce'));

        $folder = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : '';
        $tiers = isset($_POST['tiers']) ? $_POST['tiers'] : null;
        $tier_single = isset($_POST['tier']) ? intval($_POST['tier']) : null;

        $validation = API_Contract::validate_required_fields(array('folder' => $folder));
        if (is_wp_error($validation)) {
            API_Contract::send_wp_error($validation, 'Carpeta requerida', 'missing_required_fields', 422);
        }

        // Matrix UI: explicit allowed tiers list.
        if (is_array($tiers)) {
            $tiers = array_values(array_unique(array_map('intval', (array) $tiers)));
            $tiers = array_filter($tiers, function ($tier) {
                return $tier >= self::TIER_DEMO && $tier <= self::TIER_FULL;
            });
            sort($tiers);

            if (empty($tiers)) {
                API_Contract::send_error('Tiers inválidos.', 'invalid_tier', 422);
            }

            self::set_folder_allowed_tiers($folder, $tiers);
            API_Contract::send_success(['message' => 'Permisos actualizados', 'tiers' => $tiers], 'folder_permissions_updated', 'Permisos actualizados.', 200);
        }

        // Legacy UI: single `tier` means "minimum tier required" (tier or higher).
        if (!is_null($tier_single) && $tier_single >= self::TIER_DEMO && $tier_single <= self::TIER_FULL) {
            self::set_folder_tier($folder, $tier_single);
            API_Contract::send_success([
                'message' => 'Permisos actualizados',
                'min_tier' => $tier_single,
                'tiers' => self::expand_min_tier_to_allowed_tiers($tier_single),
            ], 'folder_permissions_updated', 'Permisos actualizados.', 200);
        }

        API_Contract::send_error('Tiers inválidos.', 'invalid_tier', 422);
    }

    /**
     * AJAX handler to get all folder permissions merged with actual folders from the index.
     *
     * @return void Sends JSON response and exits.
     */
    public static function get_folders_ajax()
    {
        self::authorize_admin_ajax(false, array('jpsm_nonce', 'jpsm_access_nonce'));

        global $wpdb;
        $perms = self::get_all_folder_permissions();

        // 1. Get actual folders from Index
        $db_folders_raw = [];
        if (class_exists(Index_Manager::class)) {
            $table = esc_sql(Index_Manager::get_table_name());
            // Get unique folders
            $db_folders_raw = $wpdb->get_col("SELECT DISTINCT folder FROM `$table`");
        }

        // 2. Determine "Effective Root" (Sidebar Logic)
        $root_folders = [];
        $candidates = array_unique(array_merge($db_folders_raw, array_keys($perms)));

        foreach ($candidates as $folder) {
            $folder = trim($folder, '/');
            if (empty($folder))
                continue;

            $parts = explode('/', $folder);
            $root = $parts[0] . '/';
            if (!in_array($root, $root_folders)) {
                $root_folders[] = $root;
            }
        }

        // Default: Show Top Level (Depth 1)
        $target_depth = 1;
        $required_prefix = '';

        // If EXACTLY ONE root folder exists (e.g. "Full Pack [JetPack Store]/")
        // We act as if that is the root, and show its children (Depth 2)
        if (count($root_folders) === 1) {
            $target_depth = 2;
            $required_prefix = $root_folders[0];
        }

        // 3. Filter DB & Perms folders based on Target Depth
        $final_list = [];
        foreach ($candidates as $folder) {
            $clean_folder = trim($folder, '/');
            if (empty($clean_folder))
                continue;

            $parts = explode('/', $clean_folder);

            // Skip if this path is shallower than what we are looking for
            if (count($parts) < $target_depth) {
                continue;
            }

            // Synthesize the parent folder at the target depth
            // e.g. "Music/Rock/Song.mp3" (Depth 3) -> "Music" (Depth 1)
            $relevant_parts = array_slice($parts, 0, $target_depth);
            $relevant_folder = implode('/', $relevant_parts);

            // Check Prefix (if needed for Depth 2, ensure it belongs to the single root)
            if ($target_depth > 1) {
                if (strpos($relevant_folder, $required_prefix) !== 0) {
                    continue;
                }
            }

            // Add to list (Use array key to ensure uniqueness)
            if (!isset($final_list[$relevant_folder])) {
                $final_list[$relevant_folder] = isset($perms[$relevant_folder]) ? $perms[$relevant_folder] : self::get_folder_allowed_tiers($relevant_folder);
            }
        }

        ksort($final_list);
        API_Contract::send_success($final_list, 'folders_fetched', 'Carpetas cargadas.', 200);
    }
}

// Backward compatibility alias.
class_alias(Access_Manager::class, 'JPSM_Access_Manager');
