<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Website;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\MetricRegistry;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class WebsiteMetricTest extends WebsiteTestCase
{
    #[Test]
    public function it_registers_the_six_website_figures(): void
    {
        $registry = app(MetricRegistry::class);

        foreach (['visitors', 'sessions', 'pageviews', 'bounce_rate', 'pages_per_session', 'session_duration'] as $name) {
            $this->assertNotNull($registry->find('website.'.$name), "website.{$name} was not registered");
        }

        $this->assertSame(Unit::DURATION, $registry->find('website.session_duration')->unit());
    }

    #[Test]
    public function it_reads_the_figure_the_service_reports(): void
    {
        $this->fakeService(totals: ['users' => 19, 'sessions' => 20, 'pageviews' => 88]);

        $this->assertSame(19, $this->value('website.visitors'));
        $this->assertSame(88, $this->value('website.pageviews'));
    }

    #[Test]
    public function it_rounds_what_the_service_hands_back_at_full_precision(): void
    {
        $this->fakeService(totals: [
            'sessions' => 56,
            'session_duration' => 9.946428571428571,
            'pages_per_session' => 3.1607142857142856,
            'bounce_rate' => 51.78571428571429,
        ]);

        $this->assertSame(10, $this->value('website.session_duration'));
        $this->assertSame(3.16, $this->value('website.pages_per_session'));
        $this->assertSame(51.8, $this->value('website.bounce_rate'));
    }

    /**
     * The house rule from the metric contract: a rate over nothing has no
     * answer, and 0 % beside "0 sessions" is a statement its neighbour
     * contradicts.
     */
    #[Test]
    public function a_rate_over_no_sessions_is_null_and_not_zero(): void
    {
        $this->fakeService(totals: ['users' => 0, 'sessions' => 0, 'pageviews' => 0]);

        $this->assertNull($this->value('website.bounce_rate'));
        $this->assertNull($this->value('website.pages_per_session'));
        $this->assertNull($this->value('website.session_duration'));

        // A count over nothing is a real zero, and stays one.
        $this->assertSame(0, $this->value('website.visitors'));
    }

    #[Test]
    public function it_keys_the_series_by_bucket(): void
    {
        $this->fakeService(buckets: [
            $this->bucket('2026-09-01 00:00:00', users: 3, sessions: 4, pageviews: 9),
            $this->bucket('2026-09-02 00:00:00', users: 5, sessions: 6, pageviews: 12),
        ]);

        $series = app(MetricRegistry::class)
            ->find('website.visitors')
            ->series(new MetricQuery(Period::fromPreset('7d')));

        $this->assertSame(['2026-09-01' => 3, '2026-09-02' => 5], $series);
    }

    /**
     * The same rule as `value()`, in the place it was broken first: a bucket
     * with no sessions has no bounce rate, and the reader keeps a null while
     * it turns a missing bucket into a zero.
     */
    #[Test]
    public function a_bucket_with_no_sessions_has_no_rate(): void
    {
        $this->fakeService(buckets: [
            $this->bucket('2026-09-01 00:00:00'),
            $this->bucket('2026-09-02 00:00:00', users: 5, sessions: 4, pageviews: 12, bounce: 25.0),
        ]);

        $series = $this->series('website.bounce_rate', '7d');

        $this->assertNull($series['2026-09-01']);
        $this->assertSame(25.0, $series['2026-09-02']);
    }

    /**
     * The trim belongs to the open-ended case alone. Dropped for a bounded
     * period, an empty first day would be filled back in with a zero by the
     * reader — and the chart would then answer the same state two ways: 0 %
     * at the edge, no bar in the middle.
     */
    #[Test]
    public function a_bounded_period_keeps_its_empty_first_days(): void
    {
        $this->fakeService(buckets: [
            $this->bucket('2026-09-01 00:00:00'),
            $this->bucket('2026-09-02 00:00:00'),
            $this->bucket('2026-09-03 00:00:00', users: 5, sessions: 4, pageviews: 12),
        ]);

        $this->assertSame(
            ['2026-09-01', '2026-09-02', '2026-09-03'],
            array_keys($this->series('website.visitors', '7d')),
        );
    }

    /**
     * A figure the service simply did not send is not a figure of nought.
     * `bounce_rate` missing beside twenty sessions would otherwise print
     * "0,0 %", which the guard on the derived figures cannot catch — there is
     * a denominator, so the question does apply.
     */
    #[Test]
    public function a_figure_the_service_left_out_is_not_a_nought(): void
    {
        $this->fakeService(totals: ['users' => 12, 'sessions' => 20, 'bounce_rate' => null]);

        $this->assertNull($this->value('website.bounce_rate'));
        $this->assertSame(12, $this->value('website.visitors'));
    }

    /**
     * The window contract this service actually honours, and the reason it is
     * written down: `?past=7d` is accepted and ignored, and an explicit range
     * is silently dropped unless `time_zone` travels with it. Both failures
     * look like a plausible number, which is why they are asserted here rather
     * than trusted.
     */
    #[Test]
    public function it_always_sends_an_explicit_window_with_a_timezone(): void
    {
        $this->fakeService(totals: ['users' => 1]);

        $this->value('website.visitors');

        Http::assertSent(function ($request) {
            $query = [];
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return isset($query['start_date'], $query['end_date'])
                && $query['time_zone'] === 'Europe/Berlin'
                && ! isset($query['past']);
        });
    }

    #[Test]
    public function an_open_ended_period_asks_for_no_window_at_all(): void
    {
        $this->fakeService(totals: ['users' => 53]);

        $metric = app(MetricRegistry::class)->find('website.visitors');
        $this->assertSame(53, $metric->value(new MetricQuery(Period::fromPreset('all'))));

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'start_date'));
    }

    /**
     * The bucketed endpoint cannot say "all time": without a window it answers
     * two rows with `time: null`, which is not a series. So the series asks
     * from a floor and throws away the empty months in front of the data —
     * otherwise every all-time chart opens with years of nothing.
     */
    #[Test]
    public function an_open_ended_series_is_floored_and_trimmed(): void
    {
        $this->fakeService(buckets: [
            $this->bucket('2020-01-01 00:00:00'),
            $this->bucket('2020-02-01 00:00:00'),
            $this->bucket('2026-08-01 00:00:00', users: 7, sessions: 8, pageviews: 20),
            $this->bucket('2026-09-01 00:00:00', users: 9, sessions: 9, pageviews: 30),
        ]);

        $series = app(MetricRegistry::class)
            ->find('website.visitors')
            ->series(new MetricQuery(Period::fromPreset('all'), MetricQuery::BUCKET_MONTH));

        $this->assertSame(['2026-08' => 7, '2026-09' => 9], $series);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'overview-bucketed')
            || str_contains($request->url(), 'start_date=2020-01-01'));
    }

    /**
     * Six figures and their charts on one screen must not be eighteen requests.
     * The client is a singleton and remembers what it already asked.
     */
    #[Test]
    public function the_same_question_is_asked_of_the_service_once(): void
    {
        $this->fakeService(totals: ['users' => 4, 'sessions' => 4]);

        $query = new MetricQuery(Period::fromPreset('30d'));
        $registry = app(MetricRegistry::class);

        foreach (['visitors', 'sessions', 'pageviews', 'bounce_rate'] as $name) {
            $registry->find('website.'.$name)->value($query);
        }

        Http::assertSentCount(1);
    }

    /** @return array<string, int|float|null> */
    protected function series(string $handle, string $preset = '30d'): array
    {
        $period = Period::fromPreset($preset);

        return app(MetricRegistry::class)
            ->find($handle)
            ->series(new MetricQuery($period, MetricQuery::bucketFor($period)));
    }

    protected function value(string $handle, string $preset = '30d'): int|float|null
    {
        return app(MetricRegistry::class)
            ->find($handle)
            ->value(new MetricQuery(Period::fromPreset($preset)));
    }
}
