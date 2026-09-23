<?php

namespace Goldnead\StatamicInsights\Tests;

use Goldnead\StatamicInsights\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');

        // The same suite against a real MySQL or Postgres, for the figures that
        // must not depend on the driver: INSIGHTS_TEST_DB_URL=mysql://root:root@127.0.0.1:3306/i1
        // Unset, SQLite in memory as always.
        if ($url = getenv('INSIGHTS_TEST_DB_URL')) {
            $driver = str_starts_with($url, 'pgsql') || str_starts_with($url, 'postgres') ? 'pgsql' : 'mysql';
            $app['config']->set('database.connections.testing', ['driver' => $driver, 'url' => $url, 'prefix' => '']);
        }
        $app['config']->set('statamic.system.multisite', false);

        // Without Pro, Statamic refuses a second user — and a permission test
        // needs two: one who may see the screen and one who may not.
        $app['config']->set('statamic.editions.pro', true);
    }
}
