<?php

namespace Goldnead\StatamicInsights\Subscriptions;

use Goldnead\StatamicInsights\Reports\SubscriptionForecast;
use Goldnead\StatamicInsights\Reports\UpcomingCharges;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;

/**
 * What the subscriptions screen shows, for one period and one currency.
 *
 * Arrangement only; every number comes from {@see SubscriptionFigures}. The
 * window runs from the start of the period to its end or to now, whichever
 * comes first. "All time" starts at the first subscription, so the churn over
 * it is measured against the day the first one began.
 */
class SubscriptionView
{
    /** @return array<string, mixed> */
    public function assemble(SubscriptionFigures $figures, Period $period, string $currency): array
    {
        $jetzt = Carbon::now();
        $von = $period->from ?? $figures->firstStart($currency) ?? $jetzt->copy()->subYear();
        $bis = $period->toExclusive() === null || $period->toExclusive()->gt($jetzt) ? $jetzt : $period->toExclusive();

        $mrr = $figures->mrr($jetzt, $currency);
        $mrrVorher = $figures->mrr($von, $currency);
        $aktiv = $figures->activeCount($jetzt, $currency);
        $churn = $figures->churn($von, $bis, $currency);
        $stand = $figures->standings($currency);
        $faellig = array_values(array_filter(
            $figures->upcoming(UpcomingCharges::DAYS),
            fn (array $c) => $c['currency'] === $currency,
        ));

        $geld = ['currency' => $currency];

        // The chart covers the period and never less than a year: a thirty-day
        // period drawn as one month is one bar, which is not a trend.
        $jahr = $jetzt->copy()->subMonthsNoOverflow(11)->startOfMonth();
        $diagrammAb = $von->lt($jahr) ? $von : $jahr;
        $erste = $figures->firstStart($currency);

        if ($erste !== null && $erste->gt($diagrammAb) && $period->from === null) {
            $diagrammAb = $erste;
        }

        return [
            'tiles' => [
                $this->tile('mrr', Unit::CURRENCY, $geld, $mrr, $mrrVorher),
                $this->tile('arr', Unit::CURRENCY, $geld, $figures->arr($jetzt, $currency), $figures->arr($von, $currency)),
                $this->tile('active', Unit::COUNT, [], $aktiv, $figures->activeCount($von, $currency), [
                    $stand['trial']['count'] > 0
                        ? trans_choice('statamic-insights::subscriptions.detail_trials', $stand['trial']['count'], ['count' => $stand['trial']['count']])
                        : null,
                ]),
                $this->tile('started', Unit::COUNT, [], $figures->startedCount($von, $bis, $currency)),
                $this->tile('customer_churn', Unit::PERCENT, [], $churn['customer_rate'], null, [
                    trans_choice('statamic-insights::subscriptions.detail_churned', $churn['customers_churned'], ['count' => $churn['customers_churned']]),
                    $churn['customers_paused'] > 0
                        ? trans_choice('statamic-insights::subscriptions.detail_paused', $churn['customers_paused'], ['count' => $churn['customers_paused']])
                        : null,
                ]),
                $this->tile('revenue_churn', Unit::PERCENT, [], $churn['revenue_rate']),
                $this->tile('upcoming', Unit::CURRENCY, $geld, array_sum(array_column($faellig, 'amount_cent')), null, [
                    trans_choice('statamic-insights::subscriptions.detail_charges', count($faellig), ['count' => count($faellig)]),
                ]),
                $this->tile('forecast', Unit::CURRENCY, $geld, $figures->forecastTotal(SubscriptionForecast::MONTHS, $currency)),
            ],
            'churn' => $churn,
            'movements' => $figures->movements($von, $bis, $currency),
            'series' => $figures->series($diagrammAb, $currency),
            'standings' => $this->standings($stand),
            'upcoming' => [
                'count' => count($faellig),
                'total_cent' => array_sum(array_column($faellig, 'amount_cent')),
                'subscriptions_cent' => array_sum(array_column(array_filter($faellig, fn ($c) => $c['kind'] !== 'plan'), 'amount_cent')),
                'plans_cent' => array_sum(array_column(array_filter($faellig, fn ($c) => $c['kind'] === 'plan'), 'amount_cent')),
            ],
            'forecast' => [
                'month' => $figures->forecastTotal(1, $currency),
                'quarter' => $figures->forecastTotal(3, $currency),
                'year' => $figures->forecastTotal(SubscriptionForecast::MONTHS, $currency),
            ],
            'unrecognised' => $figures->unrecognisedStatuses(),
        ];
    }

    /**
     * One figure with its name, the sentence that says what it counts, and
     * any short facts beside it.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<int, string|null>  $details
     * @return array<string, mixed>
     */
    protected function tile(string $handle, string $unit, array $meta, int|float|null $value, int|float|null $previous = null, array $details = []): array
    {
        $delta = null;

        if ($previous !== null && $value !== null && $previous > 0) {
            $delta = round(($value - $previous) / $previous * 100, 1);
        }

        return [
            'handle' => $handle,
            'label' => __("statamic-insights::subscriptions.tile_{$handle}"),
            'hint' => __("statamic-insights::subscriptions.tile_{$handle}_hint"),
            'details' => array_values(array_filter($details)),
            'unit' => $unit,
            'meta' => $meta,
            'value' => $value,
            'previous' => $previous,
            'delta' => $delta,
        ];
    }

    /**
     * @param  array<string, array{count: int, mrr: int}>  $stand
     * @return array<int, array{key: string, label: string, count: int, mrr: int}>
     */
    protected function standings(array $stand): array
    {
        $zeilen = [];

        foreach ($stand as $key => $werte) {
            $zeilen[] = [
                'key' => $key,
                'label' => __('statamic-insights::subscriptions.standing_'.$key),
            ] + $werte;
        }

        return $zeilen;
    }
}
