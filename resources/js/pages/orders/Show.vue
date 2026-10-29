<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, CircleCheck, MapPin, Maximize2 } from '@lucide/vue';
import TicketQr from '@/components/tickora/TicketQr.vue';
import type { Money } from '@/types';

type OrderTicket = {
    id: string;
    reference: string;
    type: string;
    attendee: string | null;
    status: string;
    usable: boolean;
    qr: string | null;
};

const props = defineProps<{
    order: {
        id: string;
        reference: string;
        status: string;
        status_label: string;
        paid_label: string | null;
        customer_name: string | null;
        customer_email: string;
        items: Array<{ name: string; quantity: number; amount: Money }>;
        subtotal: Money;
        discount: Money;
        fee: Money;
        total: Money;
    };
    event: {
        title: string;
        slug: string;
        date_label: string | null;
        timezone_label: string;
        venue: string | null;
        address: string | null;
        cover_url: string | null;
    };
    tickets: OrderTicket[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My tickets', href: '/dashboard' }],
    },
});

const paid = ['paid', 'partially_refunded'].includes(props.order.status);
</script>

<template>
    <Head :title="`Order ${order.reference}`" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <header
            v-if="paid"
            class="flex items-start gap-3 rounded-2xl border border-green-300 bg-green-50 p-5 text-green-950 dark:border-green-900 dark:bg-green-950/40 dark:text-green-100"
        >
            <CircleCheck class="mt-0.5 size-6 shrink-0" aria-hidden="true" />
            <div>
                <h1 class="text-xl font-extrabold">You're going!</h1>
                <p class="text-sm">
                    Order {{ order.reference }} is confirmed. We emailed the
                    details to {{ order.customer_email }}. Show a ticket's QR
                    code at the entrance; each code scans once.
                </p>
            </div>
        </header>
        <header v-else>
            <h1 class="text-2xl font-extrabold">Order {{ order.reference }}</h1>
            <p class="text-muted-foreground">
                Status: {{ order.status_label }}
            </p>
        </header>

        <section
            class="flex flex-col overflow-hidden rounded-2xl border bg-card sm:flex-row"
        >
            <div
                class="h-40 shrink-0 bg-[#2E1F7A] sm:h-auto sm:w-64"
                aria-hidden="true"
            >
                <img
                    v-if="event.cover_url"
                    :src="event.cover_url"
                    alt=""
                    class="size-full object-cover"
                />
            </div>
            <div class="flex flex-col gap-2 p-5">
                <Link
                    :href="`/events/${event.slug}`"
                    class="text-xl font-extrabold hover:underline"
                    >{{ event.title }}</Link
                >
                <p
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <CalendarDays class="size-4" aria-hidden="true" />
                    {{ event.date_label }} ({{ event.timezone_label }})
                </p>
                <p
                    v-if="event.venue"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <MapPin class="size-4" aria-hidden="true" />
                    {{ event.venue
                    }}<template v-if="event.address">
                        · {{ event.address }}</template
                    >
                </p>
            </div>
        </section>

        <section
            v-if="tickets.length"
            aria-labelledby="tickets"
            class="flex flex-col gap-3"
        >
            <h2 id="tickets" class="text-lg font-extrabold">
                Your tickets ({{ tickets.length }})
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="(ticket, index) in tickets"
                    :key="ticket.id"
                    class="flex flex-col items-center gap-3 rounded-2xl border bg-card p-5 text-center"
                >
                    <div
                        class="flex w-full items-center justify-between text-sm"
                    >
                        <span class="font-extrabold">{{ ticket.type }}</span>
                        <span class="text-muted-foreground"
                            >{{ index + 1 }} of {{ tickets.length }}</span
                        >
                    </div>
                    <TicketQr
                        v-if="ticket.qr && ticket.usable"
                        :payload="ticket.qr"
                        :size="200"
                        :label="`QR code for ticket ${ticket.reference}`"
                    />
                    <p
                        v-else
                        class="flex h-[200px] w-[200px] items-center justify-center rounded-xl bg-muted p-4 text-sm text-muted-foreground"
                    >
                        {{
                            ticket.usable
                                ? 'QR code available to the ticket holder.'
                                : `This ticket is ${ticket.status.replace('_', ' ')}.`
                        }}
                    </p>
                    <p class="font-mono text-xs">{{ ticket.reference }}</p>
                    <Link
                        v-if="ticket.qr"
                        :href="`/tickets/${ticket.id}`"
                        class="inline-flex min-h-11 items-center gap-2 rounded-xl border px-4 text-sm font-bold hover:bg-accent"
                    >
                        <Maximize2 class="size-4" aria-hidden="true" />
                        Open for scanning
                    </Link>
                </article>
            </div>
        </section>

        <section
            aria-labelledby="summary"
            class="rounded-2xl border bg-card p-5"
        >
            <h2 id="summary" class="mb-3 text-lg font-extrabold">
                Order summary
            </h2>
            <dl class="flex flex-col gap-2 text-sm">
                <div
                    v-for="item in order.items"
                    :key="item.name"
                    class="flex justify-between"
                >
                    <dt>{{ item.quantity }} × {{ item.name }}</dt>
                    <dd class="tabular-nums">{{ item.amount.formatted }}</dd>
                </div>
                <div
                    v-if="order.discount.amount_minor > 0"
                    class="flex justify-between text-green-700"
                >
                    <dt>Discount</dt>
                    <dd class="tabular-nums">
                        −{{ order.discount.formatted }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">Booking fee</dt>
                    <dd class="tabular-nums">{{ order.fee.formatted }}</dd>
                </div>
                <div
                    class="mt-1 flex justify-between border-t pt-3 text-base font-extrabold"
                >
                    <dt>Total</dt>
                    <dd class="tabular-nums">{{ order.total.formatted }}</dd>
                </div>
                <div
                    v-if="order.paid_label"
                    class="text-xs text-muted-foreground"
                >
                    Paid {{ order.paid_label }}
                </div>
            </dl>
        </section>
    </div>
</template>
