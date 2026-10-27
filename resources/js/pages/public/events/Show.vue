<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { CalendarDays, MapPin, ShieldCheck } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import EventCard from '@/components/tickora/EventCard.vue';
import StatusBadge from '@/components/tickora/StatusBadge.vue';
import type { EventCard as EventCardData, EventDetail } from '@/types';

const props = defineProps<{
    event: EventDetail;
    related: EventCardData[];
    unlisted: boolean;
}>();

const quantities = reactive<Record<string, number>>(
    Object.fromEntries(props.event.ticket_types.map((t) => [t.id, 0])),
);

const count = computed(() =>
    Object.values(quantities).reduce((sum, n) => sum + n, 0),
);

const subtotalMinor = computed(() =>
    props.event.ticket_types.reduce(
        (sum, t) => sum + t.price.amount_minor * (quantities[t.id] ?? 0),
        0,
    ),
);

const currency = computed(
    () => props.event.ticket_types[0]?.price.currency ?? '',
);

// Decimal places of the currency's minor unit, read from the server's
// formatted price so zero-decimal currencies (e.g. UGX) display correctly.
const exponent = computed(
    () => props.event.ticket_types[0]?.price.decimal.split('.')[1]?.length ?? 2,
);

const subtotalLabel = computed(() => {
    const amount = subtotalMinor.value / 10 ** exponent.value;
    const formatted = amount.toLocaleString('en-US', {
        minimumFractionDigits: exponent.value,
        maximumFractionDigits: exponent.value,
    });
    return `${currency.value} ${formatted}`;
});

function canBuy(id: string): boolean {
    const type = props.event.ticket_types.find((t) => t.id === id);
    return (
        props.event.sales_open &&
        type !== undefined &&
        type.on_sale &&
        !type.sold_out
    );
}

function max(id: string): number {
    const type = props.event.ticket_types.find((t) => t.id === id);
    return type ? Math.min(type.max_per_order, type.remaining) : 0;
}

function min(id: string): number {
    return (
        props.event.ticket_types.find((t) => t.id === id)?.min_per_order ?? 1
    );
}

// Steps straight between 0 and a ticket type's minimum group size, so the
// selector never shows a quantity the server would reject.
function change(id: string, delta: number): void {
    const current = quantities[id] ?? 0;
    let next = current + delta;

    if (delta > 0 && current === 0) {
        next = min(id);
    } else if (delta < 0 && current <= min(id)) {
        next = 0;
    }

    quantities[id] = Math.max(0, Math.min(max(id), next));
}

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));

const form = useForm({
    event: props.event.id,
    items: {} as Record<string, number>,
    promo_code: '',
});

const showPromo = computed(
    () => form.promo_code !== '' || Boolean(form.errors.promo_code),
);
const promoOpen = ref(false);

function checkout(): void {
    form.transform((data) => ({
        ...data,
        items: Object.fromEntries(
            Object.entries(quantities).filter(([, n]) => n > 0),
        ),
    })).post('/checkout', { preserveScroll: true });
}
</script>

<template>
    <Head :title="event.title">
        <meta
            v-if="unlisted"
            head-key="robots"
            name="robots"
            content="noindex"
        />
        <meta
            head-key="description"
            name="description"
            :content="event.summary ?? event.title"
        />
    </Head>

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
        <nav
            aria-label="Breadcrumb"
            class="mb-4 flex flex-wrap gap-2 text-sm text-muted-foreground"
        >
            <Link href="/" class="hover:text-foreground">Home</Link>
            <span aria-hidden="true">/</span>
            <Link
                :href="`/events?category=${event.category_slug}`"
                class="hover:text-foreground"
                >{{ event.category }}</Link
            >
            <span aria-hidden="true">/</span>
            <span aria-current="page" class="font-semibold text-foreground">{{
                event.title
            }}</span>
        </nav>

        <div
            class="flex h-56 items-end rounded-3xl bg-[#2E1F7A] p-5 sm:h-80"
            role="img"
            :aria-label="`${event.title} cover`"
        />

        <div class="mt-8 flex flex-wrap items-start gap-10">
            <article class="flex min-w-0 flex-[999_1_560px] flex-col gap-8">
                <div class="flex flex-col gap-3">
                    <div class="flex flex-wrap gap-2">
                        <span
                            class="rounded-full bg-violet-100 px-3 py-1 text-sm font-bold text-violet-800"
                            >{{ event.category }}</span
                        >
                        <StatusBadge :status="event.availability" />
                        <span
                            v-if="event.min_age"
                            class="rounded-full bg-slate-100 px-3 py-1 text-sm font-bold text-slate-700"
                            >Ages {{ event.min_age }}+</span
                        >
                    </div>
                    <h1
                        class="text-3xl leading-tight font-extrabold tracking-tight sm:text-5xl"
                    >
                        {{ event.title }}
                    </h1>
                    <p
                        v-if="event.summary"
                        class="text-lg text-muted-foreground"
                    >
                        {{ event.summary }}
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="flex gap-3 rounded-2xl border bg-card p-4">
                        <CalendarDays
                            class="size-5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <div class="flex flex-col text-sm">
                            <strong class="text-base">{{
                                event.date_label
                            }}</strong>
                            <span class="text-muted-foreground"
                                >{{ event.time_label }} ·
                                {{ event.timezone_label }}</span
                            >
                        </div>
                    </div>
                    <div
                        v-if="event.venue_detail"
                        class="flex gap-3 rounded-2xl border bg-card p-4"
                    >
                        <MapPin
                            class="size-5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <div class="flex flex-col text-sm">
                            <strong class="text-base">{{
                                event.venue_detail.name
                            }}</strong>
                            <span class="text-muted-foreground"
                                >{{ event.venue_detail.address_line }},
                                {{ event.venue_detail.city }}</span
                            >
                        </div>
                    </div>
                </div>

                <div
                    class="flex flex-wrap items-center gap-4 rounded-2xl border bg-card p-4"
                >
                    <span
                        class="flex size-12 items-center justify-center rounded-xl bg-brand-navy font-extrabold text-white"
                        aria-hidden="true"
                        >{{
                            event.organizer.name.slice(0, 2).toUpperCase()
                        }}</span
                    >
                    <div class="flex flex-1 flex-col">
                        <span class="text-sm text-muted-foreground"
                            >Organised by</span
                        >
                        <span class="flex items-center gap-2 font-extrabold">
                            {{ event.organizer.name }}
                            <span
                                v-if="event.organizer.verified"
                                class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-bold text-green-800"
                            >
                                <ShieldCheck
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Verified
                            </span>
                        </span>
                    </div>
                    <Link
                        :href="`/organizers/${event.organizer.slug}`"
                        class="inline-flex min-h-11 items-center rounded-lg border px-4 text-sm font-bold hover:bg-accent"
                        >View organiser</Link
                    >
                </div>

                <section v-if="event.description" aria-labelledby="about">
                    <h2 id="about" class="mb-3 text-2xl font-extrabold">
                        About this event
                    </h2>
                    <p
                        class="leading-relaxed whitespace-pre-line text-foreground/90"
                    >
                        {{ event.description }}
                    </p>
                </section>

                <section v-if="event.refund_policy" aria-labelledby="refunds">
                    <h2 id="refunds" class="mb-3 text-2xl font-extrabold">
                        Refund policy
                    </h2>
                    <p
                        class="rounded-2xl border bg-card p-4 text-sm leading-relaxed"
                    >
                        {{ event.refund_policy }}
                    </p>
                </section>

                <section v-if="related.length" aria-labelledby="related">
                    <h2 id="related" class="mb-4 text-2xl font-extrabold">
                        You might also like
                    </h2>
                    <div class="grid gap-5 sm:grid-cols-3">
                        <EventCard
                            v-for="item in related"
                            :key="item.id"
                            :event="item"
                        />
                    </div>
                </section>
            </article>

            <aside
                aria-labelledby="tickets"
                class="min-w-0 flex-[1_1_340px] lg:sticky lg:top-6"
            >
                <div
                    class="overflow-hidden rounded-2xl border bg-card shadow-lg"
                >
                    <div class="border-b p-5">
                        <h2 id="tickets" class="text-xl font-extrabold">
                            Select tickets
                        </h2>
                        <p
                            v-if="currency"
                            class="mt-1 text-sm text-muted-foreground"
                        >
                            Prices in {{ currency }}
                        </p>
                    </div>

                    <p
                        v-if="event.is_past"
                        class="p-5 text-sm text-muted-foreground"
                    >
                        This event has ended.
                    </p>
                    <p
                        v-else-if="!event.sales_open"
                        class="p-5 text-sm text-muted-foreground"
                    >
                        Ticket sales are not open right now.
                    </p>

                    <ul>
                        <li
                            v-for="type in event.ticket_types"
                            :key="type.id"
                            class="flex items-center gap-3 border-b p-5"
                        >
                            <div class="flex min-w-0 flex-1 flex-col gap-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <strong>{{ type.name }}</strong>
                                    <StatusBadge
                                        v-if="type.sold_out"
                                        status="sold_out"
                                    />
                                    <StatusBadge
                                        v-else-if="type.remaining < 20"
                                        status="few_left"
                                    />
                                </span>
                                <span class="font-extrabold">{{
                                    type.is_free ? 'Free' : type.price.formatted
                                }}</span>
                                <span
                                    v-if="type.description"
                                    class="text-xs text-muted-foreground"
                                    >{{ type.description }}</span
                                >
                                <span
                                    v-if="type.sales_end_label"
                                    class="text-xs text-muted-foreground"
                                    >Sales end {{ type.sales_end_label }}</span
                                >
                            </div>
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    class="size-11 rounded-xl border text-xl font-bold disabled:opacity-40"
                                    :disabled="
                                        !canBuy(type.id) || !quantities[type.id]
                                    "
                                    :aria-label="`Remove one ${type.name} ticket`"
                                    @click="change(type.id, -1)"
                                >
                                    −
                                </button>
                                <span
                                    class="w-8 text-center font-extrabold tabular-nums"
                                    aria-live="polite"
                                    >{{ quantities[type.id] }}</span
                                >
                                <button
                                    type="button"
                                    class="size-11 rounded-xl border text-xl font-bold disabled:opacity-40"
                                    :disabled="
                                        !canBuy(type.id) ||
                                        (quantities[type.id] ?? 0) >=
                                            max(type.id)
                                    "
                                    :aria-label="`Add one ${type.name} ticket`"
                                    @click="change(type.id, 1)"
                                >
                                    +
                                </button>
                            </div>
                        </li>
                    </ul>

                    <div class="flex flex-col gap-3 p-5">
                        <div class="flex items-baseline justify-between">
                            <span class="text-muted-foreground"
                                >{{ count }}
                                {{ count === 1 ? 'ticket' : 'tickets' }}</span
                            >
                            <span class="text-xl font-extrabold tabular-nums">{{
                                subtotalLabel
                            }}</span>
                        </div>
                        <div
                            v-if="signedIn && event.sales_open"
                            class="flex flex-col gap-2"
                        >
                            <button
                                v-if="!showPromo && !promoOpen"
                                type="button"
                                class="self-start text-sm font-bold text-primary underline-offset-4 hover:underline"
                                @click="promoOpen = true"
                            >
                                Have a promo code?
                            </button>
                            <template v-else>
                                <label
                                    for="promo_code"
                                    class="text-sm font-bold"
                                    >Promo code</label
                                >
                                <input
                                    id="promo_code"
                                    v-model="form.promo_code"
                                    type="text"
                                    autocomplete="off"
                                    maxlength="40"
                                    class="min-h-11 rounded-lg border bg-background px-3 uppercase"
                                    :aria-invalid="
                                        Boolean(form.errors.promo_code)
                                    "
                                    aria-describedby="promo_error"
                                />
                                <p
                                    v-if="form.errors.promo_code"
                                    id="promo_error"
                                    class="text-sm text-destructive"
                                >
                                    {{ form.errors.promo_code }}
                                </p>
                            </template>
                        </div>

                        <p
                            v-if="form.errors.items || form.errors.event"
                            role="alert"
                            class="rounded-lg bg-destructive/10 p-3 text-sm font-semibold text-destructive"
                        >
                            {{ form.errors.items ?? form.errors.event }}
                        </p>

                        <button
                            v-if="signedIn"
                            type="button"
                            :disabled="
                                !event.sales_open ||
                                count === 0 ||
                                form.processing
                            "
                            class="min-h-12 rounded-xl bg-primary font-extrabold text-primary-foreground disabled:cursor-not-allowed disabled:opacity-60"
                            @click="checkout"
                        >
                            {{
                                form.processing
                                    ? 'Holding your tickets…'
                                    : 'Get tickets'
                            }}
                        </button>
                        <Link
                            v-else
                            href="/login"
                            class="inline-flex min-h-12 items-center justify-center rounded-xl bg-primary font-extrabold text-primary-foreground"
                        >
                            Log in to buy tickets
                        </Link>
                        <p class="text-xs text-muted-foreground">
                            Tickets are held for 10 minutes while you check out.
                            Booking fee and any tax are shown before you pay.
                            <template v-if="event.sales_end_label">
                                Online sales close {{ event.sales_end_label }}.
                            </template>
                        </p>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>
