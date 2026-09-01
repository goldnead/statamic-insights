<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Reports;

use Goldnead\StatamicInsights\Reports\AccessByProduct;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

class AccessByProductTest extends ReportsTestCase
{
    #[Test]
    public function it_is_unavailable_when_entitlements_is_missing(): void
    {
        $report = new AccessByProduct;

        $this->assertFalse($report->available());
        $this->assertSame('goldnead/statamic-entitlements', $report->requires());
        $this->assertFalse($report->usesPeriod());
    }

    #[Test]
    public function it_sorts_access_into_active_grace_and_expired_per_slug(): void
    {
        $this->createEntitlementsTable();
        Carbon::setTestNow('2026-08-20 12:00:00');

        // Active: open-ended, and one with an expiry still ahead.
        $this->entitlement(['product_slug' => 'kurs-zugang']);
        $this->entitlement(['product_slug' => 'kurs-zugang', 'expires_at' => Carbon::now()->addMonth()]);
        // In grace: expired last week, grace runs another week.
        $this->entitlement(['product_slug' => 'kurs-zugang', 'expires_at' => Carbon::now()->subWeek(), 'grace_until' => Carbon::now()->addWeek()]);
        // Expired: with grace that has passed, and without any.
        $this->entitlement(['product_slug' => 'kurs-zugang', 'expires_at' => Carbon::now()->subMonth(), 'grace_until' => Carbon::now()->subWeek()]);
        $this->entitlement(['product_slug' => 'kurs-zugang', 'expires_at' => Carbon::now()->subMonth()]);
        // Revoked: in no column.
        $this->entitlement(['product_slug' => 'kurs-zugang', 'revoked_at' => Carbon::now()->subDay()]);
        // Not started yet: not active.
        $this->entitlement(['product_slug' => 'kurs-zugang', 'starts_at' => Carbon::now()->addDay()]);

        $this->entitlement(['product_slug' => 'community']);

        $rows = (new AccessByProduct)->rows($this->frage('30d'));

        $kurs = $this->row($rows, 'product_slug', 'kurs-zugang');
        $this->assertSame(2, $kurs['active']);
        $this->assertSame(1, $kurs['grace']);
        $this->assertSame(2, $kurs['expired']);

        $this->assertSame(1, $this->row($rows, 'product_slug', 'community')['active']);

        // Most active first.
        $this->assertSame('kurs-zugang', $rows[0]['product_slug']);
    }

    /** A snapshot: the period changes nothing. */
    #[Test]
    public function the_period_does_not_narrow_a_snapshot(): void
    {
        $this->createEntitlementsTable();

        $this->entitlement(['starts_at' => Carbon::now()->subYears(2)]);

        $this->assertSame(1, (new AccessByProduct)->rows($this->frage('7d'))[0]['active']);
    }
}
