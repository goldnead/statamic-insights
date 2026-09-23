<?php

namespace Goldnead\StatamicInsights\Support;

use Goldnead\StatamicInsights\Contracts\HasDefaultSort;
use Goldnead\StatamicInsights\Contracts\HasFilterOptions;
use Goldnead\StatamicInsights\Contracts\Report;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a report plus a question into the shape a screen renders.
 *
 * **Nothing here throws.** A list shows many reports; one that is broken must
 * cost its own card, not the page. A report whose `rows()` throws answers with
 * an empty table and a line in the log, which is the difference between "no
 * data" and "no answer" made visible to whoever reads logs — and the screen
 * gets `failed: true` so it can say so rather than print "nothing sold".
 */
class ReportReader
{
    public function __construct(protected ReportRegistry $registry) {}

    /**
     * The describing half — what a card on the list needs. No query runs.
     *
     * @return array<string, mixed>
     */
    public function describe(Report $report): array
    {
        $available = $this->available($report);

        return [
            'handle' => $report->handle(),
            'label' => $report->label(),
            'description' => $report->description(),
            'group' => $report->group(),
            'available' => $available,
            'requires' => $report->requires(),
            'usesPeriod' => $report->usesPeriod(),
            'sort' => $this->sort($report),
        ];
    }

    /**
     * The order the report itself asks for, or null to let the screen decide.
     *
     * Only a column the report actually has: a handle that no longer matches a
     * column would leave the listing sorting by nothing, which draws an empty
     * table over rows that are there.
     *
     * @return array{column: string, direction: string}|null
     */
    protected function sort(Report $report): ?array
    {
        if (! $report instanceof HasDefaultSort) {
            return null;
        }

        try {
            $sort = $report->defaultSort();
            $columns = array_column($report->columns(), 'key');
        } catch (Throwable $e) {
            Log::warning("insights: the report [{$report->handle()}] failed while saying how it wants to be sorted.", [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if (! in_array($sort['column'] ?? null, $columns, true)) {
            return null;
        }

        return [
            'column' => $sort['column'],
            'direction' => ($sort['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * The whole report, rows included.
     *
     * @return array<string, mixed>
     */
    public function read(Report $report, MetricQuery $query): array
    {
        $described = $this->describe($report);

        if (! $described['available']) {
            return $described + ['columns' => [], 'rows' => [], 'failed' => false];
        }

        try {
            // Rows first: a report may drop a column that came out empty in
            // this answer, and it only knows once the rows are built.
            $rows = $report->rows($query);
            $columns = $report->columns();
            $failed = false;
        } catch (Throwable $e) {
            Log::warning("insights: the report [{$report->handle()}] failed at [rows] and was left empty.", [
                'exception' => $e->getMessage(),
            ]);

            $columns = [];
            $rows = [];
            $failed = true;
        }

        return $described + [
            'columns' => $columns,
            'rows' => array_values($rows),
            'failed' => $failed,
            'filterOptions' => $this->filterOptions($report),
        ];
    }

    /**
     * What the report's filters may be set to, for a switch above the table.
     *
     * Optional, through {@see HasFilterOptions}, the contract metrics already
     * use for the same question. A report without it gets no switch.
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    public function filterOptions(Report $report): array
    {
        if (! $report instanceof HasFilterOptions) {
            return [];
        }

        try {
            return $report->filterOptions();
        } catch (Throwable $e) {
            Log::warning("insights: the report [{$report->handle()}] failed while listing its filter options.", [
                'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Every registered report described, grouped under its heading.
     *
     * @return array<int, array{group: string, reports: array<int, array<string, mixed>>}>
     */
    public function overview(): array
    {
        $groups = [];

        foreach ($this->registry->grouped() as $group => $reports) {
            $described = [];

            foreach ($reports as $report) {
                $described[] = $this->describe($report);
            }

            if ($described !== []) {
                $groups[] = ['group' => $group, 'reports' => $described];
            }
        }

        return $groups;
    }

    public function available(Report $report): bool
    {
        try {
            return $report->available();
        } catch (Throwable $e) {
            Log::warning("insights: the report [{$report->handle()}] failed while saying whether it is available.", [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
