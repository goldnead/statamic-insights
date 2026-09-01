<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use Goldnead\StatamicInsights\Facades\Insights;
use Goldnead\StatamicInsights\ServiceProvider;
use Goldnead\StatamicInsights\Support\Neighbours;
use Goldnead\StatamicInsights\Tests\Support\SeedsNeighbourTables;
use Goldnead\StatamicInsights\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The report screens, asked for over HTTP.
 *
 * The one promise that matters most is the unavailable case: a site without
 * the payments addon opens each report and reads why it is empty, rather than
 * a 500 from "no such table" or a 404 that says the report does not exist.
 */
class ReportRouteTest extends TestCase
{
    use SeedsNeighbourTables;

    protected function tearDown(): void
    {
        Neighbours::forget();

        parent::tearDown();
    }

    protected function benutzer(bool $darf = true)
    {
        if ($darf) {
            return tap(User::make()->email('darf@example.com')->makeSuper())->save();
        }

        $rolle = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email('darfnicht@example.com')->assignRole($rolle))->save();
    }

    /** The six shipped reports are registered by the provider itself. */
    #[Test]
    public function the_six_own_reports_are_registered_at_boot(): void
    {
        $this->assertEqualsCanonicalizing(
            array_values(ServiceProvider::OWN_REPORTS),
            Insights::reportHandles(),
        );
    }

    /** With no sibling installed, every report is listed and every one says it is not. */
    #[Test]
    public function the_list_shows_unavailable_reports_with_what_they_need(): void
    {
        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.reports'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('insights::Reports')
                ->has('groups', 3)
                ->where('groups.0.reports.0.available', false)
                ->where('groups.0.reports.0.requires', fn ($paket) => str_starts_with((string) $paket, 'goldnead/statamic-'))
            );
    }

    #[Test]
    public function an_unavailable_report_answers_with_its_explanation_and_no_rows(): void
    {
        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.reports.show', ['report' => 'payments.revenue_by_month']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('insights::Report')
                ->where('report.available', false)
                ->where('report.requires', 'goldnead/statamic-payments')
                ->where('report.failed', false)
                ->has('report.rows', 0)
            );
    }

    #[Test]
    public function an_available_report_answers_with_columns_and_rows(): void
    {
        $this->createPaymentsTables();
        $this->payment(['amount_cent' => 1200]);

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.reports.show', ['report' => 'payments.revenue_by_month']).'?period=30d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.available', true)
                ->where('report.usesPeriod', true)
                ->where('period', '30d')
                ->has('report.columns', 5)
                ->has('report.rows', 1)
                ->where('report.rows.0.revenue_cent', 1200)
            );
    }

    /** A snapshot report says so, so the screen can hide the period picker. */
    #[Test]
    public function a_snapshot_report_does_not_use_the_period(): void
    {
        $this->createEntitlementsTable();

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.reports.show', ['report' => 'entitlements.access_by_product']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('report.usesPeriod', false));
    }

    #[Test]
    public function an_unknown_report_is_not_found(): void
    {
        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.reports.show', ['report' => 'gibts.nicht']))
            ->assertNotFound();
    }

    #[Test]
    public function both_screens_need_the_permission(): void
    {
        $user = $this->benutzer(darf: false);

        $this->actingAs($user)->get(cp_route('insights.reports'))->assertForbidden();
        $this->actingAs($user)
            ->get(cp_route('insights.reports.show', ['report' => 'payments.revenue_by_month']))
            ->assertForbidden();
    }
}
