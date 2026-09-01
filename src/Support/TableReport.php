<?php

namespace Goldnead\StatamicInsights\Support;

use Goldnead\StatamicInsights\Contracts\Report;
use Goldnead\StatamicInsights\Support\Concerns\BucketsTimestamps;
use Goldnead\StatamicInsights\Support\Concerns\NarrowsToBrand;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A report over one database table, read through the query builder.
 *
 * What {@see TableMetric} is to a metric: the windowing, the dialect-safe
 * month bucket and the brand narrowing, done once so that six reports cannot
 * each get one of them slightly wrong. A report whose rows come from somewhere
 * else implements {@see Report} directly and ignores this.
 *
 * The sibling that owns the table is named through {@see neighbour()}, and
 * {@see available()} asks {@see Neighbours} — the class-existence probe plus
 * the table check — so a report over a table its addon has not created yet
 * says "needs statamic-payments" rather than throwing "no such table".
 */
abstract class TableReport implements Report
{
    use BucketsTimestamps;
    use NarrowsToBrand;

    /** One of the {@see Neighbours} constants. */
    abstract protected function neighbour(): string;

    abstract protected function table(): string;

    abstract protected function timestamp(): string;

    public function available(): bool
    {
        return Neighbours::installed($this->neighbour());
    }

    public function requires(): ?string
    {
        return Neighbours::package($this->neighbour());
    }

    public function usesPeriod(): bool
    {
        return true;
    }

    /**
     * The rows of the table inside the window, narrowed to the brand.
     *
     * Same three rules as {@see TableMetric::inPeriod()}: a row with no
     * timestamp is in no period, the lower bound is inclusive, the upper bound
     * is the first midnight after the period — see {@see Period::toExclusive()}
     * for the millisecond defect that rule closed.
     */
    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        return $this->window(DB::table($this->table()), $query, $column);
    }

    /**
     * The same window applied to a query somebody else started — a join.
     *
     * A join reaches the table through a second one, so the caller qualifies
     * the column (`payments.paid_at`); the brand narrowing below still keys on
     * this report's own table.
     */
    protected function window(Builder $rows, MetricQuery $query, ?string $column = null): Builder
    {
        $column ??= $this->table().'.'.$this->timestamp();

        $rows = $rows
            ->whereNotNull($column)
            ->when($query->period->from, fn ($rows) => $rows->where($column, '>=', $query->period->from))
            ->when($query->period->toExclusive(), fn ($rows) => $rows->where($column, '<', $query->period->toExclusive()));

        return $this->brandScoped($rows);
    }

    /**
     * The window, and never past this moment.
     *
     * For reports over what *happened*: an open-ended period has no upper
     * bound, and a table with scheduled rows would otherwise report the future
     * as done.
     */
    protected function untilNow(MetricQuery $query, ?string $column = null): Builder
    {
        $column ??= $this->table().'.'.$this->timestamp();

        return $this->inPeriod($query, $column)->where($column, '<=', Carbon::now());
    }

    /** `Y-m` of a timestamp, in the dialect at hand, regardless of the query's bucket. */
    protected function monthOf(string $column): string
    {
        return $this->bucketExpression(
            new MetricQuery(Period::fromPreset('all'), MetricQuery::BUCKET_MONTH),
            $column,
        );
    }

    /**
     * A driver hands back numeric strings; `+ 0` lets PHP pick int or float.
     * Casting to int would floor an average, casting to float would print a
     * count as "7.0".
     */
    protected function number(mixed $value): int|float
    {
        return is_numeric($value) ? $value + 0 : 0;
    }

    /**
     * A share as a percentage, or null over an empty denominator.
     *
     * Null is not zero: a conversion rate for an offer nobody has seen has no
     * answer, and "0 %" beside it is a statement its own neighbour contradicts.
     */
    protected function percent(int|float $part, int|float $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round($part / $whole * 100, 1);
    }

    /** @return array{key: string, label: string, unit: string} */
    protected function column(string $key, string $label, string $unit): array
    {
        return ['key' => $key, 'label' => $label, 'unit' => $unit];
    }
}
