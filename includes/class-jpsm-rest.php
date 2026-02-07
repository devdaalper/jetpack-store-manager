<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST entry points introduced in Phase 4.
 */
class JPSM_REST
{
    public static function register_routes()
    {
        register_rest_route(
            'jpsm/v1',
            '/status',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'status'),
                'permission_callback' => array(__CLASS__, 'can_read_status'),
            )
        );
    }

    public static function can_read_status()
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        if (class_exists('JPSM_Auth')) {
            return JPSM_Auth::is_admin_authenticated(true);
        }

        return false;
    }

    public static function status()
    {
        $payload = array(
            'phase' => 4,
            'api_version' => 1,
            'api_v2_opt_in' => true,
            'plugin_version' => defined('JPSM_VERSION') ? JPSM_VERSION : '',
            'timestamp' => current_time('mysql'),
        );

        return new WP_REST_Response(
            JPSM_API_Contract::build_success_payload(
                $payload,
                'status_ok',
                'JPSM API status'
            ),
            200
        );
    }
}
