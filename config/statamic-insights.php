<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Which currency the revenue screen opens on. Adding two currencies together
    | produces a number with no meaning, so the report shows one at a time and
    | names the ones it left out. Null follows the payments addon's own default.
    |
    */

    'currency' => env('STATAMIC_INSIGHTS_CURRENCY'),

    /*
    |--------------------------------------------------------------------------
    | Default period
    |--------------------------------------------------------------------------
    |
    | One of: 7d, 30d, 90d, 12m, ytd, all.
    |
    */

    'default_period' => env('STATAMIC_INSIGHTS_PERIOD', '30d'),

    /*
    |--------------------------------------------------------------------------
    | Website statistics
    |--------------------------------------------------------------------------
    |
    | Where the traffic figures come from. Unset, the website group is not
    | registered at all and nothing about it appears on any screen — an empty
    | "Website" heading would promise a source this installation does not have.
    |
    | `url` and `key` address the analytics service; `site_id` says which of the
    | sites on it this installation is. The key is a credential and belongs in
    | the environment, which is why it is not offered on the settings screen —
    | the site id is, because that is the one value an operator changes.
    |
    | Self-hosted Rybbit is what the shipped client speaks to.
    |
    | `timezone` decides which calendar day an evening visit falls on; unset it
    | follows `app.timezone`. `cache` is how many seconds an answer is kept, so
    | that six figures and five tables on one screen do not become fifty
    | requests. `verify` switches TLS verification off for an instance behind a
    | certificate that is trusted only between a CDN and its origin — a
    | deliberate exception, never a default.
    |
    */

    'website' => [
        'url' => env('STATAMIC_INSIGHTS_WEBSITE_URL'),
        'key' => env('STATAMIC_INSIGHTS_WEBSITE_KEY'),
        'site_id' => env('STATAMIC_INSIGHTS_WEBSITE_SITE'),
        'timezone' => env('STATAMIC_INSIGHTS_WEBSITE_TIMEZONE'),
        'cache' => (int) env('STATAMIC_INSIGHTS_WEBSITE_CACHE', 300),
        'timeout' => (int) env('STATAMIC_INSIGHTS_WEBSITE_TIMEOUT', 8),
        'verify' => (bool) env('STATAMIC_INSIGHTS_WEBSITE_VERIFY', true),
    ],

];
