<?php

namespace Goldnead\StatamicInsights\Tests\Fakes;

use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * A metric from an addon that is mid-upgrade.
 *
 * The case that matters: a screen shows many numbers, and one broken
 * contributor must cost its own tile and not the page somebody opened.
 */
class BrokenMetric implements Metric
{
    public function __construct(protected string $where = 'value') {}

    public function handle(): string
    {
        return 'test.broken';
    }

    public function label(): string
    {
        return 'Kaputt';
    }

    public function description(): ?string
    {
        return null;
    }

    public function group(): string
    {
        return 'Attrappe';
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    public function available(): bool
    {
        if ($this->where === 'available') {
            throw new \RuntimeException('kaputt beim Verfuegbarkeitscheck');
        }

        return true;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if ($this->where === 'value') {
            throw new \RuntimeException('kaputt beim Wert');
        }

        return 1;
    }

    public function series(MetricQuery $query): array
    {
        if ($this->where === 'series') {
            throw new \RuntimeException('kaputt bei der Reihe');
        }

        return [];
    }

    public function meta(MetricQuery $query): array
    {
        return [];
    }
}
