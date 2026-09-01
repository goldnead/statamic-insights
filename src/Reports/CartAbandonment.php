<?php

namespace Goldnead\StatamicInsights\Reports;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Neighbours;
use Goldnead\StatamicInsights\Support\TableReport;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Of the checkouts opened in a month, how many paid and how many were left.
 *
 * Reads `payments` of `statamic-payments`. A cohort, by the house rule for a
 * rate: rows are placed in the month of `created_at` — when the checkout was
 * opened — and both halves of the quota come from those same rows. A checkout
 * opened on the 31st and paid on the 1st counts for the month it was opened,
 * so the rate can never exceed 100 %.
 *
 * *Left* means `status` is `open` or `expired`: the buyer reached the
 * provider and did not finish. `initiated` rows — a checkout that never left
 * the site — and `failed` or `canceled` ones are neither, and are not in this
 * table; the sum of the two columns is therefore less than all checkouts.
 */
class CartAbandonment extends TableReport
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
        return 'created_at';
    }

    protected function brandColumn(): ?string
    {
        return 'brand_id';
    }

    public function handle(): string
    {
        return 'payments.abandonment';
    }

    public function label(): string
    {
        return __('statamic-insights::reports.abandonment');
    }

    public function description(): ?string
    {
        return __('statamic-insights::reports.abandonment_description');
    }

    public function group(): string
    {
        return __('statamic-insights::reports.group_payments');
    }

    public function columns(): array
    {
        return [
            $this->column('month', __('statamic-insights::reports.col_month'), 'month'),
            $this->column('paid', __('statamic-insights::reports.col_paid'), Unit::COUNT),
            $this->column('abandoned', __('statamic-insights::reports.col_abandoned'), Unit::COUNT),
            $this->column('rate', __('statamic-insights::reports.col_abandonment_rate'), Unit::PERCENT),
        ];
    }

    public function rows(MetricQuery $query): array
    {
        $month = $this->monthOf('created_at');

        return $this->untilNow($query)
            ->whereIn('status', ['paid', 'open', 'expired'])
            ->selectRaw(
                "{$month} as month, "
                ."sum(case when status = 'paid' then 1 else 0 end) as paid, "
                ."sum(case when status in ('open', 'expired') then 1 else 0 end) as abandoned"
            )
            ->groupBy('month')
            ->orderByDesc('month')
            ->get()
            ->map(function ($row) {
                $paid = (int) $this->number($row->paid);
                $abandoned = (int) $this->number($row->abandoned);

                return [
                    'month' => (string) $row->month,
                    'paid' => $paid,
                    'abandoned' => $abandoned,
                    'rate' => $this->percent($abandoned, $paid + $abandoned),
                ];
            })
            ->all();
    }
}
