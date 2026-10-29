<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Banknote,
    CalendarDays,
    CircleCheck,
    Info,
    ReceiptText,
    Ticket,
} from '@lucide/vue';
import { computed } from 'vue';
import DailyTicketsChart from '@/components/dashboard/DailyTicketsChart.vue';
import StatTile from '@/components/dashboard/StatTile.vue';
import EmptyState from '@/components/tickora/EmptyState.vue';
import StatusBadge from '@/components/tickora/StatusBadge.vue';
import type { CurrencyTotals, DailyTickets, EventStatus, Money } from '@/types';

const props = defineProps<{
    organizer: {
        name: string;
        status: string;
        can_sell: boolean;
        default_currency: string;
    };
    timezone: string;
    totals: { all_time: CurrencyTotals[]; last_30_days: CurrencyTotals[] };
    tickets: { all_time: number; last_30_days: number };
    orders_last_30_days: number;
    daily: DailyTickets[];
    events: Array<{
        id: string;
        title: string;
        status: EventStatus;
        date_label: string | null;
        venue: string | null;
        sold: number;
        capacity: number;
        ticket_types: number;
        sales: Money;
    }>;
    attention: Array<{
        tone: 'info' | 'warning' | 'good';
        message: string;
        href: string | null;
    }>;
    recent_orders: Array<{
        reference: string;
        customer: string;
        event: string;
        tickets: number;
        amount: Money;
        status: string;
        paid_label: string | null;
    }> | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Overview', href: '/organizer/dashboard' }],
    },
});

function money(
    list: CurrencyTotals[],
    field: 'ticket_sales',
): {
    value: string;
    extra: string[];
} {
    if (!list.length) {
        return { value: `${props.organizer.default_currency} 0`, extra: [] };
    }

    return {
        value: list[0][field].formatted,
        extra: list.slice(1).map((t) => t[field].formatted),
    };
}

const salesAll = computed(() => money(props.totals.all_time, 'ticket_sales'));
const sales30 = computed(() =>
    money(props.totals.last_30_days, 'ticket_sales'),
);

function percent(sold: number, capacity: number): number {
    return capacity > 0 ? Math.round((sold / capacity) * 100) : 0;
}

const toneClass = {
    info: 'border-violet-200 bg-violet-50 text-violet-950 dark:border-violet-900 dark:bg-violet-950/40 dark:text-violet-100',
    warning:
        'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100',
    good: 'border-green-300 bg-green-50 text-green-950 dark:border-green-900 dark:bg-green-950/40 dark:text-green-100',
};

const toneIcon = { info: Info, warning: AlertTriangle, good: CircleCheck };
</script>

<template>
    <Head title="Organiser overview" />

    <div class="flex flex-col gap-6 p-4 sm:p-6">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight">
                    {{ organizer.name }}
                </h1>
                <p class="text-muted-foreground">
                    Sales and events at a glance. Days are counted in
                    {{ timezone }}.
                </p>
            </div>
            <Link
                href="/organizer/events/create"
                class="inline-flex min-h-11 items-center rounded-xl bg-primary px-4 text-sm font-extrabold text-primary-foreground"
                >Create event</Link
            >
        </header>

        <ul v-if="attention.length" class="flex flex-col gap-2">
            <li
                v-for="item in attention"
                :key="item.message"
                class="flex items-start gap-3 rounded-xl border p-3 text-sm"
                :class="toneClass[item.tone]"
            >
                <component
                    :is="toneIcon[item.tone]"
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span class="flex-1">{{ item.message }}</span>
                <Link
                    v-if="item.href"
                    :href="item.href"
                    class="font-bold underline-offset-4 hover:underline"
                    >View</Link
                >
            </li>
        </ul>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile
                label="Ticket sales, last 30 days"
                :value="sales30.value"
                :extra="sales30.extra"
                :icon="Banknote"
                hint="After discounts, before refunds. Excludes booking fees."
            />
            <StatTile
                label="Ticket sales, all time"
                :value="salesAll.value"
                :extra="salesAll.extra"
                :icon="Banknote"
            />
            <StatTile
                label="Tickets sold, last 30 days"
                :value="tickets.last_30_days.toLocaleString()"
                :hint="`${tickets.all_time.toLocaleString()} all time`"
                :icon="Ticket"
            />
            <StatTile
                label="Orders, last 30 days"
                :value="orders_last_30_days.toLocaleString()"
                :icon="ReceiptText"
            />
        </div>

        <DailyTicketsChart
            title="Tickets sold per day"
            subtitle="Last 30 days, all your events"
            :points="daily"
        />

        <section aria-labelledby="upcoming" class="flex flex-col gap-3">
            <div class="flex items-baseline justify-between gap-2">
                <h2 id="upcoming" class="text-lg font-extrabold">
                    Upcoming events
                </h2>
                <Link
                    href="/organizer/events"
                    class="text-sm font-bold text-primary hover:underline"
                    >All events</Link
                >
            </div>

            <EmptyState
                v-if="!events.length"
                title="No upcoming events"
                description="Create an event to start selling tickets."
            />

            <div v-else class="overflow-x-auto rounded-2xl border bg-card">
                <table class="w-full min-w-[680px] text-left text-sm">
                    <thead class="border-b bg-muted/50 text-xs uppercase">
                        <tr>
                            <th scope="col" class="p-3">Event</th>
                            <th scope="col" class="p-3">Status</th>
                            <th scope="col" class="w-56 p-3">Sold</th>
                            <th scope="col" class="p-3 text-right">
                                Ticket sales
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="event in events"
                            :key="event.id"
                            class="border-b last:border-0"
                        >
                            <td class="p-3">
                                <Link
                                    :href="`/organizer/events/${event.id}`"
                                    class="font-bold hover:underline"
                                    >{{ event.title }}</Link
                                >
                                <div
                                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                                >
                                    <CalendarDays
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{ event.date_label }}
                                    <template v-if="event.venue">
                                        · {{ event.venue }}</template
                                    >
                                </div>
                            </td>
                            <td class="p-3">
                                <StatusBadge :status="event.status" />
                            </td>
                            <td class="p-3">
                                <template v-if="event.capacity > 0">
                                    <div
                                        class="h-2 overflow-hidden rounded-full bg-muted"
                                        role="progressbar"
                                        :aria-valuenow="
                                            percent(event.sold, event.capacity)
                                        "
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        :aria-label="`${event.title} sold`"
                                    >
                                        <div
                                            class="h-full rounded-full bg-[#6D4AFF] dark:bg-[#8466FF]"
                                            :style="{
                                                width: `${percent(event.sold, event.capacity)}%`,
                                            }"
                                        />
                                    </div>
                                    <div
                                        class="mt-1 text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{ event.sold.toLocaleString() }} /
                                        {{ event.capacity.toLocaleString() }}
                                        ({{
                                            percent(event.sold, event.capacity)
                                        }}%)
                                    </div>
                                </template>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >No ticket types yet</span
                                >
                            </td>
                            <td
                                class="p-3 text-right font-semibold tabular-nums"
                            >
                                {{ event.sales.formatted }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-if="recent_orders"
            aria-labelledby="recent"
            class="flex flex-col gap-3"
        >
            <h2 id="recent" class="text-lg font-extrabold">Recent orders</h2>
            <p
                v-if="!recent_orders.length"
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                No paid orders yet.
            </p>
            <div v-else class="overflow-x-auto rounded-2xl border bg-card">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead class="border-b bg-muted/50 text-xs uppercase">
                        <tr>
                            <th scope="col" class="p-3">Order</th>
                            <th scope="col" class="p-3">Customer</th>
                            <th scope="col" class="p-3">Event</th>
                            <th scope="col" class="p-3 text-right">Tickets</th>
                            <th scope="col" class="p-3 text-right">
                                Ticket sales
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="order in recent_orders"
                            :key="order.reference"
                            class="border-b last:border-0"
                        >
                            <td class="p-3">
                                <div class="font-mono text-xs font-bold">
                                    {{ order.reference }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ order.paid_label }}
                                </div>
                            </td>
                            <td class="p-3">{{ order.customer }}</td>
                            <td class="p-3">{{ order.event }}</td>
                            <td class="p-3 text-right tabular-nums">
                                {{ order.tickets }}
                            </td>
                            <td class="p-3 text-right tabular-nums">
                                {{ order.amount.formatted }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
