<?php

namespace Goldnead\StatamicInsights\Tests\Fakes;

use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * Exactly what the README asks of a contributor, and nothing more.
 *
 * No breakdowns, no filter options — the required interface alone. It exists to
 * hold the promise that implementing the documented minimum is enough: a
 * contributor who reads the contract and stops there must get a working screen,
 * not an empty state over their own numbers.
 */
class MinimalRevenueMetric implements Metric
{
    public function __construct(protected int $betrag = 1000) {}

    public function handle(): string
    {
        return 'payments.revenue_gross';
    }

    public function label(): string
    {
        return 'Einnahmen';
    }

    public function description(): ?string
    {
        return null;
    }

    public function group(): string
    {
        return 'Zahlungen';
    }

    public function unit(): string
    {
        return Unit::CURRENCY;
    }

    public function available(): bool
    {
        return true;
    }

    public function value(MetricQuery $query): int|float|null
    {
        return $this->betrag;
    }

    public function series(MetricQuery $query): array
    {
        return [];
    }

    public function meta(MetricQuery $query): array
    {
        return ['currency' => 'EUR'];
    }
}
