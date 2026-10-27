<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import EmptyState from '@/components/tickora/EmptyState.vue';
import EventCard from '@/components/tickora/EventCard.vue';
import type { EventCard as EventCardData } from '@/types';

defineProps<{
    featured: EventCardData[];
    upcoming: EventCardData[];
    categories: Array<{ name: string; slug: string; events: number }>;
}>();

const search = reactive({ q: '', category: '', city: '', when: '' });

function submit(): void {
    const params = Object.fromEntries(
        Object.entries(search).filter(([, value]) => value !== ''),
    );
    router.get('/events', params);
}
</script>

<template>
    <Head title="Discover events worth remembering" />

    <section
        aria-labelledby="hero-title"
        class="bg-brand-navy px-4 pt-16 pb-32 text-white sm:px-6"
    >
        <div class="mx-auto flex max-w-7xl flex-col gap-5">
            <span
                class="inline-flex w-fit items-center gap-2 rounded-full bg-primary/20 px-3 py-1.5 text-sm font-bold text-violet-200"
            >
                <span class="size-1.5 rounded-full bg-brand-coral" />
                Every Event. One Ticket.
            </span>
            <h1
                id="hero-title"
                class="max-w-3xl text-4xl leading-[1.05] font-extrabold tracking-tight sm:text-6xl"
            >
                Discover events worth remembering.
            </h1>
            <p class="max-w-xl text-lg text-slate-300">
                Concerts, conferences, festivals and community gatherings — find
                what's on near you and book in a few clicks.
            </p>
        </div>
    </section>

    <div class="relative mx-auto -mt-20 max-w-7xl px-4 sm:px-6">
        <form
            aria-label="Search events"
            class="grid items-end gap-3 rounded-2xl border bg-card p-5 shadow-xl sm:grid-cols-2 lg:grid-cols-5"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-1.5">
                <label for="q" class="text-sm font-bold">What</label>
                <input
                    id="q"
                    v-model="search.q"
                    type="text"
                    placeholder="Event, artist or venue"
                    class="min-h-12 rounded-xl border bg-background px-3.5 text-base"
                />
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="category" class="text-sm font-bold">Category</label>
                <select
                    id="category"
                    v-model="search.category"
                    class="min-h-12 rounded-xl border bg-background px-3 text-base"
                >
                    <option value="">All categories</option>
                    <option
                        v-for="c in categories"
                        :key="c.slug"
                        :value="c.slug"
                    >
                        {{ c.name }}
                    </option>
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="city" class="text-sm font-bold">Where</label>
                <input
                    id="city"
                    v-model="search.city"
                    type="text"
                    placeholder="City"
                    class="min-h-12 rounded-xl border bg-background px-3.5 text-base"
                />
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="when" class="text-sm font-bold">When</label>
                <select
                    id="when"
                    v-model="search.when"
                    class="min-h-12 rounded-xl border bg-background px-3 text-base"
                >
                    <option value="">Any date</option>
                    <option value="today">Today</option>
                    <option value="weekend">This weekend</option>
                    <option value="week">Next 7 days</option>
                    <option value="month">Next 30 days</option>
                </select>
            </div>
            <button
                type="submit"
                class="min-h-12 rounded-xl bg-primary px-5 text-base font-bold text-primary-foreground hover:bg-primary/90"
            >
                Search events
            </button>
        </form>
    </div>

    <section
        aria-labelledby="categories-title"
        class="mx-auto max-w-7xl px-4 pt-14 sm:px-6"
    >
        <h2
            id="categories-title"
            class="mb-5 text-2xl font-extrabold tracking-tight"
        >
            Browse by category
        </h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
            <Link
                v-for="c in categories"
                :key="c.slug"
                :href="`/events?category=${c.slug}`"
                class="flex flex-col gap-1 rounded-2xl border bg-card p-4 hover:border-primary"
            >
                <span class="font-bold">{{ c.name }}</span>
                <span class="text-xs text-muted-foreground">
                    {{ c.events }} {{ c.events === 1 ? 'event' : 'events' }}
                </span>
            </Link>
        </div>
    </section>

    <section
        aria-labelledby="featured-title"
        class="mx-auto max-w-7xl px-4 pt-14 sm:px-6"
    >
        <div class="mb-5 flex flex-wrap items-baseline justify-between gap-3">
            <h2
                id="featured-title"
                class="text-2xl font-extrabold tracking-tight"
            >
                Coming up
            </h2>
            <Link href="/events" class="font-bold text-primary"
                >Explore all events →</Link
            >
        </div>
        <div
            v-if="featured.length"
            class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4"
        >
            <EventCard
                v-for="event in featured"
                :key="event.id"
                :event="event"
            />
        </div>
        <EmptyState
            v-else
            title="No events on sale yet"
            description="Check back soon — organisers are setting up their first events."
        />
    </section>

    <section
        v-if="upcoming.length"
        aria-labelledby="later-title"
        class="mx-auto max-w-7xl px-4 pt-14 sm:px-6"
    >
        <h2
            id="later-title"
            class="mb-5 text-2xl font-extrabold tracking-tight"
        >
            Later this season
        </h2>
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <EventCard
                v-for="event in upcoming"
                :key="event.id"
                :event="event"
            />
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 pt-16 sm:px-6">
        <div
            class="grid gap-6 rounded-3xl bg-brand-navy p-8 text-white sm:p-12 lg:grid-cols-2 lg:items-center"
        >
            <div class="flex flex-col gap-4">
                <span
                    class="text-sm font-extrabold tracking-wider text-red-300 uppercase"
                    >For organisers</span
                >
                <h2 class="text-3xl font-extrabold tracking-tight sm:text-4xl">
                    Sell out your next event — and run the door from your phone.
                </h2>
                <p class="text-slate-300">
                    Publish in minutes, sell Early Bird to VVIP tickets in your
                    currency, and scan tickets at the gate with no app install.
                </p>
                <Link
                    href="/organizer"
                    class="inline-flex min-h-12 w-fit items-center rounded-xl bg-primary px-6 font-bold text-primary-foreground hover:bg-primary/90"
                >
                    Start selling tickets
                </Link>
            </div>
        </div>
    </section>
</template>
