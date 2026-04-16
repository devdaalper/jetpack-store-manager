<?php
namespace JetpackStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stats_Service
 *
 * Calcula agregados del dashboard (ventas, ingresos, charts).
 * Phase 5: mover logica pesada fuera de clases de UI/vistas.
 */
class Stats_Service
{
    /**
     * Agregados completos usados por el dashboard "mobile app" (shortcode + wrapper admin).
     *
     * @return array{
     *   log: array,
     *   sales_today: int,
     *   rev_today_mxn: float,
     *   rev_today_usd: float,
     *   rev_month_mxn: float,
     *   rev_month_usd: float,
     *   rev_total_mxn: float,
     *   rev_total_usd: float,
     *   lifetime_stats: array,
     *   package_bucket_labels: array,
     *   packages: array,
     *   regions: array,
     *   hourly_sales: array,
     *   weekday_averages: array,
     *   day_month_sales: array,
     *   top_customers: array,
     *   avg_ticket_mxn: float,
     *   avg_ticket_usd: float,
     *   unique_clients: int,
     *   recurring_clients: int,
     *   new_clients: int,
     *   folder_downloads: array,
     *   behavior_report: array,
     *   transfer_report: array
     * }
     */
    public static function get_frontend_dashboard_stats()
    {
        $log = self::get_sales_log();

        $sales_today = 0;
        $today_date = current_time('Y-m-d');
        $this_month = current_time('Y-m');

        $rev_today_mxn = 0.0;
        $rev_today_usd = 0.0;
        $rev_month_mxn = 0.0;
        $rev_month_usd = 0.0;
        $rev_total_mxn = 0.0;
        $rev_total_usd = 0.0;

        $lifetime_stats = class_exists(Sales::class)
            ? Sales::get_persistent_stats()
            : array(
                'total_sales' => 0,
                'rev_mxn' => 0,
                'rev_usd' => 0,
                'packages' => array('basic' => 0, 'vip' => 0, 'full' => 0),
            );

        $package_bucket_labels = self::get_package_bucket_labels();

        $packages = array('basic' => 0, 'vip' => 0, 'full' => 0);
        $regions = array('national' => 0, 'international' => 0);
        $hourly_sales = array_fill(0, 24, 0);
        $weekday_counts = array_fill(1, 7, 0);
        $weekday_dates = array_fill(1, 7, array());
        $customer_stats = array();

        // Ventas por dia del mes (1-31), acumulado en moneda de su registro (MXN/USD no se normaliza aqui).
        $day_month_sales = array_fill(1, 31, 0);

        foreach ($log as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $time = isset($entry['time']) ? (string) $entry['time'] : '';
            $region = isset($entry['region']) ? strtolower((string) $entry['region']) : '';

            // Amount (fallback a resolver de precios cuando el log es legacy / amount=0)
            $amt = 0.0;
            if (isset($entry['amount']) && floatval($entry['amount']) > 0) {
                $amt = floatval($entry['amount']);
            } elseif (class_exists(Sales::class)) {
                $amt = floatval(Sales::get_entry_price($entry));
            }

            $cur = isset($entry['currency'])
                ? (string) $entry['currency']
                : (($region === 'international') ? 'USD' : 'MXN');

            if ($time !== '' && strpos($time, $today_date) === 0) {
                $sales_today++;
                if ($cur === 'MXN') {
                    $rev_today_mxn += $amt;
                } else {
                    $rev_today_usd += $amt;
                }
            }

            if ($time !== '' && strpos($time, $this_month) === 0) {
                if ($cur === 'MXN') {
                    $rev_month_mxn += $amt;
                } else {
                    $rev_month_usd += $amt;
                }
            }

            if ($cur === 'MXN') {
                $rev_total_mxn += $amt;
            } else {
                $rev_total_usd += $amt;
            }

            // Package bucket counts
            $bucket = self::resolve_package_bucket($entry['package'] ?? '');
            if (isset($packages[$bucket])) {
                $packages[$bucket]++;
            }

            // Region counts
            if (isset($regions[$region])) {
                $regions[$region]++;
            }

            // Time distribution
            $timestamp = $time !== '' ? strtotime($time) : false;
            if ($timestamp !== false) {
                $hour = intval(date('H', $timestamp));
                if ($hour >= 0 && $hour <= 23) {
                    $hourly_sales[$hour]++;
                }

                $wday = intval(date('N', $timestamp)); // 1 (Mon) to 7 (Sun)
                $wdate = date('Y-m-d', $timestamp);
                if ($wday >= 1 && $wday <= 7) {
                    $weekday_counts[$wday]++;
                    $weekday_dates[$wday][$wdate] = true;
                }

                $day_num = intval(date('j', $timestamp)); // 1-31
                if ($day_num >= 1 && $day_num <= 31) {
                    $day_month_sales[$day_num] += $amt;
                }
            }

            // Customer stats
            $email = isset($entry['email']) ? (string) $entry['email'] : 'desconocido';
            if (!isset($customer_stats[$email])) {
                $customer_stats[$email] = array('count' => 0, 'total' => 0, 'mxn' => 0, 'usd' => 0);
            }

            $customer_stats[$email]['count']++;
            if ($cur === 'MXN') {
                $customer_stats[$email]['mxn'] += $amt;
            } else {
                $customer_stats[$email]['usd'] += $amt;
            }

            // Total ponderado para sort (USD aproximado a MXN)
            $customer_stats[$email]['total'] += ($cur === 'MXN') ? $amt : ($amt * 17);
        }

        // Weekday averages
        $weekday_averages = array_fill(1, 7, 0);
        for ($i = 1; $i <= 7; $i++) {
            $num_days = count($weekday_dates[$i]);
            $weekday_averages[$i] = ($num_days > 0) ? round($weekday_counts[$i] / $num_days, 1) : 0;
        }

        // KPIs
        $avg_ticket_mxn = ($regions['national'] > 0) ? $rev_total_mxn / $regions['national'] : 0;
        $avg_ticket_usd = ($regions['international'] > 0) ? $rev_total_usd / $regions['international'] : 0;

        $unique_clients = count($customer_stats);
        $recurring_clients = 0;
        foreach ($customer_stats as $c) {
            if (($c['count'] ?? 0) > 1) {
                $recurring_clients++;
            }
        }
        $new_clients = max(0, $unique_clients - $recurring_clients);

        // Top customers
        uasort($customer_stats, function ($a, $b) {
            $at = isset($a['total']) ? floatval($a['total']) : 0.0;
            $bt = isset($b['total']) ? floatval($b['total']) : 0.0;
            if ($at === $bt) {
                return 0;
            }
            return ($at < $bt) ? 1 : -1;
        });
        $top_customers = array_slice($customer_stats, 0, 5, true);
        $folder_downloads = self::get_folder_download_stats(30);
        $behavior_report = self::get_behavior_report_stats();
        $transfer_report = self::get_transfer_report_stats();

        return array(
            'log' => $log,
            'sales_today' => $sales_today,
            'rev_today_mxn' => $rev_today_mxn,
            'rev_today_usd' => $rev_today_usd,
            'rev_month_mxn' => $rev_month_mxn,
            'rev_month_usd' => $rev_month_usd,
            'rev_total_mxn' => $rev_total_mxn,
            'rev_total_usd' => $rev_total_usd,
            'lifetime_stats' => $lifetime_stats,
            'package_bucket_labels' => $package_bucket_labels,
            'packages' => $packages,
            'regions' => $regions,
            'hourly_sales' => $hourly_sales,
            'weekday_averages' => $weekday_averages,
            'day_month_sales' => $day_month_sales,
            'top_customers' => $top_customers,
            'avg_ticket_mxn' => $avg_ticket_mxn,
            'avg_ticket_usd' => $avg_ticket_usd,
            'unique_clients' => $unique_clients,
            'recurring_clients' => $recurring_clients,
            'new_clients' => $new_clients,
            'folder_downloads' => $folder_downloads,
            'behavior_report' => $behavior_report,
            'transfer_report' => $transfer_report,
        );
    }

    private static function get_sales_log()
    {
        $log = array();

        if (class_exists(Data_Layer::class)) {
            $log = Data_Layer::get_sales_log();
        } else {
            $log = get_option('jpsm_sales_log', array());
        }

        return is_array($log) ? $log : array();
    }

    private static function get_folder_download_stats($limit = 30)
    {
        $rows = array();
        $totals = array(
            'total_downloads' => 0,
            'unique_folders' => 0,
        );

        if (class_exists(Data_Layer::class)) {
            $rows = Data_Layer::get_top_folder_downloads($limit);
            $totals = Data_Layer::get_folder_download_totals();
        }

        $total_downloads = max(0, intval($totals['total_downloads'] ?? 0));
        $unique_folders = max(0, intval($totals['unique_folders'] ?? 0));

        $normalized_rows = array();
        $rank = 1;
        foreach ((array) $rows as $row) {
            $downloads = max(0, intval($row['downloads'] ?? 0));
            $demand_percent = ($total_downloads > 0)
                ? round(($downloads / $total_downloads) * 100, 1)
                : 0.0;

            $normalized_rows[] = array(
                'rank' => $rank,
                'folder_name' => isset($row['folder_name']) ? (string) $row['folder_name'] : '',
                'folder_path' => isset($row['folder_path']) ? (string) $row['folder_path'] : '',
                'downloads' => $downloads,
                'demand_percent' => $demand_percent,
                'last_download_at' => isset($row['last_download_at']) ? (string) $row['last_download_at'] : '',
            );
            $rank++;
        }

        $top1_share_percent = 0.0;
        if (!empty($normalized_rows)) {
            $top1_share_percent = floatval($normalized_rows[0]['demand_percent']);
        }

        return array(
            'total_downloads' => $total_downloads,
            'unique_folders' => $unique_folders,
            'top1_share_percent' => $top1_share_percent,
            'rows' => $normalized_rows,
        );
    }

    private static function get_package_bucket_labels()
    {
        if (class_exists(Domain_Model::class)) {
            $labels = Domain_Model::get_stats_bucket_labels();
            if (isset($labels['basic'], $labels['vip'], $labels['full'])) {
                return $labels;
            }
        }

        return array(
            'basic' => 'Básico',
            'vip' => 'VIP',
            'full' => 'Full',
        );
    }

    private static function resolve_package_bucket($package)
    {
        if (class_exists(Domain_Model::class)) {
            return Domain_Model::get_stats_bucket($package);
        }

        $pkg = mb_strtolower((string) $package);
        if (strpos($pkg, 'full') !== false) {
            return 'full';
        }
        if (strpos($pkg, 'vip') !== false) {
            return 'vip';
        }
        return 'basic';
    }

    private static function get_behavior_report_stats()
    {
        if (!class_exists(Behavior_Service::class)) {
            return array(
                'month' => current_time('Y-m'),
                'month_label' => current_time('Y-m'),
                'previous_month_label' => '',
                'yoy_month_label' => '',
                'filters' => array('tier' => 'all', 'region' => 'all', 'device_class' => 'all'),
                'summary' => array(
                    'search_total' => 0,
                    'search_zero_results_total' => 0,
                    'search_zero_results_rate' => 0,
                    'download_intent_total' => 0,
                    'download_granted_total' => 0,
                    'download_denied_total' => 0,
                    'download_intent_gap' => 0,
                ),
                'top_keywords' => array(),
                'top_zero_keywords' => array(),
                'top_downloads' => array(),
                'segment_matrix' => array(),
            );
        }

        return Behavior_Service::get_dashboard_behavior_report(current_time('Y-m'), array(
            'tier' => 'all',
            'region' => 'all',
            'device_class' => 'all',
        ));
    }

    private static function get_transfer_report_stats()
    {
        if (!class_exists(Behavior_Service::class)) {
            return array(
                'window' => 'month',
                'month' => current_time('Y-m'),
                'month_label' => current_time('Y-m'),
                'previous_month_label' => '',
                'yoy_month_label' => '',
                'filters' => array('tier' => 'all', 'region' => 'all', 'device_class' => 'all'),
                'top_folders_source' => 'behavior_primary',
                'top_folders_quality' => array(
                    'is_approx_global' => false,
                    'reason' => 'none',
                ),
                'kpis' => array(),
                'demand_kpis' => array(),
                'series_daily_90d' => array(),
                'series_monthly_absolute' => array(),
                'series_monthly_relative' => array(),
                'series_lifetime_absolute' => array(),
                'series_lifetime_relative' => array(),
                'top_folders_month' => array(),
                'coverage' => array(),
            );
        }

        return Behavior_Service::get_dashboard_transfer_report(current_time('Y-m'), array(
            'tier' => 'all',
            'region' => 'all',
            'device_class' => 'all',
        ));
    }
}

// Backward compatibility alias.
class_alias(Stats_Service::class, 'JPSM_Stats_Service');
