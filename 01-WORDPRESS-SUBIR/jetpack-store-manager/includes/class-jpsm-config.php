<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central configuration access + validation.
 *
 * Option A guardrail: keep existing option keys (jpsm_*) for compatibility.
 */
class JPSM_Config
{
    public static function get_b2_key_id()
    {
        return defined('JPSM_B2_KEY_ID') ? trim((string) JPSM_B2_KEY_ID) : '';
    }

    public static function get_b2_app_key()
    {
        return defined('JPSM_B2_APP_KEY') ? trim((string) JPSM_B2_APP_KEY) : '';
    }

    public static function get_b2_bucket()
    {
        return defined('JPSM_B2_BUCKET') ? trim((string) JPSM_B2_BUCKET) : '';
    }

    public static function get_b2_region()
    {
        return defined('JPSM_B2_REGION') ? trim((string) JPSM_B2_REGION) : '';
    }

    public static function get_b2_config()
    {
        return array(
            'key_id' => self::get_b2_key_id(),
            'app_key' => self::get_b2_app_key(),
            'bucket' => self::get_b2_bucket(),
            'region' => self::get_b2_region(),
        );
    }

    public static function is_b2_configured()
    {
        $cfg = self::get_b2_config();
        return !empty($cfg['key_id']) && !empty($cfg['app_key']) && !empty($cfg['bucket']) && !empty($cfg['region']);
    }

    public static function get_b2_missing_fields()
    {
        $cfg = self::get_b2_config();
        $missing = array();
        foreach (array('key_id', 'app_key', 'bucket', 'region') as $k) {
            if (empty($cfg[$k])) {
                $missing[] = $k;
            }
        }
        return $missing;
    }

    public static function validate_b2_config($cfg)
    {
        if (!is_array($cfg)) {
            return new WP_Error('invalid_config', 'Configuración inválida.');
        }

        $key_id = isset($cfg['key_id']) ? trim((string) $cfg['key_id']) : '';
        $app_key = isset($cfg['app_key']) ? trim((string) $cfg['app_key']) : '';
        $bucket = isset($cfg['bucket']) ? trim((string) $cfg['bucket']) : '';
        $region = isset($cfg['region']) ? trim((string) $cfg['region']) : '';

        if ($key_id === '' || $app_key === '' || $bucket === '' || $region === '') {
            return new WP_Error('missing_b2_config', 'Faltan campos requeridos de Backblaze B2 (S3).');
        }

        // Region: keep strict to prevent malformed endpoints (SSRF-ish surface).
        // Examples: us-west-004, us-east-005, eu-central-003
        if (!preg_match('/^[a-z]{2}(?:-[a-z0-9]+)+-\\d{3}$/', $region)) {
            return new WP_Error('invalid_region', 'La región de B2 no tiene un formato válido (ej: us-west-004).');
        }

        // Bucket: keep moderately strict (path-style endpoint). Backblaze bucket names can be mixed-case,
        // so allow A-Z as well (we still restrict to safe URL path characters).
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{1,61}[A-Za-z0-9]$/', $bucket) || strpos($bucket, '..') !== false) {
            return new WP_Error('invalid_bucket', 'El nombre del bucket no tiene un formato válido.');
        }

        return true;
    }

    /**
     * Normalize Cloudflare download origin.
     *
     * Accepts only HTTPS origins (scheme + host + optional port), no path/query/fragment.
     * Returns canonical origin without trailing slash, or empty string when invalid.
     */
    public static function normalize_cloudflare_domain($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        // If scheme is missing, assume HTTPS.
        if (!preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $value)) {
            $value = 'https://' . $value;
        }

        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? intval($parts['port']) : null;

        if ($scheme !== 'https') {
            return '';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        // Strict: only origin is allowed, no path/query/fragment.
        if (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }

        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return '';
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            return '';
        }

        $origin = 'https://' . $host;
        if ($port !== null && $port !== 443) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    /**
     * Get normalized Cloudflare download origin.
     */
    public static function get_cloudflare_domain()
    {
        $raw = defined('JPSM_CLOUDFLARE_DOMAIN')
            ? (string) JPSM_CLOUDFLARE_DOMAIN
            : (string) get_option('jpsm_cloudflare_domain', '');

        return self::normalize_cloudflare_domain($raw);
    }

    /**
     * Rewrite a B2 presigned URL to use the configured Cloudflare origin.
     * Keeps the original path and query string intact.
     */
    public static function rewrite_download_url_for_cloudflare($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $cloudflare_origin = self::get_cloudflare_domain();
        if ($cloudflare_origin === '') {
            return $url;
        }

        $src = parse_url($url);
        $dst = parse_url($cloudflare_origin);

        if (!is_array($src) || !is_array($dst) || empty($src['path']) || empty($dst['scheme']) || empty($dst['host'])) {
            return $url;
        }

        $rebuilt = $dst['scheme'] . '://' . $dst['host'];
        if (isset($dst['port'])) {
            $rebuilt .= ':' . intval($dst['port']);
        }
        $rebuilt .= (string) $src['path'];

        if (isset($src['query']) && $src['query'] !== '') {
            $rebuilt .= '?' . $src['query'];
        }

        if (isset($src['fragment']) && $src['fragment'] !== '') {
            $rebuilt .= '#' . $src['fragment'];
        }

        return $rebuilt;
    }

    /**
     * Admin-only AJAX handler to validate B2 connectivity with a lightweight request.
     */
    public static function test_b2_connection_ajax()
    {
        if (!class_exists('JPSM_Auth')) {
            JPSM_API_Contract::send_error('Auth service unavailable', 'auth_unavailable', 500);
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
        }

        if (!class_exists('JPSM_S3_Client')) {
            JPSM_API_Contract::send_error('MediaVault no disponible.', 'mediavault_unavailable', 500);
        }

        $cfg = self::get_b2_config();
        $valid = self::validate_b2_config($cfg);
        if (is_wp_error($valid)) {
            JPSM_API_Contract::send_wp_error($valid, $valid->get_error_message(), $valid->get_error_code(), 422);
        }

        $s3 = new JPSM_S3_Client($cfg['key_id'], $cfg['app_key'], $cfg['region'], $cfg['bucket']);
        $res = $s3->list_objects_page('', null, 1);
        if (is_wp_error($res)) {
            JPSM_API_Contract::send_error($res->get_error_message(), 'b2_connection_failed', 502);
        }

        JPSM_API_Contract::send_success(array(
            'message' => 'Conexión OK',
        ), 'b2_connection_ok', 'Conexión OK.', 200);
    }
}
