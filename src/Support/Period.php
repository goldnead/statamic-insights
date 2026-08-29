<?php

namespace Goldnead\StatamicInsights\Support;

use Illuminate\Support\Carbon;

/**
 * The stretch of time a report covers.
 *
 * A value object rather than two loose dates, because every query in the report
 * has to use the same bounds and the same interpretation of them. Two places
 * that each parse a date string are two places that can disagree by a day, and
 * a revenue figure that is off by one day is indistinguishable from one that is
 * right.
 *
 * The end is inclusive of the whole day. A person asking for "1. to 31. August"
 * means the 31st, not up to midnight at its start — the most common off-by-one
 * in every report anybody has ever written.
 */
final class Period
{
    public const PRESETS = ['7d', '30d', '90d', '12m', 'ytd', 'all'];

    private function __construct(
        public readonly ?Carbon $from,
        public readonly ?Carbon $to,
        public readonly string $preset,
    ) {}

    public static function fromPreset(?string $preset): self
    {
        $preset = in_array($preset, self::PRESETS, true) ? $preset : '30d';
        $now = Carbon::now();

        return match ($preset) {
            '7d' => new self($now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay(), $preset),
            '90d' => new self($now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay(), $preset),
            '12m' => new self($now->copy()->subMonths(11)->startOfMonth(), $now->copy()->endOfDay(), $preset),
            'ytd' => new self($now->copy()->startOfYear(), $now->copy()->endOfDay(), $preset),
            'all' => new self(null, null, $preset),
            default => new self($now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay(), '30d'),
        };
    }

    /** An explicit range, for when the data defines the bounds rather than a preset. */
    public static function between(Carbon $from, Carbon $to): self
    {
        return new self($from, $to, 'all');
    }

    /**
     * The same number of calendar days, ending the day before this one begins.
     *
     * Counted in days, not seconds. `diffInSeconds` between midnight and
     * 23:59:59 comes back as 2591999.999999 — a float with microseconds — and
     * subtracting it lands a second before midnight instead of on it. The
     * comparison period was then 31 days against 30, and every "vs. last month"
     * figure was quietly measured against a longer month.
     */
    public function previous(): ?self
    {
        if ($this->from === null || $this->to === null) {
            return null;
        }

        $tage = $this->days();
        $bis = $this->from->copy()->subDay()->endOfDay();

        return new self(
            $bis->copy()->subDays($tage - 1)->startOfDay(),
            $bis,
            $this->preset,
        );
    }

    /**
     * Calendar days in the period, inclusive of both ends.
     *
     * Counted between the two **start-of-day** points, not between the raw
     * bounds. The end is 23:59:59, so a straight difference is six-and-a-bit
     * days for a seven-day window; rounding that up and adding one gives eight,
     * and the chart then draws a week with eight columns. The suite caught it
     * here rather than a reader noticing it in a bar chart, which is the one
     * place an off-by-one is invisible.
     */
    public function days(): ?int
    {
        if ($this->from === null || $this->to === null) {
            return null;
        }

        return (int) $this->from->copy()->startOfDay()->diffInDays($this->to->copy()->startOfDay()) + 1;
    }

    public function isOpenEnded(): bool
    {
        return $this->from === null;
    }
}
