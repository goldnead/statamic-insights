<?php

namespace Goldnead\StatamicInsights\Facades;

use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\InsightsManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void registerMetric(string|Metric|\Closure $metric, ?string $handle = null)
 * @method static array<int, string> metricHandles()
 * @method static Metric|null metric(string $handle)
 * @method static array<string, Metric> metrics()
 * @method static void registerReport(string|\Goldnead\StatamicInsights\Contracts\Report $report, ?string $handle = null)
 * @method static array<int, string> reportHandles()
 * @method static \Goldnead\StatamicInsights\Contracts\Report|null report(string $handle)
 * @method static array<string, \Goldnead\StatamicInsights\Contracts\Report> reports()
 *
 * @see InsightsManager
 */
class Insights extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InsightsManager::class;
    }
}
