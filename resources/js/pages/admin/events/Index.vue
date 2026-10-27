<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import EmptyState from '@/components/tickora/EmptyState.vue';
import Pagination from '@/components/tickora/Pagination.vue';
import StatusBadge from '@/components/tickora/StatusBadge.vue';
import type { EventDetail, Paginated } from '@/types';

type ReviewItem = EventDetail & {
    submitted_label: string | null;
    organizer_status: string;
    readiness: string[];
};

defineProps<{ events: Paginated<ReviewItem> }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Event review', href: '/admin/events' }],
    },
});

const approving = useForm({});
const rejecting = useForm({ notes: '' });
const rejectFor = ref<string | null>(null);

function approve(event: ReviewItem): void {
    if (
        window.confirm(
            `Publish "${event.title}"? It will be visible to everyone.`,
        )
    ) {
        approving.post(`/admin/events/${event.id}/approve`, {
            preserveScroll: true,
        });
    }
}

function openReject(id: string): void {
    rejecting.reset();
    rejecting.clearErrors();
    rejectFor.value = id;
}

function reject(event: ReviewItem): void {
    rejecting.post(`/admin/events/${event.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => {
            rejectFor.value = null;
        },
    });
}
</script>

<template>
    <Head title="Event review" />

    <div class="flex flex-col gap-5 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-extrabold tracking-tight">Event review</h1>
            <p class="text-sm text-muted-foreground">
                Oldest submissions first. Approving publishes the event
                immediately.
            </p>
        </header>

        <EmptyState
            v-if="!events.data.length"
            title="Nothing waiting for review"
            description="New submissions from organisers will appear here."
        />

        <article
            v-for="event in events.data"
            :key="event.id"
            class="flex flex-col gap-4 rounded-2xl border bg-card p-5"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <h2 class="text-lg font-extrabold">{{ event.title }}</h2>
                    <p class="text-sm text-muted-foreground">
                        {{ event.organizer.name }} ·
                        <StatusBadge :status="event.organizer_status" /> ·
                        submitted
                        {{ event.submitted_label }}
                    </p>
                </div>
                <Link
                    :href="`/events/${event.slug}`"
                    class="text-sm font-semibold text-primary"
                    >Preview</Link
                >
            </div>

            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="font-bold">When</dt>
                    <dd>
                        {{ event.date_label }}, {{ event.time_label }} ({{
                            event.timezone_label
                        }})
                    </dd>
                </div>
                <div>
                    <dt class="font-bold">Where</dt>
                    <dd>
                        {{ event.venue_detail?.name }},
                        {{ event.venue_detail?.city }}
                        {{ event.venue_detail?.country_code }}
                    </dd>
                </div>
                <div>
                    <dt class="font-bold">Tickets</dt>
                    <dd>
                        <span
                            v-for="t in event.ticket_types"
                            :key="t.id"
                            class="block"
                        >
                            {{ t.name }} —
                            {{ t.is_free ? 'Free' : t.price.formatted }}
                        </span>
                    </dd>
                </div>
            </dl>

            <p v-if="event.summary" class="text-sm">{{ event.summary }}</p>

            <ul
                v-if="event.readiness.length"
                class="list-disc rounded-lg bg-amber-50 p-3 pl-8 text-sm text-amber-950"
            >
                <li v-for="item in event.readiness" :key="item">{{ item }}</li>
            </ul>

            <p
                v-if="event.organizer_status !== 'verified'"
                class="text-sm font-semibold text-amber-900"
            >
                The organiser isn't verified yet, so this event can't be
                published. Verify the organiser first, or send the event back.
            </p>

            <div v-if="rejectFor !== event.id" class="flex flex-wrap gap-3">
                <button
                    type="button"
                    :disabled="
                        event.organizer_status !== 'verified' ||
                        approving.processing
                    "
                    class="min-h-11 rounded-lg bg-primary px-5 font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    @click="approve(event)"
                >
                    Approve and publish
                </button>
                <button
                    type="button"
                    class="min-h-11 rounded-lg border px-4 font-semibold hover:bg-accent"
                    @click="openReject(event.id)"
                >
                    Request changes
                </button>
            </div>

            <form
                v-else
                class="flex flex-col gap-3"
                @submit.prevent="reject(event)"
            >
                <label :for="`notes-${event.id}`" class="text-sm font-bold"
                    >What should the organiser change?</label
                >
                <textarea
                    :id="`notes-${event.id}`"
                    v-model="rejecting.notes"
                    rows="3"
                    class="w-full rounded-lg border bg-background p-3"
                />
                <InputError :message="rejecting.errors.notes" />
                <div class="flex gap-3">
                    <button
                        type="submit"
                        :disabled="rejecting.processing"
                        class="min-h-11 rounded-lg bg-brand-navy px-5 font-bold text-white"
                    >
                        Send back to organiser
                    </button>
                    <button
                        type="button"
                        class="min-h-11 rounded-lg border px-4 font-semibold"
                        @click="rejectFor = null"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </article>

        <Pagination :links="events.links" />
    </div>
</template>
