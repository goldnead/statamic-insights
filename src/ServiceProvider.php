<?php

namespace Goldnead\StatamicInsights;

use Goldnead\StatamicInsights\Integrations\ContactRevenuePanel;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    // Untyped, because the parent declares it untyped and PHP refuses a child
    // that narrows it. The values must byte-match `laravel()` in vite.config.js,
    // or the manifest lands where nothing looks for it.
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    // Registered by hand in register() under the exact namespace, so
    // `__('statamic-insights::report.title')` resolves before bootAddon() runs.
    protected $translations = false;

    // The parent would boot config from the addon manifest, which is empty in a
    // package test suite. Merged by hand below instead.
    protected $config = false;

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic-insights.php', 'statamic-insights');

        $langPath = __DIR__.'/../resources/lang';

        // Two layers, and both are needed: `addNamespace` serves the PHP side
        // (`__('statamic-insights::report.title')`), `addJsonPath` serves the
        // strings the Vue components ask for by their English text.
        $this->app->resolving('translator', function ($translator) use ($langPath) {
            $translator->addNamespace('statamic-insights', $langPath);
            $translator->addJsonPath($langPath);
        });

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('statamic-insights', $langPath);
            $this->app['translator']->addJsonPath($langPath);
        }
    }

    public function bootAddon(): void
    {
        $this->registerNavigation();
        $this->registerPermissions();
        $this->registerContactPanel();

        $this->publishes([
            __DIR__.'/../config/statamic-insights.php' => config_path('statamic-insights.php'),
        ], 'statamic-insights-config');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/statamic-insights'),
        ], 'statamic-insights-translations');
    }

    /**
     * Put what somebody has paid on the CRM's contact screen.
     *
     * Booted from an `app->booted()` callback and not from `bootAddon()`:
     * `bootAddon()` already runs inside one, and a nested `booted()` fires
     * before sibling addons have booted — LeadHub's container bindings only
     * exist after its own provider has. Registered under a fixed key, so a
     * second invocation cannot produce the panel twice.
     */
    protected function registerContactPanel(): void
    {
        $this->app->booted(function (): void {
            if (! ContactRevenuePanel::available()) {
                return;
            }

            $manager = ('\Goldnead\Leadhub\Facades\LeadHub')::getFacadeRoot();

            if (! is_object($manager) || ! method_exists($manager, 'registerContactPanel')) {
                return;
            }

            $manager->registerContactPanel(
                'insights.revenue',
                fn ($contact) => $this->app->make(ContactRevenuePanel::class)($contact),
            );
        });
    }

    protected function registerNavigation(): void
    {
        Nav::extend(function ($nav) {
            $nav->create(__('statamic-insights::nav.insights'))
                ->section('Tools')
                ->icon('chart-monitoring-indicator')
                ->route('insights.revenue')
                ->can('view insights');
        });
    }

    protected function registerPermissions(): void
    {
        Permission::extend(function () {
            Permission::group('statamic-insights', __('statamic-insights::nav.insights'), function () {
                Permission::register('view insights')
                    ->label(__('statamic-insights::permissions.view_insights'));
            });
        });
    }
}
