<?php

namespace Goldnead\StatamicInsights\Tests\Feature\SearchConsole;

use Goldnead\StatamicInsights\SearchConsole\SearchConsoleView;
use Goldnead\StatamicInsights\Tests\Support\SearchConsoleSnapshot;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

class SearchConsoleViewTest extends SearchConsoleTestCase
{
    /** @return array<string, mixed> */
    protected function assemble(array $overrides = [], ?Carbon $fetched = null): array
    {
        $fetched ??= Carbon::now()->subHours(7);

        return SearchConsoleView::assemble(SearchConsoleSnapshot::make($fetched, $overrides), Carbon::now());
    }

    /** @return array<string, mixed> */
    protected function tile(array $view, string $window, string $figure): array
    {
        foreach ($view['windows'] as $w) {
            if ($w['name'] === $window) {
                foreach ($w['tiles'] as $tile) {
                    if ($tile['figure'] === $figure) {
                        return $tile;
                    }
                }
            }
        }

        $this->fail("no tile {$window}/{$figure}");
    }

    #[Test]
    public function a_rise_in_clicks_is_up_and_good(): void
    {
        $this->assertSame(
            ['delta' => 40.0, 'direction' => 'up', 'good' => true],
            SearchConsoleView::delta(42, 30),
        );
    }

    #[Test]
    public function a_fall_in_clicks_is_down_and_bad(): void
    {
        $this->assertSame(
            ['delta' => -25.0, 'direction' => 'down', 'good' => false],
            SearchConsoleView::delta(30, 40),
        );
    }

    #[Test]
    public function for_position_less_is_better(): void
    {
        // Position 11.4 after 12.6: the number fell, the ranking improved.
        $better = SearchConsoleView::delta(11.4, 12.6, lowerIsBetter: true);
        $this->assertSame('down', $better['direction']);
        $this->assertTrue($better['good']);
        $this->assertSame(-9.5, $better['delta']);

        $worse = SearchConsoleView::delta(14.0, 12.0, lowerIsBetter: true);
        $this->assertSame('up', $worse['direction']);
        $this->assertFalse($worse['good']);
    }

    #[Test]
    public function an_unchanged_figure_is_flat_and_neither_good_nor_bad(): void
    {
        $this->assertSame(
            ['delta' => 0.0, 'direction' => 'flat', 'good' => null],
            SearchConsoleView::delta(12.0, 12.0, lowerIsBetter: true),
        );
    }

    #[Test]
    public function an_earlier_value_of_zero_gives_no_percentage(): void
    {
        $nothing = ['delta' => null, 'direction' => null, 'good' => null];

        $this->assertSame($nothing, SearchConsoleView::delta(5, 0));
        $this->assertSame($nothing, SearchConsoleView::delta(0, 0));
        $this->assertSame($nothing, SearchConsoleView::delta(5, null));
        $this->assertSame($nothing, SearchConsoleView::delta(null, 5));
    }

    #[Test]
    public function a_fall_to_zero_is_minus_one_hundred_percent(): void
    {
        $this->assertSame(-100.0, SearchConsoleView::delta(0, 8)['delta']);
    }

    #[Test]
    public function the_tiles_compare_each_window_with_the_one_before(): void
    {
        $view = $this->assemble();

        $clicks = $this->tile($view, '7d', 'clicks');
        $this->assertSame(42, $clicks['value']);
        $this->assertSame(30, $clicks['previous']);
        $this->assertSame(40.0, $clicks['delta']);

        $ctr = $this->tile($view, '28d', 'ctr');
        $this->assertSame('percent', $ctr['unit']);
        $this->assertSame(2.29, $ctr['value'], 'a share of one in the file, a percentage on the screen');
        $this->assertSame(1.97, $ctr['previous']);

        $position = $this->tile($view, '28d', 'position');
        $this->assertSame(12.1, $position['value']);
        $this->assertSame('down', $position['direction']);
        $this->assertTrue($position['good']);
    }

    #[Test]
    public function a_zero_earlier_window_shows_the_value_without_a_percentage(): void
    {
        $periods = SearchConsoleSnapshot::make(Carbon::now())['periods'];
        $periods['previous_7d'] = ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => null] + $periods['previous_7d'];
        $periods['previous_7d']['position'] = null;

        $view = $this->assemble(['periods' => $periods]);

        $clicks = $this->tile($view, '7d', 'clicks');
        $this->assertSame(42, $clicks['value']);
        $this->assertNull($clicks['delta']);

        $position = $this->tile($view, '7d', 'position');
        $this->assertNull($position['previous']);
        $this->assertNull($position['delta']);
    }

    #[Test]
    public function the_daily_series_feeds_both_charts(): void
    {
        $view = $this->assemble();

        $this->assertCount(56, $view['daily']['clicks']);
        $this->assertCount(56, $view['daily']['impressions']);
        $this->assertSame(['bucket', 'value'], array_keys($view['daily']['clicks'][0]));
        $this->assertSame('2026-10-19', $view['daily']['clicks'][55]['bucket'], 'the last day is two days before the fetch');
    }

    #[Test]
    public function a_row_carries_the_earlier_values_only_when_it_appears_there(): void
    {
        $queries = $this->assemble()['queries'];

        $this->assertSame('chor suchen', $queries[0]['query']);
        $this->assertSame(40, $queries[0]['clicks']);
        $this->assertSame(31, $queries[0]['clicks_previous']);
        $this->assertSame(6.0, $queries[0]['position_previous']);
        $this->assertSame(4.44, $queries[0]['ctr']);

        // Not in the earlier top list: unknown, which is not zero.
        $this->assertSame('gospelchor münchen', $queries[2]['query']);
        $this->assertNull($queries[2]['clicks_previous']);
        $this->assertNull($queries[2]['position_previous']);

        $pages = $this->assemble()['pages'];
        $this->assertSame(60, $pages[0]['clicks_previous']);
        $this->assertNull($pages[1]['clicks_previous']);
    }

    #[Test]
    public function the_stand_is_printed_in_the_application_timezone(): void
    {
        // 05:30 in Berlin in October is 03:30 UTC.
        $view = SearchConsoleView::assemble(
            SearchConsoleSnapshot::make(Carbon::parse('2026-10-21T03:30:12+00:00')),
            Carbon::now(),
        );

        $this->assertSame('2026-10-21T05:30:12+02:00', $view['fetchedAt']);
        $this->assertStringContainsString('5:30', $view['fetchedAtLabel']);
        $this->assertStringContainsString('2026', $view['fetchedAtLabel']);

        config(['app.timezone' => 'America/New_York']);
        $other = SearchConsoleView::assemble(
            SearchConsoleSnapshot::make(Carbon::parse('2026-10-21T03:30:12+00:00')),
            Carbon::now(),
        );
        $this->assertSame('2026-10-20T23:30:12-04:00', $other['fetchedAt']);
    }

    #[Test]
    public function a_snapshot_is_stale_after_three_days_and_not_before(): void
    {
        $this->assertFalse($this->assemble(fetched: Carbon::now()->subHours(71))['stale']);
        $this->assertFalse($this->assemble(fetched: Carbon::now()->subDays(3))['stale'], 'exactly three days is still fine');
        $this->assertTrue($this->assemble(fetched: Carbon::now()->subDays(3)->subMinute())['stale']);
        $this->assertTrue($this->assemble(fetched: Carbon::now()->subDays(10))['stale']);
    }

    #[Test]
    public function a_snapshot_from_the_future_is_not_stale(): void
    {
        $this->assertFalse($this->assemble(fetched: Carbon::now()->addHour())['stale']);
    }

    #[Test]
    public function a_figure_that_is_not_finite_never_reaches_the_props(): void
    {
        $data = SearchConsoleSnapshot::make(Carbon::now()->subHours(7));
        $data['periods']['7d']['ctr'] = INF;
        $data['periods']['7d']['position'] = NAN;
        $data['daily'][0]['clicks'] = INF;
        $data['top_queries']['28d'][0]['impressions'] = INF;

        $view = SearchConsoleView::assemble($data, Carbon::now());

        $this->assertNull($this->tile($view, '7d', 'ctr')['value']);
        $this->assertNull($this->tile($view, '7d', 'ctr')['delta']);
        $this->assertNull($this->tile($view, '7d', 'position')['value']);
        $this->assertNull($view['daily']['clicks'][0]['value']);
        $this->assertNull($view['queries'][0]['impressions']);
        $this->assertTrue(json_encode($view) !== false, 'the props must be encodable');
    }

    #[Test]
    public function the_daily_series_is_sorted_filtered_and_capped(): void
    {
        $daily = [];

        for ($i = 0; $i < 450; $i++) {
            $daily[] = ['date' => Carbon::parse('2025-01-01')->addDays($i)->toDateString(), 'clicks' => $i, 'impressions' => $i];
        }

        $daily[] = ['date' => 'gestern', 'clicks' => 1, 'impressions' => 1];
        $daily[] = ['date' => '2026-10-21T10:00:00', 'clicks' => 1, 'impressions' => 1];
        shuffle($daily);

        $view = $this->assemble(['daily' => $daily]);
        $buckets = array_column($view['daily']['clicks'], 'bucket');

        $this->assertCount(400, $buckets);
        $this->assertSame($buckets, collect($buckets)->sort()->values()->all());
        $this->assertSame(Carbon::parse('2025-01-01')->addDays(449)->toDateString(), end($buckets));
        $this->assertNotContains('gestern', $buckets);
    }

    #[Test]
    public function the_top_lists_stop_at_twenty_five_rows(): void
    {
        $rows = [];

        for ($i = 0; $i < 60; $i++) {
            $rows[] = ['query' => "q{$i}", 'clicks' => 60 - $i, 'impressions' => 100, 'ctr' => 0.1, 'position' => 3.0];
        }

        $view = $this->assemble(['top_queries' => ['28d' => $rows, 'previous_28d' => $rows]]);

        $this->assertCount(25, $view['queries']);
        $this->assertSame('q24', $view['queries'][24]['query']);
    }
}
