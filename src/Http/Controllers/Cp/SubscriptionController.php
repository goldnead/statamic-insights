<?php

namespace Goldnead\StatamicInsights\Http\Controllers\Cp;

use Goldnead\StatamicInsights\Subscriptions\AgreementReader;
use Goldnead\StatamicInsights\Subscriptions\SubscriptionFigures;
use Goldnead\StatamicInsights\Subscriptions\SubscriptionView;
use Goldnead\StatamicInsights\Support\Period;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * The subscriptions screen: recurring revenue, how it moved, who left, and
 * what is about to be charged.
 *
 * One currency at a time, like the revenue screen, and for the same reason:
 * the suite knows no exchange rate, and a sum of euros and francs is a number
 * without a meaning. The switch offers every currency an agreement runs in.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        protected AgreementReader $reader,
        protected SubscriptionView $view,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view insights');

        $period = Period::fromPreset(
            $request->query('period', config('statamic-insights.default_period', '30d'))
        );

        $installed = $this->reader->available();
        $figures = new SubscriptionFigures($this->reader->all(), Carbon::now());
        $currencies = $figures->currencies();
        $currency = $this->currencyFor($request, $currencies);
        $has = $currency !== null && ! $figures->isEmpty();

        return Inertia::render('insights::Subscriptions', array_merge([
            'installed' => $installed,
            'hasSubscriptions' => $has,
            'period' => $period->preset,
            'periodOptions' => $this->periodOptions(),
            'currency' => $currency,
            'currencyOptions' => array_map(fn ($c) => ['value' => $c, 'label' => $c], $currencies),
            'reportUrls' => [
                'movements' => cp_route('insights.reports.show', ['report' => 'payments.mrr_movements']),
                'cohorts' => cp_route('insights.reports.show', ['report' => 'payments.subscription_cohorts']),
                'upcoming' => cp_route('insights.reports.show', ['report' => 'payments.upcoming_charges']),
                'forecast' => cp_route('insights.reports.show', ['report' => 'payments.subscription_forecast']),
            ],
        ], $has ? $this->view->assemble($figures, $period, $currency) : []));
    }

    /**
     * The one asked for if an agreement runs in it; otherwise the configured
     * one if it does; otherwise the busiest.
     *
     * @param  array<int, string>  $currencies
     */
    protected function currencyFor(Request $request, array $currencies): ?string
    {
        $gewuenscht = strtoupper((string) $request->query('currency', ''));

        if ($gewuenscht !== '' && in_array($gewuenscht, $currencies, true)) {
            return $gewuenscht;
        }

        $konfiguriert = strtoupper((string) (config('statamic-insights.currency')
            ?: config('statamic-payments.currency', 'EUR')));

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
