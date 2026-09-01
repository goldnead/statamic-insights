<script setup>
import { computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Text, Select, Button, Badge, Alert, Listing,
    EmptyStateMenu, EmptyStateItem,
} from '@statamic/cms/ui';
import { formatCell, isNumeric } from '../support/cells.js';

const props = defineProps([
    // { handle, label, description, group, available, requires, usesPeriod, columns, rows, failed }
    'report',
    'period',
    'periodOptions',
    'indexUrl',
]);

function navigate(params) {
    router.get(window.location.pathname, params, { preserveState: false, preserveScroll: true });
}

const hasRows = computed(() => props.report.rows.length > 0);

// Core's listing in client mode: the rows are all here, so it sorts them
// itself and draws the same table the Entries screen draws. It wants an `id`
// per row and a column shape like `Statamic\CP\Column::toArray()`.
const items = computed(() => props.report.rows.map((row, index) => ({ id: index, ...row })));

const listingColumns = computed(() => props.report.columns.map((column) => ({
    field: column.key,
    label: column.label,
    numeric: isNumeric(column),
    visible: true,
    listable: true,
    sortable: true,
    defaultVisibility: true,
})));

// The empty sentence depends on whether a period was asked at all: "nothing in
// this period" beside a snapshot would name a filter the screen does not have.
const emptyText = computed(() => (props.report.usesPeriod
    ? __('Nothing in this period.')
    : __('Nothing to report yet.')));
</script>

<template>
    <Head :title="[__('Insights'), report.label]" />

    <div class="max-w-page mx-auto">
        <Header :title="report.label" icon="chart-monitoring-indicator">
            <Button :href="indexUrl" :text="__('All reports')" variant="default" />
            <Select
                v-if="report.available && report.usesPeriod"
                :model-value="period"
                :options="periodOptions"
                class="w-48"
                @update:model-value="(value) => navigate({ period: value })"
            />
            <Badge v-else-if="report.available" color="default" :text="__('As of now')" />
        </Header>

        <Text v-if="report.description" size="sm" variant="subtle" class="mb-6 block">
            {{ report.description }}
        </Text>

        <!--
            Not installed is a state with a cause, told in core's empty-state
            shape. Same sentence structure as the revenue screen uses for the
            payments addon, so the two read as one voice.
        -->
        <EmptyStateMenu
            v-if="!report.available"
            :heading="__('This report reads what :package records, and that addon is not installed.', { package: report.requires || '…' })"
        >
            <EmptyStateItem
                icon="chart-monitoring-indicator"
                :heading="__('Not installed')"
                :description="__('Install it, run its migrations, and the rows appear here by themselves.')"
            />
        </EmptyStateMenu>

        <Alert
            v-else-if="report.failed"
            variant="error"
            :text="__('This report could not be built. The log says why.')"
        />

        <Panel v-else-if="!hasRows">
            <Card>
                <div class="py-10 text-center">
                    <Text size="sm" variant="subtle">{{ emptyText }}</Text>
                </div>
            </Card>
        </Panel>

        <template v-else>
            <Listing
                :items="items"
                :columns="listingColumns"
                :allow-search="false"
                :allow-presets="false"
                :allow-customizing-columns="false"
                :allow-bulk-actions="false"
            >
                <template v-for="column in report.columns" :key="column.key" #[`cell-${column.key}`]="{ row }">
                    <span
                        :class="[
                            isNumeric(column) ? 'tabular-nums' : '',
                            column.unit === 'code' ? 'font-mono text-xs' : '',
                        ]"
                    >{{ formatCell(row, column) }}</span>
                    <!--
                        A row may say it is switched off — an offer nobody can
                        accept any more still has history.
                    -->
                    <Badge
                        v-if="column.key === 'name' && row.active === false"
                        color="default"
                        :text="__('Inactive')"
                        class="ms-2"
                    />
                </template>
            </Listing>
            <div class="mt-2 flex justify-end">
                <Text size="xs" variant="subtle" class="tabular-nums">{{ __(':count rows', { count: report.rows.length }) }}</Text>
            </div>
        </template>
    </div>
</template>
