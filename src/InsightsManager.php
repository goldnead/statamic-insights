<?php

namespace Goldnead\StatamicInsights;

use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Contracts\Report;
use Goldnead\StatamicInsights\Support\MetricRegistry;
use Goldnead\StatamicInsights\Support\ReportRegistry;

/**
 * The public surface another addon touches.
 *
 * Deliberately four methods. Everything else this addon does — periods,
 * comparisons, charts, screens — is its own business, and a contributor that
 * had to know about any of it would be coupled to a release cycle it does not
 * control.
 */
class InsightsManager
{
    public function __construct(
        protected MetricRegistry $registry,
        protected ReportRegistry $reports,
    ) {}

    /**
     * Announce something countable.
     *
     * Call it from a service provider's `booted()` callback, not from `boot()`:
     * this addon's own bindings only exist once its provider has booted, and a
     * sibling that registers too early registers into nothing.
     *
     * **Pass the handle whenever you pass a closure**, and preferably whenever
     * you pass a class name too. A closure cannot be probed for its handle
     * without calling it, and calling it is exactly the laziness this is for —
     * so a closure without one is refused, with a line in the log.
     */
    public function registerMetric(string|Metric|\Closure $metric, ?string $handle = null): void
    {
        $this->registry->register($metric, $handle);
    }

    /** @return array<int, string> */
    public function metricHandles(): array
    {
        return $this->registry->handles();
    }

    public function metric(string $handle): ?Metric
    {
        return $this->registry->find($handle);
    }

    /** @return array<string, Metric> */
    public function metrics(): array
    {
        return $this->registry->available();
    }

    /**
     * Announce a table of rows — see {@see Report} for how it differs from a
     * metric. Same timing rule as {@see registerMetric()}: from `booted()`.
     */
    public function registerReport(string|Report $report, ?string $handle = null): void
    {
        $this->reports->register($report, $handle);
    }

    /** @return array<int, string> */
    public function reportHandles(): array
    {
        return $this->reports->handles();
    }

    public function report(string $handle): ?Report
    {
        return $this->reports->find($handle);
    }

    /** @return array<string, Report> */
    public function reports(): array
    {
        return $this->reports->all();
    }
}
