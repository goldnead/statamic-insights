<?php

namespace Goldnead\StatamicInsights\Reports;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Every charge the running agreements will make in the next thirty days.
 *
 * At the price on the agreement today, subscriptions and payment plans alike,
 * one row per charge: a weekly subscription appears four or five times.
 * Paused, suspended and ended agreements charge nothing and are not here.
 */
class UpcomingCharges extends SubscriptionReport
{
    public const DAYS = 30;

    public function handle(): string
    {
        return 'payments.upcoming_charges';
    }

    public function label(): string
    {
        return $this->t('upcoming');
    }

    public function description(): ?string
    {
        return $this->t('upcoming_description');
    }

    public function columns(): array
    {
        return [
            $this->column('at', $this->t('col_due'), 'date'),
            $this->column('product', $this->t('col_product'), 'code'),
            $this->column('customer', $this->t('col_customer'), 'text'),
            $this->column('kind_label', $this->t('col_kind'), 'text'),
            $this->column('amount_cent', $this->t('col_amount'), Unit::CURRENCY),
        ];
    }

    public function rows(MetricQuery $query): array
    {
        $this->fresh();

        return array_map(fn (array $c) => $c + [
            'kind_label' => $this->t($c['kind'] === 'plan' ? 'kind_plan' : 'kind_subscription'),
        ], $this->only($this->figures()->upcoming(self::DAYS), $query));
    }
}
