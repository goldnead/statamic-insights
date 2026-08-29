<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Heading, Subheading, Text, Select, Badge, Button,
    EmptyStateMenu, EmptyStateItem, CommandPaletteItem, Icon,
} from '@statamic/cms/ui';
import { formatValue, bucketDate } from '../support/format.js';
import Sparkline from '../components/Sparkline.vue';

/**
 * The curated revenue screen.
 *
 * Every number here now arrives already computed, by the addon that owns the
 * data. This page arranges and formats; it knows what a currency is and not
 * what a payment is.
 */
const props = defineProps([
    'installed',        // boolean — is anything registering revenue at all
    'hasSales',         // boolean — has anything ever been paid
    'period',
    'periodOptions',
    'currency',
    'currencyOptions',
    'metricsUrl',
    'tiles',            // [{ label, unit, meta, value, previous, delta, hint? }]
    'refunded',
    'refundRate',
    'netCent',
    'grossCent',
    'lineItemSumCent',
    'series',           // [{ bucket, value }]
    'byCampaign',       // [{ key, label, value, meta? }]
    'byProduct',
]);

function navigate(params) {
    router.get(window.location.pathname, params, { preserveState: false, preserveScroll: true });
}

const peak = computed(() =>
    Math.max(1, ...props.series.map((b) => Math.abs(Number(b.value) || 0)))
);

// Nothing measured means no scale. The floor of 1 lets the bars divide by
// something; printed it would be a figure nobody measured.
// One bucket is not a chart — it is the number already shown above, drawn as a
// rectangle the width of the panel. It happens when the range collapses to a
// single month, which "all time" does on a shop that started this month.
const hasChart = computed(() => props.series.length >= 2);

const hasValues = computed(() => props.series.some((b) => Number(b.value) !== 0));

// Share of the whole, and the whole is the headline figure — not the sum of the
// rows on screen, which is capped and would make every percentage quietly too
// high the moment a twenty-first campaign existed.
const wholeCent = computed(() => Math.max(1, props.grossCent || 0));

// The line items are a split of what was charged. When they do not add up, rows
// were written past the checkout, and the honest move is to say so rather than
// show two different sums on one screen.
const productDrift = computed(() =>
    props.lineItemSumCent === null || props.lineItemSumCent === undefined
        ? 0
        : props.lineItemSumCent - (props.grossCent || 0)
);

function money(cent) {
    return formatValue(cent, 'currency', { currency: props.currency });
}

function share(cent) {
    const pct = ((cent || 0) / wholeCent.value) * 100;

    // Below half a percent, "0%" is more wrong than "<1%": it says a row earned
    // nothing when it earned something.
    if (cent > 0 && pct < 0.5) return '<1%';

    return `${Math.round(pct)}%`;
}
</script>

<template>
    <Head :title="[__('Insights'), __('Revenue')]" />

    <div class="max-w-page mx-auto">
        <CommandPaletteItem
            v-for="option in periodOptions"
            :key="`palette-${option.value}`"
            :text="`${__('Revenue')}: ${option.label}`"
            category="Actions"
            icon="chart-monitoring-indicator"
            @selected="navigate({ period: option.value, currency })"
        />

        <!--
            Core's shape for an empty screen: a centred h1, not <Header>
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
            <Button :href="`${metricsUrl}?period=${period}`" :text="__('All metrics')" variant="default" />
            <Select
                v-if="currencyOptions.length > 1"
                :model-value="currency"
                :options="currencyOptions"
                class="w-28"
                @update:model-value="(value) => navigate({ period, currency: value })"
            />
            <Select
                :model-value="period"
                :options="periodOptions"
                class="w-48"
                @update:model-value="(value) => navigate({ period: value, currency })"
            />
        </Header>

        <!--
            Two different kinds of nothing, and they must not look alike. No
            addon reporting revenue is a setup problem with an answer; no sales
            yet is a true and temporary state. One empty screen for both would
            send somebody looking for a bug that is not there.
        -->
        <EmptyStateMenu
            v-if="!installed"
            :heading="__('No addon is reporting revenue. This screen shows what your checkout records.')"
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
                <Card v-for="tile in tiles" :key="tile.handle" class="h-full">
                    <Subheading :text="tile.label" />
                    <div class="mt-2 flex items-baseline gap-2">
                        <Heading size="2xl" :text="formatValue(tile.value, tile.unit, tile.meta)" />
                        <Text
                            v-if="tile.delta !== null"
                            size="sm"
                            :variant="tile.delta < 0 ? 'danger' : 'subtle'"
                        >{{ tile.delta > 0 ? '+' : '' }}{{ tile.delta }}%</Text>
                    </div>
                    <Text v-if="tile.hint" size="xs" variant="subtle">
                        {{ __(':count buyers', { count: tile.hint.value }) }}
                    </Text>
                </Card>
            </div>

            <div class="mb-6 flex flex-wrap items-center gap-3">
                <Text v-if="refunded > 0" size="xs" variant="subtle">
                    <template v-if="refundRate !== null">
                        {{ __('Refunded in this period: :amount (:rate% of what was paid)', {
                            amount: money(refunded),
                            rate: formatValue(refundRate, 'percent').replace(' %', ''),
                        }) }}
                    </template>
                    <template v-else>
                        {{ __('Refunded in this period: :amount, all of it against sales from earlier periods.', {
                            amount: money(refunded),
                        }) }}
                    </template>
                </Text>
                <!--
                    A refund counts on the day the money went back, so a period
                    can carry one for a sale it never contained and the net can
                    fall below zero. A reader who sees that with no explanation
                    stops trusting the screen.
                -->
                <Text v-if="netCent < 0" size="xs" variant="danger">
                    {{ __('Net is negative because more went back than came in during this period.') }}
                </Text>
                <Text v-if="productDrift !== 0" size="xs" variant="subtle">
                    {{ __('The product rows add up to :sum, which differs from what was charged. Some line items were written past the checkout.', {
                        sum: money(lineItemSumCent),
                    }) }}
                </Text>
            </div>

            <Panel v-if="hasChart" :heading="__('Over time')" class="mb-6">
                <Card>
                    <div v-if="hasValues" class="mb-1 flex justify-end">
                        <Text size="xs" variant="subtle" class="tabular-nums">{{ money(peak) }}</Text>
                    </div>
                    <Sparkline :series="series" height="h-32" />
                    <div class="mt-2 flex justify-between">
                        <Text size="xs" variant="subtle">{{ bucketDate(series[0]?.bucket) }}</Text>
                        <Text size="xs" variant="subtle">{{ bucketDate(series[series.length - 1]?.bucket) }}</Text>
                    </div>
                </Card>
            </Panel>

            <!--
                Both lists are gross. A refund is recorded against a payment and
                not against the line that earned it, so subtracting it per
                campaign or per product would be an invention.
            -->
            <div class="grid gap-6 md:grid-cols-2 *:min-w-0">
                <Panel :heading="__('By campaign (gross)')">
                    <Card>
                        <div v-if="byCampaign.length === 0" class="py-6 text-center">
                            <Text size="sm" variant="subtle">{{ __('Nothing to attribute yet.') }}</Text>
                        </div>
                        <ul v-else class="-my-2 divide-y divide-content-border">
                            <li v-for="(row, index) in byCampaign" :key="index" class="py-2.5 text-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="min-w-0 truncate font-medium">{{ row.label }}</span>
                                    <span class="shrink-0 tabular-nums">{{ money(row.value) }}</span>
                                </div>
                                <div class="mt-1 flex items-center justify-between gap-3">
                                    <Text v-if="row.meta?.orders" size="xs" variant="subtle">
                                        {{ __(':count orders', { count: row.meta.orders }) }}
                                    </Text>
                                    <span v-else />
                                    <Text size="xs" variant="subtle" class="tabular-nums">{{ share(row.value) }}</Text>
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
                            <li v-for="(row, index) in byProduct" :key="index" class="py-2.5 text-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="min-w-0 truncate font-medium">{{ row.label }}</span>
                                    <span class="shrink-0 tabular-nums">{{ money(row.value) }}</span>
                                </div>
                                <div class="mt-1 flex items-center justify-between gap-3">
                                    <Text v-if="row.meta?.quantity" size="xs" variant="subtle">
                                        {{ __(':count sold', { count: row.meta.quantity }) }}
                                    </Text>
                                    <span v-else />
                                    <Text size="xs" variant="subtle" class="tabular-nums">{{ share(row.value) }}</Text>
                                </div>
                            </li>
                        </ul>
                    </Card>
                </Panel>
            </div>
        </template>
    </div>
</template>
