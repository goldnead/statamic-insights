<?php

namespace Goldnead\StatamicInsights\Reports;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * What the running agreements will bring in over the next twelve months, if
 * nobody joins, leaves, pauses or changes price.
 *
 * Money on the calendar, not MRR: a quarterly subscription lands whole in the
 * month it is charged. Instalments of payment plans are their own column, so
 * the subscription line stays comparable with MRR.
 */
class SubscriptionForecast extends SubscriptionReport
{
    public const MONTHS = 12;

    public function handle(): string
    {
        return 'payments.subscription_forecast';
    }

    public function label(): string
    {
        return $this->t('forecast');
    }

    public function description(): ?string
    {
        return $this->t('forecast_description');
    }

    public function columns(): array
    {
        return [
            $this->column('month', $this->t('col_month'), 'month'),
            $this->column('subscriptions_cent', $this->t('col_subscriptions'), Unit::CURRENCY),
            $this->column('plans_cent', $this->t('col_plans'), Unit::CURRENCY),
            $this->column('total_cent', $this->t('col_total'), Unit::CURRENCY),
        ];
    }

    public function rows(MetricQuery $query): array
    {
        $this->fresh();

        return $this->only($this->figures()->forecast(self::MONTHS), $query);
    }
}
