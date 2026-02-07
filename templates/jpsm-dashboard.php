<?php

if (!defined('ABSPATH')) {
    exit;
}

// Optional inline fallback styles (only when enqueue failed and head already printed).
echo isset($inline_styles) ? $inline_styles : '';

?>
<div id="jpsm-mobile-app">
    <!-- Dashboard Container -->
    <div class="jpsm-dashboard-container">

        <!-- Desktop Sidebar -->
        <aside class="jpsm-sidebar">
            <div class="jpsm-brand">
                <h2>🚀 JetPack</h2>
                <span class="jpsm-badge">v2.0</span>
            </div>

            <nav class="jpsm-nav-menu">
                <a href="#" class="jpsm-nav-item active" onclick="jpsmOpenTab(event, 'jpsm-tab-new')">
                    <span class="icon">🛒</span> Nueva Venta
                </a>
                <a href="#" class="jpsm-nav-item" onclick="jpsmOpenTab(event, 'jpsm-tab-stats')">
                    <span class="icon">📊</span> Métricas
                </a>
                <a href="#" class="jpsm-nav-item" onclick="jpsmOpenTab(event, 'jpsm-tab-history')">
                    <span class="icon">📜</span> Historial
                </a>
                <div class="jpsm-nav-divider"></div>
                <a href="#" class="jpsm-nav-item" onclick="jpsmLogout()">
                    <span class="icon">🚪</span> Salir
                </a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="jpsm-main-content">

            <div class="jpsm-header">
                <h1>Panel de Gestión, <?php echo esc_html((string) ($operator_label ?? 'Admin')); ?></h1>
                <p class="jpsm-date-display">
                    <?php echo esc_html((string) date_i18n('l, d F Y')); ?>
                </p>
            </div>

            <!-- Top Revenue Cards -->
            <div class="jpsm-revenue-summary jpsm-kpi-grid">
                <div class="jpsm-kpi-card">
                    <span class="jpsm-kpi-label">Ventas Hoy</span>
                    <div class="jpsm-kpi-value text-slate-900">
                        <?php echo intval($sales_today ?? 0); ?>
                    </div>
                    <span class="jpsm-kpi-trend positive" style="color:var(--mv-success);">+100%</span>
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

            <!-- Mobile Tabs -->
            <div class="jpsm-nav-tabs">
                <button class="jpsm-tab-link active" onclick="jpsmOpenTab(event, 'jpsm-tab-new')">Nueva Venta</button>
                <button class="jpsm-tab-link" onclick="jpsmOpenTab(event, 'jpsm-tab-history')">Historial</button>
                <button class="jpsm-tab-link" onclick="jpsmOpenTab(event, 'jpsm-tab-stats')">Métricas</button>
            </div>

            <div id="jpsm-tab-new" class="jpsm-tab-content" style="display:block;">
                <div class="jpsm-mobile-card">
                    <h3>✉️ Registrar Nueva Venta</h3>
                    <form id="jpsm-registration-form">
                        <label>Email del Cliente</label>
                        <div class="jpsm-input-group" style="position:relative;">
                            <input type="email" id="client_email" name="client_email" required placeholder="cliente@ejemplo.com"
                                class="jpsm-input-lg" style="padding-right: 80px;">
                            <button type="button" id="jpsm-paste-email"
                                style="position:absolute; right:5px; top:5px; bottom:5px; background:var(--mv-surface-hover); color:var(--mv-accent); border:1px solid var(--mv-border); border-radius:6px; padding:0 10px; cursor:pointer;">📋
                                Pegar</button>
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

            <div id="jpsm-tab-history" class="jpsm-tab-content">
                <div class="jpsm-mobile-card">
                    <h3>📋 Historial Completo</h3>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; gap:10px;">
                        <button id="jpsm-bulk-delete" style="display:none; font-size:12px; padding:8px 12px;">
                            Borrar Seleccionados (<span id="jpsm-selected-count">0</span>)
                        </button>
                        <button onclick="if(confirm('¿Borrar TODO el historial?')) { jpsmDeleteAllLogs(); }"
                            style="background:transparent; border:1px solid var(--mv-danger); color:var(--mv-danger); padding:8px 12px; border-radius:6px; font-size:12px; margin-left:auto;">🗑️
                            Vaciado Total</button>
                    </div>
                    <!-- Search Bar -->
                    <div class="jpsm-mobile-card" style="margin-bottom: 15px; padding: 10px; background: var(--mv-bg); border:none;">
                        <input type="text" id="jpsm-history-search" placeholder="🔍 Buscar por email o paquete..." class="jpsm-input-lg"
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

            <div id="jpsm-tab-stats" class="jpsm-tab-content">
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
                    <div class="jpsm-mobile-card" style="grid-column: 1 / -1;">
                        <h3>📅 Ventas por Día del Mes (1-31)</h3>
                        <div style="height:200px; position:relative;">
                            <canvas id="jpsmChartDayOfMonth"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <!-- ========== END DASHBOARD CONTENT ========== -->

        </main>
    </div>
</div>

