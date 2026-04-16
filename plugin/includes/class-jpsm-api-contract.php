<?php
namespace JetpackStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared response and input-validation helpers for API consistency.
 *
 * Backward compatible behavior:
 * - Default (legacy): keeps current `wp_send_json_success/error` payload shape.
 * - v2 opt-in (`api_version=2`): returns a consistent envelope:
 *   success: { ok, code, message, data }
 *   error:   { ok, code, message, details }
 */
class API_Contract
{
    const API_VERSION_V2 = '2';

    /**
     * Decide if the caller requested v2 response envelope.
     */
    public static function wants_v2()
    {
        if (isset($_REQUEST['api_version'])) {
            $version = sanitize_text_field(wp_unslash($_REQUEST['api_version']));
            return (string) $version === self::API_VERSION_V2;
        }

        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $accept = sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT']));
            if (strpos($accept, 'application/vnd.jpsm.v2+json') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return consistent success payload data.
     */
    public static function build_success_payload($data = array(), $code = 'ok', $message = 'OK')
    {
        return array(
            'ok' => true,
            'code' => sanitize_key((string) $code),
            'message' => sanitize_text_field((string) $message),
            'data' => $data,
        );
    }

    /**
     * Return consistent error payload data.
     */
    public static function build_error_payload($message = 'Error', $code = 'error', $details = array())
    {
        return array(
            'ok' => false,
            'code' => sanitize_key((string) $code),
            'message' => self::extract_error_message($message),
            'details' => is_array($details) ? $details : array(),
        );
    }

    /**
     * Send success response with backward-compatible fallback.
     */
    public static function send_success($legacy_data = array(), $code = 'ok', $message = 'OK', $status = 200)
    {
        if (self::wants_v2()) {
            wp_send_json_success(self::build_success_payload($legacy_data, $code, $message), (int) $status);
        }

        wp_send_json_success($legacy_data, (int) $status);
    }

    /**
     * Send error response with backward-compatible fallback.
     */
    public static function send_error($legacy_message = 'Error', $code = 'error', $status = 400, $details = array())
    {
        if (self::wants_v2()) {
            $payload = self::build_error_payload($legacy_message, $code, $details);
            wp_send_json_error($payload, (int) $status);
        }

        wp_send_json_error(self::extract_error_message($legacy_message), (int) $status);
    }

    /**
     * Validate required fields from already-sanitized values.
     * Returns true when valid, otherwise WP_Error with details.
     */
    public static function validate_required_fields($fields, $error_code = 'missing_required_fields')
    {
        $missing = array();

        foreach ((array) $fields as $name => $value) {
            $is_missing = false;

            if (is_array($value)) {
                $is_missing = empty($value);
            } else {
                $is_missing = ($value === null || $value === '');
            }

            if ($is_missing) {
                $missing[] = (string) $name;
            }
        }

        if (empty($missing)) {
            return true;
        }

        return new WP_Error(
            sanitize_key((string) $error_code),
            'Missing required fields',
            array('missing' => $missing)
        );
    }

    /**
     * Helper to send a WP_Error using consistent response contract.
     */
    public static function send_wp_error($error, $fallback_message = 'Error', $fallback_code = 'error', $status = 400)
    {
        if (!is_wp_error($error)) {
            self::send_error($fallback_message, $fallback_code, $status);
            return;
        }

        $code = sanitize_key((string) $error->get_error_code());
        if ($code === '') {
            $code = sanitize_key((string) $fallback_code);
        }

        $message = $error->get_error_message();
        if ($message === '') {
            $message = (string) $fallback_message;
        }

        $details = $error->get_error_data();
        if (!is_array($details)) {
            $details = array('raw' => $details);
        }

        self::send_error($message, $code, $status, $details);
    }

    /**
     * Normalize message extraction for legacy mixed payloads.
     */
    public static function extract_error_message($value, $fallback = 'Error')
    {
        if (is_wp_error($value)) {
            $msg = $value->get_error_message();
            return $msg !== '' ? $msg : $fallback;
        }

        if (is_array($value)) {
            if (!empty($value['message'])) {
                return sanitize_text_field((string) $value['message']);
            }
            return $fallback;
        }

        if (is_scalar($value) && (string) $value !== '') {
            return sanitize_text_field((string) $value);
        }

        return $fallback;
    }
}

// Backward compatibility alias.
class_alias(API_Contract::class, 'JPSM_API_Contract');

