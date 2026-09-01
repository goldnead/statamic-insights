<?php

namespace Goldnead\StatamicInsights\Support;

use Goldnead\StatamicInsights\Contracts\Report;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every report anybody has registered.
 *
 * The tabular twin of {@see MetricRegistry}: a singleton, keyed by handle so a
 * second registration replaces the first, lazy so booting the family does not
 * construct every report on a request that renders none.
 *
 * One difference on purpose: {@see all()} returns the unavailable ones too.
 * A metric whose source is missing vanishes from the screen; a report stays,
 * with the sentence naming what it would need. A list of reports is also a
 * list of what the suite can tell you once the right addon is installed.
 */
class ReportRegistry
{
    /** @var array<string, class-string<Report>|Report> */
    protected array $registered = [];

    /** @var array<string, Report|null> */
    protected array $resolved = [];

    public function register(string|Report $report, ?string $handle = null): void
    {
        if ($report instanceof Report) {
            $this->registered[$report->handle()] = $report;
            $this->resolved[$report->handle()] = $report;

            return;
        }

        if ($handle === null) {
            try {
                $probe = app($report);
                $handle = $probe instanceof Report ? $probe->handle() : null;

                if ($handle !== null) {
                    $this->resolved[$handle] = $probe;
                }
            } catch (Throwable $e) {
                Log::warning('insights: a report was registered without a handle and could not be resolved to find one.', [
                    'report' => $report,
                    'reason' => $e->getMessage(),
                ]);

                return;
            }
        }

        if ($handle === null) {
            Log::warning('insights: a report was registered without a handle and could not be resolved to find one.', [
                'report' => $report,
                'reason' => 'the class is not a Report',
            ]);

            return;
        }

        $this->registered[$handle] = $report;
    }

    /** @return array<int, string> */
    public function handles(): array
    {
        return array_keys($this->registered);
    }

    /**
     * One report, or null when it is not registered or cannot be built.
     *
     * A constructor that throws is logged and treated as absent — this runs
     * while a screen renders, and a sibling mid-upgrade must not take the page
     * down with it.
     */
    public function find(string $handle): ?Report
    {
        if (array_key_exists($handle, $this->resolved)) {
            return $this->resolved[$handle];
        }

        if (! array_key_exists($handle, $this->registered)) {
            return null;
        }

        $entry = $this->registered[$handle];

        try {
            $report = is_string($entry) ? app($entry) : $entry;
        } catch (Throwable $e) {
            Log::warning("insights: the report [{$handle}] could not be resolved and was left out.", [
                'exception' => $e->getMessage(),
            ]);

            return $this->resolved[$handle] = null;
        }

        return $this->resolved[$handle] = $report instanceof Report ? $report : null;
    }

    /**
     * Every report that resolved, available or not, in registration order.
     *
     * @return array<string, Report>
     */
    public function all(): array
    {
        $reports = [];

        foreach ($this->handles() as $handle) {
            $report = $this->find($handle);

            if ($report !== null) {
                $reports[$handle] = $report;
            }
        }

        return $reports;
    }

    /**
     * Grouped under their headings, sorted on the translated heading so the
     * order holds still across installs and languages — see
     * {@see MetricRegistry::grouped()} for why.
     *
     * @return array<string, array<string, Report>>
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->all() as $handle => $report) {
            $groups[$report->group()][$handle] = $report;
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return $groups;
    }
}
