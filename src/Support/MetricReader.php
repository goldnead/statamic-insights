<?php

namespace Goldnead\StatamicInsights\Support;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Contracts\Metric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a metric plus a question into the shape a screen renders.
 *
 * Everything a reader expects around a number — what it was last period, how
 * that compares, the buckets nobody had data for, how to format it — is done
 * once here rather than in every implementation. A metric contributes a number
 * and nothing else, which is what keeps the contract small enough for another
 * addon to implement in an afternoon.
 *
 * **Nothing here throws.** A screen shows many metrics; one that is broken must
 * cost its own tile, not the page.
 */
class MetricReader
{
    public function __construct(protected MetricRegistry $registry) {}

    /**
     * One metric, fully prepared.
     *
     * @return array<string, mixed>|null Null when the metric cannot answer at all.
     */
    public function read(Metric $metric, MetricQuery $query): ?array
    {
        try {
            $wert = $metric->value($query);
            $meta = $metric->meta($query);
        } catch (Throwable $e) {
            $this->complain($metric, 'value', $e);

            return null;
        }

        $davor = $this->previousValue($metric, $query);

        return [
            'handle' => $metric->handle(),
            'label' => $metric->label(),
            'description' => $metric->description(),
            'group' => $metric->group(),
            'unit' => $metric->unit(),
            'meta' => $meta,
            'value' => $wert,
            'previous' => $davor,
            'delta' => $this->delta($wert, $davor),
            'breakdowns' => $metric instanceof HasBreakdowns ? $this->breakdownLabels($metric) : [],
        ];
    }

    /**
     * The series, with every bucket in the range present.
     *
     * The metric returns only the buckets it has; the gaps are filled here with
     * zero. A chart built from the buckets that had data skips the quiet weeks
     * and draws a bad month as a good one — and asking every implementer to
     * remember that is asking for the bug.
     *
     * @return array<int, array{bucket: string, value: int|float|null}>
     */
    public function series(Metric $metric, MetricQuery $query): array
    {
        try {
            $gemessen = $metric->series($query);
        } catch (Throwable $e) {
            $this->complain($metric, 'series', $e);

            return [];
        }

        $eimer = $this->buckets($query);

        // An open-ended period has no bounds to fill between, so the span comes
        // from the data itself: first bucket the metric reported to last. The
        // alternative — an empty array — drew a blank chart beside a figure of
        // 1.255,00 €, which is a state the number next to it disproves.
        if ($eimer === [] && $gemessen !== []) {
            $eimer = $this->spanOf(array_keys($gemessen));
        }

        $reihe = [];

        foreach ($eimer as $key) {
            // Null survives. A bucket a metric deliberately left null because
            // the question does not apply there — a rate on a day with no
            // denominator — is not a measured zero, and turning it into one is
            // the lie the contract forbids for `value()`.
            $reihe[] = [
                'bucket' => $key,
                'value' => array_key_exists($key, $gemessen) ? $gemessen[$key] : 0,
            ];
        }

        return $reihe;
    }

    /**
     * One split of a metric.
     *
     * @return array<int, array<string, mixed>>
     */
    public function breakdown(Metric $metric, MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $metric instanceof HasBreakdowns || ! array_key_exists($dimension, $metric->breakdowns())) {
            return [];
        }

        try {
            return $metric->breakdown($query, $dimension, $limit);
        } catch (Throwable $e) {
            $this->complain($metric, "breakdown:{$dimension}", $e);

            return [];
        }
    }

    /**
     * Every available metric, prepared, grouped under its own heading.
     *
     * @return array<int, array{group: string, metrics: array<int, array<string, mixed>>}>
     */
    public function overview(MetricQuery $query): array
    {
        $gruppen = [];

        foreach ($this->registry->grouped() as $gruppe => $metriken) {
            $vorbereitet = [];

            foreach ($metriken as $metrik) {
                if ($zeile = $this->read($metrik, $query)) {
                    $vorbereitet[] = $zeile;
                }
            }

            if ($vorbereitet !== []) {
                $gruppen[] = ['group' => $gruppe, 'metrics' => $vorbereitet];
            }
        }

        return $gruppen;
    }

    protected function previousValue(Metric $metric, MetricQuery $query): int|float|null
    {
        $davor = $query->previous();

        if ($davor === null) {
            return null;
        }

        try {
            return $metric->value($davor);
        } catch (Throwable $e) {
            // The comparison is the garnish, not the dish. A metric that cannot
            // answer for the previous window still shows this one's number.
            $this->complain($metric, 'previous value', $e);

            return null;
        }
    }

    /**
     * The change against the period before, in percent.
     *
     * Null where a percentage would be a claim rather than a fact: no previous
     * period, no previous value, or a previous value of zero — every increase
     * from nothing is infinite, and "+∞ %" tells a reader less than the
     * absolute number already did.
     */
    protected function delta(int|float|null $jetzt, int|float|null $davor): ?int
    {
        if ($jetzt === null || $davor === null || (float) $davor === 0.0) {
            return null;
        }

        return (int) round((($jetzt - $davor) / abs($davor)) * 100);
    }

    /** @return array<int, string> */
    protected function buckets(MetricQuery $query): array
    {
        $von = $query->period->from;
        $bis = $query->period->to;

        if ($von === null || $bis === null) {
            return [];
        }

        $monatlich = $query->bucket === MetricQuery::BUCKET_MONTH;
        $keys = [];
        $zeiger = $von->copy();

        while ($zeiger <= $bis) {
            $keys[] = $monatlich ? $zeiger->format('Y-m') : $zeiger->format('Y-m-d');
            $zeiger = $monatlich ? $zeiger->addMonth() : $zeiger->addDay();
        }

        return $keys;
    }

    /**
     * Every bucket between the first and last a metric reported.
     *
     * Used only for an open-ended period, where nothing else defines the range.
     * Granularity is read from the keys themselves rather than assumed: a
     * metric answering "all time" may well have decided on months.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    protected function spanOf(array $keys): array
    {
        sort($keys);

        $monatlich = strlen((string) $keys[0]) === 7;
        $von = Carbon::parse($keys[0]);
        $bis = Carbon::parse($keys[count($keys) - 1]);
        $eimer = [];

        while ($von <= $bis) {
            $eimer[] = $monatlich ? $von->format('Y-m') : $von->format('Y-m-d');
            $von = $monatlich ? $von->addMonth() : $von->addDay();
        }

        return $eimer;
    }

    /** @return array<string, string> */
    protected function breakdownLabels(HasBreakdowns $metric): array
    {
        try {
            return $metric->breakdowns();
        } catch (Throwable) {
            return [];
        }
    }

    protected function complain(Metric $metric, string $was, Throwable $e): void
    {
        Log::warning("insights: the metric [{$metric->handle()}] failed at [{$was}] and was left out.", [
            'exception' => $e->getMessage(),
        ]);
    }
}
