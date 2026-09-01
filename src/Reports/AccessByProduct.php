<?php

namespace Goldnead\StatamicInsights\Reports;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Neighbours;
use Goldnead\StatamicInsights\Support\TableReport;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who can get in right now, per thing they can get into.
 *
 * Reads `entitlements` of `statamic-entitlements`, grouped by `product_slug`
 * — the access slug a product's `grants` names, not the product handle, so a
 * product that opens two things shows two rows and a slug two products share
 * shows one.
 *
 * A snapshot, not a period: the three columns are answered against this
 * moment, using the same clauses that addon's own *Active* metric uses.
 * Revoked rows are left out of all three — a revocation is a decision, not a
 * lapse, and it has its own metric next door.
 *
 * - **Active**: started, not yet expired.
 * - **In grace**: expired, but `grace_until` has not passed.
 * - **Expired**: expired, and past any grace.
 */
class AccessByProduct extends TableReport
{
    protected function neighbour(): string
    {
        return Neighbours::ENTITLEMENTS;
    }

    protected function table(): string
    {
        return 'entitlements';
    }

    protected function timestamp(): string
    {
        return 'starts_at';
    }

    protected function brandColumn(): ?string
    {
        return 'brand_id';
    }

    public function handle(): string
    {
        return 'entitlements.access_by_product';
    }

    public function label(): string
    {
        return __('statamic-insights::reports.access_by_product');
    }

    public function description(): ?string
    {
        return __('statamic-insights::reports.access_by_product_description');
    }

    public function group(): string
    {
        return __('statamic-insights::reports.group_entitlements');
    }

    public function usesPeriod(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            $this->column('product_slug', __('statamic-insights::reports.col_access'), 'code'),
            $this->column('active', __('statamic-insights::reports.col_active'), Unit::COUNT),
            $this->column('grace', __('statamic-insights::reports.col_grace'), Unit::COUNT),
            $this->column('expired', __('statamic-insights::reports.col_expired'), Unit::COUNT),
        ];
    }

    public function rows(MetricQuery $query): array
    {
        $now = Carbon::now()->toDateTimeString();

        $rows = $this->brandScoped(DB::table('entitlements'))
            ->whereNull('revoked_at')
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', $now)
            ->selectRaw(
                'product_slug, '
                .'sum(case when expires_at is null or expires_at > ? then 1 else 0 end) as active, '
                .'sum(case when expires_at is not null and expires_at <= ? and grace_until is not null and grace_until > ? then 1 else 0 end) as grace, '
                .'sum(case when expires_at is not null and expires_at <= ? and (grace_until is null or grace_until <= ?) then 1 else 0 end) as expired',
                [$now, $now, $now, $now, $now]
            )
            ->groupBy('product_slug')
            ->orderByDesc('active')
            ->orderBy('product_slug')
            ->get();

        return $rows->map(fn ($row) => [
            'product_slug' => (string) $row->product_slug,
            'active' => (int) $this->number($row->active),
            'grace' => (int) $this->number($row->grace),
            'expired' => (int) $this->number($row->expired),
        ])->all();
    }
}
