<?php

namespace Goldnead\StatamicInsights\Http\Controllers\Cp;

use Goldnead\StatamicInsights\Contracts\HasFilterOptions;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\MetricRegistry;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\RevenueView;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The screen somebody opens with a question already in mind.
 *
 * Everything on it now comes from registered metrics — this controller reads no
 * table and names no sibling. What makes it different from the generic list is
 * arrangement, not access: four figures in the order a person reads them, the
 * chart, and the two splits that answer "where did it come from".
 */
class RevenueController extends Controller
{
    public function __construct(protected RevenueView $view) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view insights');

        $period = Period::fromPreset(
            $request->query('period', config('statamic-insights.default_period', '30d'))
        );

        $waehrungen = $this->currencies();
        $currencies = array_column($waehrungen, 'value');
        $currency = $this->currencyFor($request, $currencies);

        $query = new MetricQuery(
            $period,
            MetricQuery::bucketFor($period),
            $currency === null ? [] : ['currency' => $currency],
        );

        $zusammengestellt = $this->view->assemble($query);

        return Inertia::render('insights::Revenue', array_merge([
            // From the value, not from the currency list: `HasFilterOptions` is
            // optional by contract, and a metric implementing only the required
            // part reported real revenue while this screen said "no paid order
            // yet". Whoever implements the documented minimum must not get an
            // empty state over their own numbers.
            'hasSales' => ($zusammengestellt['grossCent'] ?? null) !== null
                && ($zusammengestellt['grossCent'] ?? 0) != 0,
            'period' => $period->preset,
            'periodOptions' => $this->periodOptions(),
            'currency' => $currency,
            'currencyOptions' => $waehrungen,
            'metricsUrl' => cp_route('insights.metrics'),
        ], $zusammengestellt));
    }

    /**
     * Which currencies have ever been taken.
     *
     * Asked of the metric through {@see HasFilterOptions}, not of a table: this
     * addon does not know where money is recorded, and the day it does is the
     * day it needs another addon's schema again. A metric that offers no such
     * list simply gives the screen one currency and no switch.
     *
     * @return array<int, array<string, string>>
     */
    protected function currencies(): array
    {
        $metrik = app(MetricRegistry::class)->find(RevenueView::HANDLES['gross']);

        if (! $metrik instanceof HasFilterOptions) {
            return [];
        }

        try {
            $optionen = $metrik->filterOptions()['currency'] ?? [];
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(
            $optionen,
            fn ($o) => is_array($o) && isset($o['value'], $o['label']),
        ));
    }

    /**
     * The one asked for if it was ever taken, otherwise the busiest one there
     * is — not the configured default, which on a site that only ever sold in
     * francs would open an empty screen with no reason given.
     *
     * @param  array<int, string>  $currencies
     */
    protected function currencyFor(Request $request, array $currencies): ?string
    {
        $gewuenscht = strtoupper((string) $request->query('currency', ''));

        if ($gewuenscht !== '' && in_array($gewuenscht, $currencies, true)) {
            return $gewuenscht;
        }

        $konfiguriert = config('statamic-insights.currency')
            ?: config('statamic-payments.currency', 'EUR');

        if (in_array($konfiguriert, $currencies, true)) {
            return $konfiguriert;
        }

        return $currencies[0] ?? null;
    }

    /** @return array<int, array<string, string>> */
    protected function periodOptions(): array
    {
        return [
            ['value' => '7d', 'label' => __('statamic-insights::report.period_7d')],
            ['value' => '30d', 'label' => __('statamic-insights::report.period_30d')],
            ['value' => '90d', 'label' => __('statamic-insights::report.period_90d')],
            ['value' => '12m', 'label' => __('statamic-insights::report.period_12m')],
            ['value' => 'ytd', 'label' => __('statamic-insights::report.period_ytd')],
            ['value' => 'all', 'label' => __('statamic-insights::report.period_all')],
        ];
    }
}
