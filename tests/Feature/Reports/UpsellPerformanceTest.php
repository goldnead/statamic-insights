<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Reports;

use Goldnead\StatamicInsights\Reports\UpsellPerformance;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

class UpsellPerformanceTest extends ReportsTestCase
{
    #[Test]
    public function it_is_unavailable_when_offers_is_missing(): void
    {
        $report = new UpsellPerformance;

        $this->assertFalse($report->available());
        $this->assertSame('goldnead/statamic-offers', $report->requires());
    }

    #[Test]
    public function it_lists_bumps_and_post_purchase_offers_with_conversion_and_revenue(): void
    {
        $this->createOffersTable();
        $this->createPaymentsTables();
        Carbon::setTestNow('2026-08-20 12:00:00');

        $this->offer(['handle' => 'workbook-bump', 'name' => 'Workbook dazu', 'product' => 'workbook', 'slot' => 'bump', 'shown_count' => 200, 'accepted_count' => 30]);
        $this->offer(['handle' => 'coaching-danach', 'name' => 'Coaching danach', 'product' => 'coaching', 'slot' => 'post_purchase', 'shown_count' => 0, 'accepted_count' => 0]);
        // Standalone offers are the main sale, not an upsell: not a row.
        $this->offer(['handle' => 'kurs', 'name' => 'Kurs', 'product' => 'kurs', 'slot' => 'standalone', 'shown_count' => 999, 'accepted_count' => 999]);

        $paid = $this->payment(['amount_cent' => 5800]);
        $this->item($paid, ['product' => 'kurs', 'amount_cent' => 4900, 'kind' => 'primary']);
        $this->item($paid, ['product' => 'workbook', 'amount_cent' => 900, 'kind' => 'bump']);

        $upsell = $this->payment(['amount_cent' => 19900]);
        $this->item($upsell, ['product' => 'coaching', 'amount_cent' => 19900, 'kind' => 'upsell']);

        // The workbook sold as a primary elsewhere is not bump revenue.
        $primary = $this->payment(['amount_cent' => 900]);
        $this->item($primary, ['product' => 'workbook', 'amount_cent' => 900, 'kind' => 'primary']);

        $rows = (new UpsellPerformance)->rows($this->frage('30d'));

        $this->assertCount(2, $rows);

        $bump = $this->row($rows, 'handle', 'workbook-bump');
        $this->assertSame(200, $bump['shown']);
        $this->assertSame(30, $bump['accepted']);
        $this->assertSame(15.0, $bump['conversion']);
        $this->assertSame(900, $bump['revenue_cent']);
        $this->assertSame('EUR', $bump['currency']);

        // Never shown: the conversion has no answer, and is null rather than 0.
        $danach = $this->row($rows, 'handle', 'coaching-danach');
        $this->assertNull($danach['conversion']);
        $this->assertSame(19900, $danach['revenue_cent']);
    }

    /** Offers alone, without payments: the counters show and the revenue is unmeasured, not zero. */
    #[Test]
    public function revenue_is_null_when_payments_is_not_installed(): void
    {
        $this->createOffersTable();

        $this->offer(['handle' => 'workbook-bump', 'shown_count' => 10, 'accepted_count' => 1]);

        $rows = (new UpsellPerformance)->rows($this->frage('30d'));

        $this->assertSame(10, $rows[0]['shown']);
        $this->assertNull($rows[0]['revenue_cent']);
    }
}
