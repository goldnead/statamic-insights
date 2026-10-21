<script setup>
import { computed } from 'vue';
import { Head } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Heading, Subheading, Text, Badge, Listing,
    EmptyStateMenu, EmptyStateItem,
} from '@statamic/cms/ui';
import { formatValue, bucketDate } from '../support/format.js';
import Sparkline from '../components/Sparkline.vue';

/**
 * The Search Console screen.
 *
 * Reads a snapshot some other job wrote; nothing here calls Google. Every
 * number arrives worked out (value, earlier value, change, whether the change
 * is good), so this page arranges and formats and judges nothing.
 */
const props = defineProps([
    'status',           // 'ok' | 'unreadable' | 'missing'
    'property',
    'fetchedAt',
    'fetchedAtLabel',
    'stale',
    'windows',          // [{ name, start, end, tiles: [{ figure, unit, value, previous, delta, direction, good }] }]
    'daily',            // { clicks: [{ bucket, value }], impressions: [...] }
    'queries',          // [{ query, clicks, impressions, ctr, position, clicks_previous, position_previous }]
    'pages',            // [{ page, ... }]
]);

const figureLabels = computed(() => ({
    clicks: __('Clicks'),
    impressions: __('Impressions'),
    ctr: __('CTR'),
    position: __('Average position'),
}));

// "+692,3 %" in the reader's language, not "+692.3%".
function deltaText(delta) {
    return `${delta > 0 ? '+' : delta < 0 ? '−' : ''}${formatValue(Math.abs(delta), 'percent')}`;
}

// Green only where the change is an improvement, red only where it is a
// deterioration. A rising position number is a worse ranking, and it is the
// server that knows that, not this page.
function deltaVariant(tile) {
    if (tile.good === true) return 'success';
    if (tile.good === false) return 'danger';

    return 'subtle';
}

function windowTitle(name) {
    return name === '7d' ? __('Last 7 days') : __('Last 28 days');
}

function chartPeak(series) {
    return Math.max(...series.map((b) => Number(b.value) || 0), 0);
}

function hasChart(series) {
    return (series || []).length >= 2;
}

// Core's listing in client mode, like the generic report screen: the rows are
// all here, so it sorts them itself.
function itemsOf(rows) {
    return (rows || []).map((row, index) => ({ id: index, ...row }));
}

function columnsFor(key, label) {
    return [
        { field: key, label, numeric: false },
        { field: 'clicks', label: __('Clicks'), numeric: true },
        { field: 'clicks_previous', label: __('Clicks before'), numeric: true },
        { field: 'impressions', label: __('Impressions'), numeric: true },
        { field: 'ctr', label: __('CTR'), numeric: true },
        { field: 'position', label: __('Position'), numeric: true },
    ].map((column) => ({ ...column, visible: true, listable: true, sortable: true, defaultVisibility: true }));
}

const queryColumns = computed(() => columnsFor('query', __('Query')));
const pageColumns = computed(() => columnsFor('page', __('Page')));

function cell(row, field) {
    const value = row[field];

    if (value === null || value === undefined) return '—';

    return formatValue(value, field === 'ctr' ? 'percent' : 'count');
}

const tables = computed(() => [
    { key: 'query', heading: __('Top queries'), items: itemsOf(props.queries), columns: queryColumns.value },
    { key: 'page', heading: __('Top pages'), items: itemsOf(props.pages), columns: pageColumns.value },
]);
</script>

<template>
    <Head :title="[__('Insights'), __('Search Console')]" />

    <div class="max-w-page mx-auto">
        <header v-if="status !== 'ok'" class="py-8 pt-16 text-center">
            <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                {{ __('Search Console') }}
            </h1>
        </header>

        <Header v-else :title="__('Search Console')" icon="chart-monitoring-indicator">
            <Badge v-if="stale" color="amber" pill :text="__('Older than three days')" />
            <Text size="sm" variant="subtle" class="tabular-nums">{{ __('As of :date', { date: fetchedAtLabel }) }}</Text>
        </Header>

        <EmptyStateMenu
            v-if="status === 'unreadable'"
            :heading="__('Snapshot not readable')"
        >
            <EmptyStateItem
                icon="chart-monitoring-indicator"
                :heading="__('The file is there, but it is not a Search Console snapshot in format version 1.')"
                :description="__('The job that writes the file will say why in its own log. The last good numbers are not kept here.')"
            />
        </EmptyStateMenu>

        <EmptyStateMenu
            v-else-if="status === 'missing'"
            :heading="__('There is no Search Console snapshot.')"
        >
            <EmptyStateItem
                icon="chart-monitoring-indicator"
                :heading="__('Nothing to show yet')"
                :description="__('This screen reads a JSON file that another job writes. Once the file exists, the numbers appear here.')"
            />
        </EmptyStateMenu>

        <template v-else>
            <Text v-if="property" size="sm" variant="subtle" class="mb-6 block">{{ property }}</Text>

            <section v-for="win in windows" :key="win.name" class="mb-6">
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <Heading size="lg" :text="windowTitle(win.name)" />
                    <Text v-if="win.start && win.end" size="xs" variant="subtle" class="tabular-nums">
                        {{ __(':start to :end', { start: bucketDate(win.start), end: bucketDate(win.end) }) }}
                    </Text>
                </div>

                <div class="grid gap-4 md:grid-cols-4 *:min-w-0">
                    <Card v-for="tile in win.tiles" :key="tile.figure" class="h-full">
                        <Subheading :text="figureLabels[tile.figure]" />
                        <div class="mt-2 flex items-baseline gap-2">
                            <Heading size="2xl" :text="formatValue(tile.value, tile.unit)" />
                            <Text
                                v-if="tile.delta !== null && tile.delta !== undefined"
                                size="sm"
                                :variant="deltaVariant(tile)"
                            >{{ deltaText(tile.delta) }}</Text>
                        </div>
                        <Text v-if="tile.delta !== null && tile.delta !== undefined" size="xs" variant="subtle" class="block">
                            {{ __('Before: :value', { value: formatValue(tile.previous, tile.unit) }) }}
                        </Text>
                        <Text v-else size="xs" variant="subtle" class="block">{{ __('Nothing to compare against') }}</Text>
                        <Text v-if="tile.figure === 'position'" size="xs" variant="subtle" class="mt-2 block">
                            {{ __('Lower is better.') }}
                        </Text>
                    </Card>
                </div>
            </section>

            <div class="grid gap-6 md:grid-cols-2 *:min-w-0 mb-6">
                <Panel :heading="__('Clicks per day')">
                    <Card>
                        <template v-if="hasChart(daily.clicks)">
                            <div class="mb-1 flex justify-end">
                                <Text size="xs" variant="subtle" class="tabular-nums">{{ formatValue(chartPeak(daily.clicks), 'count') }}</Text>
                            </div>
                            <Sparkline :series="daily.clicks" height="h-32" />
                            <div class="mt-2 flex justify-between">
                                <Text size="xs" variant="subtle">{{ bucketDate(daily.clicks[0]?.bucket) }}</Text>
                                <Text size="xs" variant="subtle">{{ bucketDate(daily.clicks[daily.clicks.length - 1]?.bucket) }}</Text>
                            </div>
                        </template>
                        <div v-else class="py-6 text-center">
                            <Text size="sm" variant="subtle">{{ __('Not enough days for a chart.') }}</Text>
                        </div>
                    </Card>
                </Panel>

                <Panel :heading="__('Impressions per day')">
                    <Card>
                        <template v-if="hasChart(daily.impressions)">
                            <div class="mb-1 flex justify-end">
                                <Text size="xs" variant="subtle" class="tabular-nums">{{ formatValue(chartPeak(daily.impressions), 'count') }}</Text>
                            </div>
                            <Sparkline :series="daily.impressions" height="h-32" />
                            <div class="mt-2 flex justify-between">
                                <Text size="xs" variant="subtle">{{ bucketDate(daily.impressions[0]?.bucket) }}</Text>
                                <Text size="xs" variant="subtle">{{ bucketDate(daily.impressions[daily.impressions.length - 1]?.bucket) }}</Text>
                            </div>
                        </template>
                        <div v-else class="py-6 text-center">
                            <Text size="sm" variant="subtle">{{ __('Not enough days for a chart.') }}</Text>
                        </div>
                    </Card>
                </Panel>
            </div>

            <section v-for="table in tables" :key="table.key" class="mb-6">
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <Heading size="lg" :text="table.heading" />
                    <Text size="xs" variant="subtle">{{ __('Last 28 days, against the 28 days before') }}</Text>
                </div>

                <Panel v-if="table.items.length === 0">
                    <Card>
                        <div class="py-10 text-center">
                            <Text size="sm" variant="subtle">{{ __('Nothing in this list.') }}</Text>
                        </div>
                    </Card>
                </Panel>

                <template v-else>
                    <Listing
                        :items="table.items"
                        :columns="table.columns"
                        sort-column="clicks"
                        sort-direction="desc"
                        :allow-search="false"
                        :allow-presets="false"
                        :allow-customizing-columns="false"
                        :allow-bulk-actions="false"
                    >
                        <template #[`cell-${table.key}`]="{ row }">
                            <span class="block max-w-[32rem] truncate" :title="row[table.key]">{{ row[table.key] }}</span>
                        </template>
                        <template v-for="field in ['clicks', 'clicks_previous', 'impressions', 'ctr', 'position']" :key="field" #[`cell-${field}`]="{ row }">
                            <span class="tabular-nums">{{ cell(row, field) }}</span>
                        </template>
                    </Listing>
                    <div class="mt-2 flex justify-end">
                        <Text size="xs" variant="subtle" class="tabular-nums">{{ __(':count rows', { count: table.items.length }) }}</Text>
                    </div>
                </template>
            </section>
        </template>
    </div>
</template>
