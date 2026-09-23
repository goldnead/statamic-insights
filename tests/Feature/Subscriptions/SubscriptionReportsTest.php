<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Subscriptions;

use Goldnead\StatamicInsights\Reports\MrrMovements;
use Goldnead\StatamicInsights\Reports\SubscriptionCohorts;
use Goldnead\StatamicInsights\Reports\SubscriptionForecast;
use Goldnead\StatamicInsights\Reports\UpcomingCharges;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Neighbours;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Tests\Fakes\BrandManagerStandIn;
use Goldnead\StatamicInsights\Tests\Feature\Reports\ReportsTestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * The four subscription tables, through the report contract every other table
 * on the reports screen goes through.
 */
class SubscriptionReportsTest extends ReportsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function ledger(): void
    {
        $this->createPaymentsTables();
        $this->createSubscriptionsTable();
    }

    #[Test]
    public function without_the_subscriptions_table_every_report_says_it_needs_payments(): void
    {
        // Payments installed, but from before agreements existed.
        $this->createPaymentsTables();

        foreach ([new MrrMovements, new SubscriptionCohorts, new UpcomingCharges, new SubscriptionForecast] as $report) {
            $this->assertFalse($report->available(), $report->handle());
            $this->assertSame('goldnead/statamic-payments', $report->requires());
        }
    }

    #[Test]
    public function without_payments_at_all_nothing_is_read(): void
    {
        Neighbours::pretend(Neighbours::PAYMENTS, false);

        $this->assertFalse((new MrrMovements)->available());
    }

    #[Test]
    public function mrr_movements_have_one_row_per_month_and_currency_and_never_add_currencies(): void
    {
        $this->ledger();
        $this->subscription(['amount_cent' => 1000, 'starts_at' => '2026-07-10 09:00:00']);
        $this->subscription(['amount_cent' => 1500, 'starts_at' => '2026-08-10 09:00:00']);
        $this->subscription(['amount_cent' => 2000, 'currency' => 'CHF', 'starts_at' => '2026-08-11 09:00:00']);

        $rows = (new MrrMovements)->rows(new MetricQuery(Period::fromPreset('12m')));

        $august = array_values(array_filter($rows, fn ($r) => $r['month'] === '2026-08'));

        $this->assertCount(2, $august);
        $eur = $this->row($august, 'currency', 'EUR');
        $chf = $this->row($august, 'currency', 'CHF');
        $this->assertSame(1000, $eur['mrr_start']);
        $this->assertSame(1500, $eur['new']);
        $this->assertSame(2500, $eur['mrr_end']);
        $this->assertSame(2000, $chf['new']);
        $this->assertSame(2000, $chf['mrr_end']);

        // The running month ends now, not at the end of September.
        $this->assertSame('2026-09', $rows[0]['month']);
        // Nothing before the first agreement.
        $this->assertNull($this->row($rows, 'month', '2026-06'));
    }

    #[Test]
    public function the_upcoming_charges_are_a_snapshot_in_date_order(): void
    {
        $this->ledger();
        $this->subscription(['amount_cent' => 1900, 'product' => 'mitgliedschaft', 'name' => 'Ana', 'next_payment_at' => '2026-09-20 09:00:00']);
        $this->subscription(['amount_cent' => 39900, 'product' => 'ausbildung', 'times' => 2, 'times_charged' => 1, 'next_payment_at' => '2026-09-17 09:00:00']);

        $report = new UpcomingCharges;
        $rows = $report->rows(new MetricQuery(Period::fromPreset('30d')));

        $this->assertFalse($report->usesPeriod());
        $this->assertSame(['ausbildung', 'mitgliedschaft'], array_column($rows, 'product'));
        $this->assertSame('Ana', $rows[1]['customer']);
        $this->assertSame([39900, 1900], array_column($rows, 'amount_cent'));
    }

    #[Test]
    public function the_forecast_and_the_cohorts_answer_as_of_now(): void
    {
        $this->ledger();
        $this->subscription(['amount_cent' => 1900, 'starts_at' => '2026-07-10 09:00:00', 'next_payment_at' => '2026-09-20 09:00:00']);

        $prognose = (new SubscriptionForecast)->rows(new MetricQuery(Period::fromPreset('30d')));
        $kohorten = (new SubscriptionCohorts)->rows(new MetricQuery(Period::fromPreset('30d')));

        $this->assertSame(1900, $this->row($prognose, 'month', '2026-09')['total_cent']);
        $this->assertSame(1, $this->row($kohorten, 'cohort', '2026-07')['started']);
    }

    #[Test]
    public function the_figures_follow_the_current_brand(): void
    {
        $this->ledger();
        $this->subscription(['brand_id' => 1, 'amount_cent' => 1000, 'starts_at' => '2026-08-10 09:00:00']);
        $this->subscription(['brand_id' => 2, 'amount_cent' => 5000, 'starts_at' => '2026-08-10 09:00:00']);

        $this->app->instance('brand-context', new BrandManagerStandIn(current: 2));

        $rows = (new MrrMovements)->rows(new MetricQuery(Period::fromPreset('12m')));

        $this->assertSame(5000, $this->row($rows, 'month', '2026-08')['new']);
    }
}
