<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JPSM_Stats_Service
 *
 * Calcula agregados del dashboard (ventas, ingresos, charts).
 * Phase 5: mover logica pesada fuera de clases de UI/vistas.
 */
class JPSM_Stats_Service
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
     *   new_clients: int
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

        $lifetime_stats = class_exists('JPSM_Sales')
            ? JPSM_Sales::get_persistent_stats()
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
            } elseif (class_exists('JPSM_Sales')) {
                $amt = floatval(JPSM_Sales::get_entry_price($entry));
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
        );
    }

    private static function get_sales_log()
    {
        $log = array();

        if (class_exists('JPSM_Data_Layer')) {
            $log = JPSM_Data_Layer::get_sales_log();
        } else {
            $log = get_option('jpsm_sales_log', array());
        }

        return is_array($log) ? $log : array();
    }

    private static function get_package_bucket_labels()
    {
        if (class_exists('JPSM_Domain_Model')) {
            $labels = JPSM_Domain_Model::get_stats_bucket_labels();
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
        if (class_exists('JPSM_Domain_Model')) {
            return JPSM_Domain_Model::get_stats_bucket($package);
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
}

