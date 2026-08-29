<script setup>
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Heading, Subheading, Text, Select,
    EmptyStateMenu, EmptyStateItem,
} from '@statamic/cms/ui';
import { formatValue } from '../support/format.js';

const props = defineProps([
    'period',            // '30d'
    'periodOptions',     // [{ value, label }]
    'groups',            // [{ group, metrics: [{ handle, label, description, unit, meta, value, previous, delta, breakdowns }] }]
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
    // a reader who picks "last 7 days" and opens a tile expects to still be
    // looking at seven days.
    return `${props.detailUrlTemplate.replace('__handle__', encodeURIComponent(handle))}?period=${props.period}`;
}
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

        <div v-else class="space-y-6">
            <Panel v-for="block in groups" :key="block.group" :heading="block.group">
                <div class="grid gap-4 md:grid-cols-3 *:min-w-0">
                    <Link
                        v-for="metric in block.metrics"
                        :key="metric.handle"
                        :href="detailUrl(metric.handle)"
                        class="group block focus:outline-none"
                    >
                        <Card class="h-full transition group-hover:border-gray-300 dark:group-hover:border-gray-600">
                            <Subheading :text="metric.label" />
                            <div class="mt-2 flex items-baseline gap-2">
                                <Heading size="xl" :text="formatValue(metric.value, metric.unit, metric.meta)" />
                                <Text
                                    v-if="metric.delta !== null"
                                    size="sm"
                                    :variant="metric.delta < 0 ? 'danger' : 'subtle'"
                                >{{ metric.delta > 0 ? '+' : '' }}{{ metric.delta }}%</Text>
                            </div>
                            <Text v-if="metric.description" size="xs" variant="subtle">{{ metric.description }}</Text>
                        </Card>
                    </Link>
                </div>
            </Panel>
        </div>
    </div>
</template>
