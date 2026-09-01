<?php

namespace Goldnead\StatamicInsights\Reports;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Neighbours;
use Goldnead\StatamicInsights\Support\TableReport;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Facades\DB;

/**
 * What each product earned, over the line items of paid payments.
 *
 * Reads `payment_items` joined to `payments` of `statamic-payments`. Line
 * items rather than the payment's own `product` column, so an order bump is
 * credited to the thing that was bumped and not to the product it rode along
 * with. A payment counts when `status = paid`, in the period of its `paid_at`.
 *
 * The name is the one written on the line at the time of the sale — a product
 * renamed since keeps its old name on old rows, and the newest name wins here.
 * The handle beside it is what stays stable.
 */
class RevenueByProduct extends TableReport
{
    protected function neighbour(): string
    {
        return Neighbours::PAYMENTS;
    }

    /** The brand lives on the payment, so the brand narrowing keys on it. */
    protected function table(): string
    {
        return 'payments';
    }

    protected function timestamp(): string
    {
        return 'paid_at';
    }

    protected function brandColumn(): ?string
    {
        return 'brand_id';
    }

    public function handle(): string
    {
        return 'payments.revenue_by_product';
    }

    public function label(): string
    {
        return __('statamic-insights::reports.revenue_by_product');
    }

    public function description(): ?string
    {
        return __('statamic-insights::reports.revenue_by_product_description');
    }

    public function group(): string
    {
        return __('statamic-insights::reports.group_payments');
    }

    public function columns(): array
    {
        return [
            $this->column('name', __('statamic-insights::reports.col_product'), 'text'),
            $this->column('product', __('statamic-insights::reports.col_handle'), 'code'),
            $this->column('currency', __('statamic-insights::reports.col_currency'), 'text'),
            $this->column('quantity', __('statamic-insights::reports.col_sold'), Unit::COUNT),
            $this->column('orders', __('statamic-insights::reports.col_orders'), Unit::COUNT),
            $this->column('revenue_cent', __('statamic-insights::reports.col_revenue'), Unit::CURRENCY),
        ];
    }

    public function rows(MetricQuery $query): array
    {
        $rows = DB::table('payment_items')
            ->join('payments', 'payments.id', '=', 'payment_items.payment_id')
            ->where('payments.status', 'paid');

        return $this->window($rows, $query, 'payments.paid_at')
            ->selectRaw(
                'payment_items.product as product, payments.currency as currency, '
                .'max(payment_items.name) as name, '
                .'sum(payment_items.quantity) as quantity, '
                .'count(distinct payments.id) as orders, '
                .'sum(payment_items.amount_cent) as revenue_cent'
            )
            ->groupBy('payment_items.product', 'payments.currency')
            ->orderByDesc('revenue_cent')
            ->orderBy('payment_items.product')
            ->get()
            ->map(fn ($row) => [
                'name' => (string) ($row->name ?? $row->product),
                'product' => (string) $row->product,
                'currency' => (string) $row->currency,
                'quantity' => (int) $this->number($row->quantity),
                'orders' => (int) $this->number($row->orders),
                'revenue_cent' => (int) $this->number($row->revenue_cent),
            ])
            ->all();
    }
}
