<?php

namespace Goldnead\StatamicInsights\Reports;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Neighbours;
use Goldnead\StatamicInsights\Support\TableReport;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * What was paid, month by month, with the order count and the average beside it.
 *
 * Reads `payments` of `statamic-payments`. Paid means `status = paid`, and a
 * payment belongs to the month of `paid_at` — the day the money arrived, not
 * the day the checkout was opened. Gross: refunds are not subtracted here,
 * because a refund belongs to the month it went back and this table is keyed
 * on the month the sale came in; the revenue screen carries the net figure.
 *
 * One row per month **and currency**. Two currencies in one month are two rows,
 * never one sum — 100 EUR plus 100 CHF is a number with no meaning.
 */
class RevenueByMonth extends TableReport
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
        return 'payments.revenue_by_month';
    }

    public function label(): string
    {
        return __('statamic-insights::reports.revenue_by_month');
    }

    public function description(): ?string
    {
        return __('statamic-insights::reports.revenue_by_month_description');
    }

    public function group(): string
    {
        return __('statamic-insights::reports.group_payments');
    }

    public function columns(): array
    {
        return [
            $this->column('month', __('statamic-insights::reports.col_month'), 'month'),
            $this->column('currency', __('statamic-insights::reports.col_currency'), 'text'),
            $this->column('revenue_cent', __('statamic-insights::reports.col_revenue'), Unit::CURRENCY),
            $this->column('payments', __('statamic-insights::reports.col_payments'), Unit::COUNT),
            $this->column('average_cent', __('statamic-insights::reports.col_average'), Unit::CURRENCY),
        ];
    }

    public function rows(MetricQuery $query): array
    {
        $month = $this->monthOf('paid_at');

        return $this->untilNow($query)
            ->where('status', 'paid')
            ->selectRaw("{$month} as month, currency, sum(amount_cent) as revenue_cent, count(*) as payments")
            ->groupBy('month', 'currency')
            ->orderByDesc('month')
            ->orderBy('currency')
            ->get()
            ->map(function ($row) {
                $revenue = (int) $this->number($row->revenue_cent);
                $payments = (int) $this->number($row->payments);

                return [
                    'month' => (string) $row->month,
                    'currency' => (string) $row->currency,
                    'revenue_cent' => $revenue,
                    'payments' => $payments,
                    'average_cent' => $payments > 0 ? (int) round($revenue / $payments) : null,
                ];
            })
            ->all();
    }
}
