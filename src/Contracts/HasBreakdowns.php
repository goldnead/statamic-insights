<?php

namespace Goldnead\StatamicInsights\Contracts;

use Goldnead\StatamicInsights\Support\MetricQuery;

/**
 * A metric that can also be split by something.
 *
 * Separate from {@see Metric} on purpose: most numbers are just a number, and a
 * contract that demanded a breakdown from every implementer would be answered
 * with empty arrays — which reads as "no data" rather than "not applicable".
 */
interface HasBreakdowns
{
    /**
     * The splits this metric offers: `['campaign' => 'Kampagne', …]`.
     *
     * Keys are handles and go in URLs; values are labels and are translated.
     *
     * @return array<string, string>
     */
    public function breakdowns(): array;

    /**
     * The metric split by one of them, largest first.
     *
     * `key` may be null — a sale with no campaign is a row, not an omission.
     * `label` is what to print for it, including the words for that null.
     *
     * @return array<int, array{key: string|null, label: string, value: int|float, meta?: array<string, mixed>}>
     */
    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array;
}
