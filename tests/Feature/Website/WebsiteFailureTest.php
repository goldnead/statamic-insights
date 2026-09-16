<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Website;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\MetricReader;
use Goldnead\StatamicInsights\Support\MetricRegistry;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\ReportReader;
use Goldnead\StatamicInsights\Support\ReportRegistry;
use Goldnead\StatamicInsights\Website\Rybbit;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * What happens when the service on the other end is not answering properly.
 *
 * The one failure mode that matters for a figure read over a network: a screen
 * that says "0 visitors" when the truth is "nobody asked anything successfully".
 * Both have the same shape and only one of them is a measurement.
 */
class WebsiteFailureTest extends WebsiteTestCase
{
    #[Test]
    public function a_service_that_is_down_is_not_a_measurement_of_zero(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->expectException(RequestException::class);

        $this->visitors();
    }

    /**
     * The failure the status code does not catch: an instance behind an access
     * proxy answers 200 with a login page. `json()` makes that null, and
     * without the guard the null becomes an empty array, then six zeroes.
     */
    #[Test]
    public function a_200_that_is_not_an_answer_is_not_a_measurement_of_zero(): void
    {
        $this->fakeRubbish();

        $this->expectException(RuntimeException::class);

        $this->visitors();
    }

    /** Same question, asked of the tables rather than the figures. */
    #[Test]
    public function a_broken_answer_breaks_the_table_too_rather_than_emptying_it(): void
    {
        $this->fakeRubbish();

        $this->expectException(RuntimeException::class);

        app(ReportRegistry::class)
            ->find('website.top_pages')
            ->rows(new MetricQuery(Period::fromPreset('30d')));
    }

    /**
     * And the two readers turn that into something a person can see: the
     * figure drops off the list with a line in the log, the table stays on the
     * screen and says it failed. Neither prints a zero.
     */
    #[Test]
    public function the_readers_say_so_rather_than_printing_a_nought(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        Log::spy();

        $query = new MetricQuery(Period::fromPreset('30d'));

        $this->assertNull(
            app(MetricReader::class)->read(app(MetricRegistry::class)->find('website.visitors'), $query)
        );

        $table = app(ReportReader::class)->read(app(ReportRegistry::class)->find('website.top_pages'), $query);

        $this->assertTrue($table['failed']);
        $this->assertSame([], $table['rows']);

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    /**
     * Nothing that failed is kept. `Cache::remember` never reaches its store
     * when the closure throws, so a service that is down for a minute does not
     * become a screen that is empty for the whole cache lifetime.
     */
    #[Test]
    public function a_failure_is_not_remembered(): void
    {
        config(['statamic-insights.website.cache' => 300]);
        Cache::flush();

        // One stub, two answers in order: the outage, then the recovery. A
        // second `Http::fake()` would be added behind the first and never
        // reached — the wildcard already there keeps winning.
        Http::fake(['*' => Http::sequence()
            ->push('', 500)
            ->push(['data' => ['users' => 7, 'sessions' => 7, 'pageviews' => 9]]),
        ]);

        try {
            $this->visitors();
        } catch (RequestException) {
            // expected
        }

        // A second client, because the first one remembers within the request
        // and would answer out of its own memo rather than out of the cache.
        $this->assertSame(7, (new Rybbit)->overview(new MetricQuery(Period::fromPreset('30d')))['users']);
    }

    /**
     * The cache key carries the window. Without the parameters in it, the
     * seven-day answer would be handed to the thirty-day question — a wrong
     * number that looks entirely reasonable.
     */
    #[Test]
    public function two_different_windows_are_two_different_questions(): void
    {
        config(['statamic-insights.website.cache' => 300]);
        Cache::flush();
        $this->fakeService(totals: ['users' => 5, 'sessions' => 5]);

        $client = new Rybbit;
        $client->overview(new MetricQuery(Period::fromPreset('7d')));
        $client->overview(new MetricQuery(Period::fromPreset('30d')));

        Http::assertSentCount(2);

        // And the same window twice is one question, even from a client with
        // no memory of its own.
        (new Rybbit)->overview(new MetricQuery(Period::fromPreset('7d')));

        Http::assertSentCount(2);
    }

    protected function visitors(): int|float|null
    {
        return app(MetricRegistry::class)
            ->find('website.visitors')
            ->value(new MetricQuery(Period::fromPreset('30d')));
    }
}
