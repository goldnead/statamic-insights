<?php

namespace Goldnead\StatamicInsights\Contracts;

use Goldnead\StatamicInsights\Support\MetricQuery;

/**
 * Something that can be counted, by whoever knows how to count it.
 *
 * This is the whole of the coupling between this addon and every other one.
 * Insights owns the time range, the comparison, the chart and the screen; the
 * addon owns the question and the query. Neither names a table belonging to the
 * other, which is the rule the ecosystem plan sets out and the reason this
 * contract exists at all:
 *
 * > "Ein Analytics-Addon darf nicht die operativen Tabellen der anderen Addons
 * > direkt als einzige Quelle lesen. Sonst entsteht erneut enge Kopplung."
 *
 * An implementer registers itself and is then rendered by every screen this
 * addon has, present and future, with no further work:
 *
 *     Insights::registerMetric(RevenueMetric::class);
 *
 * **Where the numbers come from is the implementer's business.** Reading the
 * activity ledger is the default and the cheapest; reading an operational table
 * directly is a decision an addon may take for its own data, and money is the
 * case where it should — an event stream can drop or double a row, and a
 * revenue figure has to agree with a bank statement rather than with a log.
 */
interface Metric
{
    /**
     * A stable identifier, namespaced by the contributing addon:
     * `payments.revenue_net`, `marketing.open_rate`.
     *
     * Semver-locked from the moment it is registered. It ends up in saved
     * dashboards and in URLs.
     */
    public function handle(): string;

    /** What a person calls it. Translated by the implementer. */
    public function label(): string;

    /** One sentence, shown where there is room for it. */
    public function description(): ?string;

    /**
     * Which heading it sits under — normally the addon's own name.
     *
     * Grouping is presentation, so this is a label and not a handle: an addon
     * that wants two headings just returns two different strings.
     */
    public function group(): string;

    /** One of {@see Unit}. Decides how the number is formatted, not what it means. */
    public function unit(): string;

    /**
     * Can this metric answer right now?
     *
     * False when the tables it reads do not exist, when a feature is switched
     * off, or when a sibling it needs is not installed. A metric that cannot
     * answer is left out of every screen rather than showing a zero — "nothing
     * to measure" and "measured nothing" are different statements, and a zero
     * for the first is the quiet kind of wrong.
     */
    public function available(): bool;

    /**
     * One number for the window, or null when the question does not apply.
     *
     * Null is not zero. A refund rate in a period that took nothing in has no
     * answer, and printing 0 % beside a refund amount is a statement its own
     * neighbour contradicts.
     */
    public function value(MetricQuery $query): int|float|null;

    /**
     * The same number per bucket, keyed by bucket.
     *
     * Keys are `Y-m-d` or `Y-m` depending on `$query->bucket`. A metric returns
     * only the buckets it has; Insights fills the rest with zero, so nobody has
     * to remember that a chart built from the buckets with data draws a bad
     * month as a good one.
     *
     * **A bucket may be `null`, and it is not the same as leaving it out.**
     * Omitted means "nothing happened here" and becomes a zero. `null` means
     * "the question does not apply here" — a rate on a day with no denominator
     * — and stays null all the way to the screen, which draws no bar rather
     * than a bar of nothing. Same distinction as `value()`, for the same
     * reason.
     *
     * @return array<string, int|float|null>
     */
    public function series(MetricQuery $query): array;

    /**
     * Anything the formatter needs that the unit does not carry.
     *
     * Open on purpose: the alternative is a contract that grows a method every
     * time somebody measures something new. A key nobody reads is harmless.
     *
     * **Reserved keys — Insights itself reads these, so they are part of the
     * contract and not free-form:**
     *
     * | Key | Type | Meaning |
     * |---|---|---|
     * | `currency` | string | ISO 4217, required for {@see Unit::CURRENCY} |
     * | `line_item_sum_cent` | int | What the parts add up to, when a metric can
     *   also say that. The curated revenue screen names the difference against
     *   `value()` rather than showing two totals and letting a reader guess. |
     *
     * Anything else is the contributor's own, and this addon will not touch it.
     *
     * @return array<string, mixed>
     */
    public function meta(MetricQuery $query): array;
}
