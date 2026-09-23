<?php

namespace Goldnead\StatamicInsights\Http\Controllers\Cp;

use Goldnead\StatamicInsights\Contracts\Report;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\ReportReader;
use Goldnead\StatamicInsights\Support\ReportRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Every table anybody registered, and one of them in full.
 *
 * Like {@see MetricController} this knows about periods and the report
 * contract, nothing else. One difference: a report whose source is missing is
 * shown, not hidden — with the sentence naming what it needs — because a list
 * of reports doubles as a list of what the suite can tell you.
 */
class ReportController extends Controller
{
    public function __construct(
        protected ReportRegistry $registry,
        protected ReportReader $reader,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view insights');

        return Inertia::render('insights::Reports', [
            'groups' => $this->reader->overview(),
            'detailUrlTemplate' => cp_route('insights.reports.show', ['report' => '__handle__']),
        ]);
    }

    public function show(Request $request, string $report)
    {
        $this->authorizeOrFail($request, 'view insights');

        $found = $this->registry->find($report);

        // A handle nobody registered is a 404. An unavailable one is not: it
        // answers with its explanation, which is the whole point of keeping it.
        if ($found === null) {
            abort(404);
        }

        $period = Period::fromPreset(
            $request->query('period', config('statamic-insights.default_period', '30d'))
        );

        $filters = $this->filters($request, $found);
        $query = new MetricQuery($period, MetricQuery::bucketFor($period), $filters);

        return Inertia::render('insights::Report', [
            'report' => $this->reader->read($found, $query),
            'filters' => (object) $filters,
            'period' => $period->preset,
            'periodOptions' => $this->periodOptions(),
            'indexUrl' => cp_route('insights.reports'),
        ]);
    }

    /**
     * The filters a report offers, each set to what was asked for when that is
     * one of its options, otherwise to its first option. A report that offers
     * a currency is never asked for "all of them": it has no such answer.
     *
     * @return array<string, string>
     */
    protected function filters(Request $request, Report $report): array
    {
        $gesetzt = [];

        if (! $this->reader->available($report)) {
            return $gesetzt;
        }

        foreach ($this->reader->filterOptions($report) as $name => $optionen) {
            $werte = array_values(array_filter(array_map(
                fn ($o) => is_array($o) && isset($o['value']) ? (string) $o['value'] : null,
                (array) $optionen,
            ), fn ($v) => $v !== null));

            if ($werte === []) {
                continue;
            }

            $gewuenscht = (string) $request->query($name, '');
            $treffer = array_values(array_filter($werte, fn ($w) => strcasecmp($w, $gewuenscht) === 0));

            $gesetzt[$name] = $treffer[0] ?? $werte[0];
        }

        return $gesetzt;
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
