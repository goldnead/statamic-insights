<?php

namespace Goldnead\StatamicInsights\Support;

/**
 * What is being asked of a metric.
 *
 * A value object rather than loose arguments, so a metric added a year from now
 * receives everything a metric added today does — and so adding a dimension to
 * the question does not change the signature of every implementation in the
 * family.
 */
final class MetricQuery
{
    public const BUCKET_DAY = 'day';

    public const BUCKET_MONTH = 'month';

    /**
     * @param  array<string, mixed>  $filters  Free-form. A metric reads what it
     *                                         understands and **ignores the rest**
     *                                         — a screen may pass a currency to
     *                                         every metric on it, and a metric
     *                                         counting bookings must not fail
     *                                         over that.
     */
    public function __construct(
        public readonly Period $period,
        public readonly string $bucket = self::BUCKET_DAY,
        public readonly array $filters = [],
    ) {}

    /** The same question, asked of the period before. Null when there is none. */
    public function previous(): ?self
    {
        $davor = $this->period->previous();

        return $davor === null ? null : new self($davor, $this->bucket, $this->filters);
    }

    /** The same question with one filter changed. */
    public function with(string $key, mixed $value): self
    {
        return new self($this->period, $this->bucket, array_merge($this->filters, [$key => $value]));
    }

    public function filter(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }

    /**
     * The bucket a period deserves.
     *
     * Days up to about a quarter, months beyond — a year of daily bars is three
     * hundred columns nobody can read, and a week of monthly ones is a single bar.
     */
    public static function bucketFor(Period $period): string
    {
        return ($period->days() ?? 400) > 92 ? self::BUCKET_MONTH : self::BUCKET_DAY;
    }
}
