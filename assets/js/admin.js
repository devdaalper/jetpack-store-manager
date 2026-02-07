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
                            .html('✅ ' + response.data.message).slideDown();
                        $adminForm[0].reset();
                        setTimeout(function () { location.reload(); }, 2000);
                    } else {
                        $msg.removeClass('success').addClass('error')
                            .html('❌ ' + response.data).slideDown();
                    }
                })
                .fail(function () {
                    $btn.prop('disabled', false).text('Enviar y Registrar');
                    $msg.removeClass('success').addClass('error')
                        .html('❌ Error de conexión').slideDown();
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
    // 3. SETTINGS PAGE (Freeze Prices)
    // =====================================================
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
                .fail(function () {
                    $status.text('❌ Error de conexión').css('color', 'red');
                    $btn.prop('disabled', false).text('Fijar Precios en Historial ❄️');
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
                                $status.text('✅ Completado: ' + totalFiles + ' archivos en ' + elapsed.toFixed(1) + 's').css('color', 'green');
                                $btn.prop('disabled', false).text('Iniciar Sincronización');
                                loadIndexStats();
                            }
                        } else {
                            $status.text('❌ Error: ' + (response.data || 'Desconocido')).css('color', 'red');
                            $btn.prop('disabled', false).text('Reintentar');
                        }
                    })
                    .fail(function () {
                        $status.text('❌ Error de conexión').css('color', 'red');
                        $btn.prop('disabled', false).text('Reintentar');
                    });
            }
            processBatch(null);
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
            $.get(jpsm_data.ajax_url, { action: 'jpsm_get_user_tier', email: email, nonce: nonceFor('access') }, function (res) {
                if (res.success) {
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
            }).fail(function () {
                alert('Error de conexión');
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
    }
});
