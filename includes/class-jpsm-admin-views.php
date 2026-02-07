<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class JPSM_Admin_Views
 * 
 * Handles rendering of the WordPress Admin pages.
 * Separated from JPSM_Admin to keep the controller clean.
 */
class JPSM_Admin_Views
{
    /**
     * Render the Dashboard page (Wrapper for Frontend Interface).
     */
    public static function render_dashboard_page()
    {
        // Ensure JPSM_Dashboard is loaded
        if (!class_exists('JPSM_Dashboard')) {
            require_once plugin_dir_path(__FILE__) . 'class-jpsm-dashboard.php';
        }

        if (class_exists('JPSM_Dashboard')) {
            echo JPSM_Dashboard::render();
        } else {
            echo '<div class="wrap"><h1>Error</h1><p>JPSM_Dashboard class missing.</p></div>';
        }
    }

    /**
     * Render the Synchronizer page.
     */
    public static function render_synchronizer_page()
    {
        ?>
        <div class="wrap">
            <h1 class="jpsm-header-title">🔄 Sincronizador de MediaVault</h1>
            <p>Sincroniza el índice local con tus archivos en Backblaze B2 para permitir búsquedas instantáneas.</p>

            <div class="jpsm-mobile-card" style="max-width: 800px; margin-top: 20px;">
                <div
                    style="display:flex; justify-content:space-around; margin-bottom: 30px; background: var(--mv-bg); padding: 20px; border-radius: var(--mv-radius-md); border: 1px solid var(--mv-border);">
                    <div style="text-align:center;">
                        <div id="jpsm-idx-total" style="font-size:32px; font-weight:bold; color: var(--mv-text);">-</div>
                        <div
                            style="font-size: 13px; color: var(--mv-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                            Total
                            Archivos</div>
                    </div>
                    <div style="text-align:center;">
                        <div id="jpsm-idx-audio" style="font-size:32px; font-weight:bold; color:var(--mv-success);">-</div>
                        <div
                            style="font-size: 13px; color: var(--mv-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                            Audios
                        </div>
                    </div>
                    <div style="text-align:center;">
                        <div id="jpsm-idx-video" style="font-size:32px; font-weight:bold; color:var(--mv-accent);">-</div>
                        <div
                            style="font-size: 13px; color: var(--mv-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                            Videos
                        </div>
                    </div>
                </div>

                <div id="jpsm-sync-progress-container"
                    style="display:none; margin-bottom:20px; background:var(--mv-bg); border-radius:6px; height:12px; overflow:hidden; border: 1px solid var(--mv-border);">
                    <div id="jpsm-sync-bar" style="width:0%; height:100%; background:var(--mv-accent); transition:width 0.3s;">
                    </div>
                </div>

                <p id="jpsm-sync-status"
                    style="margin-bottom:20px; font-weight:500; font-size: 14px; text-align: center; min-height: 20px; color: var(--mv-text);">
                </p>

                <div style="text-align: center;">
                    <button type="button" id="jpsm-sync-btn" class="button button-primary button-large"
                        style="padding: 0 40px; height: 46px; line-height: 44px; font-size: 16px; background-color: var(--mv-accent); border-color: var(--mv-accent);">
                        Iniciar Sincronización Ahora
                    </button>
                    <p class="description" style="margin-top: 15px;">La sincronización puede tardar varios minutos
                        dependiendo del volumen de archivos.</p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Access Control page.
     */
    public static function render_access_control_page()
    {
        ?>
        <div class="wrap">
            <h1>🔐 Control de Accesos y Permisos</h1>
            <p>Gestiona los niveles de acceso (Demo, Básico, VIP, Full) para tus usuarios y carpetas de MediaVault.</p>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                <!-- SECCIÓN USUARIOS -->
                <div class="jpsm-mobile-card" style="padding: 0; overflow: hidden;">
                    <h2
                        style="background:var(--mv-bg); margin:0; padding:15px; border-bottom:1px solid var(--mv-border); font-size: 18px; color: var(--mv-text);">
                        👥 Gestionar Usuarios</h2>
                    <div style="padding:20px;">
                        <p>Busca un usuario para ver su nivel actual o cambiarlo.</p>
                        <div style="display:flex; gap:10px; margin-bottom:20px;">
                            <input type="email" id="jpsm-user-search" placeholder="Buscar por email..." class="regular-text"
                                style="flex:1;">
                            <button type="button" id="jpsm-user-search-btn" class="button button-primary"
                                style="background-color: var(--mv-accent); border-color: var(--mv-accent);">Buscar</button>
                        </div>
                        <div id="jpsm-user-result" style="min-height: 50px;"></div>
                    </div>
                </div>

                <!-- SECCIÓN CARPETAS -->
                <div class="jpsm-mobile-card" style="padding: 0; overflow: hidden;">
                    <h2
                        style="background:var(--mv-bg); margin:0; padding:15px; border-bottom:1px solid var(--mv-border); font-size: 18px; color: var(--mv-text);">
                        📁 Permisos por Carpeta</h2>
                    <div style="padding:20px;">
                        <p>Define el nivel mínimo requerido para acceder a cada sub-carpeta de tu MediaVault.</p>
                        <button type="button" id="jpsm-load-folders-btn" class="button button-secondary">Cargar Lista de
                            Carpetas</button>
                        <div id="jpsm-folder-list"
                            style="margin-top:20px; max-height:400px; overflow-y:auto; border: 1px solid var(--mv-border); border-radius: 4px;">
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN LEADS -->
                <div class="jpsm-mobile-card" style="padding: 0; grid-column: span 2; overflow: hidden;">
                    <h2
                        style="background:var(--mv-bg); margin:0; padding:15px; border-bottom:1px solid var(--mv-border); font-size: 18px; color: var(--mv-text);">
                        📊 Prospectos (Leads/Demo)</h2>
                    <div style="padding:20px;">
                        <p>Lista de usuarios que se han registrado bajo el nivel Demo (Leads).</p>
                        <button type="button" id="jpsm-load-leads-btn" class="button button-secondary">Ver Lista de
                            Leads</button>
                        <div id="jpsm-leads-list"
                            style="margin-top:20px; max-height:300px; overflow-y:auto; border: 1px solid var(--mv-border); border-radius: 4px;">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Registration page.
     */
    public static function render_registration_page()
    {
        $sale_package_options = class_exists('JPSM_Domain_Model')
            ? JPSM_Domain_Model::get_sale_package_options()
            : array(
                array('id' => 'basic', 'label' => 'Básico', 'icon' => ''),
                array('id' => 'vip', 'label' => 'VIP', 'icon' => ''),
                array('id' => 'full', 'label' => 'Full', 'icon' => ''),
            );
        $vip_variant_options = class_exists('JPSM_Domain_Model')
            ? JPSM_Domain_Model::get_vip_variant_options()
            : array(
                array('id' => 'vip_videos', 'label' => 'VIP + VIDEOS'),
                array('id' => 'vip_pelis', 'label' => 'VIP + PELIS'),
                array('id' => 'vip_basic', 'label' => 'VIP + BÁSICO'),
            );

        ?>
        <div class="wrap">
            <h1>Registrar Nueva Venta</h1>
            <div class="jpsm-mobile-card"
                style="background: white; padding: 20px; max-width: 600px; border: 1px solid var(--mv-border); border-radius: 12px;">
                <form id="jpsm-registration-form">
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><label for="client_email">Correo del Cliente</label></th>
                            <td><input type="email" id="client_email" name="client_email" class="regular-text" required
                                    placeholder="cliente@email.com" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><label for="package_type">Paquete</label></th>
                            <td>
                                <select id="package_type" name="package_type" required>
                                    <option value="">Selecciona un paquete...</option>
                                    <?php foreach ($sale_package_options as $package_opt): ?>
                                        <option value="<?php echo esc_attr($package_opt['id']); ?>">
                                            <?php
                                            $label = trim((string) ($package_opt['icon'] ?? '') . ' ' . (string) ($package_opt['label'] ?? ''));
                                            echo esc_html($label);
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr valign="top" id="vip-subtype-row" style="display:none;">
                            <th scope="row"><label>Variante VIP</label></th>
                            <td>
                                <fieldset>
                                    <?php foreach ($vip_variant_options as $idx => $variant): ?>
                                        <label>
                                            <input type="radio" name="vip_subtype" value="<?php echo esc_attr($variant['id']); ?>" <?php checked($idx, 0); ?>>
                                            <span><?php echo esc_html($variant['label']); ?></span>
                                        </label><br>
                                    <?php endforeach; ?>
                                </fieldset>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><label for="region">Región</label></th>
                            <td>
                                <select id="region" name="region" required>
                                    <option value="national">Nacional (MX)</option>
                                    <option value="international">Internacional</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" id="jpsm-submit-sale" class="button button-primary"
                            style="background-color: var(--mv-accent); border-color: var(--mv-accent);">Enviar y
                            Registrar</button>
                        <span class="spinner" id="jpsm-spinner" style="float:none;"></span>
                    </p>
                    <div id="jpsm-message"></div>
                </form>
            </div>

            <hr>

            <h2>Historial Reciente (Sesión actual)</h2>

            <div class="jpsm-actions-bar" style="height: 40px; margin-bottom: 8px;">
                <button id='jpsm-bulk-delete'
                    style='display:none; background: var(--mv-danger); color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px;'>
                    Borrar Seleccionados (<span id='jpsm-selected-count'>0</span>)
                </button>
            </div>

            <div id="jpsm-recent-activity">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style='width:30px; padding-left:12px;'><input type='checkbox' id='jpsm-check-all'
                                    style='width:20px; height:20px;'></th>
                            <th>Email</th>
                            <th>Paquete</th>
                            <th>Hora</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="jpsm-activity-body">
                        <!-- Filled by JS -->
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Settings page.
     */
    public static function render_settings_page()
    {
        $settings_packages = class_exists('JPSM_Domain_Model')
            ? JPSM_Domain_Model::get_settings_packages()
            : array(
                array(
                    'id' => 'basic',
                    'label' => 'Básico',
                    'template_option' => 'jpsm_email_template_basic',
                    'price_options' => array('mxn' => 'jpsm_price_mxn_basic'),
                    'settings_price_currencies' => array('mxn'),
                ),
                array(
                    'id' => 'vip_videos',
                    'label' => 'VIP + Videos',
                    'template_option' => 'jpsm_email_template_vip_videos',
                    'price_options' => array('mxn' => 'jpsm_price_mxn_vip_videos', 'usd' => 'jpsm_price_usd_vip_videos'),
                    'settings_price_currencies' => array('mxn', 'usd'),
                ),
                array(
                    'id' => 'vip_pelis',
                    'label' => 'VIP + Películas',
                    'template_option' => 'jpsm_email_template_vip_pelis',
                    'price_options' => array('mxn' => 'jpsm_price_mxn_vip_pelis', 'usd' => 'jpsm_price_usd_vip_pelis'),
                    'settings_price_currencies' => array('mxn', 'usd'),
                ),
                array(
                    'id' => 'vip_basic',
                    'label' => 'VIP + Básico',
                    'template_option' => 'jpsm_email_template_vip_basic',
                    'price_options' => array('mxn' => 'jpsm_price_mxn_vip_basic', 'usd' => 'jpsm_price_usd_vip_basic'),
                    'settings_price_currencies' => array('mxn', 'usd'),
                ),
                array(
                    'id' => 'full',
                    'label' => 'Full',
                    'template_option' => 'jpsm_email_template_full',
                    'price_options' => array('mxn' => 'jpsm_price_mxn_full', 'usd' => 'jpsm_price_usd_full'),
                    'settings_price_currencies' => array('mxn', 'usd'),
                ),
            );

        // Simple Settings Form
        ?>
        <div class="wrap">
            <h1>Configuración de JetPack Store Manager</h1>
            <form method="post" action="options.php">
                <?php
                echo '<h2>Plantillas de Correo</h2>';
                settings_fields('jpsm_settings_templates');
                do_settings_sections('jpsm_settings_templates');

                ?>
                <p>Usa <code>{nombre}</code> como placeholder si lo necesitas (implementación futura).</p>
                <table class="form-table">
                    <?php foreach ($settings_packages as $package): ?>
                        <tr valign="top">
                            <th scope="row">
                                <?php
                                $label = (string) $package['label'];
                                if (in_array($package['id'], array('basic', 'full'), true)) {
                                    $label = 'Paquete ' . $label;
                                }
                                echo esc_html($label);
                                ?>
                            </th>
                            <td>
                                <?php
                                $template_option = (string) $package['template_option'];
                                wp_editor(get_option($template_option), $template_option, array('textarea_rows' => 10));
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <hr>
                <h2>💰 Configuración de Precios</h2>
                <div style="display: flex; gap: 40px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 300px;">
                        <h3>🇲🇽 Precios Nacionales (MXN)</h3>
                        <table class="form-table">
                            <?php foreach ($settings_packages as $package): ?>
                                <?php
                                $currencies = isset($package['settings_price_currencies']) && is_array($package['settings_price_currencies'])
                                    ? $package['settings_price_currencies']
                                    : array();
                                if (!in_array('mxn', $currencies, true)) {
                                    continue;
                                }
                                $opt = isset($package['price_options']['mxn']) ? (string) $package['price_options']['mxn'] : '';
                                if ($opt === '') {
                                    continue;
                                }
                                ?>
                                <tr>
                                    <th scope="row">
                                        <?php
                                        $label = (string) $package['label'];
                                        if (in_array($package['id'], array('basic', 'full'), true)) {
                                            $label = 'Paquete ' . $label;
                                        }
                                        echo esc_html($label);
                                        ?>
                                    </th>
                                    <td>
                                        <input type="number" step="0.01" name="<?php echo esc_attr($opt); ?>"
                                            value="<?php echo esc_attr(get_option($opt)); ?>" class="small-text"> MXN
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                    <div style="flex: 1; min-width: 300px;">
                        <h3>🌍 Precios Internacionales (USD)</h3>
                        <table class="form-table">
                            <?php foreach ($settings_packages as $package): ?>
                                <?php
                                $currencies = isset($package['settings_price_currencies']) && is_array($package['settings_price_currencies'])
                                    ? $package['settings_price_currencies']
                                    : array();
                                if (!in_array('usd', $currencies, true)) {
                                    continue;
                                }
                                $opt = isset($package['price_options']['usd']) ? (string) $package['price_options']['usd'] : '';
                                if ($opt === '') {
                                    continue;
                                }
                                ?>
                                <tr>
                                    <th scope="row">
                                        <?php
                                        $label = (string) $package['label'];
                                        if (in_array($package['id'], array('basic', 'full'), true)) {
                                            $label = 'Paquete ' . $label;
                                        }
                                        echo esc_html($label);
                                        ?>
                                    </th>
                                    <td>
                                        <input type="number" step="0.01" name="<?php echo esc_attr($opt); ?>"
                                            value="<?php echo esc_attr(get_option($opt)); ?>" class="small-text"> USD
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
        </div>

        <hr>
        <h2>🔒 Seguridad</h2>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Contraseña de Acceso (Dashboard)</th>
                <td>
                    <input type="text" name="jpsm_access_key" value="<?php echo esc_attr(get_option('jpsm_access_key')); ?>"
                        class="regular-text">
                    <p class="description">Esta es la contraseña que se pedirá para ingresar al sistema desde el sitio web
                        de forma independiente.</p>
                </td>
            </tr>
        </table>

        <hr>
        <h2>☁️ MediaVault (Backblaze B2)</h2>
        <p>Configura las credenciales de almacenamiento para el explorador de archivos.</p>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Key ID</th>
                <td>
                    <input type="text" name="jpsm_b2_key_id" value="<?php echo esc_attr(get_option('jpsm_b2_key_id')); ?>"
                        class="regular-text">
                    <p class="description">Ejemplo: 005d454a99b9dc60000000007</p>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Application Key</th>
                <td>
                    <input type="password" name="jpsm_b2_app_key" value="<?php echo esc_attr(get_option('jpsm_b2_app_key')); ?>"
                        class="regular-text">
                    <p class="description">La clave secreta de tu aplicación Backblaze.</p>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Bucket Name</th>
                <td>
                    <input type="text" name="jpsm_b2_bucket"
                        value="<?php echo esc_attr(get_option('jpsm_b2_bucket', 'jetpack-downloads')); ?>" class="regular-text">
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Region</th>
                <td>
                    <input type="text" name="jpsm_b2_region"
                        value="<?php echo esc_attr(get_option('jpsm_b2_region', 'us-west-004')); ?>" class="small-text">
                    <p class="description">Ej: us-west-004 o us-east-005</p>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Cloudflare Domain</th>
                <td>
                    <input type="text" name="jpsm_cloudflare_domain"
                        value="<?php echo esc_attr(get_option('jpsm_cloudflare_domain', 'https://descargas.jetpackstore.net')); ?>"
                        class="regular-text">
                    <p class="description">Dominio de descarga (vía Cloudflare).</p>
                </td>
            </tr>
        </table>

        <hr>
        <h2>📜 Mantenimiento de Historial</h2>
        <div class="jpsm-mobile-card"
            style="background:var(--mv-surface-hover); border:1px solid var(--mv-warning); padding:15px; border-radius:6px;">
            <h3 style="margin-top:0; color:var(--mv-warning);">⚠️ Fijar Precios Históricos</h3>
            <p>Si tienes registros antiguos que aparecen en $0.00, este botón les asignará permanentemente el precio que
                tienes
                configurado arriba hoy.</p>
            <p><strong>Usa esto antes de cambiar tus precios</strong> para asegurar que el historial del pasado no se
                altere.
            </p>
            <button type="button" id="jpsm-freeze-prices" class="button button-secondary">Fijar Precios en Historial
                ❄️</button>
            <span id="freeze-status" style="margin-left:10px;"></span>
        </div>

        <?php submit_button(); ?>
        </form>
        </div>
        <?php
    }

    /**
     * Render inline script for admin footer (Background fixes).
     */
    public static function render_admin_footer()
    {
        // Dark mode forcing removed for Media Vault light theme
        if (isset($_GET['page']) && $_GET['page'] === 'jetpack-store-manager') {
            ?>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    // Force Light background on all parent containers
                    var targets = ['html', 'body', '#wpwrap', '#wpcontent', '#wpbody', '#wpbody-content', '.auto-fold #wpcontent'];
                    targets.forEach(function (sel) {
                        var els = document.querySelectorAll(sel);
                        els.forEach(function (el) {
                            el.style.setProperty('background-color', '#f8fafc', 'important'); // Slate 50
                            el.style.setProperty('min-height', '100vh', 'important');
                        });
                    });
                });
            </script>
            <?php
        }
    }
}
