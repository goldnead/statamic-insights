<?php

namespace Goldnead\StatamicInsights\Support;

use Goldnead\StatamicInsights\Contracts\Metric;

/**
 * The curated revenue screen, assembled from registered metrics.
 *
 * There used to be a second copy of every payments query in this addon. Two
 * places computing the same money is the failure this whole family keeps
 * writing tests against: they agree until one of them is changed, and then the
 * screen and the report disagree with nothing to say which is right.
 *
 * So this class computes nothing. It knows which handles it would like, asks
 * the registry for them, and arranges what comes back. If `statamic-payments`
 * is absent — or too old to register metrics — every one of them is missing,
 * and the screen says so instead of showing zeroes.
 */
class RevenueView
{
    /** The handles this screen is built from. Missing ones are simply absent. */
    public const HANDLES = [
        'net' => 'payments.revenue_net',
        'gross' => 'payments.revenue_gross',
        'refunded' => 'payments.refunded',
        'orders' => 'payments.orders',
        'buyers' => 'payments.buyers',
        'average' => 'payments.average_order',
        'refund_rate' => 'payments.refund_rate',
    ];

    public function __construct(
        protected MetricRegistry $registry,
        protected MetricReader $reader,
    ) {}

    /**
     * Is there anything to draw?
     *
     * The gross figure is the one the whole screen hangs on: without it there
     * is no chart, no split and no comparison. Its absence is the difference
     * between "no payments addon" and "no sales yet", which the screen states
     * as two different sentences.
     */
    public function available(): bool
    {
        return $this->metric('gross') !== null;
    }

    /** @return array<string, mixed> */
    public function assemble(MetricQuery $query): array
    {
        $gross = $this->metric('gross');

        if ($gross === null) {
            return ['installed' => false];
        }

        $gelesen = [];

        foreach (array_keys(self::HANDLES) as $key) {
            $metrik = $this->metric($key);
            $gelesen[$key] = $metrik === null ? null : $this->reader->read($metrik, $query);
        }

        $meta = $gelesen['gross']['meta'] ?? [];

        return [
            'installed' => true,
            'currency' => $meta['currency'] ?? null,
            'tiles' => $this->tiles($gelesen),
            'refunded' => $gelesen['refunded']['value'] ?? 0,
            'refundRate' => $gelesen['refund_rate']['value'] ?? null,
            'netCent' => $gelesen['net']['value'] ?? 0,
            'grossCent' => $gelesen['gross']['value'] ?? 0,
            // Handed over by the metric so the screen can say when the line
            // items disagree with what was charged. It caught real broken data
            // once; a net worth keeping.
            'lineItemSumCent' => $meta['line_item_sum_cent'] ?? null,
            'series' => $this->reader->series($gross, $query),
            'byCampaign' => $this->reader->breakdown($gross, $query, 'campaign'),
            'byProduct' => $this->reader->breakdown($gross, $query, 'product'),
        ];
    }

    /**
     * The four headline figures, in the order a person reads them.
     *
     * A tile whose metric is not registered is left out rather than shown
     * empty — the row simply gets shorter, which is honest, where a dash under
     * a heading reads as "measured nothing".
     *
     * @param  array<string, array<string, mixed>|null>  $gelesen
     * @return array<int, array<string, mixed>>
     */
    protected function tiles(array $gelesen): array
    {
        $kacheln = [];

        foreach (['net', 'gross', 'orders', 'average'] as $key) {
            if ($gelesen[$key] === null) {
                continue;
            }

            $kachel = $gelesen[$key];

            // The buyer count belongs under the order count, not beside it as
            // a fifth number: it answers "how many people", which is a footnote
            // to "how many orders" and a headline to nothing.
            if ($key === 'orders' && ($gelesen['buyers'] ?? null) !== null) {
                $kachel['hint'] = $gelesen['buyers'];
            }

            $kacheln[] = $kachel;
        }

        return $kacheln;
    }

    protected function metric(string $key): ?Metric
    {
        $metrik = $this->registry->find(self::HANDLES[$key]);

        if ($metrik === null) {
            return null;
        }

        try {
            return $metrik->available() ? $metrik : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
