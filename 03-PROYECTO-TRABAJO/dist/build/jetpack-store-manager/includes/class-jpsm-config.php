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
