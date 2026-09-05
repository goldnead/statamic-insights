<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\Unit;
use Goldnead\StatamicInsights\Tests\Fakes\BrandedWidgetMetric;
use Goldnead\StatamicInsights\Tests\Fakes\BrandManagerStandIn;
use Goldnead\StatamicInsights\Tests\Fakes\WidgetMetric;
use Goldnead\StatamicInsights\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The base class fifteen addons are about to build on.
 *
 * So it is tested against a real table with real rows rather than against a
 * mock: every one of those addons will inherit whatever is wrong here, and a
 * bug in the bucketing or in the null handling would be reproduced fifteen
 * times before anybody noticed.
 */
class TableMetricTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->nullable();
            $table->unsignedInteger('weight')->default(0);
            $table->unsignedInteger('brand_id')->nullable();
            $table->timestamp('happened_at')->nullable();
        });
    }

    protected function widget(string $when, ?string $kind = 'rot', int $weight = 1, ?int $brand = 1): void
    {
        DB::table('widgets')->insert([
            'kind' => $kind,
            'weight' => $weight,
            'brand_id' => $brand,
            'happened_at' => Carbon::parse($when),
        ]);
    }

    /** Bind a stand-in for the brand manager, the way brand-context binds the real one. */
    protected function marke(bool $multi = true, ?int $current = 1, string $failMode = 'closed', bool $disabled = false): void
    {
        $this->app->instance('brand-context', new BrandManagerStandIn($multi, $current, $failMode, $disabled));
    }

    protected function frage(string $preset = '30d'): MetricQuery
    {
        $period = Period::fromPreset($preset);

        return new MetricQuery($period, MetricQuery::bucketFor($period));
    }

    #[Test]
    public function a_missing_table_means_the_metric_is_absent_not_zero(): void
    {
        Schema::drop('widgets');

        $this->assertFalse((new WidgetMetric)->available());
    }

    #[Test]
    public function it_counts_only_what_falls_inside_the_window(): void
    {
        $this->widget(Carbon::now()->subDays(2)->toDateTimeString());
        $this->widget(Carbon::now()->subDays(5)->toDateTimeString());
        $this->widget(Carbon::now()->subDays(40)->toDateTimeString());

        $this->assertSame(2, (new WidgetMetric)->value($this->frage('30d')));
    }

    /**
     * The window is inclusive of the whole last day.
     *
     * A period ending "today" that stopped at midnight would drop everything
     * sold today — the most common off-by-one in every report ever written,
     * and one this family has already made once.
     */
    #[Test]
    public function something_that_happened_today_is_inside_the_window(): void
    {
        $this->widget(Carbon::now()->setTime(23, 30)->toDateTimeString());

        $this->assertSame(1, (new WidgetMetric)->value($this->frage('7d')));
    }

    /**
     * A row with no timestamp is in no period — least of all in "all time".
     *
     * Over an open-ended range both bounds are null, so the two window clauses
     * add no condition whatsoever. A metric over a nullable column then counted
     * every row ever written the moment somebody picked the widest range:
     * cancellations that never happened, completions that never completed.
     *
     * Found by a contributor building on this class. Fixed here rather than in
     * theirs, because the next fifteen would each have had to find it again.
     */
    #[Test]
    public function a_row_without_a_timestamp_is_in_no_period_not_even_all_time(): void
    {
        $this->widget(Carbon::now()->toDateTimeString());
        DB::table('widgets')->insert(['kind' => 'rot', 'weight' => 1, 'happened_at' => null]);

        $this->assertSame(1, (new WidgetMetric)->value($this->frage('30d')));
        $this->assertSame(1, (new WidgetMetric)->value($this->frage('all')));
        $this->assertSame(1, array_sum((new WidgetMetric)->series($this->frage('all'))));
        $this->assertSame(1, array_sum(array_column((new WidgetMetric)->breakdown($this->frage('all'), 'kind'), 'value')));
    }

    /**
     * "All time" has no upper bound, and these tables are full of the future.
     *
     * A pre-order starting next month, a licence expiring next year, a campaign
     * scheduled for Friday. `untilNow()` is how a metric that answers "what
     * happened" says so; one that answers "what is scheduled" leaves it off,
     * and the future is then the point rather than a bug.
     */
    #[Test]
    public function until_now_keeps_the_future_out_of_the_widest_range(): void
    {
        $this->widget(Carbon::now()->subDay()->toDateTimeString());
        $this->widget(Carbon::now()->addMonth()->toDateTimeString());

        $offen = new WidgetMetric;
        $geklemmt = new WidgetMetric(clamped: true);

        // Unclamped, "all time" reports the pre-order as though it had happened.
        $this->assertSame(2, $offen->value($this->frage('all')));
        $this->assertSame(1, $geklemmt->value($this->frage('all')));
    }

    /**
     * The last fraction of a second of a period is still inside it.
     *
     * `to` is 23:59:59.999999 and a binding cuts the fraction off, so on a
     * column storing milliseconds the comparison became
     * `"23:59:59.500" <= "23:59:59"` — false, and the row vanished. On SQLite
     * that is a text comparison and always wrong; on MySQL with a plain
     * timestamp it happened to work, which is why a green suite never showed it.
     */
    #[Test]
    public function a_row_in_the_last_fraction_of_a_second_is_still_in_the_period(): void
    {
        $ende = Carbon::now()->endOfDay();

        DB::table('widgets')->insert([
            'kind' => 'rot',
            'weight' => 1,
            'happened_at' => $ende->copy()->setTime(23, 59, 59)->format('Y-m-d H:i:s').'.500',
        ]);

        $this->assertSame(1, (new WidgetMetric)->value($this->frage('7d')));
    }

    /** And a row one second past midnight belongs to the next period, not this one. */
    #[Test]
    public function midnight_belongs_to_the_period_that_starts_there(): void
    {
        DB::table('widgets')->insert([
            'kind' => 'rot',
            'weight' => 1,
            'happened_at' => Carbon::now()->addDay()->startOfDay()->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame(0, (new WidgetMetric)->value($this->frage('7d')));
    }

    #[Test]
    public function it_buckets_by_day_and_leaves_the_empty_days_out(): void
    {
        $heute = Carbon::now()->format('Y-m-d');
        $this->widget(Carbon::now()->toDateTimeString());
        $this->widget(Carbon::now()->toDateTimeString());
        $this->widget(Carbon::now()->subDays(3)->toDateTimeString());

        $reihe = (new WidgetMetric)->series($this->frage('7d'));

        // Two buckets, not seven: filling the range is the reader's job, and a
        // metric that filled it too would be filling it twice.
        $this->assertCount(2, $reihe);
        $this->assertSame(2, $reihe[$heute]);
    }

    #[Test]
    public function it_returns_the_buckets_in_ascending_order_whatever_order_the_rows_arrived_in(): void
    {
        // Newest first, on purpose: GROUP BY promises no order, and MySQL 8
        // returns the groups in whatever order it met them. The series has to
        // come back sorted regardless, or the chart draws backwards in time.
        foreach ([0, 5, 2, 6, 1] as $tage) {
            $this->widget(Carbon::now()->subDays($tage)->toDateTimeString());
        }

        $reihe = (new WidgetMetric)->series($this->frage('7d'));

        $schluessel = array_keys($reihe);
        $sortiert = $schluessel;
        sort($sortiert);

        $this->assertCount(5, $reihe);
        $this->assertSame($sortiert, $schluessel);
        $this->assertSame(Carbon::now()->subDays(6)->format('Y-m-d'), $schluessel[0]);
        $this->assertSame(Carbon::now()->format('Y-m-d'), $schluessel[4]);
    }

    #[Test]
    public function it_buckets_by_month_over_a_long_range(): void
    {
        $this->widget(Carbon::now()->subMonths(2)->toDateTimeString());

        $reihe = (new WidgetMetric)->series($this->frage('12m'));

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', array_key_first($reihe));
    }

    // -- Splits -------------------------------------------------------------

    #[Test]
    public function it_splits_by_a_column_largest_first(): void
    {
        $this->widget(Carbon::now()->toDateTimeString(), 'rot');
        $this->widget(Carbon::now()->toDateTimeString(), 'rot');
        $this->widget(Carbon::now()->toDateTimeString(), 'blau');

        $zeilen = (new WidgetMetric)->breakdown($this->frage(), 'kind');

        $this->assertSame('rot', $zeilen[0]['key']);
        $this->assertSame(2, $zeilen[0]['value']);
        $this->assertSame('blau', $zeilen[1]['key']);
    }

    /**
     * Gleiche Kennzahl, feste Reihenfolge.
     *
     * Ohne zweites Sortierkriterium entscheidet bei einem Gleichstand die
     * Datenbank, und sie entscheidet je nach Treiber anders: dieselben Zeilen
     * kamen unter SQLite in der einen und unter MySQL in der anderen Ordnung
     * zurueck. Auf dem Schirm ist das eine Liste, die ohne Grund springt.
     *
     * Der Fall ist nicht selten: kleine Zaehlungen, frische Installationen und
     * ruhige Wochen erzeugen laufend gleiche Werte.
     */
    #[Test]
    public function a_tie_is_broken_by_the_key_not_by_the_driver(): void
    {
        $this->widget(Carbon::now()->toDateTimeString(), 'rot');
        $this->widget(Carbon::now()->toDateTimeString(), 'blau');
        $this->widget(Carbon::now()->toDateTimeString(), 'gelb');

        $zeilen = (new WidgetMetric)->breakdown($this->frage(), 'kind');

        $this->assertSame([1, 1, 1], array_column($zeilen, 'value'));
        $this->assertSame(['blau', 'gelb', 'rot'], array_column($zeilen, 'key'));
    }

    /**
     * An der Abschneidekante ist es mehr als Kosmetik.
     *
     * Steht ein Gleichstand genau dort, wo `$limit` schneidet, haengt ohne
     * zweites Kriterium vom Treiber ab, *welche* Zeile ueberhaupt
     * zurueckkommt — nicht nur, in welcher Reihenfolge.
     */
    #[Test]
    public function the_cut_off_takes_the_same_row_on_every_driver(): void
    {
        $this->widget(Carbon::now()->toDateTimeString(), 'rot');
        $this->widget(Carbon::now()->toDateTimeString(), 'rot');
        $this->widget(Carbon::now()->toDateTimeString(), 'blau');
        $this->widget(Carbon::now()->toDateTimeString(), 'gelb');

        $zeilen = (new WidgetMetric)->breakdown($this->frage(), 'kind', 2);

        $this->assertSame(['rot', 'blau'], array_column($zeilen, 'key'));
    }

    /**
     * A row whose value is null is a row.
     *
     * Dropped, the split disagrees with the total and nothing on the screen
     * says why — the hardest kind of wrong to notice.
     */
    #[Test]
    public function rows_without_a_value_are_grouped_and_labelled_not_dropped(): void
    {
        $this->widget(Carbon::now()->toDateTimeString(), 'rot');
        $this->widget(Carbon::now()->toDateTimeString(), null);

        $zeilen = (new WidgetMetric)->breakdown($this->frage(), 'kind');
        $keys = array_column($zeilen, 'key');

        $this->assertContains(null, $keys);
        $this->assertSame(2, array_sum(array_column($zeilen, 'value')));

        $ohne = collect($zeilen)->firstWhere('key', null);
        $this->assertSame('Kein Typ', $ohne['label']);
    }

    /** An empty string is as absent as a null, and must not become its own row. */
    #[Test]
    public function an_empty_string_counts_as_missing(): void
    {
        $this->widget(Carbon::now()->toDateTimeString(), '');

        $zeilen = (new WidgetMetric)->breakdown($this->frage(), 'kind');

        $this->assertNull($zeilen[0]['key']);
    }

    #[Test]
    public function the_split_honours_its_limit(): void
    {
        foreach (['a', 'b', 'c'] as $kind) {
            $this->widget(Carbon::now()->toDateTimeString(), $kind);
        }

        $this->assertCount(2, (new WidgetMetric)->breakdown($this->frage(), 'kind', 2));
    }

    // -- Types --------------------------------------------------------------

    /**
     * A count comes back an int, an average a float.
     *
     * Casting everything to int would silently floor an average; casting
     * everything to float would print seven orders as "7.0".
     */
    #[Test]
    public function a_sum_stays_whole_and_an_average_does_not(): void
    {
        $this->widget(Carbon::now()->toDateTimeString(), 'rot', 3);
        $this->widget(Carbon::now()->toDateTimeString(), 'rot', 2);

        $summe = (new WidgetMetric(aggregate: 'sum(weight)'))->breakdown($this->frage(), 'kind');
        $schnitt = (new WidgetMetric(aggregate: 'avg(weight)'))->breakdown($this->frage(), 'kind');

        $this->assertSame(5, $summe[0]['value']);
        $this->assertSame(2.5, $schnitt[0]['value']);
    }

    #[Test]
    public function it_reports_the_unit_it_was_given(): void
    {
        $this->assertSame(Unit::COUNT, (new WidgetMetric)->unit());
    }

    #[Test]
    public function a_metric_without_a_brand_column_is_left_alone(): void
    {
        $this->marke(current: 2);
        $this->widget(Carbon::now()->subDay()->toDateTimeString(), brand: 1);
        $this->widget(Carbon::now()->subDay()->toDateTimeString(), brand: 2);

        $this->assertSame(2, (new WidgetMetric)->value($this->frage()));
    }

    #[Test]
    public function declaring_the_column_narrows_the_figure_to_the_current_brand(): void
    {
        $this->marke(current: 2);
        $this->widget(Carbon::now()->subDay()->toDateTimeString(), brand: 1);
        $this->widget(Carbon::now()->subDay()->toDateTimeString(), brand: 2);
        $this->widget(Carbon::now()->subDays(3)->toDateTimeString(), brand: 3);

        $this->assertSame(1, (new BrandedWidgetMetric)->value($this->frage()));
    }

    /**
     * The defect this mechanism was built for.
     *
     * A tile summed four brands while the switcher said one, so the figure was
     * not merely wrong, it disclosed one customer's turnover on another's
     * screen. The chart and the split are separate queries and had to be
     * checked separately, because the earlier per-addon attempts filtered the
     * figure and forgot the two below it.
     */
    #[Test]
    public function the_chart_and_the_split_narrow_with_the_figure(): void
    {
        $this->marke(current: 2);
        $this->widget(Carbon::now()->subDay()->toDateTimeString(), kind: 'blau', brand: 1);
        $this->widget(Carbon::now()->subDay()->toDateTimeString(), kind: 'rot', brand: 2);

        $metrik = new BrandedWidgetMetric;

        $this->assertSame([1], array_values($metrik->series($this->frage())));
        $this->assertSame(['rot'], array_column($metrik->breakdown($this->frage(), 'kind'), 'label'));
    }

    #[Test]
    public function a_single_brand_install_never_sees_a_filter(): void
    {
        $this->marke(multi: false, current: null);
        $this->widget(Carbon::now()->subDay()->toDateTimeString(), brand: 7);

        $this->assertSame(1, (new BrandedWidgetMetric)->value($this->frage()));
    }

    #[Test]
    public function a_deliberately_bypassed_scope_is_not_reapplied_here(): void
    {
        $this->marke(current: 2, disabled: true);
        $this->widget(Carbon::now()->subDay()->toDateTimeString(), brand: 1);
        $this->widget(Carbon::now()->subDay()->toDateTimeString(), brand: 2);

        $this->assertSame(2, (new BrandedWidgetMetric)->value($this->frage()));
    }

    /**
     * Fail closed reads zero; it does not make the metric disappear.
     *
     * Two addons had answered this case in `available()`, which removed six
     * tiles from the screen the moment a brand was unresolved. `available()`
     * says whether the thing exists — an unpicked brand is not the metric
     * ceasing to exist, and a reader can understand a zero but cannot notice
     * an absence.
     */
    #[Test]
    public function an_unresolved_brand_reads_zero_rather_than_removing_the_metric(): void
    {
        $this->marke(current: null);
        $this->widget(Carbon::now()->subDay()->toDateTimeString(), brand: 1);

        $metrik = new BrandedWidgetMetric;

        $this->assertSame(0, $metrik->value($this->frage()));
        $this->assertTrue($metrik->available());
    }

    #[Test]
    public function an_open_fail_mode_shows_everything_instead(): void
    {
        $this->marke(current: null, failMode: 'open');
        $this->widget(Carbon::now()->subDay()->toDateTimeString(), brand: 1);
        $this->widget(Carbon::now()->subDay()->toDateTimeString(), brand: 2);

        $this->assertSame(2, (new BrandedWidgetMetric)->value($this->frage()));
    }

    /**
     * Without brand-context installed there is no brand and nothing to filter.
     *
     * The coupling is optional in both directions, so a metric that declared a
     * brand column must still work in an install that never heard of brands —
     * rather than resolving a missing container binding and taking the whole
     * screen down with it.
     */
    #[Test]
    public function a_declared_column_is_harmless_when_brand_context_is_absent(): void
    {
        $this->assertFalse($this->app->bound('brand-context'));

        $this->widget(Carbon::now()->subDay()->toDateTimeString(), brand: 4);

        $this->assertSame(1, (new BrandedWidgetMetric)->value($this->frage()));
    }

    /**
     * A clamp reads the same clock the column was written on.
     *
     * The site here runs on Chicago time while the column stores UTC, which is
     * a real combination: several addons in this family write UTC on purpose so
     * that a row keeps its meaning when the site is moved. Clamped against the
     * site's clock, the last five hours of rows silently disappeared — and
     * never on the machine of whoever wrote the metric, because a site whose
     * timezone happens to be UTC shows nothing wrong at all.
     */
    #[Test]
    public function the_clamp_follows_the_zone_the_column_is_stored_in(): void
    {
        $vorher = date_default_timezone_get();
        date_default_timezone_set('America/Chicago');

        // Midday UTC, which is seven in the morning where the site thinks it is.
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'UTC'));

        // An hour ago, written as UTC wall clock the way the column stores it.
        DB::table('widgets')->insert([
            'kind' => 'rot',
            'weight' => 1,
            'brand_id' => null,
            'happened_at' => '2026-08-20 11:00:00',
        ]);

        // Clamped, or `untilNow()` never runs and this proves nothing.
        $inUtc = new class('count(*)', true) extends WidgetMetric
        {
            protected function zone(): ?string
            {
                return 'UTC';
            }
        };

        $ohneAngabe = new WidgetMetric('count(*)', true);

        // The same row, the same clamp, two clocks: eleven is before midday and
        // after seven in the morning.
        $this->assertSame(1, $inUtc->value($this->frage()));
        $this->assertSame(0, $ohneAngabe->value($this->frage()));

        Carbon::setTestNow();
        date_default_timezone_set($vorher);
    }
}
