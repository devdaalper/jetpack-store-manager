/**
 * JetPack Store Manager - Dashboard Charts (Frontend)
 *
 * Phase 5: extracted from inline <script> to keep templates clean.
 * Reads chart payload from `jpsm_vars.dashboard_stats`.
 */
(function () {
    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    var charts = {};

    function destroy(key) {
        if (charts[key] && typeof charts[key].destroy === 'function') {
            charts[key].destroy();
        }
        charts[key] = null;
    }

    function getStats() {
        if (typeof jpsm_vars === 'undefined') {
            return null;
        }
        return jpsm_vars.dashboard_stats || null;
    }

    function render() {
        var stats = getStats();
        if (!stats || typeof Chart === 'undefined') {
            return;
        }

        // Default Color Scheme for Light Mode (Media Vault)
        Chart.defaults.color = '#64748b'; // Slate 500
        Chart.defaults.borderColor = '#e2e8f0'; // Slate 200

        var pkgLabels = stats.package_bucket_labels || { basic: 'Básico', vip: 'VIP', full: 'Full' };
        var packages = stats.packages || { basic: 0, vip: 0, full: 0 };
        var regions = stats.regions || { national: 0, international: 0 };

        var weekdayAverages = Array.isArray(stats.weekday_averages) ? stats.weekday_averages : [];
        var hourlySales = Array.isArray(stats.hourly_sales) ? stats.hourly_sales : [];
        var dayMonthSales = Array.isArray(stats.day_month_sales) ? stats.day_month_sales : [];

        // Package distribution
        destroy('pkg');
        var ctxPkg = document.getElementById('jpsmMobileChartPackage');
        if (ctxPkg) {
            charts.pkg = new Chart(ctxPkg, {
                type: 'doughnut',
                data: {
                    labels: [pkgLabels.basic, pkgLabels.vip, pkgLabels.full],
                    datasets: [{
                        data: [packages.basic || 0, packages.vip || 0, packages.full || 0],
                        backgroundColor: ['#22d3ee', '#8b5cf6', '#0ea5e9'], // Cyan, Violet, Sky
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: { boxWidth: 12, color: '#0f172a', font: { size: 11, family: 'Inter' } }
                        }
                    }
                }
            });
        }

        // Weekday averages
        destroy('wday');
        var ctxWday = document.getElementById('jpsmMobileChartWeekday');
        if (ctxWday) {
            var wdayData = [];
            for (var i = 0; i < 7; i++) {
                wdayData.push(weekdayAverages[i] || 0);
            }
            charts.wday = new Chart(ctxWday, {
                type: 'bar',
                data: {
                    labels: ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
                    datasets: [{
                        label: 'Promedio Ventas',
                        data: wdayData,
                        backgroundColor: 'rgba(234, 88, 12, 0.7)', // Orange
                        borderColor: '#ea580c',
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        // Regions
        destroy('reg');
        var ctxReg = document.getElementById('jpsmMobileChartRegion');
        if (ctxReg) {
            charts.reg = new Chart(ctxReg, {
                type: 'bar',
                data: {
                    labels: ['Nac.', 'Int.'],
                    datasets: [{
                        label: 'Ventas',
                        data: [regions.national || 0, regions.international || 0],
                        backgroundColor: ['#16a34a', '#2563eb'], // Green, Blue
                        borderRadius: 4
                    }]
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#64748b' } },
                        x: { grid: { display: false }, ticks: { color: '#64748b' } }
                    }
                }
            });
        }

        // Hourly (even hours only, as before)
        destroy('hour');
        var ctxHour = document.getElementById('jpsmMobileChartHourly');
        if (ctxHour) {
            var hourLabels = ['00', '02', '04', '06', '08', '10', '12', '14', '16', '18', '20', '22'];
            var hourData = [];
            for (var h = 0; h < 24; h += 2) {
                hourData.push(hourlySales[h] || 0);
            }
            charts.hour = new Chart(ctxHour, {
                type: 'line',
                data: {
                    labels: hourLabels,
                    datasets: [{
                        label: 'Ventas',
                        data: hourData,
                        borderColor: '#ea580c', // Orange
                        backgroundColor: 'rgba(234, 88, 12, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointBackgroundColor: '#ea580c'
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#64748b' } },
                        x: { grid: { display: false }, ticks: { color: '#64748b' } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }

        // Day of month (1-31)
        destroy('dayMonth');
        var ctxDayMonth = document.getElementById('jpsmChartDayOfMonth');
        if (ctxDayMonth) {
            var dayLabels = [];
            for (var d = 1; d <= 31; d++) {
                dayLabels.push(String(d));
            }
            var dayData = [];
            for (var di = 0; di < 31; di++) {
                dayData.push(dayMonthSales[di] || 0);
            }
            charts.dayMonth = new Chart(ctxDayMonth, {
                type: 'bar',
                data: {
                    labels: dayLabels,
                    datasets: [{
                        label: 'Ventas Acumuladas',
                        data: dayData,
                        backgroundColor: '#f59e0b', // Amber
                        borderRadius: 2
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#64748b' } },
                        x: {
                            grid: { display: false },
                            ticks: { color: '#64748b', maxRotation: 0, autoSkip: true, autoSkipPadding: 10 }
                        }
                    }
                }
            });
        }
    }

    // Expose a callable hook so the tab-switcher can render when the stats tab becomes visible.
    window.jpsmRenderDashboardCharts = function () {
        try {
            render();
        } catch (e) {
            console.error('JPSM charts error', e);
        }
    };

    onReady(function () {
        var statsTab = document.getElementById('jpsm-tab-stats');
        if (statsTab && statsTab.style && statsTab.style.display !== 'none') {
            window.jpsmRenderDashboardCharts();
        }
    });
})();

