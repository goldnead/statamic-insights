<?php

namespace Goldnead\StatamicInsights;

use Goldnead\StatamicInsights\Contracts\Report;
use Goldnead\StatamicInsights\Integrations\ContactRevenuePanel;
use Goldnead\StatamicInsights\Reports\AccessByProduct;
use Goldnead\StatamicInsights\Reports\CartAbandonment;
use Goldnead\StatamicInsights\Reports\PaymentsByCountry;
use Goldnead\StatamicInsights\Reports\RevenueByMonth;
use Goldnead\StatamicInsights\Reports\RevenueByProduct;
use Goldnead\StatamicInsights\Reports\UpsellPerformance;
use Goldnead\StatamicInsights\Support\MetricRegistry;
use Goldnead\StatamicInsights\Support\Neighbours;
use Goldnead\StatamicInsights\Support\ReportRegistry;
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

        // Singletons, and both have to be: a sibling addon registers into the
        // registry while booting, and a registry rebuilt per resolution is a
        // different object from the one the screen later reads — every
        // contributed metric silently dropped.
        $this->app->singleton(MetricRegistry::class);
        $this->app->singleton(ReportRegistry::class);
        $this->app->singleton(InsightsManager::class);

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
        $this->registerOwnMetrics();
        $this->registerOwnReports();

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

    /**
     * This addon measures nothing of its own.
     *
     * The method exists as the seam and stays empty on purpose: every number
     * comes from the addon that owns the data, and the day Insights starts
     * counting something itself is the day it needs another addon's table
     * again. If that day comes, it happens here and visibly.
     */
    protected function registerOwnMetrics(): void
    {
        //
    }

    /**
     * The six tabular reports this addon ships itself.
     *
     * This is the day the seam above warned about, and it happens here and
     * visibly: these reports read `payments`, `payment_items`, `offers` and
     * `entitlements` directly. Taken on purpose — the questions they answer
     * span several siblings' tables at once, and no single one of them is the
     * natural owner of "upsell revenue" or "buyers per country". Every read is
     * behind {@see Neighbours}: class
     * existence and table existence, or the report says what it would need.
     */
    protected function registerOwnReports(): void
    {
        $registry = $this->app->make(ReportRegistry::class);

        foreach (self::OWN_REPORTS as $class => $handle) {
            $registry->register($class, $handle);
        }
    }

    /** @var array<class-string<Report>, string> */
    public const OWN_REPORTS = [
        RevenueByMonth::class => 'payments.revenue_by_month',
        RevenueByProduct::class => 'payments.revenue_by_product',
        PaymentsByCountry::class => 'payments.by_country',
        CartAbandonment::class => 'payments.abandonment',
        UpsellPerformance::class => 'offers.upsells',
        AccessByProduct::class => 'entitlements.access_by_product',
    ];

    protected function registerNavigation(): void
    {
        Nav::extend(function ($nav) {
            $nav->create(__('statamic-insights::nav.insights'))
                ->section('Tools')
                ->icon('chart-monitoring-indicator')
                ->route('insights.revenue')
                ->can('view insights')
                ->children([
                    // The curated screen stays first: it is the one somebody
                    // opens with a question in mind. The generic list is where
                    // you go when you do not know what you are looking for.
                    $nav->item(__('statamic-insights::nav.revenue'))->route('insights.revenue'),
                    $nav->item(__('statamic-insights::nav.metrics'))->route('insights.metrics'),
                    $nav->item(__('statamic-insights::nav.reports'))->route('insights.reports'),
                ]);
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
