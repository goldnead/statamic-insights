<?php

namespace Goldnead\StatamicInsights\Reports;

use Goldnead\StatamicInsights\Contracts\HasDefaultSort;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Of the subscriptions begun in a month, how many are still there one, two,
 * three, six and twelve months later.
 *
 * A snapshot: every cohort there has ever been, measured as of now. A point
 * that lies in the future is a dash, not a zero.
 */
class SubscriptionCohorts extends SubscriptionReport implements HasDefaultSort
{
    public function handle(): string
    {
        return 'payments.subscription_cohorts';
    }

    public function label(): string
    {
        return $this->t('cohorts');
    }

    public function description(): ?string
    {
        return $this->t('cohorts_description');
    }

    public function defaultSort(): array
    {
        return ['column' => 'cohort', 'direction' => 'desc'];
    }

    public function columns(): array
    {
        $spalten = [
            $this->column('cohort', $this->t('col_cohort'), 'month'),
            $this->column('currency', $this->t('col_currency'), 'text'),
            $this->column('started', $this->t('col_started'), Unit::COUNT),
        ];

        foreach ([1, 2, 3, 6, 12] as $n) {
            $spalten[] = $this->column('m'.$n, trans_choice('statamic-insights::subscriptions.col_after_months', $n, ['count' => $n]), Unit::PERCENT);
        }

        $spalten[] = $this->column('live', $this->t('col_live'), Unit::COUNT);

        return $spalten;
    }

    public function rows(MetricQuery $query): array
    {
        return $this->figures()->cohorts();
    }
}
