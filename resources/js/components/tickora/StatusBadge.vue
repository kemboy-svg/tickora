<script setup lang="ts">
import { computed } from 'vue';

// Status pills. Each status has a text label, so meaning never depends on
// colour alone; text colours are the AA-safe dark shades.
const props = defineProps<{ status: string }>();

const styles: Record<string, { label: string; class: string }> = {
    draft: { label: 'Draft', class: 'bg-slate-100 text-slate-700' },
    pending_review: {
        label: 'In review',
        class: 'bg-amber-100 text-amber-900',
    },
    published: { label: 'Published', class: 'bg-green-100 text-green-800' },
    cancelled: { label: 'Cancelled', class: 'bg-red-100 text-red-800' },
    completed: { label: 'Completed', class: 'bg-slate-200 text-slate-800' },
    active: { label: 'On sale', class: 'bg-green-100 text-green-800' },
    paused: { label: 'Paused', class: 'bg-amber-100 text-amber-900' },
    hidden: { label: 'Hidden', class: 'bg-slate-100 text-slate-700' },
    pending: {
        label: 'Pending verification',
        class: 'bg-amber-100 text-amber-900',
    },
    verified: { label: 'Verified', class: 'bg-green-100 text-green-800' },
    suspended: { label: 'Suspended', class: 'bg-red-100 text-red-800' },
    rejected: { label: 'Rejected', class: 'bg-slate-200 text-slate-800' },
    available: { label: 'On sale', class: 'bg-green-100 text-green-800' },
    few_left: { label: 'Few left', class: 'bg-amber-100 text-amber-900' },
    sold_out: { label: 'Sold out', class: 'bg-slate-200 text-slate-800' },
};

const style = computed(
    () =>
        styles[props.status] ?? {
            label: props.status,
            class: 'bg-slate-100 text-slate-700',
        },
);
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold whitespace-nowrap"
        :class="style.class"
    >
        {{ style.label }}
    </span>
</template>
