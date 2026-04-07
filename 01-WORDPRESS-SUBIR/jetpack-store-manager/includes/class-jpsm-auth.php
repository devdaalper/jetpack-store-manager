<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central auth/nonce/session helper for JPSM endpoints.
 */
class JPSM_Auth
{
    const USER_SESSION_COOKIE = 'jdd_access_token';
    const ADMIN_SESSION_COOKIE = 'jpsm_auth_session';

    /**
     * Opt-in hardening mode: only WP admins (manage_options) can use privileged features.
     * Disables secret-key auth and signed admin sessions created from secret keys.
     */
    public static function is_wp_admin_only_mode()
    {
        return (bool) get_option('jpsm_wp_admin_only_mode', false);
    }

    /**
     * Backward compatibility toggle: accept secret key from GET query string.
     * Default is false because query strings leak via logs/referrers.
     */
    public static function allow_get_secret_key()
    {
        return (bool) get_option('jpsm_allow_get_key', false);
    }

    /**
     * Return sanitized nonce from request payload.
     */
    public static function get_request_nonce($field = 'nonce')
    {
        if (isset($_POST[$field])) {
            return sanitize_text_field(wp_unslash($_POST[$field]));
        }
        if (isset($_GET[$field])) {
            return sanitize_text_field(wp_unslash($_GET[$field]));
        }
        return '';
    }

    /**
     * Validate nonce against one or more accepted actions.
     */
    public static function verify_nonce_actions($nonce, $actions = array('jpsm_nonce'))
    {
        if (empty($nonce)) {
            return false;
        }
        foreach ((array) $actions as $action) {
            if (!empty($action) && wp_verify_nonce($nonce, $action)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate nonce from request.
     */
    public static function validate_request_nonce($actions = array('jpsm_nonce'))
    {
        $nonce = self::get_request_nonce('nonce');
        return self::verify_nonce_actions($nonce, $actions);
    }

    /**
     * Validate secret key sent in request (`key`) against stored access key.
     */
    public static function validate_request_secret_key()
    {
        if (self::is_wp_admin_only_mode()) {
            return false;
        }

        $stored_key = self::get_access_key();
        if (empty($stored_key)) {
            return false;
        }

        $provided_key = self::get_request_secret_key();
        if (empty($provided_key)) {
            return false;
        }

        return hash_equals($stored_key, $provided_key);
    }

    /**
     * Check if current request has privileged admin auth.
     */
    public static function is_admin_authenticated($allow_secret_key = true)
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        if (self::is_wp_admin_only_mode()) {
            return false;
        }

        if (self::verify_admin_session()) {
            return true;
        }

        if ($allow_secret_key && self::validate_request_secret_key()) {
            return true;
        }

        return false;
    }

    /**
     * Centralized endpoint auth gate.
     */
    public static function authorize_request($args = array())
    {
        $defaults = array(
            'require_nonce' => true,
            'nonce_actions' => array('jpsm_nonce'),
            'allow_admin' => true,
            'allow_secret_key' => false,
            'allow_user_session' => false,
        );
        $cfg = array_merge($defaults, $args);

        if (!empty($cfg['require_nonce']) && !self::validate_request_nonce($cfg['nonce_actions'])) {
            return new WP_Error('invalid_nonce', 'Invalid nonce');
        }

        if (!empty($cfg['allow_admin']) && self::is_admin_authenticated((bool) $cfg['allow_secret_key'])) {
            return true;
        }

        if (!empty($cfg['allow_user_session']) && self::get_current_user_email()) {
            return true;
        }

        return new WP_Error('unauthorized', 'Unauthorized');
    }

    /**
     * Get current signed user session email.
     *
     * For migration safety, a legacy raw email cookie is upgraded in-place
     * to the signed format on first valid read.
     */
    public static function get_current_user_email()
    {
        $cookie_value = self::get_cookie_value(self::USER_SESSION_COOKIE);
        if (empty($cookie_value)) {
            return null;
        }

        $payload = self::decode_signed_token($cookie_value);
        if (is_array($payload) && isset($payload['type']) && $payload['type'] === 'user' && !empty($payload['email'])) {
            $email = sanitize_email($payload['email']);
            return is_email($email) ? $email : null;
        }

        // Backward compatibility: legacy raw email cookie
        $legacy_email = sanitize_email($cookie_value);
        if (is_email($legacy_email)) {
            self::set_user_session_cookie($legacy_email);
            return $legacy_email;
        }

        return null;
    }

    /**
     * Set signed user session cookie.
     */
    public static function set_user_session_cookie($email)
    {
        $email = sanitize_email($email);
        if (!is_email($email)) {
            return false;
        }

        $ttl = 30 * DAY_IN_SECONDS;
        $payload = array(
            'type' => 'user',
            'email' => $email,
            'exp' => time() + $ttl,
        );
        $token = self::encode_signed_token($payload);

        return self::set_cookie(self::USER_SESSION_COOKIE, $token, time() + $ttl);
    }

    /**
     * Clear signed user session cookie.
     */
    public static function clear_user_session_cookie()
    {
        self::set_cookie(self::USER_SESSION_COOKIE, '', time() - HOUR_IN_SECONDS);
        // Compatibility cleanup for potential path variation.
        setcookie(self::USER_SESSION_COOKIE, '', time() - HOUR_IN_SECONDS, '/');
    }

    /**
     * Set signed admin session cookie from secret key.
     */
    public static function set_admin_session_from_key($provided_key)
    {
        if (self::is_wp_admin_only_mode()) {
            return false;
        }

        if (empty($provided_key)) {
            return false;
        }

        $stored_key = self::get_access_key();
        if (empty($stored_key) || !hash_equals($stored_key, sanitize_text_field($provided_key))) {
            return false;
        }

        $ttl = 14 * DAY_IN_SECONDS;
        $payload = array(
            'type' => 'admin',
            'key_hash' => self::current_key_hash(),
            'exp' => time() + $ttl,
        );
        $token = self::encode_signed_token($payload);

        return self::set_cookie(self::ADMIN_SESSION_COOKIE, $token, time() + $ttl);
    }

    /**
     * Verify signed admin session cookie.
     */
    public static function verify_admin_session()
    {
        if (self::is_wp_admin_only_mode()) {
            return false;
        }

        $cookie_value = self::get_cookie_value(self::ADMIN_SESSION_COOKIE);
        if (empty($cookie_value)) {
            return false;
        }

        $payload = self::decode_signed_token($cookie_value);
        if (!is_array($payload) || !isset($payload['type']) || $payload['type'] !== 'admin') {
            return false;
        }

        if (empty($payload['key_hash']) || !hash_equals(self::current_key_hash(), $payload['key_hash'])) {
            return false;
        }

        return true;
    }

    /**
     * Clear signed admin session cookie.
     */
    public static function clear_admin_session_cookie()
    {
        self::set_cookie(self::ADMIN_SESSION_COOKIE, '', time() - HOUR_IN_SECONDS);
    }

    /**
     * Get configured access key.
     */
    public static function get_access_key()
    {
        return (string) get_option('jpsm_access_key', '');
    }

    /**
     * Get secret key from request body/query.
     */
    public static function get_request_secret_key()
    {
        if (isset($_POST['key'])) {
            return sanitize_text_field(wp_unslash($_POST['key']));
        }

        // Prefer a header for GET requests (keeps secrets out of URLs).
        if (!empty($_SERVER['HTTP_X_JPSM_KEY'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_X_JPSM_KEY']));
        }
        if (!empty($_SERVER['HTTP_X_JPSM_ACCESS_KEY'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_X_JPSM_ACCESS_KEY']));
        }

        // Legacy fallback (opt-in).
        if (self::allow_get_secret_key() && isset($_GET['key'])) {
            return sanitize_text_field(wp_unslash($_GET['key']));
        }

        return '';
    }

    private static function current_key_hash()
    {
        $secret = self::get_access_key();
        if (empty($secret)) {
            return '';
        }
        return hash_hmac('sha256', $secret, wp_salt('auth'));
    }

    private static function signing_secret()
    {
        return hash_hmac('sha256', wp_salt('secure_auth'), wp_salt('auth'));
    }

    private static function encode_signed_token($payload)
    {
        $payload_json = wp_json_encode($payload);
        $payload_b64 = self::base64url_encode($payload_json);
        $signature_raw = hash_hmac('sha256', $payload_b64, self::signing_secret(), true);
        $signature_b64 = self::base64url_encode($signature_raw);
        return $payload_b64 . '.' . $signature_b64;
    }

    private static function decode_signed_token($token)
    {
        if (!is_string($token) || strpos($token, '.') === false) {
            return null;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        list($payload_b64, $sig_b64) = $parts;
        if ($payload_b64 === '' || $sig_b64 === '') {
            return null;
        }

        $expected_sig = self::base64url_encode(hash_hmac('sha256', $payload_b64, self::signing_secret(), true));
        if (!hash_equals($expected_sig, $sig_b64)) {
            return null;
        }

        $payload_json = self::base64url_decode($payload_b64);
        if ($payload_json === false || $payload_json === '') {
            return null;
        }

        $payload = json_decode($payload_json, true);
        if (!is_array($payload)) {
            return null;
        }

        if (empty($payload['exp']) || (int) $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private static function get_cookie_value($name)
    {
        if (!isset($_COOKIE[$name])) {
            return '';
        }
        return sanitize_text_field(wp_unslash($_COOKIE[$name]));
    }

    private static function set_cookie($name, $value, $expires)
    {
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $secure = is_ssl();
        $httponly = true;

        if (PHP_VERSION_ID >= 70300) {
            return setcookie($name, $value, array(
                'expires' => (int) $expires,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => 'Lax',
            ));
        }

        return setcookie($name, $value, (int) $expires, $path, $domain, $secure, $httponly);
    }

    private static function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64url_decode($data)
    {
        $padding = 4 - (strlen($data) % 4);
        if ($padding < 4) {
            $data .= str_repeat('=', $padding);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
