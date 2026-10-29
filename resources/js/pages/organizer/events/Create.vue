<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EventForm from '@/components/organizer/EventForm.vue';
import type { EventPayload } from '@/components/organizer/EventForm.vue';
import type { Category, ImageLimits } from '@/types';

const props = defineProps<{
    categories: Category[];
    currencies: string[];
    visibilities: Array<'public' | 'unlisted'>;
    timezones: string[];
    imageLimits: ImageLimits;
    defaults: { currency: string; country_code: string; timezone: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Events', href: '/organizer/events' },
            { title: 'Create event', href: '/organizer/events/create' },
        ],
    },
});

const initial: EventPayload = {
    title: '',
    category: '',
    summary: '',
    description: '',
    timezone: props.defaults.timezone,
    currency: props.defaults.currency,
    starts_at: '',
    ends_at: '',
    sales_starts_at: '',
    sales_ends_at: '',
    visibility: 'public',
    refund_policy: '',
    min_age: '',
    venue: {
        name: '',
        address_line: '',
        city: '',
        region: null,
        postal_code: null,
        country_code: props.defaults.country_code,
    },
    images: [],
};
</script>

<template>
    <Head title="Create event" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-extrabold tracking-tight">Create event</h1>
            <p class="text-muted-foreground">
                Save a draft first; you'll add ticket types on the next screen.
                Times are in the event's own timezone.
            </p>
        </header>

        <EventForm
            :initial="initial"
            :options="{
                categories,
                currencies,
                visibilities,
                timezones,
                imageLimits,
            }"
            method="post"
            url="/organizer/events"
            submit-label="Save draft"
        />
    </div>
</template>
