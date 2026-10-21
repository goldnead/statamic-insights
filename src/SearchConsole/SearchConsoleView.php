<?php

namespace Goldnead\StatamicInsights\SearchConsole;

use Illuminate\Support\Carbon;

/**
 * What the Search Console screen shows, worked out from a snapshot.
 *
 * Pure: a decoded snapshot and a clock in, a plain array out. No file, no
 * request, so every rule here is testable with a literal.
 *
 * **Four figures, each against the period before it.** Clicks, impressions,
 * click-through rate and average position, for the last 7 and the last 28
 * days. The change is relative (percent of the earlier value), and it is `null`
 * where a percentage would be a claim: no earlier value, or an earlier value of
 * zero — an increase from nothing is infinite, and `+∞ %` says less than the
 * number already did.
 *
 * **Position is the one figure where less is better.** Position 4 after
 * position 6 is an improvement, so its arrow points down and its colour says
 * good. `good` carries that judgement so that the screen does not have to know
 * which figure is the odd one.
 */
class SearchConsoleView
{
    /** The snapshot is expected daily; three days without a new one is worth saying. */
    public const STALE_AFTER_DAYS = 3;

    /** The four figures every window carries, in the order the tiles show them. */
    public const FIGURES = ['clicks', 'impressions', 'ctr', 'position'];

    /** The file keeps the top 25; a longer list is not ours to draw. */
    public const MAX_ROWS = 25;

    /** More than this many daily points is more than any chart can show. */
    public const MAX_DAYS = 400;

    /**
     * @param  array<string, mixed>  $data  A snapshot that passed {@see SnapshotReader}.
     * @return array<string, mixed>
     */
    public static function assemble(array $data, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $fetched = Carbon::parse((string) $data['fetched_at']);
        $zone = (string) config('app.timezone', 'UTC');
        $local = $fetched->copy()->setTimezone($zone);

        return [
            'property' => is_string($data['property'] ?? null) ? $data['property'] : null,
            'fetchedAt' => $local->toIso8601String(),
            'fetchedAtLabel' => $local->copy()->locale(app()->getLocale())->isoFormat('lll'),
            'stale' => $fetched->diffInSeconds($now, false) > self::STALE_AFTER_DAYS * 86400,
            'windows' => [
                self::window('7d', 'previous_7d', $data['periods']),
                self::window('28d', 'previous_28d', $data['periods']),
            ],
            'daily' => self::daily($data['daily']),
            'queries' => self::rows($data['top_queries'] ?? [], 'query'),
            'pages' => self::rows($data['top_pages'] ?? [], 'page'),
        ];
    }

    /**
     * The relative change from `$previous` to `$current`, and what it means.
     *
     * @return array{delta: float|null, direction: string|null, good: bool|null}
     */
    public static function delta(?float $current, ?float $previous, bool $lowerIsBetter = false): array
    {
        // Division by zero is answered with "no comparison", not with zero and
        // not with infinity.
        if ($current === null || $previous === null || $previous == 0.0) {
            return ['delta' => null, 'direction' => null, 'good' => null];
        }

        $delta = round(($current - $previous) / abs($previous) * 100, 1);
        $direction = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');

        $good = match ($direction) {
            'flat' => null,
            'up' => ! $lowerIsBetter,
            default => $lowerIsBetter,
        };

        return ['delta' => $delta, 'direction' => $direction, 'good' => $good];
    }

    /**
     * @param  array<string, mixed>  $periods
     * @return array<string, mixed>
     */
    protected static function window(string $name, string $before, array $periods): array
    {
        $now = $periods[$name];
        $then = $periods[$before];
        $tiles = [];

        foreach (self::FIGURES as $figure) {
            // The change is worked out from the raw values and only the
            // printed numbers are rounded, so a rate of 2.504 % against 2.496 %
            // is not reported as "no change" because both print as 2.5.
            $raw = fn (array $row) => self::finite($row[$figure] ?? null);

            $tiles[] = [
                'figure' => $figure,
                'unit' => $figure === 'ctr' ? 'percent' : 'count',
                'value' => self::figure($figure, $now[$figure] ?? null),
                'previous' => self::figure($figure, $then[$figure] ?? null),
            ] + self::delta($raw($now), $raw($then), $figure === 'position');
        }

        return [
            'name' => $name,
            'start' => is_string($now['start'] ?? null) ? $now['start'] : null,
            'end' => is_string($now['end'] ?? null) ? $now['end'] : null,
            'tiles' => $tiles,
        ];
    }

    /**
     * A figure the way the screen prints it: the rate as a percentage, the
     * position to one decimal, counts as whole numbers.
     */
    protected static function figure(string $figure, mixed $value): int|float|null
    {
        $value = self::finite($value);

        if ($value === null) {
            return null;
        }

        return match ($figure) {
            'ctr' => round($value * 100, 2),
            'position' => round($value, 1),
            default => (int) round($value),
        };
    }

    /** A number that can be printed: INF and NAN (1e999 in JSON) are not. */
    protected static function finite(mixed $value): ?float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * The daily series, one list per chart. A day without a figure is a day
     * without a bar; it is not a zero.
     *
     * @param  array<int, mixed>  $daily
     * @return array<string, array<int, array{bucket: string, value: int|null}>>
     */
    protected static function daily(array $daily): array
    {
        $series = ['clicks' => [], 'impressions' => []];
        $days = [];

        foreach ($daily as $row) {
            // A date that is not YYYY-MM-DD is not a day; the page splits it into numbers.
            if (! is_array($row) || ! is_string($row['date'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['date'])) {
                continue;
            }

            $days[$row['date']] = $row;
        }

        // In order, and no more than the screen could use: the newest days.
        ksort($days);
        $days = array_slice($days, -self::MAX_DAYS, null, true);

        foreach ($days as $date => $row) {
            foreach (array_keys($series) as $figure) {
                $value = self::finite($row[$figure] ?? null);

                $series[$figure][] = [
                    'bucket' => (string) $date,
                    'value' => $value === null ? null : (int) round($value),
                ];
            }
        }

        return $series;
    }

    /**
     * Both windows' rows merged into one table: the last 28 days, each row
     * carrying what it had in the 28 days before **if it appears there**.
     *
     * A query that is missing from the earlier list is not a query that had no
     * clicks: the file keeps the top 25, and a row below that line is simply
     * not listed. So the earlier values are `null`, never zero.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function rows(mixed $lists, string $key): array
    {
        if (! is_array($lists)) {
            return [];
        }

        $now = is_array($lists['28d'] ?? null) ? array_slice(array_values($lists['28d']), 0, self::MAX_ROWS) : [];
        $then = [];
        $earlier = is_array($lists['previous_28d'] ?? null) ? array_slice(array_values($lists['previous_28d']), 0, self::MAX_ROWS) : [];

        foreach ($earlier as $row) {
            if (is_array($row) && is_string($row[$key] ?? null)) {
                $then[$row[$key]] = $row;
            }
        }

        $rows = [];

        foreach ($now as $row) {
            if (! is_array($row) || ! is_string($row[$key] ?? null)) {
                continue;
            }

            $before = $then[$row[$key]] ?? null;

            $rows[] = [
                $key => $row[$key],
                'clicks' => self::figure('clicks', $row['clicks'] ?? null),
                'impressions' => self::figure('impressions', $row['impressions'] ?? null),
                'ctr' => self::figure('ctr', $row['ctr'] ?? null),
                'position' => self::figure('position', $row['position'] ?? null),
                'clicks_previous' => $before === null ? null : self::figure('clicks', $before['clicks'] ?? null),
                'position_previous' => $before === null ? null : self::figure('position', $before['position'] ?? null),
            ];
        }

        return $rows;
    }
}
