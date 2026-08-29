<script setup>
import { computed } from 'vue';

/**
 * A series as bars, with no library and no axis.
 *
 * Deliberately dumb: it draws what it is given and states nothing. Everything
 * that would be a claim — a scale, a zero line, a trend — lives on the detail
 * screen where there is room to label it.
 */
const props = defineProps({
    series: { type: Array, default: () => [] },
    height: { type: String, default: 'h-10' },
});

// The tallest magnitude, so a period that only went backwards still has a
// scale. A floor of 1 keeps an all-zero period from dividing by nothing.
const peak = computed(() =>
    Math.max(1, ...props.series.map((b) => Math.abs(Number(b.value) || 0)))
);

// Has anything actually been measured? An all-zero chart must not print a
// scale of "0,01 €" — a number nobody measured, invented by the floor above.
const hasValues = computed(() => props.series.some((b) => Number(b.value) !== 0));

defineExpose({ peak, hasValues });

function barHeight(value) {
    const n = Number(value);

    // Null is not zero: a bucket the metric left out because the question does
    // not apply there gets no bar, same as an empty one — but it must never be
    // drawn as a measurement.
    if (!n) return '0%';

    return `${Math.max(4, (Math.abs(n) / peak.value) * 100)}%`;
}
</script>

<template>
    <div :class="['flex items-end gap-px', height]" aria-hidden="true">
        <!--
            A bucket that earned nothing gets no bar. A floor for zero would
            draw activity on every quiet day of the month, which is the one
            thing a chart must never do.
        -->
        <!--
            A negative bucket is drawn downwards and in the danger colour. Drawn
            upwards it was indistinguishable from a small positive one — a day
            that lost fifteen euros looked like a day that earned a little.
        -->
        <div
            v-for="(bucket, index) in series"
            :key="index"
            :class="['flex-1 min-w-0 h-full flex', Number(bucket.value) < 0 ? 'items-start' : 'items-end']"
        >
            <div
                :class="[
                    'w-full',
                    Number(bucket.value) < 0 ? 'rounded-b-xs bg-red-500/60' : 'rounded-t-xs bg-primary/60',
                ]"
                :style="{ height: barHeight(bucket.value) }"
            />
        </div>
    </div>
</template>
