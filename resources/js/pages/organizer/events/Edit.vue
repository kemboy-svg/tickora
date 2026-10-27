<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import EventForm from '@/components/organizer/EventForm.vue';
import type { EventPayload } from '@/components/organizer/EventForm.vue';
import TicketTypeEditor from '@/components/organizer/TicketTypeEditor.vue';
import StatusBadge from '@/components/tickora/StatusBadge.vue';
import type { Category, EventFormValues } from '@/types';

const props = defineProps<{
    event: EventFormValues;
    categories: Category[];
    currencies: string[];
    visibilities: Array<'public' | 'unlisted'>;
    timezones: string[];
    readiness: string[];
    can: {
        update: boolean;
        submit: boolean;
        cancel: boolean;
        delete: boolean;
        manageTicketTypes: boolean;
    };
    organizerCanSell: boolean;
    ticketStatuses: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Events', href: '/organizer/events' }],
    },
});

const url = computed(() => `/organizer/events/${props.event.id}`);

const initial = computed<EventPayload>(() => ({
    title: props.event.title,
    category: props.event.category,
    summary: props.event.summary ?? '',
    description: props.event.description ?? '',
    timezone: props.event.timezone,
    currency: props.event.currency,
    starts_at: props.event.starts_at ?? '',
    ends_at: props.event.ends_at ?? '',
    sales_starts_at: props.event.sales_starts_at ?? '',
    sales_ends_at: props.event.sales_ends_at ?? '',
    visibility: props.event.visibility,
    refund_policy: props.event.refund_policy ?? '',
    min_age: props.event.min_age === null ? '' : String(props.event.min_age),
    venue: { ...props.event.venue },
}));

const submitForm = useForm({});
const submitErrors = computed(
    () => submitForm.errors as Record<string, string | undefined>,
);

function submitForReview(): void {
    submitForm.post(`${url.value}/submit`, { preserveScroll: true });
}

const showCancel = ref(false);
const cancelForm = useForm({ reason: '' });

function cancelEvent(): void {
    cancelForm.post(`${url.value}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            showCancel.value = false;
        },
    });
}

function deleteDraft(): void {
    if (window.confirm('Delete this draft permanently?')) {
        router.delete(url.value);
    }
}
</script>

<template>
    <Head :title="event.title" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 sm:p-6">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-2">
                    <StatusBadge :status="event.status" />
                    <span class="text-sm text-muted-foreground">{{
                        event.currency
                    }}</span>
                </div>
                <h1 class="text-2xl font-extrabold tracking-tight">
                    {{ event.title }}
                </h1>
            </div>
            <Link
                v-if="event.status === 'published'"
                :href="`/events/${event.slug}`"
                class="inline-flex min-h-11 items-center rounded-lg border px-4 font-semibold hover:bg-accent"
                >View public page</Link
            >
        </header>

        <div
            v-if="event.review_notes && event.status === 'draft'"
            class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"
            role="status"
        >
            <strong class="block">The reviewer asked for changes:</strong>
            <p class="mt-1 whitespace-pre-line">{{ event.review_notes }}</p>
        </div>

        <div
            v-if="event.status === 'pending_review'"
            class="rounded-xl border border-violet-300 bg-violet-50 p-4 text-sm text-violet-950"
            role="status"
        >
            Submitted for review. You can still edit the description and ticket
            types while you wait.
        </div>

        <div
            v-if="event.status === 'cancelled'"
            class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900"
            role="status"
        >
            <strong>This event is cancelled.</strong>
            {{ event.cancellation_reason }}
        </div>

        <section
            v-if="event.status === 'draft'"
            aria-labelledby="checklist"
            class="flex flex-col gap-3 rounded-2xl border bg-card p-6"
        >
            <h2 id="checklist" class="text-lg font-extrabold">
                Ready to publish?
            </h2>
            <ul v-if="readiness.length" class="list-disc pl-5 text-sm">
                <li v-for="item in readiness" :key="item">{{ item }}</li>
            </ul>
            <p v-else class="text-sm text-green-800">
                Everything's in place. Submit it and our team will review it.
            </p>
            <p v-if="!organizerCanSell" class="text-sm text-muted-foreground">
                Your organiser still needs to be verified before this event can
                go live; you can submit it now and it will be reviewed together.
            </p>
            <InputError :message="submitErrors.event" />
            <div class="flex flex-wrap gap-3">
                <button
                    v-if="can.submit"
                    type="button"
                    :disabled="readiness.length > 0 || submitForm.processing"
                    class="min-h-11 rounded-lg bg-primary px-5 font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    @click="submitForReview"
                >
                    Submit for review
                </button>
                <button
                    v-if="can.delete"
                    type="button"
                    class="min-h-11 rounded-lg border px-4 font-semibold text-red-700 hover:bg-red-50"
                    @click="deleteDraft"
                >
                    Delete draft
                </button>
            </div>
        </section>

        <TicketTypeEditor
            :event-id="event.id"
            :currency="event.currency"
            :ticket-types="event.ticket_types"
            :statuses="ticketStatuses"
            :can-manage="can.manageTicketTypes"
        />

        <EventForm
            v-if="can.update"
            :key="event.status"
            :initial="initial"
            :options="{ categories, currencies, visibilities, timezones }"
            method="put"
            :url="url"
            submit-label="Save changes"
            :schedule-locked="!event.is_draft"
            :currency-locked="event.ticket_types.length > 0"
        />

        <section
            v-if="can.cancel && event.status !== 'draft'"
            aria-labelledby="danger"
            class="flex flex-col gap-3 rounded-2xl border border-red-200 bg-card p-6"
        >
            <h2 id="danger" class="text-lg font-extrabold text-red-800">
                Cancel event
            </h2>
            <p class="text-sm text-muted-foreground">
                Cancelling stops sales immediately and can't be undone. Ticket
                holders will be refunded through the refunds process.
            </p>
            <button
                v-if="!showCancel"
                type="button"
                class="min-h-11 w-fit rounded-lg border border-red-300 px-4 font-semibold text-red-800 hover:bg-red-50"
                @click="showCancel = true"
            >
                Cancel this event…
            </button>
            <form
                v-else
                class="flex flex-col gap-3"
                @submit.prevent="cancelEvent"
            >
                <label for="cancel-reason" class="text-sm font-bold"
                    >Reason (shown to ticket holders)</label
                >
                <textarea
                    id="cancel-reason"
                    v-model="cancelForm.reason"
                    rows="3"
                    class="w-full rounded-lg border bg-background p-3"
                />
                <InputError :message="cancelForm.errors.reason" />
                <div class="flex gap-3">
                    <button
                        type="submit"
                        :disabled="cancelForm.processing"
                        class="min-h-11 rounded-lg bg-red-700 px-5 font-bold text-white hover:bg-red-800"
                    >
                        Confirm cancellation
                    </button>
                    <button
                        type="button"
                        class="min-h-11 rounded-lg border px-4 font-semibold"
                        @click="showCancel = false"
                    >
                        Keep event
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
