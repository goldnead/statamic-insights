<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use Goldnead\StatamicInsights\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The screen itself, asked for over HTTP.
 *
 * Written after a review found the one failure the unit tests could not: with
 * no payments tables at all, `overTime()` queried a table that was not there
 * and the whole page answered 500 — which made the addon's carefully worded
 * "the payments addon is not installed" state unreachable. Every assertion
 * below existed in prose somewhere; none of them was ever executed against a
 * request.
 */
class RevenueRouteTest extends TestCase
{
    protected function benutzer(bool $darf = true)
    {
        if ($darf) {
            return tap(User::make()->email('darf@example.com')->makeSuper())->save();
        }

        // Someone who may open the Control Panel but not this screen. Without
        // `access cp` the request is redirected to the login instead of
        // refused, and the test would be asserting the wrong thing entirely.
        $rolle = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email('darfnicht@example.com')->assignRole($rolle))->save();
    }

    /** The state a fresh install lands in, and it must not be an error page. */
    #[Test]
    public function it_answers_without_the_payments_tables(): void
    {
        $antwort = $this->actingAs($this->benutzer())->get(cp_route('insights.revenue'));

        $antwort->assertOk();
        $antwort->assertInertia(fn ($page) => $page
            ->component('insights::Revenue')
            ->where('installed', false)
            ->where('hasSales', false)
        );
    }

    #[Test]
    public function it_separates_no_addon_from_no_sales(): void
    {
        $this->createPaymentsSchema();

        $antwort = $this->actingAs($this->benutzer())->get(cp_route('insights.revenue'));

        $antwort->assertOk();
        $antwort->assertInertia(fn ($page) => $page
            ->where('installed', true)
            ->where('hasSales', false)
        );
    }

    #[Test]
    public function it_reports_a_sale(): void
    {
        $this->createPaymentsSchema();

        DB::table('payments')->insert([
            'provider' => 'mollie', 'provider_id' => 'tr_1', 'product' => 'noten',
            'amount_cent' => 1900, 'currency' => 'EUR', 'status' => 'paid',
            'email' => 'wer@example.com', 'utm_campaign' => 'sommer',
            'paid_at' => Carbon::now()->subDay(),
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('hasSales', true)
                ->where('totals.gross_cent', 1900)
                ->where('currency', 'EUR')
                ->has('byCampaign', 1)
                ->has('overTime', 30)
            );
    }

    /** The period and currency ride in the query string, so a view is shareable. */
    #[Test]
    public function the_period_comes_from_the_query_string(): void
    {
        $this->createPaymentsSchema();

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue').'?period=7d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('period', '7d')->has('overTime', 7));
    }

    /** An unknown period falls back rather than producing an empty range. */
    #[Test]
    public function a_nonsense_period_falls_back(): void
    {
        $this->createPaymentsSchema();

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue').'?period=bananen')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('period', '30d'));
    }

    #[Test]
    public function it_refuses_somebody_without_the_permission(): void
    {
        $this->actingAs($this->benutzer(darf: false))
            ->get(cp_route('insights.revenue'))
            ->assertForbidden();
    }
}
