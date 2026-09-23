<?php

namespace Goldnead\StatamicInsights\Reports;

use Goldnead\StatamicInsights\Contracts\HasFilterOptions;
use Goldnead\StatamicInsights\Contracts\Report;
use Goldnead\StatamicInsights\Subscriptions\AgreementReader;
use Goldnead\StatamicInsights\Subscriptions\SubscriptionFigures;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Neighbours;
use Goldnead\StatamicInsights\Support\TableReport;
use Illuminate\Support\Carbon;

/**
 * What the four subscription tables share: where the agreements come from and
 * the group they sit under.
 *
 * Not a {@see TableReport}: those window a
 * table by a timestamp column in SQL, and these deliberately do not — every
 * date here is compared in PHP, see {@see AgreementReader}.
 */
abstract class SubscriptionReport implements HasFilterOptions, Report
{
    private ?SubscriptionFigures $figures = null;

    /**
     * The currencies agreements run in, busiest first. One is shown at a
     * time: the report is filtered, not given a currency column, so a table
     * never lists euros and francs as if they were one series.
     */
    public function filterOptions(): array
    {
        return ['currency' => array_map(
            fn (string $c) => ['value' => $c, 'label' => $c],
            $this->figures()->currencies(),
        )];
    }

    /** The currency asked for if agreements run in it, otherwise the busiest. */
    protected function currency(MetricQuery $query): ?string
    {
        $alle = $this->figures()->currencies();
        $gewuenscht = strtoupper((string) $query->filter('currency', ''));

        return in_array($gewuenscht, $alle, true) ? $gewuenscht : ($alle[0] ?? null);
    }

    /**
     * Rows of one currency.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function only(array $rows, MetricQuery $query): array
    {
        $waehrung = $this->currency($query);

        return array_values(array_filter($rows, fn (array $r) => ($r['currency'] ?? null) === $waehrung));
    }

    public function group(): string
    {
        return __('statamic-insights::subscriptions.group');
    }

    public function available(): bool
    {
        return app(AgreementReader::class)->available();
    }

    public function requires(): ?string
    {
        return Neighbours::package(Neighbours::PAYMENTS);
    }

    public function usesPeriod(): bool
    {
        return false;
    }

    /**
     * Forget the last read. Called at the start of every `rows()`: a report
     * object lives as long as the registry does, and in a long-running
     * process that is longer than one request.
     */
    protected function fresh(): void
    {
        $this->figures = null;
    }

    /** Read once per question: the options and the rows come from the same read. */
    protected function figures(): SubscriptionFigures
    {
        return $this->figures ??= new SubscriptionFigures(app(AgreementReader::class)->all(), Carbon::now());
    }

    /** @return array{key: string, label: string, unit: string} */
    protected function column(string $key, string $label, string $unit): array
    {
        return ['key' => $key, 'label' => $label, 'unit' => $unit];
    }

    protected function t(string $key): string
    {
        return __('statamic-insights::subscriptions.'.$key);
    }
}
