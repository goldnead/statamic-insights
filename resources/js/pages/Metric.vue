<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Header, Panel, Card, Heading, Subheading, Text, Select, Button } from '@statamic/cms/ui';
import { formatValue, bucketDate } from '../support/format.js';
import Sparkline from '../components/Sparkline.vue';

const props = defineProps([
    'metric',         // { handle, label, description, group, unit, meta, value, previous, delta, breakdowns }
    'series',         // [{ bucket, value }]
    'dimension',      // 'campaign' | null
    'breakdown',      // [{ key, label, value }]
    'period',
    'periodOptions',
    'indexUrl',
]);

function navigate(params) {
    router.get(window.location.pathname, params, { preserveState: false, preserveScroll: true });
}

const peak = computed(() =>
    Math.max(1, ...props.series.map((b) => Math.abs(Number(b.value) || 0)))
);

// An all-zero chart must not print a scale. The floor of 1 above exists so the
// bars can divide by something; showing it as "0,01 €" would put a number on
// the screen that nobody measured.
// One bucket is not a chart — it is the number already shown above, drawn as a
// rectangle the width of the panel. It happens when the range collapses to a
// single month, which "all time" does on a shop that started this month.
const hasChart = computed(() => props.series.length >= 2);

const hasValues = computed(() => props.series.some((b) => Number(b.value) !== 0));

// Share of the whole, not of the biggest row — a bare percentage beside a list
// is read as "this much of the total" by everybody.
const breakdownTotal = computed(() => Math.max(1, props.breakdown.reduce((sum, r) => sum + (r.value || 0), 0)));

function share(value) {
    const pct = ((value || 0) / breakdownTotal.value) * 100;

    if (value > 0 && pct < 0.5) return '<1%';

    return `${Math.round(pct)}%`;
}

const dimensionOptions = computed(() =>
    Object.entries(props.metric.breakdowns || {}).map(([value, label]) => ({ value, label }))
);
</script>

<template>
    <Head :title="[__('Insights'), metric.label]" />

    <div class="max-w-page mx-auto">
        <Header :title="metric.label" icon="chart-monitoring-indicator">
            <Button :href="`${indexUrl}?period=${period}`" :text="__('All metrics')" variant="default" />
            <Select
                :model-value="period"
                :options="periodOptions"
                class="w-48"
                @update:model-value="(value) => navigate({ period: value, by: dimension })"
            />
        </Header>

        <Text v-if="metric.description" size="sm" variant="subtle" class="mb-6 block">
            {{ metric.description }}
        </Text>

        <div class="grid gap-4 md:grid-cols-3 mb-6 *:min-w-0">
            <Card>
                <Subheading :text="__('This period')" />
                <Heading size="2xl" class="mt-2" :text="formatValue(metric.value, metric.unit, metric.meta)" />
            </Card>
            <Card>
                <Subheading :text="__('Period before')" />
                <Heading size="2xl" class="mt-2" :text="formatValue(metric.previous, metric.unit, metric.meta)" />
            </Card>
            <Card>
                <Subheading :text="__('Change')" />
                <div class="mt-2">
                    <Heading
                        v-if="metric.delta !== null"
                        size="2xl"
                        :text="`${metric.delta > 0 ? '+' : ''}${metric.delta}%`"
                    />
                    <!--
                        No percentage where one would be a claim: no period
                        before, or a previous value of zero. Every increase from
                        nothing is infinite, and "+∞ %" says less than the
                        number already did.
                    -->
                    <Text v-else size="sm" variant="subtle">{{ __('Nothing to compare against') }}</Text>
                </div>
            </Card>
        </div>

        <Panel v-if="hasChart" :heading="__('Over time')" class="mb-6">
            <Card>
                <div v-if="hasValues" class="mb-1 flex justify-end">
                    <Text size="xs" variant="subtle" class="tabular-nums">
                        {{ formatValue(peak, metric.unit, metric.meta) }}
                    </Text>
                </div>
                <Sparkline :series="series" height="h-32" />
                <div class="mt-2 flex justify-between">
                    <Text size="xs" variant="subtle">{{ bucketDate(series[0]?.bucket) }}</Text>
                    <Text size="xs" variant="subtle">{{ bucketDate(series[series.length - 1]?.bucket) }}</Text>
                </div>
            </Card>
        </Panel>

        <Panel v-if="dimension" :heading="__('Split by')">
            <Card>
                <Select
                    v-if="dimensionOptions.length > 1"
                    :model-value="dimension"
                    :options="dimensionOptions"
                    class="w-56 mb-4"
                    @update:model-value="(value) => navigate({ period, by: value })"
                />

                <div v-if="breakdown.length === 0" class="py-6 text-center">
                    <Text size="sm" variant="subtle">{{ __('Nothing to split in this period.') }}</Text>
                </div>
                <ul v-else class="-my-2 divide-y divide-content-border">
                    <li v-for="(row, index) in breakdown" :key="index" class="py-2.5 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <span class="min-w-0 truncate font-medium">{{ row.label }}</span>
                            <span class="shrink-0 tabular-nums">
                                {{ formatValue(row.value, metric.unit, metric.meta) }}
                            </span>
                        </div>
                        <div class="mt-1 flex justify-end">
                            <Text size="xs" variant="subtle" class="tabular-nums">{{ share(row.value) }}</Text>
                        </div>
                    </li>
                </ul>
            </Card>
        </Panel>
    </div>
</template>
