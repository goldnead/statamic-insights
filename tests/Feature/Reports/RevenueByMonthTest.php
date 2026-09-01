<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Reports;

use Goldnead\StatamicInsights\Reports\RevenueByMonth;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

class RevenueByMonthTest extends ReportsTestCase
{
    /** Without the payments addon there is nothing to read, and the report says so rather than throwing. */
    #[Test]
    public function it_is_unavailable_and_names_the_package_when_payments_is_missing(): void
    {
        $report = new RevenueByMonth;

        $this->assertFalse($report->available());
        $this->assertSame('goldnead/statamic-payments', $report->requires());
    }

    #[Test]
    public function it_sums_paid_payments_per_month_and_currency_with_count_and_average(): void
    {
        $this->createPaymentsTables();
        Carbon::setTestNow('2026-08-20 12:00:00');

        $this->payment(['amount_cent' => 10000, 'paid_at' => Carbon::parse('2026-08-03 10:00')]);
        $this->payment(['amount_cent' => 5000, 'paid_at' => Carbon::parse('2026-08-15 10:00')]);
        $this->payment(['amount_cent' => 7000, 'currency' => 'CHF', 'paid_at' => Carbon::parse('2026-08-15 11:00')]);
        $this->payment(['amount_cent' => 2000, 'paid_at' => Carbon::parse('2026-07-30 10:00')]);
        // Neither paid nor placed in time: not a row anywhere.
        $this->payment(['amount_cent' => 99900, 'status' => 'open']);

        $rows = (new RevenueByMonth)->rows($this->frage('90d'));

        $august = $this->row(array_filter($rows, fn ($r) => $r['currency'] === 'EUR'), 'month', '2026-08');
        $this->assertSame(15000, $august['revenue_cent']);
        $this->assertSame(2, $august['payments']);
        $this->assertSame(7500, $august['average_cent']);

        $franken = $this->row(array_filter($rows, fn ($r) => $r['currency'] === 'CHF'), 'month', '2026-08');
        $this->assertSame(7000, $franken['revenue_cent']);

        $juli = $this->row(array_filter($rows, fn ($r) => $r['currency'] === 'EUR'), 'month', '2026-07');
        $this->assertSame(2000, $juli['revenue_cent']);

        // Newest month first, so the row a reader wants is on top.
        $this->assertSame('2026-08', $rows[0]['month']);
    }

    #[Test]
    public function the_period_narrows_the_rows(): void
    {
        $this->createPaymentsTables();
        Carbon::setTestNow('2026-08-20 12:00:00');

        $this->payment(['amount_cent' => 1000, 'paid_at' => Carbon::parse('2026-08-19 10:00')]);
        $this->payment(['amount_cent' => 1000, 'paid_at' => Carbon::parse('2026-05-01 10:00')]);

        $this->assertCount(1, (new RevenueByMonth)->rows($this->frage('30d')));
        $this->assertCount(2, (new RevenueByMonth)->rows($this->frage('all')));
    }
}
