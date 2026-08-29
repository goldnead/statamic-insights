<?php

namespace Goldnead\StatamicInsights\Tests\Fakes;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * The smallest thing a contributor can write on top of {@see TableMetric}.
 *
 * It is here as a test subject, but it doubles as the honest measure of the
 * base class: if a real addon needs much more than this to count rows in a
 * table, the base is not carrying its weight.
 */
class WidgetMetric extends TableMetric implements HasBreakdowns
{
    public function __construct(protected string $aggregate = 'count(*)') {}

    protected function table(): string
    {
        return 'widgets';
    }

    protected function timestamp(): string
    {
        return 'happened_at';
    }

    public function handle(): string
    {
        return 'test.widgets';
    }

    public function label(): string
    {
        return 'Widgets';
    }

    public function group(): string
    {
        return 'Attrappe';
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        return $this->inPeriod($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        return $this->bucketed($this->inPeriod($query), $query, $this->aggregate);
    }

    public function breakdowns(): array
    {
        return ['kind' => 'Typ'];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        return $this->labelled(
            $this->splitByColumn($this->inPeriod($query), $query, $dimension, $this->aggregate, $limit),
            $dimension,
        );
    }

    protected function missingLabel(string $dimension): string
    {
        return 'Kein Typ';
    }
}
