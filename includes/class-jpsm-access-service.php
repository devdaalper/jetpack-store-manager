<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JPSM_Access_Service
 *
 * Wrapper pequeño para mantener UI/controladores libres de detalles de auth/sesion.
 */
class JPSM_Access_Service
{
    /**
     * Verifica si el request actual puede acceder al dashboard (admin UI / shortcode).
     */
    public static function can_access_dashboard()
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        if (class_exists('JPSM_Access_Manager')) {
            return JPSM_Access_Manager::verify_session();
        }

        return false;
    }

    /**
     * Telemetria de UI (sin secretos).
     *
     * Valores: wp_admin | jpsm_admin | none
     */
    public static function get_dashboard_auth_method()
    {
        if (current_user_can('manage_options')) {
            return 'wp_admin';
        }

        if (class_exists('JPSM_Access_Manager')) {
            $email = JPSM_Access_Manager::get_current_email();
            if ($email && JPSM_Access_Manager::is_admin($email)) {
                return 'jpsm_admin';
            }
        }

        return 'none';
    }

    /**
     * Etiqueta de operador (best-effort) para el header del dashboard.
     */
    public static function get_operator_label()
    {
        $user = wp_get_current_user();
        if ($user && $user->exists() && !empty($user->display_name)) {
            return $user->display_name;
        }

        if (class_exists('JPSM_Access_Manager')) {
            $email = JPSM_Access_Manager::get_current_email();
            if (!empty($email)) {
                return $email;
            }
        }

        return 'Admin';
    }
}

