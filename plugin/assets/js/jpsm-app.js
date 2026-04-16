/**
 * JetPack Store Manager - Frontend Application
 * Handles the main interface interactvity, history rendering, and API communication.
 */
document.addEventListener('DOMContentLoaded', function () {
    // Ensure configuration exists
    if (typeof jpsm_vars === 'undefined') {
        console.error('JPSM Error: Configuration object not found');
        return;
    }
    const jpsmNonces = (jpsm_vars && jpsm_vars.nonces) ? jpsm_vars.nonces : {};
    const nonceFor = (key) => jpsmNonces[key] || jpsm_vars.nonce || '';
    const getTierOptions = () => {
        if (Array.isArray(jpsm_vars.tier_options) && jpsm_vars.tier_options.length) {
            return jpsm_vars.tier_options;
        }
        return [
            { value: 0, label: 'Demo' },
            { value: 1, label: 'Level 1: Básico' },
            { value: 2, label: 'Level 2: VIP + Básico' },
            { value: 3, label: 'Level 3: VIP + Videos' },
            { value: 4, label: 'Level 4: VIP + Pelis' },
            { value: 5, label: 'Level 5: Full' }
        ];
    };
    const renderTierOptions = (selectedTier, options) => {
        var selected = parseInt(selectedTier, 10);
        return options.map(function (opt) {
            var value = parseInt(opt.value, 10);
            var isSelected = selected === value;
            return '<option value="' + value + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(opt.label) + '</option>';
        }).join('');
    };
    const getPaidTierOptions = () => getTierOptions().filter(function (opt) {
        return parseInt(opt.value, 10) > 0;
    });
    const normalizeAllowedTiers = (value) => {
        if (Array.isArray(value)) {
            return value
                .map(function (v) { return parseInt(v, 10); })
                .filter(function (v) { return Number.isInteger(v) && v >= 0; });
        }
        var parsed = parseInt(value, 10);
        if (Number.isInteger(parsed) && parsed >= 0) {
            return [parsed];
        }
        return [];
    };
    const getFolderSelectedTier = (value) => {
        var tiers = normalizeAllowedTiers(value).filter(function (t) { return t > 0; });
        if (!tiers.length) {
            return 1;
        }
        return Math.min.apply(null, tiers);
    };

    // VIP Dropdown Logic
    var pkgSelect = document.getElementById('package_type');
    var vipContainer = document.getElementById('vip-subtype-container');

    function toggleVipOptions() {
        if (pkgSelect.value === 'vip') {
            vipContainer.style.display = 'block';
        } else {
            vipContainer.style.display = 'none';
        }
    }

    if (pkgSelect) {
        pkgSelect.addEventListener('change', toggleVipOptions);
        // Run on init in case browser cached selection
        toggleVipOptions();
    }

    // Paste Email Logic
    var pasteBtn = document.getElementById('jpsm-paste-email');
    if (pasteBtn) {
        pasteBtn.addEventListener('click', function (e) {
            e.preventDefault();
            // Modern API
            if (navigator.clipboard && navigator.clipboard.readText) {
                navigator.clipboard.readText().then(function (text) {
                    if (text && text.includes('@')) {
                        document.getElementById('client_email').value = text;
                    } else {
                        alert('El portapapeles no tiene un correo válido');
                    }
                }).catch(function (err) {
                    alert('Permiso denegado para leer portapapeles');
                });
            } else {
                // Fallback: Try to focus and paste command
                document.getElementById('client_email').focus();
                document.execCommand('paste');
            }
        });
    }

    // ========== DASHBOARD NAV / CUSTOMER DB STATE ==========
    var selectedCustomerEmail = '';

    function normalizeEmail(value) {
        return String(value || '').trim().toLowerCase();
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function toInt(value) {
        var n = parseInt(value, 10);
        return Number.isFinite(n) ? n : 0;
    }

    function formatInteger(value) {
        return toInt(value).toLocaleString('es-MX');
    }

    function formatDelta(value) {
        var n = toInt(value);
        return n > 0 ? ('+' + n) : String(n);
    }

    function formatBytes(bytes) {
        var n = Number(bytes || 0);
        if (!Number.isFinite(n) || n <= 0) return '0 MB';
        var value = n / (1024 * 1024);
        if (value < 0.01) {
            return '<0.01 MB';
        }
        var units = ['MB', 'GB', 'TB'];
        var idx = 0;
        while (value >= 1024 && idx < units.length - 1) {
            value = value / 1024;
            idx++;
        }
        var digits = value >= 100 ? 0 : (value >= 10 ? 1 : 2);
        return value.toLocaleString('es-MX', { maximumFractionDigits: digits, minimumFractionDigits: 0 }) + ' ' + units[idx];
    }

    function readInputValue(id, fallback) {
        var el = document.getElementById(id);
        if (!el || el.value === undefined || el.value === null) {
            return String(fallback || '');
        }
        return String(el.value).trim();
    }

    function behaviorFiltersFromUI() {
        var month = readInputValue('jpsm-behavior-month', '');
        var tier = readInputValue('jpsm-behavior-tier', 'all');
        var region = readInputValue('jpsm-behavior-region', 'all');
        var device = readInputValue('jpsm-behavior-device', 'all');
        return { month: month, tier: tier, region: region, device_class: device };
    }

    function renderBehaviorSummary(report) {
        var summary = report && report.summary ? report.summary : {};
        var monthLabel = (report && report.month_label) ? report.month_label : '—';
        var prevLabel = (report && report.previous_month_label) ? report.previous_month_label : '—';
        var yoyLabel = (report && report.yoy_month_label) ? report.yoy_month_label : '—';

        var monthEl = document.getElementById('jpsm-behavior-month-label');
        if (monthEl) monthEl.textContent = monthLabel;

        var compareEl = document.getElementById('jpsm-behavior-compare-label');
        if (compareEl) {
            compareEl.textContent = 'Comparado contra: ' + prevLabel + ' (MoM) y ' + yoyLabel + ' (YoY)';
        }

        var map = {
            'jpsm-behavior-search-total': formatInteger(summary.search_total || 0),
            'jpsm-behavior-search-zero': formatInteger(summary.search_zero_results_total || 0),
            'jpsm-behavior-search-zero-rate': (Number(summary.search_zero_results_rate || 0).toFixed(1) + '%'),
            'jpsm-behavior-download-intent': formatInteger(summary.download_intent_total || 0),
            'jpsm-behavior-download-granted': formatInteger(summary.download_granted_total || 0),
            'jpsm-behavior-download-gap': formatInteger(summary.download_intent_gap || 0)
        };

        Object.keys(map).forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.textContent = map[id];
        });
    }

    function renderBehaviorKeywords(rows) {
        var tbody = document.getElementById('jpsm-behavior-keywords-body');
        if (!tbody) return;

        if (!Array.isArray(rows) || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4">Sin datos aún.</td></tr>';
            return;
        }

        var html = '';
        rows.forEach(function (row) {
            html += '<tr>' +
                '<td><strong>' + escapeHtml(row.query_norm || '') + '</strong></td>' +
                '<td>' + formatInteger(row.current_count || 0) + '</td>' +
                '<td>' + formatDelta(row.mom_delta || 0) + '</td>' +
                '<td>' + formatDelta(row.yoy_delta || 0) + '</td>' +
                '</tr>';
        });
        tbody.innerHTML = html;
    }

    function renderBehaviorZeroKeywords(rows) {
        var tbody = document.getElementById('jpsm-behavior-zero-body');
        if (!tbody) return;

        if (!Array.isArray(rows) || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="2">Sin datos aún.</td></tr>';
            return;
        }

        var html = '';
        rows.forEach(function (row) {
            html += '<tr>' +
                '<td>' + escapeHtml(row.query_norm || '') + '</td>' +
                '<td>' + formatInteger(row.current_count || 0) + '</td>' +
                '</tr>';
        });
        tbody.innerHTML = html;
    }

    function renderBehaviorDownloads(rows) {
        var tbody = document.getElementById('jpsm-behavior-downloads-body');
        if (!tbody) return;

        if (!Array.isArray(rows) || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5">Sin datos aún.</td></tr>';
            return;
        }

        var html = '';
        rows.forEach(function (row) {
            html += '<tr>' +
                '<td>' + escapeHtml(row.object_type || '') + '</td>' +
                '<td><code>' + escapeHtml(row.object_path_norm || '') + '</code></td>' +
                '<td>' + formatInteger(row.current_count || 0) + '</td>' +
                '<td>' + formatDelta(row.mom_delta || 0) + '</td>' +
                '<td>' + formatDelta(row.yoy_delta || 0) + '</td>' +
                '</tr>';
        });
        tbody.innerHTML = html;
    }

    function renderBehaviorSegments(rows) {
        var tbody = document.getElementById('jpsm-behavior-segments-body');
        if (!tbody) return;

        if (!Array.isArray(rows) || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5">Sin datos aún.</td></tr>';
            return;
        }

        var html = '';
        rows.forEach(function (row) {
            html += '<tr>' +
                '<td>' + formatInteger(row.tier || 0) + '</td>' +
                '<td>' + escapeHtml(row.region || 'unknown') + '</td>' +
                '<td>' + escapeHtml(row.device_class || 'unknown') + '</td>' +
                '<td>' + formatInteger(row.search_count || 0) + '</td>' +
                '<td>' + formatInteger(row.download_count || 0) + '</td>' +
                '</tr>';
        });
        tbody.innerHTML = html;
    }

    function renderBehaviorReport(report) {
        if (!report || typeof report !== 'object') return;
        renderBehaviorSummary(report);
        renderBehaviorKeywords(report.top_keywords || []);
        renderBehaviorZeroKeywords(report.top_zero_keywords || []);
        renderBehaviorDownloads(report.top_downloads || []);
        renderBehaviorSegments(report.segment_matrix || []);
    }

    function fetchBehaviorReport(filters) {
        var formData = new FormData();
        formData.append('action', 'jpsm_get_behavior_report');
        formData.append('api_version', '2');
        formData.append('nonce', nonceFor('access'));
        formData.append('month', filters.month || '');
        formData.append('tier', filters.tier || 'all');
        formData.append('region', filters.region || 'all');
        formData.append('device_class', filters.device_class || 'all');

        var applyBtn = document.getElementById('jpsm-behavior-apply');
        if (applyBtn) {
            applyBtn.disabled = true;
            applyBtn.textContent = 'Cargando...';
        }

        fetch(jpsm_vars.ajax_url, { method: 'POST', body: formData })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                var report = null;
                if (json && json.success && json.data && json.data.ok && json.data.data) {
                    report = json.data.data;
                } else if (json && json.success && json.data && typeof json.data === 'object') {
                    report = json.data;
                }

                if (!report) {
                    var errMsg = (json && json.data && json.data.message) ? json.data.message : 'No se pudo cargar el reporte.';
                    alert(errMsg);
                    return;
                }
                renderBehaviorReport(report);
            })
            .catch(function () {
                alert('Error de red al cargar reporte de comportamiento.');
            })
            .finally(function () {
                if (applyBtn) {
                    applyBtn.disabled = false;
                    applyBtn.textContent = 'Aplicar filtros';
                }
            });
    }

    function exportBehaviorCsv() {
        var filters = behaviorFiltersFromUI();
        var params = new URLSearchParams();
        params.set('action', 'jpsm_export_behavior_csv');
        params.set('nonce', nonceFor('access'));
        params.set('month', filters.month || '');
        params.set('tier', filters.tier || 'all');
        params.set('region', filters.region || 'all');
        params.set('device_class', filters.device_class || 'all');
        window.open(jpsm_vars.ajax_url + '?' + params.toString(), '_blank');
    }

    var transferCharts = {};
    var lastTransferReport = null;
    var transferKpiPreviousSnapshot = null;
    var transferKpiPreviousWindow = '';
    var transferTooltipPinnedKey = '';
    var transferTooltipHoverKey = '';
    var transferTooltipAnchorEl = null;

    var transferKpiMetaMap = {
        observed_window: {
            valueId: 'jpsm-transfer-observed-month',
            display_name: 'Tráfico real medido (período)',
            subtitle: 'Datos exactos medidos en esta ventana.',
            definition: 'Suma del tráfico realmente observado en el período activo.',
            how_to_read: 'Más alto = más consumo real del contenido.',
            action_hint: 'Úsalo para priorizar carpetas con demanda real.',
            status_rule: { type: 'trend' }
        },
        authorized_window: {
            valueId: 'jpsm-transfer-authorized-month',
            display_name: 'Tráfico permitido (período)',
            subtitle: 'Estimación de lo que el sistema habilitó.',
            definition: 'Suma del tráfico autorizado por backend en el período.',
            how_to_read: 'Si supera al observado, hay brecha de medición o flujo directo.',
            action_hint: 'Compara con observado para detectar fricción o cobertura baja.',
            status_rule: { type: 'info' }
        },
        observed_lifetime: {
            valueId: 'jpsm-transfer-observed-lifetime',
            display_name: 'Tráfico real medido (acumulado)',
            subtitle: 'Histórico exacto acumulado.',
            definition: 'Total histórico de tráfico observado desde el inicio.',
            how_to_read: 'Sirve para dimensionar crecimiento estructural.',
            action_hint: 'Úsalo para planeación de capacidad y contenido anual.',
            status_rule: { type: 'trend' }
        },
        authorized_lifetime: {
            valueId: 'jpsm-transfer-authorized-lifetime',
            display_name: 'Tráfico permitido (acumulado)',
            subtitle: 'Histórico habilitado por el sistema.',
            definition: 'Total histórico de tráfico autorizado por backend.',
            how_to_read: 'Ayuda a comparar intención permitida vs tráfico observado.',
            action_hint: 'Si se separa mucho del observado, revisar cobertura y ruta directa.',
            status_rule: { type: 'info' }
        },
        bytes_per_user_window: {
            valueId: 'jpsm-transfer-per-user-month',
            display_name: 'MB por cliente (período)',
            subtitle: 'Consumo promedio por cliente.',
            definition: 'Tráfico observado del período dividido por usuarios registrados.',
            how_to_read: 'Permite comparar demanda sin sesgo por crecimiento de base.',
            action_hint: 'Si sube, los clientes activos consumen más contenido.',
            status_rule: { type: 'trend' }
        },
        bytes_per_user_lifetime: {
            valueId: 'jpsm-transfer-per-user-lifetime',
            display_name: 'MB por cliente (acumulado)',
            subtitle: 'Consumo histórico promedio por cliente.',
            definition: 'Tráfico observado histórico dividido por registrados históricos.',
            how_to_read: 'Mide intensidad de consumo acumulada por cliente.',
            action_hint: 'Útil para estrategia de catálogo y pricing de largo plazo.',
            status_rule: { type: 'trend' }
        },
        coverage_precision: {
            valueId: 'jpsm-transfer-coverage-ratio',
            display_name: '% del tráfico medido con precisión',
            subtitle: 'Calidad de medición para decisiones de tráfico.',
            definition: 'Porcentaje de eventos de transferencia con observación exacta.',
            how_to_read: 'Mientras más alto, más confiables son los KPI de tráfico real.',
            action_hint: 'Si está bajo, prioriza mejorar instrumentación antes de decisiones finas.',
            status_rule: { type: 'coverage_threshold' }
        },
        downloads_window: {
            valueId: 'jpsm-transfer-downloads-window',
            display_name: 'Descargas del período',
            subtitle: 'Demanda total en la ventana.',
            definition: 'Cantidad total de descargas registradas en la ventana.',
            how_to_read: 'Mide tracción general de contenido.',
            action_hint: 'Combínalo con top carpetas para priorizar producción.',
            status_rule: { type: 'trend' }
        },
        unique_folders_window: {
            valueId: 'jpsm-transfer-unique-folders-window',
            display_name: 'Variedad de interés (carpetas únicas)',
            subtitle: 'Diversidad de contenido descargado.',
            definition: 'Número de carpetas distintas con descargas en la ventana.',
            how_to_read: 'Más alto = demanda más distribuida; más bajo = foco en pocos temas.',
            action_hint: 'Úsalo para decidir especialización vs diversificación.',
            status_rule: { type: 'trend' }
        },
        top1_dependence: {
            valueId: 'jpsm-transfer-top1-share',
            display_name: 'Dependencia de la carpeta #1',
            subtitle: 'Cuánto pesa la carpeta líder en la demanda.',
            definition: 'Porcentaje de descargas concentradas en la carpeta más demandada.',
            how_to_read: 'Muy alto puede indicar riesgo de dependencia de un solo tema.',
            action_hint: 'Si sube mucho, crea ofertas alternativas para equilibrar demanda.',
            status_rule: { type: 'top1_threshold' }
        },
        top3_dependence: {
            valueId: 'jpsm-transfer-top3-share',
            display_name: 'Dependencia de las 3 carpetas top',
            subtitle: 'Concentración global en el top 3.',
            definition: 'Porcentaje de descargas concentradas en las 3 carpetas líderes.',
            how_to_read: 'Mide concentración estructural de interés.',
            action_hint: 'Si está alta, ampliar catálogo en categorías secundarias.',
            status_rule: { type: 'top3_threshold' }
        },
        rising_folders: {
            valueId: 'jpsm-transfer-rising-folders',
            display_name: 'Carpetas con interés en crecimiento',
            subtitle: 'Cantidad de carpetas que suben vs período previo.',
            definition: 'Número de carpetas con variación positiva en descargas.',
            how_to_read: 'Detecta tendencias emergentes de consumo.',
            action_hint: 'Acelera contenidos de estas carpetas para capturar momentum.',
            status_rule: { type: 'trend' }
        },
        new_folders: {
            valueId: 'jpsm-transfer-new-folders',
            display_name: 'Carpetas que entran por primera vez',
            subtitle: 'Nuevos focos de demanda detectados.',
            definition: 'Carpetas sin demanda previa que aparecen en la ventana.',
            how_to_read: 'Indica exploración de nuevos intereses.',
            action_hint: 'Evalúa si conviene lanzar líneas nuevas relacionadas.',
            status_rule: { type: 'trend' }
        }
    };

    function transferTrendStatus(curr, prev) {
        if (prev === null || prev === undefined) {
            return { className: 'status-info', text: 'Sin referencia' };
        }
        var c = Number(curr || 0);
        var p = Number(prev || 0);
        if (!Number.isFinite(c) || !Number.isFinite(p)) {
            return { className: 'status-info', text: 'Sin referencia' };
        }
        if (Math.abs(c - p) < 0.0001) {
            return { className: 'status-flat', text: 'Estable' };
        }
        if (c > p) {
            return { className: 'status-up', text: 'Sube' };
        }
        return { className: 'status-down', text: 'Baja' };
    }

    function transferThresholdStatus(ruleType, value) {
        var v = Number(value || 0);
        if (!Number.isFinite(v)) {
            return { className: 'status-info', text: 'Sin dato' };
        }
        if (ruleType === 'coverage_threshold') {
            if (v < 20) return { className: 'status-bad', text: 'Baja' };
            if (v <= 60) return { className: 'status-warn', text: 'Media' };
            return { className: 'status-good', text: 'Alta' };
        }
        if (ruleType === 'top1_threshold') {
            if (v <= 15) return { className: 'status-good', text: 'Sana' };
            if (v <= 30) return { className: 'status-warn', text: 'Vigilancia' };
            return { className: 'status-bad', text: 'Alta' };
        }
        if (ruleType === 'top3_threshold') {
            if (v <= 35) return { className: 'status-good', text: 'Sana' };
            if (v <= 55) return { className: 'status-warn', text: 'Vigilancia' };
            return { className: 'status-bad', text: 'Alta' };
        }
        return { className: 'status-info', text: 'Referencial' };
    }

    function transferKpiNumericSnapshot(report) {
        var kpis = report && report.kpis ? report.kpis : {};
        var demand = report && report.demand_kpis ? report.demand_kpis : {};
        var coverage = report && report.coverage ? report.coverage : {};
        return {
            observed_window: Number(kpis.transfer_observed_bytes_month || 0),
            authorized_window: Number(kpis.transfer_authorized_bytes_month || 0),
            observed_lifetime: Number(kpis.transfer_observed_bytes_lifetime || 0),
            authorized_lifetime: Number(kpis.transfer_authorized_bytes_lifetime || 0),
            bytes_per_user_window: Number(kpis.bytes_per_registered_month || 0),
            bytes_per_user_lifetime: Number(kpis.bytes_per_registered_lifetime || 0),
            coverage_precision: Number(coverage.coverage_event_ratio || 0),
            downloads_window: Number(demand.downloads_total_window || 0),
            unique_folders_window: Number(demand.unique_folders_window || 0),
            top1_dependence: Number(demand.top1_share_percent || 0),
            top3_dependence: Number(demand.top3_share_percent || 0),
            rising_folders: Number(demand.rising_folders_count || 0),
            new_folders: Number(demand.new_folders_count || 0)
        };
    }

    function formatTransferPerUser(bytesValue, usersValue, noDataReason) {
        var users = Number(usersValue || 0);
        if (!Number.isFinite(users) || users <= 0) {
            return noDataReason || 'N/A';
        }
        return formatBytes(bytesValue || 0);
    }

    function transferNoDataReason(report, key) {
        var quality = report && report.top_folders_quality ? report.top_folders_quality : {};
        var source = (report && report.top_folders_source) ? String(report.top_folders_source) : 'behavior_primary';
        if (source === 'legacy_fallback_global') {
            return 'N/A (modo aproximado global)';
        }
        if (key === 'coverage_precision') {
            return 'N/A (sin eventos medidos)';
        }
        if (!quality || quality.reason === 'none') {
            return 'N/A';
        }
        if (quality.reason === 'primary_incomplete') {
            return 'N/A (medición parcial)';
        }
        if (quality.reason === 'primary_empty') {
            return 'N/A (sin datos en la ventana)';
        }
        return 'N/A';
    }

    function transferTooltipHtml(meta) {
        if (!meta) return '';
        return '' +
            '<div class="jpsm-kpi-tooltip-title">' + escapeHtml(meta.display_name || '') + '</div>' +
            '<div class="jpsm-kpi-tooltip-line"><strong>Qué mide:</strong> ' + escapeHtml(meta.definition || '') + '</div>' +
            '<div class="jpsm-kpi-tooltip-line"><strong>Cómo leerlo:</strong> ' + escapeHtml(meta.how_to_read || '') + '</div>' +
            '<div class="jpsm-kpi-tooltip-line"><strong>Qué hacer:</strong> ' + escapeHtml(meta.action_hint || '') + '</div>';
    }

    function setTransferHelpButtonExpanded(kpiKey, expanded) {
        if (!kpiKey) return;
        document.querySelectorAll('.jpsm-kpi-help-btn[data-kpi-help-for="' + kpiKey + '"]').forEach(function (btn) {
            btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
    }

    function positionTransferTooltip(anchorEl) {
        var tooltip = document.getElementById('jpsm-transfer-kpi-tooltip');
        if (!tooltip || !anchorEl) return;
        var rect = anchorEl.getBoundingClientRect();
        var tooltipRect = tooltip.getBoundingClientRect();
        var left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
        left = Math.max(8, Math.min(left, window.innerWidth - tooltipRect.width - 8));
        var top = rect.bottom + 10;
        if (top + tooltipRect.height > window.innerHeight - 8) {
            top = rect.top - tooltipRect.height - 10;
        }
        tooltip.style.left = Math.round(left) + 'px';
        tooltip.style.top = Math.round(Math.max(8, top)) + 'px';
    }

    function showTransferTooltip(kpiKey, anchorEl, pinned) {
        var tooltip = document.getElementById('jpsm-transfer-kpi-tooltip');
        var meta = transferKpiMetaMap[kpiKey];
        if (!tooltip || !meta || !anchorEl) return;
        if (transferTooltipPinnedKey && transferTooltipPinnedKey !== kpiKey && !pinned) {
            return;
        }
        tooltip.innerHTML = transferTooltipHtml(meta);
        tooltip.classList.add('active');
        tooltip.setAttribute('aria-hidden', 'false');
        positionTransferTooltip(anchorEl);
        transferTooltipAnchorEl = anchorEl;
        if (pinned) {
            if (transferTooltipPinnedKey && transferTooltipPinnedKey !== kpiKey) {
                setTransferHelpButtonExpanded(transferTooltipPinnedKey, false);
            }
            transferTooltipPinnedKey = kpiKey;
            setTransferHelpButtonExpanded(kpiKey, true);
        } else {
            transferTooltipHoverKey = kpiKey;
            if (!transferTooltipPinnedKey) {
                setTransferHelpButtonExpanded(kpiKey, true);
            }
        }
    }

    function hideTransferTooltip(force) {
        var tooltip = document.getElementById('jpsm-transfer-kpi-tooltip');
        if (!tooltip) return;
        if (!force && transferTooltipPinnedKey) {
            return;
        }
        if (transferTooltipPinnedKey) {
            setTransferHelpButtonExpanded(transferTooltipPinnedKey, false);
        }
        if (transferTooltipHoverKey) {
            setTransferHelpButtonExpanded(transferTooltipHoverKey, false);
        }
        transferTooltipPinnedKey = '';
        transferTooltipHoverKey = '';
        transferTooltipAnchorEl = null;
        tooltip.classList.remove('active');
        tooltip.setAttribute('aria-hidden', 'true');
    }

    function renderTransferGlossary() {
        var body = document.getElementById('jpsm-transfer-kpi-glossary-body');
        if (!body) return;
        var keys = Object.keys(transferKpiMetaMap);
        var html = '';
        keys.forEach(function (key) {
            var meta = transferKpiMetaMap[key];
            html += '' +
                '<div class="jpsm-kpi-glossary-item">' +
                '<div class="jpsm-kpi-glossary-title">' + escapeHtml(meta.display_name || '') + '</div>' +
                '<div class="jpsm-kpi-glossary-text">' + escapeHtml(meta.definition || '') + '</div>' +
                '<div class="jpsm-kpi-glossary-text"><strong>Lectura:</strong> ' + escapeHtml(meta.how_to_read || '') + '</div>' +
                '<div class="jpsm-kpi-glossary-text"><strong>Acción:</strong> ' + escapeHtml(meta.action_hint || '') + '</div>' +
                '</div>';
        });
        body.innerHTML = html;
    }

    function setTransferGlossaryOpen(open) {
        var panel = document.getElementById('jpsm-transfer-kpi-glossary');
        var overlay = document.getElementById('jpsm-transfer-glossary-overlay');
        if (!panel || !overlay) return;
        panel.classList.toggle('is-open', !!open);
        overlay.classList.toggle('active', !!open);
        overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
    }

    function initTransferKpiClarityUI() {
        renderTransferGlossary();

        document.querySelectorAll('.jpsm-kpi-help-btn').forEach(function (btn) {
            var kpiKey = (btn.getAttribute('data-kpi-help-for') || '').trim();
            if (!kpiKey) return;
            btn.setAttribute('aria-controls', 'jpsm-transfer-kpi-tooltip');
            btn.setAttribute('aria-expanded', 'false');

            btn.addEventListener('mouseenter', function () {
                showTransferTooltip(kpiKey, btn, false);
            });

            btn.addEventListener('mouseleave', function () {
                hideTransferTooltip(false);
            });

            btn.addEventListener('focus', function () {
                showTransferTooltip(kpiKey, btn, false);
            });

            btn.addEventListener('blur', function () {
                hideTransferTooltip(false);
            });

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (transferTooltipPinnedKey === kpiKey) {
                    hideTransferTooltip(true);
                    return;
                }
                showTransferTooltip(kpiKey, btn, true);
            });
        });

        var glossaryOpenBtn = document.getElementById('jpsm-transfer-glossary-open');
        if (glossaryOpenBtn) {
            glossaryOpenBtn.addEventListener('click', function () {
                setTransferGlossaryOpen(true);
            });
        }

        var glossaryCloseBtn = document.getElementById('jpsm-transfer-glossary-close');
        if (glossaryCloseBtn) {
            glossaryCloseBtn.addEventListener('click', function () {
                setTransferGlossaryOpen(false);
            });
        }

        var glossaryOverlay = document.getElementById('jpsm-transfer-glossary-overlay');
        if (glossaryOverlay) {
            glossaryOverlay.addEventListener('click', function () {
                setTransferGlossaryOpen(false);
            });
        }

        document.addEventListener('click', function (e) {
            var isHelpBtn = !!e.target.closest('.jpsm-kpi-help-btn');
            var isTooltip = !!e.target.closest('#jpsm-transfer-kpi-tooltip');
            if (!isHelpBtn && !isTooltip) {
                hideTransferTooltip(true);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                hideTransferTooltip(true);
                setTransferGlossaryOpen(false);
            }
        });

        window.addEventListener('resize', function () {
            if (transferTooltipAnchorEl && (transferTooltipPinnedKey || transferTooltipHoverKey)) {
                positionTransferTooltip(transferTooltipAnchorEl);
            }
        });

        document.addEventListener('scroll', function () {
            if (transferTooltipAnchorEl && (transferTooltipPinnedKey || transferTooltipHoverKey)) {
                positionTransferTooltip(transferTooltipAnchorEl);
            }
        }, true);
    }

    function transferFiltersFromUI() {
        var month = readInputValue('jpsm-transfer-month', '');
        var windowKey = readInputValue('jpsm-transfer-window', 'month');
        var tier = readInputValue('jpsm-transfer-tier', 'all');
        var region = readInputValue('jpsm-transfer-region', 'all');
        var device = readInputValue('jpsm-transfer-device', 'all');
        return { month: month, window: windowKey, tier: tier, region: region, device_class: device };
    }

    function setTransferWindow(windowKey) {
        var safeWindow = ['month', 'prev_month', 'rolling_90d', 'lifetime'].includes(windowKey) ? windowKey : 'month';
        var hidden = document.getElementById('jpsm-transfer-window');
        if (hidden) {
            hidden.value = safeWindow;
        }
        document.querySelectorAll('.jpsm-transfer-window-shortcut').forEach(function (btn) {
            btn.classList.toggle('active', (btn.getAttribute('data-window') || '') === safeWindow);
        });
    }

    function transferTopTitleByWindow(windowKey) {
        if (windowKey === 'prev_month') return '🔥 Top carpetas del mes anterior';
        if (windowKey === 'rolling_90d') return '🔥 Top carpetas últimos 90 días';
        if (windowKey === 'lifetime') return '🔥 Top carpetas lifetime';
        return '🔥 Top carpetas del mes';
    }

    function transferDeltaDisplay(value, available) {
        if (available === false || value === null || value === undefined) {
            return 'N/A';
        }
        return formatDelta(value);
    }

    function renderTransferSummary(report) {
        var kpis = report && report.kpis ? report.kpis : {};
        var demand = report && report.demand_kpis ? report.demand_kpis : {};
        var coverage = report && report.coverage ? report.coverage : {};
        var quality = report && report.top_folders_quality ? report.top_folders_quality : {};
        var windowKey = (report && report.window) ? report.window : 'month';
        var supportsDeltas = (windowKey === 'month' || windowKey === 'prev_month');
        var monthLabel = (report && report.month_label) ? report.month_label : '—';
        var prevLabel = (report && report.previous_month_label) ? report.previous_month_label : '—';
        var yoyLabel = (report && report.yoy_month_label) ? report.yoy_month_label : '—';

        setTransferWindow(windowKey);

        var monthEl = document.getElementById('jpsm-transfer-month-label');
        if (monthEl) monthEl.textContent = monthLabel;

        var compareEl = document.getElementById('jpsm-transfer-compare-label');
        if (compareEl) {
            compareEl.textContent = supportsDeltas
                ? ('Comparado contra: ' + prevLabel + ' (MoM) y ' + yoyLabel + ' (YoY)')
                : 'Comparativas MoM/YoY: N/A para esta ventana';
        }

        var qualityEl = document.getElementById('jpsm-transfer-quality-badge');
        if (qualityEl) {
            var approx = !!quality.is_approx_global;
            qualityEl.textContent = approx ? 'Aproximado global (fallback legacy)' : 'Exacto (segmentado)';
            qualityEl.classList.toggle('accent', approx);
            qualityEl.classList.toggle('positive', !approx);
            qualityEl.setAttribute('data-source', (report && report.top_folders_source) ? report.top_folders_source : 'behavior_primary');
        }

        var topTitleEl = document.getElementById('jpsm-transfer-top-title');
        if (topTitleEl) {
            topTitleEl.textContent = transferTopTitleByWindow(windowKey);
        }

        Object.keys(transferKpiMetaMap).forEach(function (kpiKey) {
            var meta = transferKpiMetaMap[kpiKey];
            var labelEl = document.querySelector('[data-kpi-label="' + kpiKey + '"]');
            var subtitleEl = document.querySelector('[data-kpi-subtitle="' + kpiKey + '"]');
            if (labelEl && meta.display_name) labelEl.textContent = meta.display_name;
            if (subtitleEl && meta.subtitle) subtitleEl.textContent = meta.subtitle;
        });

        var noObservedData = Number(coverage.total_events || 0) <= 0 && Number(kpis.transfer_observed_bytes_month || 0) <= 0;
        var noAuthorizedData = Number(kpis.transfer_authorized_bytes_month || 0) <= 0 && Number(kpis.transfer_observed_bytes_month || 0) <= 0;
        var noObservedLife = Number(coverage.total_events || 0) <= 0 && Number(kpis.transfer_observed_bytes_lifetime || 0) <= 0;
        var noAuthorizedLife = Number(kpis.transfer_authorized_bytes_lifetime || 0) <= 0 && Number(kpis.transfer_observed_bytes_lifetime || 0) <= 0;

        var monthlyOnlyReason = supportsDeltas ? 'N/A (sin histórico suficiente)' : 'N/A (solo en ventanas mensuales)';
        var map = {
            'jpsm-transfer-observed-month': noObservedData ? transferNoDataReason(report, 'observed_window') : formatBytes(kpis.transfer_observed_bytes_month || 0),
            'jpsm-transfer-authorized-month': noAuthorizedData ? transferNoDataReason(report, 'authorized_window') : formatBytes(kpis.transfer_authorized_bytes_month || 0),
            'jpsm-transfer-observed-lifetime': noObservedLife ? transferNoDataReason(report, 'observed_lifetime') : formatBytes(kpis.transfer_observed_bytes_lifetime || 0),
            'jpsm-transfer-authorized-lifetime': noAuthorizedLife ? transferNoDataReason(report, 'authorized_lifetime') : formatBytes(kpis.transfer_authorized_bytes_lifetime || 0),
            'jpsm-transfer-per-user-month': formatTransferPerUser(kpis.bytes_per_registered_month || 0, kpis.registered_users_global_month_end || 0, 'N/A (sin base de registrados)'),
            'jpsm-transfer-per-user-lifetime': formatTransferPerUser(kpis.bytes_per_registered_lifetime || 0, kpis.registered_users_global_actual || 0, 'N/A (sin base de registrados)'),
            'jpsm-transfer-coverage-ratio': (Number(coverage.total_events || 0) <= 0) ? transferNoDataReason(report, 'coverage_precision') : (Number(coverage.coverage_event_ratio || 0).toFixed(1) + '%'),
            'jpsm-transfer-downloads-window': formatInteger(demand.downloads_total_window || 0),
            'jpsm-transfer-unique-folders-window': formatInteger(demand.unique_folders_window || 0),
            'jpsm-transfer-top1-share': (Number(demand.top1_share_percent || 0).toFixed(1) + '%'),
            'jpsm-transfer-top3-share': (Number(demand.top3_share_percent || 0).toFixed(1) + '%'),
            'jpsm-transfer-rising-folders': (demand.rising_folders_count === null || demand.rising_folders_count === undefined) ? monthlyOnlyReason : formatInteger(demand.rising_folders_count),
            'jpsm-transfer-new-folders': (demand.new_folders_count === null || demand.new_folders_count === undefined) ? monthlyOnlyReason : formatInteger(demand.new_folders_count)
        };

        Object.keys(map).forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.textContent = map[id];
        });

        var currentSnapshot = transferKpiNumericSnapshot(report);
        Object.keys(transferKpiMetaMap).forEach(function (kpiKey) {
            var meta = transferKpiMetaMap[kpiKey];
            var statusEl = document.querySelector('[data-kpi-status="' + kpiKey + '"]');
            if (!statusEl) return;

            var status = { className: 'status-info', text: 'Referencial' };
            var ruleType = meta && meta.status_rule ? meta.status_rule.type : 'info';
            if (ruleType === 'trend') {
                var previousValue = null;
                if (transferKpiPreviousSnapshot && transferKpiPreviousWindow === windowKey) {
                    previousValue = transferKpiPreviousSnapshot[kpiKey];
                }
                status = transferTrendStatus(currentSnapshot[kpiKey], previousValue);
            } else if (ruleType === 'coverage_threshold' || ruleType === 'top1_threshold' || ruleType === 'top3_threshold') {
                status = transferThresholdStatus(ruleType, currentSnapshot[kpiKey]);
            }

            statusEl.className = 'jpsm-kpi-status-chip ' + status.className;
            statusEl.textContent = status.text;
        });

        transferKpiPreviousSnapshot = currentSnapshot;
        transferKpiPreviousWindow = windowKey;
    }

    function renderTransferTopFolders(rows) {
        var tbody = document.getElementById('jpsm-transfer-top-folders-body');
        if (!tbody) return;

        if (!Array.isArray(rows) || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6">Sin datos aún.</td></tr>';
            return;
        }

        var html = '';
        rows.forEach(function (row) {
            var hasAuthorized = (row.bytes_authorized_available !== false);
            var hasObserved = (row.bytes_observed_available !== false);
            html += '<tr>' +
                '<td><code>' + escapeHtml(row.folder_path || '') + '</code></td>' +
                '<td>' + formatInteger(row.downloads || 0) + '</td>' +
                '<td>' + (hasAuthorized ? formatBytes(row.bytes_authorized || 0) : 'N/A') + '</td>' +
                '<td>' + (hasObserved ? formatBytes(row.bytes_observed || 0) : 'N/A') + '</td>' +
                '<td>' + transferDeltaDisplay(row.mom_delta, row.mom_delta !== null && row.mom_delta !== undefined) + '</td>' +
                '<td>' + transferDeltaDisplay(row.yoy_delta, !!row.yoy_available) + '</td>' +
                '</tr>';
        });
        tbody.innerHTML = html;
    }

    function destroyTransferChart(key) {
        if (transferCharts[key] && typeof transferCharts[key].destroy === 'function') {
            transferCharts[key].destroy();
        }
        transferCharts[key] = null;
    }

    function isTransferTabVisible() {
        var tab = document.getElementById('jpsm-tab-folder-downloads');
        return !!(tab && tab.style.display !== 'none');
    }

    function scheduleTransferChartsRender(report) {
        if (!report || typeof report !== 'object') return;
        if (!isTransferTabVisible()) return;
        var runner = function () {
            try {
                renderTransferCharts(report);
            } catch (err) {
                console.error('JPSM transfer charts error', err);
            }
        };
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(runner);
            });
        } else {
            window.setTimeout(runner, 80);
        }
    }

    function renderTransferCharts(report) {
        if (typeof Chart === 'undefined' || !report || typeof report !== 'object') return;

        var daily = Array.isArray(report.series_daily_90d) ? report.series_daily_90d : [];
        var monthlyAbs = Array.isArray(report.series_monthly_absolute) ? report.series_monthly_absolute : [];
        var monthlyRel = Array.isArray(report.series_monthly_relative) ? report.series_monthly_relative : [];
        var lifeAbs = Array.isArray(report.series_lifetime_absolute) ? report.series_lifetime_absolute : [];
        var lifeRel = Array.isArray(report.series_lifetime_relative) ? report.series_lifetime_relative : [];

        destroyTransferChart('daily');
        var ctxDaily = document.getElementById('jpsmTransferDailyChart');
        if (ctxDaily) {
            transferCharts.daily = new Chart(ctxDaily, {
                type: 'line',
                data: {
                    labels: daily.map(function (r) { return String(r.day_date || '').slice(5); }),
                    datasets: [{
                        label: 'Bytes observados',
                        data: daily.map(function (r) { return Number(r.bytes_observed || 0); }),
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37,99,235,0.15)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 0
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        destroyTransferChart('monthlyAbs');
        var ctxMonthlyAbs = document.getElementById('jpsmTransferMonthlyAbsoluteChart');
        if (ctxMonthlyAbs) {
            transferCharts.monthlyAbs = new Chart(ctxMonthlyAbs, {
                type: 'bar',
                data: {
                    labels: monthlyAbs.map(function (r) { return r.month || ''; }),
                    datasets: [
                        {
                            label: 'Autorizados',
                            data: monthlyAbs.map(function (r) { return Number(r.bytes_authorized || 0); }),
                            backgroundColor: '#f59e0b'
                        },
                        {
                            label: 'Observados',
                            data: monthlyAbs.map(function (r) { return Number(r.bytes_observed || 0); }),
                            backgroundColor: '#16a34a'
                        }
                    ]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        destroyTransferChart('monthlyRel');
        var ctxMonthlyRel = document.getElementById('jpsmTransferMonthlyRelativeChart');
        if (ctxMonthlyRel) {
            transferCharts.monthlyRel = new Chart(ctxMonthlyRel, {
                type: 'line',
                data: {
                    labels: monthlyRel.map(function (r) { return r.month || ''; }),
                    datasets: [{
                        label: 'Bytes observados por registrado',
                        data: monthlyRel.map(function (r) { return Number(r.bytes_per_registered_observed || 0); }),
                        borderColor: '#dc2626',
                        backgroundColor: 'rgba(220,38,38,0.12)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 2
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        destroyTransferChart('lifetimeAbs');
        var ctxLifetimeAbs = document.getElementById('jpsmTransferLifetimeAbsoluteChart');
        if (ctxLifetimeAbs) {
            transferCharts.lifetimeAbs = new Chart(ctxLifetimeAbs, {
                type: 'line',
                data: {
                    labels: lifeAbs.map(function (r) { return r.month || ''; }),
                    datasets: [
                        {
                            label: 'Cumulativo autorizado',
                            data: lifeAbs.map(function (r) { return Number(r.cum_bytes_authorized || 0); }),
                            borderColor: '#f97316',
                            backgroundColor: 'rgba(249,115,22,0.1)',
                            fill: false,
                            tension: 0.25,
                            pointRadius: 1
                        },
                        {
                            label: 'Cumulativo observado',
                            data: lifeAbs.map(function (r) { return Number(r.cum_bytes_observed || 0); }),
                            borderColor: '#0ea5e9',
                            backgroundColor: 'rgba(14,165,233,0.1)',
                            fill: false,
                            tension: 0.25,
                            pointRadius: 1
                        }
                    ]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        destroyTransferChart('lifetimeRel');
        var ctxLifetimeRel = document.getElementById('jpsmTransferLifetimeRelativeChart');
        if (ctxLifetimeRel) {
            transferCharts.lifetimeRel = new Chart(ctxLifetimeRel, {
                type: 'line',
                data: {
                    labels: lifeRel.map(function (r) { return r.month || ''; }),
                    datasets: [{
                        label: 'Cumulativo observado por registrado',
                        data: lifeRel.map(function (r) { return Number(r.cum_bytes_per_registered_observed || 0); }),
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22,163,74,0.1)',
                        fill: true,
                        tension: 0.25,
                        pointRadius: 1
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }
    }

    function renderTransferReport(report) {
        if (!report || typeof report !== 'object') return;
        lastTransferReport = report;
        renderTransferSummary(report);
        renderTransferTopFolders(report.top_folders_month || []);
        scheduleTransferChartsRender(report);
    }

    function fetchTransferReport(filters) {
        var formData = new FormData();
        formData.append('action', 'jpsm_get_transfer_report');
        formData.append('api_version', '2');
        formData.append('nonce', nonceFor('access'));
        formData.append('month', filters.month || '');
        formData.append('window', filters.window || 'month');
        formData.append('tier', filters.tier || 'all');
        formData.append('region', filters.region || 'all');
        formData.append('device_class', filters.device_class || 'all');

        var applyBtn = document.getElementById('jpsm-transfer-apply');
        if (applyBtn) {
            applyBtn.disabled = true;
            applyBtn.textContent = 'Cargando...';
        }

        fetch(jpsm_vars.ajax_url, { method: 'POST', body: formData })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                var report = null;
                if (json && json.success && json.data && json.data.ok && json.data.data) {
                    report = json.data.data;
                } else if (json && json.success && json.data && typeof json.data === 'object') {
                    report = json.data;
                }

                if (!report) {
                    var errMsg = (json && json.data && json.data.message) ? json.data.message : 'No se pudo cargar el reporte.';
                    alert(errMsg);
                    return;
                }
                renderTransferReport(report);
            })
            .catch(function () {
                alert('Error de red al cargar reporte de transferencia.');
            })
            .finally(function () {
                if (applyBtn) {
                    applyBtn.disabled = false;
                    applyBtn.textContent = 'Aplicar filtros';
                }
            });
    }

    function exportTransferCsv() {
        var filters = transferFiltersFromUI();
        var params = new URLSearchParams();
        params.set('action', 'jpsm_export_transfer_csv');
        params.set('nonce', nonceFor('access'));
        params.set('month', filters.month || '');
        params.set('window', filters.window || 'month');
        params.set('tier', filters.tier || 'all');
        params.set('region', filters.region || 'all');
        params.set('device_class', filters.device_class || 'all');
        window.open(jpsm_vars.ajax_url + '?' + params.toString(), '_blank');
    }

    function setCustomerFilter(email) {
        selectedCustomerEmail = String(email || '').trim();

        var label = document.getElementById('jpsm-customer-filter-label');
        var clearBtn = document.getElementById('jpsm-customer-filter-clear');
        if (label) {
            label.textContent = selectedCustomerEmail ? selectedCustomerEmail : 'Todos';
        }
        if (clearBtn) {
            clearBtn.style.display = selectedCustomerEmail ? 'inline-flex' : 'none';
        }

        var normalized = normalizeEmail(selectedCustomerEmail);
        document.querySelectorAll('.jpsm-customer-item').forEach(function (el) {
            var elEmail = normalizeEmail(el.getAttribute('data-email'));
            el.classList.toggle('active', elEmail === normalized);
        });

        renderHistory();
    }

    function buildCustomerIndex(history) {
        if (!Array.isArray(history)) {
            return [];
        }

        var map = new Map();
        history.forEach(function (item) {
            var raw = (item && item.email) ? String(item.email) : '';
            var email = raw.trim();
            var key = normalizeEmail(email);
            if (!key) return;

            var rec = map.get(key);
            if (!rec) {
                rec = { email: email, count: 0, last_time: '' };
                map.set(key, rec);
            }

            rec.count += 1;
            var time = (item && item.time) ? String(item.time) : '';
            if (time && (!rec.last_time || time > rec.last_time)) {
                rec.last_time = time;
            }
        });

        var customers = Array.from(map.values());
        customers.sort(function (a, b) {
            return String(b.last_time || '').localeCompare(String(a.last_time || '')) ||
                (b.count - a.count) ||
                String(a.email || '').localeCompare(String(b.email || ''));
        });

        return customers;
    }

    function renderCustomerList() {
        var listEl = document.getElementById('jpsm-customer-list');
        if (!listEl) return;

        var metaEl = document.getElementById('jpsm-customer-list-meta');
        var search = readInputValue('jpsm-customer-search', '').toLowerCase();

        var history = Array.isArray(jpsm_vars.history) ? jpsm_vars.history : [];
        var customers = buildCustomerIndex(history);
        if (search) {
            customers = customers.filter(function (c) {
                return normalizeEmail(c.email).indexOf(search) !== -1;
            });
        }

        if (metaEl) {
            metaEl.textContent = customers.length + ' clientes';
        }

        var html = '';
        var selected = normalizeEmail(selectedCustomerEmail);

        html += '<div class="jpsm-customer-item' + (selected ? '' : ' active') + '" data-email="">' +
            '<span class="jpsm-customer-email">Todos</span>' +
            '<span class="jpsm-customer-count">' + (history.length || 0) + '</span>' +
            '</div>';

        customers.forEach(function (c) {
            var isActive = normalizeEmail(c.email) === selected;
            html += '<div class="jpsm-customer-item' + (isActive ? ' active' : '') + '" data-email="' + escapeHtml(c.email) + '">' +
                '<span class="jpsm-customer-email">' + escapeHtml(c.email) + '</span>' +
                '<span class="jpsm-customer-count">' + String(c.count || 0) + '</span>' +
                '</div>';
        });

        listEl.innerHTML = html || '<div style="padding:12px; color:var(--mv-text-muted);">Sin clientes</div>';
    }

    function setCustomerPanelOpen(open) {
        var panel = document.getElementById('jpsm-customers-sidebar');
        var overlay = document.getElementById('jpsm-customers-overlay');
        if (!panel || !overlay) return;

        if (open) {
            panel.classList.add('active');
            overlay.classList.add('active');
        } else {
            panel.classList.remove('active');
            overlay.classList.remove('active');
        }
    }

    // Render History with Date Grouping
    function formatDateHeader(dateStr) {
        // Ensure local date interpretation by using parts
        const p = dateStr.split('-');
        // Note: Months depend on constructor (0-11)
        const date = new Date(p[0], p[1] - 1, p[2]);
        const options = { weekday: 'long', day: 'numeric', month: 'long' };
        let formatted = date.toLocaleDateString('es-ES', options);
        return '📅 ' + formatted.charAt(0).toUpperCase() + formatted.slice(1);
    }

    function renderHistory() {
        var containers = document.querySelectorAll('.jpsm-history-list');
        if (containers.length === 0) return;

        if (!jpsm_vars.history) return;

        var searchQuery = readInputValue('jpsm-history-search', '').toLowerCase();

        containers.forEach(function (container) {
            var tbody = container.querySelector('.jpsm-activity-body-target');
            if (!tbody) return;

            var isRecentOnly = container.closest('#jpsm-tab-manage') !== null;
            tbody.innerHTML = '';

            var data = Array.isArray(jpsm_vars.history) ? jpsm_vars.history : [];

            // Customer filter (from Base de datos panel)
            var filterEmail = normalizeEmail(selectedCustomerEmail);
            if (filterEmail) {
                data = data.filter(function (item) {
                    return normalizeEmail(item && item.email) === filterEmail;
                });
            }

            // Search Filtering
            if (searchQuery) {
                data = data.filter(function (item) {
                    return normalizeEmail(item && item.email).indexOf(searchQuery) !== -1 ||
                        String((item && item.package) ? item.package : '').toLowerCase().indexOf(searchQuery) !== -1;
                });
            }

            if (isRecentOnly) {
                if (!Array.isArray(data)) data = [];
                data = data.filter(function (item) {
                    return item.time && item.time.indexOf(jpsm_vars.today) !== -1;
                });
            }

            if (!data || data.length === 0) {
                var msg = 'Sin registros';
                if (filterEmail) {
                    msg = 'Sin registros para "' + selectedCustomerEmail + '"';
                }
                if (searchQuery) {
                    msg = 'Sin resultados para "' + searchQuery + '"' + (filterEmail ? (' en "' + selectedCustomerEmail + '"') : '');
                }
                tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; color:var(--mv-text-muted); padding:20px;">' + escapeHtml(msg) + '</td></tr>';
                return;
            }

            var lastDate = '';
            data.forEach(function (item) {
                var dateParts = item.time.split(' ');
                var itemDate = dateParts[0];

                if (!isRecentOnly && itemDate !== lastDate) {
                    var dateHeader = document.createElement('tr');
                    dateHeader.innerHTML = '<td colspan="3" class="jpsm-history-date-header">' + formatDateHeader(itemDate) + '</td>';
                    tbody.appendChild(dateHeader);
                    lastDate = itemDate;
                }

                var tr = document.createElement('tr');
                tr.innerHTML = ' \
                    <td class="jpsm-col-check"> \
                        <input type="checkbox" class="jpsm-log-check" value="' + escapeHtml(item.id) + '"> \
                    </td> \
                    <td class="jpsm-col-info"> \
                        <div class="jpsm-info-email">' + escapeHtml(item.email) + '</div> \
                        <div class="jpsm-info-subtext">' + escapeHtml(item.package) + ' • ' + (dateParts[1] ? dateParts[1].substring(0, 5) : '') + '</div> \
                    </td> \
                    <td class="jpsm-col-actions"> \
                        <button class="jpsm-resend-email" data-id="' + escapeHtml(item.id) + '">🔁</button> \
                        <button class="jpsm-delete-log" data-id="' + escapeHtml(item.id) + '">✕</button> \
                    </td> \
                ';
                tbody.appendChild(tr);
            });
        });

        // Reset bulk selection UI after re-render/filtering.
        var checkAllBox = document.getElementById('jpsm-check-all');
        if (checkAllBox) {
            checkAllBox.checked = false;
        }
        if (typeof updateBulkUI === 'function') {
            updateBulkUI();
        }
    }

    // Search Input Listener
    document.addEventListener('input', function (e) {
        if (e.target.id === 'jpsm-history-search') {
            renderHistory();
        }
        if (e.target.id === 'jpsm-customer-search') {
            renderCustomerList();
        }
    });

    function fetchHistory() {
        var containers = document.querySelectorAll('.jpsm-activity-body-target');
        containers.forEach(function (tbody) {
            tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; padding: 20px; color:var(--mv-text-muted);">Cargando historial... <span class="spinner">⏳</span></td></tr>';
        });

        var formData = new FormData();
        formData.append('action', 'jpsm_get_history');
        formData.append('nonce', nonceFor('sales'));

        fetch(jpsm_vars.ajax_url, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(response => {
                if (response.success) {
                    jpsm_vars.history = Array.isArray(response.data) ? response.data : [];
                    renderCustomerList();
                    renderHistory();
                } else {
                    containers.forEach(function (tbody) {
                        tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; color:var(--mv-danger);">Error: ' + escapeHtml(response.data || "Unknown") + '</td></tr>';
                    });
                }
            })
            .catch(err => {
                console.error(err);
                containers.forEach(function (tbody) {
                    tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; color:var(--mv-danger);">Error de conexión</td></tr>';
                });
            });
    }

    fetchHistory();

    // ========== CUSTOMER DB UI (email list + sliding panel) ==========
    var customerListEl = document.getElementById('jpsm-customer-list');
    if (customerListEl) {
        customerListEl.addEventListener('click', function (e) {
            var item = e.target.closest('.jpsm-customer-item');
            if (!item) return;
            var email = item.getAttribute('data-email') || '';
            setCustomerFilter(email);
            setCustomerPanelOpen(false);
        });
    }

    var customerClearBtn = document.getElementById('jpsm-customer-filter-clear');
    if (customerClearBtn) {
        customerClearBtn.addEventListener('click', function (e) {
            e.preventDefault();
            setCustomerFilter('');
        });
    }

    var customersOpenBtn = document.getElementById('jpsm-customers-open');
    if (customersOpenBtn) {
        customersOpenBtn.addEventListener('click', function () {
            setCustomerPanelOpen(true);
        });
    }

    var customersCloseBtn = document.getElementById('jpsm-customers-close');
    if (customersCloseBtn) {
        customersCloseBtn.addEventListener('click', function () {
            setCustomerPanelOpen(false);
        });
    }

    var customersOverlayEl = document.getElementById('jpsm-customers-overlay');
    if (customersOverlayEl) {
        customersOverlayEl.addEventListener('click', function () {
            setCustomerPanelOpen(false);
        });
    }

    // Resend Email Logic
    document.addEventListener('click', function (e) {
        if (e.target.closest('.jpsm-resend-email')) {
            var btn = e.target.closest('.jpsm-resend-email');
            var id = btn.getAttribute('data-id');

            if (!confirm('¿Reenviar correo al cliente?')) return;

            btn.innerHTML = '⏳';

            var formData = new FormData();
            formData.append('action', 'jpsm_resend_email');
            formData.append('nonce', nonceFor('sales'));
            formData.append('id', id);

            fetch(jpsm_vars.ajax_url, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('✅ Correo reenviado con éxito');
                        btn.innerHTML = '🔁';
                    } else {
                        alert('❌ Error: ' + data.data);
                        btn.innerHTML = '⚠️';
                    }
                })
                .catch(err => {
                    console.error(err);
                    btn.innerHTML = '⚠️';
                });
        }
    });

    // Global Delete All Function
    window.jpsmDeleteAllLogs = function () {
        if (!confirm('¿Estás seguro de vaciar TODO el historial?')) return;

        var formData = new FormData();
        formData.append('action', 'jpsm_delete_all_logs');
        formData.append('nonce', nonceFor('sales'));

        fetch(jpsm_vars.ajax_url, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Historial vaciado');
                    location.reload();
                } else {
                    alert('❌ Error: ' + (data.data || 'Desconocido'));
                }
            });
    };

    // Process Sale Form
    const form = document.getElementById('jpsm-registration-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = document.getElementById('jpsm-submit-sale');
            var msg = document.getElementById('jpsm-message');

            btn.disabled = true;
            btn.innerHTML = 'Procesando...';

            var formData = new FormData(form);
            formData.append('action', 'jpsm_process_sale');
            formData.append('nonce', nonceFor('sales'));

            fetch(jpsm_vars.ajax_url, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = 'Enviar Pedido 🚀';
                    if (data.success) {
                        msg.className = 'success';
                        msg.innerHTML = '✅ Venta Registrada';
                        msg.style.display = 'block';
                        form.reset();
                        setTimeout(() => { location.reload(); }, 1500); // Reload to update history
                    } else {
                        msg.className = 'error';
                        msg.textContent = '❌ ' + (data.data || 'Error');
                        msg.style.display = 'block';
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    alert('Error de red');
                });
        });
    }

    // Delete Single Log - Synchronized
    document.addEventListener('click', function (e) {
        if (e.target.closest('.jpsm-delete-log')) {
            e.preventDefault();
            var btn = e.target.closest('.jpsm-delete-log');
            if (!confirm('¿Borrar este registro?')) return;

            var id = btn.getAttribute('data-id');
            var formData = new FormData();
            formData.append('action', 'jpsm_delete_log');
            formData.append('nonce', nonceFor('sales'));
            formData.append('id', id);

            fetch(jpsm_vars.ajax_url, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update global state and re-render all views
                        if (jpsm_vars.history) {
                            jpsm_vars.history = jpsm_vars.history.filter(function (i) {
                                return i.id != id;
                            });
                            renderCustomerList();
                            renderHistory();
                        }
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
        if (selectedCountSpan) selectedCountSpan.innerText = count;

        if (bulkBtn) {
            if (count > 0) {
                bulkBtn.style.display = 'inline-block';
                bulkBtn.style.animation = 'popIn 0.3s ease';
            } else {
                bulkBtn.style.display = 'none';
            }
        }
    }

    // Check All Handler
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            var checkboxes = document.querySelectorAll('.jpsm-log-check');
            checkboxes.forEach(function (cb) {
                cb.checked = checkAll.checked;
            });
            updateBulkUI();
        });
    }

    // Individual Checkbox Handler (Delegated)
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('jpsm-log-check')) {
            updateBulkUI();
        }
    });

    // Bulk Delete Action
    if (bulkBtn) {
        bulkBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var checked = document.querySelectorAll('.jpsm-log-check:checked');
            if (checked.length === 0) return;

            if (!confirm('¿Estás seguro de ELIMINAR ' + checked.length + ' registros?')) return;

            var ids = [];
            checked.forEach(function (cb) {
                ids.push(cb.value);
            });

            bulkBtn.disabled = true;
            bulkBtn.innerHTML = 'Borrando...';

            var formData = new FormData();
            formData.append('action', 'jpsm_delete_bulk_log');
            formData.append('nonce', nonceFor('sales'));

            // Send IDs using array notation
            ids.forEach(function (id) {
                formData.append('ids[]', id);
            });

            fetch(jpsm_vars.ajax_url, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update global history (used by customer DB + table rendering).
                        if (Array.isArray(jpsm_vars.history)) {
                            var idSet = new Set(ids.map(function (v) { return String(v); }));
                            jpsm_vars.history = jpsm_vars.history.filter(function (item) {
                                return !idSet.has(String(item && item.id));
                            });
                        }

                        // Reset UI
                        bulkBtn.disabled = false;
                        bulkBtn.innerHTML = 'Borrar Seleccionados (<span id="jpsm-selected-count">0</span>)';
                        bulkBtn.style.display = 'none';
                        if (checkAll) checkAll.checked = false;

                        renderCustomerList();
                        renderHistory();

                        alert('✅ Registros eliminados');
                    } else {
                        alert('❌ Error: ' + (data.data));
                        bulkBtn.disabled = false;
                        bulkBtn.innerHTML = 'Reintentar';
                    }
                });
        });
    }

    // ========== PERMISSIONS HANDLING ==========
    window.jpsmOpenPermTab = function (tab) {
        document.querySelectorAll('.jpsm-perm-panel').forEach(p => p.style.display = 'none');
        document.querySelectorAll('.jpsm-perm-subtab').forEach(b => b.classList.remove('active'));
        var target = document.getElementById('jpsm-perm-' + tab);
        if (target) {
            target.style.display = 'block';
            if (tab === 'indice') jpsmLoadIndexStats();
        }
    };

    window.jpsmSearchUser = function () {
        var emailInput = document.getElementById('jpsm-user-search');
        var email = emailInput ? emailInput.value : '';
        if (!email) return alert('Ingresa un email');

        var result = document.getElementById('jpsm-user-result');
        result.innerHTML = '<p style="color:#a1a1aa;">Buscando...</p>';

        fetch(jpsm_vars.ajax_url + '?action=jpsm_get_user_tier&email=' + encodeURIComponent(email) + '&nonce=' + encodeURIComponent(nonceFor('access')))
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    var u = data.data;
                    var safeEmail = escapeHtml(u.email);
                    result.innerHTML = `
                    <div class="jpsm-perm-item">
                        <div>
                            <div style="font-weight:500;">${safeEmail}</div>
                            <div style="font-size:12px; color:var(--mv-text-muted);">${u.is_customer ? '✓ Cliente' : '📊 Lead'} · Reproducciones: ${escapeHtml(u.plays)}</div>
                        </div>
                        <select class="jpsm-tier-select">
                            ${renderTierOptions(u.tier, getTierOptions())}
                        </select>
                    </div>
                `;
                    var tierSelect = result.querySelector('.jpsm-tier-select');
                    if (tierSelect) {
                        tierSelect.addEventListener('change', function () {
                            window.jpsmUpdateUserTier(u.email, this.value);
                        });
                    }
                } else {
                    result.innerHTML = '<p style="color:var(--mv-danger);">Usuario no encontrado</p>';
                }
            });
    };

    window.jpsmUpdateUserTier = function (email, tier) {
        var formData = new FormData();
        formData.append('action', 'jpsm_update_user_tier');
        formData.append('email', email);
        formData.append('tier', tier);
        formData.append('nonce', nonceFor('access'));

        fetch(jpsm_vars.ajax_url, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Nivel actualizado');
                } else {
                    alert('❌ Error: ' + data.data);
                }
            });
    };

    window.jpsmLoadFolders = function () {
        var list = document.getElementById('jpsm-folder-list');
        list.innerHTML = '<p style="color:var(--mv-text-muted);">Cargando...</p>';

        fetch(jpsm_vars.ajax_url + '?action=jpsm_get_folders&nonce=' + encodeURIComponent(nonceFor('access')))
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    var html = '';
                    Object.keys(data.data).forEach(folder => {
                        var selectedTier = getFolderSelectedTier(data.data[folder]);
                        var safeFolder = escapeHtml(folder);
                        html += `
                        <div class="jpsm-perm-item">
                            <span>📁 ${safeFolder}</span>
                            <select class="jpsm-folder-tier-select" data-folder="${safeFolder}">
                                ${renderTierOptions(selectedTier, getPaidTierOptions())}
                            </select>
                        </div>
                    `;
                    });
                    list.innerHTML = html || '<p style="color:var(--mv-text-muted);">No hay carpetas</p>';
                    list.querySelectorAll('.jpsm-folder-tier-select').forEach(function (sel) {
                        sel.addEventListener('change', function () {
                            window.jpsmUpdateFolderTier(this.dataset.folder || '', this.value);
                        });
                    });
                }
            });
    };

    window.jpsmUpdateFolderTier = function (folder, tier) {
        var formData = new FormData();
        formData.append('action', 'jpsm_update_folder_tier');
        formData.append('folder', folder);
        formData.append('tier', tier);
        formData.append('nonce', nonceFor('access'));
        fetch(jpsm_vars.ajax_url, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => { if (!data.success) alert('❌ Error: ' + data.data); });
    };

    window.jpsmLoadLeads = function () {
        var list = document.getElementById('jpsm-leads-list');
        list.innerHTML = '<p style="color:var(--mv-text-muted);">Cargando...</p>';
        fetch(jpsm_vars.ajax_url + '?action=jpsm_get_leads&nonce=' + encodeURIComponent(nonceFor('access')))
            .then(r => r.json())
            .then(data => {
                if (data.success && Object.keys(data.data).length > 0) {
                    var html = '';
                    Object.values(data.data).forEach(lead => {
                        html += `<div class="jpsm-perm-item"><div><div>${escapeHtml(lead.email)}</div><div style="font-size:11px; color:var(--mv-text-muted);">${escapeHtml(lead.registered || '')}</div></div></div>`;
                    });
                    list.innerHTML = html;
                } else { list.innerHTML = '<p style="color:var(--mv-text-muted);">Sin leads</p>'; }
            });
    };

    // ========== INDEX SYNC LOGIC ==========
    window.jpsmLoadIndexStats = function () {
        fetch(jpsm_vars.ajax_url + '?action=jpsm_get_index_stats&nonce=' + encodeURIComponent(nonceFor('index')))
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    var s = data.data;
                    var totalEl = document.getElementById('jpsm-idx-total');
                    var audioEl = document.getElementById('jpsm-idx-audio');
                    var videoEl = document.getElementById('jpsm-idx-video');
                    if (totalEl) totalEl.textContent = Number(s.total || 0).toLocaleString();
                    if (audioEl) audioEl.textContent = Number(s.audio || 0).toLocaleString();
                    if (videoEl) videoEl.textContent = Number(s.video || 0).toLocaleString();

                    var healthEl = document.getElementById('jpsm-idx-health');
                    var healthLabel = Number(s.total || 0) === 0 ? 'Empty' : (s.stale ? 'Stale' : 'Fresh');
                    if (healthEl) {
                        healthEl.textContent = healthLabel;
                        healthEl.style.color = healthLabel === 'Fresh' ? '#16a34a' : (healthLabel === 'Stale' ? '#f59e0b' : '#ef4444');
                    }

                    var activeTableEl = document.getElementById('jpsm-idx-active-table');
                    if (activeTableEl) activeTableEl.textContent = String(s.active_table || '-');

                    var quality = (s && s.quality && typeof s.quality === 'object') ? s.quality : {};
                    var qScanned = document.getElementById('jpsm-idx-q-scanned');
                    var qInserted = document.getElementById('jpsm-idx-q-inserted');
                    var qUpdated = document.getElementById('jpsm-idx-q-updated');
                    var qSkipped = document.getElementById('jpsm-idx-q-skipped');
                    var qErrors = document.getElementById('jpsm-idx-q-errors');
                    if (qScanned) qScanned.textContent = Number(quality.scanned || 0).toLocaleString();
                    if (qInserted) qInserted.textContent = Number(quality.inserted || 0).toLocaleString();
                    if (qUpdated) qUpdated.textContent = Number(quality.updated || 0).toLocaleString();
                    if (qSkipped) qSkipped.textContent = Number(quality.skipped_invalid || 0).toLocaleString();
                    if (qErrors) qErrors.textContent = Number(quality.errors || 0).toLocaleString();

                    if (s.last_sync) {
                        var d = new Date(s.last_sync);
                        var ls = document.getElementById('jpsm-idx-lastsync');
                        if (ls) ls.textContent = d.toLocaleString('es-MX', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                    } else {
                        var ls2 = document.getElementById('jpsm-idx-lastsync');
                        if (ls2) ls2.textContent = 'Nunca';
                    }
                }
            });
    };

    window.jpsmSyncIndex = async function () {
        var btn = document.getElementById('jpsm-sync-btn');
        var status = document.getElementById('jpsm-sync-status');
        var progressContainer = document.getElementById('jpsm-sync-progress-container');
        var progressBar = document.getElementById('jpsm-sync-bar');
        var phaseText = document.getElementById('jpsm-sync-phase');

        btn.disabled = true;
        btn.textContent = '⏳ Sincronizando...';
        progressContainer.style.display = 'block';
        progressBar.style.width = '5%';
        if (phaseText) phaseText.textContent = 'Iniciando...';

        var startTime = Date.now();
        var totalFiles = 0;

        async function processBatch(token) {
            try {
                var formData = new FormData();
                formData.append('action', 'jpsm_sync_mediavault_index');
                formData.append('nonce', nonceFor('index'));
                if (token) formData.append('next_token', token);

                var res = await fetch(jpsm_vars.ajax_url, { method: 'POST', body: formData });
                if (!res.ok) throw new Error(`HTTP Error: ${res.status}`);
                var data = await res.json();

                if (data.success) {
                    var info = data.data;
                    totalFiles += (info.count || 0);
                    var elapsed = (Date.now() - startTime) / 1000;
                    if (phaseText) phaseText.textContent = `Indexados: ${totalFiles}...`;

                    var currentWidth = parseFloat(progressBar.style.width) || 5;
                    if (currentWidth < 90) progressBar.style.width = (currentWidth + 2) + '%';

                    if (!info.finished && info.next_token) {
                        await processBatch(info.next_token);
                    } else {
                        progressBar.style.width = '100%';
                        if (phaseText) phaseText.textContent = '✅ Completado';
                        status.textContent = `✅ Total: ${totalFiles} archivos en ${elapsed.toFixed(1)}s (inválidos: ${Number(info.skipped_invalid || 0).toLocaleString()}, errores: ${Number(info.errors || 0).toLocaleString()})`;
                        btn.textContent = '🔄 Sincronizar Índice';
                        btn.disabled = false;
                        jpsmLoadIndexStats();
                    }
                } else { throw new Error(data.data || 'Error desconocido'); }
            } catch (err) {
                console.error(err);
                if (phaseText) phaseText.textContent = '❌ Error';
                status.textContent = '❌ ' + err.message;
                btn.disabled = false;
                btn.textContent = 'Reintentar';
            }
        }
        processBatch(null);
    };

    // ========== MAIN SIDEBAR (collapsible + mobile drawer) ==========
    var mainSidebarEl = document.getElementById('jpsm-sidebar');
    var mainSidebarOverlayEl = document.getElementById('jpsm-sidebar-overlay');
    var mainSidebarToggleEl = document.getElementById('jpsm-sidebar-toggle');

    function isMobileNav() {
        return !!(window.matchMedia && window.matchMedia('(max-width: 1023px)').matches);
    }

    function setMainSidebarOpen(open) {
        if (!mainSidebarEl || !mainSidebarOverlayEl) return;
        if (!isMobileNav()) return;

        if (open) {
            mainSidebarEl.classList.add('active');
            mainSidebarOverlayEl.classList.add('active');
        } else {
            mainSidebarEl.classList.remove('active');
            mainSidebarOverlayEl.classList.remove('active');
        }
    }

    function setMainSidebarCollapsed(collapsed) {
        if (!mainSidebarEl) return;
        if (isMobileNav()) return;
        mainSidebarEl.classList.toggle('collapsed', !!collapsed);
        try {
            localStorage.setItem('jpsm_sidebar_collapsed', collapsed ? '1' : '0');
        } catch (e) { }
    }

    function syncMainSidebarForViewport() {
        if (!mainSidebarEl) return;

        // Always reset mobile drawer state when switching modes.
        if (mainSidebarOverlayEl) {
            mainSidebarOverlayEl.classList.remove('active');
        }
        mainSidebarEl.classList.remove('active');

        if (isMobileNav()) {
            mainSidebarEl.classList.remove('collapsed');
        } else {
            var collapsed = false;
            try {
                collapsed = localStorage.getItem('jpsm_sidebar_collapsed') === '1';
            } catch (e) { }
            mainSidebarEl.classList.toggle('collapsed', collapsed);
        }
    }

    if (mainSidebarToggleEl && mainSidebarEl) {
        mainSidebarToggleEl.addEventListener('click', function () {
            if (isMobileNav()) {
                setMainSidebarOpen(!mainSidebarEl.classList.contains('active'));
                return;
            }
            setMainSidebarCollapsed(!mainSidebarEl.classList.contains('collapsed'));
        });
    }

    if (mainSidebarOverlayEl) {
        mainSidebarOverlayEl.addEventListener('click', function () {
            setMainSidebarOpen(false);
        });
    }

    window.addEventListener('resize', function () {
        syncMainSidebarForViewport();
    });
    syncMainSidebarForViewport();

    var behaviorLoadedOnce = false;
    if (jpsm_vars && jpsm_vars.behavior_report && typeof jpsm_vars.behavior_report === 'object') {
        renderBehaviorReport(jpsm_vars.behavior_report);
        behaviorLoadedOnce = true;
    }

    var transferLoadedOnce = false;
    setTransferWindow(readInputValue('jpsm-transfer-window', 'month'));
    initTransferKpiClarityUI();
    if (jpsm_vars && jpsm_vars.transfer_report && typeof jpsm_vars.transfer_report === 'object') {
        try {
            renderTransferReport(jpsm_vars.transfer_report);
            transferLoadedOnce = true;
        } catch (e) {
            console.error('JPSM transfer bootstrap error', e);
        }
    }

    var behaviorApplyBtn = document.getElementById('jpsm-behavior-apply');
    if (behaviorApplyBtn) {
        behaviorApplyBtn.addEventListener('click', function () {
            fetchBehaviorReport(behaviorFiltersFromUI());
            behaviorLoadedOnce = true;
        });
    }

    var behaviorExportBtn = document.getElementById('jpsm-behavior-export');
    if (behaviorExportBtn) {
        behaviorExportBtn.addEventListener('click', function () {
            exportBehaviorCsv();
        });
    }

    var transferApplyBtn = document.getElementById('jpsm-transfer-apply');
    if (transferApplyBtn) {
        transferApplyBtn.addEventListener('click', function () {
            fetchTransferReport(transferFiltersFromUI());
            transferLoadedOnce = true;
        });
    }

    var transferExportBtn = document.getElementById('jpsm-transfer-export');
    if (transferExportBtn) {
        transferExportBtn.addEventListener('click', function () {
            exportTransferCsv();
        });
    }

    document.querySelectorAll('.jpsm-transfer-window-shortcut').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var windowKey = (btn.getAttribute('data-window') || 'month').trim();
            setTransferWindow(windowKey);
            fetchTransferReport(transferFiltersFromUI());
            transferLoadedOnce = true;
        });
    });

    window.jpsmLogout = function () {
        if (!confirm('¿Cerrar sesión?')) return;
        var formData = new FormData();
        formData.append('action', 'jpsm_logout');
        formData.append('nonce', nonceFor('logout'));
        fetch(jpsm_vars.ajax_url, { method: 'POST', body: formData }).then(() => location.reload());
    };

    window.jpsmOpenTab = function (evt, tabName) {
        if (evt && typeof evt.preventDefault === 'function') {
            evt.preventDefault();
        }

        var i, tabcontent, tablinks;
        tabcontent = document.getElementsByClassName("jpsm-tab-content");
        for (i = 0; i < tabcontent.length; i++) tabcontent[i].style.display = "none";
        tablinks = document.querySelectorAll(".jpsm-nav-item");
        tablinks.forEach(link => link.classList.remove("active"));
        var target = document.getElementById(tabName);
        if (target) {
            target.style.display = "block";
            // Set all links that point to this tab as active
            document.querySelectorAll(`[onclick*="'${tabName}'"]`).forEach(l => l.classList.add("active"));

            // Close drawers when navigating (mobile).
            setMainSidebarOpen(false);
            setCustomerPanelOpen(false);

            // Scroll content to top for the selected section.
            var scroller = document.querySelector('.jpsm-main-content');
            if (scroller) {
                scroller.scrollTop = 0;
            }

            if (tabName === 'jpsm-tab-customers') {
                renderCustomerList();
            }

            // Charts render best once the metrics tab is visible (avoid 0-size canvas on init).
            if (tabName === 'jpsm-tab-metrics' && typeof window.jpsmRenderDashboardCharts === 'function') {
                window.jpsmRenderDashboardCharts();
            }

            if (tabName === 'jpsm-tab-behavior' && !behaviorLoadedOnce) {
                fetchBehaviorReport(behaviorFiltersFromUI());
                behaviorLoadedOnce = true;
            }

            if (tabName === 'jpsm-tab-folder-downloads' && !transferLoadedOnce) {
                fetchTransferReport(transferFiltersFromUI());
                transferLoadedOnce = true;
            } else if (tabName === 'jpsm-tab-folder-downloads' && lastTransferReport) {
                scheduleTransferChartsRender(lastTransferReport);
            }
        }
    };
});
