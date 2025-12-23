<?php

class JPSM_Sales
{

    public function __construct()
    {
        // No auth init needed
    }

    /**
     * Send email using wp_mail with proper headers
     */
    public function send_email($to, $subject, $body)
    {
        $admin_email = get_option('admin_email');
        $site_url = get_site_url();
        $site_domain = parse_url($site_url, PHP_URL_HOST);

        // Remove www. prefix if present
        if (substr($site_domain, 0, 4) == 'www.') {
            $site_domain = substr($site_domain, 4);
        }

        // Construct a safe local sender
        $from_email = "wordpress@" . $site_domain;

        // Only use admin_email if it matches the domain (e.g. info@mysite.com)
        // Otherwise, avoid using gmail/yahoo/etc as sender to prevent DMARC blocks.
        if (is_email($admin_email) && strpos($admin_email, $site_domain) !== false) {
            $from_email = $admin_email;
        }

        // Use valid local email for 'From' to avoid Spoofing/DMARC blocks
        // Add 'Reply-To' so users reply to the official gmail
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: JetPack Store <' . $from_email . '>',
            'Reply-To: JetPack Store <jetpackstore.oficial@gmail.com>'
        );

        // Hook into PHPMailer to fix SPF and add Plain Text version (Anti-Spam)
        $phpmailer_hook = function ($phpmailer) use ($from_email, $body) {
            $phpmailer->Sender = $from_email; // Envelope Sender (Fixes SPF)
            $phpmailer->AltBody = wp_strip_all_tags($body); // Plain Text (Fixes Spam Filter)
        };
        add_action('phpmailer_init', $phpmailer_hook);

        $sent = wp_mail($to, $subject, $body, $headers);

        // Remove hook to not affect other emails
        remove_action('phpmailer_init', $phpmailer_hook);

        if ($sent) {
            return true;
        } else {
            // Log error for debugging
            error_log("JPSM wp_mail failed. To: $to, From: $from_email");
            return 'Error: wp_mail falló. Puede ser un bloqueo de SPAM/Spoofing. Revisa el log de errores.';
        }
    }

    /**
     * Process the sale: Send Client Email + Admin Notification
     */
    public function process_sale($email, $package, $region, $template_body)
    {
        // 1. Prepare Client Email
        $body = str_replace('{email}', $email, $template_body);
        $body = str_replace('{paquete}', ucfirst($package), $body);

        $subject = "Entrega de Material - Paquete " . ucfirst($package);

        // Send Client Email
        $email_result = $this->send_email($email, $subject, $body);
        $email_sent = ($email_result === true);

        // 2. Send Admin Confirmation (Parallel-ish)
        if ($email_sent) {
            $admin_subject = "[Confirmación] Venta Exitosa - " . ucfirst($package);
            $admin_body = "<p>Se ha enviado exitosamente el material.</p>";
            $admin_body .= "<ul>";
            $admin_body .= "<li><strong>Cliente:</strong> $email</li>";
            $admin_body .= "<li><strong>Paquete:</strong> " . ucfirst($package) . "</li>";
            $admin_body .= "<li><strong>Región:</strong> " . ucfirst($region) . "</li>";
            $admin_body .= "<li><strong>Fecha:</strong> " . current_time('d/m/Y H:i:s') . "</li>";
            $admin_body .= "</ul>";

            $this->send_email('jetpackstore.oficial@gmail.com', $admin_subject, $admin_body);
        }

        // 3. Log to Local Database already happens in the Controller (AJAX handler)
        // No external API call needed.

        return [
            'email_sent' => $email_sent,
            'email_message' => $email_sent ? 'OK' : $email_result,
        ];
    }
}
