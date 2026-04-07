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
        var search = (document.getElementById('jpsm-customer-search')?.value || '').toLowerCase().trim();

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

        var searchQuery = (document.getElementById('jpsm-history-search')?.value || '').toLowerCase();

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
                    document.getElementById('jpsm-idx-total').textContent = s.total.toLocaleString();
                    document.getElementById('jpsm-idx-audio').textContent = s.audio.toLocaleString();
                    document.getElementById('jpsm-idx-video').textContent = s.video.toLocaleString();
                    if (s.last_sync) {
                        var d = new Date(s.last_sync);
                        document.getElementById('jpsm-idx-lastsync').textContent = d.toLocaleString('es-MX', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
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
        phaseText.textContent = 'Iniciando...';

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
                    phaseText.textContent = `Indexados: ${totalFiles}...`;

                    var currentWidth = parseFloat(progressBar.style.width) || 5;
                    if (currentWidth < 90) progressBar.style.width = (currentWidth + 2) + '%';

                    if (!info.finished && info.next_token) {
                        await processBatch(info.next_token);
                    } else {
                        progressBar.style.width = '100%';
                        phaseText.textContent = '✅ Completado';
                        status.textContent = `✅ Total: ${totalFiles} archivos en ${elapsed.toFixed(1)}s`;
                        btn.textContent = '🔄 Sincronizar Índice';
                        btn.disabled = false;
                        jpsmLoadIndexStats();
                    }
                } else { throw new Error(data.data || 'Error desconocido'); }
            } catch (err) {
                console.error(err);
                phaseText.textContent = '❌ Error';
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
        }
    };
});
