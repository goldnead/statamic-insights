<script setup>
import { computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Heading, Subheading, Text, Select, Badge,
    EmptyStateMenu, EmptyStateItem, CommandPaletteItem, Icon,
} from '@statamic/cms/ui';
import { money, delta, bucketDate, percent } from '../support/money.js';

const props = defineProps([
    'installed',        // boolean — are the payments tables even there
    'hasSales',         // boolean — has anything ever been paid
    'period',           // '30d'
    'periodOptions',    // [{ value, label }]
    'currency',         // 'EUR'
    'currencyOptions',  // [{ value, label }]
    'otherCurrencies',  // ['CHF'] — taken in this period but not shown
    'totals',           // { gross_cent, refunded_cent, net_cent, orders, buyers, average_cent, refund_rate, previous }
    'byCampaign',       // [{ campaign, source, orders, gross_cent }]
    'byProduct',        // [{ handle, name, orders, quantity, gross_cent }]
    'overTime',         // [{ bucket, gross_cent }]
    'productSumCent',   // int — what the product rows add up to, for the drift note
]);

function navigate(params) {
    // Through the Inertia router, so the progress bar, the flash toasts and the
    // back button all behave. The period lands in the query string, which makes
    // the view shareable and survives a reload.
    router.get(window.location.pathname, params, { preserveState: false, preserveScroll: true });
}

const tiles = computed(() => {
    const before = props.totals.previous;

    return [
        {
            key: 'net',
            label: __('Net revenue'),
            value: money(props.totals.net_cent, props.currency),
            delta: delta(props.totals.net_cent, before?.net_cent ?? null),
        },
        {
            key: 'gross',
            label: __('Paid'),
            value: money(props.totals.gross_cent, props.currency),
            delta: delta(props.totals.gross_cent, before?.gross_cent ?? null),
        },
        {
            key: 'orders',
            label: __('Orders'),
            value: String(props.totals.orders),
            hint: __(':count buyers', { count: props.totals.buyers }),
            delta: delta(props.totals.orders, before?.orders ?? null),
        },
        {
            key: 'average',
            label: __('Average order'),
            value: money(props.totals.average_cent, props.currency),
            delta: delta(props.totals.average_cent, before?.average_cent ?? null),
        },
    ];
});

// The tallest bar defines the scale. Without a floor of 1 an all-zero period
// divides by zero and every bar renders as NaN% — a chart of nothing at all.
const peak = computed(() => Math.max(1, ...props.overTime.map((b) => b.gross_cent)));

// Share of the whole, not of the biggest row. A bare percentage beside a list
// is read as "this much of the total" by everybody — showing a share of the
// leader instead makes the top row 100% and every figure below it a number
// that answers a question nobody asked.
// The whole is the headline figure, not the sum of the rows on screen. Both
// lists are capped at twenty; summing only what is shown would make every
// percentage quietly too high the moment a twenty-first campaign exists, while
// the label still promises a share of the total.
const wholeCent = computed(() => Math.max(1, props.totals.gross_cent));

// The line items are a split of what was charged. When they do not add up to
// it, rows were written past the checkout — said out loud, because two
// different sums on one screen leave a reader to guess which one is real.
const productDrift = computed(() => (props.productSumCent ?? 0) - props.totals.gross_cent);

const bucketLabel = bucketDate;

function share(cent, of) {
    const pct = (cent / of) * 100;

    // Below half a percent, "0%" is more wrong than "<1%": it says a row earned
    // nothing when it earned something.
    if (cent > 0 && pct < 0.5) return '<1%';

    return `${Math.round(pct)}%`;
}
</script>

<template>
    <Head :title="[__('Insights'), __('Revenue')]" />

    <div class="max-w-page mx-auto">
        <!--
            The period switch, reachable from the command palette as well as
            from the select. Every core primary action is registered there, and
            a screen whose only control is a dropdown feels inert beside them.
        -->
        <CommandPaletteItem
            v-for="option in periodOptions"
            :key="`palette-${option.value}`"
            :text="`${__('Revenue')}: ${option.label}`"
            category="Actions"
            icon="chart-monitoring-indicator"
            @selected="navigate({ period: option.value, currency })"
        />

        <!--
            The empty screens get core's own shape: a centred h1, not <Header>
            (pages/forms/Index.vue:28-33). A left-aligned title above a centred
            block is a shape no core screen has — and it is the first screen a
            fresh installation ever sees.
        -->
        <header v-if="!installed || !hasSales" class="py-8 pt-16 text-center">
            <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                <Icon name="chart-monitoring-indicator" class="size-5 text-gray-500" />{{ __('Revenue') }}
            </h1>
        </header>

        <Header v-if="installed && hasSales" :title="__('Revenue')" icon="chart-monitoring-indicator">
            <Select
                v-if="hasSales && currencyOptions.length > 1"
                :model-value="currency"
                :options="currencyOptions"
                class="w-28"
                @update:model-value="(value) => navigate({ period, currency: value })"
            />
            <Select
                v-if="hasSales"
                :model-value="period"
                :options="periodOptions"
                class="w-48"
                @update:model-value="(value) => navigate({ period: value, currency })"
            />
        </Header>

        <!--
            Two different kinds of nothing, and they must not look alike. No
            payments addon is a setup problem with an answer; no sales yet is a
            true and temporary state. One empty screen for both would send
            somebody looking for a bug that is not there.
        -->
        <EmptyStateMenu
            v-if="!installed"
            :heading="__('This report reads what the payments addon records, and it is not installed.')"
        >
            <EmptyStateItem
                icon="shopping-cart"
                :heading="__('Install statamic-payments')"
                :description="__('Once a checkout has taken a payment, its revenue, campaigns and products appear here.')"
            />
        </EmptyStateMenu>

        <EmptyStateMenu
            v-else-if="!hasSales"
            :heading="__('No paid order yet. The first one fills this screen.')"
        >
            <EmptyStateItem
                icon="shopping-cart"
                :heading="__('Nothing has been sold')"
                :description="__('Revenue, the campaigns behind it and the products that earned it all appear here as soon as a payment settles.')"
            />
        </EmptyStateMenu>

        <template v-else>
            <div class="grid gap-4 md:grid-cols-4 mb-6 *:min-w-0">
                <Card v-for="tile in tiles" :key="tile.key" class="h-full">
                    <Subheading :text="tile.label" />
                    <div class="mt-2 flex items-baseline gap-2">
                        <Heading size="2xl" :text="tile.value" />
                        <Text
                            v-if="tile.delta !== null"
                            size="sm"
                            :variant="tile.delta < 0 ? 'danger' : 'subtle'"
                        >{{ tile.delta > 0 ? '+' : '' }}{{ tile.delta }}%</Text>
                    </div>
                    <Text v-if="tile.hint" size="xs" variant="subtle">{{ tile.hint }}</Text>
                </Card>
            </div>

            <!--
                Said, not hidden. Refunds are counted in the period the money
                left, which is why a month can show a refund against a sale it
                never contained. A reader who is not told will find the
                discrepancy and distrust the whole screen.
            -->
            <div class="mb-6 flex flex-wrap items-center gap-3">
                <Text v-if="totals.refunded_cent > 0" size="xs" variant="subtle">
                    <template v-if="totals.refund_rate !== null">
                        {{ __('Refunded in this period: :amount (:rate% of what was paid)', {
                            amount: money(totals.refunded_cent, currency),
                            rate: percent(totals.refund_rate),
                        }) }}
                    </template>
                    <!--
                        No percentage when nothing came in. A rate against zero
                        is not a small number, it is a question that does not
                        apply — and "0%" printed beside a refund amount is a
                        statement contradicted by the figure next to it.
                    -->
                    <template v-else>
                        {{ __('Refunded in this period: :amount, all of it against sales from earlier periods.', {
                            amount: money(totals.refunded_cent, currency),
                        }) }}
                    </template>
                </Text>
                <!--
                    Said on the screen, not only in the code. A refund counts on
                    the day the money went back, so a period can carry a refund
                    for a sale it never contained and the net can fall below
                    zero. A reader who sees a negative figure with no
                    explanation stops trusting the whole screen.
                -->
                <Text v-if="totals.net_cent < 0" size="xs" variant="danger">
                    {{ __('Net is negative because more went back than came in during this period.') }}
                </Text>
                <Text v-if="productDrift !== 0" size="xs" variant="subtle">
                    {{ __('The product rows add up to :sum, which differs from what was charged. Some line items were written past the checkout.', {
                        sum: money(productSumCent, currency),
                    }) }}
                </Text>
                <Badge
                    v-for="other in otherCurrencies"
                    :key="other"
                    color="amber"
                    :text="__('Also taken in :currency, not included', { currency: other })"
                />
            </div>

            <Panel :heading="__('Over time')" class="mb-6">
                <Card>
                    <div class="mb-1 flex justify-end">
                        <Text size="xs" variant="subtle" class="tabular-nums">{{ money(peak, currency) }}</Text>
                    </div>
                    <div class="flex items-end gap-1 h-32" role="img" :aria-label="__('Revenue over time')">
                        <!--
                            `h-full flex items-end` on the wrapper is not
                            decoration: a percentage height resolves against the
                            parent's height, and a wrapper of automatic height
                            makes every bar nought. The chart renders as an
                            empty box, which reads as "no sales" rather than as
                            a broken chart.

                            A day with no sales gets no bar at all. The two
                            pixel floor is for days that earned something too
                            small to see; giving it to zero as well drew
                            revenue on every quiet day of the month — which it
                            did, on 24 of 30 days, until a review counted them.
                        -->
                        <div
                            v-for="bucket in overTime"
                            :key="bucket.bucket"
                            class="flex-1 min-w-0 h-full flex items-end group"
                            :title="`${bucketLabel(bucket.bucket)} · ${money(bucket.gross_cent, currency)}`"
                        >
                            <div
                                class="w-full rounded-t-sm bg-primary/70 group-hover:bg-primary transition-colors"
                                :style="{ height: bucket.gross_cent === 0 ? '0%' : `${Math.max(2, (bucket.gross_cent / peak) * 100)}%` }"
                            />
                        </div>
                    </div>
                    <div class="mt-2 flex justify-between">
                        <Text size="xs" variant="subtle">{{ bucketLabel(overTime[0]?.bucket ?? '') }}</Text>
                        <Text size="xs" variant="subtle">{{ bucketLabel(overTime[overTime.length - 1]?.bucket ?? '') }}</Text>
                    </div>
                </Card>
            </Panel>

            <div class="grid gap-6 md:grid-cols-2 *:min-w-0">
                <!--
                    Both lists are gross. A refund is recorded against a payment
                    and not against the line that earned it, so subtracting it
                    per campaign or per product would be an invention. Said in
                    the heading rather than left for somebody to work out from
                    the fact that the columns do not add up to the net figure.
                -->
                <Panel :heading="__('By campaign (gross)')">
                    <Card>
                        <div v-if="byCampaign.length === 0" class="py-6 text-center">
                            <Text size="sm" variant="subtle">{{ __('Nothing to attribute yet.') }}</Text>
                        </div>
                        <ul v-else class="-my-2 divide-y divide-content-border">
                            <li v-for="row in byCampaign" :key="`${row.campaign}-${row.source}`" class="py-2.5 text-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="min-w-0 truncate font-medium">
                                        {{ row.campaign ?? __('No campaign') }}
                                    </span>
                                    <span class="shrink-0 tabular-nums">{{ money(row.gross_cent, currency) }}</span>
                                </div>
                                <div class="mt-1 flex items-center justify-between gap-3">
                                    <Text size="xs" variant="subtle">
                                        {{ row.source ?? __('unknown source') }} · {{ __(':count orders', { count: row.orders }) }}
                                    </Text>
                                    <Text size="xs" variant="subtle" class="tabular-nums">{{ share(row.gross_cent, wholeCent) }}</Text>
                                </div>
                            </li>
                        </ul>
                    </Card>
                </Panel>

                <Panel :heading="__('By product (gross)')">
                    <Card>
                        <div v-if="byProduct.length === 0" class="py-6 text-center">
                            <Text size="sm" variant="subtle">{{ __('Nothing sold in this period.') }}</Text>
                        </div>
                        <ul v-else class="-my-2 divide-y divide-content-border">
                            <li v-for="row in byProduct" :key="row.handle" class="py-2.5 text-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="min-w-0 truncate font-medium">{{ row.name }}</span>
                                    <span class="shrink-0 tabular-nums">{{ money(row.gross_cent, currency) }}</span>
                                </div>
                                <div class="mt-1 flex items-center justify-between gap-3">
                                    <Text size="xs" variant="subtle">
                                        {{ __(':count sold', { count: row.quantity }) }}
                                    </Text>
                                    <Text size="xs" variant="subtle" class="tabular-nums">{{ share(row.gross_cent, wholeCent) }}</Text>
                                </div>
                            </li>
                        </ul>
                    </Card>
                </Panel>
            </div>
        </template>
    </div>
</template>
