<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import EmptyState from '@/components/tickora/EmptyState.vue';
import EventCard from '@/components/tickora/EventCard.vue';
import Pagination from '@/components/tickora/Pagination.vue';
import type { Category, EventCard as EventCardData, Paginated } from '@/types';

type Filters = {
    q: string;
    category: string;
    city: string;
    when: string;
    price: string;
    sort: string;
};

const props = defineProps<{
    events: Paginated<EventCardData>;
    filters: Filters;
    categories: Category[];
}>();

const form = reactive<Filters>({ ...props.filters });

function apply(): void {
    const params = Object.fromEntries(
        Object.entries(form).filter(
            ([key, value]) =>
                value !== '' && !(key === 'sort' && value === 'date'),
        ),
    );
    router.get('/events', params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function setCategory(slug: string): void {
    form.category = form.category === slug ? '' : slug;
    apply();
}

function reset(): void {
    Object.assign(form, {
        q: '',
        category: '',
        city: '',
        when: '',
        price: '',
        sort: 'date',
    });
    apply();
}
</script>

<template>
    <Head title="Explore events" />

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6">
        <h1 class="text-3xl font-extrabold tracking-tight">Explore events</h1>

        <form
            aria-label="Filter events"
            class="mt-6 grid gap-3 rounded-2xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-6"
            @submit.prevent="apply"
        >
            <div class="flex flex-col gap-1.5 lg:col-span-2">
                <label for="f-q" class="text-sm font-bold">Search</label>
                <input
                    id="f-q"
                    v-model="form.q"
                    type="search"
                    placeholder="Event, artist or venue"
                    class="min-h-11 rounded-lg border bg-background px-3"
                />
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="f-city" class="text-sm font-bold">City</label>
                <input
                    id="f-city"
                    v-model="form.city"
                    type="text"
                    placeholder="Any city"
                    class="min-h-11 rounded-lg border bg-background px-3"
                />
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="f-when" class="text-sm font-bold">Date</label>
                <select
                    id="f-when"
                    v-model="form.when"
                    class="min-h-11 rounded-lg border bg-background px-3"
                    @change="apply"
                >
                    <option value="">Any date</option>
                    <option value="today">Today</option>
                    <option value="weekend">This weekend</option>
                    <option value="week">Next 7 days</option>
                    <option value="month">Next 30 days</option>
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="f-price" class="text-sm font-bold">Price</label>
                <select
                    id="f-price"
                    v-model="form.price"
                    class="min-h-11 rounded-lg border bg-background px-3"
                    @change="apply"
                >
                    <option value="">Free and paid</option>
                    <option value="free">Free</option>
                    <option value="paid">Paid</option>
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="f-sort" class="text-sm font-bold">Sort by</label>
                <select
                    id="f-sort"
                    v-model="form.sort"
                    class="min-h-11 rounded-lg border bg-background px-3"
                    @change="apply"
                >
                    <option value="date">Date</option>
                    <option value="price_asc">Price: low to high</option>
                    <option value="price_desc">Price: high to low</option>
                    <option value="newest">Newly added</option>
                </select>
            </div>
            <div class="flex gap-2 sm:col-span-2 lg:col-span-6">
                <button
                    type="submit"
                    class="min-h-11 rounded-lg bg-primary px-5 font-bold text-primary-foreground hover:bg-primary/90"
                >
                    Apply filters
                </button>
                <button
                    type="button"
                    class="min-h-11 rounded-lg border px-4 font-semibold hover:bg-accent"
                    @click="reset"
                >
                    Clear
                </button>
            </div>
        </form>

        <div
            role="group"
            aria-label="Categories"
            class="mt-5 flex flex-wrap gap-2"
        >
            <button
                v-for="c in categories"
                :key="c.slug"
                type="button"
                class="min-h-10 rounded-full border px-4 text-sm font-semibold"
                :class="
                    form.category === c.slug
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'bg-card hover:bg-accent'
                "
                :aria-pressed="form.category === c.slug"
                @click="setCategory(c.slug)"
            >
                {{ c.name }}
            </button>
        </div>

        <p class="mt-6 text-sm text-muted-foreground" aria-live="polite">
            {{ events.total }} {{ events.total === 1 ? 'event' : 'events' }}
        </p>

        <div
            v-if="events.data.length"
            class="mt-4 grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <EventCard
                v-for="event in events.data"
                :key="event.id"
                :event="event"
            />
        </div>
        <EmptyState
            v-else
            class="mt-4"
            title="No events match your search"
            description="Try a different date, widen the location, or clear the filters."
        >
            <button
                type="button"
                class="min-h-11 rounded-lg border px-4 font-semibold hover:bg-accent"
                @click="reset"
            >
                Clear filters
            </button>
        </EmptyState>

        <div class="mt-10">
            <Pagination :links="events.links" />
        </div>
    </div>
</template>
