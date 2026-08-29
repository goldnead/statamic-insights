<?php

namespace Goldnead\StatamicInsights\Tests\Fakes;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;

/**
 * A metric written the way a sibling addon would write one.
 *
 * It exists to prove the contract is implementable without knowing anything
 * about this addon beyond the two interfaces — no base class to extend, no
 * table to touch, no boot order to learn. If this stand-in ever needs more than
 * the interfaces to work, the contract has grown a hidden requirement.
 */
class CountingMetric implements HasBreakdowns, Metric
{
    public int $valueCalls = 0;

    public function __construct(
        protected string $handle = 'test.counted',
        protected array $perBucket = [],
        protected bool $isAvailable = true,
        protected ?int $fixed = null,
    ) {}

    public function handle(): string
    {
        return $this->handle;
    }

    public function label(): string
    {
        return 'Gezähltes';
    }

    public function description(): ?string
    {
        return 'Etwas, das jemand anders zählt.';
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
        return $this->isAvailable;
    }

    public function value(MetricQuery $query): int|float|null
    {
        $this->valueCalls++;

        if ($this->fixed !== null) {
            return $this->fixed;
        }

        // Sums only the buckets that fall inside the window, so the previous
        // period genuinely differs from this one.
        $summe = 0;

        foreach ($this->perBucket as $tag => $wert) {
            $zeit = Carbon::parse($tag);

            if ($query->period->from === null || ($zeit >= $query->period->from && $zeit <= $query->period->to)) {
                $summe += $wert;
            }
        }

        return $summe;
    }

    public function series(MetricQuery $query): array
    {
        // Deliberately returns only the buckets it has. Filling the gaps is the
        // reader's job, and a metric that filled them itself would be the one
        // place a future implementer forgets.
        return $this->perBucket;
    }

    public function meta(MetricQuery $query): array
    {
        return [];
    }

    public function breakdowns(): array
    {
        return ['farbe' => 'Farbe', 'form' => 'Form'];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        return array_slice([
            ['key' => 'rot', 'label' => 'Rot', 'value' => 30],
            ['key' => null, 'label' => 'Ohne Farbe', 'value' => 12],
            ['key' => 'blau', 'label' => 'Blau', 'value' => 5],
        ], 0, $limit);
    }
}
