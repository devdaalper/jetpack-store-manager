<?php

if (!defined('ABSPATH')) {
    exit;
}

class JPSM_Finance
{
    const SALES_PICKER_LIMIT = 80;
    const RECENT_SETTLEMENTS_LIMIT = 25;
    const RECENT_EXPENSES_LIMIT = 25;

    public static function get_market_options()
    {
        return array(
            array(
                'id' => 'mx',
                'label' => 'México',
                'description' => 'Transferencia bancaria directa en MXN.',
            ),
            array(
                'id' => 'us',
                'label' => 'Estados Unidos',
                'description' => 'Cobro en USD con liquidación por PayPal.',
            ),
            array(
                'id' => 'manual',
                'label' => 'Manual',
                'description' => 'Ajustes u otros ingresos operativos.',
            ),
        );
    }

    public static function get_channel_options()
    {
        return array(
            'bank_transfer' => 'Transferencia bancaria',
            'paypal' => 'PayPal',
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'manual_adjustment' => 'Ajuste manual',
            'other' => 'Otro',
        );
    }

    public static function get_expense_categories()
    {
        return array(
            'marketing' => 'Marketing',
            'operacion' => 'Operación',
            'software' => 'Software',
            'nomina' => 'Nómina',
            'impuestos' => 'Impuestos',
            'comisiones_bancarias' => 'Comisiones bancarias',
            'otros' => 'Otros',
        );
    }

    public static function get_admin_overview()
    {
        $sales_log = class_exists('JPSM_Data_Layer') ? JPSM_Data_Layer::get_sales_log() : array();
        $settlements = class_exists('JPSM_Data_Layer') ? JPSM_Data_Layer::get_finance_settlements(0) : array();
        $expenses = class_exists('JPSM_Data_Layer') ? JPSM_Data_Layer::get_finance_expenses(0) : array();
        $linked_sale_uids = class_exists('JPSM_Data_Layer') ? JPSM_Data_Layer::get_finance_linked_sale_uids() : array();

        $overview = self::build_overview_from_records($sales_log, $settlements, $expenses, $linked_sale_uids);
        $sales_picker_rows = self::build_sales_picker_rows(
            class_exists('JPSM_Data_Layer') ? JPSM_Data_Layer::get_sales_log(self::SALES_PICKER_LIMIT) : array_slice((array) $sales_log, 0, self::SALES_PICKER_LIMIT),
            $linked_sale_uids
        );

        return array_merge($overview, array(
            'sales_picker_rows' => $sales_picker_rows,
            'recent_settlements' => array_slice((array) $settlements, 0, self::RECENT_SETTLEMENTS_LIMIT),
            'recent_expenses' => array_slice((array) $expenses, 0, self::RECENT_EXPENSES_LIMIT),
            'market_options' => self::get_market_options(),
            'channel_options' => self::get_channel_options(),
            'expense_categories' => self::get_expense_categories(),
        ));
    }

    public static function build_overview_from_records($sales_log, $settlements, $expenses, $linked_sale_uids = array())
    {
        $linked_map = array_fill_keys(array_values(array_unique(array_filter((array) $linked_sale_uids))), true);
        $current_month = current_time('Y-m');

        $overview = array(
            'current_month' => self::empty_summary_bucket(),
            'lifetime' => self::empty_summary_bucket(),
        );

        foreach ((array) $sales_log as $sale) {
            if (!is_array($sale)) {
                continue;
            }

            $currency = strtoupper((string) ($sale['currency'] ?? ''));
            $amount = isset($sale['amount']) ? floatval($sale['amount']) : 0.0;
            $sale_month = self::extract_month_key($sale['time'] ?? '');
            $is_linked = !empty($linked_map[(string) ($sale['id'] ?? '')]);

            self::add_sale_to_bucket($overview['lifetime'], $currency, $amount, $is_linked);
            if ($sale_month === $current_month) {
                self::add_sale_to_bucket($overview['current_month'], $currency, $amount, $is_linked);
            }
        }

        foreach ((array) $settlements as $settlement) {
            if (!is_array($settlement)) {
                continue;
            }

            $settlement_month = self::extract_month_key($settlement['settlement_date'] ?? '');
            self::add_settlement_to_bucket($overview['lifetime'], $settlement);
            if ($settlement_month === $current_month) {
                self::add_settlement_to_bucket($overview['current_month'], $settlement);
            }
        }

        foreach ((array) $expenses as $expense) {
            if (!is_array($expense)) {
                continue;
            }

            $expense_month = self::extract_month_key($expense['expense_date'] ?? '');
            self::add_expense_to_bucket($overview['lifetime'], $expense);
            if ($expense_month === $current_month) {
                self::add_expense_to_bucket($overview['current_month'], $expense);
            }
        }

        $overview['current_month']['operating_profit_mxn'] = round(
            $overview['current_month']['net_received_mxn'] - $overview['current_month']['expenses_mxn_equivalent'],
            2
        );
        $overview['lifetime']['operating_profit_mxn'] = round(
            $overview['lifetime']['net_received_mxn'] - $overview['lifetime']['expenses_mxn_equivalent'],
            2
        );

        return $overview;
    }

    public static function record_settlement_ajax()
    {
        self::authorize_finance_ajax();

        $sale_uids = self::read_sale_uids_from_request($_POST['sale_uids'] ?? array());
        $selected_sales = class_exists('JPSM_Data_Layer') ? JPSM_Data_Layer::get_sales_by_uids($sale_uids) : array();
        $linked_sale_uids = class_exists('JPSM_Data_Layer') ? JPSM_Data_Layer::get_finance_linked_sale_uids() : array();

        if (!empty($sale_uids) && count($selected_sales) !== count($sale_uids)) {
            JPSM_API_Contract::send_error('Una o más ventas seleccionadas ya no existen o no pudieron cargarse.', 'missing_selected_sales', 404);
        }

        foreach ($sale_uids as $sale_uid) {
            if (in_array($sale_uid, $linked_sale_uids, true)) {
                JPSM_API_Contract::send_error('Una o más ventas seleccionadas ya están conciliadas.', 'sale_already_settled', 409);
            }
        }

        $derived = self::derive_context_from_sales($selected_sales);
        if (is_wp_error($derived)) {
            JPSM_API_Contract::send_wp_error($derived, 'Las ventas seleccionadas no son compatibles entre sí.', 'invalid_sales_mix', 422);
            return;
        }

        $market = $derived['market'] !== '' ? $derived['market'] : sanitize_key((string) ($_POST['market'] ?? ''));
        $channel = sanitize_key((string) ($_POST['channel'] ?? self::default_channel_for_market($market)));
        $currency = $derived['currency'] !== '' ? $derived['currency'] : strtoupper(sanitize_text_field((string) ($_POST['currency'] ?? self::default_currency_for_market($market))));

        $gross_amount = self::read_amount_from_request($_POST['gross_amount'] ?? '');
        if (!empty($selected_sales)) {
            $gross_amount = $derived['gross_amount'];
        }

        $fee_amount = self::read_amount_from_request($_POST['fee_amount'] ?? '');
        $net_amount = self::read_amount_from_request($_POST['net_amount'] ?? '');
        if ($net_amount <= 0 && $gross_amount > 0) {
            $net_amount = max(0, $gross_amount - $fee_amount);
        }

        $fx_rate = self::read_rate_from_request($_POST['fx_rate'] ?? '');
        $net_amount_mxn = self::read_amount_from_request($_POST['net_amount_mxn'] ?? '');
        if ($currency === 'MXN' && $net_amount > 0 && $net_amount_mxn <= 0) {
            $net_amount_mxn = $net_amount;
        }
        if ($currency === 'USD' && $net_amount > 0 && $net_amount_mxn <= 0 && $fx_rate > 0) {
            $net_amount_mxn = round($net_amount * $fx_rate, 2);
        }

        if ($market === '' || !in_array($market, array('mx', 'us', 'manual'), true)) {
            JPSM_API_Contract::send_error('Selecciona un mercado válido.', 'invalid_market', 422);
        }

        if ($gross_amount <= 0) {
            JPSM_API_Contract::send_error('Captura un ingreso bruto válido o selecciona ventas para conciliar.', 'invalid_gross_amount', 422);
        }

        if ($currency === 'USD' && $net_amount_mxn <= 0) {
            JPSM_API_Contract::send_error('Para USD captura el neto recibido en MXN o el tipo de cambio efectivo.', 'missing_mxn_net', 422);
        }

        $settlement = array(
            'settlement_date' => sanitize_text_field((string) ($_POST['settlement_date'] ?? current_time('Y-m-d'))),
            'market' => $market,
            'channel' => $channel,
            'currency' => $currency,
            'gross_amount' => $gross_amount,
            'fee_amount' => $fee_amount,
            'net_amount' => $net_amount,
            'fx_rate' => $fx_rate,
            'net_amount_mxn' => $net_amount_mxn,
            'sales_count' => count($selected_sales),
            'bank_account' => sanitize_text_field((string) ($_POST['bank_account'] ?? '')),
            'external_ref' => sanitize_text_field((string) ($_POST['external_ref'] ?? '')),
            'notes' => sanitize_text_field((string) ($_POST['notes'] ?? '')),
            'status' => 'recorded',
        );

        $items = self::build_settlement_items_from_sales($selected_sales, $fee_amount, $net_amount);
        $saved = class_exists('JPSM_Data_Layer') ? JPSM_Data_Layer::create_finance_settlement($settlement, $items) : false;
        if (!$saved) {
            JPSM_API_Contract::send_error('No se pudo registrar la liquidación.', 'finance_settlement_save_failed', 500);
        }

        JPSM_API_Contract::send_success(
            array(
                'message' => 'Liquidación registrada.',
                'settlement' => $saved,
            ),
            'finance_settlement_recorded',
            'Liquidación registrada.',
            200
        );
    }

    public static function record_expense_ajax()
    {
        self::authorize_finance_ajax();

        $amount = self::read_amount_from_request($_POST['amount'] ?? '');
        $currency = strtoupper(sanitize_text_field((string) ($_POST['currency'] ?? 'MXN')));
        $fx_rate = self::read_rate_from_request($_POST['fx_rate'] ?? '');
        $amount_mxn = self::read_amount_from_request($_POST['amount_mxn'] ?? '');

        if ($currency === 'MXN' && $amount_mxn <= 0) {
            $amount_mxn = $amount;
        }
        if ($currency === 'USD' && $amount_mxn <= 0 && $fx_rate > 0) {
            $amount_mxn = round($amount * $fx_rate, 2);
        }

        if ($amount <= 0) {
            JPSM_API_Contract::send_error('Captura un monto de gasto válido.', 'invalid_expense_amount', 422);
        }

        if ($currency === 'USD' && $amount_mxn <= 0) {
            JPSM_API_Contract::send_error('Para gastos en USD captura el monto equivalente en MXN o un tipo de cambio.', 'missing_expense_mxn', 422);
        }

        $category = sanitize_key((string) ($_POST['category'] ?? 'otros'));
        $vendor = sanitize_text_field((string) ($_POST['vendor'] ?? ''));
        $description = sanitize_text_field((string) ($_POST['description'] ?? ''));
        if ($category === '') {
            JPSM_API_Contract::send_error('Selecciona una categoría de gasto.', 'missing_expense_category', 422);
        }
        if ($vendor === '' && $description === '') {
            JPSM_API_Contract::send_error('Describe el gasto o indica el proveedor.', 'missing_expense_description', 422);
        }

        $expense = array(
            'expense_date' => sanitize_text_field((string) ($_POST['expense_date'] ?? current_time('Y-m-d'))),
            'category' => $category,
            'vendor' => $vendor,
            'description' => $description,
            'amount' => $amount,
            'currency' => $currency,
            'fx_rate' => $fx_rate,
            'amount_mxn' => $amount_mxn,
            'account_label' => sanitize_text_field((string) ($_POST['account_label'] ?? '')),
            'notes' => sanitize_text_field((string) ($_POST['notes'] ?? '')),
            'status' => 'recorded',
        );

        $saved = class_exists('JPSM_Data_Layer') ? JPSM_Data_Layer::create_finance_expense($expense) : false;
        if (!$saved) {
            JPSM_API_Contract::send_error('No se pudo registrar el gasto.', 'finance_expense_save_failed', 500);
        }

        JPSM_API_Contract::send_success(
            array(
                'message' => 'Gasto registrado.',
                'expense' => $saved,
            ),
            'finance_expense_recorded',
            'Gasto registrado.',
            200
        );
    }

    public static function delete_settlement_ajax()
    {
        self::authorize_finance_ajax();
        $settlement_uid = sanitize_text_field((string) ($_POST['settlement_uid'] ?? ''));
        if ($settlement_uid === '') {
            JPSM_API_Contract::send_error('Falta la liquidación a eliminar.', 'missing_settlement_uid', 422);
        }

        $deleted = class_exists('JPSM_Data_Layer') ? JPSM_Data_Layer::delete_finance_settlement($settlement_uid) : false;
        if (!$deleted) {
            JPSM_API_Contract::send_error('No se pudo eliminar la liquidación.', 'finance_settlement_delete_failed', 500);
        }

        JPSM_API_Contract::send_success(array('message' => 'Liquidación eliminada.'), 'finance_settlement_deleted', 'Liquidación eliminada.', 200);
    }

    public static function delete_expense_ajax()
    {
        self::authorize_finance_ajax();
        $expense_uid = sanitize_text_field((string) ($_POST['expense_uid'] ?? ''));
        if ($expense_uid === '') {
            JPSM_API_Contract::send_error('Falta el gasto a eliminar.', 'missing_expense_uid', 422);
        }

        $deleted = class_exists('JPSM_Data_Layer') ? JPSM_Data_Layer::delete_finance_expense($expense_uid) : false;
        if (!$deleted) {
            JPSM_API_Contract::send_error('No se pudo eliminar el gasto.', 'finance_expense_delete_failed', 500);
        }

        JPSM_API_Contract::send_success(array('message' => 'Gasto eliminado.'), 'finance_expense_deleted', 'Gasto eliminado.', 200);
    }

    private static function authorize_finance_ajax()
    {
        if (class_exists('JPSM_Auth')) {
            $auth = JPSM_Auth::authorize_request(array(
                'require_nonce' => true,
                'nonce_actions' => array('jpsm_nonce', 'jpsm_finance_nonce'),
                'allow_admin' => true,
                'allow_secret_key' => true,
                'allow_user_session' => false,
            ));

            if (is_wp_error($auth)) {
                $message = $auth->get_error_code() === 'invalid_nonce' ? 'Invalid nonce' : 'Unauthorized';
                $code = $auth->get_error_code() === 'invalid_nonce' ? 'invalid_nonce' : 'unauthorized';
                $status = $auth->get_error_code() === 'invalid_nonce' ? 401 : 403;
                JPSM_API_Contract::send_error($message, $code, $status);
            }

            return;
        }

        if (!current_user_can('manage_options')) {
            JPSM_API_Contract::send_error('Unauthorized', 'unauthorized', 403);
        }
    }

    private static function empty_summary_bucket()
    {
        return array(
            'gross_sales_mxn' => 0.0,
            'gross_sales_usd' => 0.0,
            'unsettled_sales_mxn' => 0.0,
            'unsettled_sales_usd' => 0.0,
            'unsettled_sales_count' => 0,
            'settlements_count' => 0,
            'expenses_count' => 0,
            'net_received_mxn' => 0.0,
            'fee_mxn' => 0.0,
            'fee_usd' => 0.0,
            'fee_mxn_equivalent' => 0.0,
            'expenses_mxn' => 0.0,
            'expenses_usd' => 0.0,
            'expenses_mxn_equivalent' => 0.0,
            'operating_profit_mxn' => 0.0,
        );
    }

    private static function add_sale_to_bucket(&$bucket, $currency, $amount, $is_linked)
    {
        $amount = round(floatval($amount), 2);
        if ($currency === 'USD') {
            $bucket['gross_sales_usd'] += $amount;
            if (!$is_linked) {
                $bucket['unsettled_sales_usd'] += $amount;
            }
        } else {
            $bucket['gross_sales_mxn'] += $amount;
            if (!$is_linked) {
                $bucket['unsettled_sales_mxn'] += $amount;
            }
        }

        if (!$is_linked) {
            $bucket['unsettled_sales_count']++;
        }
    }

    private static function add_settlement_to_bucket(&$bucket, $settlement)
    {
        $currency = strtoupper((string) ($settlement['currency'] ?? ''));
        $fee_amount = round(floatval($settlement['fee_amount'] ?? 0), 2);
        $fx_rate = round(floatval($settlement['fx_rate'] ?? 0), 6);
        $net_amount_mxn = round(floatval($settlement['net_amount_mxn'] ?? 0), 2);

        $bucket['settlements_count']++;
        $bucket['net_received_mxn'] += $net_amount_mxn;

        if ($currency === 'USD') {
            $bucket['fee_usd'] += $fee_amount;
            if ($fx_rate > 0) {
                $bucket['fee_mxn_equivalent'] += round($fee_amount * $fx_rate, 2);
            }
        } else {
            $bucket['fee_mxn'] += $fee_amount;
            $bucket['fee_mxn_equivalent'] += $fee_amount;
        }
    }

    private static function add_expense_to_bucket(&$bucket, $expense)
    {
        $currency = strtoupper((string) ($expense['currency'] ?? ''));
        $amount = round(floatval($expense['amount'] ?? 0), 2);
        $amount_mxn = round(floatval($expense['amount_mxn'] ?? 0), 2);

        $bucket['expenses_count']++;
        $bucket['expenses_mxn_equivalent'] += $amount_mxn;

        if ($currency === 'USD') {
            $bucket['expenses_usd'] += $amount;
        } else {
            $bucket['expenses_mxn'] += $amount;
        }
    }

    private static function extract_month_key($datetime)
    {
        $value = sanitize_text_field((string) $datetime);
        if ($value === '') {
            return '';
        }
        return substr($value, 0, 7);
    }

    private static function build_sales_picker_rows($sales, $linked_sale_uids)
    {
        $linked_map = array_fill_keys(array_values(array_unique(array_filter((array) $linked_sale_uids))), true);
        $rows = array();

        foreach ((array) $sales as $sale) {
            if (!is_array($sale)) {
                continue;
            }

            $sale_uid = (string) ($sale['id'] ?? '');
            if ($sale_uid === '') {
                continue;
            }

            $market = self::market_from_sale_region($sale['region'] ?? '');
            $rows[] = array(
                'id' => $sale_uid,
                'time' => (string) ($sale['time'] ?? ''),
                'email' => (string) ($sale['email'] ?? ''),
                'package' => (string) ($sale['package'] ?? ''),
                'region' => (string) ($sale['region'] ?? ''),
                'market' => $market,
                'currency' => strtoupper((string) ($sale['currency'] ?? self::default_currency_for_market($market))),
                'amount' => round(floatval($sale['amount'] ?? 0), 2),
                'linked' => !empty($linked_map[$sale_uid]),
            );
        }

        return $rows;
    }

    private static function derive_context_from_sales($sales)
    {
        if (empty($sales)) {
            return array(
                'market' => '',
                'currency' => '',
                'gross_amount' => 0.0,
            );
        }

        $markets = array();
        $currencies = array();
        $gross_amount = 0.0;
        foreach ((array) $sales as $sale) {
            $market = self::market_from_sale_region($sale['region'] ?? '');
            $currency = strtoupper((string) ($sale['currency'] ?? self::default_currency_for_market($market)));
            if ($market === '' || $currency === '') {
                return new WP_Error('invalid_sale_context', 'Hay ventas seleccionadas sin mercado o moneda reconocible.');
            }
            $markets[$market] = true;
            $currencies[$currency] = true;
            $gross_amount += round(floatval($sale['amount'] ?? 0), 2);
        }

        if (count($markets) > 1 || count($currencies) > 1) {
            return new WP_Error('mixed_sales_context', 'No mezcles ventas de México y Estados Unidos en la misma liquidación.');
        }

        return array(
            'market' => (string) key($markets),
            'currency' => (string) key($currencies),
            'gross_amount' => round($gross_amount, 2),
        );
    }

    private static function build_settlement_items_from_sales($sales, $fee_total, $net_total)
    {
        $sales = array_values(array_filter((array) $sales, 'is_array'));
        if (empty($sales)) {
            return array();
        }

        $gross_total = 0.0;
        foreach ($sales as $sale) {
            $gross_total += round(floatval($sale['amount'] ?? 0), 2);
        }

        $fee_total = round(floatval($fee_total), 2);
        $net_total = round(floatval($net_total), 2);
        $remaining_fee = $fee_total;
        $remaining_net = $net_total;
        $items = array();
        $last_index = count($sales) - 1;

        foreach ($sales as $idx => $sale) {
            $gross_amount = round(floatval($sale['amount'] ?? 0), 2);
            $currency = strtoupper((string) ($sale['currency'] ?? ''));

            if ($idx === $last_index) {
                $fee_amount = round($remaining_fee, 2);
                $net_amount = round($remaining_net, 2);
            } else {
                $ratio = $gross_total > 0 ? ($gross_amount / $gross_total) : (1 / max(1, count($sales)));
                $fee_amount = round($fee_total * $ratio, 2);
                $net_amount = round($net_total * $ratio, 2);
                $remaining_fee = round($remaining_fee - $fee_amount, 2);
                $remaining_net = round($remaining_net - $net_amount, 2);
            }

            $items[] = array(
                'sale_uid' => (string) ($sale['id'] ?? ''),
                'sale_time' => (string) ($sale['time'] ?? ''),
                'sale_email' => (string) ($sale['email'] ?? ''),
                'package' => (string) ($sale['package'] ?? ''),
                'sale_region' => (string) ($sale['region'] ?? ''),
                'gross_amount' => $gross_amount,
                'fee_amount' => max(0, $fee_amount),
                'net_amount' => max(0, $net_amount),
                'currency' => $currency,
            );
        }

        return $items;
    }

    private static function market_from_sale_region($region)
    {
        $region = sanitize_key((string) $region);
        if ($region === 'national') {
            return 'mx';
        }
        if ($region === 'international') {
            return 'us';
        }
        return '';
    }

    private static function default_currency_for_market($market)
    {
        if ($market === 'us') {
            return 'USD';
        }
        if ($market === 'mx' || $market === 'manual') {
            return 'MXN';
        }
        return '';
    }

    public static function default_channel_for_market($market)
    {
        if ($market === 'us') {
            return 'paypal';
        }
        if ($market === 'mx') {
            return 'bank_transfer';
        }
        return 'manual_adjustment';
    }

    public static function market_label($market)
    {
        foreach (self::get_market_options() as $option) {
            if ((string) $option['id'] === (string) $market) {
                return (string) $option['label'];
            }
        }
        return 'Sin definir';
    }

    public static function channel_label($channel)
    {
        $options = self::get_channel_options();
        return isset($options[$channel]) ? (string) $options[$channel] : 'Sin definir';
    }

    public static function expense_category_label($category)
    {
        $categories = self::get_expense_categories();
        return isset($categories[$category]) ? (string) $categories[$category] : 'Otros';
    }

    private static function read_sale_uids_from_request($value)
    {
        if (!is_array($value)) {
            $value = array($value);
        }

        $ids = array();
        foreach ((array) $value as $item) {
            $id = sanitize_text_field((string) $item);
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function read_amount_from_request($value)
    {
        return round(self::normalize_decimal_like_input($value), 2);
    }

    private static function read_rate_from_request($value)
    {
        return round(self::normalize_decimal_like_input($value), 6);
    }

    private static function normalize_decimal_like_input($value)
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return 0.0;
            }
            $value = str_replace(' ', '', $value);
            if (preg_match('/^-?\d+,\d+$/', $value)) {
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        }

        if (!is_numeric($value)) {
            return 0.0;
        }

        return max(0, (float) $value);
    }
}
