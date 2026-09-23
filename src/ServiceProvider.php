<?php

namespace Goldnead\StatamicInsights;

use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\StatamicInsights\Contracts\Report;
use Goldnead\StatamicInsights\Integrations\ContactRevenuePanel;
use Goldnead\StatamicInsights\Reports\AccessByProduct;
use Goldnead\StatamicInsights\Reports\CartAbandonment;
use Goldnead\StatamicInsights\Reports\MrrMovements;
use Goldnead\StatamicInsights\Reports\PaymentsByCountry;
use Goldnead\StatamicInsights\Reports\RevenueByMonth;
use Goldnead\StatamicInsights\Reports\RevenueByProduct;
use Goldnead\StatamicInsights\Reports\SubscriptionCohorts;
use Goldnead\StatamicInsights\Reports\SubscriptionForecast;
use Goldnead\StatamicInsights\Reports\UpcomingCharges;
use Goldnead\StatamicInsights\Reports\UpsellPerformance;
use Goldnead\StatamicInsights\Support\MetricRegistry;
use Goldnead\StatamicInsights\Support\Neighbours;
use Goldnead\StatamicInsights\Support\ReportRegistry;
use Goldnead\StatamicInsights\Support\Settings;
use Goldnead\StatamicInsights\Support\Unit;
use Goldnead\StatamicInsights\Website\CountriesReport;
use Goldnead\StatamicInsights\Website\Rybbit;
use Goldnead\StatamicInsights\Website\TopMetricReport;
use Goldnead\StatamicInsights\Website\WebsiteMetric;
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

        // A singleton so that six website metrics and five website tables on
        // one screen share the memo inside it. Resolved per metric rather than
        // injected, because the metrics are constructed while booting.
        $this->app->singleton(Rybbit::class);

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

    /**
     * Announce this addon's settings to the suite's shared screen.
     *
     * In `boot()`, not in `bootAddon()`, and that is not a style choice.
     * brand-context applies the stored overrides from an `app->booted()`
     * callback so that every provider has had its turn first. `bootAddon()`
     * itself runs from an `app->booted()` callback of Statamic's, and which of
     * the two fires first depends on package load order — registering there
     * would mean these settings reach the live config on some installs and not
     * on others, with nothing on screen to say which.
     */
    public function boot(): void
    {
        parent::boot();

        $this->app->make(SettingsRegistry::class)->register(Settings::class);
    }

    public function bootAddon(): void
    {
        $this->registerNavigation();
        $this->registerPermissions();
        $this->registerContactPanel();
        $this->registerOwnMetrics();
        $this->registerOwnReports();
        $this->registerWebsiteReports();

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
     * The website's own traffic — the one thing no sibling addon owns.
     *
     * The seam used to be empty, with a note that the day Insights measured
     * something itself would be the day it needed another addon's table again.
     * That is not what happened. These six figures come from outside the
     * installation altogether: an analytics service that knows what the site's
     * readers did, which no addon in the family records and none ever will.
     * A site's traffic belongs beside its revenue rather than in a second tab,
     * so it is here.
     *
     * Registered only when the service is configured. An unconfigured install
     * shows no Website heading at all, rather than a heading over nothing —
     * the same judgement `available()` makes for a metric with no table, made
     * one level earlier because the reason is a setting rather than a schema.
     */
    protected function registerOwnMetrics(): void
    {
        if (! Rybbit::configured()) {
            return;
        }

        $registry = $this->app->make(MetricRegistry::class);

        foreach (self::WEBSITE_METRICS as $name => [$figure, $unit]) {
            $registry->register(new WebsiteMetric($name, $figure, $unit));
        }
    }

    /**
     * The figures the website group offers: handle suffix => [service key, unit].
     *
     * `visitors` and the service's `users` are the same number under two
     * names; the handle follows what a reader calls it and the key follows
     * what the service calls it.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const WEBSITE_METRICS = [
        'visitors' => ['users', Unit::COUNT],
        'sessions' => ['sessions', Unit::COUNT],
        'pageviews' => ['pageviews', Unit::COUNT],
        'bounce_rate' => ['bounce_rate', Unit::PERCENT],
        'pages_per_session' => ['pages_per_session', Unit::COUNT],
        'session_duration' => ['session_duration', Unit::DURATION],
    ];

    /**
     * The five traffic tables, on the same condition as the metrics above.
     *
     * Handle suffix => the dimension the analytics service knows it by, and
     * the key of the first column.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const WEBSITE_REPORTS = [
        'top_pages' => ['pathname', 'path'],
        'referrers' => ['referrer', 'referrer'],
        'devices' => ['device_type', 'device'],
        'browsers' => ['browser', 'browser'],
    ];

    protected function registerWebsiteReports(): void
    {
        if (! Rybbit::configured()) {
            return;
        }

        $registry = $this->app->make(ReportRegistry::class);

        foreach (self::WEBSITE_REPORTS as $name => [$parameter, $column]) {
            $registry->register(new TopMetricReport($name, $parameter, $column));
        }

        // Its own class only because the values are codes and a table of
        // codes is a table nobody reads.
        $registry->register(new CountriesReport);
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
        // Subscriptions, read from `subscriptions` of statamic-payments. Their
        // own group, because four tables about one question read best together.
        MrrMovements::class => 'payments.mrr_movements',
        SubscriptionCohorts::class => 'payments.subscription_cohorts',
        UpcomingCharges::class => 'payments.upcoming_charges',
        SubscriptionForecast::class => 'payments.subscription_forecast',
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
                    $nav->item(__('statamic-insights::nav.subscriptions'))->route('insights.subscriptions'),
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

                // The permission brand-context checks before it shows this
                // addon's section on the shared settings screen. Registered
                // here because the addon owns its permissions; the shared
                // layer only asks which one to check.
                Permission::register('manage insights settings')
                    ->label(__('statamic-insights::permissions.manage_insights_settings'));
            });
        });
    }
}
