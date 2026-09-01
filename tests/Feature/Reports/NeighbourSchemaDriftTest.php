<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Reports;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The hand-built neighbour tables against the neighbours' real migrations.
 *
 * `SeedsNeighbourTables` names the columns the reports read. If a sibling
 * renames one, the reports break on a real install while this suite stays
 * green — unless somebody checks the names against the sibling's migrations,
 * which is what this does. Every column the seed trait creates has to be
 * declared in some migration of the sibling, by name.
 *
 * Skipped, not failed, when the sibling's working copy is not beside this one:
 * CI for this addon alone has no way to check, and a skip says so where a pass
 * would lie.
 */
class NeighbourSchemaDriftTest extends ReportsTestCase
{
    /** Written by `id()` and `timestamps()`, never named in a migration. */
    private const IMPLICIT = ['id', 'created_at', 'updated_at'];

    #[Test]
    public function the_seeded_payments_tables_match_the_payments_migrations(): void
    {
        $this->createPaymentsTables();

        $this->assertColumnsAreDeclared('statamic-payments', 'payments');
        $this->assertColumnsAreDeclared('statamic-payments', 'payment_items');
    }

    #[Test]
    public function the_seeded_offers_table_matches_the_offers_migrations(): void
    {
        $this->createOffersTable();

        $this->assertColumnsAreDeclared('statamic-offers', 'offers');
    }

    #[Test]
    public function the_seeded_entitlements_table_matches_the_entitlements_migrations(): void
    {
        $this->createEntitlementsTable();

        $this->assertColumnsAreDeclared('statamic-entitlements', 'entitlements');
    }

    protected function assertColumnsAreDeclared(string $sibling, string $table): void
    {
        $dir = realpath(__DIR__.'/../../../../'.$sibling.'/database/migrations');

        if ($dir === false) {
            $this->markTestSkipped("{$sibling} is not checked out beside this addon; nothing to compare against.");
        }

        $migrations = implode("\n", array_map('file_get_contents', glob($dir.'/*.php') ?: []));

        $this->assertStringContainsString(
            "Schema::create('{$table}'",
            $migrations,
            "{$sibling} no longer creates a `{$table}` table.",
        );

        foreach (Schema::getColumnListing($table) as $column) {
            if (in_array($column, self::IMPLICIT, true)) {
                continue;
            }

            // `$table->string('country', 2)`, `$table->timestamp('paid_at')`,
            // `$table->foreignId('payment_id')` — the column is always the
            // first quoted argument of a builder call.
            $this->assertMatchesRegularExpression(
                "/->\\w+\\(\\s*'".preg_quote($column, '/')."'/",
                $migrations,
                "`{$table}.{$column}` is seeded by the suite but no migration of {$sibling} declares it. Either the sibling renamed it, or the seed trait is out of date.",
            );
        }
    }
}
