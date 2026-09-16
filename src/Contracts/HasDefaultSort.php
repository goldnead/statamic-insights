<?php

namespace Goldnead\StatamicInsights\Contracts;

/**
 * A report whose own row order is the answer, not a starting point.
 *
 * The listing sorts client-side and, left to itself, sorts by the first
 * column. That is right for a table that *is* a list — revenue by month reads
 * best by month — and wrong for a table that was already narrowed by a
 * ranking: the twenty busiest pages, shown alphabetically, look like the whole
 * site with twenty entries. The rows a reader cannot see are the ones the
 * ranking cut, and nothing on the screen says a ranking happened.
 *
 * Separate from {@see Report} rather than a method on it, for the same reason
 * {@see HasBreakdowns} is separate from a metric: most tables have no opinion,
 * and a contract that demanded one would be answered with a guess. It is also
 * the compatible way to add this — {@see Report} is implemented outside this
 * package and semver-locked.
 */
interface HasDefaultSort
{
    /**
     * The column to sort by and the direction, as the listing understands them.
     *
     * The column has to be one of {@see Report::columns()}; a key that is not
     * there is ignored by the screen and the listing falls back to its own
     * choice.
     *
     * @return array{column: string, direction: 'asc'|'desc'}
     */
    public function defaultSort(): array;
}
