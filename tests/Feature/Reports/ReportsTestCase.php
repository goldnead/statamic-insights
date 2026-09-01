<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Reports;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Tests\Support\SeedsNeighbourTables;
use Goldnead\StatamicInsights\Tests\TestCase;

abstract class ReportsTestCase extends TestCase
{
    use SeedsNeighbourTables;

    protected function tearDown(): void
    {
        // Static, so one test's idea of which siblings exist would otherwise
        // still be answering in the next.
        $this->forgetNeighbours();

        parent::tearDown();
    }

    protected function frage(string $preset = '30d'): MetricQuery
    {
        $period = Period::fromPreset($preset);

        return new MetricQuery($period, MetricQuery::bucketFor($period));
    }

    /**
     * The row for a key, from a list of rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    protected function row(array $rows, string $key, mixed $value): ?array
    {
        foreach ($rows as $row) {
            if (($row[$key] ?? null) === $value) {
                return $row;
            }
        }

        return null;
    }
}
