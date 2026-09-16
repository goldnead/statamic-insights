<?php

namespace Goldnead\StatamicInsights\Website;

use Goldnead\StatamicInsights\Support\Countries;

/**
 * Visitors by country, with the code spelled out.
 *
 * The only dimension whose values are not already words: the service answers
 * `DE`, and a table of two-letter codes is a table a reader decodes instead of
 * reading. Same treatment the payments report gives the same question, from
 * the same six lines.
 */
class CountriesReport extends TopMetricReport
{
    public function __construct()
    {
        parent::__construct('countries', 'country', 'country');
    }

    protected function name(string $value): string
    {
        return $value === ''
            ? __('statamic-insights::reports.country_unknown')
            : Countries::name($value);
    }
}
