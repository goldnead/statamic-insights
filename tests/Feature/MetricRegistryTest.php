<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use Goldnead\StatamicInsights\Facades\Insights;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\MetricReader;
use Goldnead\StatamicInsights\Support\MetricRegistry;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Tests\Fakes\BrokenMetric;
use Goldnead\StatamicInsights\Tests\Fakes\CountingMetric;
use Goldnead\StatamicInsights\Tests\Fakes\ThrowsWhenBuilt;
use Goldnead\StatamicInsights\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * The seam every other addon reaches this one through.
 *
 * It is the whole public surface, semver-locked from the first release, so the
 * tests here are as much a specification as a check: what a contributor may
 * assume, and what this addon promises to do around the number it is handed.
 */
class MetricRegistryTest extends TestCase
{
    protected function registry(): MetricRegistry
    {
        return app(MetricRegistry::class);
    }

    protected function reader(): MetricReader
    {
        return app(MetricReader::class);
    }

    protected function frage(string $preset = '30d'): MetricQuery
    {
        $period = Period::fromPreset($preset);

        return new MetricQuery($period, MetricQuery::bucketFor($period));
    }

    // -- Registering --------------------------------------------------------

    #[Test]
    public function an_addon_registers_a_metric_and_it_appears(): void
    {
        Insights::registerMetric(new CountingMetric);

        $this->assertContains('test.counted', Insights::metricHandles());
        $this->assertArrayHasKey('test.counted', Insights::metrics());
    }

    /**
     * The registry is one object for the whole request.
     *
     * A sibling registers while booting; a registry rebuilt per resolution
     * would be a different object from the one the screen reads, and every
     * contributed metric would vanish with nothing to see.
     */
    #[Test]
    public function the_registry_is_the_same_object_everywhere(): void
    {
        $this->assertSame(app(MetricRegistry::class), app(MetricRegistry::class));

        Insights::registerMetric(new CountingMetric);

        $this->assertNotNull($this->registry()->find('test.counted'));
    }

    /** The same handle twice replaces, never duplicates. */
    #[Test]
    public function registering_the_same_handle_twice_shows_it_once(): void
    {
        Insights::registerMetric(new CountingMetric(fixed: 1));
        Insights::registerMetric(new CountingMetric(fixed: 99));

        $this->assertSame(['test.counted'], Insights::metricHandles());
        $this->assertSame(99, Insights::metric('test.counted')->value($this->frage()));
    }

    /**
     * A metric that cannot answer is absent, not zero.
     *
     * "Nothing to measure" and "measured nothing" are different statements, and
     * a zero for the first is the quiet kind of wrong.
     */
    #[Test]
    public function an_unavailable_metric_is_left_out_entirely(): void
    {
        Insights::registerMetric(new CountingMetric(isAvailable: false));

        $this->assertContains('test.counted', Insights::metricHandles());
        $this->assertArrayNotHasKey('test.counted', Insights::metrics());
        $this->assertSame([], $this->reader()->overview($this->frage()));
    }

    #[Test]
    public function an_unregistered_handle_is_null_rather_than_an_error(): void
    {
        $this->assertNull(Insights::metric('nichts.da'));
    }

    /**
     * The way production actually registers: a class name and a handle.
     *
     * Every one of payments' seven metrics comes in this way, and until now no
     * test in this addon used it — the suites on both sides were green about a
     * path neither of them crossed.
     */
    #[Test]
    public function a_metric_can_be_registered_by_class_name_with_a_handle(): void
    {
        Insights::registerMetric(CountingMetric::class, 'test.counted');

        $this->assertContains('test.counted', Insights::metricHandles());
        $this->assertInstanceOf(CountingMetric::class, Insights::metric('test.counted'));
    }

    /** And by class name alone, which costs a construction to find the handle. */
    #[Test]
    public function a_metric_can_be_registered_by_class_name_alone(): void
    {
        Insights::registerMetric(CountingMetric::class);

        $this->assertContains('test.counted', Insights::metricHandles());
    }

    /**
     * A closure without a handle is refused, not silently kept.
     *
     * It cannot be probed for its handle without calling it, and calling it is
     * the laziness the registry exists to keep.
     */
    #[Test]
    public function a_closure_without_a_handle_is_refused(): void
    {
        Insights::registerMetric(fn () => new CountingMetric);

        $this->assertSame([], Insights::metricHandles());
    }

    #[Test]
    public function a_closure_with_a_handle_is_resolved_when_somebody_asks(): void
    {
        Insights::registerMetric(fn () => new CountingMetric(fixed: 77), 'test.counted');

        $this->assertSame(77, Insights::metric('test.counted')->value($this->frage()));
    }

    /** A class that throws while constructing is absent, not fatal. */
    #[Test]
    public function a_metric_whose_construction_throws_is_left_out(): void
    {
        Insights::registerMetric(ThrowsWhenBuilt::class, 'test.explodiert');

        $this->assertNull(Insights::metric('test.explodiert'));
        $this->assertSame([], Insights::metrics());
    }

    // -- Reading ------------------------------------------------------------

    #[Test]
    public function it_reports_the_value_the_previous_period_and_the_change(): void
    {
        $heute = Carbon::now()->format('Y-m-d');
        $frueher = Carbon::now()->subDays(40)->format('Y-m-d');

        Insights::registerMetric(new CountingMetric(perBucket: [$heute => 30, $frueher => 10]));

        $gelesen = $this->reader()->read(Insights::metric('test.counted'), $this->frage('30d'));

        $this->assertSame(30, $gelesen['value']);
        $this->assertSame(10, $gelesen['previous']);
        $this->assertSame(200, $gelesen['delta']);
    }

    /**
     * No percentage where one would be a claim.
     *
     * Every increase from nothing is infinite, and "+∞ %" tells a reader less
     * than the absolute number already did.
     */
    #[Test]
    public function growth_from_zero_reports_no_percentage(): void
    {
        $heute = Carbon::now()->format('Y-m-d');

        Insights::registerMetric(new CountingMetric(perBucket: [$heute => 30]));

        $this->assertNull($this->reader()->read(Insights::metric('test.counted'), $this->frage('30d'))['delta']);
    }

    /** "All time" has no period before it and says so rather than inventing one. */
    #[Test]
    public function an_open_ended_period_has_nothing_to_compare(): void
    {
        Insights::registerMetric(new CountingMetric(fixed: 5));

        $gelesen = $this->reader()->read(Insights::metric('test.counted'), $this->frage('all'));

        $this->assertNull($gelesen['previous']);
        $this->assertNull($gelesen['delta']);
    }

    /**
     * An open-ended period gets its span from the data.
     *
     * There is nothing else to define it. Returning an empty series drew a
     * blank chart beside a figure of over a thousand euros — a state the number
     * next to it disproves, which is the failure this whole family keeps
     * writing tests against.
     */
    #[Test]
    public function all_time_takes_its_range_from_the_buckets_the_metric_reported(): void
    {
        Insights::registerMetric(new CountingMetric(perBucket: [
            '2026-08-01' => 5,
            '2026-08-04' => 9,
        ]));

        $reihe = $this->reader()->series(Insights::metric('test.counted'), $this->frage('all'));

        $this->assertCount(4, $reihe);
        $this->assertSame('2026-08-01', $reihe[0]['bucket']);
        $this->assertSame('2026-08-04', $reihe[3]['bucket']);
        $this->assertSame(14, array_sum(array_column($reihe, 'value')));
    }

    /**
     * A bucket the metric left null stays null.
     *
     * Omitted means "nothing happened" and becomes a zero; null means "the
     * question does not apply here" — a rate on a day with no denominator — and
     * a zero for that is the same lie the contract forbids for `value()`.
     */
    #[Test]
    public function a_null_bucket_survives_the_filling(): void
    {
        $heute = Carbon::now()->format('Y-m-d');
        $gestern = Carbon::now()->subDay()->format('Y-m-d');

        Insights::registerMetric(new CountingMetric(perBucket: [$gestern => null, $heute => 4]));

        $reihe = collect($this->reader()->series(Insights::metric('test.counted'), $this->frage('7d')))
            ->keyBy('bucket');

        $this->assertNull($reihe[$gestern]['value']);
        $this->assertSame(4, $reihe[$heute]['value']);
    }

    /**
     * The reader fills the empty buckets, not the metric.
     *
     * A chart built only from the buckets that had data skips the quiet weeks
     * and draws a bad month as a good one — and asking every implementer to
     * remember that is asking for the bug.
     */
    #[Test]
    public function the_reader_fills_the_buckets_the_metric_left_out(): void
    {
        $heute = Carbon::now()->format('Y-m-d');

        Insights::registerMetric(new CountingMetric(perBucket: [$heute => 7]));

        $reihe = $this->reader()->series(Insights::metric('test.counted'), $this->frage('7d'));

        $this->assertCount(7, $reihe);
        $this->assertSame(7, array_sum(array_column($reihe, 'value')));
        $this->assertContains(0, array_column($reihe, 'value'));
    }

    #[Test]
    public function a_long_period_is_bucketed_by_month(): void
    {
        Insights::registerMetric(new CountingMetric(fixed: 1));

        $reihe = $this->reader()->series(Insights::metric('test.counted'), $this->frage('12m'));

        $this->assertCount(12, $reihe);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $reihe[0]['bucket']);
    }

    // -- Breakdowns ---------------------------------------------------------

    #[Test]
    public function a_metric_may_offer_splits_and_a_null_key_is_a_row(): void
    {
        Insights::registerMetric(new CountingMetric);

        $zeilen = $this->reader()->breakdown(Insights::metric('test.counted'), $this->frage(), 'farbe');

        $this->assertCount(3, $zeilen);
        $this->assertContains(null, array_column($zeilen, 'key'));
    }

    /** A split nobody offered answers empty rather than guessing one. */
    #[Test]
    public function an_unknown_split_is_empty(): void
    {
        Insights::registerMetric(new CountingMetric);

        $this->assertSame([], $this->reader()->breakdown(Insights::metric('test.counted'), $this->frage(), 'groesse'));
    }

    #[Test]
    public function a_metric_without_splits_offers_none(): void
    {
        Insights::registerMetric(new BrokenMetric(where: 'series'));

        $gelesen = $this->reader()->read(Insights::metric('test.broken'), $this->frage());

        $this->assertSame([], $gelesen['breakdowns']);
    }

    // -- When a contributor is having a bad day -----------------------------

    /**
     * One broken metric costs its own tile, never the page.
     *
     * A screen shows many numbers from many addons; a sibling mid-upgrade must
     * not be able to take down a page somebody opened for a different figure.
     */
    #[Test]
    public function a_metric_that_throws_is_left_out_and_the_rest_still_read(): void
    {
        Insights::registerMetric(new CountingMetric(fixed: 42));
        Insights::registerMetric(new BrokenMetric);

        $uebersicht = $this->reader()->overview($this->frage());
        $handles = [];

        foreach ($uebersicht as $gruppe) {
            $handles = array_merge($handles, array_column($gruppe['metrics'], 'handle'));
        }

        $this->assertContains('test.counted', $handles);
        $this->assertNotContains('test.broken', $handles);
    }

    #[Test]
    public function a_metric_that_throws_while_saying_it_is_available_is_left_out(): void
    {
        Insights::registerMetric(new BrokenMetric(where: 'available'));

        $this->assertSame([], Insights::metrics());
    }

    #[Test]
    public function a_metric_that_throws_on_its_series_returns_an_empty_chart(): void
    {
        Insights::registerMetric(new BrokenMetric(where: 'series'));

        $this->assertSame([], $this->reader()->series(Insights::metric('test.broken'), $this->frage()));
    }

    // -- Grouping -----------------------------------------------------------

    #[Test]
    public function metrics_are_grouped_under_their_own_heading(): void
    {
        Insights::registerMetric(new CountingMetric(fixed: 1));

        $uebersicht = $this->reader()->overview($this->frage());

        $this->assertSame('Attrappe', $uebersicht[0]['group']);
    }

    // -- The query ----------------------------------------------------------

    /**
     * A filter a metric does not understand must not break it.
     *
     * A screen passes a currency to every metric on it; one counting bookings
     * has to ignore that rather than fail.
     */
    #[Test]
    public function a_filter_a_metric_does_not_know_is_harmless(): void
    {
        Insights::registerMetric(new CountingMetric(fixed: 3));

        $query = $this->frage()->with('currency', 'CHF')->with('unsinn', true);

        $this->assertSame(3, Insights::metric('test.counted')->value($query));
        $this->assertSame('CHF', $query->filter('currency'));
        $this->assertNull($query->filter('gibtsnicht'));
    }
}
