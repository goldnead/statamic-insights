<script setup>
import { computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Heading, Subheading, Text, Select, Button,
    EmptyStateMenu, EmptyStateItem, CommandPaletteItem, Icon,
} from '@statamic/cms/ui';
import { formatValue, bucketDate } from '../support/format.js';
import Sparkline from '../components/Sparkline.vue';

/**
 * The subscriptions screen.
 *
 * Laid out like the revenue screen on purpose: figures, the line over time,
 * then the lists that explain the figures. Every number arrives computed;
 * this page arranges and formats, one currency at a time.
 */
const props = defineProps([
    'installed',
    'hasSubscriptions',
    'period',
    'periodOptions',
    'currency',
    'currencyOptions',
    'reportUrls',
    'tiles',            // [{ handle, label, unit, meta, value, previous, delta }]
    'churn',            // { customers_start, customers_churned, customer_rate, revenue_rate }
    'movements',        // { mrr_start, new, reactivation, expansion, contraction, churn, paused, net, mrr_end }
    'series',           // [{ bucket, value }]
    'standings',        // [{ key, label, count, mrr }]
    'upcoming',         // { count, total_cent, subscriptions_cent, plans_cent }
    'forecast',         // { month, quarter, year }
    'unrecognised',     // ['on_hold', …]
]);

function navigate(params) {
    router.get(window.location.pathname, params, { preserveState: false, preserveScroll: true });
}

function money(cent) {
    return formatValue(cent, 'currency', { currency: props.currency });
}

const peak = computed(() =>
    Math.max(1, ...(props.series || []).map((b) => Math.abs(Number(b.value) || 0)))
);

const hasChart = computed(() => (props.series || []).length >= 2);
const hasValues = computed(() => (props.series || []).some((b) => Number(b.value) !== 0));

// The order a reader follows the change: what came in, what went, what is left.
// Losses carry their sign here so the column adds up by eye.
const movementRows = computed(() => {
    const m = props.movements || {};

    return [
        { key: 'mrr_start', label: __('At the start'), value: m.mrr_start, strong: true },
        { key: 'new', label: __('New'), value: m.new },
        // Most shops never see anybody come back; a line of zeros says nothing.
        m.reactivation ? { key: 'reactivation', label: __('Reactivated'), value: m.reactivation } : null,
        { key: 'expansion', label: __('Expansion'), value: m.expansion },
        { key: 'contraction', label: __('Contraction'), value: -(m.contraction || 0) },
        { key: 'churn', label: __('Churned'), value: -(m.churn || 0) },
        { key: 'paused', label: __('Paused or suspended'), value: -(m.paused || 0), hint: __('Not counted as churn.') },
        { key: 'net', label: __('Change'), value: m.net, strong: true },
        { key: 'mrr_end', label: __('At the end'), value: m.mrr_end, strong: true },
    ].filter(Boolean);
});

// "+692,3 %" in the reader's language, not "+692.3%".
function deltaText(delta) {
    return `${delta > 0 ? '+' : delta < 0 ? '−' : ''}${formatValue(Math.abs(delta), 'percent')}`;
}

function signed(cent) {
    if (!cent) return money(0);

    return `${cent > 0 ? '+' : '−'}${money(Math.abs(cent))}`;
}
</script>

<template>
    <Head :title="[__('Insights'), __('Subscriptions')]" />

    <div class="max-w-page mx-auto">
        <CommandPaletteItem
            v-for="option in periodOptions"
            :key="`palette-${option.value}`"
            :text="`${__('Subscriptions')}: ${option.label}`"
            category="Actions"
            icon="chart-monitoring-indicator"
            @selected="navigate({ period: option.value, currency })"
        />

        <header v-if="!installed || !hasSubscriptions" class="py-8 pt-16 text-center">
            <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                <Icon name="chart-monitoring-indicator" class="size-5 text-gray-500" />{{ __('Subscriptions') }}
            </h1>
        </header>

        <Header v-if="installed && hasSubscriptions" :title="__('Subscriptions')" icon="chart-monitoring-indicator">
            <Button :href="reportUrls.movements" :text="__('Month by month')" variant="default" />
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
            Two kinds of nothing, told apart: the addon or its table is missing,
            or nobody has subscribed yet.
        -->
        <EmptyStateMenu
            v-if="!installed"
            :heading="__('This screen reads the subscriptions statamic-payments records, and that addon or its subscriptions table is not installed.')"
        >
            <EmptyStateItem
                icon="shopping-cart"
                :heading="__('Install statamic-payments')"
                :description="__('Install it, run its migrations, and the first subscription fills this screen.')"
            />
        </EmptyStateMenu>

        <EmptyStateMenu
            v-else-if="!hasSubscriptions"
            :heading="__('No subscription yet. The first one fills this screen.')"
        >
            <EmptyStateItem
                icon="shopping-cart"
                :heading="__('Nothing recurring has been sold')"
                :description="__('Recurring revenue, cancellations and the charges coming up appear here as soon as somebody subscribes.')"
            />
        </EmptyStateMenu>

        <template v-else>
            <div class="grid gap-4 md:grid-cols-4 mb-6 *:min-w-0">
                <Card v-for="tile in tiles" :key="tile.handle" class="h-full">
                    <Subheading :text="tile.label" />
                    <div class="mt-2 flex items-baseline gap-2">
                        <Heading size="2xl" :text="formatValue(tile.value, tile.unit, tile.meta)" />
                        <Text
                            v-if="tile.delta !== null && tile.delta !== undefined"
                            size="sm"
                            :variant="tile.delta < 0 ? 'danger' : 'subtle'"
                        >{{ deltaText(tile.delta) }}</Text>
                    </div>
                    <Text v-for="(detail, index) in tile.details" :key="index" size="xs" variant="subtle" class="block">
                        {{ detail }}
                    </Text>
                    <Text v-if="tile.hint" size="xs" variant="subtle" class="mt-2 block">{{ tile.hint }}</Text>
                </Card>
            </div>

            <div v-if="unrecognised && unrecognised.length" class="mb-6">
                <Text size="xs" variant="subtle">
                    {{ __('Counted as paused, because this addon does not know the status: :statuses', { statuses: unrecognised.join(', ') }) }}
                </Text>
            </div>

            <Panel v-if="hasChart" :heading="__('Monthly recurring revenue over time')" class="mb-6">
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

            <div class="grid gap-6 md:grid-cols-2 *:min-w-0 mb-6">
                <Panel :heading="__('Change in this period')">
                    <Card>
                        <ul class="-my-2 divide-y divide-content-border">
                            <li v-for="row in movementRows" :key="row.key" class="py-2.5 text-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <span :class="['min-w-0 truncate', row.strong ? 'font-medium' : '']">{{ row.label }}</span>
                                    <span :class="['shrink-0 tabular-nums', row.strong ? 'font-medium' : '']">
                                        {{ ['mrr_start', 'mrr_end'].includes(row.key) ? money(row.value) : signed(row.value) }}
                                    </span>
                                </div>
                                <Text v-if="row.hint" size="xs" variant="subtle">{{ row.hint }}</Text>
                            </li>
                        </ul>
                    </Card>
                </Panel>

                <Panel :heading="__('Where subscriptions stand')">
                    <Card>
                        <ul class="-my-2 divide-y divide-content-border">
                            <li v-for="row in standings" :key="row.key" class="py-2.5 text-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="min-w-0 truncate font-medium">{{ row.label }}</span>
                                    <span class="shrink-0 tabular-nums">{{ formatValue(row.count, 'count') }}</span>
                                </div>
                                <div v-if="row.mrr && row.key !== 'active'" class="mt-1 flex justify-end">
                                    <Text size="xs" variant="subtle" class="tabular-nums">
                                        <template v-if="row.key === 'ended'">{{ __(':amount lost', { amount: money(row.mrr) }) }}</template>
                                        <template v-else-if="row.key === 'trial'">{{ __('from the first charge :amount per month', { amount: money(row.mrr) }) }}</template>
                                        <template v-else>{{ __(':amount per month', { amount: money(row.mrr) }) }}</template>
                                    </Text>
                                </div>
                            </li>
                        </ul>
                    </Card>
                </Panel>
            </div>

            <div class="grid gap-6 md:grid-cols-2 *:min-w-0">
                <Panel :heading="__('Due in the next 30 days')">
                    <template #header-actions>
                        <Button :href="reportUrls.upcoming" :text="__('Every charge')" size="sm" variant="ghost" />
                    </template>
                    <Card>
                        <div v-if="upcoming.count === 0" class="py-6 text-center">
                            <Text size="sm" variant="subtle">{{ __('Nothing is due in the next 30 days.') }}</Text>
                        </div>
                        <ul v-else class="-my-2 divide-y divide-content-border">
                            <li class="py-2.5 text-sm flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate">{{ __('Subscriptions') }}</span>
                                <span class="shrink-0 tabular-nums">{{ money(upcoming.subscriptions_cent) }}</span>
                            </li>
                            <li class="py-2.5 text-sm flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate">{{ __('Instalments') }}</span>
                                <span class="shrink-0 tabular-nums">{{ money(upcoming.plans_cent) }}</span>
                            </li>
                            <li class="py-2.5 text-sm flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate font-medium">{{ __('Total') }}</span>
                                <span class="shrink-0 tabular-nums font-medium">{{ money(upcoming.total_cent) }}</span>
                            </li>
                        </ul>
                    </Card>
                </Panel>

                <Panel :heading="__('Forecast from running subscriptions')">
                    <template #header-actions>
                        <Button :href="reportUrls.forecast" :text="__('By month')" size="sm" variant="ghost" />
                    </template>
                    <Card>
                        <ul class="-my-2 divide-y divide-content-border">
                            <li class="py-2.5 text-sm flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate">{{ __('Within one month') }}</span>
                                <span class="shrink-0 tabular-nums">{{ money(forecast.month) }}</span>
                            </li>
                            <li class="py-2.5 text-sm flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate">{{ __('Within three months') }}</span>
                                <span class="shrink-0 tabular-nums">{{ money(forecast.quarter) }}</span>
                            </li>
                            <li class="py-2.5 text-sm flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate font-medium">{{ __('Within twelve months') }}</span>
                                <span class="shrink-0 tabular-nums font-medium">{{ money(forecast.year) }}</span>
                            </li>
                        </ul>
                        <Text size="xs" variant="subtle" class="mt-3 block">
                            {{ __('If nobody joins, cancels, pauses or changes price.') }}
                        </Text>
                    </Card>
                </Panel>
            </div>

            <div class="mt-6 flex flex-wrap gap-2">
                <Button :href="reportUrls.cohorts" :text="__('Retention by start month')" variant="default" />
            </div>
        </template>
    </div>
</template>
