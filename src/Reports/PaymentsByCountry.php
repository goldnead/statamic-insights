<?php

namespace Goldnead\StatamicInsights\Reports;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Neighbours;
use Goldnead\StatamicInsights\Support\TableReport;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Paid payments by the buyer's country.
 *
 * Reads `payments.country` of `statamic-payments` — the two-letter code the
 * checkout froze on the row, from the provider or the billing address, see
 * that addon's `country_source`. A payment with no country is a row under
 * *unknown*, never dropped: a table that quietly loses rows disagrees with
 * the total and nothing says why.
 *
 * The code is spelled out through `intl` when PHP has it, in the language the
 * Control Panel is being read in. Without `intl` the code itself is shown.
 */
class PaymentsByCountry extends TableReport
{
    protected function neighbour(): string
    {
        return Neighbours::PAYMENTS;
    }

    protected function table(): string
    {
        return 'payments';
    }

    protected function timestamp(): string
    {
        return 'paid_at';
    }

    protected function brandColumn(): ?string
    {
        return 'brand_id';
    }

    public function handle(): string
    {
        return 'payments.by_country';
    }

    public function label(): string
    {
        return __('statamic-insights::reports.by_country');
    }

    public function description(): ?string
    {
        return __('statamic-insights::reports.by_country_description');
    }

    public function group(): string
    {
        return __('statamic-insights::reports.group_payments');
    }

    public function columns(): array
    {
        return [
            $this->column('country', __('statamic-insights::reports.col_country'), 'text'),
            $this->column('code', __('statamic-insights::reports.col_code'), 'code'),
            $this->column('currency', __('statamic-insights::reports.col_currency'), 'text'),
            $this->column('payments', __('statamic-insights::reports.col_payments'), Unit::COUNT),
            $this->column('revenue_cent', __('statamic-insights::reports.col_revenue'), Unit::CURRENCY),
        ];
    }

    public function rows(MetricQuery $query): array
    {
        return $this->untilNow($query)
            ->where('status', 'paid')
            // Upper-cased in SQL, so `de` and `DE` are one country and not two
            // rows that a reader has to add up in their head.
            ->selectRaw('upper(country) as country, currency, count(*) as payments, sum(amount_cent) as revenue_cent')
            ->groupByRaw('upper(country), currency')
            ->orderByDesc('payments')
            ->orderByRaw('upper(country)')
            ->get()
            ->map(function ($row) {
                $code = ($row->country === null || $row->country === '') ? null : strtoupper((string) $row->country);

                return [
                    'country' => $code === null ? __('statamic-insights::reports.country_unknown') : $this->countryName($code),
                    'code' => $code,
                    'currency' => (string) $row->currency,
                    'payments' => (int) $this->number($row->payments),
                    'revenue_cent' => (int) $this->number($row->revenue_cent),
                ];
            })
            ->all();
    }

    protected function countryName(string $code): string
    {
        if (! class_exists(\Locale::class)) {
            return $code;
        }

        $name = \Locale::getDisplayRegion('-'.$code, app()->getLocale());

        return ($name === '' || $name === false) ? $code : $name;
    }
}
