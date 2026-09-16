<?php

namespace Goldnead\StatamicInsights\Website;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * What the analytics service knows about the site in front of it.
 *
 * The one place in this addon that talks to something over the network. Every
 * website metric and every website report reads through here, so the quirks of
 * the service are written down once instead of being rediscovered by the next
 * question somebody asks it.
 *
 * Self-hosted Rybbit is the service it speaks to. The configuration keys are
 * neutral (`url`, `key`, `site_id`) because a second service would answer the
 * same three questions — a total, a series and a top list — and the screens
 * above this class do not care which one answered.
 *
 * **Three things about this API that are not obvious and cost an afternoon each:**
 *
 * 1. `?past=7d` is accepted and ignored. Every window has to be an explicit
 *    `start_date`/`end_date` pair, and the pair is *silently dropped* unless
 *    `time_zone` is sent with it — leaving all-time totals that look like a
 *    plausible week.
 * 2. Without a window, `/overview` is correctly all-time but
 *    `/overview-bucketed` returns two rows with `time: null`, which is not a
 *    series at all. So "all time" asks the first endpoint with no window and
 *    the second one with {@see FLOOR} as its start.
 * 3. Bot filtering is on per site. A headless browser sending the default user
 *    agent produces no event, which looks exactly like a broken snippet.
 */
class Rybbit
{
    /**
     * Where an open-ended period starts when a chart needs a first bucket.
     *
     * "All time" has no start, and the bucketed endpoint needs one anyway. The
     * empty months before the first real one are trimmed off again in
     * {@see series}, so the floor decides nothing a reader sees — it only has
     * to be older than any data this service can hold.
     */
    private const FLOOR = '2020-01-01';

    /** Answers already fetched in this request, keyed by the URL that produced them. */
    private array $memo = [];

    public static function configured(): bool
    {
        return self::url() !== null
            && self::setting('key') !== null
            && self::siteId() !== null;
    }

    /**
     * The totals for the window: users, sessions, pageviews, bounce_rate,
     * pages_per_session, session_duration.
     *
     * @return array<string, float|int>
     */
    public function overview(MetricQuery $query): array
    {
        $payload = $this->get('overview', $this->window($query->period));

        return $this->figures($payload['data'] ?? []);
    }

    /**
     * The same totals per bucket, keyed `Y-m-d` or `Y-m` after `$query->bucket`.
     *
     * Leading empty buckets are dropped rather than drawn: for an open-ended
     * period they are the months before the site existed, and a chart that
     * opens with two years of floor is a chart nobody reads to the end.
     *
     * @return array<string, array<string, float|int>>
     */
    public function series(MetricQuery $query): array
    {
        $monthly = $query->bucket === MetricQuery::BUCKET_MONTH;

        $payload = $this->get('overview-bucketed', [
            'bucket' => $monthly ? 'month' : 'day',
        ] + $this->window($query->period, floor: true));

        $rows = [];

        foreach ($payload['data'] ?? [] as $row) {
            $time = (string) ($row['time'] ?? '');

            if ($time === '') {
                continue;
            }

            $rows[substr($time, 0, $monthly ? 7 : 10)] = $this->figures($row);
        }

        ksort($rows);

        foreach ($rows as $bucket => $figures) {
            if ($figures['pageviews'] > 0 || $figures['users'] > 0 || $figures['sessions'] > 0) {
                break;
            }

            unset($rows[$bucket]);
        }

        return $rows;
    }

    /**
     * The top values of one dimension — `pathname`, `referrer`, `country`,
     * `device_type`, `browser`, `operating_system`, `entry_page`.
     *
     * @return array<int, array{value: string, visitors: int, pageviews: int, bounce_rate: float|null}>
     */
    public function top(MetricQuery $query, string $parameter, int $limit = 20): array
    {
        $payload = $this->get('metric', [
            'parameter' => $parameter,
            'limit' => $limit,
        ] + $this->window($query->period));

        // Two levels of `data`, and that is the service's shape rather than a
        // typo: the outer one is the envelope every route has, the inner one
        // sits beside `totalCount`.
        $rows = $payload['data']['data'] ?? [];

        return array_values(array_map(fn (array $row): array => [
            'value' => (string) ($row['value'] ?? ''),
            'visitors' => (int) ($row['count'] ?? 0),
            'pageviews' => (int) ($row['pageviews'] ?? 0),
            // A dimension without a bounce rate says nothing rather than zero:
            // 0 % is an excellent result and "not measured here" is not.
            'bounce_rate' => isset($row['bounce_rate']) ? round((float) $row['bounce_rate'], 1) : null,
        ], $rows));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function get(string $path, array $params): array
    {
        $url = rtrim((string) self::url(), '/').'/api/sites/'.self::siteId().'/'.$path;
        $key = $url.'?'.http_build_query($params);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $ttl = (int) config('statamic-insights.website.cache', 300);

        // `remember` and not `put`: a request that throws never reaches the
        // store, so a service that is down for a minute does not become a
        // screen that is empty for the whole cache lifetime.
        $answer = $ttl > 0
            ? Cache::remember('insights.website.'.md5($key), $ttl, fn () => $this->fetch($url, $params))
            : $this->fetch($url, $params);

        return $this->memo[$key] = $answer;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function fetch(string $url, array $params): array
    {
        return $this->request()->get($url, $params)->throw()->json() ?? [];
    }

    protected function request(): PendingRequest
    {
        return Http::withToken((string) self::setting('key'))
            ->timeout((int) config('statamic-insights.website.timeout', 8))
            // A self-hosted instance behind a Cloudflare origin certificate
            // presents a chain that is trusted between Cloudflare and the
            // origin and nowhere else. Verification stays on by default and is
            // a deliberate switch, not a default nobody chose.
            ->withOptions(['verify' => (bool) config('statamic-insights.website.verify', true)]);
    }

    /**
     * `start_date`, `end_date` and `time_zone` — or nothing at all.
     *
     * An open-ended period sends no window, which is how this API spells "all
     * time". `$floor` is for the one endpoint that cannot cope with that.
     *
     * @return array<string, string>
     */
    protected function window(Period $period, bool $floor = false): array
    {
        $from = $period->from;
        $to = $period->to;

        if ($from === null || $to === null) {
            if (! $floor) {
                return [];
            }

            $from = Carbon::parse(self::FLOOR);
            $to = Carbon::now();
        }

        return [
            'start_date' => $from->format('Y-m-d'),
            'end_date' => $to->format('Y-m-d'),
            'time_zone' => (string) (config('statamic-insights.website.timezone') ?: config('app.timezone', 'UTC')),
        ];
    }

    /**
     * The six figures every overview row carries, in the units the screens want.
     *
     * The service hands back full precision — a session duration of
     * `9.946428571428571` seconds — and a chart of those is a chart of noise.
     * Rounded here so that every caller rounds the same way.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, float|int>
     */
    protected function figures(array $row): array
    {
        return [
            'users' => (int) ($row['users'] ?? 0),
            'sessions' => (int) ($row['sessions'] ?? 0),
            'pageviews' => (int) ($row['pageviews'] ?? 0),
            'bounce_rate' => round((float) ($row['bounce_rate'] ?? 0), 1),
            'pages_per_session' => round((float) ($row['pages_per_session'] ?? 0), 2),
            'session_duration' => (int) round((float) ($row['session_duration'] ?? 0)),
        ];
    }

    protected static function url(): ?string
    {
        return self::setting('url');
    }

    protected static function siteId(): ?string
    {
        return self::setting('site_id');
    }

    /**
     * One configuration value, with blank read as unset.
     *
     * The site id arrives from the settings screen as a string and from the
     * environment as a string, and an operator who empties the field means
     * "no site", not "site zero".
     */
    protected static function setting(string $key): ?string
    {
        $value = config('statamic-insights.website.'.$key);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
