<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Banknote,
    ClipboardCheck,
    Percent,
    ReceiptText,
    Store,
    Ticket,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import DailyTicketsChart from '@/components/dashboard/DailyTicketsChart.vue';
import StatTile from '@/components/dashboard/StatTile.vue';
import type { CurrencyTotals, DailyTickets, Money } from '@/types';

const props = defineProps<{
    timezone: string;
    can: { money: boolean; moderate: boolean; verify: boolean };
    totals: {
        all_time: CurrencyTotals[];
        last_30_days: CurrencyTotals[];
    } | null;
    tickets: { all_time: number; last_30_days: number };
    orders: { all_time: number; last_30_days: number };
    users: { total: number; last_30_days: number };
    queues: { events_in_review: number; organizers_pending: number };
    organizers_by_status: Record<string, number>;
    events_by_status: Record<string, number>;
    daily: DailyTickets[];
    top_organizers: Array<{
        name: string;
        slug: string;
        tickets: number;
        orders: number;
    }>;
    recent_orders: Array<{
        reference: string;
        organizer: string;
        event: string;
        tickets: number;
        total: Money;
        fee: Money;
        paid_label: string | null;
    }> | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Platform overview', href: '/admin' }],
    },
});

function lines(
    list: CurrencyTotals[],
    field: 'gross' | 'fees',
): {
    value: string;
    extra: string[];
} {
    if (!list.length) {
        return { value: '—', extra: [] };
    }

    return {
        value: list[0][field].formatted,
        extra: list.slice(1).map((t) => t[field].formatted),
    };
}

const gross30 = computed(() =>
    props.totals ? lines(props.totals.last_30_days, 'gross') : null,
);
const fees30 = computed(() =>
    props.totals ? lines(props.totals.last_30_days, 'fees') : null,
);

const statusLabel: Record<string, string> = {
    pending: 'Pending',
    verified: 'Verified',
    suspended: 'Suspended',
    rejected: 'Rejected',
    draft: 'Draft',
    pending_review: 'In review',
    published: 'Published',
    cancelled: 'Cancelled',
    completed: 'Completed',
};
</script>

<template>
    <Head title="Platform overview" />

    <div class="flex flex-col gap-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-extrabold tracking-tight">
                Platform overview
            </h1>
            <p class="text-muted-foreground">
                All organisers, last 30 days unless noted. Days are counted in
                {{ timezone }}.
            </p>
        </header>

        <div
            v-if="can.moderate || can.verify"
            class="grid gap-3 sm:grid-cols-2"
        >
            <Link
                v-if="can.moderate"
                href="/admin/events"
                class="flex items-center justify-between gap-3 rounded-2xl border bg-card p-4 hover:shadow"
            >
                <span class="flex items-center gap-3">
                    <ClipboardCheck
                        class="size-5 text-primary"
                        aria-hidden="true"
                    />
                    <span class="font-bold">Events waiting for review</span>
                </span>
                <span
                    class="rounded-full px-3 py-1 text-sm font-extrabold tabular-nums"
                    :class="
                        queues.events_in_review
                            ? 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200'
                            : 'bg-muted'
                    "
                    >{{ queues.events_in_review }}</span
                >
            </Link>
            <Link
                v-if="can.verify"
                href="/admin/organizers?status=pending"
                class="flex items-center justify-between gap-3 rounded-2xl border bg-card p-4 hover:shadow"
            >
                <span class="flex items-center gap-3">
                    <Store class="size-5 text-primary" aria-hidden="true" />
                    <span class="font-bold">Organisers to verify</span>
                </span>
                <span
                    class="rounded-full px-3 py-1 text-sm font-extrabold tabular-nums"
                    :class="
                        queues.organizers_pending
                            ? 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200'
                            : 'bg-muted'
                    "
                    >{{ queues.organizers_pending }}</span
                >
            </Link>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile
                v-if="gross30"
                label="Gross sales"
                :value="gross30.value"
                :extra="gross30.extra"
                :icon="Banknote"
                hint="What customers paid, including booking fees."
            />
            <StatTile
                v-if="fees30"
                label="Platform fees"
                :value="fees30.value"
                :extra="fees30.extra"
                :icon="Percent"
                hint="Booking fees plus tax on them."
            />
            <StatTile
                label="Tickets sold"
                :value="tickets.last_30_days.toLocaleString()"
                :hint="`${tickets.all_time.toLocaleString()} all time`"
                :icon="Ticket"
            />
            <StatTile
                label="Paid orders"
                :value="orders.last_30_days.toLocaleString()"
                :hint="`${orders.all_time.toLocaleString()} all time`"
                :icon="ReceiptText"
            />
            <StatTile
                v-if="!can.money"
                label="Users"
                :value="users.total.toLocaleString()"
                :hint="`${users.last_30_days.toLocaleString()} joined in the last 30 days`"
                :icon="Users"
            />
        </div>

        <DailyTicketsChart
            title="Tickets sold per day"
            subtitle="Last 30 days, all organisers"
            :points="daily"
        />

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="flex flex-col gap-3 rounded-2xl border bg-card p-5">
                <h2 class="text-lg font-extrabold">Top organisers</h2>
                <p
                    v-if="!top_organizers.length"
                    class="text-sm text-muted-foreground"
                >
                    No sales in the last 30 days.
                </p>
                <ol v-else class="flex flex-col divide-y">
                    <li
                        v-for="(org, index) in top_organizers"
                        :key="org.slug"
                        class="flex items-center justify-between gap-3 py-2.5"
                    >
                        <span class="flex items-center gap-3">
                            <span
                                class="w-5 text-right text-sm text-muted-foreground tabular-nums"
                                >{{ index + 1 }}</span
                            >
                            <Link
                                :href="`/organizers/${org.slug}`"
                                class="font-semibold hover:underline"
                                >{{ org.name }}</Link
                            >
                        </span>
                        <span class="text-sm tabular-nums"
                            ><strong>{{ org.tickets.toLocaleString() }}</strong>
                            tickets ·
                            {{ org.orders.toLocaleString() }} orders</span
                        >
                    </li>
                </ol>
            </section>

            <section class="flex flex-col gap-4 rounded-2xl border bg-card p-5">
                <h2 class="text-lg font-extrabold">Accounts and events</h2>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <dt class="text-muted-foreground">Users</dt>
                    <dd class="text-right tabular-nums">
                        {{ users.total.toLocaleString() }}
                        <span class="text-muted-foreground"
                            >(+{{ users.last_30_days }})</span
                        >
                    </dd>
                    <template
                        v-for="(count, status) in organizers_by_status"
                        :key="`o-${status}`"
                    >
                        <dt class="text-muted-foreground">
                            Organisers · {{ statusLabel[status] ?? status }}
                        </dt>
                        <dd class="text-right tabular-nums">{{ count }}</dd>
                    </template>
                    <template
                        v-for="(count, status) in events_by_status"
                        :key="`e-${status}`"
                    >
                        <dt class="text-muted-foreground">
                            Events · {{ statusLabel[status] ?? status }}
                        </dt>
                        <dd class="text-right tabular-nums">{{ count }}</dd>
                    </template>
                </dl>
            </section>
        </div>

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
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="border-b bg-muted/50 text-xs uppercase">
                        <tr>
                            <th scope="col" class="p-3">Order</th>
                            <th scope="col" class="p-3">Organiser</th>
                            <th scope="col" class="p-3">Event</th>
                            <th scope="col" class="p-3 text-right">Tickets</th>
                            <th scope="col" class="p-3 text-right">Total</th>
                            <th scope="col" class="p-3 text-right">Fee</th>
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
                            <td class="p-3">{{ order.organizer }}</td>
                            <td class="p-3">{{ order.event }}</td>
                            <td class="p-3 text-right tabular-nums">
                                {{ order.tickets }}
                            </td>
                            <td class="p-3 text-right tabular-nums">
                                {{ order.total.formatted }}
                            </td>
                            <td class="p-3 text-right tabular-nums">
                                {{ order.fee.formatted }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
