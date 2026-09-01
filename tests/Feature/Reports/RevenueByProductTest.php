<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Reports;

use Goldnead\StatamicInsights\Reports\RevenueByProduct;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

class RevenueByProductTest extends ReportsTestCase
{
    #[Test]
    public function it_is_unavailable_when_payments_is_missing(): void
    {
        $this->assertFalse((new RevenueByProduct)->available());
    }

    /** Line items, not the payment's product column: a bump is credited to itself. */
    #[Test]
    public function it_credits_each_line_item_to_its_own_product(): void
    {
        $this->createPaymentsTables();
        Carbon::setTestNow('2026-08-20 12:00:00');

        $one = $this->payment(['amount_cent' => 5800, 'product' => 'kurs']);
        $this->item($one, ['product' => 'kurs', 'name' => 'Kurs', 'amount_cent' => 4900]);
        $this->item($one, ['product' => 'workbook', 'name' => 'Workbook', 'amount_cent' => 900, 'kind' => 'bump']);

        $two = $this->payment(['amount_cent' => 4900, 'product' => 'kurs']);
        $this->item($two, ['product' => 'kurs', 'name' => 'Kurs (neu)', 'amount_cent' => 4900]);

        // Unpaid: its items do not count.
        $offen = $this->payment(['status' => 'open']);
        $this->item($offen, ['product' => 'kurs', 'amount_cent' => 4900]);

        $rows = (new RevenueByProduct)->rows($this->frage('30d'));

        $kurs = $this->row($rows, 'product', 'kurs');
        $this->assertSame(9800, $kurs['revenue_cent']);
        $this->assertSame(2, $kurs['orders']);
        $this->assertSame(2, $kurs['quantity']);

        $workbook = $this->row($rows, 'product', 'workbook');
        $this->assertSame(900, $workbook['revenue_cent']);
        $this->assertSame('Workbook', $workbook['name']);

        // Largest first.
        $this->assertSame('kurs', $rows[0]['product']);
    }

    #[Test]
    public function two_currencies_are_two_rows(): void
    {
        $this->createPaymentsTables();

        $eur = $this->payment(['currency' => 'EUR']);
        $this->item($eur, ['product' => 'kurs']);
        $chf = $this->payment(['currency' => 'CHF']);
        $this->item($chf, ['product' => 'kurs']);

        $rows = (new RevenueByProduct)->rows($this->frage('30d'));

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['EUR', 'CHF'], array_column($rows, 'currency'));
    }
}
