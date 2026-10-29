<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CalendarDays, Clock, MapPin, ReceiptText, Ticket } from '@lucide/vue';
import { computed } from 'vue';
import StatTile from '@/components/dashboard/StatTile.vue';
import EmptyState from '@/components/tickora/EmptyState.vue';
import type { CustomerOrder, Money } from '@/types';

defineProps<{
    stats: {
        upcoming_events: number;
        upcoming_tickets: number;
        orders: number;
    };
    upcoming: CustomerOrder[];
    past: CustomerOrder[];
    holds: Array<{
        id: string;
        event: string;
        tickets: number;
        total: Money;
        expires_at: string;
        minutes_left: number;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My tickets', href: '/dashboard' }],
    },
});

const page = usePage();
const firstName = computed(
    () => page.props.auth?.user?.name?.split(' ')[0] ?? 'there',
);
const nav = computed(() => page.props.nav);

const statusClass: Record<string, string> = {
    paid: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    partially_refunded:
        'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    pending_payment:
        'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    payment_failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
};
</script>

<template>
    <Head title="My tickets" />

    <div class="flex flex-col gap-6 p-4 sm:p-6">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight">
                    Hi {{ firstName }}
                </h1>
                <p class="text-muted-foreground">
                    Your tickets and orders in one place.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    v-if="nav?.organizer"
                    href="/organizer/dashboard"
                    class="inline-flex min-h-11 items-center rounded-xl border px-4 text-sm font-bold hover:bg-accent"
                    >Organiser overview</Link
                >
                <Link
                    v-if="nav?.platform"
                    href="/admin"
                    class="inline-flex min-h-11 items-center rounded-xl border px-4 text-sm font-bold hover:bg-accent"
                    >Platform overview</Link
                >
                <Link
                    href="/events"
                    class="inline-flex min-h-11 items-center rounded-xl bg-primary px-4 text-sm font-extrabold text-primary-foreground"
                    >Find events</Link
                >
            </div>
        </header>

        <div class="grid gap-4 sm:grid-cols-3">
            <StatTile
                label="Upcoming events"
                :value="stats.upcoming_events"
                :icon="CalendarDays"
            />
            <StatTile
                label="Tickets for upcoming events"
                :value="stats.upcoming_tickets"
                :icon="Ticket"
            />
            <StatTile
                label="Orders placed"
                :value="stats.orders"
                :icon="ReceiptText"
            />
        </div>

        <section
            v-if="holds.length"
            aria-labelledby="holds"
            class="flex flex-col gap-3"
        >
            <h2 id="holds" class="text-lg font-extrabold">Tickets on hold</h2>
            <Link
                v-for="hold in holds"
                :key="hold.id"
                :href="`/checkout/${hold.id}`"
                class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-amber-950 hover:shadow dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
            >
                <span class="flex items-center gap-3">
                    <Clock class="size-5 shrink-0" aria-hidden="true" />
                    <span>
                        <strong>{{ hold.event }}</strong> · {{ hold.tickets }}
                        {{ hold.tickets === 1 ? 'ticket' : 'tickets' }} ·
                        {{ hold.total.formatted }}
                    </span>
                </span>
                <span class="text-sm font-bold"
                    >About {{ hold.minutes_left }} min left · Finish checkout
                    →</span
                >
            </Link>
        </section>

        <section aria-labelledby="upcoming" class="flex flex-col gap-3">
            <h2 id="upcoming" class="text-lg font-extrabold">Coming up</h2>

            <EmptyState
                v-if="!upcoming.length"
                title="No upcoming events yet"
                description="Tickets you buy will show up here."
            />

            <div v-else class="grid gap-4 lg:grid-cols-2">
                <article
                    v-for="order in upcoming"
                    :key="order.reference"
                    class="flex overflow-hidden rounded-2xl border bg-card"
                >
                    <div
                        class="w-28 shrink-0 bg-[#2E1F7A] sm:w-36"
                        aria-hidden="true"
                    >
                        <img
                            v-if="order.event.cover_url"
                            :src="order.event.cover_url"
                            alt=""
                            loading="lazy"
                            class="size-full object-cover"
                        />
                    </div>
                    <div class="flex min-w-0 flex-1 flex-col gap-1.5 p-4">
                        <span class="flex flex-wrap items-center gap-2">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-bold"
                                :class="statusClass[order.status] ?? 'bg-muted'"
                                >{{ order.status_label }}</span
                            >
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >{{ order.reference }}</span
                            >
                        </span>
                        <Link
                            :href="`/events/${order.event.slug}`"
                            class="leading-snug font-extrabold hover:underline"
                            >{{ order.event.title }}</Link
                        >
                        <span
                            class="flex items-center gap-1.5 text-sm text-muted-foreground"
                        >
                            <CalendarDays class="size-4" aria-hidden="true" />
                            {{ order.event.date_label }}
                        </span>
                        <span
                            v-if="order.event.venue"
                            class="flex items-center gap-1.5 text-sm text-muted-foreground"
                        >
                            <MapPin class="size-4" aria-hidden="true" />
                            {{ order.event.venue
                            }}<template v-if="order.event.city"
                                >, {{ order.event.city }}</template
                            >
                        </span>
                        <span
                            class="mt-auto flex flex-wrap items-center justify-between gap-2 pt-2 text-sm"
                        >
                            <span
                                >{{ order.tickets }}
                                {{ order.tickets === 1 ? 'ticket' : 'tickets' }}
                                · {{ order.total.formatted }}</span
                            >
                            <Link
                                v-if="order.checkout_id"
                                :href="`/checkout/${order.checkout_id}`"
                                class="font-bold text-primary hover:underline"
                                >Continue to payment →</Link
                            >
                            <Link
                                v-else-if="
                                    ['paid', 'partially_refunded'].includes(
                                        order.status,
                                    )
                                "
                                :href="`/orders/${order.id}`"
                                class="font-bold text-primary hover:underline"
                                >View tickets →</Link
                            >
                        </span>
                    </div>
                </article>
            </div>
        </section>

        <section
            v-if="past.length"
            aria-labelledby="past"
            class="flex flex-col gap-3"
        >
            <h2 id="past" class="text-lg font-extrabold">Past events</h2>
            <div class="overflow-x-auto rounded-2xl border bg-card">
                <table class="w-full min-w-[520px] text-left text-sm">
                    <thead class="border-b bg-muted/50 text-xs uppercase">
                        <tr>
                            <th scope="col" class="p-3">Event</th>
                            <th scope="col" class="p-3">Date</th>
                            <th scope="col" class="p-3">Reference</th>
                            <th scope="col" class="p-3 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="order in past"
                            :key="order.reference"
                            class="border-b last:border-0"
                        >
                            <td class="p-3 font-semibold">
                                <Link
                                    :href="`/orders/${order.id}`"
                                    class="hover:underline"
                                    >{{ order.event.title }}</Link
                                >
                            </td>
                            <td class="p-3">{{ order.event.date_label }}</td>
                            <td class="p-3 font-mono text-xs">
                                {{ order.reference }}
                            </td>
                            <td class="p-3 text-right tabular-nums">
                                {{ order.total.formatted }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
