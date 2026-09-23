<?php

namespace Goldnead\StatamicInsights\Subscriptions;

use Illuminate\Support\Carbon;

/**
 * One row of `subscriptions`, read once and asked about points in time.
 *
 * The table records an agreement's present, not its history: one status, one
 * price, a handful of dates. Everything a figure needs about the past is
 * reconstructed here, from two facts that do not change afterwards:
 *
 * - **When it paid.** It begins at the first paid charge that cost something,
 *   or at `starts_at` if nothing has been paid yet. The checkout pays the
 *   first period; `statamic-payments` then sets `starts_at` one rhythm later,
 *   and counting from there would start every subscription a month late.
 * - **What it charged.** The price at a moment is the amount of the latest
 *   renewal at or before it. The checkout is not a renewal — it can carry a
 *   bump, a setup fee or a first-payment discount — so the price before the
 *   first renewal is that renewal's. With no renewal at all, the price on the
 *   row. A charge marked `meta.proration` is a settling-up, not a price.
 *
 * It stops at the first date the row offers for the end — `paused_at` for a
 * pause if the column exists, then `ended_at`, `cancelled_at`,
 * `dunning_started_at`, `updated_at` — and only if it is not running now.
 * A running agreement with an old `ended_at` came back from a suspension; the
 * gap is lost, and the figures treat it as having run through.
 */
final class Agreement
{
    /**
     * @param  array<int, array{0: Carbon, 1: int}>  $prices  renewal time and amount, in time order
     */
    public function __construct(
        public readonly int $id,
        public readonly string $customer,
        public readonly ?string $name,
        public readonly string $product,
        public readonly string $currency,
        public readonly string $interval,
        public readonly ?int $times,
        public readonly int $timesCharged,
        public readonly string $status,
        public readonly string $standing,
        public readonly int $amountCent,
        public readonly ?Carbon $start,
        public readonly ?Carbon $stop,
        public readonly ?Carbon $nextPaymentAt,
        public readonly array $prices = [],
    ) {}

    /** A payment plan: money on a schedule, never recurring revenue. */
    public function isPlan(): bool
    {
        return $this->times !== null;
    }

    public function isSubscription(): bool
    {
        return ! $this->isPlan() && $this->standing !== Standing::DRAFT;
    }

    /** Running at this moment: begun, and not yet stopped. */
    public function activeAt(Carbon $at): bool
    {
        if ($this->standing === Standing::DRAFT || $this->start === null || $at->lt($this->start)) {
            return false;
        }

        return $this->stop === null || $at->lt($this->stop);
    }

    /** Stopped by a pause or a failed card at or before this moment, and not ended since. */
    public function heldAt(Carbon $at): bool
    {
        return Standing::isHeld($this->standing)
            && $this->stop !== null
            && $this->start !== null
            && $this->stop->gt($this->start)
            && $at->gte($this->stop);
    }

    /** Has it ever earned anything? A trial cancelled before its first charge has not. */
    public function everActive(): bool
    {
        return $this->start !== null
            && $this->standing !== Standing::DRAFT
            && ($this->stop === null || $this->stop->gt($this->start));
    }

    /** Recurring revenue per month at this moment, in minor units, unrounded. */
    public function monthlyAt(Carbon $at): float
    {
        if (! $this->isSubscription() || ! $this->activeAt($at)) {
            return 0.0;
        }

        return $this->priceAt($at) * Interval::monthlyFactor($this->interval);
    }

    public function priceAt(Carbon $at): int
    {
        if ($this->prices === []) {
            return $this->amountCent;
        }

        $preis = $this->prices[0][1];

        foreach ($this->prices as [$wann, $betrag]) {
            if ($wann->gt($at)) {
                break;
            }

            $preis = $betrag;
        }

        return $preis;
    }

    /**
     * The charges it will make from `$from` up to `$until`, at today's price.
     *
     * Only for a running agreement. The first date is `next_payment_at`, or the
     * start of a trial that has none; a plan stops after its last instalment.
     * A date already past is stepped over and not counted — an overdue charge
     * is not a coming one.
     *
     * @return array<int, Carbon>
     */
    public function chargesBetween(Carbon $from, Carbon $until): array
    {
        if ($this->standing !== Standing::LIVE) {
            return [];
        }

        $naechste = $this->nextPaymentAt
            ?? ($this->start !== null && $this->start->gte($from) ? $this->start : null);

        if ($naechste === null) {
            return [];
        }

        $offen = $this->isPlan() ? max(0, (int) $this->times - $this->timesCharged) : PHP_INT_MAX;
        $termine = [];
        $wann = $naechste->copy();

        // Bounded by the window, and a rhythm always moves forward (see
        // Interval), so this ends. The guard is for a rhythm of zero that a
        // future parser might let through.
        for ($schritt = 0; $schritt < 1000 && $offen > 0 && $wann->lt($until); $schritt++) {
            if ($wann->gte($from)) {
                $termine[] = $wann->copy();
                $offen--;
            }

            $wann = Interval::add($wann, $this->interval);
        }

        return $termine;
    }
}
