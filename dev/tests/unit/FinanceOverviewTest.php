<?php

use PHPUnit\Framework\TestCase;

require_once jpsm_test_plugin_root() . '/includes/class-jpsm-finance.php';

final class FinanceOverviewTest extends TestCase
{
    public function testBuildOverviewFromRecordsSeparatesGrossNetFeesExpensesAndPendingSales(): void
    {
        $currentMonth = current_time('Y-m');
        $previousMonth = gmdate('Y-m', strtotime($currentMonth . '-01 -1 month'));

        $sales = array(
            array(
                'id' => 'sale_mx_1',
                'time' => $currentMonth . '-05 10:00:00',
                'amount' => 1000,
                'currency' => 'MXN',
                'region' => 'national',
            ),
            array(
                'id' => 'sale_us_1',
                'time' => $currentMonth . '-06 11:00:00',
                'amount' => 100,
                'currency' => 'USD',
                'region' => 'international',
            ),
            array(
                'id' => 'sale_us_old',
                'time' => $previousMonth . '-10 09:30:00',
                'amount' => 80,
                'currency' => 'USD',
                'region' => 'international',
            ),
        );

        $settlements = array(
            array(
                'settlement_date' => $currentMonth . '-07 12:00:00',
                'currency' => 'MXN',
                'fee_amount' => 0,
                'fx_rate' => 0,
                'net_amount_mxn' => 1000,
            ),
            array(
                'settlement_date' => $currentMonth . '-08 12:00:00',
                'currency' => 'USD',
                'fee_amount' => 5,
                'fx_rate' => 17.5,
                'net_amount_mxn' => 1662.5,
            ),
        );

        $expenses = array(
            array(
                'expense_date' => $currentMonth . '-09 08:00:00',
                'currency' => 'MXN',
                'amount' => 300,
                'amount_mxn' => 300,
            ),
            array(
                'expense_date' => $previousMonth . '-03 08:00:00',
                'currency' => 'USD',
                'amount' => 20,
                'amount_mxn' => 340,
            ),
        );

        $overview = JPSM_Finance::build_overview_from_records(
            $sales,
            $settlements,
            $expenses,
            array('sale_mx_1', 'sale_us_1')
        );

        $this->assertEqualsWithDelta(1000, $overview['current_month']['gross_sales_mxn'], 0.001);
        $this->assertEqualsWithDelta(100, $overview['current_month']['gross_sales_usd'], 0.001);
        $this->assertSame(0, $overview['current_month']['unsettled_sales_count']);
        $this->assertEqualsWithDelta(2662.5, $overview['current_month']['net_received_mxn'], 0.001);
        $this->assertEqualsWithDelta(87.5, $overview['current_month']['fee_mxn_equivalent'], 0.001);
        $this->assertEqualsWithDelta(300, $overview['current_month']['expenses_mxn_equivalent'], 0.001);
        $this->assertEqualsWithDelta(2362.5, $overview['current_month']['operating_profit_mxn'], 0.001);

        $this->assertEqualsWithDelta(180, $overview['lifetime']['gross_sales_usd'], 0.001);
        $this->assertEqualsWithDelta(80, $overview['lifetime']['unsettled_sales_usd'], 0.001);
        $this->assertSame(1, $overview['lifetime']['unsettled_sales_count']);
        $this->assertEqualsWithDelta(640, $overview['lifetime']['expenses_mxn_equivalent'], 0.001);
        $this->assertEqualsWithDelta(2022.5, $overview['lifetime']['operating_profit_mxn'], 0.001);
    }
}
