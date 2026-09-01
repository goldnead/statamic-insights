<?php

namespace Goldnead\StatamicInsights\Support\Concerns;

use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\StatamicInsights\Support\TableReport;
use Illuminate\Database\Query\Builder;

/**
 * Narrowing a query-builder read to the current brand.
 *
 * A transcription of {@see TableMetric::brandColumn()} and
 * {@see TableMetric::brandScoped()}, for {@see TableReport}. Not extracted from
 * `TableMetric` itself, though that would be the cleaner shape: four sibling
 * addons pin that file's source in their own suites
 * (`InsightsContractsMatchTest`), so a change to it is a coordinated release
 * across the family, not a refactor. Until that release, the two must be kept
 * in step by hand — a change to one is a change to both.
 */
trait NarrowsToBrand
{
    /** The table the brand column lives on. */
    abstract protected function table(): string;

    /**
     * The column that says which brand a row belongs to, or null for none.
     *
     * Declaring it is the whole opt-in: every figure, chart, split and row is
     * then narrowed to the current brand at once, and no individual metric can
     * forget to. A table without brands, or a metric that answers a question
     * deliberately spanning all of them, returns null — and then says so in its
     * description, because a screen where one tile counts one brand and its
     * neighbour counts four, with nothing on either saying which, is worse than
     * a screen that knows no brands at all.
     */
    protected function brandColumn(): ?string
    {
        return null;
    }

    /**
     * Narrow a query to the current brand.
     *
     * This is `Goldnead\BrandContext\Scopes\BrandScope::apply()` transcribed
     * for the query builder, and it must stay a transcription: the metrics read
     * tables through `DB::table()`, so Eloquent's global scope never fires, and
     * a figure that filtered by its own rules would disagree with every other
     * reading of the same install. The order matters and is theirs — bypass
     * first, then single-brand, then the unresolved case, then the filter.
     *
     * An unresolved brand fails closed to no rows, not to an absent metric:
     * `available()` answers whether the thing exists, and a brand that has not
     * been picked yet is not the metric ceasing to exist. A tile reading zero
     * can be understood; a tile that vanished cannot.
     */
    protected function brandScoped(Builder $rows, ?string $column = null): Builder
    {
        $column ??= $this->brandColumn();

        if ($column === null || ! app()->bound('brand-context')) {
            return $rows;
        }

        $manager = app('brand-context');

        if ($manager->scopeIsDisabled() || ! $manager->multiBrandEnabled()) {
            return $rows;
        }

        if (! $manager->hasCurrent()) {
            return $manager->failMode() === 'open'
                ? $rows
                : $rows->whereRaw('1 = 0');
        }

        return $rows->where($this->table().'.'.$column, $manager->currentId());
    }
}
