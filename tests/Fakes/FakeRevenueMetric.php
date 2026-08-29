<?php

namespace Goldnead\StatamicInsights\Tests\Fakes;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Contracts\HasFilterOptions;
use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;

/**
 * A stand-in for what `statamic-payments` registers.
 *
 * The curated screen is assembled from metrics by handle, so testing it means
 * registering those handles — not installing a payment provider. That is the
 * point of the contract: this addon's screens can be tested against anything
 * that implements it, and the real implementation is tested where it lives.
 *
 * **It answers whatever it is asked**, and that is a deliberate limit worth
 * writing down: it does not filter by currency, so these tests prove how the
 * screen arranges numbers and never that the filter reaches the query. That
 * half is tested in `statamic-payments`, against its real tables, where it can
 * actually be wrong.
 */
class FakeRevenueMetric implements HasBreakdowns, HasFilterOptions, Metric
{
    /**
     * @param  array<string, int>  $perBucket
     * @param  array<int, string>  $currencies
     */
    public function __construct(
        protected string $handle,
        protected string $label,
        protected string $unit = Unit::CURRENCY,
        protected int|float|null $fixed = null,
        protected array $perBucket = [],
        protected array $currencies = ['EUR'],
        protected array $meta = [],
        protected bool $isAvailable = true,
    ) {}

    public function handle(): string
    {
        return $this->handle;
    }

    public function label(): string
    {
        return $this->label;
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
        return $this->unit;
    }

    public function available(): bool
    {
        return $this->isAvailable;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if ($this->fixed !== null) {
            return $this->fixed;
        }

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
        return $this->perBucket;
    }

    public function meta(MetricQuery $query): array
    {
        return array_merge(
            $this->unit === Unit::CURRENCY ? ['currency' => $query->filter('currency', 'EUR')] : [],
            $this->meta,
        );
    }

    public function breakdowns(): array
    {
        return ['campaign' => 'Kampagne', 'product' => 'Produkt'];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if ($dimension === 'campaign') {
            return array_slice([
                ['key' => 'sommer', 'label' => 'sommer · newsletter', 'value' => 3000, 'meta' => ['orders' => 2]],
                // A sale with no campaign is a row, not an omission.
                ['key' => null, 'label' => 'Ohne Kampagne', 'value' => 1000, 'meta' => ['orders' => 1]],
            ], 0, $limit);
        }

        return array_slice([
            ['key' => 'noten', 'label' => 'Notenpaket', 'value' => 2500, 'meta' => ['quantity' => 3]],
            ['key' => 'cd', 'label' => 'Begleit-CD', 'value' => 1500, 'meta' => ['quantity' => 1]],
        ], 0, $limit);
    }

    public function filterOptions(): array
    {
        return ['currency' => array_map(fn ($c) => ['value' => $c, 'label' => $c], $this->currencies)];
    }
}
