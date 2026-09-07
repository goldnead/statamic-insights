<?php

namespace Goldnead\StatamicInsights\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * What an operator may change from the Control Panel, and nothing else.
 *
 * Only the field list lives here. Screen, form, validation, store, permission
 * check and the brand dimension all come from `goldnead/statamic-brand-context`
 * — see {@see ProvidesSettings}. This addon owns two values, and both of them
 * are read at request time in a controller, which is the condition for being
 * offered here at all: `SettingsManager::apply()` runs from `app->booted()`, so
 * anything read while the application boots would show a switch on screen that
 * only takes effect on the next deploy.
 *
 * Both keys were checked against that rule rather than taken from a list:
 * `default_period` is read in MetricController::index/show, ReportController
 * and RevenueController, `currency` in RevenueController::currencyFor — five
 * call sites, all inside a controller action.
 *
 * `config/statamic-insights.php` holds exactly these two keys, so nothing of
 * this addon's configuration is left out of the screen and there is nothing to
 * point at the config file for.
 */
class Settings implements ProvidesSettings
{
    /**
     * Stable forever: it is stored in `brand_settings.namespace` on every row,
     * so renaming it orphans every override a site has made.
     */
    public static function settingsNamespace(): string
    {
        return 'insights';
    }

    /**
     * The config root the unset values keep following. Not the same string as
     * the namespace here — the file is `config/statamic-insights.php`, which is
     * what every `config('statamic-insights.…')` call in this addon reads.
     */
    public static function settingsConfigPath(): string
    {
        return 'statamic-insights';
    }

    public static function settingsPermission(): string
    {
        return 'manage insights settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('statamic-insights::settings.groups.defaults.title'),
                'description' => __('statamic-insights::settings.groups.defaults.description'),
                'fields' => [
                    [
                        'key' => 'default_period',
                        // A closed set of six, spelled out in the config file
                        // as well. Offered as free text, the first `letzte 30
                        // Tage` typed in here would fall through
                        // `Period::fromPreset()` on every screen at once.
                        'type' => 'select',
                        'options' => [
                            '7d' => __('statamic-insights::report.period_7d'),
                            '30d' => __('statamic-insights::report.period_30d'),
                            '90d' => __('statamic-insights::report.period_90d'),
                            '12m' => __('statamic-insights::report.period_12m'),
                            'ytd' => __('statamic-insights::report.period_ytd'),
                            'all' => __('statamic-insights::report.period_all'),
                        ],
                        'label' => __('statamic-insights::settings.fields.default_period.label'),
                        'description' => __('statamic-insights::settings.fields.default_period.description'),
                        'nullable' => false,
                    ],
                    [
                        'key' => 'currency',
                        'type' => 'string',
                        'label' => __('statamic-insights::settings.fields.currency.label'),
                        'description' => __('statamic-insights::settings.fields.currency.description'),
                        // Empty is a real answer and a different one from a
                        // set currency: it means "follow the payments addon",
                        // which is what a site with one currency wants.
                        'nullable' => true,
                    ],
                ],
            ],
        ];
    }
}
