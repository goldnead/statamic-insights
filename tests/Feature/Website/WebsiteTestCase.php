<?php

namespace Goldnead\StatamicInsights\Tests\Feature\Website;

use Goldnead\StatamicInsights\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * A configured website group, talking to a service that is not there.
 *
 * Every answer is faked. The point of these tests is the translation between
 * what the service says and what a screen shows — the rounding, the nulls, the
 * window parameters — and none of that needs a network.
 */
abstract class WebsiteTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.timezone', 'Europe/Berlin');
        $app['config']->set('statamic-insights.website', [
            'url' => 'https://analytics.example',
            'key' => 'rb_test',
            'site_id' => '7',
            'timezone' => null,
            // Off, so one test cannot answer another test's question out of a
            // store both of them share.
            'cache' => 0,
            'timeout' => 8,
            'verify' => true,
        ]);
    }

    /**
     * @param  array<string, float|int>  $totals
     * @param  array<int, array<string, mixed>>  $buckets
     * @param  array<int, array<string, mixed>>  $top
     */
    protected function fakeService(array $totals = [], array $buckets = [], array $top = []): void
    {
        Http::fake([
            '*/overview-bucketed*' => Http::response(['data' => $buckets]),
            '*/overview*' => Http::response(['data' => $totals + [
                'users' => 0, 'sessions' => 0, 'pageviews' => 0,
                'bounce_rate' => 0, 'pages_per_session' => 0, 'session_duration' => 0,
            ]]),
            '*/metric*' => Http::response(['data' => ['data' => $top, 'totalCount' => count($top)]]),
        ]);
    }

    /** A service that answers, but not with an answer. */
    protected function fakeRubbish(int $status = 200, string $body = '<html>Please sign in</html>'): void
    {
        Http::fake(['*' => Http::response($body, $status, ['Content-Type' => 'text/html'])]);
    }

    /**
     * One bucket row in the shape the service sends it.
     *
     * @return array<string, mixed>
     */
    protected function bucket(string $time, int $users = 0, int $sessions = 0, int $pageviews = 0, float $bounce = 0.0): array
    {
        return [
            'time' => $time,
            'users' => $users,
            'sessions' => $sessions,
            'pageviews' => $pageviews,
            'bounce_rate' => $bounce,
            'pages_per_session' => $sessions > 0 ? $pageviews / $sessions : 0,
            'session_duration' => 0,
        ];
    }
}
