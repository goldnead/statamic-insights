<?php

namespace Goldnead\StatamicInsights\Contracts;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Neighbours;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * A table of rows, by whoever knows how to build it.
 *
 * The sibling of {@see Metric}, for questions whose answer is not one number
 * but a list: revenue per month with the order count and the average beside
 * it, payments per country, access per product. A metric with a breakdown
 * comes close and stops one column short — a split carries one value per row,
 * and "how many payments and what was the average" is three.
 *
 * Like a metric, a report is registered and then rendered by every screen this
 * addon has, with the period, the empty states and the formatting done for it:
 *
 *     Insights::registerReport(RevenueByMonth::class, 'payments.revenue_by_month');
 *
 * **Where the rows come from is the implementer's business.** The six reports
 * this addon ships itself read the tables of sibling addons directly, guarded
 * by {@see Neighbours}, and say so in their
 * class docblocks. That is a deliberate exception to the rule that Insights
 * owns no data, taken because the questions span several addons' tables at
 * once and no single sibling is the natural owner of "upsell revenue".
 */
interface Report
{
    /**
     * A stable identifier, namespaced by the contributing addon:
     * `payments.revenue_by_month`. Semver-locked; it ends up in URLs.
     */
    public function handle(): string;

    /** What a person calls it. Translated by the implementer. */
    public function label(): string;

    /**
     * One or two sentences on what the rows are and what they are not — which
     * status counted, which timestamp placed a row in a month, whether refunds
     * were subtracted. Shown above the table. A report without one is a table
     * of numbers whose meaning the reader has to guess.
     */
    public function description(): ?string;

    /** Which heading it sits under — normally the addon whose data it reads. */
    public function group(): string;

    /**
     * Can this report answer right now?
     *
     * False when the tables it reads do not exist or the sibling that owns them
     * is not installed. Unlike a metric, an unavailable report is **not left
     * out**: it stays on the list with the sentence from {@see requires()}, so
     * a reader learns what would have to be installed rather than wondering
     * whether the report exists at all.
     */
    public function available(): bool;

    /**
     * The package this report reads, for the sentence shown when it is absent:
     * `goldnead/statamic-payments`. Null for a report with no such dependency.
     */
    public function requires(): ?string;

    /**
     * Whether the period picker applies.
     *
     * False for a snapshot — "who has access right now" has no period, and a
     * picker beside it would promise a filter that changes nothing.
     */
    public function usesPeriod(): bool;

    /**
     * The columns, in order.
     *
     * `unit` is one of {@see Unit} plus
     * `text` and `date`, and decides only how a cell is printed. A currency
     * column reads the row's own `currency` key when it has one, so a report
     * spanning two currencies puts them in separate rows rather than adding
     * them up.
     *
     * @return array<int, array{key: string, label: string, unit: string}>
     */
    public function columns(): array;

    /**
     * The rows, keyed by column key, in the order they should be printed.
     *
     * Empty is an answer — "nothing sold in this period" — and the screen says
     * so. Null in a cell is "does not apply" (a rate over nothing) and prints
     * as a dash, never as zero.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(MetricQuery $query): array;
}
