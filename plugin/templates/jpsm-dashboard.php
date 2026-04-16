<?php

if (!defined('ABSPATH')) {
    exit;
}

// Optional inline fallback styles (only when enqueue failed and head already printed).
echo isset($inline_styles) ? $inline_styles : '';

$folder_downloads_data = is_array($folder_downloads ?? null) ? $folder_downloads : array();
$folder_download_rows = isset($folder_downloads_data['rows']) && is_array($folder_downloads_data['rows'])
    ? array_values($folder_downloads_data['rows'])
    : array();
$folder_download_total = intval($folder_downloads_data['total_downloads'] ?? 0);
$folder_download_unique = intval($folder_downloads_data['unique_folders'] ?? 0);
$folder_download_top1 = floatval($folder_downloads_data['top1_share_percent'] ?? 0);
$behavior_data = is_array($behavior_report ?? null) ? $behavior_report : array();
$behavior_summary = isset($behavior_data['summary']) && is_array($behavior_data['summary'])
    ? $behavior_data['summary']
    : array();
$behavior_keywords = isset($behavior_data['top_keywords']) && is_array($behavior_data['top_keywords'])
    ? array_values($behavior_data['top_keywords'])
    : array();
$behavior_zero_keywords = isset($behavior_data['top_zero_keywords']) && is_array($behavior_data['top_zero_keywords'])
    ? array_values($behavior_data['top_zero_keywords'])
    : array();
$behavior_top_downloads = isset($behavior_data['top_downloads']) && is_array($behavior_data['top_downloads'])
    ? array_values($behavior_data['top_downloads'])
    : array();
$behavior_segments = isset($behavior_data['segment_matrix']) && is_array($behavior_data['segment_matrix'])
    ? array_values($behavior_data['segment_matrix'])
    : array();
$behavior_filters = isset($behavior_data['filters']) && is_array($behavior_data['filters'])
    ? $behavior_data['filters']
    : array('tier' => 'all', 'region' => 'all', 'device_class' => 'all');
$behavior_month = (string) ($behavior_data['month'] ?? current_time('Y-m'));
$behavior_month_label = (string) ($behavior_data['month_label'] ?? $behavior_month);
$behavior_prev_label = (string) ($behavior_data['previous_month_label'] ?? '');
$behavior_yoy_label = (string) ($behavior_data['yoy_month_label'] ?? '');
$transfer_data = is_array($transfer_report ?? null) ? $transfer_report : array();
$transfer_kpis = isset($transfer_data['kpis']) && is_array($transfer_data['kpis'])
    ? $transfer_data['kpis']
    : array();
$transfer_filters = isset($transfer_data['filters']) && is_array($transfer_data['filters'])
    ? $transfer_data['filters']
    : array('tier' => 'all', 'region' => 'all', 'device_class' => 'all');
$transfer_month = (string) ($transfer_data['month'] ?? current_time('Y-m'));
$transfer_month_label = (string) ($transfer_data['month_label'] ?? $transfer_month);
$transfer_prev_label = (string) ($transfer_data['previous_month_label'] ?? '');
$transfer_yoy_label = (string) ($transfer_data['yoy_month_label'] ?? '');
$transfer_window = (string) ($transfer_data['window'] ?? 'month');
$transfer_source = (string) ($transfer_data['top_folders_source'] ?? 'behavior_primary');
$transfer_quality = isset($transfer_data['top_folders_quality']) && is_array($transfer_data['top_folders_quality'])
    ? $transfer_data['top_folders_quality']
    : array('is_approx_global' => false, 'reason' => 'none');
$transfer_demand_kpis = isset($transfer_data['demand_kpis']) && is_array($transfer_data['demand_kpis'])
    ? $transfer_data['demand_kpis']
    : array();
$transfer_top_folders = isset($transfer_data['top_folders_month']) && is_array($transfer_data['top_folders_month'])
    ? array_values($transfer_data['top_folders_month'])
    : array();
$transfer_coverage = isset($transfer_data['coverage']) && is_array($transfer_data['coverage'])
    ? $transfer_data['coverage']
    : array();
$transfer_quality_label = !empty($transfer_quality['is_approx_global'])
    ? 'Aproximado global (fallback legacy)'
    : 'Exacto (segmentado)';
$transfer_quality_class = !empty($transfer_quality['is_approx_global']) ? 'accent' : 'positive';
$jpsm_format_bytes_human = static function ($bytes) {
    $value = floatval($bytes);
    if (!is_finite($value) || $value <= 0) {
        return '0 MB';
    }

    $value = $value / (1024 * 1024);
    if ($value < 0.01) {
        return '<0.01 MB';
    }

    $units = array('MB', 'GB', 'TB');
    $unit_index = 0;
    while ($value >= 1024 && $unit_index < (count($units) - 1)) {
        $value = $value / 1024;
        $unit_index++;
    }

    $decimals = ($value >= 100) ? 0 : (($value >= 10) ? 1 : 2);
    return number_format($value, $decimals, '.', ',') . ' ' . $units[$unit_index];
};
$transfer_month_users = intval($transfer_kpis['registered_users_global_month_end'] ?? 0);
$transfer_lifetime_users = intval($transfer_kpis['registered_users_global_actual'] ?? 0);
$transfer_per_user_month_label = $transfer_month_users > 0
    ? $jpsm_format_bytes_human($transfer_kpis['bytes_per_registered_month'] ?? 0)
    : 'N/A';
$transfer_per_user_lifetime_label = $transfer_lifetime_users > 0
    ? $jpsm_format_bytes_human($transfer_kpis['bytes_per_registered_lifetime'] ?? 0)
    : 'N/A';

?>
<div id="jpsm-mobile-app">
    <!-- Dashboard Container -->
    <div class="jpsm-dashboard-container">

        <!-- Sidebar -->
        <aside class="jpsm-sidebar" id="jpsm-sidebar">
            <div class="jpsm-brand">
                <h2>
                    <span class="jpsm-brand-icon" aria-hidden="true">🚀</span>
                    <span class="jpsm-brand-text">MediaVault</span>
                </h2>
                <span class="jpsm-badge">v2.0</span>
            </div>

            <nav class="jpsm-nav-menu">
                <a href="#" class="jpsm-nav-item active" onclick="jpsmOpenTab(event, 'jpsm-tab-manage')" title="Gestión de clientes">
                    <span class="icon" aria-hidden="true">🛒</span>
                    <span class="jpsm-nav-label">Gestión de clientes</span>
                </a>
                <a href="#" class="jpsm-nav-item" onclick="jpsmOpenTab(event, 'jpsm-tab-customers')" title="Base de datos de clientes">
                    <span class="icon" aria-hidden="true">📇</span>
                    <span class="jpsm-nav-label">Base de datos de clientes</span>
                </a>
                <a href="#" class="jpsm-nav-item" onclick="jpsmOpenTab(event, 'jpsm-tab-metrics')" title="Métricas">
                    <span class="icon" aria-hidden="true">📊</span>
                    <span class="jpsm-nav-label">Métricas</span>
                </a>
                <a href="#" class="jpsm-nav-item" onclick="jpsmOpenTab(event, 'jpsm-tab-folder-downloads')" title="Descargas por carpeta">
                    <span class="icon" aria-hidden="true">📁</span>
                    <span class="jpsm-nav-label">Descargas por carpeta</span>
                </a>
                <a href="#" class="jpsm-nav-item" onclick="jpsmOpenTab(event, 'jpsm-tab-behavior')" title="Comportamiento">
                    <span class="icon" aria-hidden="true">🧭</span>
                    <span class="jpsm-nav-label">Comportamiento</span>
                </a>
                <div class="jpsm-nav-divider"></div>
                <a href="#" class="jpsm-nav-item" onclick="jpsmLogout()">
                    <span class="icon" aria-hidden="true">🚪</span>
                    <span class="jpsm-nav-label">Salir</span>
                </a>
            </nav>
        </aside>

        <div class="jpsm-sidebar-overlay" id="jpsm-sidebar-overlay" aria-hidden="true"></div>

        <!-- Main Content Area -->
        <main class="jpsm-main-content">

            <div class="jpsm-header">
                <div class="jpsm-header-row">
                    <button type="button" class="jpsm-sidebar-toggle" id="jpsm-sidebar-toggle" aria-label="Mostrar u ocultar el menú">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <div class="jpsm-header-titles">
                        <h1>Panel de Gestión, <?php echo esc_html((string) ($operator_label ?? 'Admin')); ?></h1>
                        <p class="jpsm-date-display">
                            <?php echo esc_html((string) date_i18n('l, d F Y')); ?>
                        </p>
                    </div>
                </div>
            </div>

            <div id="jpsm-tab-manage" class="jpsm-tab-content" style="display:block;">
                <div class="jpsm-mobile-card">
                    <h3>🛒 Gestión de clientes</h3>
                    <p class="jpsm-card-subtitle">Registra una nueva venta para dar de alta al cliente y enviar su correo.</p>
                    <form id="jpsm-registration-form">
                        <label>Email del Cliente</label>
                        <div class="jpsm-input-group">
	                            <input type="email" id="client_email" name="client_email" required placeholder="cliente@example.com"
	                                class="jpsm-input-lg jpsm-input-with-action">
                            <button type="button" id="jpsm-paste-email" class="jpsm-input-action-btn">📋 Pegar</button>
                        </div>

                        <div class="jpsm-form-row">
                            <div>
                                <label>Paquete</label>
                                <select id="package_type" name="package_type" required class="jpsm-input-lg">
                                    <option value="">Seleccionar paquete...</option>
                                    <?php foreach (($sale_package_options ?? array()) as $option): ?>
                                        <option value="<?php echo esc_attr((string) ($option['id'] ?? '')); ?>">
                                            <?php
                                            $label = trim((string) (($option['icon'] ?? '') . ' ' . ($option['label'] ?? '')));
                                            echo esc_html($label);
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Región</label>
                                <select id="region" name="region" required class="jpsm-input-lg">
                                    <option value="national">🇲🇽 Nacional (MX)</option>
                                    <option value="international">🌍 Internacional</option>
                                </select>
                            </div>
                        </div>

                        <div id="vip-subtype-container" style="display:none; margin-top:10px;">
                            <label style="color:var(--mv-text-muted); display:block; margin-bottom:8px;">Variante VIP</label>
                            <div class="jpsm-radio-group">
                                <?php foreach (($vip_variant_options ?? array()) as $idx => $variant): ?>
                                    <label class="jpsm-radio-option">
                                        <input type="radio" name="vip_subtype" value="<?php echo esc_attr((string) ($variant['id'] ?? '')); ?>"
                                            <?php checked($idx, 0); ?>>
                                        <span><?php echo esc_html((string) ($variant['label'] ?? '')); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" id="jpsm-submit-sale" class="jpsm-btn-block">Enviar Pedido 🚀</button>
                        <div id="jpsm-message"></div>
                    </form>
                </div>
            </div>

            <div id="jpsm-tab-customers" class="jpsm-tab-content">
                <div class="jpsm-customers-layout">
                    <aside class="jpsm-customers-sidebar" id="jpsm-customers-sidebar" aria-label="Lista de clientes">
                        <div class="jpsm-customers-sidebar-header">
                            <div>
                                <div class="jpsm-customers-sidebar-title">Clientes</div>
                                <div class="jpsm-customers-sidebar-meta" id="jpsm-customer-list-meta">Cargando...</div>
                            </div>
                            <button type="button" class="jpsm-icon-btn" id="jpsm-customers-close" aria-label="Cerrar panel de clientes">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                        <div class="jpsm-customers-sidebar-search">
                            <input type="text" id="jpsm-customer-search" placeholder="🔍 Buscar email..." class="jpsm-input-lg jpsm-input-compact">
                        </div>
                        <div class="jpsm-customer-list" id="jpsm-customer-list"></div>
                    </aside>

                    <div class="jpsm-customers-overlay" id="jpsm-customers-overlay" aria-hidden="true"></div>

                    <div class="jpsm-customers-main">
                        <div class="jpsm-mobile-card">
                            <div class="jpsm-card-title-row">
                                <h3 style="margin:0;">📇 Base de datos de clientes</h3>
                                <button type="button" class="jpsm-btn-outline jpsm-customers-open" id="jpsm-customers-open">📇 Clientes</button>
                            </div>

                            <div class="jpsm-filter-row">
                                <span class="jpsm-filter-label">Mostrando:</span>
                                <span class="jpsm-filter-value" id="jpsm-customer-filter-label">Todos</span>
                                <button type="button" class="jpsm-link-btn" id="jpsm-customer-filter-clear" style="display:none;">Quitar filtro</button>
                            </div>

                            <div class="jpsm-actions-row">
                                <button id="jpsm-bulk-delete" class="jpsm-btn-danger-outline" style="display:none;">
                                    Borrar Seleccionados (<span id="jpsm-selected-count">0</span>)
                                </button>
                                <button type="button" class="jpsm-btn-danger-outline" onclick="if(confirm('¿Borrar TODO el historial?')) { jpsmDeleteAllLogs(); }">
                                    🗑️ Vaciado Total
                                </button>
                            </div>

                            <div class="jpsm-search-row">
                                <input type="text" id="jpsm-history-search" placeholder="🔍 Buscar por email o paquete..." class="jpsm-input-lg jpsm-input-compact"
                                    style="margin-bottom:0;">
                            </div>

                            <div class="jpsm-history-list">
                                <table class="jpsm-modern-table">
                                    <thead>
                                        <tr>
                                            <th style='width:40px;'><input type='checkbox' id='jpsm-check-all'></th>
                                            <th>Información</th>
                                            <th style='text-align:right;'>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody class="jpsm-activity-body-target">
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="jpsm-tab-metrics" class="jpsm-tab-content">
                <div class="jpsm-section-header">
                    <h3>📊 Métricas</h3>
                </div>

                <div class="jpsm-kpi-grid">
                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Ventas Hoy</span>
                        <div class="jpsm-kpi-value">
                            <?php echo intval($sales_today ?? 0); ?>
                        </div>
                    </div>

                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Ingresos MXN (Hoy)</span>
                        <div class="jpsm-kpi-value positive">
                            $<?php echo number_format(floatval($rev_today_mxn ?? 0), 0); ?>
                        </div>
                    </div>

                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Ingresos USD (Hoy)</span>
                        <div class="jpsm-kpi-value accent">
                            $<?php echo number_format(floatval($rev_today_usd ?? 0), 0); ?>
                        </div>
                    </div>

                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Total Mes (MXN)</span>
                        <div class="jpsm-kpi-value">
                            $<?php echo number_format(floatval($rev_month_mxn ?? 0), 0); ?>
                        </div>
                    </div>

                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Total Mes (USD)</span>
                        <div class="jpsm-kpi-value accent">
                            $<?php echo number_format(floatval($rev_month_usd ?? 0), 0); ?>
                        </div>
                    </div>

                    <div class="jpsm-kpi-card" style="border-color: var(--mv-warning);">
                        <span class="jpsm-kpi-label">Total Histórico</span>
                        <div class="jpsm-kpi-value" style="color: var(--mv-warning);">
                            <?php echo number_format(floatval(($lifetime_stats['total_sales'] ?? 0)), 0); ?>
                        </div>
                    </div>
                </div>

                <div class="jpsm-section-header">
                    <h3>🛒 Resumen de Historial (1000 reg)</h3>
                </div>
                <div class="jpsm-kpi-grid" style="margin-bottom: var(--mv-space-xl);">
                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Ventas en Log</span>
                        <div class="jpsm-kpi-value">
                            <?php echo intval(is_array($log ?? null) ? count($log) : 0); ?>
                        </div>
                    </div>
                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Ticket Promedio (🇲🇽)</span>
                        <div class="jpsm-kpi-value positive">
                            $<?php echo number_format(floatval($avg_ticket_mxn ?? 0), 0); ?>
                        </div>
                    </div>
                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Ticket Promedio (🌍)</span>
                        <div class="jpsm-kpi-value accent">
                            $<?php echo number_format(floatval($avg_ticket_usd ?? 0), 0); ?>
                        </div>
                    </div>
                </div>

                <div class="jpsm-section-header">
                    <h3>🌎 Total Global (Absoluto)</h3>
                </div>
                <div class="jpsm-kpi-grid" style="margin-bottom: var(--mv-space-xl);">
                    <div class="jpsm-kpi-card" style="border-color: var(--mv-warning);">
                        <span class="jpsm-kpi-label">Ventas Totales</span>
                        <div class="jpsm-kpi-value" style="color: var(--mv-warning);">
                            <?php echo number_format(floatval(($lifetime_stats['total_sales'] ?? 0)), 0); ?>
                        </div>
                    </div>
                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Recaudado MXN</span>
                        <div class="jpsm-kpi-value positive">
                            $<?php echo number_format(floatval(($lifetime_stats['rev_mxn'] ?? 0)), 0); ?>
                        </div>
                    </div>
                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Recaudado USD</span>
                        <div class="jpsm-kpi-value accent">
                            $<?php echo number_format(floatval(($lifetime_stats['rev_usd'] ?? 0)), 0); ?>
                        </div>
                    </div>
                </div>

                <div class="jpsm-mobile-card" style="margin-top: -10px;">
                    <h3 style="color:var(--mv-text);">🏆 Top 5 Clientes</h3>
                    <div class="jpsm-top-clients">
                        <?php
                        $max_total = 0;
                        if (!empty($top_customers ?? array())) {
                            $first = reset($top_customers);
                            $max_total = floatval($first['total'] ?? 0);
                        }
                        foreach (($top_customers ?? array()) as $mail => $c):
                            $percent = ($max_total > 0) ? (floatval($c['total'] ?? 0) / $max_total) * 100 : 0;
                            ?>
                            <div class="jpsm-top-client-item" style="--progress-width: <?php echo esc_attr((string) $percent); ?>%;">
                                <div style="display:flex; flex-direction:column; width:100%; position:relative; z-index:2;">
                                    <div class="jpsm-client-meta">
                                        <span class="jpsm-client-email">
                                            <?php echo esc_html((string) $mail); ?>
                                        </span>
                                        <span class="jpsm-client-total">
                                            <?php
                                            $parts = array();
                                            if (!empty($c['mxn']) && floatval($c['mxn']) > 0) {
                                                $parts[] = "$" . number_format(floatval($c['mxn']), 0);
                                            }
                                            if (!empty($c['usd']) && floatval($c['usd']) > 0) {
                                                $parts[] = "$" . number_format(floatval($c['usd']), 0) . " USD";
                                            }
                                            echo esc_html(implode(' + ', $parts));
                                            ?>
                                        </span>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; margin-top:4px;">
                                        <span class="jpsm-client-count">
                                            <?php echo intval($c['count'] ?? 0); ?> Compras
                                        </span>
                                        <span class="jpsm-client-count">
                                            <?php echo number_format(floatval($percent), 0); ?>%
                                            Vol.
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="jpsm-charts-grid">
                    <div class="jpsm-mobile-card">
                        <h3>📊 Promedio por Día de Semana</h3>
                        <div style="height:180px; position:relative;">
                            <canvas id="jpsmMobileChartWeekday"></canvas>
                        </div>
                    </div>
                    <div class="jpsm-mobile-card">
                        <h3>📦 Por Paquete</h3>
                        <div style="height:180px; position:relative;">
                            <canvas id="jpsmMobileChartPackage"></canvas>
                        </div>
                    </div>
                    <div class="jpsm-mobile-card">
                        <h3>🌍 Por Región</h3>
                        <div style="height:180px; position:relative;">
                            <canvas id="jpsmMobileChartRegion"></canvas>
                        </div>
                    </div>
                    <div class="jpsm-mobile-card">
                        <h3>⏰ Horas Pico</h3>
                        <div style="height:180px; position:relative;">
                            <canvas id="jpsmMobileChartHourly"></canvas>
                        </div>
                    </div>
                    <div class="jpsm-mobile-card jpsm-chart-wide">
                        <h3>📅 Ventas por Día del Mes (1-31)</h3>
                        <div style="height:200px; position:relative;">
                            <canvas id="jpsmChartDayOfMonth"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div id="jpsm-tab-folder-downloads" class="jpsm-tab-content">
                <div class="jpsm-section-header">
                    <h3>📁 Descargas por carpeta</h3>
                </div>

                <div class="jpsm-mobile-card" style="margin-bottom: var(--mv-space-lg);">
                    <div class="jpsm-card-title-row">
                        <h3 style="margin:0;">Transferencia y estacionalidad</h3>
                        <span class="jpsm-folder-demand-meta" id="jpsm-transfer-month-label"><?php echo esc_html($transfer_month_label); ?></span>
                    </div>
                    <input type="hidden" id="jpsm-transfer-window" value="<?php echo esc_attr($transfer_window); ?>">
                    <div class="jpsm-form-row" style="margin-top:12px;">
                        <div>
                            <label>Mes</label>
                            <input type="month" id="jpsm-transfer-month" class="jpsm-input-lg" value="<?php echo esc_attr($transfer_month); ?>">
                        </div>
                        <div>
                            <label>Tier</label>
                            <select id="jpsm-transfer-tier" class="jpsm-input-lg">
                                <option value="all">Todos</option>
                                <option value="0" <?php selected((string) ($transfer_filters['tier'] ?? 'all'), '0'); ?>>Demo</option>
                                <option value="1" <?php selected((string) ($transfer_filters['tier'] ?? 'all'), '1'); ?>>Básico</option>
                                <option value="2" <?php selected((string) ($transfer_filters['tier'] ?? 'all'), '2'); ?>>VIP Básico</option>
                                <option value="3" <?php selected((string) ($transfer_filters['tier'] ?? 'all'), '3'); ?>>VIP Videos</option>
                                <option value="4" <?php selected((string) ($transfer_filters['tier'] ?? 'all'), '4'); ?>>VIP Pelis</option>
                                <option value="5" <?php selected((string) ($transfer_filters['tier'] ?? 'all'), '5'); ?>>Full</option>
                            </select>
                        </div>
                    </div>
                    <div class="jpsm-form-row">
                        <div>
                            <label>Región</label>
                            <select id="jpsm-transfer-region" class="jpsm-input-lg">
                                <option value="all" <?php selected((string) ($transfer_filters['region'] ?? 'all'), 'all'); ?>>Todas</option>
                                <option value="national" <?php selected((string) ($transfer_filters['region'] ?? 'all'), 'national'); ?>>Nacional</option>
                                <option value="international" <?php selected((string) ($transfer_filters['region'] ?? 'all'), 'international'); ?>>Internacional</option>
                                <option value="unknown" <?php selected((string) ($transfer_filters['region'] ?? 'all'), 'unknown'); ?>>Sin región</option>
                            </select>
                        </div>
                        <div>
                            <label>Dispositivo</label>
                            <select id="jpsm-transfer-device" class="jpsm-input-lg">
                                <option value="all" <?php selected((string) ($transfer_filters['device_class'] ?? 'all'), 'all'); ?>>Todos</option>
                                <option value="desktop" <?php selected((string) ($transfer_filters['device_class'] ?? 'all'), 'desktop'); ?>>Desktop</option>
                                <option value="mobile" <?php selected((string) ($transfer_filters['device_class'] ?? 'all'), 'mobile'); ?>>Mobile</option>
                                <option value="tablet" <?php selected((string) ($transfer_filters['device_class'] ?? 'all'), 'tablet'); ?>>Tablet</option>
                                <option value="unknown" <?php selected((string) ($transfer_filters['device_class'] ?? 'all'), 'unknown'); ?>>Sin clasificar</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                        <button type="button" class="jpsm-btn-outline jpsm-transfer-window-shortcut<?php echo ($transfer_window === 'month') ? ' active' : ''; ?>" data-window="month">Mes actual</button>
                        <button type="button" class="jpsm-btn-outline jpsm-transfer-window-shortcut<?php echo ($transfer_window === 'prev_month') ? ' active' : ''; ?>" data-window="prev_month">Mes anterior</button>
                        <button type="button" class="jpsm-btn-outline jpsm-transfer-window-shortcut<?php echo ($transfer_window === 'rolling_90d') ? ' active' : ''; ?>" data-window="rolling_90d">90 días</button>
                        <button type="button" class="jpsm-btn-outline jpsm-transfer-window-shortcut<?php echo ($transfer_window === 'lifetime') ? ' active' : ''; ?>" data-window="lifetime">Lifetime</button>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
                        <button type="button" class="jpsm-btn-outline" id="jpsm-transfer-apply">Aplicar filtros</button>
                        <button type="button" class="jpsm-btn-outline" id="jpsm-transfer-export">Exportar CSV</button>
                        <button type="button" class="jpsm-btn-outline" id="jpsm-transfer-glossary-open">Glosario KPI</button>
                        <span class="jpsm-folder-demand-meta <?php echo esc_attr($transfer_quality_class); ?>" id="jpsm-transfer-quality-badge" data-source="<?php echo esc_attr($transfer_source); ?>">
                            <?php echo esc_html($transfer_quality_label); ?>
                        </span>
                    </div>
                    <p class="jpsm-card-subtitle" id="jpsm-transfer-compare-label" style="margin-top:10px;">
                        Comparado contra: <?php echo esc_html($transfer_prev_label); ?> (MoM) y <?php echo esc_html($transfer_yoy_label); ?> (YoY)
                    </p>
                </div>

                <div class="jpsm-transfer-kpi-layout">
                <div class="jpsm-kpi-grid jpsm-transfer-kpi-grid">
                    <div class="jpsm-kpi-card jpsm-transfer-kpi-card" data-kpi-key="observed_window">
                        <div class="jpsm-kpi-head">
                            <span class="jpsm-kpi-label" data-kpi-label="observed_window">Tráfico real medido (período)</span>
                            <button type="button" class="jpsm-kpi-help-btn" data-kpi-help-for="observed_window" aria-label="Ayuda: Tráfico real medido (período)">?</button>
                        </div>
                        <div class="jpsm-kpi-subtitle" data-kpi-subtitle="observed_window">Datos exactos medidos en esta ventana.</div>
                        <div class="jpsm-kpi-value" id="jpsm-transfer-observed-month"><?php echo esc_html($jpsm_format_bytes_human($transfer_kpis['transfer_observed_bytes_month'] ?? 0)); ?></div>
                        <span class="jpsm-kpi-status-chip status-info" data-kpi-status="observed_window">Referencial</span>
                    </div>
                    <div class="jpsm-kpi-card jpsm-transfer-kpi-card" data-kpi-key="authorized_window">
                        <div class="jpsm-kpi-head">
                            <span class="jpsm-kpi-label" data-kpi-label="authorized_window">Tráfico permitido (período)</span>
                            <button type="button" class="jpsm-kpi-help-btn" data-kpi-help-for="authorized_window" aria-label="Ayuda: Tráfico permitido (período)">?</button>
                        </div>
                        <div class="jpsm-kpi-subtitle" data-kpi-subtitle="authorized_window">Estimación de lo que el sistema habilitó.</div>
                        <div class="jpsm-kpi-value" id="jpsm-transfer-authorized-month"><?php echo esc_html($jpsm_format_bytes_human($transfer_kpis['transfer_authorized_bytes_month'] ?? 0)); ?></div>
                        <span class="jpsm-kpi-status-chip status-info" data-kpi-status="authorized_window">Referencial</span>
                    </div>
                    <div class="jpsm-kpi-card jpsm-transfer-kpi-card" data-kpi-key="observed_lifetime">
                        <div class="jpsm-kpi-head">
                            <span class="jpsm-kpi-label" data-kpi-label="observed_lifetime">Tráfico real medido (acumulado)</span>
                            <button type="button" class="jpsm-kpi-help-btn" data-kpi-help-for="observed_lifetime" aria-label="Ayuda: Tráfico real medido (acumulado)">?</button>
                        </div>
                        <div class="jpsm-kpi-subtitle" data-kpi-subtitle="observed_lifetime">Histórico exacto acumulado.</div>
                        <div class="jpsm-kpi-value" id="jpsm-transfer-observed-lifetime"><?php echo esc_html($jpsm_format_bytes_human($transfer_kpis['transfer_observed_bytes_lifetime'] ?? 0)); ?></div>
                        <span class="jpsm-kpi-status-chip status-info" data-kpi-status="observed_lifetime">Referencial</span>
                    </div>
                    <div class="jpsm-kpi-card jpsm-transfer-kpi-card" data-kpi-key="authorized_lifetime">
                        <div class="jpsm-kpi-head">
                            <span class="jpsm-kpi-label" data-kpi-label="authorized_lifetime">Tráfico permitido (acumulado)</span>
                            <button type="button" class="jpsm-kpi-help-btn" data-kpi-help-for="authorized_lifetime" aria-label="Ayuda: Tráfico permitido (acumulado)">?</button>
                        </div>
                        <div class="jpsm-kpi-subtitle" data-kpi-subtitle="authorized_lifetime">Histórico habilitado por el sistema.</div>
                        <div class="jpsm-kpi-value" id="jpsm-transfer-authorized-lifetime"><?php echo esc_html($jpsm_format_bytes_human($transfer_kpis['transfer_authorized_bytes_lifetime'] ?? 0)); ?></div>
                        <span class="jpsm-kpi-status-chip status-info" data-kpi-status="authorized_lifetime">Referencial</span>
                    </div>
                    <div class="jpsm-kpi-card jpsm-transfer-kpi-card" data-kpi-key="bytes_per_user_window">
                        <div class="jpsm-kpi-head">
                            <span class="jpsm-kpi-label" data-kpi-label="bytes_per_user_window">MB por cliente (período)</span>
                            <button type="button" class="jpsm-kpi-help-btn" data-kpi-help-for="bytes_per_user_window" aria-label="Ayuda: MB por cliente (período)">?</button>
                        </div>
                        <div class="jpsm-kpi-subtitle" data-kpi-subtitle="bytes_per_user_window">Consumo promedio por cliente.</div>
                        <div class="jpsm-kpi-value accent" id="jpsm-transfer-per-user-month"><?php echo esc_html($transfer_per_user_month_label); ?></div>
                        <span class="jpsm-kpi-status-chip status-info" data-kpi-status="bytes_per_user_window">Contextual</span>
                    </div>
                    <div class="jpsm-kpi-card jpsm-transfer-kpi-card" data-kpi-key="bytes_per_user_lifetime">
                        <div class="jpsm-kpi-head">
                            <span class="jpsm-kpi-label" data-kpi-label="bytes_per_user_lifetime">MB por cliente (acumulado)</span>
                            <button type="button" class="jpsm-kpi-help-btn" data-kpi-help-for="bytes_per_user_lifetime" aria-label="Ayuda: MB por cliente (acumulado)">?</button>
                        </div>
                        <div class="jpsm-kpi-subtitle" data-kpi-subtitle="bytes_per_user_lifetime">Consumo histórico promedio por cliente.</div>
                        <div class="jpsm-kpi-value accent" id="jpsm-transfer-per-user-lifetime"><?php echo esc_html($transfer_per_user_lifetime_label); ?></div>
                        <span class="jpsm-kpi-status-chip status-info" data-kpi-status="bytes_per_user_lifetime">Contextual</span>
                    </div>
                    <div class="jpsm-kpi-card jpsm-transfer-kpi-card" data-kpi-key="coverage_precision">
                        <div class="jpsm-kpi-head">
                            <span class="jpsm-kpi-label" data-kpi-label="coverage_precision">% del tráfico medido con precisión</span>
                            <button type="button" class="jpsm-kpi-help-btn" data-kpi-help-for="coverage_precision" aria-label="Ayuda: % del tráfico medido con precisión">?</button>
                        </div>
                        <div class="jpsm-kpi-subtitle" data-kpi-subtitle="coverage_precision">Calidad de medición para decisiones de tráfico.</div>
                        <div class="jpsm-kpi-value positive" id="jpsm-transfer-coverage-ratio"><?php echo esc_html(number_format(floatval($transfer_coverage['coverage_event_ratio'] ?? 0), 1)); ?>%</div>
                        <span class="jpsm-kpi-status-chip status-info" data-kpi-status="coverage_precision">Sin evaluar</span>
                    </div>
                    <div class="jpsm-kpi-card jpsm-transfer-kpi-card" data-kpi-key="downloads_window">
                        <div class="jpsm-kpi-head">
                            <span class="jpsm-kpi-label" data-kpi-label="downloads_window">Descargas del período</span>
                            <button type="button" class="jpsm-kpi-help-btn" data-kpi-help-for="downloads_window" aria-label="Ayuda: Descargas del período">?</button>
                        </div>
                        <div class="jpsm-kpi-subtitle" data-kpi-subtitle="downloads_window">Demanda total en la ventana.</div>
                        <div class="jpsm-kpi-value" id="jpsm-transfer-downloads-window"><?php echo number_format(intval($transfer_demand_kpis['downloads_total_window'] ?? 0), 0); ?></div>
                        <span class="jpsm-kpi-status-chip status-info" data-kpi-status="downloads_window">Tendencia</span>
                    </div>
                    <div class="jpsm-kpi-card jpsm-transfer-kpi-card" data-kpi-key="unique_folders_window">
                        <div class="jpsm-kpi-head">
                            <span class="jpsm-kpi-label" data-kpi-label="unique_folders_window">Variedad de interés (carpetas únicas)</span>
                            <button type="button" class="jpsm-kpi-help-btn" data-kpi-help-for="unique_folders_window" aria-label="Ayuda: Variedad de interés (carpetas únicas)">?</button>
                        </div>
                        <div class="jpsm-kpi-subtitle" data-kpi-subtitle="unique_folders_window">Diversidad de contenido descargado.</div>
                        <div class="jpsm-kpi-value" id="jpsm-transfer-unique-folders-window"><?php echo number_format(intval($transfer_demand_kpis['unique_folders_window'] ?? 0), 0); ?></div>
                        <span class="jpsm-kpi-status-chip status-info" data-kpi-status="unique_folders_window">Tendencia</span>
                    </div>
                    <div class="jpsm-kpi-card jpsm-transfer-kpi-card" data-kpi-key="top1_dependence">
                        <div class="jpsm-kpi-head">
                            <span class="jpsm-kpi-label" data-kpi-label="top1_dependence">Dependencia de la carpeta #1</span>
                            <button type="button" class="jpsm-kpi-help-btn" data-kpi-help-for="top1_dependence" aria-label="Ayuda: Dependencia de la carpeta #1">?</button>
                        </div>
                        <div class="jpsm-kpi-subtitle" data-kpi-subtitle="top1_dependence">Cuánto pesa la carpeta líder en la demanda.</div>
                        <div class="jpsm-kpi-value" id="jpsm-transfer-top1-share"><?php echo esc_html(number_format(floatval($transfer_demand_kpis['top1_share_percent'] ?? 0), 1)); ?>%</div>
                        <span class="jpsm-kpi-status-chip status-info" data-kpi-status="top1_dependence">Sin evaluar</span>
                    </div>
                    <div class="jpsm-kpi-card jpsm-transfer-kpi-card" data-kpi-key="top3_dependence">
                        <div class="jpsm-kpi-head">
                            <span class="jpsm-kpi-label" data-kpi-label="top3_dependence">Dependencia de las 3 carpetas top</span>
                            <button type="button" class="jpsm-kpi-help-btn" data-kpi-help-for="top3_dependence" aria-label="Ayuda: Dependencia de las 3 carpetas top">?</button>
                        </div>
                        <div class="jpsm-kpi-subtitle" data-kpi-subtitle="top3_dependence">Concentración global en el top 3.</div>
                        <div class="jpsm-kpi-value" id="jpsm-transfer-top3-share"><?php echo esc_html(number_format(floatval($transfer_demand_kpis['top3_share_percent'] ?? 0), 1)); ?>%</div>
                        <span class="jpsm-kpi-status-chip status-info" data-kpi-status="top3_dependence">Sin evaluar</span>
                    </div>
                    <div class="jpsm-kpi-card jpsm-transfer-kpi-card" data-kpi-key="rising_folders">
                        <div class="jpsm-kpi-head">
                            <span class="jpsm-kpi-label" data-kpi-label="rising_folders">Carpetas con interés en crecimiento</span>
                            <button type="button" class="jpsm-kpi-help-btn" data-kpi-help-for="rising_folders" aria-label="Ayuda: Carpetas con interés en crecimiento">?</button>
                        </div>
                        <div class="jpsm-kpi-subtitle" data-kpi-subtitle="rising_folders">Cantidad de carpetas que suben vs periodo previo.</div>
                        <div class="jpsm-kpi-value" id="jpsm-transfer-rising-folders"><?php echo ($transfer_demand_kpis['rising_folders_count'] ?? null) === null ? 'N/A' : number_format(intval($transfer_demand_kpis['rising_folders_count']), 0); ?></div>
                        <span class="jpsm-kpi-status-chip status-info" data-kpi-status="rising_folders">Tendencia</span>
                    </div>
                    <div class="jpsm-kpi-card jpsm-transfer-kpi-card" data-kpi-key="new_folders">
                        <div class="jpsm-kpi-head">
                            <span class="jpsm-kpi-label" data-kpi-label="new_folders">Carpetas que entran por primera vez</span>
                            <button type="button" class="jpsm-kpi-help-btn" data-kpi-help-for="new_folders" aria-label="Ayuda: Carpetas que entran por primera vez">?</button>
                        </div>
                        <div class="jpsm-kpi-subtitle" data-kpi-subtitle="new_folders">Nuevos focos de demanda detectados.</div>
                        <div class="jpsm-kpi-value" id="jpsm-transfer-new-folders"><?php echo ($transfer_demand_kpis['new_folders_count'] ?? null) === null ? 'N/A' : number_format(intval($transfer_demand_kpis['new_folders_count']), 0); ?></div>
                        <span class="jpsm-kpi-status-chip status-info" data-kpi-status="new_folders">Tendencia</span>
                    </div>
                </div>
                <aside class="jpsm-mobile-card jpsm-kpi-glossary-card" id="jpsm-transfer-kpi-glossary" aria-label="Glosario de KPIs de descarga">
                    <div class="jpsm-card-title-row" style="margin-bottom:8px;">
                        <h3 style="margin:0;">Glosario KPI</h3>
                        <button type="button" class="jpsm-icon-btn" id="jpsm-transfer-glossary-close" aria-label="Cerrar glosario">✕</button>
                    </div>
                    <p class="jpsm-card-subtitle" style="margin-bottom:8px;">Definiciones para lectura de negocio.</p>
                    <div class="jpsm-kpi-glossary-list" id="jpsm-transfer-kpi-glossary-body"></div>
                </aside>
                </div>
                <div class="jpsm-kpi-glossary-overlay" id="jpsm-transfer-glossary-overlay" aria-hidden="true"></div>
                <div class="jpsm-kpi-tooltip" id="jpsm-transfer-kpi-tooltip" role="tooltip" aria-hidden="true"></div>

                <div class="jpsm-mobile-card">
                    <div class="jpsm-card-title-row">
                        <h3 style="margin:0;">📈 Serie diaria 90 días (observado)</h3>
                    </div>
                    <div style="height:220px; position:relative;">
                        <canvas id="jpsmTransferDailyChart"></canvas>
                    </div>
                </div>

                <div class="jpsm-mobile-card">
                    <div class="jpsm-card-title-row">
                        <h3 style="margin:0;">📆 Serie mensual (absoluta)</h3>
                    </div>
                    <div style="height:220px; position:relative;">
                        <canvas id="jpsmTransferMonthlyAbsoluteChart"></canvas>
                    </div>
                </div>

                <div class="jpsm-mobile-card">
                    <div class="jpsm-card-title-row">
                        <h3 style="margin:0;">👥 Serie mensual (relativa por registrados)</h3>
                    </div>
                    <div style="height:220px; position:relative;">
                        <canvas id="jpsmTransferMonthlyRelativeChart"></canvas>
                    </div>
                </div>

                <div class="jpsm-mobile-card">
                    <div class="jpsm-card-title-row">
                        <h3 style="margin:0;">🏁 Lifetime (absoluta y relativa)</h3>
                    </div>
                    <div style="height:220px; position:relative;">
                        <canvas id="jpsmTransferLifetimeAbsoluteChart"></canvas>
                    </div>
                    <div style="height:220px; position:relative; margin-top: 12px;">
                        <canvas id="jpsmTransferLifetimeRelativeChart"></canvas>
                    </div>
                </div>

                <div class="jpsm-mobile-card">
                    <div class="jpsm-card-title-row">
                        <h3 style="margin:0;" id="jpsm-transfer-top-title">🔥 Top carpetas</h3>
                    </div>
                    <div class="jpsm-folder-demand-table-wrap">
                        <table class="jpsm-modern-table jpsm-folder-demand-table">
                            <thead>
                                <tr>
                                    <th>Carpeta</th>
                                    <th>Descargas</th>
                                    <th>Bytes autorizados</th>
                                    <th>Bytes observados</th>
                                    <th>Δ MoM</th>
                                    <th>Δ YoY</th>
                                </tr>
                            </thead>
                            <tbody id="jpsm-transfer-top-folders-body">
                                <?php if (!empty($transfer_top_folders)): ?>
                                    <?php foreach ($transfer_top_folders as $row): ?>
                                        <?php
                                        $yoy_available = !empty($row['yoy_available']);
                                        $yoy_delta = $yoy_available ? intval($row['yoy_delta'] ?? 0) : null;
                                        $mom_delta = isset($row['mom_delta']) ? $row['mom_delta'] : null;
                                        $bytes_authorized_available = !array_key_exists('bytes_authorized_available', $row) || !empty($row['bytes_authorized_available']);
                                        $bytes_observed_available = !array_key_exists('bytes_observed_available', $row) || !empty($row['bytes_observed_available']);
                                        ?>
                                        <tr>
                                            <td><code><?php echo esc_html((string) ($row['folder_path'] ?? '')); ?></code></td>
                                            <td><?php echo number_format(intval($row['downloads'] ?? 0), 0); ?></td>
                                            <td><?php echo $bytes_authorized_available ? esc_html($jpsm_format_bytes_human($row['bytes_authorized'] ?? 0)) : 'N/A'; ?></td>
                                            <td><?php echo $bytes_observed_available ? esc_html($jpsm_format_bytes_human($row['bytes_observed'] ?? 0)) : 'N/A'; ?></td>
                                            <td><?php echo ($mom_delta === null) ? 'N/A' : esc_html((intval($mom_delta) > 0 ? '+' : '') . intval($mom_delta)); ?></td>
                                            <td><?php echo $yoy_available ? esc_html((($yoy_delta > 0) ? '+' : '') . $yoy_delta) : 'N/A'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6">Sin datos aún.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="jpsm-tab-behavior" class="jpsm-tab-content">
                <div class="jpsm-section-header">
                    <h3>🧭 Comportamiento y estacionalidad</h3>
                </div>

                <div class="jpsm-mobile-card" style="margin-bottom: var(--mv-space-lg);">
                    <div class="jpsm-card-title-row">
                        <h3 style="margin:0;">Filtros de comportamiento</h3>
                        <span class="jpsm-folder-demand-meta" id="jpsm-behavior-month-label"><?php echo esc_html($behavior_month_label); ?></span>
                    </div>
                    <div class="jpsm-form-row" style="margin-top:12px;">
                        <div>
                            <label>Mes</label>
                            <input type="month" id="jpsm-behavior-month" class="jpsm-input-lg" value="<?php echo esc_attr($behavior_month); ?>">
                        </div>
                        <div>
                            <label>Tier</label>
                            <select id="jpsm-behavior-tier" class="jpsm-input-lg">
                                <option value="all">Todos</option>
                                <option value="0" <?php selected((string) ($behavior_filters['tier'] ?? 'all'), '0'); ?>>Demo</option>
                                <option value="1" <?php selected((string) ($behavior_filters['tier'] ?? 'all'), '1'); ?>>Básico</option>
                                <option value="2" <?php selected((string) ($behavior_filters['tier'] ?? 'all'), '2'); ?>>VIP Básico</option>
                                <option value="3" <?php selected((string) ($behavior_filters['tier'] ?? 'all'), '3'); ?>>VIP Videos</option>
                                <option value="4" <?php selected((string) ($behavior_filters['tier'] ?? 'all'), '4'); ?>>VIP Pelis</option>
                                <option value="5" <?php selected((string) ($behavior_filters['tier'] ?? 'all'), '5'); ?>>Full</option>
                            </select>
                        </div>
                    </div>
                    <div class="jpsm-form-row">
                        <div>
                            <label>Región</label>
                            <select id="jpsm-behavior-region" class="jpsm-input-lg">
                                <option value="all" <?php selected((string) ($behavior_filters['region'] ?? 'all'), 'all'); ?>>Todas</option>
                                <option value="national" <?php selected((string) ($behavior_filters['region'] ?? 'all'), 'national'); ?>>Nacional</option>
                                <option value="international" <?php selected((string) ($behavior_filters['region'] ?? 'all'), 'international'); ?>>Internacional</option>
                                <option value="unknown" <?php selected((string) ($behavior_filters['region'] ?? 'all'), 'unknown'); ?>>Sin región</option>
                            </select>
                        </div>
                        <div>
                            <label>Dispositivo</label>
                            <select id="jpsm-behavior-device" class="jpsm-input-lg">
                                <option value="all" <?php selected((string) ($behavior_filters['device_class'] ?? 'all'), 'all'); ?>>Todos</option>
                                <option value="desktop" <?php selected((string) ($behavior_filters['device_class'] ?? 'all'), 'desktop'); ?>>Desktop</option>
                                <option value="mobile" <?php selected((string) ($behavior_filters['device_class'] ?? 'all'), 'mobile'); ?>>Mobile</option>
                                <option value="tablet" <?php selected((string) ($behavior_filters['device_class'] ?? 'all'), 'tablet'); ?>>Tablet</option>
                                <option value="unknown" <?php selected((string) ($behavior_filters['device_class'] ?? 'all'), 'unknown'); ?>>Sin clasificar</option>
                            </select>
                        </div>
                    </div>
                    <div class="jpsm-actions-row" style="margin-top:12px;">
                        <button type="button" class="jpsm-btn-outline" id="jpsm-behavior-apply">Aplicar filtros</button>
                        <button type="button" class="jpsm-btn-outline" id="jpsm-behavior-export">Exportar CSV</button>
                    </div>
                    <p class="jpsm-card-subtitle" id="jpsm-behavior-compare-label" style="margin-top:10px;">
                        Comparado contra: <?php echo esc_html($behavior_prev_label); ?> (MoM) y <?php echo esc_html($behavior_yoy_label); ?> (YoY)
                    </p>
                </div>

                <div class="jpsm-kpi-grid">
                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Búsquedas</span>
                        <div class="jpsm-kpi-value" id="jpsm-behavior-search-total"><?php echo number_format(intval($behavior_summary['search_total'] ?? 0), 0); ?></div>
                    </div>
                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Sin resultados</span>
                        <div class="jpsm-kpi-value accent" id="jpsm-behavior-search-zero"><?php echo number_format(intval($behavior_summary['search_zero_results_total'] ?? 0), 0); ?></div>
                    </div>
                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Zero-result rate</span>
                        <div class="jpsm-kpi-value accent" id="jpsm-behavior-search-zero-rate"><?php echo esc_html(number_format(floatval($behavior_summary['search_zero_results_rate'] ?? 0), 1)); ?>%</div>
                    </div>
                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Intentos de descarga</span>
                        <div class="jpsm-kpi-value" id="jpsm-behavior-download-intent"><?php echo number_format(intval($behavior_summary['download_intent_total'] ?? 0), 0); ?></div>
                    </div>
                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Descargas concedidas</span>
                        <div class="jpsm-kpi-value positive" id="jpsm-behavior-download-granted"><?php echo number_format(intval($behavior_summary['download_granted_total'] ?? 0), 0); ?></div>
                    </div>
                    <div class="jpsm-kpi-card">
                        <span class="jpsm-kpi-label">Gap intención vs granted</span>
                        <div class="jpsm-kpi-value" id="jpsm-behavior-download-gap"><?php echo number_format(intval($behavior_summary['download_intent_gap'] ?? 0), 0); ?></div>
                    </div>
                </div>

                <div class="jpsm-mobile-card">
                    <h3 style="margin:0 0 8px;">🔎 Top Keywords (MoM + YoY)</h3>
                    <div class="jpsm-folder-demand-table-wrap">
                        <table class="jpsm-modern-table">
                            <thead>
                                <tr>
                                    <th>Keyword</th>
                                    <th>Mes actual</th>
                                    <th>Δ MoM</th>
                                    <th>Δ YoY</th>
                                </tr>
                            </thead>
                            <tbody id="jpsm-behavior-keywords-body">
                                <?php if (!empty($behavior_keywords)): ?>
                                    <?php foreach ($behavior_keywords as $row): ?>
                                        <tr>
                                            <td><strong><?php echo esc_html((string) ($row['query_norm'] ?? '')); ?></strong></td>
                                            <td><?php echo number_format(intval($row['current_count'] ?? 0), 0); ?></td>
                                            <td><?php echo intval($row['mom_delta'] ?? 0); ?></td>
                                            <td><?php echo intval($row['yoy_delta'] ?? 0); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4">Sin datos aún.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="jpsm-mobile-card">
                    <h3 style="margin:0 0 8px;">🕳️ Keywords sin resultados</h3>
                    <div class="jpsm-folder-demand-table-wrap">
                        <table class="jpsm-modern-table">
                            <thead>
                                <tr>
                                    <th>Keyword</th>
                                    <th>Veces</th>
                                </tr>
                            </thead>
                            <tbody id="jpsm-behavior-zero-body">
                                <?php if (!empty($behavior_zero_keywords)): ?>
                                    <?php foreach ($behavior_zero_keywords as $row): ?>
                                        <tr>
                                            <td><?php echo esc_html((string) ($row['query_norm'] ?? '')); ?></td>
                                            <td><?php echo number_format(intval($row['current_count'] ?? 0), 0); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="2">Sin datos aún.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="jpsm-mobile-card">
                    <h3 style="margin:0 0 8px;">📥 Contenido más descargado</h3>
                    <div class="jpsm-folder-demand-table-wrap">
                        <table class="jpsm-modern-table">
                            <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th>Ruta</th>
                                    <th>Mes actual</th>
                                    <th>Δ MoM</th>
                                    <th>Δ YoY</th>
                                </tr>
                            </thead>
                            <tbody id="jpsm-behavior-downloads-body">
                                <?php if (!empty($behavior_top_downloads)): ?>
                                    <?php foreach ($behavior_top_downloads as $row): ?>
                                        <tr>
                                            <td><?php echo esc_html((string) ($row['object_type'] ?? '')); ?></td>
                                            <td><code><?php echo esc_html((string) ($row['object_path_norm'] ?? '')); ?></code></td>
                                            <td><?php echo number_format(intval($row['current_count'] ?? 0), 0); ?></td>
                                            <td><?php echo intval($row['mom_delta'] ?? 0); ?></td>
                                            <td><?php echo intval($row['yoy_delta'] ?? 0); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5">Sin datos aún.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="jpsm-mobile-card">
                    <h3 style="margin:0 0 8px;">🧩 Matriz de preferencia por segmento</h3>
                    <div class="jpsm-folder-demand-table-wrap">
                        <table class="jpsm-modern-table">
                            <thead>
                                <tr>
                                    <th>Tier</th>
                                    <th>Región</th>
                                    <th>Dispositivo</th>
                                    <th>Búsquedas</th>
                                    <th>Descargas</th>
                                </tr>
                            </thead>
                            <tbody id="jpsm-behavior-segments-body">
                                <?php if (!empty($behavior_segments)): ?>
                                    <?php foreach ($behavior_segments as $row): ?>
                                        <tr>
                                            <td><?php echo intval($row['tier'] ?? 0); ?></td>
                                            <td><?php echo esc_html((string) ($row['region'] ?? 'unknown')); ?></td>
                                            <td><?php echo esc_html((string) ($row['device_class'] ?? 'unknown')); ?></td>
                                            <td><?php echo number_format(intval($row['search_count'] ?? 0), 0); ?></td>
                                            <td><?php echo number_format(intval($row['download_count'] ?? 0), 0); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5">Sin datos aún.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <!-- ========== END DASHBOARD CONTENT ========== -->

        </main>
    </div>
</div>
