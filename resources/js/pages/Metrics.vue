<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Text, Select, Listing,
    EmptyStateMenu, EmptyStateItem,
} from '@statamic/cms/ui';
import { formatValue } from '../support/format.js';
import Sparkline from '../components/Sparkline.vue';

const props = defineProps([
    'period',            // '30d'
    'periodOptions',     // [{ value, label }]
    'groups',            // [{ group, metrics: [{ handle, label, description, unit, meta, value, previous, delta, series }] }]
    // Built with cp_route() on the PHP side and handed over with a placeholder
    // where the handle goes — a metric handle contains dots, so it cannot be
    // pasted onto a base URL here without knowing how the route is shaped.
    'detailUrlTemplate',
]);

function navigate(params) {
    router.get(window.location.pathname, params, { preserveState: false, preserveScroll: true });
}

function detailUrl(handle) {
    // The period travels with the link. It is the question, not the page — and
    // a reader who picks "last 7 days" and opens a row expects to still be
    // looking at seven days.
    return `${props.detailUrlTemplate.replace('__handle__', encodeURIComponent(handle))}?period=${props.period}`;
}

// One row per metric, the source addon as a column rather than as a heading of
// its own. A panel per group put a grey container with a white island inside
// it on screen, and it cost a screenful of height for five numbers.
const items = computed(() => props.groups.flatMap((block) =>
    block.metrics.map((metric) => ({ id: metric.handle, source: block.group, ...metric }))
));

// Core's listing in client mode: every row is already here, so it sorts them
// itself and draws the same table the Entries screen draws. Column shape
// follows `Statamic\CP\Column::toArray()`.
//
// Value and trend are not sortable on purpose. The column holds euros beside
// counts beside seconds, and an order over those three is a ranking of nothing.
// The change is a percentage and comparable, so that one sorts.
const columns = computed(() => [
    { field: 'label', label: __('Metric'), sortable: true },
    { field: 'source', label: __('Source'), sortable: true },
    { field: 'value', label: __('Value'), numeric: true, sortable: false },
    { field: 'delta', label: __('Change'), numeric: true, sortable: true },
    { field: 'series', label: __('Over time'), sortable: false },
].map((column) => ({
    numeric: false,
    visible: true,
    listable: true,
    defaultVisibility: true,
    ...column,
})));
</script>

<template>
    <Head :title="[__('Insights'), __('Metrics')]" />

    <div class="max-w-page mx-auto">
        <!--
            Core's shape for an empty screen: a centred heading, not <Header>.
            This is what a site with no reporting siblings installed sees first.
        -->
        <header v-if="groups.length === 0" class="py-8 pt-16 text-center">
            <h1 class="text-[25px] font-medium antialiased">{{ __('Metrics') }}</h1>
        </header>

        <Header v-else :title="__('Metrics')" icon="chart-monitoring-indicator">
            <Select
                :model-value="period"
                :options="periodOptions"
                class="w-48"
                @update:model-value="(value) => navigate({ period: value })"
            />
        </Header>

        <!--
            Nothing registered is a true state with a cause, not a failure. It
            means no installed addon offers a number — which on a fresh install
            with no payments addon is exactly right.
        -->
        <EmptyStateMenu
            v-if="groups.length === 0"
            :heading="__('No addon is offering a number to report yet.')"
        >
            <EmptyStateItem
                icon="chart-monitoring-indicator"
                :heading="__('Nothing registered')"
                :description="__('Every figure here is contributed by the addon that owns the data. Install one that reports — statamic-payments is the first — and its metrics appear on this screen by themselves.')"
            />
        </EmptyStateMenu>

        <!--
            Sorted by source out of the box, so the table opens grouped the way
            the panels used to group it — every number of one addon together,
            in the order that addon registered them. Any other order is one
            click on a column header away.
        -->
        <Listing
            v-else
            :items="items"
            :columns="columns"
            sort-column="source"
            :allow-search="false"
            :allow-presets="false"
            :allow-customizing-columns="false"
            :allow-bulk-actions="false"
        >
            <!--
                The name is the link, as it is on every core listing. What the
                number means is on the other side of it: the description used to
                sit under each figure and was what made five metrics a screenful.
            -->
            <template #cell-label="{ row }">
                <Link :href="detailUrl(row.handle)" class="font-medium">{{ row.label }}</Link>
            </template>

            <template #cell-source="{ row }">
                <Text size="sm" variant="subtle">{{ row.source }}</Text>
            </template>

            <template #cell-value="{ row }">
                <span class="tabular-nums">{{ formatValue(row.value, row.unit, row.meta) }}</span>
            </template>

            <!--
                No percentage where one would be a claim: no period before, or a
                previous value of zero. The reader says so with a null, and an
                em dash is how the rest of the Control Panel prints "no answer".
            -->
            <template #cell-delta="{ row }">
                <Text
                    v-if="row.delta !== null"
                    size="sm"
                    :variant="row.delta < 0 ? 'danger' : 'subtle'"
                    class="tabular-nums"
                >{{ row.delta > 0 ? '+' : '' }}{{ row.delta }} %</Text>
                <Text v-else size="sm" variant="subtle">—</Text>
            </template>

            <template #cell-series="{ row }">
                <Sparkline :series="row.series || []" height="h-6" class="w-32" />
            </template>
        </Listing>
    </div>
</template>
