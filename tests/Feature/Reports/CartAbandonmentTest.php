<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Reports;

use Goldnead\StatamicInsights\Reports\CartAbandonment;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

class CartAbandonmentTest extends ReportsTestCase
{
    #[Test]
    public function it_is_unavailable_when_payments_is_missing(): void
    {
        $this->assertFalse((new CartAbandonment)->available());
    }

    /** A cohort on the month the checkout was opened, with the rate over those same rows. */
    #[Test]
    public function it_compares_paid_against_open_and_expired_per_month_opened(): void
    {
        $this->createPaymentsTables();
        Carbon::setTestNow('2026-08-20 12:00:00');

        $august = Carbon::parse('2026-08-05 10:00');
        $juli = Carbon::parse('2026-07-05 10:00');

        $this->payment(['status' => 'paid', 'created_at' => $august, 'paid_at' => $august]);
        $this->payment(['status' => 'paid', 'created_at' => $august, 'paid_at' => $august]);
        $this->payment(['status' => 'paid', 'created_at' => $august, 'paid_at' => $august]);
        $this->payment(['status' => 'open', 'created_at' => $august]);
        $this->payment(['status' => 'expired', 'created_at' => $august]);
        // Never reached the provider, or explicitly failed: in neither column.
        $this->payment(['status' => 'initiated', 'created_at' => $august]);
        $this->payment(['status' => 'failed', 'created_at' => $august]);

        // Opened in July, paid in August: belongs to July's cohort.
        $this->payment(['status' => 'paid', 'created_at' => $juli, 'paid_at' => Carbon::parse('2026-08-01 09:00')]);
        $this->payment(['status' => 'open', 'created_at' => $juli]);

        $rows = (new CartAbandonment)->rows($this->frage('90d'));

        $aug = $this->row($rows, 'month', '2026-08');
        $this->assertSame(3, $aug['paid']);
        $this->assertSame(2, $aug['abandoned']);
        $this->assertSame(40.0, $aug['rate']);

        $jul = $this->row($rows, 'month', '2026-07');
        $this->assertSame(1, $jul['paid']);
        $this->assertSame(1, $jul['abandoned']);
        $this->assertSame(50.0, $jul['rate']);
    }

    #[Test]
    public function a_month_with_only_paid_rows_has_a_zero_rate_and_an_empty_month_no_row(): void
    {
        $this->createPaymentsTables();
        Carbon::setTestNow('2026-08-20 12:00:00');

        $this->payment(['status' => 'paid', 'created_at' => Carbon::parse('2026-08-05 10:00')]);

        $rows = (new CartAbandonment)->rows($this->frage('30d'));

        $this->assertCount(1, $rows);
        $this->assertSame(0.0, $rows[0]['rate']);
    }
}
