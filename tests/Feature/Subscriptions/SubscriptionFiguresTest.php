<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Subscriptions;

use Goldnead\StatamicInsights\Subscriptions\AgreementReader;
use Goldnead\StatamicInsights\Subscriptions\SubscriptionFigures;
use Goldnead\StatamicInsights\Tests\Feature\Reports\ReportsTestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * The subscription figures against a ledger whose answers were worked out by
 * hand, before the code existed.
 *
 * Every expected number in this file is written as the sum it comes from, so
 * a reader can check the arithmetic without running anything. None of them is
 * read back from the class under test (`feedback-die-eigene-zahl-ist-nicht-geprueft`).
 */
class SubscriptionFiguresTest extends ReportsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00'));

        $this->createPaymentsTables();
        $this->createSubscriptionsTable();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function figures(): SubscriptionFigures
    {
        return new SubscriptionFigures(app(AgreementReader::class)->all(), Carbon::now());
    }

    #[Test]
    public function mrr_normalises_every_rhythm_to_a_month_and_leaves_plans_trials_and_drafts_out(): void
    {
        $this->subscription(['amount_cent' => 1900, 'interval' => '1 month', 'starts_at' => '2026-01-10 09:00:00']);
        $this->subscription(['amount_cent' => 39900, 'interval' => '3 months', 'starts_at' => '2026-06-01 09:00:00']);
        $this->subscription(['amount_cent' => 120000, 'interval' => '1 year', 'starts_at' => '2026-02-01 09:00:00']);
        // A payment plan is money on a schedule, not recurring revenue.
        $this->subscription(['amount_cent' => 39900, 'interval' => '1 month', 'times' => 3, 'starts_at' => '2026-08-01 09:00:00']);
        // A trial whose first charge is still ahead has earned nothing yet.
        $this->subscription(['amount_cent' => 14900, 'status' => 'pending', 'starts_at' => '2026-09-20 09:00:00']);
        // Never confirmed by the provider: not an agreement.
        $this->subscription(['amount_cent' => 5000, 'status' => 'initiated', 'starts_at' => '2026-09-01 09:00:00']);
        // Francs are their own figure, never added to euros.
        $this->subscription(['amount_cent' => 2000, 'currency' => 'CHF', 'starts_at' => '2026-05-01 09:00:00']);

        $figures = $this->figures();

        $this->assertSame(1900 + 13300 + 10000, $figures->mrr(Carbon::now(), 'EUR'));
        $this->assertSame(2000, $figures->mrr(Carbon::now(), 'CHF'));
        $this->assertSame((1900 + 13300 + 10000) * 12, $figures->arr(Carbon::now(), 'EUR'));
        $this->assertSame(['EUR', 'CHF'], $figures->currencies());
    }

    /**
     * `statamic-payments` sets `starts_at` one rhythm after the checkout, because
     * the checkout paid the first period. Counting from `starts_at` would put
     * every subscription a month late into MRR.
     */
    #[Test]
    public function a_subscription_counts_from_the_checkout_that_paid_its_first_period(): void
    {
        $id = $this->subscription(['amount_cent' => 1900, 'starts_at' => '2026-09-10 09:00:00']);
        $this->cycle($id, '2026-08-10 09:00:00', 1900);
        // A free first payment of a trial is not a paid period.
        $trial = $this->subscription(['amount_cent' => 1900, 'status' => 'pending', 'starts_at' => '2026-09-20 09:00:00']);
        $this->cycle($trial, '2026-09-06 09:00:00', 0);

        $figures = $this->figures();

        $this->assertSame(1900, $figures->mrr(Carbon::parse('2026-08-20 00:00:00'), 'EUR'));
        $this->assertSame(1900, $figures->mrr(Carbon::parse('2026-09-15 00:00:00'), 'EUR'));
    }

    #[Test]
    public function a_weekly_rhythm_is_rounded_once_on_the_sum_not_per_agreement(): void
    {
        // 1000 × 52 / 12 = 4333.33 each; three of them are 12999.99, i.e. 13000.
        foreach (range(1, 3) as $i) {
            $this->subscription(['amount_cent' => 1000, 'interval' => '1 week', 'starts_at' => '2026-08-01 09:00:00']);
        }

        $this->assertSame(13000, $this->figures()->mrr(Carbon::now(), 'EUR'));
    }

    /**
     * August 2026, by hand:
     *
     *     at 1 Aug: s1 1000 + s3 2000 + s4 3000 + s5 500 + s6 2000 + s7 3000 + s8 700 = 12200
     *     at 1 Sep: s1 1000 + s2 1500 + s6 2500 + s7 2000                              =  7000
     *
     *     12200 + new 1500 + expansion 500 − contraction 1000 − churn 2000 − paused 4200 = 7000
     */
    protected function august(): void
    {
        $this->subscription(['email' => 's1@x.de', 'amount_cent' => 1000, 'starts_at' => '2026-06-05 09:00:00']);
        $this->subscription(['email' => 's2@x.de', 'amount_cent' => 1500, 'starts_at' => '2026-08-10 09:00:00']);
        $this->subscription([
            'email' => 's3@x.de', 'amount_cent' => 2000, 'starts_at' => '2026-05-01 09:00:00',
            'status' => 'cancelled', 'cancelled_at' => '2026-08-20 10:00:00', 'ended_at' => '2026-08-20 10:00:00',
        ]);
        // A pause, in whatever word payments ends up writing for it.
        $this->subscription([
            'email' => 's4@x.de', 'amount_cent' => 3000, 'starts_at' => '2026-04-01 09:00:00',
            'status' => 'paused', 'ended_at' => '2026-08-15 10:00:00', 'updated_at' => '2026-08-15 10:00:00',
        ]);
        // A status nobody told this addon about is held, not churned.
        $this->subscription([
            'email' => 's5@x.de', 'amount_cent' => 500, 'starts_at' => '2026-03-01 09:00:00',
            'status' => 'on_hold', 'updated_at' => '2026-08-12 10:00:00',
        ]);

        // Price went up from the August cycle on. The checkout carried a bump
        // and is not the price of the agreement.
        $s6 = $this->subscription(['email' => 's6@x.de', 'amount_cent' => 2500, 'starts_at' => '2026-02-01 09:00:00']);
        $this->cycle($s6, '2026-02-01 09:00:00', 9900);
        foreach (['03', '04', '05', '06', '07'] as $m) {
            $this->cycle($s6, "2026-{$m}-01 09:00:00", 2000);
        }
        $this->cycle($s6, '2026-08-01 10:00:00', 2500);
        $this->cycle($s6, '2026-09-01 10:00:00', 2500);

        // Price went down from the August cycle on.
        $s7 = $this->subscription(['email' => 's7@x.de', 'amount_cent' => 2000, 'starts_at' => '2026-01-05 09:00:00']);
        $this->cycle($s7, '2026-01-05 09:00:00', 5000);
        foreach (['02', '03', '04', '05', '06', '07'] as $m) {
            $this->cycle($s7, "2026-{$m}-05 09:00:00", 3000);
        }
        $this->cycle($s7, '2026-08-05 09:00:00', 2000);

        // The card failed; the provider stopped charging. Held, not churned.
        $this->subscription([
            'email' => 's8@x.de', 'amount_cent' => 700, 'starts_at' => '2026-02-01 09:00:00',
            'status' => 'suspended', 'dunning_started_at' => '2026-08-25 10:00:00', 'ended_at' => '2026-08-26 10:00:00',
        ]);
    }

    #[Test]
    public function the_movements_of_a_month_reconcile_the_mrr_at_both_ends(): void
    {
        $this->august();

        $bewegung = $this->figures()->movements(
            Carbon::parse('2026-08-01 00:00:00'),
            Carbon::parse('2026-09-01 00:00:00'),
            'EUR',
        );

        $this->assertSame([
            'mrr_start' => 12200,
            'new' => 1500,
            'reactivation' => 0,
            'expansion' => 500,
            'contraction' => 1000,
            'churn' => 2000,
            'paused' => 3000 + 500 + 700,
            'net' => 1500 + 500 - 1000 - 2000 - 4200,
            'mrr_end' => 7000,
        ], $bewegung);
    }

    #[Test]
    public function churn_counts_ended_customers_and_lost_revenue_but_not_pauses(): void
    {
        $this->august();

        $churn = $this->figures()->churn(
            Carbon::parse('2026-08-01 00:00:00'),
            Carbon::parse('2026-09-01 00:00:00'),
            'EUR',
        );

        // Seven customers paying on 1 Aug (s1, s3–s8); only s3 left for good.
        $this->assertSame(7, $churn['customers_start']);
        $this->assertSame(1, $churn['customers_churned']);
        $this->assertSame(round(1 / 7 * 100, 1), $churn['customer_rate']);
        // Gross revenue churn: churned plus contraction, over the MRR at the start.
        $this->assertSame(round((2000 + 1000) / 12200 * 100, 1), $churn['revenue_rate']);
    }

    /**
     * Joined in June at 20, went up to 25 in August. Over June to August that is
     * new 20 and expansion 5, not new 25: compared only end to end, every price
     * change of everybody who joined inside a long window would vanish.
     */
    #[Test]
    public function a_long_window_keeps_the_price_changes_of_those_who_joined_inside_it(): void
    {
        $id = $this->subscription(['amount_cent' => 2500, 'starts_at' => '2026-06-10 09:00:00']);
        $this->cycle($id, '2026-06-10 09:00:00', 2000);
        $this->cycle($id, '2026-07-10 09:00:00', 2000);
        $this->cycle($id, '2026-08-10 09:00:00', 2500);

        $bewegung = $this->figures()->movements(
            Carbon::parse('2026-06-01 00:00:00'),
            Carbon::parse('2026-09-01 00:00:00'),
            'EUR',
        );

        $this->assertSame(2000, $bewegung['new']);
        $this->assertSame(500, $bewegung['expansion']);
        $this->assertSame(2500, $bewegung['mrr_end']);
    }

    /**
     * July: four paying, one leaves (25 %). August: three paying, none leaves
     * (0 %). The rate for the two months is the mean, 12.5 %, not 1 of 4.
     */
    #[Test]
    public function churn_over_several_months_is_the_mean_monthly_rate(): void
    {
        foreach (['a', 'b', 'c'] as $wer) {
            $this->subscription(['email' => "{$wer}@x.de", 'amount_cent' => 1000, 'starts_at' => '2026-05-01 09:00:00']);
        }
        $this->subscription([
            'email' => 'd@x.de', 'amount_cent' => 1000, 'starts_at' => '2026-05-01 09:00:00',
            'status' => 'cancelled', 'ended_at' => '2026-07-20 09:00:00',
        ]);

        $churn = $this->figures()->churn(
            Carbon::parse('2026-07-01 00:00:00'),
            Carbon::parse('2026-09-01 00:00:00'),
            'EUR',
        );

        $this->assertSame(12.5, $churn['customer_rate']);
        $this->assertSame(1, $churn['customers_churned']);
        // Revenue: 1000 of 4000 in July, nothing of 3000 in August.
        $this->assertSame(12.5, $churn['revenue_rate']);
    }

    /**
     * 25 Aug to 5 Sep: seven days of August (31 days long), four of September
     * (30). Ten paying; one leaves on 28 Aug, nobody in September.
     *
     * Each piece is scaled to its month and weighted by its days:
     *     (10 % × 31 + 0 % × 30) / (7 + 4) = 3.1 / 11 = 28.2 %
     *
     * The unweighted mean of the two pieces would say 5 %: seven days that
     * lost one in ten, counted like four days that lost nobody, and neither
     * scaled to a month.
     */
    #[Test]
    public function a_short_window_is_scaled_to_a_month_and_its_pieces_weighted_by_days(): void
    {
        foreach (range(1, 9) as $i) {
            $this->subscription(['email' => "k{$i}@x.de", 'amount_cent' => 1000, 'starts_at' => '2026-05-01 09:00:00']);
        }
        $this->subscription([
            'email' => 'weg@x.de', 'amount_cent' => 1000, 'starts_at' => '2026-05-01 09:00:00',
            'status' => 'cancelled', 'ended_at' => '2026-08-28 09:00:00',
        ]);

        $churn = $this->figures()->churn(
            Carbon::parse('2026-08-25 00:00:00'),
            Carbon::parse('2026-09-05 00:00:00'),
            'EUR',
        );

        // Compounded, not linear: 1 − 0.9^(31/7) = 37.3 % for the August week,
        // 0 for the September days, weighted by days: 37.3 × 7 / 11 = 23.7 %.
        $august = 1 - (1 - 0.1) ** (31 / 7);
        $erwartet = round(($august * 7 + 0 * 4) / (7 + 4) * 100, 1);

        $this->assertSame(23.7, $erwartet);
        $this->assertSame($erwartet, $churn['customer_rate']);
        $this->assertSame($erwartet, $churn['revenue_rate']);
    }

    /**
     * Three paying, two leave in the first week of August. Scaled linearly
     * that is 2/3 × 31/7 = 295.2 % "per month", a share of customers larger
     * than all of them. Compounded: 1 − (1/3)^(31/7) = 99.2 %.
     */
    #[Test]
    public function a_monthly_rate_never_exceeds_everybody(): void
    {
        $this->subscription(['email' => 'bleibt@x.de', 'amount_cent' => 1000, 'starts_at' => '2026-05-01 09:00:00']);
        foreach (['a', 'b'] as $wer) {
            $this->subscription([
                'email' => "{$wer}@x.de", 'amount_cent' => 1000, 'starts_at' => '2026-05-01 09:00:00',
                'status' => 'cancelled', 'ended_at' => '2026-08-04 09:00:00',
            ]);
        }

        $churn = $this->figures()->churn(
            Carbon::parse('2026-08-01 00:00:00'),
            Carbon::parse('2026-08-08 00:00:00'),
            'EUR',
        );

        $this->assertSame(99.2, round((1 - (1 / 3) ** (31 / 7)) * 100, 1));
        $this->assertSame(99.2, $churn['customer_rate']);
        $this->assertSame(99.2, $churn['revenue_rate']);
        $this->assertLessThanOrEqual(100.0, $churn['customer_rate']);
    }

    /**
     * Paused 10 June, resumed 20 July, running since. `statamic-payments`
     * clears `paused_at` and `ended_at` on resuming and keeps the window in
     * `meta.pauses`; the row alone would say it never stopped.
     */
    protected function pausedAndBack(): void
    {
        $this->subscription([
            'email' => 'pause@x.de', 'amount_cent' => 1000, 'starts_at' => '2026-03-01 09:00:00',
            'meta' => json_encode(['pauses' => [
                ['paused_at' => '2026-06-10T09:00:00+00:00', 'resumed_at' => '2026-07-20T09:00:00+00:00', 'mode' => 'recreate', 'by' => 'portal'],
            ]]),
        ]);
    }

    #[Test]
    public function a_past_pause_takes_the_subscription_out_of_mrr_for_exactly_its_window(): void
    {
        $this->pausedAndBack();
        $figures = $this->figures();

        $this->assertSame(1000, $figures->mrr(Carbon::parse('2026-06-09 00:00:00'), 'EUR'));
        $this->assertSame(0, $figures->mrr(Carbon::parse('2026-06-20 00:00:00'), 'EUR'));
        $this->assertSame(1000, $figures->mrr(Carbon::parse('2026-07-25 00:00:00'), 'EUR'));
    }

    #[Test]
    public function going_into_a_pause_and_coming_back_are_pause_movements_not_churn_or_new(): void
    {
        $this->pausedAndBack();
        $figures = $this->figures();

        $juni = $figures->movements(Carbon::parse('2026-06-01 00:00:00'), Carbon::parse('2026-07-01 00:00:00'), 'EUR');
        $juli = $figures->movements(Carbon::parse('2026-07-01 00:00:00'), Carbon::parse('2026-08-01 00:00:00'), 'EUR');

        $this->assertSame(0, $juni['churn']);
        $this->assertSame(1000, $juni['paused']);
        $this->assertSame(0, $juli['new']);
        $this->assertSame(0, $juli['reactivation']);
        // Coming back is the pause undone: the pause column goes negative.
        $this->assertSame(-1000, $juli['paused']);
        $this->assertSame(1000, $juli['net']);

        $churn = $figures->churn(Carbon::parse('2026-06-01 00:00:00'), Carbon::parse('2026-07-01 00:00:00'), 'EUR');
        $this->assertSame(0, $churn['customers_churned']);
        $this->assertSame(1, $churn['customers_paused']);
    }

    #[Test]
    public function a_cohort_counts_a_subscription_in_a_past_pause_as_still_there(): void
    {
        $this->pausedAndBack();

        // Started 1 Mar; three months on is 1 Jun (running), four is 1 Jul (paused).
        $kohorte = $this->row($this->figures()->cohorts(), 'cohort', '2026-03');

        $this->assertSame(100.0, $kohorte['m3']);
        $this->assertSame(100.0, $kohorte['m6']); // 1 Sep, running again
    }

    #[Test]
    public function a_subscription_cancelled_while_paused_stopped_paying_when_the_pause_began(): void
    {
        $this->subscription([
            'amount_cent' => 1000, 'starts_at' => '2026-03-01 09:00:00',
            'status' => 'cancelled', 'paused_at' => '2026-06-10 09:00:00', 'ended_at' => '2026-08-01 09:00:00',
        ]);
        // Paused now, with the column the payments addon writes.
        $this->subscription([
            'amount_cent' => 2000, 'starts_at' => '2026-03-01 09:00:00',
            'status' => 'paused', 'paused_at' => '2026-08-10 09:00:00', 'updated_at' => '2026-09-01 09:00:00',
        ]);

        $figures = $this->figures();

        $this->assertSame(2000, $figures->mrr(Carbon::parse('2026-07-01 00:00:00'), 'EUR'));
        $this->assertSame(0, $figures->mrr(Carbon::parse('2026-08-20 00:00:00'), 'EUR'));
    }

    #[Test]
    public function a_customer_who_comes_back_on_a_new_agreement_is_a_reactivation(): void
    {
        $this->subscription([
            'email' => 'Wieder@X.de', 'amount_cent' => 1000, 'starts_at' => '2026-02-01 09:00:00',
            'status' => 'cancelled', 'ended_at' => '2026-05-01 09:00:00',
        ]);
        $this->subscription(['email' => 'wieder@x.de ', 'amount_cent' => 1200, 'starts_at' => '2026-08-03 09:00:00']);

        $bewegung = $this->figures()->movements(
            Carbon::parse('2026-08-01 00:00:00'),
            Carbon::parse('2026-09-01 00:00:00'),
            'EUR',
        );

        $this->assertSame(0, $bewegung['new']);
        $this->assertSame(1200, $bewegung['reactivation']);
    }

    #[Test]
    public function an_unknown_status_is_named_so_the_screen_can_say_how_it_was_counted(): void
    {
        $this->august();

        $this->assertSame(['on_hold'], $this->figures()->unrecognisedStatuses());
    }

    #[Test]
    public function retention_follows_each_cohort_month_after_month_and_counts_a_pause_as_still_there(): void
    {
        // Four agreements started in January 2026.
        $this->subscription(['email' => 'a@x.de', 'starts_at' => '2026-01-10 09:00:00']);
        $this->subscription(['email' => 'b@x.de', 'starts_at' => '2026-01-10 09:00:00', 'status' => 'cancelled', 'ended_at' => '2026-02-15 09:00:00']);
        $this->subscription(['email' => 'c@x.de', 'starts_at' => '2026-01-10 09:00:00', 'status' => 'paused', 'updated_at' => '2026-03-05 09:00:00']);
        $this->subscription(['email' => 'd@x.de', 'starts_at' => '2026-01-10 09:00:00', 'status' => 'cancelled', 'ended_at' => '2026-06-20 09:00:00']);

        $kohorte = $this->row($this->figures()->cohorts(), 'cohort', '2026-01');

        $this->assertNotNull($kohorte);
        $this->assertSame('EUR', $kohorte['currency']);
        $this->assertSame(4, $kohorte['started']);
        $this->assertSame(100.0, $kohorte['m1']);   // 10 Feb: all four
        $this->assertSame(75.0, $kohorte['m2']);    // 10 Mar: b ended
        $this->assertSame(75.0, $kohorte['m3']);    // 10 Apr
        $this->assertSame(50.0, $kohorte['m6']);    // 10 Jul: d ended too
        $this->assertNull($kohorte['m12']);         // 10 Jan 2027 has not happened
    }

    /**
     * Now is 15 Sep 12:00, the window closes 15 Oct 12:00.
     */
    #[Test]
    public function the_charges_due_in_the_next_thirty_days_come_from_every_running_agreement(): void
    {
        $this->subscription(['amount_cent' => 1900, 'next_payment_at' => '2026-09-20 09:00:00']);
        // Weekly: 16, 23, 30 Sep, 7 and 14 Oct.
        $this->subscription(['amount_cent' => 1000, 'interval' => '1 week', 'next_payment_at' => '2026-09-16 09:00:00']);
        // A plan with one instalment left, and one with two left of which one falls inside.
        $this->subscription(['amount_cent' => 39900, 'times' => 3, 'times_charged' => 2, 'next_payment_at' => '2026-09-18 09:00:00']);
        $this->subscription(['amount_cent' => 39900, 'times' => 3, 'times_charged' => 1, 'next_payment_at' => '2026-09-17 09:00:00']);
        // A trial with no date of its own is charged when it starts.
        $this->subscription(['amount_cent' => 14900, 'status' => 'pending', 'starts_at' => '2026-09-25 09:00:00', 'next_payment_at' => null]);
        // Held and ended agreements are charged nothing.
        $this->subscription(['amount_cent' => 5000, 'status' => 'paused', 'next_payment_at' => '2026-09-20 09:00:00']);
        $this->subscription(['amount_cent' => 5000, 'status' => 'cancelled', 'next_payment_at' => '2026-09-20 09:00:00']);
        $this->subscription(['amount_cent' => 2000, 'currency' => 'CHF', 'next_payment_at' => '2026-10-01 09:00:00']);

        $faellig = $this->figures()->upcoming(30);

        $eur = array_values(array_filter($faellig, fn ($c) => $c['currency'] === 'EUR'));
        $chf = array_values(array_filter($faellig, fn ($c) => $c['currency'] === 'CHF'));

        $this->assertCount(1 + 5 + 1 + 1 + 1, $eur);
        $this->assertSame(1900 + 5 * 1000 + 39900 + 39900 + 14900, array_sum(array_column($eur, 'amount_cent')));
        $this->assertSame(39900 + 39900, array_sum(array_column(
            array_filter($eur, fn ($c) => $c['kind'] === 'plan'),
            'amount_cent',
        )));
        $this->assertSame([2000], array_column($chf, 'amount_cent'));
        // In date order.
        $this->assertSame('2026-09-16 09:00:00', $faellig[0]['at']);
    }

    #[Test]
    public function the_forecast_rolls_each_running_agreement_forward_and_stops_a_plan_at_its_last_instalment(): void
    {
        $this->subscription(['amount_cent' => 1900, 'next_payment_at' => '2026-09-20 09:00:00']);
        $this->subscription(['amount_cent' => 39900, 'interval' => '3 months', 'next_payment_at' => '2026-11-01 09:00:00']);
        $this->subscription(['amount_cent' => 10000, 'times' => 3, 'times_charged' => 1, 'next_payment_at' => '2026-10-01 09:00:00']);

        $prognose = $this->figures()->forecast(12);

        $september = $this->row($prognose, 'month', '2026-09');
        $oktober = $this->row($prognose, 'month', '2026-10');
        $november = $this->row($prognose, 'month', '2026-11');
        $dezember = $this->row($prognose, 'month', '2026-12');

        $this->assertSame(1900, $september['subscriptions_cent']);
        $this->assertSame(0, $september['plans_cent']);
        $this->assertSame(1900, $oktober['subscriptions_cent']);
        $this->assertSame(10000, $oktober['plans_cent']);
        $this->assertSame(1900 + 39900, $november['subscriptions_cent']);
        $this->assertSame(10000, $november['plans_cent']);
        // Two of three were due; the plan is done after November.
        $this->assertSame(0, $dezember['plans_cent']);

        // Twelve months from now: 12 monthly charges, quarterly on 1 Nov, 1 Feb, 1 May, 1 Aug.
        $this->assertSame(12 * 1900 + 4 * 39900 + 2 * 10000, $this->figures()->forecastTotal(12, 'EUR'));
    }
}
