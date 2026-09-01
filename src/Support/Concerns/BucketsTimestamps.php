<?php

namespace Goldnead\StatamicInsights\Support\Concerns;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\StatamicInsights\Support\TableReport;
use Illuminate\Support\Facades\DB;

/**
 * Truncating a timestamp to a day or a month, in the dialect at hand.
 *
 * `strftime` is SQLite's and MySQL has never heard of it. Written for one
 * engine, a chart is green in a test suite on SQLite and a 500 on the first
 * production install that runs MySQL — a bill this family has already paid
 * once.
 *
 * A transcription of {@see TableMetric::bucketExpression()} for
 * {@see TableReport}, for the reason given on {@see NarrowsToBrand}: sibling
 * suites pin `TableMetric`'s source, so the two copies are kept in step by
 * hand until a coordinated release lets `TableMetric` use this trait.
 */
trait BucketsTimestamps
{
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

    /** The column that says when a row happened. */
    abstract protected function timestamp(): string;
}
