<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import EmptyState from '@/components/tickora/EmptyState.vue';
import EventCard from '@/components/tickora/EventCard.vue';
import type { EventCard as EventCardData } from '@/types';

defineProps<{
    organizer: {
        name: string;
        slug: string;
        description: string | null;
        country_code: string;
        support_email: string | null;
        verified: boolean;
    };
    events: EventCardData[];
}>();
</script>

<template>
    <Head :title="organizer.name" />

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6">
        <header
            class="flex flex-wrap items-center gap-5 rounded-3xl border bg-card p-6"
        >
            <span
                class="flex size-16 items-center justify-center rounded-2xl bg-brand-navy text-xl font-extrabold text-white"
                aria-hidden="true"
                >{{ organizer.name.slice(0, 2).toUpperCase() }}</span
            >
            <div class="flex flex-1 flex-col gap-1">
                <h1
                    class="flex items-center gap-2 text-3xl font-extrabold tracking-tight"
                >
                    {{ organizer.name }}
                    <span
                        v-if="organizer.verified"
                        class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-bold text-green-800"
                    >
                        <ShieldCheck class="size-3.5" aria-hidden="true" />
                        Verified
                    </span>
                </h1>
                <p v-if="organizer.description" class="text-muted-foreground">
                    {{ organizer.description }}
                </p>
                <a
                    v-if="organizer.support_email"
                    :href="`mailto:${organizer.support_email}`"
                    class="text-sm font-semibold text-primary"
                    >{{ organizer.support_email }}</a
                >
            </div>
        </header>

        <h2 class="mt-10 mb-5 text-2xl font-extrabold">Upcoming events</h2>
        <div
            v-if="events.length"
            class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <EventCard v-for="event in events" :key="event.id" :event="event" />
        </div>
        <EmptyState
            v-else
            title="No upcoming events"
            description="This organiser has nothing on sale right now."
        />
    </div>
</template>
