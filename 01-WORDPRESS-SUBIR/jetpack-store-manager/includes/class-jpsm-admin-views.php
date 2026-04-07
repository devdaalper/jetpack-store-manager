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
    public static function render_setup_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $mv_page_id = intval(get_option(JPSM_Admin::OPTION_MEDIAVAULT_PAGE_ID, 0));
        $mgr_page_id = intval(get_option(JPSM_Admin::OPTION_MANAGER_PAGE_ID, 0));
        $mv_page_ok = ($mv_page_id > 0 && get_post_status($mv_page_id));
        $mgr_page_ok = ($mgr_page_id > 0 && get_post_status($mgr_page_id));

        $b2_ok = class_exists('JPSM_Config') ? JPSM_Config::is_b2_configured() : false;
        $b2_missing = class_exists('JPSM_Config') ? JPSM_Config::get_b2_missing_fields() : array('jpsm_b2_key_id', 'jpsm_b2_app_key', 'jpsm_b2_bucket', 'jpsm_b2_region');

        $index_stats = class_exists('JPSM_Index_Manager') ? JPSM_Index_Manager::get_stats() : array();

        $created = isset($_GET['created']) ? sanitize_text_field(wp_unslash($_GET['created'])) : '';
        ?>
        <div class="wrap">
            <h1>Setup de MediaVault Manager</h1>
            <?php if ($created !== ''): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Setup:</strong> se crearon/registraron páginas: <code><?php echo esc_html($created); ?></code></p>
                </div>
            <?php endif; ?>

            <div class="jpsm-mobile-card" style="max-width: 980px;">
                <h2 style="margin-top:0;">Checklist</h2>
                <ol>
                    <li>
                        <strong>Páginas:</strong>
                        <ul>
                            <li>MediaVault page: <?php echo $mv_page_ok ? '<span style="color:#16a34a;font-weight:700;">OK</span>' : '<span style="color:#dc2626;font-weight:700;">FALTA</span>'; ?>
                                <?php if ($mv_page_ok): ?>
                                    (ID <?php echo intval($mv_page_id); ?>: <a href="<?php echo esc_url(get_permalink($mv_page_id)); ?>" target="_blank" rel="noopener">abrir</a>)
                                <?php endif; ?>
                            </li>
                            <li>Manager page (frontend): <?php echo $mgr_page_ok ? '<span style="color:#16a34a;font-weight:700;">OK</span>' : '<span style="color:#dc2626;font-weight:700;">OPCIONAL</span>'; ?>
                                <?php if ($mgr_page_ok): ?>
                                    (ID <?php echo intval($mgr_page_id); ?>: <a href="<?php echo esc_url(get_permalink($mgr_page_id)); ?>" target="_blank" rel="noopener">abrir</a>)
                                <?php endif; ?>
                            </li>
                        </ul>
                    </li>
                    <li>
                        <strong>Backblaze B2 (S3):</strong>
                        <?php if ($b2_ok): ?>
                            <span style="color:#16a34a;font-weight:700;">OK</span>
                        <?php else: ?>
                            <span style="color:#dc2626;font-weight:700;">FALTA CONFIG</span>
                            <div style="margin-top:6px;">Faltan: <code><?php echo esc_html(implode(', ', (array) $b2_missing)); ?></code></div>
                        <?php endif; ?>
                    </li>
                    <li>
                        <strong>Índice MediaVault:</strong>
                        <span style="color:#0f172a;font-weight:700;"><?php echo intval($index_stats['total'] ?? 0); ?></span> items
                        <?php if (!empty($index_stats['last_sync'])): ?>
                            (última sync: <code><?php echo esc_html((string) $index_stats['last_sync']); ?></code>)
                        <?php else: ?>
                            (sin sync registrada)
                        <?php endif; ?>
                    </li>
                </ol>
            </div>

            <div class="jpsm-mobile-card" style="max-width: 980px;">
                <h2 style="margin-top:0;">Acciones</h2>
                <p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('jpsm_setup_create_pages'); ?>
                        <input type="hidden" name="action" value="jpsm_setup_create_pages">
                        <button type="submit" class="button button-primary">Crear / Detectar páginas automáticamente</button>
                        <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=jpsm-settings')); ?>">Ir a Configuración</a>
                    </form>
                </p>
                <p class="description">
                    Este wizard evita slugs hardcodeados. El loader usa el ID de página seleccionado o detecta el shortcode.
                </p>
            </div>

            <div class="jpsm-mobile-card" style="max-width: 980px;">
                <h2 style="margin-top:0;">Health Checks</h2>
                <p>Este panel es de solo lectura; para acciones usa los botones en Configuración/Sync.</p>
                <table class="widefat striped" style="max-width: 980px;">
                    <tbody>
                        <tr>
                            <th style="width:260px;">B2 Config</th>
                            <td><?php echo $b2_ok ? '<strong style="color:#16a34a;">OK</strong>' : '<strong style="color:#dc2626;">Missing</strong>'; ?></td>
                        </tr>
                        <tr>
                            <th>Index table</th>
                            <td><?php echo !empty($index_stats['table_exists']) ? '<strong style="color:#16a34a;">OK</strong>' : '<strong style="color:#dc2626;">Missing</strong>'; ?></td>
                        </tr>
                        <tr>
                            <th>Index stale</th>
                            <td>
                                <?php
                                $stale = class_exists('JPSM_Index_Manager') ? JPSM_Index_Manager::is_stale() : true;
                                echo $stale ? '<strong style="color:#f59e0b;">Stale</strong>' : '<strong style="color:#16a34a;">Fresh</strong>';
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

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

                <div style="display:grid; gap:10px; margin-bottom:20px;">
                    <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; background:var(--mv-bg); border:1px solid var(--mv-border); border-radius:10px; padding:10px 12px;">
                        <div style="font-size:13px; color:var(--mv-text-muted);">
                            Estado índice: <strong id="jpsm-idx-health">-</strong>
                        </div>
                        <div style="font-size:13px; color:var(--mv-text-muted);">
                            Tabla activa: <strong id="jpsm-idx-active-table">-</strong>
                        </div>
                        <div style="font-size:13px; color:var(--mv-text-muted);">
                            Última sync: <strong id="jpsm-idx-lastsync">-</strong>
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:8px;">
                        <div style="background:var(--mv-bg); border:1px solid var(--mv-border); border-radius:8px; padding:8px 10px;">
                            <div style="font-size:11px; color:var(--mv-text-muted); text-transform:uppercase;">Escaneados</div>
                            <div id="jpsm-idx-q-scanned" style="font-weight:700; color:var(--mv-text);">-</div>
                        </div>
                        <div style="background:var(--mv-bg); border:1px solid var(--mv-border); border-radius:8px; padding:8px 10px;">
                            <div style="font-size:11px; color:var(--mv-text-muted); text-transform:uppercase;">Insertados</div>
                            <div id="jpsm-idx-q-inserted" style="font-weight:700; color:var(--mv-success);">-</div>
                        </div>
                        <div style="background:var(--mv-bg); border:1px solid var(--mv-border); border-radius:8px; padding:8px 10px;">
                            <div style="font-size:11px; color:var(--mv-text-muted); text-transform:uppercase;">Actualizados</div>
                            <div id="jpsm-idx-q-updated" style="font-weight:700; color:var(--mv-accent);">-</div>
                        </div>
                        <div style="background:var(--mv-bg); border:1px solid var(--mv-border); border-radius:8px; padding:8px 10px;">
                            <div style="font-size:11px; color:var(--mv-text-muted); text-transform:uppercase;">Inválidos</div>
                            <div id="jpsm-idx-q-skipped" style="font-weight:700; color:#f59e0b;">-</div>
                        </div>
                        <div style="background:var(--mv-bg); border:1px solid var(--mv-border); border-radius:8px; padding:8px 10px;">
                            <div style="font-size:11px; color:var(--mv-text-muted); text-transform:uppercase;">Errores</div>
                            <div id="jpsm-idx-q-errors" style="font-weight:700; color:var(--mv-danger);">-</div>
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

                <div style="margin-top:24px; padding:14px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                    <h3 style="margin:0 0 8px 0;">🎚️ Importar BPM (CSV)</h3>
                    <p class="description" style="margin-top:0;">
                        Sube un CSV con columnas <code>path,bpm</code> (o <code>file_path,bpm</code>) para completar BPM del catálogo.
                        Estos valores persisten entre sincronizaciones.
                    </p>
                    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                        <input type="file" id="jpsm-bpm-csv-file" accept=".csv,text/csv">
                        <button type="button" id="jpsm-bpm-import-btn" class="button button-secondary">Importar BPM CSV</button>
                    </div>
                    <p id="jpsm-bpm-import-status" style="margin:10px 0 0 0; min-height:18px; color:var(--mv-text-muted);"></p>
                </div>

                <div style="margin-top:12px; padding:14px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                    <h3 style="margin:0 0 8px 0;">🤖 Extraer BPM Automáticamente (Modo Profundo)</h3>
                    <p class="description" style="margin-top:0;">
                        Ejecuta análisis por lotes sobre audios sin BPM: primero intenta metadatos MP3 (tag <code>TBPM</code>) y luego
                        estimación acústica por software (<code>ffmpeg</code>) para los faltantes. El botón reinicia marcas <code>auto_*</code>
                        para reintentar todo el catálogo pendiente.
                    </p>
                    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                        <button type="button" id="jpsm-bpm-auto-btn" class="button button-secondary">Iniciar extracción profunda</button>
                    </div>
                    <p id="jpsm-bpm-auto-status" style="margin:10px 0 0 0; min-height:18px; color:var(--mv-text-muted);"></p>
                </div>

                <div style="margin-top:12px; padding:14px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                    <h3 style="margin:0 0 8px 0;">🔐 Token API Desktop BPM</h3>
                    <p class="description" style="margin-top:0;">
                        Genera o revoca el token para la app de escritorio BPM. El token se muestra solo al generarlo.
                    </p>
                    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                        <button type="button" id="jpsm-desktop-token-issue-btn" class="button button-secondary">Generar token desktop</button>
                        <button type="button" id="jpsm-desktop-token-revoke-btn" class="button">Revocar token</button>
                    </div>
                    <p id="jpsm-desktop-token-value" style="margin:10px 0 0 0; min-height:18px; color:#14532d; font-family:monospace;"></p>
                    <p id="jpsm-desktop-token-status" style="margin:6px 0 0 0; min-height:18px; color:var(--mv-text-muted);"></p>
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

                <!-- SECCIÓN ORDEN DE NAVEGACIÓN (SIDEBAR + INICIO) -->
                <div class="jpsm-mobile-card" style="padding: 0; grid-column: span 2; overflow: hidden;">
                    <h2
                        style="background:var(--mv-bg); margin:0; padding:15px; border-bottom:1px solid var(--mv-border); font-size: 18px; color: var(--mv-text);">
                        🧭 Orden de Carpetas (Navegación)</h2>
                    <div style="padding:20px;">
                        <p>Arrastra y suelta para definir el orden de las carpetas en la barra lateral izquierda y en la
                            pantalla de inicio del MediaVault (Frontend).</p>

                        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom: 12px;">
                            <button type="button" id="jpsm-mv-nav-load" class="button button-secondary">Cargar Carpetas</button>
                            <button type="button" id="jpsm-mv-nav-save" class="button button-primary"
                                style="background-color: var(--mv-accent); border-color: var(--mv-accent);" disabled>
                                Guardar Orden
                            </button>
                            <button type="button" id="jpsm-mv-nav-reset" class="button">Restablecer</button>
                            <span id="jpsm-mv-nav-status" style="font-weight:600; color: var(--mv-text-muted);"></span>
                        </div>

                        <ul id="jpsm-mv-nav-list" style="
                            margin: 0;
                            padding: 0;
                            list-style: none;
                            border: 1px solid var(--mv-border);
                            border-radius: 8px;
                            background: var(--mv-surface);
                            max-height: 320px;
                            overflow-y: auto;
                        "></ul>

                        <p class="description" style="margin-top: 10px;">
                            Nota: si agregas nuevas carpetas en B2, aparecerán al final hasta que vuelvas a ordenar y guardar.
                        </p>
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
	                                    placeholder="cliente@example.com" /></td>
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

    public static function render_finance_page()
    {
        $overview = class_exists('JPSM_Finance') ? JPSM_Finance::get_admin_overview() : array();
        $current = isset($overview['current_month']) && is_array($overview['current_month']) ? $overview['current_month'] : array();
        $lifetime = isset($overview['lifetime']) && is_array($overview['lifetime']) ? $overview['lifetime'] : array();
        $sales_picker_rows = isset($overview['sales_picker_rows']) && is_array($overview['sales_picker_rows']) ? $overview['sales_picker_rows'] : array();
        $recent_settlements = isset($overview['recent_settlements']) && is_array($overview['recent_settlements']) ? $overview['recent_settlements'] : array();
        $recent_expenses = isset($overview['recent_expenses']) && is_array($overview['recent_expenses']) ? $overview['recent_expenses'] : array();
        $market_options = isset($overview['market_options']) && is_array($overview['market_options']) ? $overview['market_options'] : array();
        $channel_options = isset($overview['channel_options']) && is_array($overview['channel_options']) ? $overview['channel_options'] : array();
        $expense_categories = isset($overview['expense_categories']) && is_array($overview['expense_categories']) ? $overview['expense_categories'] : array();

        $fmt_money = static function ($amount, $currency = 'MXN') {
            $prefix = $currency === 'USD' ? 'USD ' : 'MXN ';
            return $prefix . number_format((float) $amount, 2);
        };
        $fmt_rate = static function ($amount) {
            return number_format((float) $amount, 4);
        };
        ?>
        <div class="wrap" id="jpsm-finance-page">
            <h1>Finanzas</h1>
            <p>Controla el dinero efectivamente recibido, las comisiones de pasarela, el tipo de cambio real y los gastos operativos sin mezclarlo con MediaVault.</p>

            <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:20px; margin:20px 0;">
                <div class="jpsm-mobile-card" style="padding:18px;">
                    <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                        <div>
                            <h2 style="margin:0 0 6px 0;">Mes actual</h2>
                            <p style="margin:0; color:var(--mv-text-muted);">Corte operativo del mes en curso.</p>
                        </div>
                        <span class="jpsm-badge">Operación</span>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin-top:16px;">
                        <div style="padding:12px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                            <div style="font-size:12px; color:var(--mv-text-muted);">Ventas brutas MXN</div>
                            <div style="font-size:20px; font-weight:700; color:var(--mv-text);"><?php echo esc_html($fmt_money($current['gross_sales_mxn'] ?? 0, 'MXN')); ?></div>
                        </div>
                        <div style="padding:12px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                            <div style="font-size:12px; color:var(--mv-text-muted);">Ventas brutas USD</div>
                            <div style="font-size:20px; font-weight:700; color:var(--mv-text);"><?php echo esc_html($fmt_money($current['gross_sales_usd'] ?? 0, 'USD')); ?></div>
                        </div>
                        <div style="padding:12px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                            <div style="font-size:12px; color:var(--mv-text-muted);">Neto recibido MXN</div>
                            <div style="font-size:20px; font-weight:700; color:var(--mv-success);"><?php echo esc_html($fmt_money($current['net_received_mxn'] ?? 0, 'MXN')); ?></div>
                        </div>
                        <div style="padding:12px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                            <div style="font-size:12px; color:var(--mv-text-muted);">Comisiones MXN eq.</div>
                            <div style="font-size:20px; font-weight:700; color:#b45309;"><?php echo esc_html($fmt_money($current['fee_mxn_equivalent'] ?? 0, 'MXN')); ?></div>
                        </div>
                        <div style="padding:12px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                            <div style="font-size:12px; color:var(--mv-text-muted);">Gastos MXN eq.</div>
                            <div style="font-size:20px; font-weight:700; color:var(--mv-danger);"><?php echo esc_html($fmt_money($current['expenses_mxn_equivalent'] ?? 0, 'MXN')); ?></div>
                        </div>
                        <div style="padding:12px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                            <div style="font-size:12px; color:var(--mv-text-muted);">Utilidad operativa MXN</div>
                            <div style="font-size:20px; font-weight:700; color:var(--mv-accent);"><?php echo esc_html($fmt_money($current['operating_profit_mxn'] ?? 0, 'MXN')); ?></div>
                        </div>
                    </div>
                    <div style="display:flex; flex-wrap:wrap; gap:14px; margin-top:14px; color:var(--mv-text-muted); font-size:13px;">
                        <span>Pendientes por liquidar: <strong style="color:var(--mv-text);"><?php echo intval($current['unsettled_sales_count'] ?? 0); ?></strong></span>
                        <span>Liquidaciones: <strong style="color:var(--mv-text);"><?php echo intval($current['settlements_count'] ?? 0); ?></strong></span>
                        <span>Gastos: <strong style="color:var(--mv-text);"><?php echo intval($current['expenses_count'] ?? 0); ?></strong></span>
                    </div>
                </div>

                <div class="jpsm-mobile-card" style="padding:18px;">
                    <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                        <div>
                            <h2 style="margin:0 0 6px 0;">Histórico</h2>
                            <p style="margin:0; color:var(--mv-text-muted);">Acumulado de todo el módulo financiero.</p>
                        </div>
                        <span class="jpsm-badge">Acumulado</span>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin-top:16px;">
                        <div style="padding:12px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                            <div style="font-size:12px; color:var(--mv-text-muted);">Ventas brutas MXN</div>
                            <div style="font-size:20px; font-weight:700; color:var(--mv-text);"><?php echo esc_html($fmt_money($lifetime['gross_sales_mxn'] ?? 0, 'MXN')); ?></div>
                        </div>
                        <div style="padding:12px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                            <div style="font-size:12px; color:var(--mv-text-muted);">Ventas brutas USD</div>
                            <div style="font-size:20px; font-weight:700; color:var(--mv-text);"><?php echo esc_html($fmt_money($lifetime['gross_sales_usd'] ?? 0, 'USD')); ?></div>
                        </div>
                        <div style="padding:12px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                            <div style="font-size:12px; color:var(--mv-text-muted);">Neto recibido MXN</div>
                            <div style="font-size:20px; font-weight:700; color:var(--mv-success);"><?php echo esc_html($fmt_money($lifetime['net_received_mxn'] ?? 0, 'MXN')); ?></div>
                        </div>
                        <div style="padding:12px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                            <div style="font-size:12px; color:var(--mv-text-muted);">Comisiones MXN eq.</div>
                            <div style="font-size:20px; font-weight:700; color:#b45309;"><?php echo esc_html($fmt_money($lifetime['fee_mxn_equivalent'] ?? 0, 'MXN')); ?></div>
                        </div>
                        <div style="padding:12px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                            <div style="font-size:12px; color:var(--mv-text-muted);">Gastos MXN eq.</div>
                            <div style="font-size:20px; font-weight:700; color:var(--mv-danger);"><?php echo esc_html($fmt_money($lifetime['expenses_mxn_equivalent'] ?? 0, 'MXN')); ?></div>
                        </div>
                        <div style="padding:12px; border:1px solid var(--mv-border); border-radius:10px; background:var(--mv-bg);">
                            <div style="font-size:12px; color:var(--mv-text-muted);">Utilidad operativa MXN</div>
                            <div style="font-size:20px; font-weight:700; color:var(--mv-accent);"><?php echo esc_html($fmt_money($lifetime['operating_profit_mxn'] ?? 0, 'MXN')); ?></div>
                        </div>
                    </div>
                    <div style="display:flex; flex-wrap:wrap; gap:14px; margin-top:14px; color:var(--mv-text-muted); font-size:13px;">
                        <span>Pendientes MXN: <strong style="color:var(--mv-text);"><?php echo esc_html($fmt_money($lifetime['unsettled_sales_mxn'] ?? 0, 'MXN')); ?></strong></span>
                        <span>Pendientes USD: <strong style="color:var(--mv-text);"><?php echo esc_html($fmt_money($lifetime['unsettled_sales_usd'] ?? 0, 'USD')); ?></strong></span>
                        <span>Pendientes: <strong style="color:var(--mv-text);"><?php echo intval($lifetime['unsettled_sales_count'] ?? 0); ?></strong></span>
                    </div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1.25fr 1fr; gap:20px;">
                <div class="jpsm-mobile-card" style="padding:0; overflow:hidden;">
                    <h2 style="margin:0; padding:15px 20px; border-bottom:1px solid var(--mv-border); background:var(--mv-bg);">Registrar liquidación / ingreso</h2>
                    <div style="padding:20px;">
                        <form id="jpsm-finance-settlement-form">
                            <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px;">
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Mercado</div>
                                    <select id="jpsm-finance-market" name="market">
                                        <?php foreach ($market_options as $option): ?>
                                            <option value="<?php echo esc_attr($option['id']); ?>"><?php echo esc_html($option['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Canal</div>
                                    <select id="jpsm-finance-channel" name="channel">
                                        <?php foreach ($channel_options as $id => $label): ?>
                                            <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Fecha</div>
                                    <input type="date" id="jpsm-finance-settlement-date" name="settlement_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>">
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Cuenta bancaria</div>
                                    <input type="text" id="jpsm-finance-bank-account" name="bank_account" placeholder="BBVA MX / PayPal payout / otra">
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Bruto</div>
                                    <input type="number" step="0.01" min="0" id="jpsm-finance-gross-amount" name="gross_amount" placeholder="0.00">
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Comisión</div>
                                    <input type="number" step="0.01" min="0" id="jpsm-finance-fee-amount" name="fee_amount" placeholder="0.00">
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Neto en moneda original</div>
                                    <input type="number" step="0.01" min="0" id="jpsm-finance-net-amount" name="net_amount" placeholder="0.00">
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Tipo de cambio efectivo</div>
                                    <input type="number" step="0.0001" min="0" id="jpsm-finance-fx-rate" name="fx_rate" placeholder="17.2500">
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Neto recibido en MXN</div>
                                    <input type="number" step="0.01" min="0" id="jpsm-finance-net-mxn" name="net_amount_mxn" placeholder="0.00">
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Referencia externa</div>
                                    <input type="text" id="jpsm-finance-external-ref" name="external_ref" placeholder="ID PayPal / SPEI / estado de cuenta">
                                </label>
                            </div>
                            <label style="display:block; margin-top:14px;">
                                <div style="font-weight:600; margin-bottom:6px;">Notas</div>
                                <textarea id="jpsm-finance-settlement-notes" name="notes" rows="3" placeholder="Observaciones sobre esta liquidación"></textarea>
                            </label>

                            <div style="margin-top:18px; border:1px solid var(--mv-border); border-radius:10px; overflow:hidden;">
                                <div style="padding:12px 14px; background:var(--mv-bg); border-bottom:1px solid var(--mv-border); display:flex; justify-content:space-between; gap:12px; align-items:center;">
                                    <div>
                                        <strong>Ventas para conciliar</strong>
                                        <div style="font-size:12px; color:var(--mv-text-muted);">Selecciona ventas recientes. No mezcles México y USA en la misma liquidación.</div>
                                    </div>
                                    <div style="font-size:13px; color:var(--mv-text-muted);">Seleccionadas: <strong id="jpsm-finance-selected-count">0</strong></div>
                                </div>
                                <div style="max-height:320px; overflow:auto;">
                                    <table class="wp-list-table widefat striped" style="margin:0; border:none;">
                                        <thead>
                                            <tr>
                                                <th style="width:44px;"></th>
                                                <th>Fecha</th>
                                                <th>Email</th>
                                                <th>Paquete</th>
                                                <th>Mercado</th>
                                                <th>Monto</th>
                                                <th>Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($sales_picker_rows)): ?>
                                                <?php foreach ($sales_picker_rows as $row): ?>
                                                    <tr>
                                                        <td>
                                                            <input
                                                                type="checkbox"
                                                                class="jpsm-finance-sale-checkbox"
                                                                value="<?php echo esc_attr($row['id']); ?>"
                                                                data-market="<?php echo esc_attr($row['market']); ?>"
                                                                data-currency="<?php echo esc_attr($row['currency']); ?>"
                                                                data-amount="<?php echo esc_attr(number_format((float) $row['amount'], 2, '.', '')); ?>"
                                                                <?php disabled(!empty($row['linked'])); ?>>
                                                        </td>
                                                        <td><?php echo esc_html((string) $row['time']); ?></td>
                                                        <td><?php echo esc_html((string) $row['email']); ?></td>
                                                        <td><?php echo esc_html((string) $row['package']); ?></td>
                                                        <td><?php echo esc_html(class_exists('JPSM_Finance') ? JPSM_Finance::market_label($row['market']) : strtoupper((string) $row['market'])); ?></td>
                                                        <td><?php echo esc_html($fmt_money($row['amount'], $row['currency'])); ?></td>
                                                        <td>
                                                            <?php if (!empty($row['linked'])): ?>
                                                                <span style="color:#b45309; font-weight:600;">Ya conciliada</span>
                                                            <?php else: ?>
                                                                <span style="color:#166534; font-weight:600;">Pendiente</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="7">No hay ventas recientes para conciliar.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div style="display:flex; gap:12px; align-items:center; margin-top:16px;">
                                <button type="submit" id="jpsm-finance-submit-settlement" class="button button-primary">Guardar liquidación</button>
                                <button type="button" id="jpsm-finance-clear-selection" class="button">Limpiar selección</button>
                            </div>
                            <div id="jpsm-finance-settlement-message" style="margin-top:12px;"></div>
                        </form>
                    </div>
                </div>

                <div class="jpsm-mobile-card" style="padding:0; overflow:hidden;">
                    <h2 style="margin:0; padding:15px 20px; border-bottom:1px solid var(--mv-border); background:var(--mv-bg);">Registrar gasto</h2>
                    <div style="padding:20px;">
                        <form id="jpsm-finance-expense-form">
                            <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px;">
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Fecha</div>
                                    <input type="date" id="jpsm-finance-expense-date" name="expense_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>">
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Categoría</div>
                                    <select id="jpsm-finance-expense-category" name="category">
                                        <?php foreach ($expense_categories as $id => $label): ?>
                                            <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Proveedor</div>
                                    <input type="text" id="jpsm-finance-expense-vendor" name="vendor" placeholder="Proveedor o servicio">
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Cuenta</div>
                                    <input type="text" id="jpsm-finance-expense-account" name="account_label" placeholder="Cuenta pagadora">
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Monto</div>
                                    <input type="number" step="0.01" min="0" id="jpsm-finance-expense-amount" name="amount" placeholder="0.00">
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Moneda</div>
                                    <select id="jpsm-finance-expense-currency" name="currency">
                                        <option value="MXN">MXN</option>
                                        <option value="USD">USD</option>
                                    </select>
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Tipo de cambio</div>
                                    <input type="number" step="0.0001" min="0" id="jpsm-finance-expense-fx-rate" name="fx_rate" placeholder="17.2500">
                                </label>
                                <label>
                                    <div style="font-weight:600; margin-bottom:6px;">Monto equivalente MXN</div>
                                    <input type="number" step="0.01" min="0" id="jpsm-finance-expense-mxn" name="amount_mxn" placeholder="0.00">
                                </label>
                            </div>
                            <label style="display:block; margin-top:14px;">
                                <div style="font-weight:600; margin-bottom:6px;">Descripción</div>
                                <input type="text" id="jpsm-finance-expense-description" name="description" placeholder="Campaña Meta Ads, dominio, outsourcing, etc.">
                            </label>
                            <label style="display:block; margin-top:14px;">
                                <div style="font-weight:600; margin-bottom:6px;">Notas</div>
                                <textarea id="jpsm-finance-expense-notes" name="notes" rows="3" placeholder="Observaciones del gasto"></textarea>
                            </label>
                            <div style="display:flex; gap:12px; align-items:center; margin-top:16px;">
                                <button type="submit" id="jpsm-finance-submit-expense" class="button button-primary">Guardar gasto</button>
                            </div>
                            <div id="jpsm-finance-expense-message" style="margin-top:12px;"></div>
                        </form>
                    </div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:20px;">
                <div class="jpsm-mobile-card" style="padding:0; overflow:hidden;">
                    <h2 style="margin:0; padding:15px 20px; border-bottom:1px solid var(--mv-border); background:var(--mv-bg);">Liquidaciones recientes</h2>
                    <div style="max-height:420px; overflow:auto;">
                        <table class="wp-list-table widefat striped" style="margin:0; border:none;">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Mercado</th>
                                    <th>Canal</th>
                                    <th>Bruto</th>
                                    <th>Comisión</th>
                                    <th>Neto MXN</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_settlements)): ?>
                                    <?php foreach ($recent_settlements as $settlement): ?>
                                        <tr>
                                            <td><?php echo esc_html((string) ($settlement['settlement_date'] ?? '')); ?></td>
                                            <td><?php echo esc_html(class_exists('JPSM_Finance') ? JPSM_Finance::market_label($settlement['market'] ?? '') : (string) ($settlement['market'] ?? '')); ?></td>
                                            <td><?php echo esc_html(class_exists('JPSM_Finance') ? JPSM_Finance::channel_label($settlement['channel'] ?? '') : (string) ($settlement['channel'] ?? '')); ?></td>
                                            <td><?php echo esc_html($fmt_money($settlement['gross_amount'] ?? 0, strtoupper((string) ($settlement['currency'] ?? 'MXN')))); ?></td>
                                            <td><?php echo esc_html($fmt_money($settlement['fee_amount'] ?? 0, strtoupper((string) ($settlement['currency'] ?? 'MXN')))); ?></td>
                                            <td><?php echo esc_html($fmt_money($settlement['net_amount_mxn'] ?? 0, 'MXN')); ?></td>
                                            <td style="text-align:right;">
                                                <button type="button" class="button-link-delete jpsm-finance-delete-settlement" data-settlement="<?php echo esc_attr((string) ($settlement['settlement_uid'] ?? '')); ?>">Eliminar</button>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="7" style="font-size:12px; color:var(--mv-text-muted);">
                                                Ref: <?php echo esc_html((string) ($settlement['external_ref'] ?? 'Sin referencia')); ?>
                                                · Cuenta: <?php echo esc_html((string) ($settlement['bank_account'] ?? 'Sin cuenta')); ?>
                                                · Ventas ligadas: <?php echo intval($settlement['sales_count'] ?? 0); ?>
                                                · FX: <?php echo esc_html($fmt_rate($settlement['fx_rate'] ?? 0)); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7">Aún no hay liquidaciones registradas.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="jpsm-mobile-card" style="padding:0; overflow:hidden;">
                    <h2 style="margin:0; padding:15px 20px; border-bottom:1px solid var(--mv-border); background:var(--mv-bg);">Gastos recientes</h2>
                    <div style="max-height:420px; overflow:auto;">
                        <table class="wp-list-table widefat striped" style="margin:0; border:none;">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Categoría</th>
                                    <th>Detalle</th>
                                    <th>Monto</th>
                                    <th>MXN eq.</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_expenses)): ?>
                                    <?php foreach ($recent_expenses as $expense): ?>
                                        <tr>
                                            <td><?php echo esc_html((string) ($expense['expense_date'] ?? '')); ?></td>
                                            <td><?php echo esc_html(class_exists('JPSM_Finance') ? JPSM_Finance::expense_category_label($expense['category'] ?? '') : (string) ($expense['category'] ?? '')); ?></td>
                                            <td><?php echo esc_html((string) ($expense['vendor'] ?: $expense['description'])); ?></td>
                                            <td><?php echo esc_html($fmt_money($expense['amount'] ?? 0, strtoupper((string) ($expense['currency'] ?? 'MXN')))); ?></td>
                                            <td><?php echo esc_html($fmt_money($expense['amount_mxn'] ?? 0, 'MXN')); ?></td>
                                            <td style="text-align:right;">
                                                <button type="button" class="button-link-delete jpsm-finance-delete-expense" data-expense="<?php echo esc_attr((string) ($expense['expense_uid'] ?? '')); ?>">Eliminar</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6">Aún no hay gastos registrados.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
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
            <h1>Configuración de MediaVault Manager</h1>
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
	                    <?php $has_access_key = ((string) get_option('jpsm_access_key', '')) !== ''; ?>
	                    <input type="password" name="jpsm_access_key" value="" class="regular-text" autocomplete="new-password"
	                        placeholder="<?php echo esc_attr($has_access_key ? '(Deja en blanco para conservar la actual)' : 'Establece una clave nueva'); ?>">
	                    <p class="description">
	                        Esta es la contraseña que se pedirá para ingresar al sistema desde el sitio web de forma independiente.
	                        <br>
	                        <strong>Estado:</strong> <?php echo $has_access_key ? 'configurada' : 'no configurada'; ?>.
	                    </p>
	                </td>
	            </tr>
	            <tr valign="top">
	                <th scope="row">Modo Solo WP Admins</th>
	                <td>
	                    <?php $wp_admin_only = !empty(get_option('jpsm_wp_admin_only_mode', 0)); ?>
	                    <label>
	                        <input type="hidden" name="jpsm_wp_admin_only_mode" value="0">
	                        <input type="checkbox" name="jpsm_wp_admin_only_mode" value="1" <?php checked($wp_admin_only); ?>>
	                        Restringir funcionalidades privilegiadas a usuarios de WordPress con <code>manage_options</code>.
	                    </label>
	                    <p class="description">
	                        Si lo activas, se deshabilita la autenticación por clave (secret-key) y la sesión admin firmada de JPSM.
	                        Útil si quieres operar solo desde WP Admin.
	                    </p>
	                </td>
	            </tr>
	            <tr valign="top">
	                <th scope="row">Permitir Clave en URL (Legacy)</th>
	                <td>
	                    <?php $allow_get_key = !empty(get_option('jpsm_allow_get_key', 0)); ?>
	                    <label>
	                        <input type="hidden" name="jpsm_allow_get_key" value="0">
	                        <input type="checkbox" name="jpsm_allow_get_key" value="1" <?php checked($allow_get_key); ?>>
	                        Permitir <code>?key=...</code> en querystring (no recomendado).
	                    </label>
	                    <p class="description">
	                        Desactivado por defecto por seguridad (URLs se registran en logs/referrers). Preferir POST o header <code>X-JPSM-Key</code>.
	                    </p>
	                </td>
	            </tr>
	        </table>

	        <hr>
	        <h2>📧 Email</h2>
	        <table class="form-table">
	            <tr valign="top">
	                <th scope="row">Reply-To</th>
	                <td>
	                    <input type="email" name="jpsm_reply_to_email"
	                        value="<?php echo esc_attr(get_option('jpsm_reply_to_email', '')); ?>" class="regular-text"
	                        placeholder="reply@example.com">
	                    <p class="description">Si se deja vacío, se usará el email de administrador de WordPress.</p>
	                </td>
	            </tr>
	            <tr valign="top">
	                <th scope="row">Emails de Notificación (Admin)</th>
	                <td>
	                    <?php
	                    $notify_emails = get_option('jpsm_notify_emails', array());
	                    if (!is_array($notify_emails)) {
	                        $notify_emails = array_filter(preg_split('/[\\s,;]+/', (string) $notify_emails, -1, PREG_SPLIT_NO_EMPTY));
	                    }
	                    $notify_text = is_array($notify_emails) ? implode("\n", $notify_emails) : '';
	                    ?>
	                    <textarea name="jpsm_notify_emails" rows="3" class="large-text code"
	                        placeholder="admin@example.com"><?php echo esc_textarea($notify_text); ?></textarea>
	                    <p class="description">
	                        Uno por línea (o separado por comas). Recibe confirmaciones de ventas.
	                        Si se deja vacío, el sistema usa el buzón oficial legacy <code>jetpackstore.oficial@gmail.com</code>.
	                    </p>
	                </td>
	            </tr>
	            <tr valign="top">
	                <th scope="row">Emails Admin (MediaVault)</th>
	                <td>
	                    <?php
	                    $mv_admin_emails = get_option('jpsm_admin_emails', array());
	                    if (!is_array($mv_admin_emails)) {
	                        $mv_admin_emails = array_filter(preg_split('/[\\s,;]+/', (string) $mv_admin_emails, -1, PREG_SPLIT_NO_EMPTY));
	                    }
	                    $mv_admin_text = is_array($mv_admin_emails) ? implode("\n", $mv_admin_emails) : '';
	                    ?>
	                    <textarea name="jpsm_admin_emails" rows="3" class="large-text code"
	                        placeholder="admin@example.com"><?php echo esc_textarea($mv_admin_text); ?></textarea>
	                    <p class="description">
	                        Legacy. Se mantiene por compatibilidad, pero ya no otorga permisos por sí mismo.
	                        <br>
	                        Admin ahora se controla por WP Admin (<code>manage_options</code>) o por sesión admin firmada (login con la contraseña del dashboard).
	                    </p>
	                </td>
	            </tr>
	        </table>

	        <hr>
	        <h2>📱 Contacto (WhatsApp)</h2>
	        <table class="form-table">
	            <tr valign="top">
	                <th scope="row">Número WhatsApp</th>
	                <td>
	                    <input type="text" name="jpsm_whatsapp_number"
	                        value="<?php echo esc_attr(get_option('jpsm_whatsapp_number', '')); ?>" class="regular-text"
	                        placeholder="521234567890">
	                    <p class="description">
	                        Solo dígitos (incluye código de país). Se usa en CTAs de upgrade dentro de MediaVault.
	                    </p>
	                </td>
	            </tr>
	        </table>

		        <hr>
		        <h2>☁️ MediaVault (Backblaze B2)</h2>
		        <p>Configura las credenciales de almacenamiento para el explorador de archivos.</p>
	        <?php if (class_exists('JPSM_Config') && !JPSM_Config::is_b2_configured()): ?>
	            <?php $missing = JPSM_Config::get_b2_missing_fields(); ?>
	            <div class="jpsm-mobile-card" style="background:#fff7ed; border:1px solid #fb923c; padding:12px; border-radius:8px; max-width:900px;">
	                <strong>⚠️ MediaVault no está configurado.</strong>
	                <div style="margin-top:6px; color:#7c2d12;">
	                    Faltan: <code><?php echo esc_html(implode(', ', $missing)); ?></code>
	                </div>
	            </div>
	        <?php endif; ?>
	        <p style="margin-top:10px;">
	            <button type="button" id="jpsm-b2-test-connection" class="button button-secondary">Probar conexión</button>
	            <span id="jpsm-b2-test-status" style="margin-left:10px;"></span>
	        </p>
		        <table class="form-table">
	            <tr valign="top">
	                <th scope="row">Key ID</th>
	                <td>
	                    <?php $has_b2_key_id = ((string) get_option('jpsm_b2_key_id', '')) !== ''; ?>
	                    <input type="text" name="jpsm_b2_key_id" value="" class="regular-text" autocomplete="off"
	                        placeholder="<?php echo esc_attr($has_b2_key_id ? '(Deja en blanco para conservar el actual)' : 'Tu Backblaze Key ID'); ?>">
	                    <p class="description">
	                        <strong>Estado:</strong> <?php echo $has_b2_key_id ? 'configurado' : 'no configurado'; ?>.
	                    </p>
	                </td>
	            </tr>
	            <tr valign="top">
	                <th scope="row">Application Key</th>
	                <td>
	                    <?php $has_b2_app_key = ((string) get_option('jpsm_b2_app_key', '')) !== ''; ?>
	                    <input type="password" name="jpsm_b2_app_key" value="" class="regular-text" autocomplete="new-password"
	                        placeholder="<?php echo esc_attr($has_b2_app_key ? '(Deja en blanco para conservar la actual)' : 'Tu Backblaze Application Key'); ?>">
	                    <p class="description">
	                        La clave secreta de tu aplicación Backblaze.
	                        <br>
	                        <strong>Estado:</strong> <?php echo $has_b2_app_key ? 'configurada' : 'no configurada'; ?>.
	                    </p>
	                </td>
	            </tr>
	            <tr valign="top">
	                <th scope="row">Bucket Name</th>
	                <td>
	                    <input type="text" name="jpsm_b2_bucket"
	                        value="<?php echo esc_attr(get_option('jpsm_b2_bucket', '')); ?>" class="regular-text"
	                        placeholder="my-bucket-name">
	                </td>
	            </tr>
	            <tr valign="top">
	                <th scope="row">Region</th>
	                <td>
	                    <input type="text" name="jpsm_b2_region"
	                        value="<?php echo esc_attr(get_option('jpsm_b2_region', '')); ?>" class="small-text"
	                        placeholder="us-west-004">
	                    <p class="description">Ej: us-west-004 o us-east-005</p>
	                </td>
	            </tr>
	            <tr valign="top">
	                <th scope="row">Cloudflare Domain</th>
	                <td>
	                    <input type="text" name="jpsm_cloudflare_domain"
	                        value="<?php echo esc_attr(get_option('jpsm_cloudflare_domain', '')); ?>" class="regular-text"
	                        placeholder="https://downloads.example.com">
	                    <p class="description">
	                        Origen HTTPS exacto para descargas (formato: <code>https://host</code> o <code>https://host:puerto</code>, sin ruta).
	                        <br>
	                        Requiere Worker proxy de Cloudflare para bucket privado de Backblaze B2.
	                    </p>
	                </td>
	            </tr>
		        </table>

                <hr>
                <h2>📄 Páginas (Onboarding)</h2>
                <p>Para evitar slugs hardcodeados, selecciona la página donde vive cada shortcode.</p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Página MediaVault</th>
                        <td>
                            <?php
                            $mv_page_id = intval(get_option(JPSM_Admin::OPTION_MEDIAVAULT_PAGE_ID, 0));
                            $pages = get_pages(array('post_status' => array('publish', 'draft', 'private')));
                            ?>
                            <select name="<?php echo esc_attr(JPSM_Admin::OPTION_MEDIAVAULT_PAGE_ID); ?>">
                                <option value="0">(Auto por shortcode)</option>
                                <?php foreach ($pages as $p): ?>
                                    <option value="<?php echo intval($p->ID); ?>" <?php selected($mv_page_id, $p->ID); ?>>
                                        <?php echo esc_html($p->post_title); ?> (ID <?php echo intval($p->ID); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Recomendado: una página con <code>[mediavault_vault]</code>.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Página Manager (frontend)</th>
                        <td>
                            <?php $mgr_page_id = intval(get_option(JPSM_Admin::OPTION_MANAGER_PAGE_ID, 0)); ?>
                            <select name="<?php echo esc_attr(JPSM_Admin::OPTION_MANAGER_PAGE_ID); ?>">
                                <option value="0">(No usar / Auto por shortcode)</option>
                                <?php foreach ($pages as $p): ?>
                                    <option value="<?php echo intval($p->ID); ?>" <?php selected($mgr_page_id, $p->ID); ?>>
                                        <?php echo esc_html($p->post_title); ?> (ID <?php echo intval($p->ID); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Si expones el manager al frontend, usa <code>[mediavault_manager]</code>.</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=jpsm-setup')); ?>">Abrir Setup Wizard</a>
                </p>

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
