/**
 * JetPack Store Manager - Admin Dashboard JS
 * 
 * This file handles ONLY the WordPress Admin Dashboard functionality.
 */
jQuery(document).ready(function ($) {
    var jpsmNonces = (typeof jpsm_data !== 'undefined' && jpsm_data.nonces) ? jpsm_data.nonces : {};
    var nonceFor = function (key) {
        return jpsmNonces[key] || (typeof jpsm_data !== 'undefined' ? jpsm_data.nonce : '');
    };
    var extractAjaxError = function (jqXHR, fallback) {
        fallback = fallback || 'Error de conexión';
        try {
            var r = (jqXHR && jqXHR.responseJSON) ? jqXHR.responseJSON : null;
            if (r) {
                if (typeof r === 'string' && r) return r;
                if (r.data !== undefined) {
                    if (typeof r.data === 'string' && r.data) return r.data;
                    if (r.data && typeof r.data === 'object') {
                        if (typeof r.data.message === 'string' && r.data.message) return r.data.message;
                        if (typeof r.data.error === 'string' && r.data.error) return r.data.error;
                    }
                }
                if (typeof r.message === 'string' && r.message) return r.message;
            }

            var text = (jqXHR && typeof jqXHR.responseText === 'string') ? jqXHR.responseText : '';
            if (text) {
                var parsed = JSON.parse(text);
                if (parsed) {
                    if (typeof parsed.data === 'string' && parsed.data) return parsed.data;
                    if (parsed.data && typeof parsed.data === 'object' && typeof parsed.data.message === 'string' && parsed.data.message) {
                        return parsed.data.message;
                    }
                    if (typeof parsed.message === 'string' && parsed.message) return parsed.message;
                }
            }
        } catch (e) { }
        return fallback;
    };
    var escapeHtml = function (value) {
        return (value || '').toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    // =====================================================
    // 1. ADMIN DASHBOARD: Stats & Charts
    // =====================================================
    if (typeof jpsm_data !== 'undefined' && jpsm_data.stats) {
        // Update stat counters
        $('#stat-total').text(jpsm_data.stats.total);
        $('#stat-today').text(jpsm_data.stats.today);

        // Initialize Charts (if Chart.js is available)
        if (typeof Chart !== 'undefined') {
            // Package Distribution Chart
            var pkgCtx = document.getElementById('chart-packages');
            if (pkgCtx) {
                new Chart(pkgCtx.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Básico', 'VIP', 'Full'],
                        datasets: [{
                            data: [
                                jpsm_data.stats.packages.basic || 0,
                                jpsm_data.stats.packages.vip || 0,
                                jpsm_data.stats.packages.full || 0
                            ],
                            backgroundColor: ['#22c55e', '#8b5cf6', '#ec4899'],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }

            // Region Distribution Chart
            var regCtx = document.getElementById('chart-regions');
            if (regCtx) {
                new Chart(regCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: ['Nacional', 'Internacional'],
                        datasets: [{
                            label: 'Ventas',
                            data: [
                                jpsm_data.stats.regions.national || 0,
                                jpsm_data.stats.regions.international || 0
                            ],
                            backgroundColor: ['#3b82f6', '#f97316'],
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: { y: { beginAtZero: true } },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        }

        // Populate Dashboard History Table
        var $dashboardBody = $('#dashboard-history-body');
        if ($dashboardBody.length && jpsm_data.log) {
            jpsm_data.log.slice(0, 10).forEach(function (entry) {
                var statusClass = entry.status === 'Completado' ? 'color: green;' : 'color: red;';
                $dashboardBody.append(
                    '<tr>' +
                    '<td>' + entry.time + '</td>' +
                    '<td>' + entry.email + '</td>' +
                    '<td>' + entry.package + '</td>' +
                    '<td>' + entry.region + '</td>' +
                    '<td style="' + statusClass + '">' + entry.status + '</td>' +
                    '</tr>'
                );
            });
        }
    }

    // =====================================================
    // 2. ADMIN REGISTRATION PAGE
    // =====================================================
    var $adminForm = $('#jpsm-registration-form');
    if ($adminForm.length) {
        $adminForm.on('submit', function (e) {
            e.preventDefault();
            var $btn = $('#jpsm-submit-sale');
            var $msg = $('#jpsm-message');
            $btn.prop('disabled', true).text('Procesando...');

            var payload = {
                action: 'jpsm_process_sale',
                nonce: nonceFor('sales'),
                client_email: $('#client_email').val(),
                package_type: $('#package_type').val(),
                region: $('#region').val()
            };

            if ($('#package_type').val() === 'vip') {
                payload.vip_subtype = $('input[name="vip_subtype"]:checked').val() || 'vip_videos';
            }

	                $.post(jpsm_data.ajax_url, payload)
	                    .done(function (response) {
	                    $btn.prop('disabled', false).text('Enviar y Registrar');
	                    if (response.success) {
	                        $msg.removeClass('error').addClass('success')
	                            .text('✅ ' + response.data.message).slideDown();
	                        $adminForm[0].reset();
	                        setTimeout(function () { location.reload(); }, 2000);
	                    } else {
	                        $msg.removeClass('success').addClass('error')
	                            .text('❌ ' + response.data).slideDown();
	                    }
	                })
	                .fail(function (jqXHR) {
	                    $btn.prop('disabled', false).text('Enviar y Registrar');
	                    $msg.removeClass('success').addClass('error')
	                        .text('❌ ' + extractAjaxError(jqXHR)).slideDown();
	                });
	        });

        $('#package_type').on('change', function () {
            if ($(this).val() === 'vip') {
                $('#vip-subtype-row').slideDown();
            } else {
                $('#vip-subtype-row').slideUp();
            }
        });
    }

    // =====================================================
    // 2.5. FINANCE PAGE
    // =====================================================
    var $financePage = $('#jpsm-finance-page');
    if ($financePage.length) {
        var financeChannelDefaults = {
            mx: 'bank_transfer',
            us: 'paypal',
            manual: 'manual_adjustment'
        };

        var financeShowMessage = function ($target, message, isError) {
            if (!$target || !$target.length) {
                return;
            }
            $target
                .stop(true, true)
                .css({
                    padding: '10px 12px',
                    borderRadius: '8px',
                    border: isError ? '1px solid #fecaca' : '1px solid #bbf7d0',
                    background: isError ? '#fef2f2' : '#f0fdf4',
                    color: isError ? '#991b1b' : '#166534',
                    display: 'block'
                })
                .text(message);
        };

        var financeClearMessage = function ($target) {
            if ($target && $target.length) {
                $target.text('').removeAttr('style');
            }
        };

        var parseMoney = function (value) {
            var raw = (value || '').toString().trim().replace(/\s+/g, '');
            if (!raw) {
                return 0;
            }
            if (/^-?\d+,\d+$/.test(raw)) {
                raw = raw.replace(',', '.');
            } else {
                raw = raw.replace(/,/g, '');
            }
            var parsed = parseFloat(raw);
            return isNaN(parsed) ? 0 : parsed;
        };

        var roundMoney = function (value, digits) {
            var decimals = typeof digits === 'number' ? digits : 2;
            var factor = Math.pow(10, decimals);
            return Math.round((value || 0) * factor) / factor;
        };

        var selectedFinanceSales = function () {
            return $('.jpsm-finance-sale-checkbox:checked').map(function () {
                return {
                    id: this.value,
                    market: this.getAttribute('data-market') || '',
                    currency: this.getAttribute('data-currency') || '',
                    amount: parseMoney(this.getAttribute('data-amount') || '0')
                };
            }).get();
        };

        var syncFinanceChannels = function () {
            var market = $('#jpsm-finance-market').val() || 'manual';
            var desired = financeChannelDefaults[market] || 'manual_adjustment';
            if ($('#jpsm-finance-channel option[value="' + desired + '"]').length) {
                $('#jpsm-finance-channel').val(desired);
            }
        };

        var syncSettlementComputedFields = function (preserveManualGross) {
            var rows = selectedFinanceSales();
            var market = $('#jpsm-finance-market').val() || 'manual';
            var gross = parseMoney($('#jpsm-finance-gross-amount').val());
            var fee = parseMoney($('#jpsm-finance-fee-amount').val());
            var net = parseMoney($('#jpsm-finance-net-amount').val());
            var fx = parseMoney($('#jpsm-finance-fx-rate').val());
            var netMxn = parseMoney($('#jpsm-finance-net-mxn').val());
            var $msg = $('#jpsm-finance-settlement-message');

            $('#jpsm-finance-selected-count').text(rows.length);

            if (rows.length) {
                var firstMarket = rows[0].market;
                var firstCurrency = rows[0].currency;
                var hasMixedMarket = rows.some(function (row) { return row.market !== firstMarket; });
                var hasMixedCurrency = rows.some(function (row) { return row.currency !== firstCurrency; });

                if (hasMixedMarket || hasMixedCurrency) {
                    var last = $('.jpsm-finance-sale-checkbox:checked').last();
                    last.prop('checked', false);
                    financeShowMessage($msg, 'No mezcles ventas de México y Estados Unidos en la misma liquidación.', true);
                    rows = selectedFinanceSales();
                    $('#jpsm-finance-selected-count').text(rows.length);
                } else {
                    financeClearMessage($msg);
                    $('#jpsm-finance-market').val(firstMarket);
                    syncFinanceChannels();
                    if (!preserveManualGross) {
                        gross = rows.reduce(function (acc, row) { return acc + row.amount; }, 0);
                        $('#jpsm-finance-gross-amount').val(roundMoney(gross).toFixed(2));
                    }
                    market = firstMarket;
                }
            }

            gross = parseMoney($('#jpsm-finance-gross-amount').val());
            fee = parseMoney($('#jpsm-finance-fee-amount').val());
            net = parseMoney($('#jpsm-finance-net-amount').val());
            fx = parseMoney($('#jpsm-finance-fx-rate').val());
            netMxn = parseMoney($('#jpsm-finance-net-mxn').val());

            if (gross > 0) {
                var computedNet = Math.max(0, roundMoney(gross - fee));
                if (!$('#jpsm-finance-net-amount').val() || rows.length) {
                    $('#jpsm-finance-net-amount').val(computedNet.toFixed(2));
                    net = computedNet;
                }
            }

            if (market === 'mx') {
                if (!$('#jpsm-finance-fee-amount').val()) {
                    $('#jpsm-finance-fee-amount').val('0.00');
                    fee = 0;
                }
                if (net > 0) {
                    $('#jpsm-finance-net-mxn').val(roundMoney(net).toFixed(2));
                }
            } else if (market === 'us' && net > 0 && fx > 0) {
                $('#jpsm-finance-net-mxn').val(roundMoney(net * fx).toFixed(2));
            }
        };

        syncFinanceChannels();
        syncSettlementComputedFields(false);

        $('#jpsm-finance-market').on('change', function () {
            syncFinanceChannels();
            syncSettlementComputedFields(true);
        });

        $('#jpsm-finance-fee-amount, #jpsm-finance-fx-rate, #jpsm-finance-gross-amount').on('input', function () {
            syncSettlementComputedFields(true);
        });

        $(document).on('change', '.jpsm-finance-sale-checkbox', function () {
            syncSettlementComputedFields(false);
        });

        $('#jpsm-finance-clear-selection').on('click', function () {
            $('.jpsm-finance-sale-checkbox').prop('checked', false);
            $('#jpsm-finance-selected-count').text('0');
            financeClearMessage($('#jpsm-finance-settlement-message'));
            syncSettlementComputedFields(true);
        });

        $('#jpsm-finance-expense-amount, #jpsm-finance-expense-fx-rate, #jpsm-finance-expense-currency').on('input change', function () {
            var amount = parseMoney($('#jpsm-finance-expense-amount').val());
            var fx = parseMoney($('#jpsm-finance-expense-fx-rate').val());
            var currency = $('#jpsm-finance-expense-currency').val() || 'MXN';
            if (currency === 'MXN') {
                $('#jpsm-finance-expense-mxn').val(roundMoney(amount).toFixed(2));
            } else if (fx > 0) {
                $('#jpsm-finance-expense-mxn').val(roundMoney(amount * fx).toFixed(2));
            }
        });

        $('#jpsm-finance-settlement-form').on('submit', function (e) {
            e.preventDefault();
            var $btn = $('#jpsm-finance-submit-settlement');
            var $msg = $('#jpsm-finance-settlement-message');
            financeClearMessage($msg);
            $btn.prop('disabled', true).text('Guardando...');

            var payload = $(this).serializeArray();
            payload.push({ name: 'action', value: 'jpsm_record_finance_settlement' });
            payload.push({ name: 'nonce', value: nonceFor('finance') });
            selectedFinanceSales().forEach(function (row) {
                payload.push({ name: 'sale_uids[]', value: row.id });
            });

            $.post(jpsm_data.ajax_url, payload)
                .done(function (response) {
                    $btn.prop('disabled', false).text('Guardar liquidación');
                    if (response && response.success) {
                        financeShowMessage($msg, response.data && response.data.message ? response.data.message : 'Liquidación registrada.', false);
                        window.setTimeout(function () { window.location.reload(); }, 900);
                        return;
                    }
                    financeShowMessage($msg, 'No se pudo registrar la liquidación.', true);
                })
                .fail(function (jqXHR) {
                    $btn.prop('disabled', false).text('Guardar liquidación');
                    financeShowMessage($msg, extractAjaxError(jqXHR, 'No se pudo registrar la liquidación.'), true);
                });
        });

        $('#jpsm-finance-expense-form').on('submit', function (e) {
            e.preventDefault();
            var $btn = $('#jpsm-finance-submit-expense');
            var $msg = $('#jpsm-finance-expense-message');
            financeClearMessage($msg);
            $btn.prop('disabled', true).text('Guardando...');

            var payload = $(this).serializeArray();
            payload.push({ name: 'action', value: 'jpsm_record_finance_expense' });
            payload.push({ name: 'nonce', value: nonceFor('finance') });

            $.post(jpsm_data.ajax_url, payload)
                .done(function (response) {
                    $btn.prop('disabled', false).text('Guardar gasto');
                    if (response && response.success) {
                        financeShowMessage($msg, response.data && response.data.message ? response.data.message : 'Gasto registrado.', false);
                        window.setTimeout(function () { window.location.reload(); }, 900);
                        return;
                    }
                    financeShowMessage($msg, 'No se pudo registrar el gasto.', true);
                })
                .fail(function (jqXHR) {
                    $btn.prop('disabled', false).text('Guardar gasto');
                    financeShowMessage($msg, extractAjaxError(jqXHR, 'No se pudo registrar el gasto.'), true);
                });
        });

        $(document).on('click', '.jpsm-finance-delete-settlement', function () {
            var settlementUid = $(this).data('settlement');
            if (!settlementUid || !window.confirm('¿Eliminar esta liquidación?')) {
                return;
            }

            $.post(jpsm_data.ajax_url, {
                action: 'jpsm_delete_finance_settlement',
                nonce: nonceFor('finance'),
                settlement_uid: settlementUid
            }).done(function () {
                window.location.reload();
            }).fail(function (jqXHR) {
                window.alert(extractAjaxError(jqXHR, 'No se pudo eliminar la liquidación.'));
            });
        });

        $(document).on('click', '.jpsm-finance-delete-expense', function () {
            var expenseUid = $(this).data('expense');
            if (!expenseUid || !window.confirm('¿Eliminar este gasto?')) {
                return;
            }

            $.post(jpsm_data.ajax_url, {
                action: 'jpsm_delete_finance_expense',
                nonce: nonceFor('finance'),
                expense_uid: expenseUid
            }).done(function () {
                window.location.reload();
            }).fail(function (jqXHR) {
                window.alert(extractAjaxError(jqXHR, 'No se pudo eliminar el gasto.'));
            });
        });
    }

    // =====================================================
    // 3. SETTINGS PAGE (Freeze Prices)
    // =====================================================
    if ($('body').hasClass('jetpack-store-page_jpsm-settings')) {
        var $settingsForm = $('.wrap form[action="options.php"]').first();
        if ($settingsForm.length && $settingsForm.children('h2').length > 1 && !$settingsForm.find('.jpsm-settings-tabs').length) {
            var $headings = $settingsForm.children('h2');
            var $firstHeading = $headings.first();
            var $actions = $('<div class="jpsm-settings-top-actions"></div>');
            var $tabs = $('<div class="jpsm-settings-tabs" role="tablist" aria-label="Secciones de configuración"></div>');
            var $panels = $('<div class="jpsm-settings-panels"></div>');
            var sections = [];

            var slugify = function (value) {
                return (value || '').toString()
                    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '') || 'seccion';
            };

            $firstHeading.before($actions);
            $actions.after($tabs);
            $tabs.after($panels);

            var $saveTopBtn = $('<button type="button" class="button button-primary jpsm-settings-save-btn">Guardar cambios</button>');
            var $saveTopHint = $('<span class="jpsm-settings-save-hint">Aplica a todas las pestañas.</span>');
            $actions.append($saveTopBtn, $saveTopHint);

            $saveTopBtn.on('click', function () {
                $settingsForm.trigger('submit');
            });

            $headings.each(function (idx) {
                var $heading = $(this);
                var title = $.trim($heading.text()) || ('Sección ' + (idx + 1));
                var slug = slugify(title) + '-' + idx;
                var panelId = 'jpsm-settings-panel-' + slug;
                var tabId = 'jpsm-settings-tab-' + slug;
                var $panel = $('<section class="jpsm-settings-panel" role="tabpanel"></section>');
                var $btn = $('<button type="button" class="jpsm-settings-tab" role="tab"></button>');
                var $nodes = $heading.nextUntil('h2').addBack();

                $panel.attr({
                    id: panelId,
                    'aria-labelledby': tabId,
                    hidden: 'hidden'
                });
                $btn.attr({
                    id: tabId,
                    'aria-controls': panelId,
                    'aria-selected': 'false',
                    tabindex: '-1',
                    'data-panel': panelId
                }).text(title);

                $panel.append($nodes);
                $panels.append($panel);
                $tabs.append($btn);
                sections.push({ panel: $panel, button: $btn, id: panelId });
            });

            var activateSection = function (index, moveFocus) {
                if (!sections.length) return;
                var safeIndex = Math.max(0, Math.min(index, sections.length - 1));
                sections.forEach(function (section, idx) {
                    var active = idx === safeIndex;
                    section.panel.toggleClass('is-active', active);
                    section.panel.attr('hidden', active ? null : 'hidden');
                    section.button.toggleClass('is-active', active);
                    section.button.attr('aria-selected', active ? 'true' : 'false');
                    section.button.attr('tabindex', active ? '0' : '-1');
                });

                if (moveFocus) {
                    sections[safeIndex].button.trigger('focus');
                }

                try {
                    window.sessionStorage.setItem('jpsm_settings_active_tab', sections[safeIndex].id);
                } catch (e) { }
            };

            $tabs.on('click', '.jpsm-settings-tab', function () {
                var idx = sections.findIndex(function (section) {
                    return section.button[0] === this;
                }.bind(this));
                if (idx >= 0) activateSection(idx, false);
            });

            $tabs.on('keydown', '.jpsm-settings-tab', function (e) {
                var current = sections.findIndex(function (section) {
                    return section.button[0] === this;
                }.bind(this));
                if (current < 0) return;

                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    activateSection((current + 1) % sections.length, true);
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    activateSection((current - 1 + sections.length) % sections.length, true);
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    activateSection(0, true);
                } else if (e.key === 'End') {
                    e.preventDefault();
                    activateSection(sections.length - 1, true);
                }
            });

            var activeFromSession = null;
            try {
                activeFromSession = window.sessionStorage.getItem('jpsm_settings_active_tab');
            } catch (e) { }
            var initialIdx = sections.findIndex(function (section) {
                return section.id === activeFromSession;
            });
            activateSection(initialIdx >= 0 ? initialIdx : 0, false);
        }
    }

    if ($('#jpsm_access_key').closest('form').length || $('#jpsm-freeze-prices').length) {
        $('#jpsm-freeze-prices').on('click', function () {
            var $btn = $(this);
            var $status = $('#freeze-status');

            if (!confirm('¿Estás seguro? Esto fijará los precios actuales en todos los registros que hoy están en cero.')) return;

            $btn.prop('disabled', true).text('Procesando... ❄️');
            $status.text('Actualizando historial...').css('color', '#666');

	            $.post(jpsm_data.ajax_url, {
	                action: 'jpsm_freeze_prices',
	                nonce: nonceFor('sales')
	            })
                .done(function (response) {
                    if (response.success) {
                        $status.text('✅ ' + response.data).css('color', 'green');
                        $btn.text('Fijado correctamente').addClass('button-disabled');
                    } else {
                        $status.text('❌ Error: ' + response.data).css('color', 'red');
                        $btn.prop('disabled', false).text('Fijar Precios en Historial ❄️');
                    }
                })
	                .fail(function (jqXHR) {
	                    $status.text('❌ ' + extractAjaxError(jqXHR)).css('color', 'red');
	                    $btn.prop('disabled', false).text('Fijar Precios en Historial ❄️');
	                });
	        });
	    }

    // =====================================================
    // 3b. SETTINGS PAGE (B2 Connection Test)
    // =====================================================
    if ($('#jpsm-b2-test-connection').length) {
        $('#jpsm-b2-test-connection').on('click', function () {
            var $btn = $(this);
            var $status = $('#jpsm-b2-test-status');

            $btn.prop('disabled', true).text('Probando...');
            $status.text('Conectando...').css('color', '#666');

	            $.post(jpsm_data.ajax_url, {
	                action: 'jpsm_test_b2_connection',
	                nonce: nonceFor('index')
	            })
                .done(function (response) {
                    if (response && response.success) {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Conexión OK';
                        $status.text('✅ ' + msg).css('color', 'green');
                    } else {
                        var err = (response && response.data) ? response.data : 'Error desconocido';
                        $status.text('❌ ' + err).css('color', 'red');
                    }
                })
	                .fail(function (jqXHR) {
	                    $status.text('❌ ' + extractAjaxError(jqXHR)).css('color', 'red');
	                })
	                .always(function () {
	                    $btn.prop('disabled', false).text('Probar conexión');
	                });
	        });
	    }

    // =====================================================
    // 4. SYNCHRONIZER PAGE
    // =====================================================
    if ($('#jpsm-sync-btn').length) {
        function loadIndexStats() {
            if (!$('#jpsm-idx-total').length) return;
            $.get(jpsm_data.ajax_url, { action: 'jpsm_get_index_stats', nonce: nonceFor('index') }, function (response) {
                if (response.success) {
                    var s = response.data;
                    $('#jpsm-idx-total').text(s.total.toLocaleString());
                    $('#jpsm-idx-audio').text(s.audio.toLocaleString());
                    $('#jpsm-idx-video').text(s.video.toLocaleString());
                    var health = (Number(s.total || 0) === 0) ? 'Empty' : (s.stale ? 'Stale' : 'Fresh');
                    $('#jpsm-idx-health').text(health);
                    $('#jpsm-idx-active-table').text(s.active_table || '-');
                    if (s.last_sync) {
                        var d = new Date(s.last_sync);
                        $('#jpsm-idx-lastsync').text(d.toLocaleString('es-MX', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }));
                    } else {
                        $('#jpsm-idx-lastsync').text('Nunca');
                    }
                    var q = (s && s.quality) ? s.quality : {};
                    $('#jpsm-idx-q-scanned').text(Number(q.scanned || 0).toLocaleString());
                    $('#jpsm-idx-q-inserted').text(Number(q.inserted || 0).toLocaleString());
                    $('#jpsm-idx-q-updated').text(Number(q.updated || 0).toLocaleString());
                    $('#jpsm-idx-q-skipped').text(Number(q.skipped_invalid || 0).toLocaleString());
                    $('#jpsm-idx-q-errors').text(Number(q.errors || 0).toLocaleString());

                    if ($('#jpsm-bpm-auto-status').length) {
                        var audioTotal = Number(s.audio || 0);
                        var audioWithBpm = Number(s.audio_with_bpm || 0);
                        var pending = Number(s.audio_pending_bpm_scan || 0);
                        var coverage = Number(s.bpm_coverage_pct || 0).toFixed(2);
                        $('#jpsm-bpm-auto-status')
                            .text('Cobertura BPM: ' + coverage + '% (' + audioWithBpm.toLocaleString() + '/' + audioTotal.toLocaleString() + '). Pendientes: ' + pending.toLocaleString() + '.')
                            .css('color', '#64748b');
                    }
                }
            });
        }
        loadIndexStats();

        $('#jpsm-sync-btn').on('click', function () {
            var $btn = $(this);
            var $status = $('#jpsm-sync-status');
            var $progressContainer = $('#jpsm-sync-progress-container');
            var $bar = $('#jpsm-sync-bar');

            $btn.prop('disabled', true).text('⏳ Sincronizando...');
            $status.text('Iniciando...');
            $progressContainer.show();
            $bar.css('width', '5%');

            var startTime = Date.now();
            var totalFiles = 0;

            function processBatch(token) {
                var payload = {
                    action: 'jpsm_sync_mediavault_index',
                    nonce: nonceFor('index'),
                    next_token: token
                };

                $.post(jpsm_data.ajax_url, payload)
                    .done(function (response) {
                        if (response.success) {
                            var info = response.data;
                            totalFiles += (info.count || 0);
                            var elapsed = (Date.now() - startTime) / 1000;
                            $status.text('Procesando... ' + totalFiles + ' archivos indexados.');

                            var currentWidth = parseFloat($bar[0].style.width) || 5;
                            if (currentWidth < 90) $bar.css('width', (currentWidth + 5) + '%');

                            if (!info.finished && info.next_token) {
                                processBatch(info.next_token);
                            } else {
                                $bar.css('width', '100%');
                                $status.text('✅ Completado: ' + totalFiles + ' archivos en ' + elapsed.toFixed(1) + 's (inválidos: ' + Number(info.skipped_invalid || 0).toLocaleString() + ', errores: ' + Number(info.errors || 0).toLocaleString() + ')').css('color', 'green');
                                $btn.prop('disabled', false).text('Iniciar Sincronización');
                                loadIndexStats();
                            }
                        } else {
                            $status.text('❌ Error: ' + (response.data || 'Desconocido')).css('color', 'red');
                            $btn.prop('disabled', false).text('Reintentar');
                        }
	                    })
	                    .fail(function (jqXHR) {
	                        $status.text('❌ ' + extractAjaxError(jqXHR)).css('color', 'red');
	                        $btn.prop('disabled', false).text('Reintentar');
	                    });
	            }
            processBatch(null);
        });

        $('#jpsm-bpm-import-btn').on('click', function () {
            var $btn = $(this);
            var $status = $('#jpsm-bpm-import-status');
            var fileInput = $('#jpsm-bpm-csv-file')[0];
            if (!fileInput || !fileInput.files || !fileInput.files.length) {
                $status.text('Selecciona un archivo CSV primero.').css('color', '#b45309');
                return;
            }

            var formData = new FormData();
            formData.append('action', 'jpsm_import_bpm_csv');
            formData.append('nonce', nonceFor('index'));
            formData.append('bpm_csv', fileInput.files[0]);
            formData.append('api_version', '2');

            $btn.prop('disabled', true).text('Importando...');
            $status.text('Procesando CSV...').css('color', '#64748b');

            $.ajax({
                url: jpsm_data.ajax_url,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 120000
            }).done(function (res) {
                var payload = null;
                if (res && res.success && res.data && res.data.data) {
                    payload = res.data.data;
                } else if (res && res.success && res.data) {
                    payload = res.data;
                }

                if (!res || !res.success || !payload) {
                    var msg = (res && res.data && res.data.message) ? res.data.message : (res && res.data ? res.data : 'Error desconocido');
                    $status.text('❌ ' + msg).css('color', '#dc2626');
                    return;
                }

                var processed = Number(payload.processed_rows || 0).toLocaleString();
                var upserted = Number(payload.upserted || 0).toLocaleString();
                var invalid = Number(payload.invalid_rows || 0).toLocaleString();
                $status.text('✅ Importado. Filas: ' + processed + ', guardadas: ' + upserted + ', inválidas: ' + invalid + '.').css('color', '#15803d');
                loadIndexStats();
            }).fail(function (jqXHR) {
                $status.text('❌ ' + extractAjaxError(jqXHR)).css('color', '#dc2626');
            }).always(function () {
                $btn.prop('disabled', false).text('Importar BPM CSV');
            });
        });

        $('#jpsm-bpm-auto-btn').on('click', function () {
            var $btn = $(this);
            var $status = $('#jpsm-bpm-auto-status');
            var running = true;
            var totalScanned = 0;
            var totalDetected = 0;
            var totalNoBpm = 0;
            var totalUnsupported = 0;
            var totalErrors = 0;
            var fallbackWarning = '';

            function parsePayload(res) {
                if (res && res.success && res.data && res.data.data) {
                    return res.data.data;
                }
                if (res && res.success && res.data) {
                    return res.data;
                }
                return null;
            }

            function runBatch() {
                if (!running) return;

                $.post(jpsm_data.ajax_url, {
                    action: 'jpsm_auto_detect_bpm_batch',
                    nonce: nonceFor('index'),
                    limit: 25,
                    mode: 'deep',
                    api_version: '2'
                }).done(function (res) {
                    var payload = parsePayload(res);

                    if (!res || !res.success || !payload) {
                        var msg = (res && res.data && res.data.message) ? res.data.message : (res && res.data ? res.data : 'Error desconocido');
                        $status.text('❌ ' + msg).css('color', '#dc2626');
                        running = false;
                        $btn.prop('disabled', false).text('Reanudar extracción automática');
                        return;
                    }

                    totalScanned += Number(payload.scanned || 0);
                    totalDetected += Number(payload.detected || 0);
                    totalNoBpm += Number(payload.no_bpm || 0);
                    totalUnsupported += Number(payload.unsupported || 0);
                    totalErrors += Number(payload.errors || 0);

                    var remaining = Number(payload.remaining || 0);
                    var done = !!payload.done;
                    var firstError = payload.first_error ? String(payload.first_error) : '';
                    var warnings = Array.isArray(payload.warnings) ? payload.warnings : [];
                    if (!fallbackWarning && payload.requested_mode === 'deep' && payload.mode === 'meta' && warnings.length) {
                        fallbackWarning = String(warnings[0] || '');
                    }
                    $status.text(
                        'Procesados: ' + totalScanned.toLocaleString()
                        + ' | Detectados: ' + totalDetected.toLocaleString()
                        + ' | Sin tag BPM: ' + totalNoBpm.toLocaleString()
                        + ' | No soportados: ' + totalUnsupported.toLocaleString()
                        + ' | Errores: ' + totalErrors.toLocaleString()
                        + ' | Pendientes: ' + remaining.toLocaleString()
                    ).css('color', '#334155');

                    if (totalErrors > 0 && totalDetected === 0 && firstError) {
                        $status.append(' | Causa probable: ' + firstError);
                    }
                    if (fallbackWarning) {
                        $status.append(' | Aviso: ' + fallbackWarning + ' (continuando en modo metadata)');
                    }

                    if (done) {
                        running = false;
                        $btn.prop('disabled', false).text('Volver a escanear BPM');
                        $status.text(
                            '✅ Extracción finalizada. Detectados: ' + totalDetected.toLocaleString()
                            + '. Sin BPM en metadato: ' + totalNoBpm.toLocaleString()
                            + '. No soportados: ' + totalUnsupported.toLocaleString()
                            + '. Errores: ' + totalErrors.toLocaleString()
                            + (firstError ? '. Causa probable: ' + firstError : '.')
                            + (fallbackWarning ? ' Aviso: ' + fallbackWarning + ' (se usó modo metadata).' : '')
                        ).css('color', '#15803d');
                        loadIndexStats();
                        return;
                    }

                    setTimeout(runBatch, 80);
                }).fail(function (jqXHR) {
                    running = false;
                    $status.text('❌ ' + extractAjaxError(jqXHR)).css('color', '#dc2626');
                    $btn.prop('disabled', false).text('Reanudar extracción automática');
                });
            }

            $btn.prop('disabled', true).text('Preparando...');
            $status.text('Reiniciando marcas previas para análisis profundo BPM...').css('color', '#64748b');

            $.post(jpsm_data.ajax_url, {
                action: 'jpsm_reset_auto_bpm_scan_marks',
                nonce: nonceFor('index'),
                api_version: '2'
            }).done(function (res) {
                var payload = parsePayload(res);
                if (!res || !res.success || !payload) {
                    var msg = (res && res.data && res.data.message) ? res.data.message : (res && res.data ? res.data : 'Error desconocido');
                    $status.text('❌ No se pudieron reiniciar marcas: ' + msg).css('color', '#dc2626');
                    $btn.prop('disabled', false).text('Iniciar extracción automática');
                    running = false;
                    return;
                }

                var resetTotal = Number(payload.total || 0).toLocaleString();
                $btn.text('Extrayendo...');
                $status.text('Marcas reiniciadas: ' + resetTotal + '. Iniciando lotes de extracción BPM profunda...').css('color', '#334155');
                runBatch();
            }).fail(function (jqXHR) {
                running = false;
                $status.text('❌ No se pudieron reiniciar marcas: ' + extractAjaxError(jqXHR)).css('color', '#dc2626');
                $btn.prop('disabled', false).text('Iniciar extracción automática');
            });
        });

        $('#jpsm-desktop-token-issue-btn').on('click', function () {
            var $btn = $(this);
            var $status = $('#jpsm-desktop-token-status');
            var $value = $('#jpsm-desktop-token-value');

            $btn.prop('disabled', true).text('Generando...');
            $status.text('Generando token...').css('color', '#64748b');
            $value.text('');

            $.post(jpsm_data.ajax_url, {
                action: 'jpsm_desktop_issue_token',
                nonce: nonceFor('index'),
                api_version: '2'
            }).done(function (res) {
                var payload = null;
                if (res && res.success && res.data && res.data.data) {
                    payload = res.data.data;
                } else if (res && res.success && res.data) {
                    payload = res.data;
                }

                if (!res || !res.success || !payload || !payload.token) {
                    var msg = (res && res.data && res.data.message) ? res.data.message : (res && res.data ? res.data : 'Error desconocido');
                    $status.text('❌ ' + msg).css('color', '#dc2626');
                    return;
                }

                $value.text(payload.token);
                $status.text('✅ Token generado. Guárdalo en la app desktop BPM.').css('color', '#15803d');
            }).fail(function (jqXHR) {
                $status.text('❌ ' + extractAjaxError(jqXHR)).css('color', '#dc2626');
            }).always(function () {
                $btn.prop('disabled', false).text('Generar token desktop');
            });
        });

        $('#jpsm-desktop-token-revoke-btn').on('click', function () {
            var $btn = $(this);
            var $status = $('#jpsm-desktop-token-status');
            var $value = $('#jpsm-desktop-token-value');

            if (!window.confirm('¿Seguro que quieres revocar el token desktop actual?')) {
                return;
            }
            if (!window.confirm('Confirmación final: la app desktop dejará de autenticar hasta generar un nuevo token. ¿Revocar?')) {
                return;
            }

            $btn.prop('disabled', true).text('Revocando...');
            $status.text('Revocando token...').css('color', '#64748b');

            $.post(jpsm_data.ajax_url, {
                action: 'jpsm_desktop_revoke_token',
                nonce: nonceFor('index'),
                api_version: '2'
            }).done(function (res) {
                if (res && res.success) {
                    $value.text('');
                    $status.text('✅ Token revocado.').css('color', '#15803d');
                    return;
                }
                var msg = (res && res.data && res.data.message) ? res.data.message : (res && res.data ? res.data : 'Error desconocido');
                $status.text('❌ ' + msg).css('color', '#dc2626');
            }).fail(function (jqXHR) {
                $status.text('❌ ' + extractAjaxError(jqXHR)).css('color', '#dc2626');
            }).always(function () {
                $btn.prop('disabled', false).text('Revocar token');
            });
        });
    }

    // =====================================================
    // 5. ACCESS CONTROL PAGE
    // =====================================================
    if ($('#jpsm-user-search-btn').length || $('#jpsm-load-folders-btn').length) {
        function getTierOptions() {
            if (typeof jpsm_data !== 'undefined' && Array.isArray(jpsm_data.tier_options) && jpsm_data.tier_options.length) {
                return jpsm_data.tier_options;
            }
            return [
                { value: 0, label: 'Demo' },
                { value: 1, label: 'Level 1: Básico' },
                { value: 2, label: 'Level 2: VIP + Básico' },
                { value: 3, label: 'Level 3: VIP + Videos' },
                { value: 4, label: 'Level 4: VIP + Pelis' },
                { value: 5, label: 'Level 5: Full' }
            ];
        }

        function renderTierSelectOptions(selectedTier) {
            return getTierOptions().map(function (opt) {
                var selected = parseInt(opt.value, 10) === parseInt(selectedTier, 10) ? ' selected' : '';
                return '<option value="' + opt.value + '"' + selected + '>' + opt.label + '</option>';
            }).join('');
        }

        // Search User
        $('#jpsm-user-search-btn').on('click', function () {
            var email = $('#jpsm-user-search').val();
            if (!email) return alert('Ingresa un email');
            $('#jpsm-user-result').html('Buscando...');
            $.ajax({
                url: jpsm_data.ajax_url,
                method: 'GET',
                dataType: 'json',
                timeout: 15000,
                data: { action: 'jpsm_get_user_tier', email: email, nonce: nonceFor('access') }
            })
                .done(function (res) {
                    if (res && res.success) {
                        var u = res.data;
                        var html = '<div style="background:#fff; padding:15px; border:1px solid #ccd0d4; margin-top:10px; border-radius:4px;">' +
                            '<strong>' + u.email + '</strong> (' + (u.is_customer ? 'Cliente' : 'Lead') + ')<br><br>' +
                            'Nivel Actual: <select id="jpsm-user-tier-select" data-email="' + u.email + '" style="margin-right:10px;">' +
                            renderTierSelectOptions(u.tier) +
                            '</select> <button id="jpsm-update-user-btn" class="button button-primary">Guardar Nivel</button>' +
                            '</div>';
                        $('#jpsm-user-result').html(html);
                    } else {
                        $('#jpsm-user-result').html('<p style="color:#d63638">Usuario no encontrado</p>');
                    }
                })
	                .fail(function (xhr, statusText, err) {
	                    // Common failure mode: backend emits warnings/notices and JSON parsing fails.
	                    // Don't leave the UI stuck in "Buscando...".
	                    console.error('[JPSM] get_user_tier failed', statusText, err);
	                    var msg = statusText === 'timeout'
	                        ? 'Tiempo de espera agotado'
	                        : extractAjaxError(xhr, 'Error de conexión o respuesta inválida');
	                    $('#jpsm-user-result').html('<p style="color:#d63638">❌ ' + escapeHtml(msg) + '</p>');
	                });
	        });

        $(document).on('click', '#jpsm-update-user-btn', function () {
            var $select = $('#jpsm-user-tier-select');
            var email = $select.data('email');
            var tier = $select.val();
            $.post(jpsm_data.ajax_url, {
                action: 'jpsm_update_user_tier',
                email: email,
                tier: tier,
                nonce: nonceFor('access')
            }, function (res) {
                if (res.success) alert('✅ Nivel de usuario actualizado');
                else alert('❌ Error: ' + res.data);
            });
        });

        // Load Folders (MATRIX UI)
        $('#jpsm-load-folders-btn').on('click', function () {
            var $container = $('#jpsm-folder-list');
            $container.html('<p style="padding:15px;">Cargando configuración...</p>');

            $.get(jpsm_data.ajax_url, { action: 'jpsm_get_folders', nonce: nonceFor('access') }, function (res) {
                if (res.success) {
                    var folders = res.data; // {'path': [1, 3], ...}
                    window.jpsm_folders = folders; // cache

                    // Define Tiers
                    var tiers = [
                        { id: 1, name: 'Básico' },
                        { id: 2, name: 'VIP + Básico' },
                        { id: 3, name: 'VIP + Videos' },
                        { id: 4, name: 'VIP + Pelis' },
                        { id: 5, name: 'Full' }
                    ];

                    // Render Tabs
                    var html = '<div class="jpsm-tabs" style="display:flex; border-bottom:1px solid #ccc; margin-bottom:0;">';
                    tiers.forEach(function (t, idx) {
                        var active = idx === 0 ? 'border-bottom: 2px solid #2271b1; font-weight:bold; background:#f0f0f1;' : 'background:#fff;';
                        html += '<button type="button" class="jpsm-tab-btn" data-id="' + t.id + '" style="flex:1; padding:10px; border:none; cursor:pointer; ' + active + '">' + t.name + '</button>';
                    });
                    html += '</div>';

                    // Container for lists
                    html += '<div id="jpsm-tab-content" style="border:1px solid #ccc; border-top:none; max-height:500px; overflow-y:auto; background:#fff;"></div>';

                    $container.html(html);

                    // Initial Render (Tab 1)
                    renderFolderList(1);

                    // Tab Click Event
                    $('.jpsm-tab-btn').on('click', function () {
                        var tierId = $(this).data('id');
                        // Update UI
                        $('.jpsm-tab-btn').css({ borderBottom: 'none', fontWeight: 'normal', background: '#fff' });
                        $(this).css({ borderBottom: '2px solid #2271b1', fontWeight: 'bold', background: '#f0f0f1' });
                        renderFolderList(tierId);
                    });

                } else {
                    $container.html('<p style="padding:15px;">Error cargando datos.</p>');
                }
            });

            function renderFolderList(tierId) {
                var html = '<table class="widefat striped"><thead><tr>' +
                    '<th style="padding:10px;">Carpeta (2do Nivel)</th>' +
                    '<th style="width:100px; text-align:center;">Acceso</th>' +
                    '</tr></thead><tbody>';

                var count = 0;
                // Sort keys
                var keys = Object.keys(window.jpsm_folders).sort();

                keys.forEach(function (folder) {
                    count++;
                    var allowedTiers = window.jpsm_folders[folder] || [];
                    var isChecked = allowedTiers.includes(tierId);

                    // Specific logic for Full (Tier 5) - Always checked?
                    // User said "Flexibility", so let's allow toggling even for Full, 
                    // though typically Full sees all. 
                    // Let's assume explicit grant.

                    html += '<tr>' +
                        '<td style="padding:10px;">📁 ' + folder + '</td>' +
                        '<td style="text-align:center;">' +
                        '<input type="checkbox" class="jpsm-perm-check" data-folder="' + folder + '" data-tier="' + tierId + '" ' + (isChecked ? 'checked' : '') + ' style="transform:scale(1.5);">' +
                        '</td>' +
                        '</tr>';
                });

                if (count === 0) {
                    html += '<tr><td colspan="2" style="padding:20px; text-align:center;">No hay carpetas sincronizadas.</td></tr>';
                }

                html += '</tbody></table>';

                // Add "Select All" helper
                html = '<div style="padding:10px; background:#f6f7f7; border-bottom:1px solid #ddd; display:flex; justify-content:space-between; align-items:center;">' +
                    '<span>Mostrando carpetas para Tier: <strong>' + tierId + '</strong></span>' +
                    '</div>' + html;

                $('#jpsm-tab-content').html(html);
            }
        });

        // Handle Checkbox Change (Immediate Save)
        $(document).on('change', '.jpsm-perm-check', function () {
            var $chk = $(this);
            var folder = $chk.data('folder');
            var tierId = $chk.data('tier');
            var isChecked = $chk.is(':checked');

            // Update local cache
            var currentTiers = window.jpsm_folders[folder] || [];
            if (isChecked) {
                if (!currentTiers.includes(tierId)) currentTiers.push(tierId);
            } else {
                currentTiers = currentTiers.filter(t => t !== tierId);
            }
            window.jpsm_folders[folder] = currentTiers;

            // Optimistic UI interaction (Show spinner?)
            var $cell = $chk.parent();
            var originalHtml = $cell.html();
            // $cell.html('<span class="spinner is-active" style="float:none; margin:0;"></span>'); 
            // Replacing checkbox with spinner might be annoying if clicking fast.
            // Let's just do silent background save or small indicator.

            // Send AJAX
	            $.post(jpsm_data.ajax_url, {
	                action: 'jpsm_update_folder_tier',
	                folder: folder,
	                tiers: currentTiers,
	                nonce: nonceFor('access')
	            }).done(function (res) {
	                if (!res.success) {
	                    alert('Error guardando: ' + folder);
	                    // Revert?
	                    $chk.prop('checked', !isChecked);
	                }
	            }).fail(function (jqXHR) {
	                alert('❌ ' + extractAjaxError(jqXHR));
	                $chk.prop('checked', !isChecked);
	            });
	        });

        // Load Leads
        $('#jpsm-load-leads-btn').on('click', function () {
            $('#jpsm-leads-list').html('<p style="padding:15px;">Cargando leads...</p>');
            $.get(jpsm_data.ajax_url, { action: 'jpsm_get_leads', nonce: nonceFor('access') }, function (res) {
                if (res.success) {
                    var html = '<table class="widefat fixed striped"><thead><tr><th>Email</th><th>Registrado</th></tr></thead><tbody>';
                    $.each(res.data, function (i, lead) {
                        html += '<tr><td>' + lead.email + '</td><td>' + (lead.registered || '-') + '</td></tr>';
                    });
                    html += '</tbody></table>';
                    $('#jpsm-leads-list').html(html);
                } else {
                    $('#jpsm-leads-list').html('<p style="padding:15px;">Sin registros de leads.</p>');
                }
            });
        });

        // =====================================================
        // 6. MEDIAVAULT NAV ORDER (Sidebar + Home Screen)
        // =====================================================
        if ($('#jpsm-mv-nav-load').length) {
            var $list = $('#jpsm-mv-nav-list');
            var $status = $('#jpsm-mv-nav-status');
            var $save = $('#jpsm-mv-nav-save');

            function escHtml(str) {
                return (str || '').toString()
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function escAttr(str) {
                return escHtml(str);
            }

            function setStatus(text, color) {
                $status.text(text || '').css('color', color || 'var(--mv-text-muted)');
            }

            function renderNavList(payload) {
                var folders = (payload && payload.folders) ? payload.folders : [];
                $list.empty();

                if (!folders.length) {
                    $list.html('<li style="padding:14px; color: var(--mv-text-muted);">No hay carpetas disponibles. Asegúrate de tener el índice sincronizado.</li>');
                    $save.prop('disabled', true);
                    return;
                }

                folders.forEach(function (path, idx) {
                    var clean = (path || '').toString().replace(/^\/+|\/+$/g, '');
                    var name = clean.split('/').filter(Boolean).pop() || clean || 'Carpeta';
                    var safePath = escAttr(path);
                    var safeClean = escHtml(clean);
                    var safeName = escHtml(name);

                    var border = (idx === folders.length - 1) ? 'none' : '1px solid var(--mv-border)';
                    $list.append(
                        '<li class="jpsm-mv-nav-li" data-path="' + safePath + '" style="margin:0; padding:0; border-bottom:' + border + ';">' +
                        '<div style="display:flex; align-items:center; gap:10px; padding:10px 12px;">' +
                        '<span class="jpsm-mv-drag" title="Arrastrar" style="cursor:grab; user-select:none; color: var(--mv-text-muted); font-size:16px;">☰</span>' +
                        '<div style="min-width:0; flex:1;">' +
                        '<div style="font-weight:700; color: var(--mv-text);">📁 ' + safeName + '</div>' +
                        '<div style="font-size:12px; color: var(--mv-text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + safeClean + '</div>' +
                        '</div>' +
                        '</div>' +
                        '</li>'
                    );
                });

                try {
                    $list.sortable('destroy');
                } catch (e) { }

                $list.sortable({
                    axis: 'y',
                    handle: '.jpsm-mv-drag',
                    containment: 'parent',
                    tolerance: 'pointer'
                });

                $save.prop('disabled', false);
            }

            $('#jpsm-mv-nav-load').on('click', function () {
                setStatus('Cargando carpetas...', 'var(--mv-text-muted)');
                $list.html('<li style="padding:14px; color: var(--mv-text-muted);">Cargando...</li>');
                $save.prop('disabled', true);

	                $.get(jpsm_data.ajax_url, { action: 'jpsm_mv_get_sidebar_folders', nonce: nonceFor('mediavault') })
                    .done(function (res) {
                        if (res && res.success) {
                            renderNavList(res.data);
                            setStatus('Listo. Arrastra para reordenar y guarda.', 'var(--mv-text-muted)');
                        } else {
                            setStatus('Error cargando carpetas.', 'var(--mv-danger)');
                            $list.html('<li style="padding:14px; color: var(--mv-danger);">❌ Error: ' + ((res && res.data) ? res.data : 'Desconocido') + '</li>');
                        }
	                    })
	                    .fail(function (xhr) {
	                        console.error('[JPSM] mv nav load failed');
	                        var msg = extractAjaxError(xhr);
	                        setStatus('❌ ' + msg, 'var(--mv-danger)');
	                        $list.html('<li style="padding:14px; color: var(--mv-danger);">❌ ' + escHtml(msg) + '</li>');
	                    });
	            });

            $save.on('click', function () {
                var order = $list.find('li.jpsm-mv-nav-li').map(function () {
                    return $(this).data('path');
                }).get();

                if (!order.length) return;

                $save.prop('disabled', true);
                setStatus('Guardando...', 'var(--mv-text-muted)');

                $.post(jpsm_data.ajax_url, {
                    action: 'jpsm_mv_save_sidebar_order',
                    nonce: nonceFor('mediavault'),
                    order: order
                })
                    .done(function (res) {
                        if (res && res.success) {
                            setStatus('✅ Orden guardado.', 'var(--mv-success)');
                            // Reload from server to reflect any normalization/filtering.
                            $('#jpsm-mv-nav-load').trigger('click');
                        } else {
                            setStatus('❌ No se pudo guardar.', 'var(--mv-danger)');
                        }
                    })
	                    .fail(function (xhr) {
	                        console.error('[JPSM] mv nav save failed');
	                        var msg = extractAjaxError(xhr, 'Error de conexión al guardar');
	                        setStatus('❌ ' + msg, 'var(--mv-danger)');
	                    })
                    .always(function () {
                        $save.prop('disabled', false);
                    });
            });

            $('#jpsm-mv-nav-reset').on('click', function () {
                if (!confirm('¿Restablecer el orden a predeterminado (alfabético)?')) return;
                setStatus('Restableciendo...', 'var(--mv-text-muted)');
                $save.prop('disabled', true);

                $.post(jpsm_data.ajax_url, { action: 'jpsm_mv_reset_sidebar_order', nonce: nonceFor('mediavault') })
                    .done(function (res) {
                        if (res && res.success) {
                            renderNavList(res.data);
                            setStatus('Orden restablecido.', 'var(--mv-text-muted)');
                        } else {
                            setStatus('❌ No se pudo restablecer.', 'var(--mv-danger)');
                        }
                    })
	                    .fail(function (xhr) {
	                        console.error('[JPSM] mv nav reset failed');
	                        var msg = extractAjaxError(xhr);
	                        setStatus('❌ ' + msg, 'var(--mv-danger)');
	                    })
                    .always(function () {
                        $save.prop('disabled', false);
                    });
            });
        }
    }
});
