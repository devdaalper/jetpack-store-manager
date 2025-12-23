<?php

class JPSM_Admin
{

    /**
     * Initialize the class and set its properties.
     */
    public function __construct()
    {
        // Constructor
    }

    /**
     * Run the admin class.
     */
    public function run()
    {
        add_action('admin_menu', array($this, 'add_plugin_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Frontend Assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));

        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_jpsm_process_sale', array($this, 'process_sale_ajax'));
        add_action('wp_ajax_nopriv_jpsm_process_sale', array($this, 'process_sale_ajax')); // Enable for Secret Key

        add_action('wp_ajax_jpsm_delete_log', array($this, 'delete_log_ajax'));
        add_action('wp_ajax_nopriv_jpsm_delete_log', array($this, 'delete_log_ajax')); // Enable for Secret Key

        add_action('wp_ajax_jpsm_delete_all_logs', array($this, 'delete_all_logs_ajax'));
        add_action('wp_ajax_nopriv_jpsm_delete_all_logs', array($this, 'delete_all_logs_ajax')); // Enable for Secret Key

        add_action('wp_ajax_jpsm_delete_bulk_log', array($this, 'delete_bulk_log_ajax'));
        add_action('wp_ajax_nopriv_jpsm_delete_bulk_log', array($this, 'delete_bulk_log_ajax'));

        add_action('wp_ajax_jpsm_resend_email', array($this, 'resend_email_ajax'));
        add_action('wp_ajax_nopriv_jpsm_resend_email', array($this, 'resend_email_ajax')); // Enable for Secret Key

        // Shortcode
        add_shortcode('jetpack_manager', array($this, 'render_frontend_interface'));
    }

    /**
     * Enqueue scripts/styles for Frontend (if shortcode is present)
     */
    public function enqueue_frontend_assets()
    {
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'jetpack_manager')) {
            $this->enqueue_styles();
            // Don't enqueue admin scripts (charts, admin.js) on frontend to prevent conflict/double submission.
            // The frontend uses its own inline JS.
        }
    }

    /**
     * Render the Mobile-Friendly Frontend Interface
     */
    public function render_frontend_interface()
    {
        // Security Check: Admin OR Secret Access Key
        $access_granted = false;

        // Method 1: WordPress Admin
        if (current_user_can('manage_options')) {
            $access_granted = true;
        }

        // Method 2: Secret Access Key via URL
        $stored_key = get_option('jpsm_access_key', '');
        $url_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';

        if (!empty($stored_key) && !empty($url_key) && $url_key === $stored_key) {
            $access_granted = true;
        }

        if (!$access_granted) {
            return '<div style="text-align:center; padding:50px; font-family:sans-serif;"><h2>🔒 Acceso Denegado</h2><p>Ingresa con la clave correcta o inicia sesión como administrador.</p></div>';
        }

        // Force load styles inline to guarantee rendering (bypass enqueue issues)
        $css_file = JPSM_PLUGIN_DIR . 'assets/css/admin.css';
        $styles = '';
        if (file_exists($css_file)) {
            $styles = '<style>' . file_get_contents($css_file) . '</style>';
            $styles .= '<style>
            /* High Contrast Input Fix - Force Dark Background on Autofill */
            input:-webkit-autofill,
            input:-webkit-autofill:hover, 
            input:-webkit-autofill:focus, 
            textarea:-webkit-autofill,
            textarea:-webkit-autofill:hover,
            textarea:-webkit-autofill:focus,
            select:-webkit-autofill:hover,
            select:-webkit-autofill:focus {
                -webkit-text-fill-color: #ffffff !important;
                -webkit-box-shadow: 0 0 0px 1000px #27272a inset !important;
                transition: background-color 5000s ease-in-out 0s;
                caret-color: white;
            }
            
            /* Segmented Control / Radio Group Styles */
            .jpsm-radio-group {
                display: flex;
                gap: 10px;
                width: 100%;
            }
            .jpsm-radio-option {
                flex: 1;
                position: relative;
                cursor: pointer;
            }
            .jpsm-radio-option input {
                position: absolute;
                opacity: 0;
                cursor: pointer;
            }
            .jpsm-radio-option span {
                display: block;
                width: 100%;
                text-align: center;
                padding: 12px 5px;
                background: #27272a;
                border: 1px solid #3f3f46;
                border-radius: 8px;
                color: #a1a1aa;
                font-weight: 500;
                font-size: 13px;
                transition: all 0.2s ease;
            }
            .jpsm-radio-option input:checked + span {
                background: #7c3aed; /* Violet */
                border-color: #7c3aed;
                color: #ffffff;
                box-shadow: 0 4px 6px -1px rgba(124, 58, 237, 0.5);
            }
            </style>';
        }

        // Inline JS to guarantee functionality with Secret Key (bypassing enqueue issues)
        $ajax_nonce = wp_create_nonce('jpsm_nonce');
        $ajax_url = admin_url('admin-ajax.php');
        $current_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $sales_log = get_option('jpsm_sales_log', array());

        $inline_script = "
        <script type='text/javascript'>
        document.addEventListener('DOMContentLoaded', function() {
            var jpsm_vars = {
                ajax_url: '" . esc_url($ajax_url) . "',
                nonce: '" . esc_js($ajax_nonce) . "',
                key: '" . esc_js($current_key) . "',
                history: " . json_encode($sales_log) . "
            };

            // VIP Dropdown Logic
            var pkgSelect = document.getElementById('package_type');
            var vipContainer = document.getElementById('vip-subtype-container');
            
            function toggleVipOptions() {
                if(pkgSelect.value === 'vip') {
                    vipContainer.style.display = 'block';
                } else {
                    vipContainer.style.display = 'none';
                }
            }
            
            if(pkgSelect) {
                pkgSelect.addEventListener('change', toggleVipOptions);
                // Run on init in case browser cached selection
                toggleVipOptions();
            }
            
            // Paste Email Logic
            var pasteBtn = document.getElementById('jpsm-paste-email');
            if(pasteBtn) {
                pasteBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    // Modern API
                    if(navigator.clipboard && navigator.clipboard.readText) {
                        navigator.clipboard.readText().then(function(text) {
                            if(text && text.includes('@')) {
                                document.getElementById('client_email').value = text;
                            } else {
                                alert('El portapapeles no tiene un correo válido');
                            }
                        }).catch(function(err) {
                            alert('Permiso denegado para leer portapapeles');
                        });
                    } else {
                        // Fallback: Try to focus and paste command
                        document.getElementById('client_email').focus();
                        document.execCommand('paste');
                    }
                });
            }

            // Render History
            function renderHistory() {
                var tbodies = document.querySelectorAll('.jpsm-activity-body-target');
                if(tbodies.length === 0) return;
                
                tbodies.forEach(function(tbody) {
                    tbody.innerHTML = '';
                    jpsm_vars.history.forEach(function(item) {
                        var tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td class='jpsm-col-check'>
                                <input type='checkbox' class='jpsm-log-check' value='\${item.id}'>
                            </td>
                            <td class='jpsm-col-info'>
                                <div class='jpsm-info-email'>\${item.email}</div>
                                <div class='jpsm-info-subtext'>\${item.package} • \${item.time}</div>
                            </td>
                            <td class='jpsm-col-actions'>
                                <button class='jpsm-resend-email' data-id='\${item.id}'>🔁</button>
                                <button class='jpsm-delete-log' data-id='\${item.id}'>✕</button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                });
            }
            renderHistory();

            // Resend Email Logic
            document.addEventListener('click', function(e) {
                if(e.target.closest('.jpsm-resend-email')) {
                    var btn = e.target.closest('.jpsm-resend-email');
                    var id = btn.getAttribute('data-id');
                    
                    if(!confirm('¿Reenviar correo al cliente?')) return;
                    
                    btn.innerHTML = '⏳';
                    
                    var formData = new FormData();
                    formData.append('action', 'jpsm_resend_email');
                    formData.append('nonce', jpsm_vars.nonce);
                    formData.append('id', id);
                    if(jpsm_vars.key) formData.append('key', jpsm_vars.key);
                    
                    fetch(jpsm_vars.ajax_url, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if(data.success) {
                            alert('✅ Correo reenviado con éxito');
                            btn.innerHTML = '🔁';
                        } else {
                            alert('❌ Error: ' + data.data);
                            btn.innerHTML = '⚠️';
                        }
                    });
                }
            });

            // Global Delete All Function
            window.jpsmDeleteAllLogs = function() {
                var formData = new FormData();
                formData.append('action', 'jpsm_delete_all_logs');
                formData.append('nonce', jpsm_vars.nonce);
                if(jpsm_vars.key) formData.append('key', jpsm_vars.key);
                
                fetch(jpsm_vars.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        alert('✅ Historial vaciado');
                        location.reload();
                    } else {
                        alert('❌ Error: ' + (data.data || 'Desconocido'));
                    }
                });
            };

            // Process Sale Form
            const form = document.getElementById('jpsm-registration-form');
            if(form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var btn = document.getElementById('jpsm-submit-sale');
                    var msg = document.getElementById('jpsm-message');
                    
                    btn.disabled = true;
                    btn.innerHTML = 'Procesando...';
                    
                    var formData = new FormData(form);
                    formData.append('action', 'jpsm_process_sale');
                    formData.append('nonce', jpsm_vars.nonce);
                    if(jpsm_vars.key) formData.append('key', jpsm_vars.key);
                    
                    fetch(jpsm_vars.ajax_url, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        btn.disabled = false;
                        btn.innerHTML = 'Enviar Pedido 🚀';
                        if(data.success) {
                            msg.className = 'success';
                            msg.innerHTML = '✅ Venta Registrada';
                            msg.style.display = 'block';
                            form.reset();
                            setTimeout(() => { location.reload(); }, 1500); // Reload to update history
                        } else {
                            msg.className = 'error';
                            msg.innerHTML = '❌ ' + (data.data || 'Error');
                            msg.style.display = 'block';
                        }
                    });
                });
            }
            
            // Delete Single Log - Synchronized
            document.addEventListener('click', function(e) {
                if(e.target.closest('.jpsm-delete-log')) {
                    e.preventDefault();
                    var btn = e.target.closest('.jpsm-delete-log');
                    if(!confirm('¿Borrar este registro?')) return;
                    
                    var id = btn.getAttribute('data-id');
                    var formData = new FormData();
                    formData.append('action', 'jpsm_delete_log');
                    formData.append('nonce', jpsm_vars.nonce);
                    formData.append('id', id);
                    if(jpsm_vars.key) formData.append('key', jpsm_vars.key);
                    
                    fetch(jpsm_vars.ajax_url, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if(data.success) {
                            // Remove ALL rows with this ID to sync both tables
                            document.querySelectorAll('.jpsm-delete-log[data-id=\"'+id+'\"]').forEach(function(b) {
                                b.closest('tr').remove();
                            });
                        }
                    });
                }
            });
            // Bulk Actions Logic
            const checkAll = document.getElementById('jpsm-check-all');
            const bulkBtn = document.getElementById('jpsm-bulk-delete');
            const selectedCountSpan = document.getElementById('jpsm-selected-count');
            
            function updateBulkUI() {
                var checked = document.querySelectorAll('.jpsm-log-check:checked');
                var count = checked.length;
                selectedCountSpan.innerText = count;
                
                if(count > 0) {
                    bulkBtn.style.display = 'inline-block';
                    bulkBtn.style.animation = 'popIn 0.3s ease';
                } else {
                    bulkBtn.style.display = 'none';
                }
            }

            // Check All Handler
            if(checkAll) {
                checkAll.addEventListener('change', function() {
                    var checkboxes = document.querySelectorAll('.jpsm-log-check');
                    checkboxes.forEach(function(cb) {
                        cb.checked = checkAll.checked;
                    });
                    updateBulkUI();
                });
            }

            // Individual Checkbox Handler (Delegated)
            document.addEventListener('change', function(e) {
                if(e.target.classList.contains('jpsm-log-check')) {
                    updateBulkUI();
                }
            });

            // Bulk Delete Action
            if(bulkBtn) {
                bulkBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var checked = document.querySelectorAll('.jpsm-log-check:checked');
                    if(checked.length === 0) return;
                    
                    if(!confirm('¿Estás seguro de ELIMINAR ' + checked.length + ' registros?')) return;
                    
                    var ids = [];
                    checked.forEach(function(cb) {
                        ids.push(cb.value);
                    });
                    
                    bulkBtn.disabled = true;
                    bulkBtn.innerHTML = 'Borrando...';
                    
                    var formData = new FormData();
                    formData.append('action', 'jpsm_delete_bulk_log');
                    formData.append('nonce', jpsm_vars.nonce);
                    if(jpsm_vars.key) formData.append('key', jpsm_vars.key);
                    
                    // Send IDs using array notation
                    ids.forEach(function(id) {
                        formData.append('ids[]', id);
                    });
                    
                    fetch(jpsm_vars.ajax_url, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if(data.success) {
                            // Remove rows
                            ids.forEach(function(id) {
                                var checkbox = document.querySelector('.jpsm-log-check[value=\"' + id + '\"]');
                                if(checkbox) checkbox.closest('tr').remove();
                            });
                            
                            // Reset UI
                            bulkBtn.disabled = false;
                            bulkBtn.innerHTML = 'Borrar Seleccionados (<span id=\"jpsm-selected-count\">0</span>)';
                            bulkBtn.style.display = 'none';
                            if(checkAll) checkAll.checked = false;
                            
                            alert('✅ Registros eliminados');
                        } else {
                            alert('❌ Error: ' + (data.data));
                            bulkBtn.disabled = false;
                            bulkBtn.innerHTML = 'Reintentar';
                        }
                    });
                });
            }

        });
        </script>";

        ob_start();
        echo $styles;
        echo $inline_script;
        ?>
        <div id="jpsm-mobile-app">

            <!-- Header -->
            <div class="jpsm-header">
                <h1>🚀 JetPack Store</h1>
                <p>Panel de Gestión</p>
            </div>

            <!-- Mobile Navigation -->
            <div class="jpsm-nav-tabs">
                <button class="jpsm-tab-link active" onclick="jpsmOpenTab(event, 'jpsm-tab-new')">Nueva Venta</button>
                <button class="jpsm-tab-link" onclick="jpsmOpenTab(event, 'jpsm-tab-history')">Historial</button>
                <button class="jpsm-tab-link" onclick="jpsmOpenTab(event, 'jpsm-tab-stats')">Métricas</button>
            </div>

            <!-- TAB 1: NEW SALE -->
            <div id="jpsm-tab-new" class="jpsm-tab-content" style="display:block;">
                <div class="jpsm-mobile-card">
                    <h3>✉️ Registrar Nueva Venta</h3>
                    <form id="jpsm-registration-form">
                        <label>Email del Cliente</label>
                        <div class="jpsm-input-group" style="position:relative;">
                            <input type="email" id="client_email" name="client_email" required placeholder="cliente@ejemplo.com"
                                class="jpsm-input-lg" style="padding-right: 80px;">
                            <button type="button" id="jpsm-paste-email"
                                style="position:absolute; right:5px; top:5px; bottom:5px; background:#2563eb; color:white; border:none; border-radius:6px; padding:0 10px; cursor:pointer;">📋
                                Pegar</button>
                        </div>

                        <label>Paquete</label>
                        <select id="package_type" name="package_type" required class="jpsm-input-lg">
                            <option value="">Seleccionar paquete...</option>
                            <option value="basic">📦 Básico</option>
                            <option value="vip">⭐ VIP</option>
                            <option value="full">💎 Full</option>
                        </select>

                        </select>

                        <!-- VIP Sub-packages Dropdown (Conditional) -->
                        <div id="vip-subtype-container" style="display:none; margin-top:10px;">
                            <label style="color:#8b5cf6; display:block; margin-bottom:8px;">Variante VIP</label>

                            <div class="jpsm-radio-group">
                                <label class="jpsm-radio-option">
                                    <input type="radio" name="vip_subtype" value="vip_videos" checked>
                                    <span>VIP + VIDEOS</span>
                                </label>
                                <label class="jpsm-radio-option">
                                    <input type="radio" name="vip_subtype" value="vip_pelis">
                                    <span>VIP + PELIS</span>
                                </label>
                                <label class="jpsm-radio-option">
                                    <input type="radio" name="vip_subtype" value="vip_basic">
                                    <span>VIP + BÁSICO</span>
                                </label>
                            </div>
                        </div>

                        <label>Región</label>
                        <select id="region" name="region" required class="jpsm-input-lg">
                            <option value="national">🇲🇽 Nacional (MX)</option>
                            <option value="international">🌍 Internacional</option>
                        </select>

                        <button type="submit" id="jpsm-submit-sale" class="jpsm-btn-block">Enviar Pedido 🚀</button>
                        <div id="jpsm-message"></div>
                    </form>
                </div>

                <!-- Recent Activity on Main Screen (Visible by default) -->
                <div class="jpsm-mobile-card" style="margin-top:20px;">
                    <h3>📋 Actividad Reciente</h3>
                    <div class="jpsm-history-list">
                        <table class="jpsm-mobile-table">
                            <thead>
                                <tr>
                                    <th style='width:40px;'></th>
                                    <th>Información</th>
                                    <th style='text-align:right;'>Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="jpsm-activity-body-target"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 2: HISTORY -->
            <div id="jpsm-tab-history" class="jpsm-tab-content">
                <div class="jpsm-mobile-card">
                    <h3>📋 Historial Completo</h3>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; gap:10px;">
                        <button id="jpsm-bulk-delete" style="display:none; font-size:12px; padding:8px 12px;">
                            Borrar Seleccionados (<span id="jpsm-selected-count">0</span>)
                        </button>
                        <button onclick="if(confirm('¿Borrar TODO el historial?')) { jpsmDeleteAllLogs(); }"
                            style="background:transparent; border:1px solid var(--jpsm-danger); color:var(--jpsm-danger); padding:8px 12px; border-radius:6px; font-size:12px; margin-left:auto;">🗑️
                            Vaciado Total</button>
                    </div>
                    <div class="jpsm-history-list">
                        <table class="jpsm-mobile-table">
                            <thead>
                                <tr>
                                    <th style='width:40px;'><input type='checkbox' id='jpsm-check-all'></th>
                                    <th>Información</th>
                                    <th style='text-align:right;'>Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="jpsm-activity-body-target">
                                <!-- JS fills this -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 3: STATS -->
            <div id="jpsm-tab-stats" class="jpsm-tab-content">
                <?php
                // Calculate Stats
                $log = get_option('jpsm_sales_log', array());
                $total_sales = count($log);
                $sales_today = 0;
                $today_date = current_time('Y-m-d');

                $packages = array('basic' => 0, 'vip' => 0, 'full' => 0);
                $regions = array('national' => 0, 'international' => 0);

                foreach ($log as $entry) {
                    // Count Today
                    if (strpos($entry['time'], $today_date) === 0) {
                        $sales_today++;
                    }
                    // Count Packages
                    $pkg = isset($entry['package']) ? strtolower($entry['package']) : '';
                    if (isset($packages[$pkg]))
                        $packages[$pkg]++;

                    // Count Regions
                    $reg = isset($entry['region']) ? strtolower($entry['region']) : '';
                    if (isset($regions[$reg]))
                        $regions[$reg]++;
                }
                ?>

                <div class="jpsm-stats-grid">
                    <div class="jpsm-stat-box">
                        <span>Ventas Hoy</span>
                        <h2 id="stat-today"><?php echo $sales_today; ?></h2>
                    </div>
                    <div class="jpsm-stat-box">
                        <span>Total</span>
                        <h2 id="stat-total"><?php echo $total_sales; ?></h2>
                    </div>
                </div>

                <!-- Charts -->
                <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

                <div class="jpsm-mobile-card">
                    <h3>📦 Por Paquete</h3>
                    <div style="height:200px; position:relative;">
                        <canvas id="jpsmMobileChartPackage"></canvas>
                    </div>
                </div>

                <div class="jpsm-mobile-card">
                    <h3>🌍 Por Región</h3>
                    <div style="height:200px; position:relative;">
                        <canvas id="jpsmMobileChartRegion"></canvas>
                    </div>
                </div>

                <script>             document.addEventListener('DOMContentLoaded', function () {                 // Common Chart Config                 Chart.defaults.color = '#8b949e';                 Chart.defaults.borderColor = '#30363d             ';
                            // Package Chart                 new Chart(document.getElementById('jpsmMobileChartPackage'), {                     type: 'doughnut',                     data: {                         labels: ['Básico', 'VIP', 'Full'],                         datasets: [{                             data: [<?php echo $packages['basic']; ?>, <?php echo $packages['vip']; ?>, <?php echo $packages['full']; ?>],                             backgroundColor: ['#3fb950', '#a371f7', '#db61a2'],                             borderWidth: 0                         }]                     },                     options: {                         maintainAspectRatio: false,                         plugins: {                             legend: { position: 'right', labels: { boxWidth: 12 } }                         }                     }                 });
                            // Region Chart                 new Chart(document.getElementById('jpsmMobileChartRegion'), {                     type: 'bar',                     data: {                         labels: ['Nacional', 'Internacional'],                         datasets: [{                             label: 'Ventas',                             data: [<?php echo $regions['national']; ?>, <?php echo $regions['international']; ?>],                             backgroundColor: ['#58a6ff', '#f0883e'],                             borderRadius: 4                         }]                     },                     options: {                         maintainAspectRatio: false,                         scales: {                             y: { beginAtZero: true, grid: { color: '#21262d' } },                             x: { grid: { display: false } }                         },                         plugins: { legend: { display: false } }                     }                 });             });
                        </script>
                    </div>

                </div>

                <script>     function jpsmOpenTab(evt, tabName) { var i, tabcontent, tablinks; tabcontent = document.getElementsByClassName("jpsm-tab-content"); for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; } tablinks = document.getElementsByClassName("jpsm-tab-link"); for (i = 0; i < tablinks.length; i++) { tablinks[i].className = tablinks[i].className.replace(" active", ""); } document.getElementById(tabName).style.display = "block"; evt.currentTarget.className += " active"; }
                </script>
                <?php
                return ob_get_clean();
    }

    /**
     * Register the administration menu for this plugin into the WordPress Dashboard.
     */
    public function add_plugin_admin_menu()
    {
        add_menu_page(
            'JetPack Store Manager',
            'JetPack Store',
            'manage_options',
            'jetpack-store-manager',
            array($this, 'display_dashboard_page'),
            'dashicons-chart-area',
            6
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'jetpack-store-manager',
            array($this, 'display_dashboard_page')
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Registrar Venta',
            'Registrar Venta',
            'manage_options',
            'jpsm-register-sale',
            array($this, 'display_registration_page')
        );

        add_submenu_page(
            'jetpack-store-manager',
            'Configuración',
            'Configuración',
            'manage_options',
            'jpsm-settings',
            array($this, 'display_settings_page')
        );
    }

    public function register_settings()
    {
        // Email Templates
        register_setting('jpsm_settings_templates', 'jpsm_email_template_basic');
        register_setting('jpsm_settings_templates', 'jpsm_email_template_vip'); // Legacy/Fallback
        register_setting('jpsm_settings_templates', 'jpsm_email_template_full');

        // VIP Sub-packages
        register_setting('jpsm_settings_templates', 'jpsm_email_template_vip_videos');
        register_setting('jpsm_settings_templates', 'jpsm_email_template_vip_pelis');
        register_setting('jpsm_settings_templates', 'jpsm_email_template_vip_basic');

        // Access Key for Mobile
        register_setting('jpsm_settings_templates', 'jpsm_access_key');
    }

    /**
     * Render the Dashboard page.
     */
    public function display_dashboard_page()
    {
        ?>
                <div class="wrap">
                    <h1>Dashboard - JetPack Store Manager</h1>

                    <div class="jpsm-stats-row" style="display:flex; gap:20px; margin-bottom:20px;">
                        <div class="jpsm-card" style="background:#fff; padding:20px; border:1px solid #ccc; flex:1;">
                            <h3 style="margin-top:0;">Ventas Totales</h3>
                            <p class="jpsm-stat-number" id="stat-total" style="font-size:32px; font-weight:bold; margin:0;">0</p>
                        </div>
                        <div class="jpsm-card" style="background:#fff; padding:20px; border:1px solid #ccc; flex:1;">
                            <h3 style="margin-top:0;">Ventas Hoy</h3>
                            <p class="jpsm-stat-number" id="stat-today" style="font-size:32px; font-weight:bold; margin:0;">0</p>
                        </div>
                    </div>

                    <div class="jpsm-charts-row" style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px;">
                        <div class="jpsm-card" style="background:#fff; padding:20px; border:1px solid #ccc; flex:1; min-width:300px;">
                            <h3 style="margin-top:0;">Por Paquete</h3>
                            <canvas id="chart-packages" style="max-height:300px;"></canvas>
                        </div>
                        <div class="jpsm-card" style="background:#fff; padding:20px; border:1px solid #ccc; flex:1; min-width:300px;">
                            <h3 style="margin-top:0;">Por Región</h3>
                            <canvas id="chart-regions" style="max-height:300px;"></canvas>
                        </div>
                    </div>

                    <div class="jpsm-card" style="background:#fff; padding:20px; border:1px solid #ccc;">
                        <h3 style="margin-top:0;">Últimos Movimientos</h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Email</th>
                                    <th>Paquete</th>
                                    <th>Región</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="dashboard-history-body"></tbody>
                        </table>
                    </div>
                </div>
                <?php
    }


    /**
     * Render the Registration page.
     */
    public function display_registration_page()
    {
        ?>
                <div class="wrap">
                    <h1>Registrar Nueva Venta</h1>
                    <div class="jpsm-card"
                        style="background: white; padding: 20px; max-width: 600px; border: 1px solid #ccc; border-radius: 5px;">
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
                                            <option value="basic">Básico</option>
                                            <option value="vip">VIP</option>
                                            <option value="full">Full</option>
                                        </select>
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
                                <button type="submit" id="jpsm-submit-sale" class="button button-primary">Enviar y Registrar</button>
                                <span class="spinner" id="jpsm-spinner" style="float:none;"></span>
                            </p>
                            <div id="jpsm-message"></div>
                        </form>
                    </div>

                    <hr>

                    <h2>Historial Reciente (Sesión actual)</h2>

                    <div class="jpsm-actions-bar" style="height: 40px; margin-bottom: 8px;">
                        <button id='jpsm-bulk-delete'
                            style='display:none; background: #f85149; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px;'>
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
    public function display_settings_page()
    {
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
                            <tr valign="top">
                                <th scope="row">Paquete Básico</th>
                                <td>
                                    <?php wp_editor(get_option('jpsm_email_template_basic'), 'jpsm_email_template_basic', array('textarea_rows' => 10)); ?>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">VIP + VIDEOS</th>
                                <td>
                                    <?php wp_editor(get_option('jpsm_email_template_vip_videos'), 'jpsm_email_template_vip_videos', array('textarea_rows' => 10)); ?>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">VIP + Pelis</th>
                                <td>
                                    <?php wp_editor(get_option('jpsm_email_template_vip_pelis'), 'jpsm_email_template_vip_pelis', array('textarea_rows' => 10)); ?>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">VIP + Básico</th>
                                <td>
                                    <?php wp_editor(get_option('jpsm_email_template_vip_basic'), 'jpsm_email_template_vip_basic', array('textarea_rows' => 10)); ?>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">Paquete Full</th>
                                <td>
                                    <?php wp_editor(get_option('jpsm_email_template_full'), 'jpsm_email_template_full', array('textarea_rows' => 10)); ?>
                                </td>
                            </tr>
                        </table>

                        <hr>
                        <h2>🔑 Acceso Móvil</h2>
                        <p>Configura una clave secreta para acceder desde tu celular sin iniciar sesión en WordPress.</p>
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row">Clave de Acceso</th>
                                <td>
                                    <input type="text" name="jpsm_access_key"
                                        value="<?php echo esc_attr(get_option('jpsm_access_key')); ?>" class="regular-text"
                                        placeholder="Ej: miClaveSecreta123" />
                                    <p class="description">
                                        Tu URL de acceso será:
                                        <code><?php echo esc_url(home_url('/gestion/?key=')); ?><strong>[TU_CLAVE]</strong></code><br>
                                        Guarda esta URL en los favoritos de tu celular.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(); ?>
                    </form>
                </div>
                <?php
    }

    public function enqueue_styles()
    {
        wp_enqueue_style('jpsm-admin-css', JPSM_PLUGIN_URL . 'assets/css/admin.css', array(), JPSM_VERSION, 'all');
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);
        wp_enqueue_script('jpsm-admin-js', JPSM_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'chart-js'), JPSM_VERSION, false);

        // Prepare Dashboard Data
        $log = get_option('jpsm_sales_log', array());
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
            if (strpos($entry['time'], $today) === 0)
                $stats['today']++;

            // Count Packages
            $pkg = strtolower($entry['package']);
            if (isset($stats['packages'][$pkg])) {
                $stats['packages'][$pkg]++;
            } else {
                $stats['packages'][$pkg] = 1; // Fallback
            }

            // Count Regions
            $reg = strtolower($entry['region']);
            if (isset($stats['regions'][$reg])) {
                $stats['regions'][$reg]++;
            } else {
                $stats['regions'][$reg] = 1;
            }
        }

        wp_localize_script('jpsm-admin-js', 'jpsm_data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jpsm_process_sale_nonce'),
            'stats' => $stats
        ));
    }


    /**
     * Handle the AJAX request to process a sale.
     * Refactored for reliability: unified field names, flexible auth, proper error handling.
     */
    public function process_sale_ajax()
    {
        // Flexible Nonce Validation: Accept both nonce names OR Secret Key
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        $nonce_valid = wp_verify_nonce($nonce, 'jpsm_nonce') || wp_verify_nonce($nonce, 'jpsm_process_sale_nonce');

        if (!$nonce_valid && !$this->verify_access()) {
            wp_send_json_error('Sesión expirada. Recarga la página.');
            return;
        }

        // Unified Field Names: Accept both frontend (client_email, package_type) and admin (email, package)
        $email = sanitize_email($_POST['client_email'] ?? $_POST['email'] ?? '');
        $package = sanitize_text_field($_POST['package_type'] ?? $_POST['package'] ?? '');
        $region = sanitize_text_field($_POST['region'] ?? '');

        if (empty($email) || empty($package) || empty($region)) {
            wp_send_json_error('Faltan datos requeridos (email, paquete o región).');
            return;
        }

        // Get Email Template and handle VIP Subtypes
        $template_option = 'jpsm_email_template_' . strtolower($package);

        // Check for VIP Subtype
        if (strtolower($package) === 'vip' && !empty($_POST['vip_subtype'])) {
            $subtype = sanitize_text_field($_POST['vip_subtype']);
            $valid_subtypes = ['vip_videos', 'vip_pelis', 'vip_basic'];

            if (in_array($subtype, $valid_subtypes)) {
                $template_option = 'jpsm_email_template_' . $subtype;

                // Set pretty name for Email/Logs
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

        $template_body = get_option($template_option, '');

        if (empty($template_body)) {
            $template_body = "<p>Hola {email},</p><p>Aquí tienes tu paquete {paquete}.</p>";
        }

        // Process Sale via Sales Engine
        $sales_engine = new JPSM_Sales();
        $result = $sales_engine->process_sale($email, $package, $region, $template_body);

        // Save to local log (regardless of email success for tracking)
        $log_entry = array(
            'id' => uniqid('sale_'),
            'time' => current_time('mysql'),
            'email' => $email,
            'package' => $package,
            'region' => $region,
            'status' => $result['email_sent'] ? 'Completado' : 'Falló'
        );

        $current_log = get_option('jpsm_sales_log', array());
        if (!is_array($current_log)) {
            $current_log = array();
        }
        array_unshift($current_log, $log_entry);

        // Keep only last 100 entries
        $current_log = array_slice($current_log, 0, 100);
        update_option('jpsm_sales_log', $current_log);

        // Return proper success/error based on email result
        if ($result['email_sent']) {
            wp_send_json_success(array(
                'message' => 'Venta registrada. Correo enviado exitosamente.',
                'entry' => $log_entry
            ));
        } else {
            wp_send_json_error('Venta guardada pero el correo falló: ' . $result['email_message']);
        }
    }

    /**
     * Delete log entry via AJAX
     */
    public function delete_log_ajax()
    {
        // Security: Allow if Admin OR valid Secret Key
        if (!$this->verify_access()) {
            wp_send_json_error('Acceso denegado');
        }

        $id_to_delete = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';

        if ($id_to_delete) {
            $current_log = get_option('jpsm_sales_log', array());
            $new_log = array();

            foreach ($current_log as $entry) {
                if (isset($entry['id']) && $entry['id'] === $id_to_delete) {
                    continue; // Skip (Delete)
                }
                $new_log[] = $entry;
            }

            update_option('jpsm_sales_log', $new_log);
            wp_send_json_success('Eliminado');
        }

        wp_send_json_error('ID no válido');
    }

    /**
     * Delete ALL logs via AJAX
     */
    public function delete_all_logs_ajax()
    {
        // Security: Allow if Admin OR valid Secret Key
        if (!$this->verify_access()) {
            wp_send_json_error('Acceso denegado');
        }

        update_option('jpsm_sales_log', array());
        wp_send_json_success('Historial borrado');
    }

    /**
     * Delete Bulk Logs via AJAX
     */
    public function delete_bulk_log_ajax()
    {
        // Security
        if (!$this->verify_access()) {
            wp_send_json_error('Acceso denegado');
        }

        $ids_to_delete = isset($_POST['ids']) ? $_POST['ids'] : [];

        if (!is_array($ids_to_delete) || empty($ids_to_delete)) {
            wp_send_json_error('No hay IDs para borrar');
            return;
        }

        $current_log = get_option('jpsm_sales_log', array());
        $new_log = array();
        $deleted_count = 0;

        foreach ($current_log as $entry) {
            // Check if this entry's ID is in the deletion list
            if (isset($entry['id']) && in_array($entry['id'], $ids_to_delete)) {
                $deleted_count++;
                continue; // Skip/Delete
            }
            $new_log[] = $entry;
        }

        update_option('jpsm_sales_log', $new_log);
        wp_send_json_success("Eliminados $deleted_count registros");
    }

    /**
     * Resend Email via AJAX
     */
    public function resend_email_ajax()
    {
        // Security: Allow if Admin OR valid Secret Key
        if (!$this->verify_access()) {
            wp_send_json_error('Acceso denegado');
        }

        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
        $log = get_option('jpsm_sales_log', array());
        $target_entry = null;

        foreach ($log as $entry) {
            if (isset($entry['id']) && $entry['id'] === $id) {
                $target_entry = $entry;
                break;
            }
        }

        if ($target_entry) {
            $sales_engine = new JPSM_Sales();

            // Fetch the correct template based on package
            $package_key = 'jpsm_email_template_' . strtolower($target_entry['package']);
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
                wp_send_json_success('Correo reenviado');
            } else {
                wp_send_json_error('Fallo al enviar: ' . $result['email_message']);
            }
        } else {
            wp_send_json_error('Registro no encontrado');
        }
    }

    /**
     * Verify access via Admin login OR Secret Key
     */
    private function verify_access()
    {
        // Method 1: Logged-in Admin
        if (current_user_can('manage_options')) {
            return true;
        }

        // Method 2: Secret Key from POST or GET
        $stored_key = get_option('jpsm_access_key', '');
        $provided_key = '';

        if (isset($_POST['key'])) {
            $provided_key = sanitize_text_field($_POST['key']);
        } elseif (isset($_GET['key'])) {
            $provided_key = sanitize_text_field($_GET['key']);
        }

        if (!empty($stored_key) && $provided_key === $stored_key) {
            return true;
        }

        return false;
    }
}
