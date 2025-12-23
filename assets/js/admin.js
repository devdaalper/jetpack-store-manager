/**
 * JetPack Store Manager - Admin Dashboard JS
 * 
 * This file handles ONLY the WordPress Admin Dashboard functionality.
 * The mobile frontend (shortcode) uses inline JS to avoid enqueue issues.
 */
jQuery(document).ready(function ($) {

    // =====================================================
    // ADMIN DASHBOARD: Stats & Charts (Dashboard page only)
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
    // ADMIN REGISTRATION PAGE: Form Handling (WP Admin only)
    // =====================================================

    // Only run on admin pages with the registration form
    // Guard: Don't attach if mobile inline JS already handles this (check for jpsm_vars)
    var $adminForm = $('#jpsm-registration-form');
    if ($adminForm.length && typeof jpsm_data !== 'undefined' && typeof jpsm_vars === 'undefined') {

        $adminForm.on('submit', function (e) {
            e.preventDefault();

            var $btn = $('#jpsm-submit-sale');
            var $msg = $('#jpsm-message');

            $btn.prop('disabled', true).text('Procesando...');

            // Build payload - include vip_subtype if VIP is selected
            var payload = {
                action: 'jpsm_process_sale',
                nonce: jpsm_data.nonce,
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
        // Show/Hide VIP Subtypes in Admin Form
        $('#package_type').on('change', function () {
            if ($(this).val() === 'vip') {
                $('#vip-subtype-row').slideDown();
            } else {
                $('#vip-subtype-row').slideUp();
            }
        });

        // Handle Price Freeze button
        $('#jpsm-freeze-prices').on('click', function () {
            var $btn = $(this);
            var $status = $('#freeze-status');

            if (!confirm('¿Estás seguro? Esto fijará los precios actuales en todos los registros que hoy están en cero.')) return;

            $btn.prop('disabled', true).text('Procesando... ❄️');
            $status.text('Actualizando historial...').css('color', '#666');

            $.post(jpsm_data.ajax_url, {
                action: 'jpsm_freeze_prices',
                nonce: jpsm_data.nonce
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
});
