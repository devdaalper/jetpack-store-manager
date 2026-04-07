<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Behavior analytics service for MediaVault usage telemetry.
 *
 * Tracks keyword/search usage and download intent-vs-success signals,
 * then exposes monthly MoM/YoY business reports.
 */
class JPSM_Behavior_Service
{
    const CRON_HOOK_DAILY_ROLLUP = 'jpsm_behavior_daily_rollup';

    private static $allowed_event_names = array(
        'search_executed',
        'search_zero_results',
        'download_file_click',
        'download_file_granted',
        'download_file_denied',
        'download_folder_click',
        'download_folder_granted',
        'download_folder_denied',
        'download_folder_completed',
        'preview_direct_opened',
        'preview_proxy_streamed',
    );

    /**
     * Wire background rollups.
     */
    public static function bootstrap()
    {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('init', array(__CLASS__, 'ensure_rollup_schedule'));
        add_action(self::CRON_HOOK_DAILY_ROLLUP, array(__CLASS__, 'run_daily_rollup'));
    }

    public static function get_allowed_event_names()
    {
        return self::$allowed_event_names;
    }

    /**
     * AJAX: track behavior event.
     */
    public static function track_behavior_event_ajax()
    {
        $auth = self::authorize_tracking_request();
        if (is_wp_error($auth)) {
            $status = ($auth->get_error_code() === 'invalid_nonce') ? 401 : 403;
            JPSM_API_Contract::send_wp_error($auth, 'No autorizado', 'unauthorized', $status);
        }

        $event = self::build_event_payload($_REQUEST);
        if (is_wp_error($event)) {
            JPSM_API_Contract::send_wp_error($event, 'Evento inválido', 'invalid_event', 422);
        }

        $result = class_exists('JPSM_Data_Layer')
            ? JPSM_Data_Layer::insert_behavior_event($event)
            : array('inserted' => false, 'duplicate' => false, 'event_uuid' => $event['event_uuid']);

        JPSM_API_Contract::send_success(
            array(
                'event_uuid' => $result['event_uuid'] ?? $event['event_uuid'],
                'inserted' => !empty($result['inserted']),
                'duplicate' => !empty($result['duplicate']),
            ),
            'event_tracked',
            'Evento registrado.',
            200
        );
    }

    /**
     * Passive server-side tracking (must never break product flows).
     */
    public static function track_event_passive($event_name, $fields = array())
    {
        try {
            $payload = is_array($fields) ? $fields : array();
            $payload['event_name'] = sanitize_key((string) $event_name);
            $event = self::build_event_payload($payload);
            if (is_wp_error($event) || !class_exists('JPSM_Data_Layer')) {
                return false;
            }

            $result = JPSM_Data_Layer::insert_behavior_event($event);
            return !empty($result['inserted']) || !empty($result['duplicate']);
        } catch (Throwable $t) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[JPSM Behavior] passive track failed: ' . $t->getMessage());
            }
            return false;
        }
    }

    /**
     * AJAX: behavior report for dashboard widgets.
     */
    public static function get_behavior_report_ajax()
    {
        $auth = self::authorize_admin_report_request();
        if (is_wp_error($auth)) {
            $status = ($auth->get_error_code() === 'invalid_nonce') ? 401 : 403;
            JPSM_API_Contract::send_wp_error($auth, 'No autorizado', 'unauthorized', $status);
        }

        $month = self::sanitize_month($_REQUEST['month'] ?? current_time('Y-m'));
        $filters = self::extract_filters($_REQUEST);

        $report = self::build_behavior_report($month, $filters);
        if (is_wp_error($report)) {
            JPSM_API_Contract::send_wp_error($report, 'No se pudo generar reporte', 'behavior_report_error', 500);
        }

        JPSM_API_Contract::send_success($report, 'behavior_report_fetched', 'Reporte cargado.', 200);
    }

    /**
     * AJAX: transfer report for dashboard widgets.
     */
    public static function get_transfer_report_ajax()
    {
        $auth = self::authorize_admin_report_request();
        if (is_wp_error($auth)) {
            $status = ($auth->get_error_code() === 'invalid_nonce') ? 401 : 403;
            JPSM_API_Contract::send_wp_error($auth, 'No autorizado', 'unauthorized', $status);
        }

        $month = self::sanitize_month($_REQUEST['month'] ?? current_time('Y-m'));
        $filters = self::extract_filters($_REQUEST);
        $window = self::sanitize_transfer_window($_REQUEST['window'] ?? 'month');

        $report = self::build_transfer_report($month, $filters, $window);
        if (is_wp_error($report)) {
            JPSM_API_Contract::send_wp_error($report, 'No se pudo generar reporte', 'transfer_report_error', 500);
        }

        JPSM_API_Contract::send_success($report, 'transfer_report_fetched', 'Reporte de transferencia cargado.', 200);
    }

    /**
     * AJAX: monthly CSV export.
     */
    public static function export_behavior_csv_ajax()
    {
        $auth = self::authorize_admin_report_request();
        if (is_wp_error($auth)) {
            $status = ($auth->get_error_code() === 'invalid_nonce') ? 401 : 403;
            JPSM_API_Contract::send_wp_error($auth, 'No autorizado', 'unauthorized', $status);
        }

        $month = self::sanitize_month($_REQUEST['month'] ?? current_time('Y-m'));
        $filters = self::extract_filters($_REQUEST);
        $report = self::build_behavior_report($month, $filters);
        if (is_wp_error($report)) {
            JPSM_API_Contract::send_wp_error($report, 'No se pudo generar CSV', 'behavior_export_error', 500);
        }

        $filename = 'jpsm-behavior-' . $month . '.csv';
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        $out = fopen('php://output', 'w');
        if ($out === false) {
            wp_die('No se pudo exportar CSV');
        }

        fputcsv($out, array('section', 'key', 'value'));
        fputcsv($out, array('meta', 'month', $report['month']));
        fputcsv($out, array('meta', 'month_label', $report['month_label']));
        fputcsv($out, array('meta', 'tier_filter', (string) ($report['filters']['tier'] ?? 'all')));
        fputcsv($out, array('meta', 'region_filter', (string) ($report['filters']['region'] ?? 'all')));
        fputcsv($out, array('meta', 'device_filter', (string) ($report['filters']['device_class'] ?? 'all')));

        foreach ((array) ($report['summary'] ?? array()) as $key => $value) {
            fputcsv($out, array('summary', (string) $key, is_scalar($value) ? (string) $value : wp_json_encode($value)));
        }

        fputcsv($out, array('keywords', 'query_norm', 'current_count', 'mom_delta', 'yoy_delta'));
        foreach ((array) ($report['top_keywords'] ?? array()) as $row) {
            fputcsv($out, array(
                'keywords',
                (string) ($row['query_norm'] ?? ''),
                intval($row['current_count'] ?? 0),
                intval($row['mom_delta'] ?? 0),
                intval($row['yoy_delta'] ?? 0),
            ));
        }

        fputcsv($out, array('zero_results', 'query_norm', 'count'));
        foreach ((array) ($report['top_zero_keywords'] ?? array()) as $row) {
            fputcsv($out, array(
                'zero_results',
                (string) ($row['query_norm'] ?? ''),
                intval($row['current_count'] ?? 0),
            ));
        }

        fputcsv($out, array('downloads', 'object_type', 'object_path', 'current_count', 'mom_delta', 'yoy_delta'));
        foreach ((array) ($report['top_downloads'] ?? array()) as $row) {
            fputcsv($out, array(
                'downloads',
                (string) ($row['object_type'] ?? ''),
                (string) ($row['object_path_norm'] ?? ''),
                intval($row['current_count'] ?? 0),
                intval($row['mom_delta'] ?? 0),
                intval($row['yoy_delta'] ?? 0),
            ));
        }

        fputcsv($out, array('segments', 'tier', 'region', 'device_class', 'search_count', 'download_count'));
        foreach ((array) ($report['segment_matrix'] ?? array()) as $row) {
            fputcsv($out, array(
                'segments',
                intval($row['tier'] ?? 0),
                (string) ($row['region'] ?? 'unknown'),
                (string) ($row['device_class'] ?? 'unknown'),
                intval($row['search_count'] ?? 0),
                intval($row['download_count'] ?? 0),
            ));
        }

        fclose($out);
        exit;
    }

    /**
     * AJAX: transfer CSV export.
     */
    public static function export_transfer_csv_ajax()
    {
        $auth = self::authorize_admin_report_request();
        if (is_wp_error($auth)) {
            $status = ($auth->get_error_code() === 'invalid_nonce') ? 401 : 403;
            JPSM_API_Contract::send_wp_error($auth, 'No autorizado', 'unauthorized', $status);
        }

        $month = self::sanitize_month($_REQUEST['month'] ?? current_time('Y-m'));
        $filters = self::extract_filters($_REQUEST);
        $window = self::sanitize_transfer_window($_REQUEST['window'] ?? 'month');
        $report = self::build_transfer_report($month, $filters, $window);
        if (is_wp_error($report)) {
            JPSM_API_Contract::send_wp_error($report, 'No se pudo generar CSV', 'transfer_export_error', 500);
        }

        $filename = 'jpsm-transfer-' . $month . '.csv';
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        $out = fopen('php://output', 'w');
        if ($out === false) {
            wp_die('No se pudo exportar CSV');
        }

        fputcsv($out, array('section', 'key', 'value'));
        fputcsv($out, array('meta', 'month', $report['month']));
        fputcsv($out, array('meta', 'month_label', $report['month_label']));
        fputcsv($out, array('meta', 'tier_filter', (string) ($report['filters']['tier'] ?? 'all')));
        fputcsv($out, array('meta', 'region_filter', (string) ($report['filters']['region'] ?? 'all')));
        fputcsv($out, array('meta', 'device_filter', (string) ($report['filters']['device_class'] ?? 'all')));
        fputcsv($out, array('meta_extended', 'window', (string) ($report['window'] ?? 'month')));
        fputcsv($out, array('meta_extended', 'top_folders_source', (string) ($report['top_folders_source'] ?? 'behavior_primary')));
        fputcsv($out, array('meta_extended', 'top_folders_quality_is_approx_global', !empty($report['top_folders_quality']['is_approx_global']) ? '1' : '0'));
        fputcsv($out, array('meta_extended', 'top_folders_quality_reason', (string) ($report['top_folders_quality']['reason'] ?? 'none')));

        foreach ((array) ($report['kpis'] ?? array()) as $key => $value) {
            fputcsv($out, array('kpis', (string) $key, is_scalar($value) ? (string) $value : wp_json_encode($value)));
        }

        foreach ((array) ($report['demand_kpis'] ?? array()) as $key => $value) {
            fputcsv($out, array('demand_kpis', (string) $key, ($value === null) ? 'N/A' : (is_scalar($value) ? (string) $value : wp_json_encode($value))));
        }

        fputcsv($out, array('daily_90d', 'day_date', 'event_count', 'bytes_authorized', 'bytes_observed'));
        foreach ((array) ($report['series_daily_90d'] ?? array()) as $row) {
            fputcsv($out, array(
                'daily_90d',
                (string) ($row['day_date'] ?? ''),
                intval($row['event_count'] ?? 0),
                intval($row['bytes_authorized'] ?? 0),
                intval($row['bytes_observed'] ?? 0),
            ));
        }

        fputcsv($out, array('monthly_absolute', 'month', 'event_count', 'bytes_authorized', 'bytes_observed'));
        foreach ((array) ($report['series_monthly_absolute'] ?? array()) as $row) {
            fputcsv($out, array(
                'monthly_absolute',
                (string) ($row['month'] ?? ''),
                intval($row['event_count'] ?? 0),
                intval($row['bytes_authorized'] ?? 0),
                intval($row['bytes_observed'] ?? 0),
            ));
        }

        fputcsv($out, array('monthly_relative', 'month', 'registered_users', 'bytes_per_registered_observed'));
        foreach ((array) ($report['series_monthly_relative'] ?? array()) as $row) {
            fputcsv($out, array(
                'monthly_relative',
                (string) ($row['month'] ?? ''),
                intval($row['registered_users'] ?? 0),
                floatval($row['bytes_per_registered_observed'] ?? 0),
            ));
        }

        fputcsv($out, array('lifetime_absolute', 'month', 'cum_bytes_authorized', 'cum_bytes_observed'));
        foreach ((array) ($report['series_lifetime_absolute'] ?? array()) as $row) {
            fputcsv($out, array(
                'lifetime_absolute',
                (string) ($row['month'] ?? ''),
                intval($row['cum_bytes_authorized'] ?? 0),
                intval($row['cum_bytes_observed'] ?? 0),
            ));
        }

        fputcsv($out, array('lifetime_relative', 'month', 'registered_users', 'cum_bytes_per_registered_observed'));
        foreach ((array) ($report['series_lifetime_relative'] ?? array()) as $row) {
            fputcsv($out, array(
                'lifetime_relative',
                (string) ($row['month'] ?? ''),
                intval($row['registered_users'] ?? 0),
                floatval($row['cum_bytes_per_registered_observed'] ?? 0),
            ));
        }

        fputcsv($out, array('top_folders_window', 'folder_path', 'downloads', 'bytes_authorized', 'bytes_observed', 'mom_delta', 'yoy_delta'));
        foreach ((array) ($report['top_folders_month'] ?? array()) as $row) {
            $bytes_authorized = array_key_exists('bytes_authorized', $row) ? $row['bytes_authorized'] : null;
            $bytes_observed = array_key_exists('bytes_observed', $row) ? $row['bytes_observed'] : null;
            $mom_delta = $row['mom_delta'] ?? null;
            $yoy_delta = $row['yoy_delta'] ?? null;
            fputcsv($out, array(
                'top_folders_window',
                (string) ($row['folder_path'] ?? ''),
                intval($row['downloads'] ?? 0),
                ($bytes_authorized === null) ? 'N/A' : intval($bytes_authorized),
                ($bytes_observed === null) ? 'N/A' : intval($bytes_observed),
                ($mom_delta === null) ? 'N/A' : intval($mom_delta),
                ($yoy_delta === null) ? 'N/A' : intval($yoy_delta),
            ));
        }

        foreach ((array) ($report['coverage'] ?? array()) as $key => $value) {
            fputcsv($out, array('coverage', (string) $key, is_scalar($value) ? (string) $value : wp_json_encode($value)));
        }

        fclose($out);
        exit;
    }

    /**
     * Server-side report used by dashboard initial render.
     */
    public static function get_dashboard_behavior_report($month = '', $filters = array())
    {
        $target_month = ($month !== '') ? self::sanitize_month($month) : self::sanitize_month(current_time('Y-m'));
        $report = self::build_behavior_report($target_month, self::extract_filters($filters));
        if (is_wp_error($report)) {
            return self::empty_behavior_report($target_month, self::extract_filters($filters));
        }
        return $report;
    }

    /**
     * Server-side transfer report used by dashboard initial render.
     */
    public static function get_dashboard_transfer_report($month = '', $filters = array())
    {
        $target_month = ($month !== '') ? self::sanitize_month($month) : self::sanitize_month(current_time('Y-m'));
        $safe_filters = self::extract_filters($filters);
        $window = self::sanitize_transfer_window($filters['window'] ?? 'month');
        $report = self::build_transfer_report($target_month, $safe_filters, $window);
        if (is_wp_error($report)) {
            return self::empty_transfer_report($target_month, $safe_filters, $window);
        }
        return $report;
    }

    public static function ensure_rollup_schedule()
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }

        if (wp_next_scheduled(self::CRON_HOOK_DAILY_ROLLUP)) {
            return;
        }

        $next = strtotime(current_time('Y-m-d') . ' 02:15:00');
        if (!$next || $next <= time()) {
            $next = time() + HOUR_IN_SECONDS;
        }
        wp_schedule_event($next, 'daily', self::CRON_HOOK_DAILY_ROLLUP);
    }

    public static function run_daily_rollup()
    {
        if (!class_exists('JPSM_Data_Layer')) {
            return;
        }

        $today = current_time('Y-m-d');
        $from = gmdate('Y-m-d', strtotime($today . ' -2 day'));
        JPSM_Data_Layer::rebuild_behavior_daily($from, $today);
    }

    public static function normalize_search_query($query)
    {
        $query = sanitize_text_field((string) $query);
        if ($query === '') {
            return '';
        }

        $query = mb_strtolower($query);
        if (function_exists('remove_accents')) {
            $query = remove_accents($query);
        }

        $query = preg_replace('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', '[email]', $query);
        $query = preg_replace('/\+?\d[\d\s\-()]{7,}\d/', '[phone]', $query);
        $query = preg_replace('/[[:punct:]]+/u', ' ', (string) $query);
        $query = preg_replace('/\s+/', ' ', (string) $query);
        return trim((string) $query);
    }

    public static function normalize_object_path($path)
    {
        $path = sanitize_text_field((string) $path);
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }

        $has_trailing = substr($path, -1) === '/';
        $path = preg_replace('#/+#', '/', $path);
        $path = ltrim((string) $path, '/');
        if ($path === '') {
            return '';
        }

        if ($has_trailing) {
            $path = rtrim((string) $path, '/') . '/';
        }

        return $path;
    }

    public static function hash_identity($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $salt = '';
        if (function_exists('wp_salt')) {
            $salt = (string) wp_salt('auth');
        }
        if ($salt === '' && defined('AUTH_KEY')) {
            $salt = (string) AUTH_KEY;
        }
        if ($salt === '') {
            $salt = 'jpsm-behavior-default-salt';
        }

        return hash_hmac('sha256', mb_strtolower($value), $salt);
    }

    public static function normalize_transfer_bytes($value)
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return 0;
            }
        }

        if (!is_numeric($value)) {
            return 0;
        }

        $bytes = (int) floor((float) $value);
        return max(0, $bytes);
    }

    public static function detect_device_class($ua = '')
    {
        $ua = ($ua !== '') ? $ua : (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ua = mb_strtolower((string) $ua);

        if ($ua === '') {
            return 'unknown';
        }

        if (preg_match('/ipad|tablet|kindle|silk/', $ua)) {
            return 'tablet';
        }

        if (preg_match('/mobile|iphone|ipod|android|iemobile|opera mini|mobi/', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }

    public static function sanitize_month($value)
    {
        $month = sanitize_text_field((string) $value);
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $month;
        }
        return current_time('Y-m');
    }

    public static function sanitize_transfer_window($value)
    {
        $window = sanitize_key((string) $value);
        if (!in_array($window, array('month', 'prev_month', 'rolling_90d', 'lifetime'), true)) {
            return 'month';
        }
        return $window;
    }

    private static function authorize_tracking_request()
    {
        if (!class_exists('JPSM_Auth')) {
            return new WP_Error('auth_unavailable', 'Auth service unavailable');
        }

        return JPSM_Auth::authorize_request(array(
            'require_nonce' => true,
            'nonce_actions' => array('jpsm_nonce', 'jpsm_mediavault_nonce', 'jpsm_access_nonce'),
            'allow_admin' => true,
            'allow_secret_key' => false,
            'allow_user_session' => true,
        ));
    }

    private static function authorize_admin_report_request()
    {
        if (!class_exists('JPSM_Auth')) {
            return new WP_Error('auth_unavailable', 'Auth service unavailable');
        }

        return JPSM_Auth::authorize_request(array(
            'require_nonce' => true,
            'nonce_actions' => array('jpsm_nonce', 'jpsm_access_nonce', 'jpsm_mediavault_nonce', 'jpsm_sales_nonce'),
            'allow_admin' => true,
            'allow_secret_key' => true,
            'allow_user_session' => false,
        ));
    }

    private static function extract_filters($source)
    {
        if (!is_array($source)) {
            $source = array();
        }

        $tier = $source['tier'] ?? 'all';
        $region = sanitize_key((string) ($source['region'] ?? 'all'));
        $device = sanitize_key((string) ($source['device_class'] ?? 'all'));

        if ($tier === '' || $tier === null || $tier === 'all') {
            $tier = 'all';
        } else {
            $tier = intval($tier);
        }

        if (!in_array($region, array('all', 'national', 'international', 'unknown'), true)) {
            $region = 'all';
        }

        if (!in_array($device, array('all', 'mobile', 'tablet', 'desktop', 'unknown'), true)) {
            $device = 'all';
        }

        return array(
            'tier' => $tier,
            'region' => $region,
            'device_class' => $device,
        );
    }

    private static function month_range($month)
    {
        $month = self::sanitize_month($month);
        $start = $month . '-01';
        $next_start = gmdate('Y-m-d', strtotime($start . ' +1 month'));
        $end = gmdate('Y-m-d', strtotime($next_start . ' -1 day'));

        return array(
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'next_start' => $next_start,
            'label' => function_exists('wp_date')
                ? wp_date('F Y', strtotime($start))
                : date('F Y', strtotime($start)),
        );
    }

    private static function transfer_window_context($month, $window)
    {
        $window = self::sanitize_transfer_window($window);
        $selected = self::month_range($month);
        $current = $selected;

        if ($window === 'prev_month') {
            $current = self::month_range(gmdate('Y-m', strtotime($selected['start'] . ' -1 month')));
        } elseif ($window === 'rolling_90d') {
            $start = gmdate('Y-m-d', strtotime($selected['end'] . ' -89 day'));
            if ($start === false) {
                $start = $selected['start'];
            }
            $current = array(
                'month' => $selected['month'],
                'start' => $start,
                'end' => $selected['end'],
                'next_start' => gmdate('Y-m-d', strtotime($selected['end'] . ' +1 day')),
                'label' => 'Últimos 90 días (hasta ' . $selected['label'] . ')',
            );
        } elseif ($window === 'lifetime') {
            $current = array(
                'month' => $selected['month'],
                'start' => '2000-01-01',
                'end' => $selected['end'],
                'next_start' => gmdate('Y-m-d', strtotime($selected['end'] . ' +1 day')),
                'label' => 'Lifetime (hasta ' . $selected['label'] . ')',
            );
        }

        $supports_monthly_deltas = in_array($window, array('month', 'prev_month'), true);
        return array(
            'window' => $window,
            'selected' => $selected,
            'current' => $current,
            'supports_monthly_deltas' => $supports_monthly_deltas,
        );
    }

    private static function is_default_transfer_filters($filters)
    {
        if (!is_array($filters)) {
            return true;
        }

        return (($filters['tier'] ?? 'all') === 'all')
            && (($filters['region'] ?? 'all') === 'all')
            && (($filters['device_class'] ?? 'all') === 'all');
    }

    private static function build_event_payload($source)
    {
        if (!is_array($source)) {
            $source = array();
        }

        $event_name = sanitize_key((string) ($source['event_name'] ?? ''));
        if (!in_array($event_name, self::$allowed_event_names, true)) {
            return new WP_Error('invalid_event', 'Evento no permitido');
        }

        $query_raw = $source['query_norm'] ?? ($source['query'] ?? '');
        $query_norm = self::normalize_search_query($query_raw);

        $event_uuid = sanitize_text_field((string) ($source['event_uuid'] ?? ''));
        if ($event_uuid === '') {
            if (function_exists('wp_generate_uuid4')) {
                $event_uuid = (string) wp_generate_uuid4();
            } else {
                $hash = md5(uniqid('jpsm_behavior_', true));
                $event_uuid = substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
            }
        }

        $event_time = sanitize_text_field((string) ($source['event_time'] ?? ''));
        if ($event_time === '') {
            $event_time = current_time('mysql');
        }

        $email = '';
        if (class_exists('JPSM_Access_Manager')) {
            $email = (string) JPSM_Access_Manager::get_current_email();
        }

        $session_cookie_name = class_exists('JPSM_Auth') ? JPSM_Auth::USER_SESSION_COOKIE : 'jdd_access_token';
        $session_cookie = isset($_COOKIE[$session_cookie_name]) ? (string) $_COOKIE[$session_cookie_name] : '';

        $session_hash = sanitize_text_field((string) ($source['session_id_hash'] ?? ''));
        if ($session_hash === '') {
            $session_hash = self::hash_identity($session_cookie);
        }

        $user_hash = sanitize_text_field((string) ($source['user_id_hash'] ?? ''));
        if ($user_hash === '' && $email !== '') {
            $user_hash = self::hash_identity($email);
        }

        $tier = $source['tier'] ?? null;
        if ($tier === null || $tier === '' || $tier === 'all') {
            $tier = 0;
            if ($email !== '' && class_exists('JPSM_Access_Manager')) {
                $tier = intval(JPSM_Access_Manager::get_user_tier($email));
            }
        }
        $tier = intval($tier);

        $region = sanitize_key((string) ($source['region'] ?? ''));
        if (!in_array($region, array('national', 'international', 'unknown'), true)) {
            $region = self::resolve_region_for_email($email);
        }

        $device_class = sanitize_key((string) ($source['device_class'] ?? ''));
        if (!in_array($device_class, array('mobile', 'tablet', 'desktop', 'unknown'), true)) {
            $device_class = self::detect_device_class();
        }

        $source_screen = sanitize_key((string) ($source['source_screen'] ?? 'mediavault_vault'));
        if ($source_screen === '') {
            $source_screen = 'mediavault_vault';
        }

        $object_type = sanitize_key((string) ($source['object_type'] ?? ''));
        if (!in_array($object_type, array('file', 'folder', 'search', 'package'), true)) {
            $object_type = '';
        }

        $status = sanitize_key((string) ($source['status'] ?? ''));
        if (!in_array($status, array('success', 'denied', 'error', 'click', 'ok', ''), true)) {
            $status = '';
        }

        $meta_json = self::normalize_meta_json($source['meta'] ?? array());

        return array(
            'event_uuid' => $event_uuid,
            'event_name' => $event_name,
            'event_time' => $event_time,
            'session_id_hash' => $session_hash,
            'user_id_hash' => $user_hash,
            'tier' => $tier,
            'region' => $region,
            'device_class' => $device_class,
            'source_screen' => $source_screen,
            'query_norm' => $query_norm,
            'result_count' => max(0, intval($source['result_count'] ?? 0)),
            'object_type' => $object_type,
            'object_path_norm' => self::normalize_object_path($source['object_path_norm'] ?? ($source['object_path'] ?? '')),
            'status' => $status,
            'files_count' => max(0, intval($source['files_count'] ?? 0)),
            'bytes_authorized' => self::normalize_transfer_bytes($source['bytes_authorized'] ?? 0),
            'bytes_observed' => self::normalize_transfer_bytes($source['bytes_observed'] ?? 0),
            'meta_json' => $meta_json,
        );
    }

    private static function normalize_meta_json($meta)
    {
        if (!is_array($meta)) {
            return '';
        }

        $allow = array('error_code', 'search_type', 'source', 'note');
        $normalized = array();
        foreach ($allow as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            $value = sanitize_text_field((string) $meta[$key]);
            if ($value === '') {
                continue;
            }
            $normalized[$key] = mb_substr($value, 0, 120);
        }

        if (empty($normalized)) {
            return '';
        }

        if (function_exists('wp_json_encode')) {
            return (string) wp_json_encode($normalized);
        }
        return json_encode($normalized);
    }

    private static function resolve_region_for_email($email)
    {
        $email = sanitize_email((string) $email);
        if (!is_email($email) || !class_exists('JPSM_Data_Layer')) {
            return 'unknown';
        }

        $sales = JPSM_Data_Layer::get_sales_by_email($email);
        if (!is_array($sales) || empty($sales)) {
            return 'unknown';
        }

        $latest = $sales[0];
        $region = sanitize_key((string) ($latest['region'] ?? 'unknown'));
        if (!in_array($region, array('national', 'international'), true)) {
            return 'unknown';
        }

        return $region;
    }

    private static function maybe_warm_behavior_daily($from, $to)
    {
        if (!class_exists('JPSM_Data_Layer')) {
            return;
        }

        if (!JPSM_Data_Layer::behavior_daily_has_data($from, $to)) {
            JPSM_Data_Layer::rebuild_behavior_daily($from, $to);
            return;
        }

        $to_date = sanitize_text_field((string) $to);
        $from_date = sanitize_text_field((string) $from);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
            return;
        }

        $rolling_from = gmdate('Y-m-d', strtotime($to_date . ' -45 day'));
        if ($rolling_from === false || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rolling_from)) {
            $rolling_from = $from_date;
        }
        if ($rolling_from < $from_date) {
            $rolling_from = $from_date;
        }

        JPSM_Data_Layer::rebuild_behavior_daily($rolling_from, $to_date);
    }

    private static function to_map_by_key($rows, $key, $value_key = 'total_count')
    {
        $map = array();
        foreach ((array) $rows as $row) {
            $k = (string) ($row[$key] ?? '');
            if ($k === '') {
                continue;
            }
            $map[$k] = max(0, intval($row[$value_key] ?? 0));
        }
        return $map;
    }

    private static function build_download_map($rows)
    {
        $map = array();
        foreach ((array) $rows as $row) {
            $type = sanitize_key((string) ($row['object_type'] ?? ''));
            $path = (string) ($row['object_path_norm'] ?? '');
            if ($path === '') {
                continue;
            }
            $key = $type . '|' . $path;
            $map[$key] = max(0, intval($row['total_count'] ?? 0));
        }
        return $map;
    }

    private static function build_behavior_report($month, $filters)
    {
        if (!class_exists('JPSM_Data_Layer')) {
            return new WP_Error('data_layer_unavailable', 'Data layer unavailable');
        }

        $current = self::month_range($month);
        $prev = self::month_range(gmdate('Y-m', strtotime($current['start'] . ' -1 month')));
        $yoy = self::month_range(gmdate('Y-m', strtotime($current['start'] . ' -1 year')));

        self::maybe_warm_behavior_daily($yoy['start'], $current['end']);

        $top_keywords_current = JPSM_Data_Layer::get_behavior_top_keywords($current['start'], $current['end'], $filters, 30, false);
        $top_keywords_prev = JPSM_Data_Layer::get_behavior_top_keywords($prev['start'], $prev['end'], $filters, 200, false);
        $top_keywords_yoy = JPSM_Data_Layer::get_behavior_top_keywords($yoy['start'], $yoy['end'], $filters, 200, false);
        $top_zero_keywords = JPSM_Data_Layer::get_behavior_top_keywords($current['start'], $current['end'], $filters, 30, true);

        $prev_map = self::to_map_by_key($top_keywords_prev, 'query_norm');
        $yoy_map = self::to_map_by_key($top_keywords_yoy, 'query_norm');

        $keywords_rows = array();
        foreach ((array) $top_keywords_current as $row) {
            $query = (string) ($row['query_norm'] ?? '');
            if ($query === '') {
                continue;
            }

            $current_count = max(0, intval($row['total_count'] ?? 0));
            $mom_base = max(0, intval($prev_map[$query] ?? 0));
            $yoy_base = max(0, intval($yoy_map[$query] ?? 0));

            $keywords_rows[] = array(
                'query_norm' => $query,
                'current_count' => $current_count,
                'mom_delta' => $current_count - $mom_base,
                'yoy_delta' => $current_count - $yoy_base,
                'mom_base' => $mom_base,
                'yoy_base' => $yoy_base,
            );
        }

        $top_downloads_current = JPSM_Data_Layer::get_behavior_top_downloads($current['start'], $current['end'], $filters, 30);
        $top_downloads_prev = JPSM_Data_Layer::get_behavior_top_downloads($prev['start'], $prev['end'], $filters, 300);
        $top_downloads_yoy = JPSM_Data_Layer::get_behavior_top_downloads($yoy['start'], $yoy['end'], $filters, 300);

        $prev_download_map = self::build_download_map($top_downloads_prev);
        $yoy_download_map = self::build_download_map($top_downloads_yoy);

        $download_rows = array();
        foreach ((array) $top_downloads_current as $row) {
            $type = sanitize_key((string) ($row['object_type'] ?? ''));
            $path = (string) ($row['object_path_norm'] ?? '');
            if ($path === '') {
                continue;
            }

            $key = $type . '|' . $path;
            $current_count = max(0, intval($row['total_count'] ?? 0));
            $mom_base = max(0, intval($prev_download_map[$key] ?? 0));
            $yoy_base = max(0, intval($yoy_download_map[$key] ?? 0));

            $download_rows[] = array(
                'object_type' => $type,
                'object_path_norm' => $path,
                'current_count' => $current_count,
                'mom_delta' => $current_count - $mom_base,
                'yoy_delta' => $current_count - $yoy_base,
                'mom_base' => $mom_base,
                'yoy_base' => $yoy_base,
            );
        }

        $search_total = JPSM_Data_Layer::get_behavior_metric_sum(array('search_executed'), $current['start'], $current['end'], $filters);
        $zero_total = JPSM_Data_Layer::get_behavior_metric_sum(array('search_zero_results'), $current['start'], $current['end'], $filters);
        $download_intent_total = JPSM_Data_Layer::get_behavior_metric_sum(
            array('download_file_click', 'download_folder_click'),
            $current['start'],
            $current['end'],
            $filters
        );
        $download_granted_total = JPSM_Data_Layer::get_behavior_metric_sum(
            array('download_file_granted', 'download_folder_granted'),
            $current['start'],
            $current['end'],
            $filters
        );
        $download_denied_total = JPSM_Data_Layer::get_behavior_metric_sum(
            array('download_file_denied', 'download_folder_denied'),
            $current['start'],
            $current['end'],
            $filters
        );

        $zero_rate = ($search_total > 0) ? round(($zero_total / $search_total) * 100, 1) : 0.0;
        $download_gap = max(0, $download_intent_total - $download_granted_total);

        $search_segments = JPSM_Data_Layer::get_behavior_segment_preferences(
            $current['start'],
            $current['end'],
            $filters,
            array('search_executed'),
            500
        );
        $download_segments = JPSM_Data_Layer::get_behavior_segment_preferences(
            $current['start'],
            $current['end'],
            $filters,
            array('download_file_granted', 'download_folder_granted'),
            500
        );

        $segment_map = array();
        foreach ($search_segments as $row) {
            $key = intval($row['tier']) . '|' . $row['region'] . '|' . $row['device_class'];
            $segment_map[$key] = array(
                'tier' => intval($row['tier']),
                'region' => (string) $row['region'],
                'device_class' => (string) $row['device_class'],
                'search_count' => intval($row['total_count'] ?? 0),
                'download_count' => 0,
            );
        }
        foreach ($download_segments as $row) {
            $key = intval($row['tier']) . '|' . $row['region'] . '|' . $row['device_class'];
            if (!isset($segment_map[$key])) {
                $segment_map[$key] = array(
                    'tier' => intval($row['tier']),
                    'region' => (string) $row['region'],
                    'device_class' => (string) $row['device_class'],
                    'search_count' => 0,
                    'download_count' => 0,
                );
            }
            $segment_map[$key]['download_count'] += intval($row['total_count'] ?? 0);
        }

        usort($segment_map, function ($a, $b) {
            $score_a = intval($a['search_count']) + intval($a['download_count']);
            $score_b = intval($b['search_count']) + intval($b['download_count']);
            if ($score_a === $score_b) {
                return strcmp((string) $a['region'], (string) $b['region']);
            }
            return ($score_a < $score_b) ? 1 : -1;
        });

        $segment_matrix = array_slice(array_values($segment_map), 0, 50);

        return array(
            'month' => $current['month'],
            'month_label' => $current['label'],
            'previous_month_label' => $prev['label'],
            'yoy_month_label' => $yoy['label'],
            'filters' => $filters,
            'summary' => array(
                'search_total' => $search_total,
                'search_zero_results_total' => $zero_total,
                'search_zero_results_rate' => $zero_rate,
                'download_intent_total' => $download_intent_total,
                'download_granted_total' => $download_granted_total,
                'download_denied_total' => $download_denied_total,
                'download_intent_gap' => $download_gap,
            ),
            'top_keywords' => $keywords_rows,
            'top_zero_keywords' => array_map(function ($row) {
                return array(
                    'query_norm' => (string) ($row['query_norm'] ?? ''),
                    'current_count' => max(0, intval($row['total_count'] ?? 0)),
                );
            }, (array) $top_zero_keywords),
            'top_downloads' => $download_rows,
            'segment_matrix' => $segment_matrix,
        );
    }

    private static function transfer_metric_keys($include_backfill = true)
    {
        $keys = array(
            'download_file_granted',
            'download_folder_granted',
            'download_folder_completed',
            'preview_direct_opened',
            'preview_proxy_streamed',
        );

        if ($include_backfill) {
            $keys[] = 'download_folder_granted_backfill';
        }

        return $keys;
    }

    private static function build_transfer_report($month, $filters, $window = 'month')
    {
        if (!class_exists('JPSM_Data_Layer')) {
            return new WP_Error('data_layer_unavailable', 'Data layer unavailable');
        }

        $ctx = self::transfer_window_context($month, $window);
        $window = $ctx['window'];
        $selected = $ctx['selected'];
        $current = $ctx['current'];
        $supports_monthly_deltas = !empty($ctx['supports_monthly_deltas']);
        $series_anchor_month = $supports_monthly_deltas ? $current['month'] : $selected['month'];

        $prev = self::month_range(gmdate('Y-m', strtotime($current['start'] . ' -1 month')));
        $yoy = self::month_range(gmdate('Y-m', strtotime($current['start'] . ' -1 year')));

        $daily_start_90d = gmdate('Y-m-d', strtotime($current['end'] . ' -89 day'));
        if ($daily_start_90d === false) {
            $daily_start_90d = $current['start'];
        }

        $monthly_start = gmdate('Y-m', strtotime($series_anchor_month . '-01 -11 month'));
        if ($monthly_start === false || !preg_match('/^\d{4}-\d{2}$/', $monthly_start)) {
            $monthly_start = $series_anchor_month;
        }

        $warm_from = $supports_monthly_deltas ? $yoy['start'] : gmdate('Y-m-d', strtotime($current['end'] . ' -120 day'));
        if ($warm_from === false || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $warm_from)) {
            $warm_from = $current['start'];
        }
        self::maybe_warm_behavior_daily($warm_from, $current['end']);
        JPSM_Data_Layer::backfill_transfer_authorized_from_folder_events($current['end']);

        $transfer_keys = self::transfer_metric_keys(true);
        $coverage_keys = self::transfer_metric_keys(false);
        $folder_download_metric_keys = array('download_folder_granted', 'download_folder_granted_backfill');

        $observed_month = JPSM_Data_Layer::get_behavior_metric_bytes_sum($transfer_keys, $current['start'], $current['end'], $filters, 'observed');
        $authorized_month = JPSM_Data_Layer::get_behavior_metric_bytes_sum($transfer_keys, $current['start'], $current['end'], $filters, 'authorized');
        $observed_lifetime = JPSM_Data_Layer::get_behavior_metric_bytes_sum($transfer_keys, '2000-01-01', $current['end'], $filters, 'observed');
        $authorized_lifetime = JPSM_Data_Layer::get_behavior_metric_bytes_sum($transfer_keys, '2000-01-01', $current['end'], $filters, 'authorized');

        $registered_month = max(0, intval(JPSM_Data_Layer::count_registered_users_dedup($series_anchor_month)));
        $registered_lifetime = max(0, intval(JPSM_Data_Layer::count_registered_users_dedup('')));

        $coverage_counts = JPSM_Data_Layer::get_behavior_transfer_coverage_counts($current['start'], $current['end'], $filters, $coverage_keys);
        $coverage_total = max(0, intval($coverage_counts['total_events'] ?? 0));
        $coverage_observed = max(0, intval($coverage_counts['observed_events'] ?? 0));
        $coverage_ratio = ($coverage_total > 0)
            ? round(($coverage_observed / $coverage_total) * 100, 1)
            : 0.0;

        $daily_rows = JPSM_Data_Layer::get_behavior_transfer_daily_series($daily_start_90d, $current['end'], $filters, $transfer_keys);
        $series_daily_90d = self::fill_transfer_daily_series($daily_start_90d, $current['end'], $daily_rows);

        $monthly_rows = JPSM_Data_Layer::get_behavior_transfer_monthly_series($monthly_start, $series_anchor_month, $filters, $transfer_keys);
        $series_monthly_absolute = self::fill_transfer_monthly_series($monthly_start, $series_anchor_month, $monthly_rows);

        $series_monthly_relative = array();
        foreach ($series_monthly_absolute as $row) {
            $row_month = (string) ($row['month'] ?? '');
            $month_registered = max(0, intval(JPSM_Data_Layer::count_registered_users_dedup($row_month)));
            $series_monthly_relative[] = array(
                'month' => $row_month,
                'registered_users' => $month_registered,
                'bytes_per_registered_observed' => self::safe_divide($row['bytes_observed'] ?? 0, $month_registered),
            );
        }

        $lifetime_rows = JPSM_Data_Layer::get_behavior_transfer_monthly_series('2000-01', $series_anchor_month, $filters, $transfer_keys);
        $lifetime_first_month = $series_anchor_month;
        if (!empty($lifetime_rows)) {
            $lifetime_first_month = sanitize_text_field((string) ($lifetime_rows[0]['month_key'] ?? $series_anchor_month));
            if (!preg_match('/^\d{4}-\d{2}$/', $lifetime_first_month)) {
                $lifetime_first_month = $series_anchor_month;
            }
        }

        $lifetime_absolute_base = self::fill_transfer_monthly_series($lifetime_first_month, $series_anchor_month, $lifetime_rows);
        $series_lifetime_absolute = array();
        $series_lifetime_relative = array();
        $cum_authorized = 0;
        $cum_observed = 0;
        foreach ($lifetime_absolute_base as $row) {
            $row_month = (string) ($row['month'] ?? '');
            $cum_authorized += max(0, intval($row['bytes_authorized'] ?? 0));
            $cum_observed += max(0, intval($row['bytes_observed'] ?? 0));
            $month_registered = max(0, intval(JPSM_Data_Layer::count_registered_users_dedup($row_month)));

            $series_lifetime_absolute[] = array(
                'month' => $row_month,
                'cum_bytes_authorized' => $cum_authorized,
                'cum_bytes_observed' => $cum_observed,
            );
            $series_lifetime_relative[] = array(
                'month' => $row_month,
                'registered_users' => $month_registered,
                'cum_bytes_per_registered_observed' => self::safe_divide($cum_observed, $month_registered),
            );
        }

        $top_current = JPSM_Data_Layer::get_behavior_transfer_top_folders($current['start'], $current['end'], $filters, 30);
        $top_prev_map = array();
        $top_yoy_map = array();
        if ($supports_monthly_deltas) {
            $top_prev = JPSM_Data_Layer::get_behavior_transfer_top_folders($prev['start'], $prev['end'], $filters, 300);
            $top_yoy = JPSM_Data_Layer::get_behavior_transfer_top_folders($yoy['start'], $yoy['end'], $filters, 300);
            $top_prev_map = self::to_map_by_key($top_prev, 'object_path_norm', 'downloads');
            $top_yoy_map = self::to_map_by_key($top_yoy, 'object_path_norm', 'downloads');
        }

        $top_folders_source = 'behavior_primary';
        $top_folders_quality = array(
            'is_approx_global' => false,
            'reason' => 'none',
        );
        $top_folders_month = array();
        $rising_folders_count = 0;
        $new_folders_count = 0;
        $primary_downloads_total = 0;

        foreach ((array) $top_current as $row) {
            $primary_downloads_total += max(0, intval($row['downloads'] ?? 0));
        }

        foreach ((array) $top_current as $row) {
            $path = (string) ($row['object_path_norm'] ?? '');
            if ($path === '') {
                continue;
            }

            $downloads = max(0, intval($row['downloads'] ?? 0));
            $mom_base = $supports_monthly_deltas ? max(0, intval($top_prev_map[$path] ?? 0)) : 0;
            $yoy_exists = $supports_monthly_deltas && array_key_exists($path, $top_yoy_map);
            $yoy_base = $supports_monthly_deltas ? max(0, intval($top_yoy_map[$path] ?? 0)) : 0;
            $mom_delta = $supports_monthly_deltas ? ($downloads - $mom_base) : null;
            $yoy_delta = $supports_monthly_deltas ? ($yoy_exists ? ($downloads - $yoy_base) : null) : null;

            if ($supports_monthly_deltas && $mom_delta !== null && $mom_delta > 0) {
                $rising_folders_count++;
            }
            if ($supports_monthly_deltas && $downloads > 0 && $mom_base === 0) {
                $new_folders_count++;
            }

            $top_folders_month[] = array(
                'folder_path' => $path,
                'downloads' => $downloads,
                'bytes_authorized' => max(0, intval($row['bytes_authorized'] ?? 0)),
                'bytes_observed' => max(0, intval($row['bytes_observed'] ?? 0)),
                'bytes_authorized_available' => true,
                'bytes_observed_available' => true,
                'mom_delta' => $mom_delta,
                'yoy_delta' => $yoy_delta,
                'yoy_available' => $yoy_exists,
            );
        }

        $downloads_total_window = max(0, intval(JPSM_Data_Layer::get_behavior_metric_sum($folder_download_metric_keys, $current['start'], $current['end'], $filters)));
        $unique_folders_window = max(0, intval(JPSM_Data_Layer::get_behavior_transfer_unique_folder_count($current['start'], $current['end'], $filters, $folder_download_metric_keys)));
        $legacy_totals = JPSM_Data_Layer::get_folder_download_totals_by_range($current['start'], $current['end']);
        $legacy_total_downloads = max(0, intval($legacy_totals['total_downloads'] ?? 0));

        $needs_fallback = (empty($top_folders_month) || $downloads_total_window <= 0) && $legacy_total_downloads > 0;
        if ($needs_fallback) {
            $top_folders_source = 'legacy_fallback_global';
            $top_folders_quality = array(
                'is_approx_global' => !self::is_default_transfer_filters($filters),
                'reason' => ($primary_downloads_total > 0) ? 'primary_incomplete' : 'primary_empty',
            );

            $legacy_top_current = JPSM_Data_Layer::get_top_folder_downloads_by_range($current['start'], $current['end'], 30);
            $legacy_prev_map = array();
            $legacy_yoy_map = array();
            if ($supports_monthly_deltas) {
                foreach ((array) JPSM_Data_Layer::get_top_folder_downloads_by_range($prev['start'], $prev['end'], 300) as $row) {
                    $path = (string) ($row['folder_path'] ?? '');
                    if ($path !== '') {
                        $legacy_prev_map[$path] = max(0, intval($row['downloads'] ?? 0));
                    }
                }
                foreach ((array) JPSM_Data_Layer::get_top_folder_downloads_by_range($yoy['start'], $yoy['end'], 300) as $row) {
                    $path = (string) ($row['folder_path'] ?? '');
                    if ($path !== '') {
                        $legacy_yoy_map[$path] = max(0, intval($row['downloads'] ?? 0));
                    }
                }
            }

            $top_folders_month = array();
            $rising_folders_count = 0;
            $new_folders_count = 0;
            foreach ((array) $legacy_top_current as $row) {
                $path = (string) ($row['folder_path'] ?? '');
                if ($path === '') {
                    continue;
                }

                $downloads = max(0, intval($row['downloads'] ?? 0));
                $mom_base = $supports_monthly_deltas ? max(0, intval($legacy_prev_map[$path] ?? 0)) : 0;
                $yoy_exists = $supports_monthly_deltas && array_key_exists($path, $legacy_yoy_map);
                $yoy_base = $supports_monthly_deltas ? max(0, intval($legacy_yoy_map[$path] ?? 0)) : 0;
                $mom_delta = $supports_monthly_deltas ? ($downloads - $mom_base) : null;
                $yoy_delta = $supports_monthly_deltas ? ($yoy_exists ? ($downloads - $yoy_base) : null) : null;

                if ($supports_monthly_deltas && $mom_delta !== null && $mom_delta > 0) {
                    $rising_folders_count++;
                }
                if ($supports_monthly_deltas && $downloads > 0 && $mom_base === 0) {
                    $new_folders_count++;
                }

                $top_folders_month[] = array(
                    'folder_path' => $path,
                    'downloads' => $downloads,
                    'bytes_authorized' => null,
                    'bytes_observed' => null,
                    'bytes_authorized_available' => false,
                    'bytes_observed_available' => false,
                    'mom_delta' => $mom_delta,
                    'yoy_delta' => $yoy_delta,
                    'yoy_available' => $yoy_exists,
                );
            }

            $downloads_total_window = $legacy_total_downloads;
            $unique_folders_window = max(0, intval($legacy_totals['unique_folders'] ?? 0));
        }

        $top1_downloads = !empty($top_folders_month) ? max(0, intval($top_folders_month[0]['downloads'] ?? 0)) : 0;
        $top3_downloads = 0;
        for ($i = 0; $i < 3; $i++) {
            if (!isset($top_folders_month[$i])) {
                break;
            }
            $top3_downloads += max(0, intval($top_folders_month[$i]['downloads'] ?? 0));
        }
        $top1_share_percent = ($downloads_total_window > 0)
            ? round(($top1_downloads / $downloads_total_window) * 100, 1)
            : 0.0;
        $top3_share_percent = ($downloads_total_window > 0)
            ? round(($top3_downloads / $downloads_total_window) * 100, 1)
            : 0.0;

        if (!$supports_monthly_deltas) {
            $rising_folders_count = null;
            $new_folders_count = null;
        }

        return array(
            'window' => $window,
            'month' => $current['month'],
            'month_label' => $current['label'],
            'previous_month_label' => $supports_monthly_deltas ? $prev['label'] : 'N/A',
            'yoy_month_label' => $supports_monthly_deltas ? $yoy['label'] : 'N/A',
            'filters' => $filters,
            'top_folders_source' => $top_folders_source,
            'top_folders_quality' => $top_folders_quality,
            'kpis' => array(
                'transfer_observed_bytes_month' => $observed_month,
                'transfer_authorized_bytes_month' => $authorized_month,
                'transfer_observed_bytes_lifetime' => $observed_lifetime,
                'transfer_authorized_bytes_lifetime' => $authorized_lifetime,
                'registered_users_global_month_end' => $registered_month,
                'registered_users_global_actual' => $registered_lifetime,
                'bytes_per_registered_month' => self::safe_divide($observed_month, $registered_month),
                'bytes_per_registered_lifetime' => self::safe_divide($observed_lifetime, $registered_lifetime),
            ),
            'demand_kpis' => array(
                'downloads_total_window' => $downloads_total_window,
                'unique_folders_window' => $unique_folders_window,
                'top1_share_percent' => $top1_share_percent,
                'top3_share_percent' => $top3_share_percent,
                'rising_folders_count' => $rising_folders_count,
                'new_folders_count' => $new_folders_count,
            ),
            'series_daily_90d' => $series_daily_90d,
            'series_monthly_absolute' => $series_monthly_absolute,
            'series_monthly_relative' => $series_monthly_relative,
            'series_lifetime_absolute' => $series_lifetime_absolute,
            'series_lifetime_relative' => $series_lifetime_relative,
            'top_folders_month' => $top_folders_month,
            'coverage' => array(
                'total_events' => $coverage_total,
                'observed_events' => $coverage_observed,
                'coverage_event_ratio' => $coverage_ratio,
            ),
        );
    }

    private static function fill_transfer_daily_series($from_date, $to_date, $rows)
    {
        $from = sanitize_text_field((string) $from_date);
        $to = sanitize_text_field((string) $to_date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            return array();
        }

        $map = array();
        foreach ((array) $rows as $row) {
            $day = sanitize_text_field((string) ($row['day_date'] ?? ''));
            if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }
            $map[$day] = array(
                'event_count' => max(0, intval($row['event_count'] ?? 0)),
                'bytes_authorized' => max(0, intval($row['bytes_authorized'] ?? 0)),
                'bytes_observed' => max(0, intval($row['bytes_observed'] ?? 0)),
            );
        }

        $out = array();
        $cursor = strtotime($from);
        $end_ts = strtotime($to);
        while ($cursor !== false && $end_ts !== false && $cursor <= $end_ts) {
            $day = gmdate('Y-m-d', $cursor);
            $rec = $map[$day] ?? array(
                'event_count' => 0,
                'bytes_authorized' => 0,
                'bytes_observed' => 0,
            );
            $out[] = array(
                'day_date' => $day,
                'event_count' => intval($rec['event_count']),
                'bytes_authorized' => intval($rec['bytes_authorized']),
                'bytes_observed' => intval($rec['bytes_observed']),
            );
            $cursor = strtotime($day . ' +1 day');
        }

        return $out;
    }

    private static function fill_transfer_monthly_series($from_month, $to_month, $rows)
    {
        $sequence = self::month_sequence($from_month, $to_month);
        if (empty($sequence)) {
            return array();
        }

        $map = array();
        foreach ((array) $rows as $row) {
            $month_key = sanitize_text_field((string) ($row['month_key'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}$/', $month_key)) {
                continue;
            }
            $map[$month_key] = array(
                'event_count' => max(0, intval($row['event_count'] ?? 0)),
                'bytes_authorized' => max(0, intval($row['bytes_authorized'] ?? 0)),
                'bytes_observed' => max(0, intval($row['bytes_observed'] ?? 0)),
            );
        }

        $out = array();
        foreach ($sequence as $month_key) {
            $rec = $map[$month_key] ?? array(
                'event_count' => 0,
                'bytes_authorized' => 0,
                'bytes_observed' => 0,
            );
            $out[] = array(
                'month' => $month_key,
                'event_count' => intval($rec['event_count']),
                'bytes_authorized' => intval($rec['bytes_authorized']),
                'bytes_observed' => intval($rec['bytes_observed']),
            );
        }

        return $out;
    }

    private static function month_sequence($from_month, $to_month)
    {
        $from_month = sanitize_text_field((string) $from_month);
        $to_month = sanitize_text_field((string) $to_month);
        if (!preg_match('/^\d{4}-\d{2}$/', $from_month) || !preg_match('/^\d{4}-\d{2}$/', $to_month) || $from_month > $to_month) {
            return array();
        }

        $out = array();
        $cursor = $from_month . '-01';
        $end = $to_month . '-01';
        $cursor_ts = strtotime($cursor);
        $end_ts = strtotime($end);
        while ($cursor_ts !== false && $end_ts !== false && $cursor_ts <= $end_ts) {
            $out[] = gmdate('Y-m', $cursor_ts);
            $cursor_ts = strtotime(gmdate('Y-m-01', $cursor_ts) . ' +1 month');
        }

        return $out;
    }

    private static function safe_divide($numerator, $denominator, $precision = 2)
    {
        $num = floatval($numerator);
        $den = floatval($denominator);
        if ($den <= 0) {
            return 0.0;
        }
        return round($num / $den, $precision);
    }

    private static function empty_transfer_report($month, $filters, $window = 'month')
    {
        return array(
            'window' => self::sanitize_transfer_window($window),
            'month' => $month,
            'month_label' => $month,
            'previous_month_label' => '',
            'yoy_month_label' => '',
            'filters' => $filters,
            'top_folders_source' => 'behavior_primary',
            'top_folders_quality' => array(
                'is_approx_global' => false,
                'reason' => 'none',
            ),
            'kpis' => array(
                'transfer_observed_bytes_month' => 0,
                'transfer_authorized_bytes_month' => 0,
                'transfer_observed_bytes_lifetime' => 0,
                'transfer_authorized_bytes_lifetime' => 0,
                'registered_users_global_month_end' => 0,
                'registered_users_global_actual' => 0,
                'bytes_per_registered_month' => 0,
                'bytes_per_registered_lifetime' => 0,
            ),
            'demand_kpis' => array(
                'downloads_total_window' => 0,
                'unique_folders_window' => 0,
                'top1_share_percent' => 0,
                'top3_share_percent' => 0,
                'rising_folders_count' => 0,
                'new_folders_count' => 0,
            ),
            'series_daily_90d' => array(),
            'series_monthly_absolute' => array(),
            'series_monthly_relative' => array(),
            'series_lifetime_absolute' => array(),
            'series_lifetime_relative' => array(),
            'top_folders_month' => array(),
            'coverage' => array(
                'total_events' => 0,
                'observed_events' => 0,
                'coverage_event_ratio' => 0,
            ),
        );
    }

    private static function empty_behavior_report($month, $filters)
    {
        return array(
            'month' => $month,
            'month_label' => $month,
            'previous_month_label' => '',
            'yoy_month_label' => '',
            'filters' => $filters,
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
}
