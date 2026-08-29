<?php

namespace Goldnead\StatamicInsights\Support;

use Goldnead\StatamicInsights\Contracts\Metric;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every metric anybody has registered.
 *
 * A singleton, and it has to be: a sibling addon registers into it while
 * booting, and a registry rebuilt per resolution would be a different object
 * from the one the screen later reads — every contributed metric silently
 * dropped, with nothing to see but a page that is missing something.
 *
 * Registrations are lazy. A class name is stored and resolved when somebody
 * actually asks, so booting twenty addons does not mean constructing eighty
 * metric objects on a request that renders none of them.
 */
class MetricRegistry
{
    /** @var array<string, class-string<Metric>|Metric|\Closure> */
    protected array $registered = [];

    /** @var array<string, Metric|null> */
    protected array $resolved = [];

    /**
     * Register a metric by class name, instance or factory.
     *
     * Keyed by handle, so registering the same handle twice replaces the first
     * rather than showing it twice — an addon booted through both a service
     * provider and a bridge must not produce the number twice.
     */
    public function register(string|Metric|\Closure $metric, ?string $handle = null): void
    {
        if ($metric instanceof Metric) {
            $this->registered[$metric->handle()] = $metric;
            $this->resolved[$metric->handle()] = $metric;

            return;
        }

        $grund = null;

        if ($handle === null && is_string($metric)) {
            // Resolving it here would defeat the laziness, so the handle should
            // be given — or the class has to be constructible without the
            // container, which is the common case and worth the try.
            try {
                $probe = app($metric);
                $handle = $probe instanceof Metric ? $probe->handle() : null;
                $grund = $handle === null ? 'the class is not a Metric' : null;

                if ($handle !== null) {
                    $this->resolved[$handle] = $probe;
                }
            } catch (Throwable $e) {
                // Said, not swallowed. A constructor that throws for an
                // unrelated reason used to be reported as "no handle", which
                // sends whoever reads the log looking in the wrong place.
                $grund = $e->getMessage();
                $handle = null;
            }
        }

        if ($handle === null) {
            Log::warning('insights: a metric was registered without a handle and could not be resolved to find one.', [
                'metric' => is_string($metric) ? $metric : get_debug_type($metric),
                // A closure always lands here: it cannot be probed without
                // calling it, and calling it is the laziness this registry
                // exists to keep. Pass the handle alongside it.
                'reason' => $grund ?? 'a closure or instance was given without a handle',
            ]);

            return;
        }

        $this->registered[$handle] = $metric;
    }

    /** @return array<int, string> */
    public function handles(): array
    {
        return array_keys($this->registered);
    }

    /**
     * One metric, or null when it is not registered or cannot answer.
     *
     * A metric whose construction throws is logged and treated as absent. This
     * runs while a screen renders, and a sibling mid-upgrade must not be able
     * to take the page down with it.
     */
    public function find(string $handle): ?Metric
    {
        if (array_key_exists($handle, $this->resolved)) {
            return $this->resolved[$handle];
        }

        if (! array_key_exists($handle, $this->registered)) {
            return null;
        }

        $entry = $this->registered[$handle];

        try {
            $metric = $entry instanceof \Closure ? $entry() : (is_string($entry) ? app($entry) : $entry);
        } catch (Throwable $e) {
            Log::warning("insights: the metric [{$handle}] could not be resolved and was left out.", [
                'exception' => $e->getMessage(),
            ]);

            return $this->resolved[$handle] = null;
        }

        return $this->resolved[$handle] = $metric instanceof Metric ? $metric : null;
    }

    /**
     * Every metric that can answer right now, in registration order.
     *
     * `available()` is asked here rather than by each screen, so a metric whose
     * source is missing is absent everywhere at once instead of showing a zero
     * on the screens that forgot to check.
     *
     * @return array<string, Metric>
     */
    public function available(): array
    {
        $metriken = [];

        foreach ($this->handles() as $handle) {
            $metrik = $this->find($handle);

            if ($metrik === null) {
                continue;
            }

            try {
                if (! $metrik->available()) {
                    continue;
                }
            } catch (Throwable $e) {
                Log::warning("insights: the metric [{$handle}] failed while saying whether it is available.", [
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $metriken[$handle] = $metrik;
        }

        return $metriken;
    }

    /**
     * The available metrics, grouped under their own headings, in a settled order.
     *
     * Sorted by heading rather than left in registration order, which is the
     * order the service providers happened to boot in. Unsorted, installing or
     * removing any addon reshuffled every other addon's section on the screen —
     * a reader who had learnt where "Invoices" sits would find it somewhere
     * else after an unrelated update. Alphabetical is not the cleverest order
     * imaginable, but it is the one that holds still, and holding still is what
     * a screen read every morning needs.
     *
     * Sorted on the translated heading, so the order follows the language the
     * reader is actually looking at.
     *
     * @return array<string, array<string, Metric>>
     */
    public function grouped(): array
    {
        $gruppen = [];

        foreach ($this->available() as $handle => $metrik) {
            $gruppen[$metrik->group()][$handle] = $metrik;
        }

        ksort($gruppen, SORT_NATURAL | SORT_FLAG_CASE);

        return $gruppen;
    }
}
