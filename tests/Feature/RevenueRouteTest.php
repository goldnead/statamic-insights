<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use Goldnead\StatamicInsights\Facades\Insights;
use Goldnead\StatamicInsights\Support\Unit;
use Goldnead\StatamicInsights\Tests\Fakes\FakeRevenueMetric;
use Goldnead\StatamicInsights\Tests\Fakes\MinimalRevenueMetric;
use Goldnead\StatamicInsights\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The curated revenue screen, asked for over HTTP.
 *
 * Since the rewrite it computes nothing: it asks the registry for six handles
 * and arranges what comes back. So these tests register those handles and check
 * the arranging — the arithmetic behind them is tested in the addon that owns
 * it, which is the whole point of the contract.
 *
 * A route test exists at all because the last review found the one failure no
 * unit test could see: a screen that answered 500 because nothing ever
 * requested it.
 */
class RevenueRouteTest extends TestCase
{
    protected function benutzer(bool $darf = true)
    {
        if ($darf) {
            return tap(User::make()->email('darf@example.com')->makeSuper())->save();
        }

        $rolle = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email('darfnicht@example.com')->assignRole($rolle))->save();
    }

    /**
     * Register what payments would register.
     *
     * @param  array<string, int>  $perBucket
     */
    protected function registerRevenue(array $perBucket = [], array $currencies = ['EUR'], array $meta = []): void
    {
        $summe = array_sum($perBucket);

        Insights::registerMetric(new FakeRevenueMetric(
            'payments.revenue_gross', 'Einnahmen', Unit::CURRENCY,
            perBucket: $perBucket, currencies: $currencies, meta: $meta,
        ));
        Insights::registerMetric(new FakeRevenueMetric('payments.revenue_net', 'Einnahmen netto', Unit::CURRENCY, fixed: $summe - 500));
        Insights::registerMetric(new FakeRevenueMetric('payments.refunded', 'Erstattungen', Unit::CURRENCY, fixed: 500));
        Insights::registerMetric(new FakeRevenueMetric('payments.orders', 'Bestellungen', Unit::COUNT, fixed: 3));
        Insights::registerMetric(new FakeRevenueMetric('payments.buyers', 'Käufer', Unit::COUNT, fixed: 2));
        Insights::registerMetric(new FakeRevenueMetric('payments.average_order', 'Durchschnitt', Unit::CURRENCY, fixed: (int) ($summe / 3)));
        Insights::registerMetric(new FakeRevenueMetric('payments.refund_rate', 'Erstattungsquote', Unit::PERCENT, fixed: 12.5));
    }

    /**
     * The state a fresh install lands in, and it must not be an error page.
     *
     * Nothing registers revenue, which is a setup problem with an answer — not
     * the same thing as having sold nothing.
     */
    #[Test]
    public function it_answers_when_no_addon_reports_revenue(): void
    {
        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('insights::Revenue')
                ->where('installed', false)
                ->where('hasSales', false)
            );
    }

    /** Registered but nothing sold is the other kind of nothing. */
    #[Test]
    public function it_separates_no_addon_from_no_sales(): void
    {
        $this->registerRevenue(currencies: []);

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('installed', true)
                ->where('hasSales', false)
            );
    }

    #[Test]
    public function it_arranges_the_registered_figures(): void
    {
        $heute = Carbon::now()->format('Y-m-d');
        $this->registerRevenue([$heute => 3000]);

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('hasSales', true)
                ->where('grossCent', 3000)
                ->where('netCent', 2500)
                ->where('refunded', 500)
                ->where('refundRate', 12.5)
                ->where('currency', 'EUR')
                ->has('tiles', 4)
                ->has('series', 30)
                ->has('byCampaign', 2)
                ->has('byProduct', 2)
            );
    }

    /** The buyer count is a footnote to the order count, not a fifth tile. */
    #[Test]
    public function the_buyer_count_rides_along_with_the_orders(): void
    {
        $this->registerRevenue([Carbon::now()->format('Y-m-d') => 3000]);

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue'))
            ->assertInertia(fn ($page) => $page->where('tiles.2.hint.value', 2));
    }

    /**
     * A screen assembled from parts must survive a missing part.
     *
     * An older payments that registers revenue but not the refund rate leaves a
     * gap, and the gap has to be absence rather than a zero: "not measured" and
     * "measured nothing" are different statements.
     */
    #[Test]
    public function a_metric_that_is_not_registered_is_absent_rather_than_zero(): void
    {
        Insights::registerMetric(new FakeRevenueMetric(
            'payments.revenue_gross', 'Einnahmen', Unit::CURRENCY,
            perBucket: [Carbon::now()->format('Y-m-d') => 1000],
        ));

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('grossCent', 1000)
                ->where('refundRate', null)
                ->where('netCent', 0)
                // Only the gross tile survives; the row gets shorter rather
                // than showing three dashes.
                ->has('tiles', 1)
            );
    }

    /**
     * The required contract is enough to fill the screen.
     *
     * `HasFilterOptions` is optional by contract, and the screen used to derive
     * "has anything been sold" from it — so a metric implementing only the
     * documented minimum reported real revenue and got "no paid order yet" over
     * its own numbers. Whoever implements what the README asks for must not be
     * shown an empty state.
     */
    #[Test]
    public function a_metric_with_only_the_required_contract_still_fills_the_screen(): void
    {
        Insights::registerMetric(new MinimalRevenueMetric(1250));

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('installed', true)
                ->where('hasSales', true)
                ->where('grossCent', 1250)
                // No switch, because nothing offered a choice — which is
                // different from having no sales.
                ->has('currencyOptions', 0)
            );
    }

    #[Test]
    public function the_period_comes_from_the_query_string(): void
    {
        $this->registerRevenue([Carbon::now()->format('Y-m-d') => 1000]);

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue').'?period=7d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('period', '7d')->has('series', 7));
    }

    #[Test]
    public function a_nonsense_period_falls_back(): void
    {
        $this->registerRevenue([Carbon::now()->format('Y-m-d') => 1000]);

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue').'?period=bananen')
            ->assertInertia(fn ($page) => $page->where('period', '30d'));
    }

    /** The currency switch comes from the metric, not from this addon. */
    #[Test]
    public function the_currency_options_come_from_the_metric(): void
    {
        $this->registerRevenue([Carbon::now()->format('Y-m-d') => 1000], currencies: ['CHF', 'EUR']);

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue').'?currency=EUR')
            ->assertInertia(fn ($page) => $page
                ->has('currencyOptions', 2)
                ->where('currency', 'EUR')
            );
    }

    /**
     * A currency nobody ever took falls back to the busiest one there is.
     *
     * Not to the configured default: a site that only ever sold in francs would
     * otherwise open an empty screen with no reason given.
     */
    #[Test]
    public function an_unknown_currency_falls_back_to_what_exists(): void
    {
        $this->registerRevenue([Carbon::now()->format('Y-m-d') => 1000], currencies: ['CHF']);

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue').'?currency=USD')
            ->assertInertia(fn ($page) => $page->where('currency', 'CHF'));
    }

    /** The line-item discrepancy travels as metric metadata, or not at all. */
    #[Test]
    public function the_line_item_sum_is_passed_through_when_the_metric_offers_it(): void
    {
        $this->registerRevenue(
            [Carbon::now()->format('Y-m-d') => 3000],
            meta: ['line_item_sum_cent' => 3400],
        );

        $this->actingAs($this->benutzer())
            ->get(cp_route('insights.revenue'))
            ->assertInertia(fn ($page) => $page->where('lineItemSumCent', 3400));
    }

    #[Test]
    public function it_refuses_somebody_without_the_permission(): void
    {
        $this->actingAs($this->benutzer(darf: false))
            ->get(cp_route('insights.revenue'))
            ->assertForbidden();
    }
}
