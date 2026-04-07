<?php

use PHPUnit\Framework\TestCase;

if (!function_exists('get_site_url')) {
    function get_site_url()
    {
        return 'https://jetpackstore.mx';
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '')
    {
        return 'JetPack Store';
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text)
    {
        return strip_tags((string) $text);
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        if (!isset($GLOBALS['__wp_actions'][$hook])) {
            $GLOBALS['__wp_actions'][$hook] = array();
        }
        $GLOBALS['__wp_actions'][$hook][] = $callback;
        return true;
    }
}

if (!function_exists('remove_action')) {
    function remove_action($hook, $callback, $priority = 10)
    {
        if (!isset($GLOBALS['__wp_actions'][$hook]) || !is_array($GLOBALS['__wp_actions'][$hook])) {
            return true;
        }

        foreach ($GLOBALS['__wp_actions'][$hook] as $idx => $registered) {
            if ($registered === $callback) {
                unset($GLOBALS['__wp_actions'][$hook][$idx]);
            }
        }

        $GLOBALS['__wp_actions'][$hook] = array_values($GLOBALS['__wp_actions'][$hook]);
        return true;
    }
}

if (!function_exists('wp_specialchars_decode')) {
    function wp_specialchars_decode($text, $quote_style = ENT_QUOTES)
    {
        return htmlspecialchars_decode((string) $text, (int) $quote_style);
    }
}

if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = array(), $attachments = array())
    {
        if (!isset($GLOBALS['__jpsm_wp_mail_calls'])) {
            $GLOBALS['__jpsm_wp_mail_calls'] = array();
        }

        $GLOBALS['__jpsm_wp_mail_calls'][] = array(
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
        );

        return true;
    }
}

require_once dirname(__DIR__, 3) . '/01-WORDPRESS-SUBIR/jetpack-store-manager/includes/class-jpsm-sales.php';

final class SalesEmailNotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__wp_options'] = array();
        $GLOBALS['__wp_actions'] = array();
        $GLOBALS['__jpsm_wp_mail_calls'] = array();
    }

    public function testProcessSaleFallsBackToLegacyOfficialInboxWhenNotificationOptionIsEmpty(): void
    {
        update_option('admin_email', 'admin@jetpackstore.mx');

        $sales = new JPSM_Sales();
        $result = $sales->process_sale(
            'cliente@example.com',
            'basic',
            'national',
            '<p>Hola {email}, aquí está tu paquete {paquete}.</p>'
        );

        $this->assertTrue($result['email_sent']);
        $this->assertTrue($result['admin_notification_sent']);
        $this->assertSame(array('jetpackstore.oficial@gmail.com'), $result['admin_notification_recipients']);
        $this->assertCount(2, $GLOBALS['__jpsm_wp_mail_calls']);
        $this->assertSame(array('jetpackstore.oficial@gmail.com'), $GLOBALS['__jpsm_wp_mail_calls'][1]['to']);
    }

    public function testConfiguredNotificationEmailsOverrideLegacyFallback(): void
    {
        update_option('jpsm_notify_emails', "ops@example.com\nalerts@example.com");

        $sales = new JPSM_Sales();
        $result = $sales->process_sale(
            'cliente@example.com',
            'vip',
            'international',
            '<p>Hola {email}, aquí está tu paquete {paquete}.</p>'
        );

        $this->assertTrue($result['email_sent']);
        $this->assertTrue($result['admin_notification_sent']);
        $this->assertSame(array('alerts@example.com', 'ops@example.com'), $result['admin_notification_recipients']);
        $this->assertSame(array('alerts@example.com', 'ops@example.com'), $GLOBALS['__jpsm_wp_mail_calls'][1]['to']);
    }
}
