<?php

namespace Goldnead\StatamicInsights\Contracts;

/**
 * A metric that can say what its filters may be set to.
 *
 * Without this a screen can pass a filter but never offer a choice: it would
 * have to know that money comes in currencies and which ones this installation
 * has ever taken — which is precisely the knowledge that belongs to the addon
 * owning the data.
 *
 * Optional, like {@see HasBreakdowns}. A metric with nothing to choose from
 * simply does not implement it, and the screen shows no switch rather than an
 * empty one.
 */
interface HasFilterOptions
{
    /**
     * The values each filter may take, keyed by filter name.
     *
     *     ['currency' => [['value' => 'EUR', 'label' => 'EUR'], …]]
     *
     * Ordered by whatever the metric considers most useful first — for a
     * currency that is the one with the most orders, not the alphabet. An empty
     * list for a filter means "this installation has no choice to make", which
     * is different from not offering the filter at all.
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    public function filterOptions(): array;
}
