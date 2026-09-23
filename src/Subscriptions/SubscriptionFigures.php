<?php

namespace Goldnead\StatamicInsights\Subscriptions;

use Illuminate\Support\Carbon;

/**
 * The subscription figures, worked out from a list of agreements.
 *
 * The rules, fixed before anything was built (the backlog asked for that, and
 * a figure whose rule changes with the implementation is not a figure):
 *
 * - **MRR** is the monthly worth of every subscription running at a moment:
 *   the cycle price times the rhythm's monthly factor (a quarter counts a
 *   third, a year a twelfth, a week 52/12). Rounded once, on the sum.
 * - **Payment plans are not MRR.** A plan ends by design; its instalments are
 *   in the charges due and the forecast, on a line of their own.
 * - **A trial is not MRR** until its first charge, and a draft never is.
 * - **Currencies are never added.** Every figure is asked for one currency.
 *   There is no exchange rate anywhere in the suite, and which day's rate
 *   would turn last March's francs into euros is a question with no right
 *   answer for a trend line — so each currency is its own figure, the way
 *   the revenue screen already does it.
 * - **A pause is not churn.** Held agreements — paused, stopped after a
 *   failed card, or in a status this addon does not know — leave MRR as their
 *   own movement and count as retained. Only an ended subscription is churn.
 * - **Movements are per agreement.** A second subscription of the same person
 *   is new MRR; a person who had left and comes back on a new agreement is a
 *   reactivation. Customer churn counts people, by e-mail address.
 * - **Nothing is read at the database's clock.** `now` is handed in.
 */
final class SubscriptionFigures
{
    /** Months after the start at which a cohort is measured. */
    public const COHORT_POINTS = [1, 2, 3, 6, 12];

    /** @param  array<int, Agreement>  $agreements */
    public function __construct(
        private readonly array $agreements,
        private readonly Carbon $now,
    ) {}

    public function isEmpty(): bool
    {
        return $this->subscriptionsAndPlans() === [];
    }

    /**
     * The currencies agreements run in, the busiest first.
     *
     * @return array<int, string>
     */
    public function currencies(): array
    {
        $zaehler = [];

        foreach ($this->subscriptionsAndPlans() as $a) {
            $zaehler[$a->currency] = ($zaehler[$a->currency] ?? 0) + 1;
        }

        uksort($zaehler, fn ($x, $y) => [$zaehler[$y], $x] <=> [$zaehler[$x], $y]);

        return array_keys($zaehler);
    }

    /** Status words on the rows that this addon had to guess at. @return array<int, string> */
    public function unrecognisedStatuses(): array
    {
        $unbekannt = [];

        foreach ($this->agreements as $a) {
            if (! Standing::isKnown($a->status)) {
                $unbekannt[$a->status] = true;
            }
        }

        $liste = array_keys($unbekannt);
        sort($liste);

        return array_map('strval', $liste);
    }

    public function mrr(Carbon $at, string $currency): int
    {
        return (int) round($this->mrrExact($at, $currency));
    }

    public function arr(Carbon $at, string $currency): int
    {
        return (int) round($this->mrrExact($at, $currency) * 12);
    }

    /** Subscriptions paying at this moment. */
    public function activeCount(Carbon $at, string $currency): int
    {
        return count(array_filter(
            $this->inCurrency($currency),
            fn (Agreement $a) => $a->isSubscription() && $a->activeAt($at),
        ));
    }

    /** Subscriptions that began inside the window. */
    public function startedCount(Carbon $from, Carbon $until, string $currency): int
    {
        return count(array_filter(
            $this->inCurrency($currency),
            fn (Agreement $a) => $a->isSubscription() && $a->everActive()
                && $a->start->gte($from) && $a->start->lt($until),
        ));
    }

    /**
     * How MRR moved between two moments, and why.
     *
     * Reconciles by construction: start + new + reactivation + expansion
     * − contraction − churn − paused = end.
     *
     * Summed month by month, not compared end to end. Compared end to end, a
     * subscription that started in March and went up in June would be one
     * "new" at the June price and no expansion at all; a year's window would
     * hide every price change of everybody who joined inside it.
     *
     * @return array{mrr_start: int, new: int, reactivation: int, expansion: int, contraction: int, churn: int, paused: int, net: int, mrr_end: int}
     */
    public function movements(Carbon $from, Carbon $until, string $currency): array
    {
        $summe = ['new' => 0.0, 'reactivation' => 0.0, 'expansion' => 0.0, 'contraction' => 0.0, 'churn' => 0.0, 'paused' => 0.0];

        foreach ($this->segments($from, $until) as [$a, $b]) {
            foreach ($this->segmentMovements($a, $b, $currency) as $k => $v) {
                $summe[$k] += $v;
            }
        }

        $gerundet = array_map(fn ($v) => (int) round($v), $summe);

        // The ends are rounded on their own sums; the net is what the rounded
        // movements say, so the table always adds up on screen.
        $netto = $gerundet['new'] + $gerundet['reactivation'] + $gerundet['expansion']
            - $gerundet['contraction'] - $gerundet['churn'] - $gerundet['paused'];

        return [
            'mrr_start' => $this->mrr($from, $currency),
            'new' => $gerundet['new'],
            'reactivation' => $gerundet['reactivation'],
            'expansion' => $gerundet['expansion'],
            'contraction' => $gerundet['contraction'],
            'churn' => $gerundet['churn'],
            'paused' => $gerundet['paused'],
            'net' => $netto,
            'mrr_end' => $this->mrr($until, $currency),
        ];
    }

    /**
     * Customer churn and gross revenue churn, **per month**.
     *
     * In each calendar month of the window: of the people paying at its start,
     * the share paying nothing at its end and holding nothing either; and
     * churned plus contracted MRR over the MRR at its start. Over a window of
     * several months the rate is the mean of the months — the figure every
     * subscription business compares, and the only one that does not read
     * 0 % for a year in which the base grew tenfold and five people left.
     * Null over nobody. `customers_churned` is the total over the window.
     *
     * @return array{customers_start: int, customers_churned: int, customer_rate: ?float, revenue_rate: ?float}
     */
    public function churn(Carbon $from, Carbon $until, string $currency): array
    {
        $weg = 0;
        $kundenQuoten = [];
        $umsatzQuoten = [];

        foreach ($this->segments($from, $until) as [$a, $b]) {
            $vorher = array_filter($this->customerMrr($a, $currency), fn ($v) => $v > 0);
            $nachher = $this->customerMrr($b, $currency);
            $gehalten = [];

            foreach ($this->inCurrency($currency) as $vertrag) {
                if ($vertrag->isSubscription() && $vertrag->heldAt($b)) {
                    $gehalten[$vertrag->customer] = true;
                }
            }

            $hier = 0;

            foreach (array_keys($vorher) as $kunde) {
                if (($nachher[$kunde] ?? 0.0) == 0.0 && ! isset($gehalten[$kunde])) {
                    $hier++;
                }
            }

            $weg += $hier;

            if ($vorher !== []) {
                $kundenQuoten[] = $hier / count($vorher);
            }

            $mrrAnfang = $this->mrrExact($a, $currency);

            if ($mrrAnfang > 0) {
                $bewegung = $this->segmentMovements($a, $b, $currency);
                $umsatzQuoten[] = ($bewegung['churn'] + $bewegung['contraction']) / $mrrAnfang;
            }
        }

        return [
            'customers_start' => count(array_filter($this->customerMrr($from, $currency), fn ($v) => $v > 0)),
            'customers_churned' => $weg,
            'customer_rate' => $this->mean($kundenQuoten),
            'revenue_rate' => $this->mean($umsatzQuoten),
        ];
    }

    /**
     * The window cut at every month boundary inside it.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function segments(Carbon $from, Carbon $until): array
    {
        $teile = [];
        $a = $from->copy();

        while ($a->lt($until)) {
            $grenze = $a->copy()->startOfMonth()->addMonthNoOverflow();
            $b = $grenze->lt($until) ? $grenze : $until->copy();
            $teile[] = [$a, $b];
            $a = $b->copy();
        }

        return $teile;
    }

    /** @return array{new: float, reactivation: float, expansion: float, contraction: float, churn: float, paused: float} */
    private function segmentMovements(Carbon $from, Carbon $until, string $currency): array
    {
        $summe = ['new' => 0.0, 'reactivation' => 0.0, 'expansion' => 0.0, 'contraction' => 0.0, 'churn' => 0.0, 'paused' => 0.0];
        $vorher = $this->customerMrr($from, $currency);

        foreach ($this->inCurrency($currency) as $a) {
            if (! $a->isSubscription()) {
                continue;
            }

            $anfang = $a->monthlyAt($from);
            $ende = $a->monthlyAt($until);

            if ($anfang == 0.0 && $ende > 0.0) {
                $zurueck = ($vorher[$a->customer] ?? 0.0) == 0.0 && $this->hadEarlierAgreement($a, $from);
                $summe[$zurueck ? 'reactivation' : 'new'] += $ende;
            } elseif ($anfang > 0.0 && $ende == 0.0) {
                $summe[Standing::isHeld($a->standing) ? 'paused' : 'churn'] += $anfang;
            } elseif ($ende > $anfang) {
                $summe['expansion'] += $ende - $anfang;
            } elseif ($ende < $anfang) {
                $summe['contraction'] += $anfang - $ende;
            }
        }

        return $summe;
    }

    /** @param  array<int, float>  $quoten */
    private function mean(array $quoten): ?float
    {
        return $quoten === [] ? null : round(array_sum($quoten) / count($quoten) * 100, 1);
    }

    /**
     * MRR at the end of each month, the running month at this moment.
     *
     * @return array<int, array{bucket: string, value: int}>
     */
    public function series(Carbon $fromMonth, string $currency): array
    {
        $reihe = [];
        $monat = $fromMonth->copy()->startOfMonth();

        while ($monat->lte($this->now)) {
            $ende = $monat->copy()->addMonthNoOverflow()->startOfMonth();
            $reihe[] = [
                'bucket' => $monat->format('Y-m'),
                'value' => $this->mrr($ende->lt($this->now) ? $ende : $this->now, $currency),
            ];
            $monat = $ende;
        }

        return $reihe;
    }

    /** The month the first subscription in this currency began, if any. */
    public function firstStart(?string $currency = null): ?Carbon
    {
        $erste = null;

        foreach ($currency === null ? $this->agreements : $this->inCurrency($currency) as $a) {
            if ($a->isSubscription() && $a->everActive() && ($erste === null || $a->start->lt($erste))) {
                $erste = $a->start;
            }
        }

        return $erste?->copy();
    }

    /**
     * Movements month by month, one row per month and currency, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthlyMovements(?Carbon $from, ?Carbon $until): array
    {
        $zeilen = [];
        $bis = $until === null || $until->gt($this->now) ? $this->now : $until;

        foreach ($this->currencies() as $waehrung) {
            $erste = $this->firstStart($waehrung);

            if ($erste === null) {
                continue;
            }

            $monat = ($from !== null && $from->gt($erste) ? $from : $erste)->copy()->startOfMonth();

            while ($monat->lt($bis)) {
                $naechster = $monat->copy()->addMonthNoOverflow()->startOfMonth();
                $ende = $naechster->lt($this->now) ? $naechster : $this->now;

                $zeilen[] = ['month' => $monat->format('Y-m'), 'currency' => $waehrung]
                    + $this->movements($monat, $ende, $waehrung);

                $monat = $naechster;
            }
        }

        usort($zeilen, fn ($a, $b) => [$b['month'], $a['currency']] <=> [$a['month'], $b['currency']]);

        return $zeilen;
    }

    /**
     * Of the subscriptions begun in a month, the share still there n months on.
     *
     * Measured per agreement at its own start plus n months, so a cohort does
     * not favour whoever started on the 1st. Still there means running or held;
     * a pause is not a departure. A point in the future is not measured: the
     * share is over the agreements old enough to have reached it, or null.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cohorts(): array
    {
        $gruppen = [];

        foreach ($this->agreements as $a) {
            if (! $a->isSubscription() || ! $a->everActive() || $a->start->gt($this->now)) {
                continue;
            }

            $gruppen[$a->start->format('Y-m').'|'.$a->currency][] = $a;
        }

        $zeilen = [];

        foreach ($gruppen as $schluessel => $liste) {
            [$monat, $waehrung] = explode('|', $schluessel, 2);
            $zeile = ['cohort' => $monat, 'currency' => $waehrung, 'started' => count($liste)];

            foreach (self::COHORT_POINTS as $n) {
                $gemessen = 0;
                $geblieben = 0;

                foreach ($liste as $a) {
                    $punkt = $a->start->copy()->addMonthsNoOverflow($n);

                    if ($punkt->gt($this->now)) {
                        continue;
                    }

                    $gemessen++;

                    if ($a->activeAt($punkt) || $a->heldAt($punkt)) {
                        $geblieben++;
                    }
                }

                $zeile['m'.$n] = $this->percent($geblieben, $gemessen);
            }

            $zeile['live'] = count(array_filter($liste, fn (Agreement $a) => $a->activeAt($this->now)));
            $zeilen[] = $zeile;
        }

        usort($zeilen, fn ($a, $b) => [$b['cohort'], $a['currency']] <=> [$a['cohort'], $b['currency']]);

        return $zeilen;
    }

    /**
     * Every charge the running agreements will make in the next days, in date order.
     *
     * @return array<int, array{id: int, at: string, product: string, customer: ?string, kind: string, currency: string, amount_cent: int}>
     */
    public function upcoming(int $days): array
    {
        return $this->charges($this->now, $this->now->copy()->addDays($days));
    }

    /**
     * What the running agreements will charge, month by month, if nobody
     * joins, leaves or changes price. One row per month and currency; the
     * running month counts from now.
     *
     * @return array<int, array{month: string, currency: string, subscriptions_cent: int, plans_cent: int, total_cent: int}>
     */
    public function forecast(int $months): array
    {
        $bis = $this->now->copy()->addMonthsNoOverflow($months);
        $zeilen = [];

        foreach ($this->liveCurrencies() as $waehrung) {
            $monat = $this->now->copy()->startOfMonth();

            while ($monat->lt($bis)) {
                $zeilen[$monat->format('Y-m').'|'.$waehrung] = [
                    'month' => $monat->format('Y-m'),
                    'currency' => $waehrung,
                    'subscriptions_cent' => 0,
                    'plans_cent' => 0,
                    'total_cent' => 0,
                ];
                $monat->addMonthNoOverflow()->startOfMonth();
            }
        }

        foreach ($this->charges($this->now, $bis) as $c) {
            $schluessel = substr($c['at'], 0, 7).'|'.$c['currency'];
            $zeilen[$schluessel][$c['kind'] === 'plan' ? 'plans_cent' : 'subscriptions_cent'] += $c['amount_cent'];
            $zeilen[$schluessel]['total_cent'] += $c['amount_cent'];
        }

        return array_values($zeilen);
    }

    /** The sum of the charges from now for the next months, in one currency. */
    public function forecastTotal(int $months, string $currency): int
    {
        $summe = 0;

        foreach ($this->charges($this->now, $this->now->copy()->addMonthsNoOverflow($months)) as $c) {
            if ($c['currency'] === $currency) {
                $summe += $c['amount_cent'];
            }
        }

        return $summe;
    }

    /**
     * How many agreements stand where, right now.
     *
     * @return array<string, array{count: int, mrr: int}>
     */
    public function standings(string $currency): array
    {
        $stand = [];
        $roh = [];

        foreach (['active', 'trial', 'paused', 'suspended', 'ended', 'plans'] as $k) {
            $stand[$k] = ['count' => 0, 'mrr' => 0];
            $roh[$k] = 0.0;
        }

        foreach ($this->inCurrency($currency) as $a) {
            if ($a->standing === Standing::DRAFT) {
                continue;
            }

            if ($a->isPlan()) {
                if ($a->standing === Standing::LIVE) {
                    $stand['plans']['count']++;
                }

                continue;
            }

            $wert = $a->priceAt($this->now) * Interval::monthlyFactor($a->interval);

            $k = match (true) {
                $a->standing === Standing::LIVE && $a->activeAt($this->now) => 'active',
                $a->standing === Standing::LIVE => 'trial',
                $a->standing === Standing::PAUSED => 'paused',
                $a->standing === Standing::SUSPENDED => 'suspended',
                default => 'ended',
            };

            $stand[$k]['count']++;
            $roh[$k] += $wert;
        }

        foreach ($roh as $k => $v) {
            $stand[$k]['mrr'] = (int) round($v);
        }

        return $stand;
    }

    /** @return array<int, array{id: int, at: string, product: string, customer: ?string, kind: string, currency: string, amount_cent: int}> */
    private function charges(Carbon $from, Carbon $until): array
    {
        $liste = [];

        foreach ($this->agreements as $a) {
            foreach ($a->chargesBetween($from, $until) as $wann) {
                $liste[] = [
                    'id' => $a->id,
                    'at' => $wann->format('Y-m-d H:i:s'),
                    'product' => $a->product,
                    'customer' => $a->name,
                    'kind' => $a->isPlan() ? 'plan' : 'subscription',
                    'currency' => $a->currency,
                    'amount_cent' => $a->amountCent,
                ];
            }
        }

        usort($liste, fn ($x, $y) => [$x['at'], $x['id']] <=> [$y['at'], $y['id']]);

        return $liste;
    }

    /** @return array<int, string> */
    private function liveCurrencies(): array
    {
        return array_values(array_filter(
            $this->currencies(),
            fn ($w) => array_filter($this->inCurrency($w), fn (Agreement $a) => $a->standing === Standing::LIVE) !== [],
        ));
    }

    private function mrrExact(Carbon $at, string $currency): float
    {
        $summe = 0.0;

        foreach ($this->inCurrency($currency) as $a) {
            $summe += $a->monthlyAt($at);
        }

        return $summe;
    }

    /** @return array<string, float> */
    private function customerMrr(Carbon $at, string $currency): array
    {
        $je = [];

        foreach ($this->inCurrency($currency) as $a) {
            $je[$a->customer] = ($je[$a->customer] ?? 0.0) + $a->monthlyAt($at);
        }

        return $je;
    }

    private function hadEarlierAgreement(Agreement $neu, Carbon $before): bool
    {
        foreach ($this->agreements as $a) {
            if ($a->id !== $neu->id && $a->customer === $neu->customer && $a->isSubscription()
                && $a->everActive() && $a->start->lt($before)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, Agreement> */
    private function inCurrency(string $currency): array
    {
        $currency = strtoupper($currency);

        return array_filter($this->agreements, fn (Agreement $a) => $a->currency === $currency);
    }

    /** @return array<int, Agreement> */
    private function subscriptionsAndPlans(): array
    {
        return array_filter($this->agreements, fn (Agreement $a) => $a->standing !== Standing::DRAFT);
    }

    private function percent(int|float $part, int|float $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round($part / $whole * 100, 1);
    }
}
