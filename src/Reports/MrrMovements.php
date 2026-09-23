<?php

namespace Goldnead\StatamicInsights\Reports;

use Goldnead\StatamicInsights\Contracts\HasDefaultSort;
use Goldnead\StatamicInsights\Subscriptions\SubscriptionFigures;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How recurring revenue moved, month by month: where it stood, what came in,
 * what went, where it ended.
 *
 * One row per month **and currency**, like {@see RevenueByMonth}. The running
 * month ends now. The rules behind every column are on
 * {@see SubscriptionFigures}.
 */
class MrrMovements extends SubscriptionReport implements HasDefaultSort
{
    /** The latest month first: it is the one somebody opens this to see. */
    public function defaultSort(): array
    {
        return ['column' => 'month', 'direction' => 'desc'];
    }

    public function handle(): string
    {
        return 'payments.mrr_movements';
    }

    public function label(): string
    {
        return $this->t('mrr_movements');
    }

    public function description(): ?string
    {
        return $this->t('mrr_movements_description');
    }

    public function usesPeriod(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            $this->column('month', $this->t('col_month'), 'month'),
            $this->column('currency', $this->t('col_currency'), 'text'),
            $this->column('mrr_start', $this->t('col_mrr_start'), Unit::CURRENCY),
            $this->column('new', $this->t('col_new'), Unit::CURRENCY),
            $this->column('reactivation', $this->t('col_reactivation'), Unit::CURRENCY),
            $this->column('expansion', $this->t('col_expansion'), Unit::CURRENCY),
            $this->column('contraction', $this->t('col_contraction'), Unit::CURRENCY),
            $this->column('churn', $this->t('col_churn'), Unit::CURRENCY),
            $this->column('paused', $this->t('col_paused'), Unit::CURRENCY),
            $this->column('net', $this->t('col_net'), Unit::CURRENCY),
            $this->column('mrr_end', $this->t('col_mrr_end'), Unit::CURRENCY),
        ];
    }

    public function rows(MetricQuery $query): array
    {
        return $this->figures()->monthlyMovements($query->period->from, $query->period->toExclusive());
    }
}
