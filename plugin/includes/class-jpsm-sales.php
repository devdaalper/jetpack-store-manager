<?php
namespace JetpackStore;

/**
 * Sales engine for the JetPack Store Manager.
 *
 * Handles sale processing, email delivery, sales log CRUD,
 * persistent statistics, and related AJAX endpoints.
 *
 * @package JetpackStore
 */
class Sales
{
    private const LEGACY_NOTIFY_EMAIL = 'jetpackstore.oficial@gmail.com';

    /**
     * Constructor.
     */
    public function __construct()
    {
        // No auth init needed
    }

    /**
     * Normalize a list of emails from option string/array input.
     *
     * @return array<int, string>
     */
    private static function normalize_email_list($value)
    {
        if (is_array($value)) {
            $tokens = $value;
        } else {
            $tokens = preg_split('/[\\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        }

        $out = array();
        foreach ((array) $tokens as $token) {
            $email = sanitize_email((string) $token);
            if ($email && is_email($email)) {
                $out[] = strtolower($email);
            }
        }

        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /**
     * Resolve notification recipients.
     *
     * Keep the configurable option first, but preserve the historical official inbox
     * as a fallback so sales confirmations do not silently stop after config changes.
     *
     * @return array<int, string>
     */
    private static function get_notification_emails()
    {
        $configured = self::normalize_email_list(get_option('jpsm_notify_emails', array()));
        if (!empty($configured)) {
            return $configured;
        }

        $legacy = self::normalize_email_list(array(self::LEGACY_NOTIFY_EMAIL));
        if (!empty($legacy)) {
            return $legacy;
        }

        $admin_email = sanitize_email((string) get_option('admin_email', ''));
        if ($admin_email && is_email($admin_email)) {
            return array(strtolower($admin_email));
        }

        return array();
    }

    /**
     * Send email using wp_mail with proper headers.
     *
     * @param string|array $to      Recipient email address(es).
     * @param string       $subject Email subject line.
     * @param string       $body    HTML email body.
     * @return true|string True on success, error message string on failure.
     */
    public function send_email($to, $subject, $body)
    {
        $admin_email = get_option('admin_email');
        $reply_to_email = sanitize_email((string) get_option('jpsm_reply_to_email', ''));
        if (!($reply_to_email && is_email($reply_to_email))) {
            $reply_to_email = (is_email($admin_email)) ? $admin_email : '';
        }

        $site_url = get_site_url();
        $site_domain = parse_url($site_url, PHP_URL_HOST);

        // Remove www. prefix if present
        if (substr($site_domain, 0, 4) == 'www.') {
            $site_domain = substr($site_domain, 4);
        }

        // Construct a safe local sender
        $from_email = "wordpress@" . $site_domain;

        // Only use admin_email if it matches the domain (e.g. info@example.com)
        // Otherwise, avoid using gmail/yahoo/etc as sender to prevent DMARC blocks.
        if (is_email($admin_email) && strpos($admin_email, $site_domain) !== false) {
            $from_email = $admin_email;
        }

        $from_name = get_bloginfo('name');
        $from_name = $from_name ? sanitize_text_field(wp_specialchars_decode((string) $from_name, ENT_QUOTES)) : 'WordPress';

        // Use valid local email for 'From' to avoid Spoofing/DMARC blocks.
        // Reply-To is configurable via WordPress admin email by default.
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        );
        if ($reply_to_email !== '') {
            $headers[] = 'Reply-To: ' . $from_name . ' <' . $reply_to_email . '>';
        }

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
            // Log error for debugging (avoid leaking PII like recipient emails).
            $count = is_array($to) ? count($to) : 1;
            error_log("JPSM wp_mail failed. Recipients: {$count}");
            return 'Error: wp_mail falló. Puede ser un bloqueo de SPAM/Spoofing. Revisa el log de errores.';
        }
    }

    /**
     * Process the sale: Send Client Email + Admin Notification.
     *
     * @param string $email         Client email address.
     * @param string $package       Package name/label.
     * @param string $region        Region (national or international).
     * @param string $template_body HTML template body with {email} and {paquete} placeholders.
     * @return array Result with email_sent, email_message, admin_notification_sent, etc.
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
        $admin_notification_result = true;
        $notify_emails = array();
        if ($email_sent) {
            $admin_subject = "[Confirmación] Venta Exitosa - " . ucfirst($package);
            $admin_body = "<p>Se ha enviado exitosamente el material.</p>";
            $admin_body .= "<ul>";
            $admin_body .= "<li><strong>Cliente:</strong> $email</li>";
            $admin_body .= "<li><strong>Paquete:</strong> " . ucfirst($package) . "</li>";
            $admin_body .= "<li><strong>Región:</strong> " . ucfirst($region) . "</li>";
            $admin_body .= "<li><strong>Fecha:</strong> " . current_time('d/m/Y H:i:s') . "</li>";
            $admin_body .= "</ul>";

            $notify_emails = self::get_notification_emails();
            if (!empty($notify_emails)) {
                $admin_notification_result = $this->send_email($notify_emails, $admin_subject, $admin_body);
                if ($admin_notification_result !== true) {
                    error_log('JPSM admin notification failed. Recipients: ' . count($notify_emails));
                }
            }
        }

        // 3. Log to Local Database already happens in the Controller (AJAX handler)
        // No external API call needed.

        $admin_notification_sent = $email_sent && !empty($notify_emails) && ($admin_notification_result === true);
        $admin_notification_message = $admin_notification_sent
            ? 'OK'
            : (($admin_notification_result === true) ? 'Not attempted.' : (string) $admin_notification_result);

        return [
            'email_sent' => $email_sent,
            'email_message' => $email_sent ? 'OK' : $email_result,
            'admin_notification_sent' => $admin_notification_sent,
            'admin_notification_message' => $admin_notification_message,
            'admin_notification_recipients' => $notify_emails,
        ];
    }

    // =========================================================================
    // SECURITY & HELPERS
    // =========================================================================

    /**
     * Verify Access (Duplicate of Admin logic for autonomy)
     */
    private static function verify_access()
    {
        if (class_exists(Auth::class)) {
            return Auth::is_admin_authenticated(true);
        }

        return current_user_can('manage_options');
    }

    /**
     * Centralized auth+nonce gate for sales endpoints.
     */
    private static function authorize_sales_ajax($args = array())
    {
        if (!class_exists(Auth::class)) {
            API_Contract::send_error('Auth service unavailable', 'auth_unavailable', 500);
        }

        $defaults = array(
            'require_nonce' => true,
            'nonce_actions' => array('jpsm_nonce'),
            'allow_admin' => true,
            'allow_secret_key' => true,
            'allow_user_session' => false,
        );

        $auth = Auth::authorize_request(array_merge($defaults, $args));
        if (is_wp_error($auth)) {
            $message = $auth->get_error_code() === 'invalid_nonce' ? 'Invalid nonce' : 'Unauthorized';
            $code = $auth->get_error_code() === 'invalid_nonce' ? 'invalid_nonce' : 'unauthorized';
            $status = $auth->get_error_code() === 'invalid_nonce' ? 401 : 403;
            API_Contract::send_error($message, $code, $status);
        }
    }

    /**
     * Resolve price based on package name and region (retroactive fallback).
     *
     * @param array $entry Sale entry with package, region, and optional currency keys.
     * @return float Price amount from stored options.
     */
    public static function get_entry_price($entry)
    {
        if (class_exists(Domain_Model::class)) {
            return Domain_Model::get_entry_price($entry);
        }

        $pkg = isset($entry['package']) ? mb_strtolower($entry['package']) : '';
        $region = isset($entry['region']) ? strtolower($entry['region']) : 'national';
        $cur = (isset($entry['currency'])) ? strtolower($entry['currency']) : (($region === 'international') ? 'usd' : 'mxn');

        $slug = 'basic';
        if (strpos($pkg, 'full') !== false) {
            $slug = 'full';
        } elseif (strpos($pkg, 'videos') !== false) {
            $slug = 'vip_videos';
        } elseif (strpos($pkg, 'películas') !== false || strpos($pkg, 'pelis') !== false) {
            $slug = 'vip_pelis';
        } elseif (strpos($pkg, 'básico') !== false && strpos($pkg, 'vip') !== false) {
            $slug = 'vip_basic';
        } elseif (strpos($pkg, 'vip') !== false) {
            $slug = 'vip_videos'; // Default for old generic VIP logs
        }

        $option_name = "jpsm_price_{$cur}_{$slug}";
        return floatval(get_option($option_name, 0));
    }

    // =========================================================================
    // AJAX HANDLERS (CONTROLLER Logic)
    // =========================================================================

    /**
     * AJAX handler to process a new sale, send emails, and log the entry.
     *
     * @return void Sends JSON response and exits.
     */
    public static function process_sale_ajax()
    {
        self::authorize_sales_ajax(array(
            'nonce_actions' => array('jpsm_nonce', 'jpsm_process_sale_nonce', 'jpsm_sales_nonce'),
            'allow_secret_key' => true,
        ));

        // Unified Field Names: Accept both frontend (client_email, package_type) and admin (email, package)
        $email = sanitize_email($_POST['client_email'] ?? $_POST['email'] ?? '');
        $package = sanitize_text_field($_POST['package_type'] ?? $_POST['package'] ?? '');
        $region = sanitize_text_field($_POST['region'] ?? '');

        $validation = API_Contract::validate_required_fields(array(
            'email' => $email,
            'package' => $package,
            'region' => $region,
        ));
        if (is_wp_error($validation)) {
            API_Contract::send_wp_error(
                $validation,
                'Faltan datos requeridos (email, paquete o región).',
                'missing_required_fields',
                422
            );
            return;
        }

        // Resolve package metadata from domain registry.
        $resolved_tier = 0;
        if (class_exists(Domain_Model::class)) {
            $vip_subtype = sanitize_text_field($_POST['vip_subtype'] ?? '');
            $resolved = Domain_Model::resolve_sale_package($package, $vip_subtype);
            if (!$resolved) {
                API_Contract::send_error('Paquete no válido.', 'invalid_package', 422, array(
                    'package' => $package,
                    'vip_subtype' => $vip_subtype,
                ));
                return;
            }
            $package = $resolved['label'];
            $template_option = $resolved['template_option'];
            $resolved_tier = isset($resolved['tier']) ? intval($resolved['tier']) : 0;
        } else {
            // Legacy fallback
            $template_option = 'jpsm_email_template_' . strtolower($package);

            if (strtolower($package) === 'vip' && !empty($_POST['vip_subtype'])) {
                $subtype = sanitize_text_field($_POST['vip_subtype']);
                $valid_subtypes = ['vip_videos', 'vip_pelis', 'vip_basic'];

                if (in_array($subtype, $valid_subtypes, true)) {
                    $template_option = 'jpsm_email_template_' . $subtype;

                    switch ($subtype) {
                        case 'vip_videos':
                            $package = 'VIP + Videos';
                            break;
                        case 'vip_pelis':
                            $package = 'VIP + Películas';
                            break;
                        case 'vip_basic':
                            $package = 'VIP + Básico';
                            break;
                    }
                }
            }
        }

        $template_body = get_option($template_option, '');

        if (empty($template_body)) {
            $template_body = "<p>Hola {email},</p><p>Aquí tienes tu paquete {paquete}.</p>";
        }

        // Process Sale via Sales Engine (Instance)
        $sales_engine = new self();
        $result = $sales_engine->process_sale($email, $package, $region, $template_body);

        // Determine Price and Currency with improved matching
        $currency = ($region === 'national') ? 'MXN' : 'USD';
        $amount = self::get_entry_price(array(
            'package' => $package, // This handles "VIP + Videos" etc.
            'region' => $region,
            'currency' => $currency
        ));

        // Save to local log (regardless of email success for tracking)
        $log_entry = array(
            'id' => uniqid('sale_'),
            'time' => current_time('mysql'),
            'email' => $email,
            'package' => $package,
            'region' => $region,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $result['email_sent'] ? 'Completado' : 'Falló'
        );

        if (class_exists(Data_Layer::class)) {
            Data_Layer::create_sale_entry($log_entry);
        } else {
            // Legacy fallback
            $current_log = get_option('jpsm_sales_log', array());
            if (!is_array($current_log)) {
                $current_log = array();
            }
            array_unshift($current_log, $log_entry);
            $current_log = array_slice($current_log, 0, 1000);
            update_option('jpsm_sales_log', $current_log);
        }

        // Update Absolute Stats
        self::update_persistent_stats($log_entry);

        // Ensure entitlements are persisted immediately.
        // get_user_tier() lazily syncs from sales logs, but on a real sale we want the tier saved right away.
        if (class_exists(Access_Manager::class) && is_email($email)) {
            $tier_after = Access_Manager::get_user_tier($email);
            if ($resolved_tier > 0 && $tier_after < $resolved_tier) {
                Access_Manager::set_user_tier($email, $resolved_tier);
            }
        }

        // Return proper success/error based on email result
        if ($result['email_sent']) {
            API_Contract::send_success(array(
                'message' => 'Venta registrada. Correo enviado exitosamente.',
                'entry' => $log_entry
            ), 'sale_registered', 'Venta registrada y correo enviado.', 200);
        } else {
            API_Contract::send_error(
                'Venta guardada pero el correo falló: ' . $result['email_message'],
                'email_delivery_failed',
                502,
                array('entry' => $log_entry)
            );
        }
    }

    /**
     * AJAX handler to delete a single sales log entry.
     *
     * @return void Sends JSON response and exits.
     */
    public static function delete_log_ajax()
    {
        self::authorize_sales_ajax(array(
            'nonce_actions' => array('jpsm_nonce', 'jpsm_sales_nonce'),
            'allow_secret_key' => true,
        ));

        $id_to_delete = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';

        if ($id_to_delete) {
            if (class_exists(Data_Layer::class)) {
                Data_Layer::delete_sale_by_uid($id_to_delete);
            } else {
                $current_log = get_option('jpsm_sales_log', array());
                $new_log = array();
                foreach ($current_log as $entry) {
                    if (isset($entry['id']) && $entry['id'] === $id_to_delete) {
                        continue;
                    }
                    $new_log[] = $entry;
                }
                update_option('jpsm_sales_log', $new_log);
            }
            API_Contract::send_success('Eliminado', 'sale_deleted', 'Registro eliminado.', 200);
        }

        API_Contract::send_error('ID no válido', 'invalid_sale_id', 422);
    }

    /**
     * AJAX handler to delete all sales log entries.
     *
     * @return void Sends JSON response and exits.
     */
    public static function delete_all_logs_ajax()
    {
        self::authorize_sales_ajax(array(
            'nonce_actions' => array('jpsm_nonce', 'jpsm_sales_nonce'),
            'allow_secret_key' => true,
        ));

        if (class_exists(Data_Layer::class)) {
            Data_Layer::clear_sales();
        } else {
            update_option('jpsm_sales_log', array());
        }
        API_Contract::send_success('Historial borrado', 'history_cleared', 'Historial borrado.', 200);
    }

    /**
     * AJAX handler to delete multiple sales log entries in bulk.
     *
     * @return void Sends JSON response and exits.
     */
    public static function delete_bulk_log_ajax()
    {
        self::authorize_sales_ajax(array(
            'nonce_actions' => array('jpsm_nonce', 'jpsm_sales_nonce'),
            'allow_secret_key' => true,
        ));

        $ids_to_delete = isset($_POST['ids']) ? $_POST['ids'] : [];

        if (!is_array($ids_to_delete) || empty($ids_to_delete)) {
            API_Contract::send_error('No hay IDs para borrar', 'missing_sale_ids', 422);
            return;
        }

        $ids_to_delete = array_map('sanitize_text_field', wp_unslash($ids_to_delete));

        if (class_exists(Data_Layer::class)) {
            $deleted_count = Data_Layer::delete_sales_by_uids($ids_to_delete);
        } else {
            $current_log = get_option('jpsm_sales_log', array());
            $new_log = array();
            $deleted_count = 0;

            foreach ($current_log as $entry) {
                if (isset($entry['id']) && in_array($entry['id'], $ids_to_delete, true)) {
                    $deleted_count++;
                    continue;
                }
                $new_log[] = $entry;
            }

            update_option('jpsm_sales_log', $new_log);
        }

        API_Contract::send_success("Eliminados $deleted_count registros", 'bulk_deleted', 'Eliminación masiva completada.', 200);
    }

    /**
     * AJAX handler to resend the delivery email for an existing sale.
     *
     * @return void Sends JSON response and exits.
     */
    public static function resend_email_ajax()
    {
        self::authorize_sales_ajax(array(
            'nonce_actions' => array('jpsm_nonce', 'jpsm_sales_nonce'),
            'allow_secret_key' => true,
        ));

        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
        $target_entry = null;
        if (class_exists(Data_Layer::class)) {
            $target_entry = Data_Layer::get_sale_by_uid($id);
        } else {
            $log = get_option('jpsm_sales_log', array());
            foreach ($log as $entry) {
                if (isset($entry['id']) && $entry['id'] === $id) {
                    $target_entry = $entry;
                    break;
                }
            }
        }

        if ($target_entry) {
            $sales_engine = new self();

            // Fetch the template based on package through domain model.
            $package_key = class_exists(Domain_Model::class)
                ? Domain_Model::get_template_option($target_entry['package'])
                : 'jpsm_email_template_' . strtolower($target_entry['package']);
            $template_content = get_option($package_key, '');

            // Fallback if empty
            if (empty($template_content)) {
                $template_content = "Hola {email}, aquí está tu paquete {paquete}.";
            }

            $result = $sales_engine->process_sale(
                $target_entry['email'],
                $target_entry['package'],
                $target_entry['region'],
                $template_content
            );

            if ($result['email_sent']) {
                API_Contract::send_success('Correo reenviado', 'email_resent', 'Correo reenviado.', 200);
            } else {
                API_Contract::send_error('Fallo al enviar: ' . $result['email_message'], 'email_delivery_failed', 502);
            }
        } else {
            API_Contract::send_error('Registro no encontrado', 'sale_not_found', 404);
        }
    }

    /**
     * AJAX handler to freeze historical prices into log entries missing amounts.
     *
     * @return void Sends JSON response and exits.
     */
    public static function freeze_prices_ajax()
    {
        self::authorize_sales_ajax(array(
            'nonce_actions' => array('jpsm_nonce', 'jpsm_sales_nonce'),
            'allow_secret_key' => false,
        ));

        $log = class_exists(Data_Layer::class)
            ? Data_Layer::get_sales_log()
            : get_option('jpsm_sales_log', array());
        if (!is_array($log) || empty($log)) {
            API_Contract::send_success('El historial está vacío.', 'history_empty', 'No hay registros.', 200);
        }

        $updated_count = 0;
        foreach ($log as &$entry) {
            // Only update if amount is missing or 0
            $amt = isset($entry['amount']) ? floatval($entry['amount']) : 0;
            if ($amt <= 0) {
                $price = self::get_entry_price($entry);
                $entry['amount'] = $price;
                $entry['currency'] = isset($entry['currency']) ? $entry['currency'] : (($entry['region'] === 'international') ? 'USD' : 'MXN');
                $updated_count++;
            }
        }

        if ($updated_count > 0) {
            if (class_exists(Data_Layer::class)) {
                Data_Layer::replace_sales_log($log);
            } else {
                update_option('jpsm_sales_log', $log);
            }
            API_Contract::send_success("Se han fijado los precios en {$updated_count} registros.", 'prices_frozen', 'Precios históricos actualizados.', 200);
        } else {
            API_Contract::send_success('Todos los registros ya tienen precios fijados.', 'prices_already_frozen', 'No hubo cambios.', 200);
        }
    }

    /**
     * AJAX handler to get the sales history log.
     *
     * @return void Sends JSON response and exits.
     */
    public static function get_history_ajax()
    {
        self::authorize_sales_ajax(array(
            'nonce_actions' => array('jpsm_nonce', 'jpsm_sales_nonce'),
            'allow_secret_key' => true,
        ));

        // Return data
        $log = class_exists(Data_Layer::class)
            ? Data_Layer::get_sales_log()
            : get_option('jpsm_sales_log', array());
        // Ensure array
        if (!is_array($log))
            $log = array();

        API_Contract::send_success($log, 'history_fetched', 'Historial cargado.', 200);
    }

    /**
     * Get persistent lifetime statistics, auto-migrating from sales log if needed.
     *
     * @return array Lifetime stats with total_sales, rev_mxn, rev_usd, and packages.
     */
    public static function get_persistent_stats()
    {
        $stats = get_option('jpsm_lifetime_stats', array());

        // FORCE RE-MIGRATION if version is missing or old
        if (empty($stats) || !isset($stats['version']) || $stats['version'] < 1.1) {

            // Self-migration: Calculate from existing log
            $log = class_exists(Data_Layer::class)
                ? Data_Layer::get_sales_log()
                : get_option('jpsm_sales_log', array());
            $stats = array(
                'version' => 1.1, // Set version to prevent re-run
                'total_sales' => 0,
                'rev_mxn' => 0,
                'rev_usd' => 0,
                'packages' => array('basic' => 0, 'vip' => 0, 'full' => 0)
            );

            foreach ($log as $entry) {
                // FIXED: Use Fallback Price if amount is 0 or missing
                $amt = isset($entry['amount']) && $entry['amount'] > 0 ? floatval($entry['amount']) : self::get_entry_price($entry);
                $cur = $entry['currency'] ?? (($entry['region'] === 'international') ? 'USD' : 'MXN');

                $stats['total_sales']++;

                if ($cur === 'MXN')
                    $stats['rev_mxn'] += $amt;
                else
                    $stats['rev_usd'] += $amt;

                $bucket = self::resolve_stats_bucket($entry['package'] ?? '');
                if (isset($stats['packages'][$bucket])) {
                    $stats['packages'][$bucket]++;
                }
            }
            update_option('jpsm_lifetime_stats', $stats);
        }

        $defaults = array(
            'version' => 1.1,
            'total_sales' => 0,
            'rev_mxn' => 0,
            'rev_usd' => 0,
            'packages' => array('basic' => 0, 'vip' => 0, 'full' => 0)
        );
        return array_merge($defaults, $stats);
    }

    /**
     * Update Persistent Statistics with a new sale
     */
    private static function update_persistent_stats($entry)
    {
        $stats = self::get_persistent_stats();

        $stats['total_sales']++;

        $amt = floatval($entry['amount'] ?? 0);
        $cur = $entry['currency'] ?? 'MXN';

        if ($cur === 'MXN') {
            $stats['rev_mxn'] += $amt;
        } else {
            $stats['rev_usd'] += $amt;
        }

        // Categorize package from domain model
        $bucket = self::resolve_stats_bucket($entry['package'] ?? '');
        if (isset($stats['packages'][$bucket])) {
            $stats['packages'][$bucket]++;
        }

        update_option('jpsm_lifetime_stats', $stats);
    }

    /**
     * Get dashboard stats: today's count, package/region distribution, and recent history.
     *
     * @return array Stats with total, today, packages, regions, and history.
     */
    public static function get_dashboard_stats()
    {
        $log = class_exists(Data_Layer::class)
            ? Data_Layer::get_sales_log()
            : get_option('jpsm_sales_log', array());
        if (!is_array($log))
            $log = array();

        // Compute Stats
        $stats = array(
            'total' => count($log),
            'today' => 0,
            'packages' => array('basic' => 0, 'vip' => 0, 'full' => 0),
            'regions' => array('national' => 0, 'international' => 0),
            'history' => array_slice($log, 0, 10)
        );

        $today = current_time('Y-m-d');
        foreach ($log as $entry) {
            // Check today (entries use current_time('mysql'))
            if (isset($entry['time']) && strpos($entry['time'], $today) === 0)
                $stats['today']++;

            // Count package bucket
            $bucket = self::resolve_stats_bucket($entry['package'] ?? '');
            if (isset($stats['packages'][$bucket])) {
                $stats['packages'][$bucket]++;
            }

            // Count Regions
            $reg = strtolower($entry['region'] ?? '');
            if (isset($stats['regions'][$reg])) {
                $stats['regions'][$reg]++;
            } else {
                $stats['regions'][$reg] = 1;
            }
        }

        return $stats;
    }

    /**
     * Map package label/id to normalized stats bucket.
     */
    private static function resolve_stats_bucket($package)
    {
        if (class_exists(Domain_Model::class)) {
            return Domain_Model::get_stats_bucket($package);
        }

        $pkg = mb_strtolower((string) $package);
        if (strpos($pkg, 'full') !== false) {
            return 'full';
        }
        if (strpos($pkg, 'vip') !== false) {
            return 'vip';
        }
        return 'basic';
    }
}

// Backward compatibility alias.
class_alias(Sales::class, 'JPSM_Sales');
