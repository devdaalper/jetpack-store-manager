<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * creating the database table on plugin activation.
 */
function jdd_install_stats_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'jdd_stats';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        folder_id varchar(100) NOT NULL,
        folder_name varchar(255) NOT NULL,
        downloaded_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(JPSM_PLUGIN_DIR . 'jetpack-store-manager.php', 'jdd_install_stats_table');

/**
 * Track a download.
 */
function jdd_track_download($folder_id, $folder_name)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'jdd_stats';

    $wpdb->insert(
        $table_name,
        array(
            'folder_id' => $folder_id,
            'folder_name' => $folder_name,
            'downloaded_at' => current_time('mysql')
        )
    );
}

/**
 * Register Admin Menu.
 */
function jdd_register_analytics_page()
{
    add_menu_page(
        'JDD Estadísticas',
        'JDD Estadísticas',
        'manage_options',
        'jdd-analytics',
        'jdd_render_analytics_page',
        'dashicons-chart-bar',
        30
    );
}
add_action('admin_menu', 'jdd_register_analytics_page');

/**
 * Render Admin Page.
 */
function jdd_render_analytics_page()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'jdd_stats';

    // Get Top 20
    $top_folders = $wpdb->get_results("
        SELECT folder_name, folder_id, COUNT(*) as count 
        FROM $table_name 
        GROUP BY folder_id 
        ORDER BY count DESC 
        LIMIT 20
    ");

    // Get Daily Stats for Chart (Last 30 days)
    $daily_stats = $wpdb->get_results("
        SELECT DATE(downloaded_at) as date, COUNT(*) as count 
        FROM $table_name 
        WHERE downloaded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(downloaded_at)
        ORDER BY date ASC
    ");

    $chart_labels = [];
    $chart_data = [];
    foreach ($daily_stats as $stat) {
        $chart_labels[] = $stat->date;
        $chart_data[] = $stat->count;
    }
    ?>
    <div class="wrap">
        <h1>📊 Estadísticas de Descargas (JetPack Drive)</h1>

        <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
            <!-- Chart Container -->
            <div style="flex: 2; min-width: 400px; background: #fff; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3>Descargas (Últimos 30 días)</h3>
                <canvas id="jddChart"></canvas>
            </div>

            <!-- Top List Container -->
            <div style="flex: 1; min-width: 300px; background: #fff; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3>🔥 Top 20 Carpetas</h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Carpeta</th>
                            <th>Descargas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($top_folders): ?>
                            <?php foreach ($top_folders as $folder): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($folder->folder_name); ?></strong><br>
                                        <small style="color:#888;"><?php echo esc_html($folder->folder_id); ?></small>
                                    </td>
                                    <td><?php echo esc_html($folder->count); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="2">No hay datos aún.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Load Chart.js from CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const ctx = document.getElementById('jddChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{
                        label: 'Descargas Diarias',
                        data: <?php echo json_encode($chart_data); ?>,
                        borderColor: '#0073aa',
                        backgroundColor: 'rgba(0, 115, 170, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });
        });
    </script>
    <?php
}
