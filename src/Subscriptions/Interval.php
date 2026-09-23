<?php

namespace Goldnead\StatamicInsights\Subscriptions;

use Illuminate\Support\Carbon;

/**
 * A billing rhythm in the provider's words: `"1 month"`, `"3 months"`,
 * `"1 year"`, `"2 weeks"`.
 *
 * `statamic-payments` stores the rhythm as typed rather than as a number and a
 * unit, so every reader has to parse it. The fallback is the same as there:
 * what cannot be read is taken as one month (`Subscription::addInterval()`).
 * Two readers that disagreed about an unreadable rhythm would show one
 * agreement at two prices.
 */
final class Interval
{
    /** Average month lengths, so that 12 × a monthly figure is a year. */
    private const WEEKS_PER_MONTH = 52 / 12;

    private const DAYS_PER_MONTH = 365 / 12;

    /**
     * What one cycle is worth per month.
     *
     * A quarter at 399 is 133 a month, a year at 1200 is 100, a week at 10 is
     * 43.33. Kept as a float: the figures round once, on the sum, so that three
     * weekly agreements do not lose a cent each.
     */
    public static function monthlyFactor(string $interval): float
    {
        [$anzahl, $einheit] = self::parse($interval);

        return match ($einheit) {
            'year' => 1 / (12 * $anzahl),
            'week' => self::WEEKS_PER_MONTH / $anzahl,
            'day' => self::DAYS_PER_MONTH / $anzahl,
            default => 1 / $anzahl,
        };
    }

    /**
     * The next date one rhythm later.
     *
     * Months without overflow, like the payments addon: 31 January plus a month
     * is 28 February, not 3 March.
     */
    public static function add(Carbon $from, string $interval): Carbon
    {
        [$anzahl, $einheit] = self::parse($interval);

        return match ($einheit) {
            'year' => $from->copy()->addYearsNoOverflow($anzahl),
            'week' => $from->copy()->addWeeks($anzahl),
            'day' => $from->copy()->addDays($anzahl),
            default => $from->copy()->addMonthsNoOverflow($anzahl),
        };
    }

    /** @return array{0: int, 1: string} */
    private static function parse(string $interval): array
    {
        if (preg_match('/^\s*(\d+)\s*(day|week|month|year)s?\s*$/i', $interval, $m) && (int) $m[1] > 0) {
            return [(int) $m[1], strtolower($m[2])];
        }

        return [1, 'month'];
    }
}
