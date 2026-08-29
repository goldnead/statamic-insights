<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\RevenueReport;
use Goldnead\StatamicInsights\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The numbers behind the screen.
 *
 * Every test here is one way a revenue report is quietly wrong: a refund
 * credited to the wrong month, two currencies added together, a bump crediting
 * its whole payment to the main product, a quiet week missing from the chart
 * because nothing was sold in it.
 */
class RevenueReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createPaymentsSchema();
    }

    protected function zahlung(array $overrides = []): int
    {
        return DB::table('payments')->insertGetId(array_merge([
            'provider' => 'mollie',
            'provider_id' => 'tr_'.uniqid(),
            'product' => 'noten',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => 'paid',
            'email' => 'wer@example.com',
            'paid_at' => Carbon::now()->subDays(2),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $overrides));
    }

    protected function bericht(string $preset = '30d', string $currency = 'EUR'): RevenueReport
    {
        return new RevenueReport(Period::fromPreset($preset), $currency);
    }

    // -- The headline -------------------------------------------------------

    #[Test]
    public function it_adds_up_what_was_paid(): void
    {
        $this->zahlung(['amount_cent' => 1900]);
        $this->zahlung(['amount_cent' => 500, 'email' => 'zweite@example.com']);

        $summen = $this->bericht()->totals();

        $this->assertSame(2400, $summen['gross_cent']);
        $this->assertSame(2, $summen['orders']);
        $this->assertSame(2, $summen['buyers']);
        $this->assertSame(1200, $summen['average_cent']);
    }

    /** Two orders from one person are two orders and one buyer. */
    #[Test]
    public function it_counts_a_repeat_buyer_once(): void
    {
        $this->zahlung();
        $this->zahlung();

        $summen = $this->bericht()->totals();

        $this->assertSame(2, $summen['orders']);
        $this->assertSame(1, $summen['buyers']);
    }

    /** Only paid orders. An abandoned checkout is not revenue. */
    #[Test]
    public function it_ignores_everything_that_was_not_paid(): void
    {
        $this->zahlung(['amount_cent' => 1000]);
        $this->zahlung(['amount_cent' => 9999, 'status' => 'open']);
        $this->zahlung(['amount_cent' => 8888, 'status' => 'failed']);

        $this->assertSame(1000, $this->bericht()->totals()['gross_cent']);
    }

    /**
     * A refund belongs to the month the money left, not the month of the sale.
     *
     * The alternative — crediting it against the sale — makes last month's
     * closed figure change after the fact, which is the property a reported
     * number must never have.
     */
    #[Test]
    public function a_refund_counts_when_it_happened_not_when_the_sale_did(): void
    {
        $this->zahlung([
            'amount_cent' => 10000,
            'paid_at' => Carbon::now()->subMonths(6),
            'refunded_cent' => 4000,
            'refunded_at' => Carbon::now()->subDay(),
        ]);

        $summen = $this->bericht('30d')->totals();

        // The sale is outside the window, the refund is inside it.
        $this->assertSame(0, $summen['gross_cent']);
        $this->assertSame(4000, $summen['refunded_cent']);
        $this->assertSame(-4000, $summen['net_cent']);
    }

    #[Test]
    public function it_reports_the_refund_rate(): void
    {
        $this->zahlung([
            'amount_cent' => 10000,
            'refunded_cent' => 2500,
            'refunded_at' => Carbon::now()->subDay(),
        ]);

        $this->assertSame(25.0, $this->bericht()->totals()['refund_rate']);
    }

    /** No sales at all must produce zeroes, never a division by zero. */
    #[Test]
    public function an_empty_period_reports_zeroes(): void
    {
        $summen = $this->bericht()->totals();

        $this->assertSame(0, $summen['gross_cent']);
        $this->assertSame(0, $summen['average_cent']);
        // Not "0%". A rate against nothing is a question that does not apply.
        $this->assertNull($summen['refund_rate']);
    }

    /**
     * A refund with no sales beside it must not print "0%".
     *
     * The screen shows the refunded amount right next to the rate; a zero there
     * is a statement contradicted by the figure beside it, which is worse than
     * saying nothing.
     */
    #[Test]
    public function a_refund_without_sales_reports_no_rate_at_all(): void
    {
        $this->zahlung([
            'amount_cent' => 10000,
            'paid_at' => Carbon::now()->subMonths(6),
            'refunded_cent' => 1500,
            'refunded_at' => Carbon::now()->subDay(),
        ]);

        $summen = $this->bericht('30d')->totals();

        $this->assertSame(0, $summen['gross_cent']);
        $this->assertSame(1500, $summen['refunded_cent']);
        $this->assertSame(-1500, $summen['net_cent']);
        $this->assertNull($summen['refund_rate']);
    }

    /** A cancelled payment with a refund on it is not a refund of revenue. */
    #[Test]
    public function a_refund_on_a_payment_that_was_never_paid_is_ignored(): void
    {
        $this->zahlung([
            'status' => 'canceled',
            'amount_cent' => 5000,
            'refunded_cent' => 5000,
            'refunded_at' => Carbon::now()->subDay(),
        ]);

        $this->assertSame(0, $this->bericht()->totals()['refunded_cent']);
    }

    /**
     * Adding 100 EUR to 100 CHF produces a number with no meaning. The report
     * filters to one currency and says what it left out.
     */
    #[Test]
    public function it_never_adds_two_currencies_together(): void
    {
        $this->zahlung(['amount_cent' => 1000, 'currency' => 'EUR']);
        $this->zahlung(['amount_cent' => 5000, 'currency' => 'CHF']);

        $bericht = $this->bericht('30d', 'EUR');

        $this->assertSame(1000, $bericht->totals()['gross_cent']);
        $this->assertSame(['CHF'], $bericht->otherCurrencies());
    }

    /** The comparison is the point: a figure alone says nothing. */
    #[Test]
    public function it_reports_the_period_before_for_comparison(): void
    {
        $this->zahlung(['amount_cent' => 1000, 'paid_at' => Carbon::now()->subDays(2)]);
        $this->zahlung(['amount_cent' => 4000, 'paid_at' => Carbon::now()->subDays(40)]);

        $summen = $this->bericht('30d')->totals();

        $this->assertSame(1000, $summen['gross_cent']);
        $this->assertSame(4000, $summen['previous']['gross_cent']);
    }

    /** "All time" has no period before it, and says so instead of inventing one. */
    #[Test]
    public function all_time_has_nothing_to_compare_against(): void
    {
        $this->zahlung();

        $this->assertNull($this->bericht('all')->totals()['previous']);
    }

    // -- Campaigns ----------------------------------------------------------

    #[Test]
    public function it_credits_revenue_to_the_campaign_that_produced_it(): void
    {
        $this->zahlung(['amount_cent' => 3000, 'utm_campaign' => 'sommer', 'utm_source' => 'newsletter']);
        $this->zahlung(['amount_cent' => 1000, 'utm_campaign' => 'sommer', 'utm_source' => 'newsletter']);
        $this->zahlung(['amount_cent' => 2000, 'utm_campaign' => 'herbst', 'utm_source' => 'instagram']);

        $zeilen = $this->bericht()->byCampaign();

        $this->assertSame('sommer', $zeilen[0]['campaign']);
        $this->assertSame(4000, $zeilen[0]['gross_cent']);
        $this->assertSame(2, $zeilen[0]['orders']);
        $this->assertSame('herbst', $zeilen[1]['campaign']);
    }

    /**
     * A sale with no campaign is grouped, never dropped.
     *
     * A report that quietly excludes rows is the hardest kind of wrong to
     * notice: the totals and the table disagree, and nothing says why.
     */
    #[Test]
    public function sales_without_a_campaign_still_appear(): void
    {
        $this->zahlung(['amount_cent' => 1000, 'utm_campaign' => 'sommer']);
        $this->zahlung(['amount_cent' => 700]);

        $zeilen = $this->bericht()->byCampaign();

        $this->assertSame(1700, array_sum(array_column($zeilen, 'gross_cent')));
        $this->assertContains(null, array_column($zeilen, 'campaign'));
    }

    // -- Products -----------------------------------------------------------

    /**
     * A bump and its main product are one payment and two products. Crediting
     * the whole amount to the first overstates one and hides the other.
     */
    #[Test]
    public function it_splits_a_payment_across_its_line_items(): void
    {
        $id = $this->zahlung(['amount_cent' => 2400, 'product' => 'noten']);

        DB::table('payment_items')->insert([
            ['payment_id' => $id, 'product' => 'noten', 'amount_cent' => 1900, 'quantity' => 1, 'discount_cent' => 0, 'kind' => 'primary'],
            ['payment_id' => $id, 'product' => 'cd', 'amount_cent' => 500, 'quantity' => 1, 'discount_cent' => 0, 'kind' => 'bump'],
        ]);

        $zeilen = collect($this->bericht()->byProduct())->keyBy('handle');

        $this->assertSame(1900, $zeilen['noten']['gross_cent']);
        $this->assertSame(500, $zeilen['cd']['gross_cent']);
        $this->assertSame(2400, $zeilen->sum('gross_cent'));
    }

    #[Test]
    public function it_takes_quantity_and_the_line_discount_into_account(): void
    {
        $id = $this->zahlung(['amount_cent' => 2700, 'product' => 'noten']);

        DB::table('payment_items')->insert([
            ['payment_id' => $id, 'product' => 'noten', 'amount_cent' => 1000, 'quantity' => 3, 'discount_cent' => 300, 'kind' => 'primary'],
        ]);

        $this->assertSame(2700, $this->bericht()->byProduct()[0]['gross_cent']);
        $this->assertSame(3, $this->bericht()->byProduct()[0]['quantity']);
    }

    /** A payment written before line items existed still counts. */
    #[Test]
    public function a_payment_without_line_items_is_not_lost(): void
    {
        $this->zahlung(['amount_cent' => 1500, 'product' => 'altbestand']);

        $zeilen = collect($this->bericht()->byProduct())->keyBy('handle');

        $this->assertSame(1500, $zeilen['altbestand']['gross_cent']);
    }

    /** Without the catalogue there is still a name: the handle itself. */
    #[Test]
    public function a_product_with_no_catalogue_entry_keeps_its_handle(): void
    {
        $this->zahlung(['product' => 'unbekannt']);

        $this->assertSame('unbekannt', $this->bericht()->byProduct()[0]['name']);
    }

    // -- Over time ----------------------------------------------------------

    /**
     * Every day in the range, including the quiet ones.
     *
     * A chart built only from days that had sales skips the empty weeks and
     * draws a bad month as a good one.
     */
    #[Test]
    public function the_chart_contains_every_bucket_not_only_the_ones_with_sales(): void
    {
        $this->zahlung(['amount_cent' => 1000, 'paid_at' => Carbon::now()->subDays(3)]);

        $verlauf = $this->bericht('7d')->overTime();

        $this->assertCount(7, $verlauf);
        $this->assertSame(1000, array_sum(array_column($verlauf, 'gross_cent')));
        $this->assertContains(0, array_column($verlauf, 'gross_cent'));
    }

    /** A long range is grouped by month, or the chart has 365 columns. */
    #[Test]
    public function a_long_range_is_grouped_by_month(): void
    {
        $this->zahlung(['paid_at' => Carbon::now()->subMonths(2)]);

        $verlauf = $this->bericht('12m')->overTime();

        $this->assertCount(12, $verlauf);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $verlauf[0]['bucket']);
    }

    #[Test]
    public function without_the_payments_tables_the_report_is_empty_rather_than_broken(): void
    {
        Schema::drop('payment_items');
        Schema::drop('payments');

        $this->assertFalse(RevenueReport::available());
        $this->assertSame([], $this->bericht()->byCampaign());
        $this->assertSame(0, $this->bericht()->totals()['gross_cent']);
        $this->assertSame([], $this->bericht()->byProduct());
        // The one the first version forgot. `overTime()` fell through to the
        // open-ended branch, queried the missing table and answered the whole
        // screen with a 500 — which made the carefully worded "the payments
        // addon is not installed" state unreachable code nobody ever saw.
        $this->assertSame([], $this->bericht()->overTime());
        $this->assertSame([], $this->bericht('all')->overTime());
        $this->assertSame(0, $this->bericht()->productSumCent());
    }
}
