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
        $app['config']->set('statamic.system.multisite', false);

        // Without Pro, Statamic refuses a second user — and a permission test
        // needs two: one who may see the screen and one who may not.
        $app['config']->set('statamic.editions.pro', true);
    }
}
