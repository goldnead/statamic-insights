<?php

namespace Goldnead\StatamicInsights\Http\Controllers\Cp;

use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\RevenueReport;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RevenueController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view insights');

        $period = Period::fromPreset(
            $request->query('period', config('statamic-insights.default_period', '30d'))
        );

        $currencies = RevenueReport::currencies();
        $currency = $this->currencyFor($request, $currencies);
        $report = new RevenueReport($period, $currency);

        return Inertia::render('insights::Revenue', [
            'installed' => RevenueReport::available(),
            'hasSales' => $currencies !== [],
            'period' => $period->preset,
            'periodOptions' => $this->periodOptions(),
            'currency' => $currency,
            'currencyOptions' => array_map(fn ($c) => ['value' => $c, 'label' => $c], $currencies),
            'otherCurrencies' => $report->otherCurrencies(),
            'totals' => $report->totals(),
            'byCampaign' => $report->byCampaign(),
            'byProduct' => $report->byProduct(),
            'overTime' => $report->overTime(),
            'productSumCent' => $report->productSumCent(),
        ]);
    }

    /**
     * Which currency to show.
     *
     * The one asked for if it was ever taken, otherwise the busiest one the
     * data actually contains — not the configured default, which on a site that
     * only ever sold in francs would show an empty screen and no reason for it.
     */
    protected function currencyFor(Request $request, array $currencies): string
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

        return $currencies[0] ?? (string) $konfiguriert;
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
