<?php

namespace Goldnead\StatamicInsights\Tests\Support;

use Illuminate\Support\Carbon;

/**
 * A synthetic Search Console snapshot in format version 1.
 *
 * Built from a clock, the way the real job builds it: the windows end two days
 * before `$fetched`, the daily series is 56 days without a gap.
 */
class SearchConsoleSnapshot
{
    /**
     * @param  array<string, mixed>  $overrides  Merged over the top level (shallow).
     * @return array<string, mixed>
     */
    public static function make(Carbon $fetched, array $overrides = []): array
    {
        $end = $fetched->copy()->startOfDay()->subDays(2);

        $daily = [];

        for ($i = 55; $i >= 0; $i--) {
            $day = $end->copy()->subDays($i);
            $impressions = 100 + (($i * 7) % 40) + (55 - $i);

            $daily[] = [
                'date' => $day->toDateString(),
                'clicks' => 2 + (($i * 3) % 5) + intdiv(55 - $i, 8),
                'impressions' => $impressions,
                'ctr' => round((2 + (($i * 3) % 5)) / $impressions, 4),
                'position' => round(14.2 - (55 - $i) * 0.03, 1),
            ];
        }

        $period = fn (int $days, int $offset, array $figures) => [
            'start' => $end->copy()->subDays($offset + $days - 1)->toDateString(),
            'end' => $end->copy()->subDays($offset)->toDateString(),
        ] + $figures;

        return array_merge([
            'version' => 1,
            'property' => 'sc-domain:example.test',
            'fetched_at' => $fetched->toIso8601String(),
            'daily' => $daily,
            'periods' => [
                '7d' => $period(7, 0, ['clicks' => 42, 'impressions' => 1800, 'ctr' => 0.0233, 'position' => 11.4]),
                'previous_7d' => $period(7, 7, ['clicks' => 30, 'impressions' => 1500, 'ctr' => 0.02, 'position' => 12.6]),
                '28d' => $period(28, 0, ['clicks' => 160, 'impressions' => 7000, 'ctr' => 0.0229, 'position' => 12.1]),
                'previous_28d' => $period(28, 28, ['clicks' => 120, 'impressions' => 6100, 'ctr' => 0.0197, 'position' => 13.0]),
            ],
            'top_queries' => [
                '28d' => [
                    ['query' => 'chor suchen', 'clicks' => 40, 'impressions' => 900, 'ctr' => 0.0444, 'position' => 5.2],
                    ['query' => 'chor in der nähe', 'clicks' => 22, 'impressions' => 1200, 'ctr' => 0.0183, 'position' => 8.9],
                    ['query' => 'gospelchor münchen', 'clicks' => 9, 'impressions' => 310, 'ctr' => 0.029, 'position' => 6.1],
                ],
                'previous_28d' => [
                    ['query' => 'chor suchen', 'clicks' => 31, 'impressions' => 820, 'ctr' => 0.0378, 'position' => 6.0],
                    ['query' => 'chor in der nähe', 'clicks' => 25, 'impressions' => 1100, 'ctr' => 0.0227, 'position' => 8.1],
                ],
            ],
            'top_pages' => [
                '28d' => [
                    ['page' => 'https://example.test/', 'clicks' => 80, 'impressions' => 2400, 'ctr' => 0.0333, 'position' => 7.4],
                    ['page' => 'https://example.test/chor/berlin', 'clicks' => 31, 'impressions' => 1500, 'ctr' => 0.0207, 'position' => 9.8],
                ],
                'previous_28d' => [
                    ['page' => 'https://example.test/', 'clicks' => 60, 'impressions' => 2100, 'ctr' => 0.0286, 'position' => 8.0],
                ],
            ],
        ], $overrides);
    }

    /** Writes a snapshot to a path and returns the path. */
    public static function write(string $path, array $snapshot): string
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $path;
    }
}
