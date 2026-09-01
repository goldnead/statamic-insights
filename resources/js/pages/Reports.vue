<script setup>
import { Head, Link } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Subheading, Text, Badge,
    EmptyStateMenu, EmptyStateItem,
} from '@statamic/cms/ui';

const props = defineProps([
    // [{ group, reports: [{ handle, label, description, available, requires, usesPeriod }] }]
    'groups',
    // Built with cp_route() on the PHP side, placeholder where the handle goes.
    'detailUrlTemplate',
]);

function detailUrl(handle) {
    return props.detailUrlTemplate.replace('__handle__', encodeURIComponent(handle));
}
</script>

<template>
    <Head :title="[__('Insights'), __('Reports')]" />

    <div class="max-w-page mx-auto">
        <header v-if="groups.length === 0" class="py-8 pt-16 text-center">
            <h1 class="text-[25px] font-medium antialiased">{{ __('Reports') }}</h1>
        </header>

        <Header v-else :title="__('Reports')" icon="chart-monitoring-indicator" />

        <EmptyStateMenu v-if="groups.length === 0" :heading="__('No report is registered yet.')">
            <EmptyStateItem
                icon="chart-monitoring-indicator"
                :heading="__('Nothing to show')"
                :description="__('Reports are tables over what the family records. The six this addon ships appear as soon as it boots; a sibling can register more.')"
            />
        </EmptyStateMenu>

        <div v-else class="space-y-6">
            <Panel v-for="block in groups" :key="block.group" :heading="block.group">
                <div class="grid gap-4 md:grid-cols-3 *:min-w-0">
                    <Link
                        v-for="report in block.reports"
                        :key="report.handle"
                        :href="detailUrl(report.handle)"
                        class="group block focus:outline-none"
                    >
                        <Card class="h-full transition group-hover:border-gray-300 dark:group-hover:border-gray-600">
                            <div class="flex items-start justify-between gap-3">
                                <Subheading :text="report.label" />
                                <!--
                                    A report whose source is missing stays on the
                                    list and says so. Hidden, it would be a report
                                    nobody knows they could have.
                                -->
                                <Badge v-if="!report.available" color="default" :text="__('Not installed')" class="shrink-0" />
                            </div>
                            <Text v-if="report.description" size="xs" variant="subtle" class="mt-2 block">
                                {{ report.description }}
                            </Text>
                            <Text v-if="!report.available && report.requires" size="xs" variant="subtle" class="mt-2 block font-mono">
                                {{ __('Requires :package', { package: report.requires }) }}
                            </Text>
                        </Card>
                    </Link>
                </div>
            </Panel>
        </div>
    </div>
</template>
