<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Subscriptions;

use Goldnead\StatamicInsights\Tests\Feature\Reports\ReportsTestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The subscriptions screen, asked for over HTTP.
 */
class SubscriptionsScreenTest extends ReportsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

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

    #[Test]
    public function it_is_closed_to_somebody_without_the_permission(): void
    {
        $this->actingAs($this->benutzer(false))
            ->get(cp_route('insights.subscriptions'))
            ->assertForbidden();
    }

    #[Test]
    public function without_payments_it_says_what_is_missing(): void
    {
        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.subscriptions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('insights::Subscriptions')
                ->where('installed', false)
                ->where('hasSubscriptions', false)
            );
    }

    #[Test]
    public function it_shows_one_currency_at_a_time_and_offers_the_others(): void
    {
        $this->createPaymentsTables();
        $this->createSubscriptionsTable();
        $this->subscription(['amount_cent' => 1900, 'starts_at' => '2026-07-10 09:00:00', 'next_payment_at' => '2026-09-20 09:00:00']);
        $this->subscription(['amount_cent' => 1900, 'starts_at' => '2026-07-10 09:00:00', 'next_payment_at' => '2026-09-21 09:00:00']);
        $this->subscription(['amount_cent' => 5000, 'currency' => 'CHF', 'starts_at' => '2026-07-10 09:00:00']);

        $wer = $this->benutzer();

        $this->actingAs($wer)
            ->get(cp_route('insights.subscriptions', ['period' => '90d']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('insights::Subscriptions')
                ->where('installed', true)
                ->where('hasSubscriptions', true)
                ->where('currency', 'EUR')
                ->has('currencyOptions', 2)
                ->where('tiles.0.handle', 'mrr')
                ->where('tiles.0.value', 3800)
                ->where('tiles.1.value', 3800 * 12)
                ->where('movements.new', 3800)
                ->where('upcoming.total_cent', 3800)
                ->has('series')
            );

        $this->actingAs($wer)
            ->get(cp_route('insights.subscriptions', ['currency' => 'chf']))
            ->assertInertia(fn ($page) => $page
                ->where('currency', 'CHF')
                ->where('tiles.0.value', 5000)
            );
    }
}
