<?php

namespace Goldnead\StatamicInsights\Website;

use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * One figure from the website's own traffic.
 *
 * Six of these are registered, and they are six instances of this class rather
 * than six classes: every one of them asks the same endpoint the same question
 * and reads a different key out of the same answer. A subclass per figure would
 * be six files whose only difference is a string.
 *
 * This is the first thing Insights measures that no sibling addon owns. It is a
 * departure from "every number comes from the addon that owns the data", taken
 * for the reason named in the service provider: nobody owns the visitors, and
 * a site's traffic belongs beside its revenue rather than in a second tab.
 *
 * **The derived three answer null rather than zero when nothing happened.**
 * A bounce rate over no sessions, an average of pages over no sessions, a
 * session length over no sessions — none of those have an answer, and `0 %`
 * beside "0 sessions" is the quiet kind of wrong the contract warns about.
 */
class WebsiteMetric implements Metric
{
    /**
     * Figures that are an average or a rate over sessions, and therefore have
     * no answer in a window that had none.
     */
    private const DERIVED = ['bounce_rate', 'pages_per_session', 'session_duration'];

    /**
     * @param  string  $name  The part after `website.` in the handle, and the
     *                        lang key for label and description.
     * @param  string  $figure  The key to read out of the service's answer.
     * @param  string  $unit  One of {@see Unit}.
     */
    public function __construct(
        protected string $name,
        protected string $figure,
        protected string $unit,
    ) {}

    public function handle(): string
    {
        return 'website.'.$this->name;
    }

    public function label(): string
    {
        return __('statamic-insights::website.'.$this->name);
    }

    public function description(): ?string
    {
        return __('statamic-insights::website.'.$this->name.'_description');
    }

    public function group(): string
    {
        return __('statamic-insights::website.group');
    }

    public function unit(): string
    {
        return $this->unit;
    }

    public function available(): bool
    {
        return Rybbit::configured();
    }

    public function value(MetricQuery $query): int|float|null
    {
        return $this->read($this->client()->overview($query));
    }

    public function series(MetricQuery $query): array
    {
        return array_map(fn (array $row) => $this->read($row), $this->client()->series($query));
    }

    public function meta(MetricQuery $query): array
    {
        return [];
    }

    /**
     * One figure out of one row, with the null rule applied.
     *
     * @param  array<string, float|int|null>  $row
     */
    protected function read(array $row): int|float|null
    {
        if (in_array($this->figure, self::DERIVED, true) && ($row['sessions'] ?? 0) <= 0) {
            return null;
        }

        return $row[$this->figure] ?? null;
    }

    /**
     * Resolved per call rather than injected.
     *
     * These metrics are registered as constructed instances while the addon
     * boots, and the container is not a place to be reaching into at that
     * point. The binding is a singleton, so all six share one client and the
     * memo inside it — which is what keeps a screen showing six figures and
     * six charts down to a handful of requests.
     */
    protected function client(): Rybbit
    {
        return app(Rybbit::class);
    }
}
