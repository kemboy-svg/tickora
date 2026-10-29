<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, MapPin, SunMedium } from '@lucide/vue';
import TicketQr from '@/components/tickora/TicketQr.vue';

defineProps<{
    ticket: {
        id: string;
        reference: string;
        type: string;
        attendee: string | null;
        status: string;
        usable: boolean;
        qr: string | null;
        order_id: string;
    };
    event: {
        title: string;
        slug: string;
        date_label: string | null;
        venue: string | null;
        city: string | null;
        cover_url: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My tickets', href: '/dashboard' }],
    },
});
</script>

<template>
    <Head :title="`Ticket ${ticket.reference}`" />

    <div class="flex justify-center p-4 sm:p-8">
        <article
            class="w-full max-w-sm overflow-hidden rounded-3xl border bg-card shadow-lg"
        >
            <div class="relative h-28 bg-[#2E1F7A]" aria-hidden="true">
                <img
                    v-if="event.cover_url"
                    :src="event.cover_url"
                    alt=""
                    class="size-full object-cover opacity-80"
                />
            </div>
            <div class="flex flex-col gap-1 p-5">
                <h1 class="text-xl leading-tight font-extrabold">
                    {{ event.title }}
                </h1>
                <p
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <CalendarDays class="size-4" aria-hidden="true" />
                    {{ event.date_label }}
                </p>
                <p
                    v-if="event.venue"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <MapPin class="size-4" aria-hidden="true" />
                    {{ event.venue
                    }}<template v-if="event.city">, {{ event.city }}</template>
                </p>
            </div>

            <div
                class="flex flex-col items-center gap-3 border-t border-dashed px-5 py-6"
            >
                <TicketQr
                    v-if="ticket.qr && ticket.usable"
                    :payload="ticket.qr"
                    :size="260"
                    :label="`QR code for ticket ${ticket.reference}`"
                />
                <p
                    v-else
                    class="rounded-xl bg-muted p-6 text-center text-sm text-muted-foreground"
                >
                    {{
                        ticket.usable
                            ? 'This QR code cannot be shown. Contact support with your ticket reference.'
                            : `This ticket is ${ticket.status.replace('_', ' ')} and can no longer be used.`
                    }}
                </p>
                <p
                    v-if="ticket.usable"
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <SunMedium class="size-4" aria-hidden="true" />
                    Turn your screen brightness up at the entrance.
                </p>
            </div>

            <dl class="grid grid-cols-2 gap-3 border-t p-5 text-sm">
                <div>
                    <dt class="text-xs text-muted-foreground">Ticket</dt>
                    <dd class="font-bold">{{ ticket.type }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Name</dt>
                    <dd class="font-bold">{{ ticket.attendee ?? '—' }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-xs text-muted-foreground">Reference</dt>
                    <dd class="font-mono font-bold">{{ ticket.reference }}</dd>
                </div>
            </dl>

            <div class="border-t p-4 text-center">
                <Link
                    :href="`/orders/${ticket.order_id}`"
                    class="text-sm font-bold text-primary hover:underline"
                    >All tickets in this order</Link
                >
            </div>
        </article>
    </div>
</template>
