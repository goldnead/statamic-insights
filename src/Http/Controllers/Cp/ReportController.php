<?php

namespace Goldnead\StatamicInsights\Http\Controllers\Cp;

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

        $query = new MetricQuery($period, MetricQuery::bucketFor($period));

        return Inertia::render('insights::Report', [
            'report' => $this->reader->read($found, $query),
            'period' => $period->preset,
            'periodOptions' => $this->periodOptions(),
            'indexUrl' => cp_route('insights.reports'),
        ]);
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
