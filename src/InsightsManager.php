<?php

namespace Goldnead\StatamicInsights;

use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Support\MetricRegistry;

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
    public function __construct(protected MetricRegistry $registry) {}

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
}
