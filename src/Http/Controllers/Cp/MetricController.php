<?php

namespace Goldnead\StatamicInsights\Http\Controllers\Cp;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\MetricReader;
use Goldnead\StatamicInsights\Support\MetricRegistry;
use Goldnead\StatamicInsights\Support\Period;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Every number anybody registered, and one of them up close.
 *
 * This screen knows nothing about payments, campaigns or contacts. It knows
 * about periods and about the metric contract — which is the whole point: an
 * addon that registers a metric tomorrow appears here without a line changing.
 */
class MetricController extends Controller
{
    public function __construct(
        protected MetricRegistry $registry,
        protected MetricReader $reader,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view insights');

        $period = Period::fromPreset(
            $request->query('period', config('statamic-insights.default_period', '30d'))
        );

        $query = new MetricQuery($period, MetricQuery::bucketFor($period), $this->filters($request));

        return Inertia::render('insights::Metrics', [
            'period' => $period->preset,
            'periodOptions' => $this->periodOptions(),
            'groups' => $this->reader->overview($query),
            'detailUrlTemplate' => cp_route('insights.metrics.show', ['metric' => '__handle__']),
        ]);
    }

    public function show(Request $request, string $metric)
    {
        $this->authorizeOrFail($request, 'view insights');

        // The handle carries dots (`payments.revenue_gross`), which a route
        // parameter is happy with — but a metric that is registered and one
        // that never was must not answer the same way, or a typo in a saved
        // link reads as "this number is zero".
        $found = $this->registry->find($metric);

        if ($found === null || ! $found->available()) {
            abort(404);
        }

        $period = Period::fromPreset(
            $request->query('period', config('statamic-insights.default_period', '30d'))
        );

        $query = new MetricQuery($period, MetricQuery::bucketFor($period), $this->filters($request));
        $gelesen = $this->reader->read($found, $query);

        // Registered, available, and still unable to answer — a contributor
        // whose `value()` throws. The list leaves such a metric out; this
        // screen used to hand the page a null and let the browser fall over on
        // `metric.label`, which is the one promise the reader makes broken on
        // the very URL that ends up in saved links.
        if ($gelesen === null) {
            abort(404);
        }

        $breakdowns = array_keys($gelesen['breakdowns'] ?? []);
        $dimension = $request->query('by');
        $dimension = in_array($dimension, $breakdowns, true) ? $dimension : ($breakdowns[0] ?? null);

        return Inertia::render('insights::Metric', [
            'metric' => $gelesen,
            'series' => $this->reader->series($found, $query),
            'dimension' => $dimension,
            'breakdown' => $dimension === null ? [] : $this->reader->breakdown($found, $query, $dimension),
            'period' => $period->preset,
            'periodOptions' => $this->periodOptions(),
            'indexUrl' => cp_route('insights.metrics'),
        ]);
    }

    /**
     * What every metric on the screen is asked alongside the period.
     *
     * Passed to all of them; a metric reads what it understands and ignores the
     * rest, so a currency handed to a metric counting bookings is not an error.
     *
     * @return array<string, mixed>
     */
    protected function filters(Request $request): array
    {
        $filters = [];

        if ($currency = strtoupper((string) $request->query('currency', ''))) {
            $filters['currency'] = $currency;
        }

        return $filters;
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
