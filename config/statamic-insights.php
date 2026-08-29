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

];
