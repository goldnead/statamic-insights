<?php

namespace Goldnead\StatamicInsights\Reports;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Neighbours;
use Goldnead\StatamicInsights\Support\TableReport;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Facades\DB;

/**
 * How each order bump and post-purchase offer is doing.
 *
 * Reads `offers` of `statamic-offers` — `shown_count` and `accepted_count`,
 * the two counters that addon keeps on the row — and, when `statamic-payments`
 * is there as well, the revenue from `payment_items`.
 *
 * Two things a reader has to know, and the description says both:
 *
 * The counters are **lifetime**. The offers addon increments two integers and
 * keeps no log of when, so the period picker cannot narrow them; only the
 * revenue column follows it.
 *
 * Revenue is credited **by product and kind**, because a line item names the
 * product it sold and the slot it was sold in, not the offer. Two bumps
 * selling the same product both show that product's bump revenue. The offers
 * addon would have to write its handle onto the line for this to be exact,
 * and until it does this column is an attribution, not a ledger.
 *
 * Spans all brands: `offers` has no brand column, and narrowing only the
 * revenue half would put two brands' counters beside one brand's money.
 */
class UpsellPerformance extends TableReport
{
    protected function neighbour(): string
    {
        return Neighbours::OFFERS;
    }

    protected function table(): string
    {
        return 'offers';
    }

    protected function timestamp(): string
    {
        return 'created_at';
    }

    public function handle(): string
    {
        return 'offers.upsells';
    }

    public function label(): string
    {
        return __('statamic-insights::reports.upsells');
    }

    public function description(): ?string
    {
        return __('statamic-insights::reports.upsells_description');
    }

    public function group(): string
    {
        return __('statamic-insights::reports.group_offers');
    }

    public function columns(): array
    {
        return [
            $this->column('name', __('statamic-insights::reports.col_offer'), 'text'),
            $this->column('slot', __('statamic-insights::reports.col_slot'), 'text'),
            $this->column('product', __('statamic-insights::reports.col_product'), 'code'),
            $this->column('shown', __('statamic-insights::reports.col_shown'), Unit::COUNT),
            $this->column('accepted', __('statamic-insights::reports.col_accepted'), Unit::COUNT),
            $this->column('conversion', __('statamic-insights::reports.col_conversion'), Unit::PERCENT),
            // Named for what it is: an attribution by product and slot, not a
            // ledger per offer — `payment_items` carries no offer handle yet.
            $this->column('revenue_cent', __('statamic-insights::reports.col_revenue_attributed'), Unit::CURRENCY),
        ];
    }

    public function rows(MetricQuery $query): array
    {
        $offers = DB::table('offers')
            ->whereIn('slot', ['bump', 'post_purchase'])
            ->orderByDesc('accepted_count')
            ->orderBy('name')
            ->get(['id', 'handle', 'name', 'slot', 'product', 'currency', 'active', 'shown_count', 'accepted_count']);

        $revenue = $this->revenueByProductAndKind($query);

        return $offers->map(function ($offer) use ($revenue) {
            $kind = $offer->slot === 'bump' ? 'bump' : 'upsell';
            $earned = $revenue[$offer->product][$kind] ?? null;
            $shown = (int) $this->number($offer->shown_count);
            $accepted = (int) $this->number($offer->accepted_count);

            return [
                'name' => (string) $offer->name,
                'handle' => (string) $offer->handle,
                'slot' => __('statamic-insights::reports.slot_'.$offer->slot),
                'product' => (string) $offer->product,
                'active' => (bool) $offer->active,
                'shown' => $shown,
                'accepted' => $accepted,
                'conversion' => $this->percent($accepted, $shown),
                // Null, not zero, when payments is absent: "not measured" and
                // "earned nothing" are different statements.
                'revenue_cent' => $earned === null ? null : $earned['cent'],
                'currency' => $earned['currency'] ?? ($offer->currency ?: null),
            ];
        })->all();
    }

    /**
     * Revenue from paid line items in the period, keyed by product then kind.
     *
     * The currency is the one with the most revenue for that product and kind;
     * a bump sold in two currencies would need two rows to be honest and the
     * offer has only one. Rare enough to name here and not solve.
     *
     * @return array<string, array<string, array{cent: int, currency: string}>>
     */
    protected function revenueByProductAndKind(MetricQuery $query): array
    {
        if (! Neighbours::installed(Neighbours::PAYMENTS)) {
            return [];
        }

        $rows = DB::table('payment_items')
            ->join('payments', 'payments.id', '=', 'payment_items.payment_id')
            ->where('payments.status', 'paid')
            ->whereIn('payment_items.kind', ['bump', 'upsell'])
            ->whereNotNull('payments.paid_at')
            ->when($query->period->from, fn ($q) => $q->where('payments.paid_at', '>=', $query->period->from))
            ->when($query->period->toExclusive(), fn ($q) => $q->where('payments.paid_at', '<', $query->period->toExclusive()))
            ->selectRaw('payment_items.product as product, payment_items.kind as kind, payments.currency as currency, sum(payment_items.amount_cent) as cent')
            ->groupBy('payment_items.product', 'payment_items.kind', 'payments.currency')
            ->orderByDesc('cent')
            ->get();

        $earned = [];

        foreach ($rows as $row) {
            // First one wins per product and kind: ordered by revenue, so the
            // busiest currency is the one kept.
            $earned[$row->product][$row->kind] ??= [
                'cent' => (int) $this->number($row->cent),
                'currency' => (string) $row->currency,
            ];
        }

        return $earned;
    }
}
