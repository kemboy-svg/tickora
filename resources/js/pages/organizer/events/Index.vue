<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/components/tickora/EmptyState.vue';
import Pagination from '@/components/tickora/Pagination.vue';
import StatusBadge from '@/components/tickora/StatusBadge.vue';
import type { OrganizerEventRow, Paginated } from '@/types';

const props = defineProps<{
    events: Paginated<OrganizerEventRow>;
    filters: { status: string };
    canCreate: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Events', href: '/organizer/events' }],
    },
});

const page = usePage();
const workspace = computed(() => page.props.workspace);

const tabs = [
    { value: '', label: 'All' },
    { value: 'draft', label: 'Drafts' },
    { value: 'pending_review', label: 'In review' },
    { value: 'published', label: 'Published' },
    { value: 'cancelled', label: 'Cancelled' },
];

function filter(status: string): void {
    router.get('/organizer/events', status ? { status } : {}, {
        preserveState: true,
        preserveScroll: true,
    });
}

const active = computed(() => props.filters.status);
</script>

<template>
    <Head title="Events" />

    <div class="flex flex-col gap-5 p-4 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight">Events</h1>
                <p v-if="workspace" class="text-sm text-muted-foreground">
                    {{ workspace.current.name }}
                </p>
            </div>
            <Link
                v-if="canCreate"
                href="/organizer/events/create"
                class="inline-flex min-h-11 items-center rounded-lg bg-primary px-4 font-bold text-primary-foreground hover:bg-primary/90"
            >
                Create event
            </Link>
        </div>

        <div
            v-if="workspace && !workspace.current.can_sell"
            class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"
            role="status"
        >
            <strong
                >Your organiser is
                {{
                    workspace.current.status === 'pending'
                        ? 'awaiting verification'
                        : workspace.current.status
                }}.</strong
            >
            You can prepare events now; they can be published once verification
            is complete.
        </div>

        <div
            role="tablist"
            aria-label="Filter by status"
            class="flex flex-wrap gap-2"
        >
            <button
                v-for="tab in tabs"
                :key="tab.value"
                type="button"
                role="tab"
                :aria-selected="active === tab.value"
                class="min-h-10 rounded-full border px-4 text-sm font-semibold"
                :class="
                    active === tab.value
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'bg-card hover:bg-accent'
                "
                @click="filter(tab.value)"
            >
                {{ tab.label }}
            </button>
        </div>

        <div
            v-if="events.data.length"
            class="overflow-x-auto rounded-2xl border bg-card"
        >
            <table class="w-full min-w-[720px] text-sm">
                <thead>
                    <tr
                        class="border-b text-left text-xs text-muted-foreground"
                    >
                        <th scope="col" class="px-4 py-3 font-bold">Event</th>
                        <th scope="col" class="px-4 py-3 font-bold">Date</th>
                        <th scope="col" class="px-4 py-3 font-bold">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-bold">
                            Sold
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="event in events.data"
                        :key="event.id"
                        class="border-b last:border-0 hover:bg-accent/40"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="`/organizer/events/${event.id}`"
                                class="font-bold hover:text-primary"
                                >{{ event.title }}</Link
                            >
                            <div class="text-xs text-muted-foreground">
                                {{ event.venue ?? 'No venue yet'
                                }}<template v-if="event.city">
                                    · {{ event.city }}</template
                                >
                            </div>
                            <div
                                v-if="
                                    event.review_notes &&
                                    event.status === 'draft'
                                "
                                class="mt-1 text-xs font-semibold text-amber-900"
                            >
                                Changes requested by reviewer
                            </div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ event.starts_label }}
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge :status="event.status" />
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ event.sold }} / {{ event.capacity }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState
            v-else
            title="No events here yet"
            description="Create your first event, add ticket types, then submit it for review."
        >
            <Link
                v-if="canCreate"
                href="/organizer/events/create"
                class="inline-flex min-h-11 items-center rounded-lg bg-primary px-4 font-bold text-primary-foreground"
                >Create event</Link
            >
        </EmptyState>

        <Pagination :links="events.links" />
    </div>
</template>
