<?php

namespace Goldnead\StatamicInsights\Support;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Contracts\Metric;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A metric over one database table with a timestamp on it.
 *
 * Optional and offered, not required: the contract is {@see Metric}, and an
 * addon whose numbers come from somewhere else — a file store, an API, a
 * calculation — implements that directly and ignores this. What this saves is
 * the part every table-backed metric would otherwise write again: windowing a
 * period, bucketing a timestamp in three SQL dialects, splitting by a column
 * without dropping the rows whose value is null.
 *
 * Extracted from `statamic-payments` after it had proven itself there, rather
 * than designed in advance — which is why it has exactly the methods a real
 * implementation needed and no others.
 *
 * A contributor writes:
 *
 *     class ActiveMembers extends TableMetric
 *     {
 *         protected function table(): string { return 'memberships'; }
 *         protected function timestamp(): string { return 'started_at'; }
 *
 *         public function handle(): string { return 'memberships.started'; }
 *         public function label(): string  { return __('New memberships'); }
 *         public function group(): string  { return __('Memberships'); }
 *         public function unit(): string   { return Unit::COUNT; }
 *
 *         public function value(MetricQuery $q): int|float|null
 *         {
 *             return $this->inPeriod($q)->count();
 *         }
 *
 *         public function series(MetricQuery $q): array
 *         {
 *             return $this->bucketed($this->inPeriod($q), $q, 'count(*)');
 *         }
 *     }
 *
 * **Loading this class means Insights is installed.** Guard the registration
 * with `class_exists` on the facade and PHP never reaches the file when the
 * sibling is absent — which is what keeps the coupling one-directional and
 * optional. Put Insights in `suggest`, never in `require`.
 */
abstract class TableMetric implements Metric
{
    /** The table the numbers come from. */
    abstract protected function table(): string;

    /**
     * The column that says when a row happened.
     *
     * Not `created_at` by default and deliberately without one: the row is
     * written when the software noticed, and the fact happened when it
     * happened. A payment paid on the 30th and recorded on the 1st belongs to
     * the 30th, and a metric that never had to choose would pick the wrong one
     * silently.
     */
    abstract protected function timestamp(): string;

    /**
     * Nothing to measure, which is not the same as measuring nothing.
     *
     * A metric whose table is absent is left out of every screen rather than
     * reporting a zero — the difference between "this addon is not installed"
     * and "nobody bought anything".
     */
    public function available(): bool
    {
        return Schema::hasTable($this->table());
    }

    public function description(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function meta(MetricQuery $query): array
    {
        return [];
    }

    /**
     * The rows inside the window, ready to be counted or summed.
     *
     * Override to add the conditions that make a row count at all — a status,
     * a brand, a soft-delete. Everything downstream builds on this, so a
     * condition put here applies to the figure, the chart and every split at
     * once, and cannot be forgotten in one of them.
     */
    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        $column ??= $this->timestamp();

        return DB::table($this->table())
            // A row with no timestamp cannot be placed in time, so it is in no
            // period — including "all time", where both bounds are null and the
            // two clauses below add no condition at all. Without this, a metric
            // over a nullable column counted every row ever written the moment
            // somebody picked the widest range: cancellations that never
            // happened, completions that never completed. Found by a
            // contributor building on this class, which is the only reason it
            // was found before shipping.
            ->whereNotNull($column)
            ->when($query->period->from, fn ($rows) => $rows->where($column, '>=', $query->period->from))
            ->when($query->period->to, fn ($rows) => $rows->where($column, '<=', $query->period->to));
    }

    /**
     * The same window, but never reaching past this moment.
     *
     * **A decision every metric has to make, which is why this is opt-in and
     * named rather than done for you.** An open-ended period has no upper
     * bound, and these tables are full of the future: a pre-order starting next
     * month, a licence expiring next year, a campaign scheduled for Friday,
     * a task due on Monday. Counted without a clamp, the widest range reports
     * all of it as though it had already happened.
     *
     * Clamp when the metric answers **what happened** — sales, cancellations,
     * confirmations, bounces. Do not clamp when it answers **what is
     * scheduled** — upcoming events, due tasks, pending retries — because there
     * the future is the point, and a screen that hid it would be lying by
     * omission instead.
     *
     * Found by a contributor whose tables carried pre-orders. It is the sibling
     * of the null-timestamp defect one method up: there the condition was
     * missing entirely, here only its upper half.
     */
    protected function untilNow(MetricQuery $query, ?string $column = null): Builder
    {
        $column ??= $this->timestamp();

        return $this->inPeriod($query, $column)->where($column, '<=', Carbon::now());
    }

    /**
     * Truncating a timestamp to a day or a month, in the dialect at hand.
     *
     * `strftime` is SQLite's and MySQL has never heard of it. Written for one
     * engine, a chart is green in a test suite on SQLite and a 500 on the first
     * production install that runs MySQL — a bill this family has already paid
     * once.
     */
    protected function bucketExpression(MetricQuery $query, ?string $column = null): string
    {
        $column ??= $this->timestamp();
        $monthly = $query->bucket === MetricQuery::BUCKET_MONTH;

        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => $monthly
                ? "date_format({$column}, '%Y-%m')"
                : "date_format({$column}, '%Y-%m-%d')",
            'pgsql' => $monthly
                ? "to_char({$column}, 'YYYY-MM')"
                : "to_char({$column}, 'YYYY-MM-DD')",
            default => $monthly
                ? "strftime('%Y-%m', {$column})"
                : "strftime('%Y-%m-%d', {$column})",
        };
    }

    /**
     * One aggregate per bucket, and only for the buckets that have data.
     *
     * The empty ones are left out on purpose: Insights fills the range in for
     * every metric at once. A metric that invented its own zeroes would fill
     * them twice, and one that invented a bucket outside the range would draw
     * a column the axis has no place for.
     *
     * @return array<string, int|float>
     */
    protected function bucketed(Builder $rows, MetricQuery $query, string $aggregate, ?string $column = null): array
    {
        return $rows
            ->selectRaw($this->bucketExpression($query, $column).' as bucket, '.$aggregate.' as measured')
            ->groupBy('bucket')
            ->pluck('measured', 'bucket')
            ->all();
    }

    /**
     * Split by one column, largest first, with the null rows kept.
     *
     * **A row whose value is null is a row.** A sale with no campaign, a
     * booking with no source, a grant with no reason — grouping them under one
     * heading is honest; dropping them makes the split disagree with the total
     * and nothing on the screen says why. Label them through
     * {@see missingLabel()}.
     *
     * @return array<int, array{key: string|null, value: int|float}>
     */
    protected function splitByColumn(
        Builder $rows,
        MetricQuery $query,
        string $column,
        string $aggregate,
        int $limit,
    ): array {
        return $rows
            ->selectRaw($column.' as split_key, '.$aggregate.' as measured')
            ->groupBy($column)
            ->orderByRaw($aggregate.' desc')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'key' => ($row->split_key === null || $row->split_key === '') ? null : (string) $row->split_key,
                // `+ 0` rather than a cast: the driver hands back a numeric
                // string, and PHP turns "1500" into an int and "1.5" into a
                // float on its own. Casting to int would silently floor an
                // average; casting to float would print a count as "7.0".
                'value' => $row->measured + 0,
            ])
            ->all();
    }

    /**
     * Turn the raw split rows into what {@see HasBreakdowns}
     * promises, labelling the null.
     *
     * @param  array<int, array{key: string|null, value: int|float}>  $rows
     * @return array<int, array{key: string|null, label: string, value: int|float}>
     */
    protected function labelled(array $rows, string $dimension): array
    {
        return array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => $row['key'] ?? $this->missingLabel($dimension),
            'value' => $row['value'],
        ], $rows);
    }

    /**
     * What to call the rows that have no value for this split.
     *
     * Overridden per addon, because "no campaign" and "no source" read
     * differently and a shared "—" tells a reader nothing.
     */
    protected function missingLabel(string $dimension): string
    {
        return __('statamic-insights::report.unassigned');
    }
}
