<?php

namespace Goldnead\StatamicInsights\Reports;

use Goldnead\StatamicInsights\Contracts\Report;
use Goldnead\StatamicInsights\Subscriptions\AgreementReader;
use Goldnead\StatamicInsights\Subscriptions\SubscriptionFigures;
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
abstract class SubscriptionReport implements Report
{
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

    protected function figures(): SubscriptionFigures
    {
        return new SubscriptionFigures(app(AgreementReader::class)->all(), Carbon::now());
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
