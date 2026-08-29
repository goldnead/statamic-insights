<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\Unit;
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
            $table->timestamp('happened_at')->nullable();
        });
    }

    protected function widget(string $when, ?string $kind = 'rot', int $weight = 1): void
    {
        DB::table('widgets')->insert([
            'kind' => $kind,
            'weight' => $weight,
            'happened_at' => Carbon::parse($when),
        ]);
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
}
