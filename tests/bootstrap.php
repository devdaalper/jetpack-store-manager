<?php

// PHPUnit bootstrap for this plugin without requiring a full WordPress runtime.
// We stub the minimal WP functions used by the classes under test.

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

$GLOBALS['__wp_options'] = array();

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value)
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $value = (string) $value;
        $value = str_replace("\0", '', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim((string) $value);
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($value)
    {
        $value = (string) $value;
        $value = trim($value);
        return strtolower($value);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        $key = (string) $key;
        $key = strtolower($key);
        $key = preg_replace('/[^a-z0-9_\\-]/', '', $key);
        return $key;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('remove_accents')) {
    function remove_accents($string)
    {
        $string = (string) $string;
        $map = array(
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ñ' => 'N', 'ñ' => 'n',
        );
        return strtr($string, $map);
    }
}

if (!function_exists('is_email')) {
    function is_email($value)
    {
        $value = (string) $value;
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        $name = (string) $name;
        if (array_key_exists($name, $GLOBALS['__wp_options'])) {
            return $GLOBALS['__wp_options'][$name];
        }
        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value)
    {
        $GLOBALS['__wp_options'][(string) $name] = $value;
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '')
    {
        return true;
    }
}

