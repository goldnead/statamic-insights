<?php

namespace Goldnead\StatamicInsights\Website;

use Goldnead\StatamicInsights\Contracts\HasDefaultSort;
use Goldnead\StatamicInsights\Contracts\Report;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * The busiest values of one dimension of the website's traffic — pages,
 * referrers, devices, browsers.
 *
 * Registered several times as configured instances, for the same reason
 * {@see WebsiteMetric} is: the question differs by one string, and the table
 * around it does not differ at all.
 *
 * Every row carries visitors, pageviews and a bounce rate, and a dimension
 * that has no bounce rate to give leaves that cell empty rather than filling
 * it with a flattering zero.
 */
class TopMetricReport implements HasDefaultSort, Report
{
    /**
     * @param  string  $name  The part after `website.` in the handle, and
     *                        the lang key for label and description.
     * @param  string  $parameter  The dimension the service knows this by.
     * @param  string  $column  The key and lang suffix of the first column.
     */
    public function __construct(
        protected string $name,
        protected string $parameter,
        protected string $column,
    ) {}

    public function handle(): string
    {
        return 'website.'.$this->name;
    }

    public function label(): string
    {
        return __('statamic-insights::website.'.$this->name);
    }

    public function description(): ?string
    {
        return __('statamic-insights::website.'.$this->name.'_description');
    }

    public function group(): string
    {
        return __('statamic-insights::website.group');
    }

    public function available(): bool
    {
        return Rybbit::configured();
    }

    /**
     * Null, and that is not an oversight.
     *
     * The sentence the screen builds from this one is "this report reads what
     * :package records, and that addon is not installed" — true for a sibling
     * addon and false for a web service. An unconfigured website report is not
     * registered at all (see the service provider), so this is only ever read
     * for a report that is configured and available.
     */
    public function requires(): ?string
    {
        return null;
    }

    public function usesPeriod(): bool
    {
        return true;
    }

    /**
     * Busiest first, because that is what was asked.
     *
     * These are the top twenty by visitors and nothing else; sorted by name
     * they would read as a complete list that happens to have twenty entries,
     * and the rows the ranking cut would be invisible as well as absent.
     */
    public function defaultSort(): array
    {
        return ['column' => 'visitors', 'direction' => 'desc'];
    }

    public function columns(): array
    {
        return [
            ['key' => $this->column, 'label' => __('statamic-insights::website.col_'.$this->column), 'unit' => 'text'],
            ['key' => 'visitors', 'label' => __('statamic-insights::website.col_visitors'), 'unit' => Unit::COUNT],
            ['key' => 'pageviews', 'label' => __('statamic-insights::website.col_pageviews'), 'unit' => Unit::COUNT],
            ['key' => 'bounce_rate', 'label' => __('statamic-insights::website.col_bounce_rate'), 'unit' => Unit::PERCENT],
        ];
    }

    public function rows(MetricQuery $query): array
    {
        return array_map(
            fn (array $row): array => [
                $this->column => $this->name($row['value']),
                'visitors' => $row['visitors'],
                'pageviews' => $row['pageviews'],
                'bounce_rate' => $row['bounce_rate'],
            ],
            app(Rybbit::class)->top($query, $this->parameter, 20),
        );
    }

    /**
     * What to print for a value.
     *
     * An empty one is a real answer and the most common one in the referrer
     * table: somebody who typed the address or followed a bookmark arrived
     * with no referrer at all. Printing an empty cell there would lose the
     * largest row on the screen.
     */
    protected function name(string $value): string
    {
        return $value === '' ? __('statamic-insights::website.direct') : $value;
    }
}
