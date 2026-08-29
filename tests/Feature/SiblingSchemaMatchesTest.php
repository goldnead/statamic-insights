<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * The columns this addon reads have to be the columns the sibling writes.
 *
 * The report is a set of SQL aggregates over another package's tables, and the
 * suite builds those tables itself so it can run without a payment provider
 * installed. The cost of that is real: rename a column in `statamic-payments`
 * and every test here stays green while the screen goes blank.
 *
 * This is the guard that pays it back. It reads the sibling's own migrations
 * wherever they are reachable — a path checkout beside this one, or an
 * installed vendor copy — and fails if a column the report names is not in
 * them. Where the sibling is nowhere to be found (a CI job that installs only
 * this package) it skips, saying so, rather than passing quietly.
 */
class SiblingSchemaMatchesTest extends TestCase
{
    /** Columns the report reads by name. Keep in step with RevenueReport. */
    private const PAYMENTS = [
        'status', 'currency', 'amount_cent', 'paid_at', 'email', 'product',
        'refunded_cent', 'refunded_at', 'utm_campaign', 'utm_source',
    ];

    private const PAYMENT_ITEMS = [
        'payment_id', 'product', 'amount_cent', 'quantity', 'discount_cent',
    ];

    #[Test]
    public function every_column_the_report_reads_exists_in_the_siblings_migrations(): void
    {
        $quelle = $this->siblingMigrations();

        if ($quelle === null) {
            $this->markTestSkipped('goldnead/statamic-payments ist hier nicht auffindbar; der Playground bleibt der Waechter.');
        }

        $sql = '';

        foreach (Finder::create()->files()->in($quelle)->name('*.php') as $datei) {
            $sql .= $datei->getContents();
        }

        foreach (self::PAYMENTS as $spalte) {
            $this->assertStringContainsString(
                "'{$spalte}'",
                $sql,
                "Die Spalte payments.{$spalte} kommt in den Migrationen des Zahlungs-Addons nicht vor."
            );
        }

        foreach (self::PAYMENT_ITEMS as $spalte) {
            $this->assertStringContainsString(
                "'{$spalte}'",
                $sql,
                "Die Spalte payment_items.{$spalte} kommt in den Migrationen des Zahlungs-Addons nicht vor."
            );
        }
    }

    protected function siblingMigrations(): ?string
    {
        $kandidaten = [
            __DIR__.'/../../vendor/goldnead/statamic-payments/database/migrations',
            __DIR__.'/../../../statamic-payments/database/migrations',
        ];

        foreach ($kandidaten as $pfad) {
            if (is_dir($pfad)) {
                return $pfad;
            }
        }

        return null;
    }
}
