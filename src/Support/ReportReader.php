<?php

namespace Goldnead\StatamicInsights\Support;

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
            $columns = $report->columns();
            $rows = $report->rows($query);
            $failed = false;
        } catch (Throwable $e) {
            Log::warning("insights: the report [{$report->handle()}] failed at [rows] and was left empty.", [
                'exception' => $e->getMessage(),
            ]);

            $columns = [];
            $rows = [];
            $failed = true;
        }

        return $described + ['columns' => $columns, 'rows' => array_values($rows), 'failed' => $failed];
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

    protected function available(Report $report): bool
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
