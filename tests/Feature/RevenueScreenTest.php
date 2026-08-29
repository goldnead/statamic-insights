<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\RevenueReport;
use Goldnead\StatamicInsights\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The screen's two kinds of nothing, and the shape of its period.
 *
 * "No payments addon" is a setup problem with an answer; "no sales yet" is a
 * true and temporary state. One empty screen for both sends somebody looking
 * for a bug that is not there, so the two are separate props and separate
 * copy — asserted here so a refactor cannot quietly merge them.
 */
class RevenueScreenTest extends TestCase
{
    #[Test]
    public function without_the_payments_tables_it_says_the_addon_is_missing(): void
    {
        $this->assertFalse(RevenueReport::available());
    }

    #[Test]
    public function with_the_tables_but_no_sales_it_is_a_different_state(): void
    {
        $this->createPaymentsSchema();

        $this->assertTrue(RevenueReport::available());
        $this->assertSame([], RevenueReport::currencies());
    }

    /**
     * The end of a period includes the whole of its last day.
     *
     * "1. to 31. August" means the 31st, not up to midnight at its start — the
     * most common off-by-one in every report anybody has written.
     */
    #[Test]
    public function a_period_ends_at_the_end_of_its_last_day(): void
    {
        $zeitraum = Period::fromPreset('7d');

        $this->assertSame(23, (int) $zeitraum->to->format('H'));
        $this->assertSame(7, $zeitraum->days());
    }

    /** The period before is the same length, ending where this one begins. */
    #[Test]
    public function the_comparison_period_is_the_same_length_and_does_not_overlap(): void
    {
        $jetzt = Period::fromPreset('30d');
        $davor = $jetzt->previous();

        $this->assertSame($jetzt->days(), $davor->days());
        $this->assertTrue($davor->to->lessThan($jetzt->from));
    }

    /** An unknown preset falls back rather than producing an empty range. */
    #[Test]
    public function an_unknown_period_falls_back_to_the_default(): void
    {
        $this->assertSame('30d', Period::fromPreset('bananen')->preset);
        $this->assertSame('30d', Period::fromPreset(null)->preset);
    }

    /** "All time" is open ended and has nothing before it. */
    #[Test]
    public function all_time_has_no_bounds_and_no_predecessor(): void
    {
        $zeitraum = Period::fromPreset('all');

        $this->assertTrue($zeitraum->isOpenEnded());
        $this->assertNull($zeitraum->previous());
    }
}
