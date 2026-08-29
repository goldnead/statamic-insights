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
