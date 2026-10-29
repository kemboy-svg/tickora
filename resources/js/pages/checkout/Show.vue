<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CalendarDays, Clock, MapPin, ReceiptText } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { CheckoutSummary } from '@/types';

const props = defineProps<{ checkout: CheckoutSummary }>();

const holding = computed(
    () =>
        props.checkout.status === 'open' ||
        props.checkout.status === 'awaiting_payment',
);

// Count down from the server's figure rather than the client clock, so a
// wrong device clock cannot show the wrong time left.
const secondsLeft = ref(props.checkout.seconds_left);
let timer: ReturnType<typeof setInterval> | undefined;

function startTimer(): void {
    clearInterval(timer);
    secondsLeft.value = props.checkout.seconds_left;

    if (!holding.value) {
        return;
    }

    timer = setInterval(() => {
        secondsLeft.value = Math.max(0, secondsLeft.value - 1);

        if (secondsLeft.value === 0) {
            clearInterval(timer);
            router.reload({ only: ['checkout'] });
        }
    }, 1000);
}

onMounted(startTimer);
onBeforeUnmount(() => clearInterval(timer));
watch(() => props.checkout.expires_at + props.checkout.status, startTimer);

const timeLeft = computed(() => {
    const m = Math.floor(secondsLeft.value / 60);
    const s = secondsLeft.value % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
});

const urgent = computed(() => secondsLeft.value <= 120);

const ticketCount = computed(() =>
    props.checkout.lines.reduce((sum, line) => sum + line.quantity, 0),
);

const promo = useForm({ promo_code: props.checkout.promo_code ?? '' });

function applyPromo(): void {
    promo.post(`/checkout/${props.checkout.id}/promo`, {
        preserveScroll: true,
    });
}

function removePromo(): void {
    promo.promo_code = '';
    applyPromo();
}

const confirm = useForm({});
const confirmError = computed(
    () => (confirm.errors as Record<string, string | undefined>).checkout,
);

function placeOrder(): void {
    confirm.post(`/checkout/${props.checkout.id}/confirm`, {
        preserveScroll: true,
    });
}

const payment = useForm({});
const paymentError = computed(
    () => (payment.errors as Record<string, string | undefined>).checkout,
);

function pay(): void {
    payment.post(`/checkout/${props.checkout.id}/pay`, {
        preserveScroll: true,
    });
}

const release = useForm({});

function cancel(): void {
    release.delete(`/checkout/${props.checkout.id}`);
}
</script>

<template>
    <Head title="Checkout" />

    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
        <Link
            :href="`/events/${checkout.event.slug}`"
            class="text-sm font-bold text-primary hover:underline"
            >← Back to event</Link
        >
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">
            {{
                checkout.status === 'awaiting_payment'
                    ? 'Your order is reserved'
                    : 'Checkout'
            }}
        </h1>

        <div
            v-if="holding"
            role="timer"
            aria-live="off"
            class="mt-5 flex items-center gap-3 rounded-2xl border p-4"
            :class="
                urgent
                    ? 'border-amber-300 bg-amber-50 text-amber-900'
                    : 'bg-violet-50 text-violet-900'
            "
        >
            <Clock class="size-5 shrink-0" aria-hidden="true" />
            <p class="text-sm font-semibold">
                Your tickets are held for
                <span class="font-extrabold tabular-nums">{{ timeLeft }}</span
                >. After that they go back on sale.
            </p>
        </div>

        <div
            v-else-if="checkout.status === 'expired'"
            role="alert"
            class="mt-5 flex flex-col gap-3 rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-900"
        >
            <p class="font-extrabold">Your hold ran out</p>
            <p v-if="checkout.order?.refunded" class="text-sm">
                Your payment arrived after the tickets had sold out, so we
                refunded it in full. Sorry about that.
            </p>
            <p v-else class="text-sm">
                The tickets went back on sale. They may still be available —
                choose them again to start a new checkout.
            </p>
            <Link
                :href="`/events/${checkout.event.slug}`"
                class="inline-flex min-h-11 items-center self-start rounded-xl bg-primary px-5 font-extrabold text-primary-foreground"
                >Choose tickets again</Link
            >
        </div>

        <div
            v-else-if="checkout.status === 'cancelled'"
            class="mt-5 rounded-2xl border bg-muted p-5 text-sm"
        >
            You released these tickets.
            <Link
                :href="`/events/${checkout.event.slug}`"
                class="font-bold text-primary hover:underline"
                >Back to the event</Link
            >
        </div>

        <div class="mt-6 flex flex-wrap items-start gap-8">
            <section
                aria-labelledby="summary"
                class="min-w-0 flex-[999_1_480px] overflow-hidden rounded-2xl border bg-card"
            >
                <div class="flex flex-col gap-2 border-b p-5">
                    <h2 id="summary" class="text-xl font-extrabold">
                        {{ checkout.event.title }}
                    </h2>
                    <p
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <CalendarDays class="size-4" aria-hidden="true" />
                        {{ checkout.event.date_label }}
                    </p>
                    <p
                        v-if="checkout.event.venue"
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <MapPin class="size-4" aria-hidden="true" />
                        {{ checkout.event.venue
                        }}<template v-if="checkout.event.city"
                            >, {{ checkout.event.city }}</template
                        >
                    </p>
                </div>

                <ul>
                    <li
                        v-for="(line, i) in checkout.lines"
                        :key="i"
                        class="flex items-start justify-between gap-4 border-b p-5"
                    >
                        <div class="flex flex-col">
                            <span class="font-bold"
                                >{{ line.quantity }} × {{ line.name }}</span
                            >
                            <span class="text-sm text-muted-foreground">{{
                                line.unit_price.amount_minor === 0
                                    ? 'Free'
                                    : `${line.unit_price.formatted} each`
                            }}</span>
                        </div>
                        <span class="font-bold tabular-nums">{{
                            line.subtotal.formatted
                        }}</span>
                    </li>
                </ul>

                <dl class="flex flex-col gap-2 p-5 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">
                            Subtotal · {{ ticketCount }}
                            {{ ticketCount === 1 ? 'ticket' : 'tickets' }}
                        </dt>
                        <dd class="tabular-nums">
                            {{ checkout.subtotal.formatted }}
                        </dd>
                    </div>
                    <div
                        v-if="checkout.discount.amount_minor > 0"
                        class="flex justify-between text-green-700"
                    >
                        <dt>Discount ({{ checkout.promo_code }})</dt>
                        <dd class="tabular-nums">
                            −{{ checkout.discount.formatted }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Booking fee</dt>
                        <dd class="tabular-nums">
                            {{ checkout.fee.formatted }}
                        </dd>
                    </div>
                    <div
                        v-if="checkout.tax.amount_minor > 0"
                        class="flex justify-between"
                    >
                        <dt class="text-muted-foreground">Tax on fee</dt>
                        <dd class="tabular-nums">
                            {{ checkout.tax.formatted }}
                        </dd>
                    </div>
                    <div
                        class="mt-2 flex items-baseline justify-between border-t pt-3"
                    >
                        <dt class="text-base font-extrabold">Total</dt>
                        <dd class="text-2xl font-extrabold tabular-nums">
                            {{ checkout.total.formatted }}
                        </dd>
                    </div>
                </dl>
            </section>

            <aside class="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
                <template v-if="checkout.status === 'open'">
                    <form
                        class="flex flex-col gap-2 rounded-2xl border bg-card p-5"
                        @submit.prevent="applyPromo"
                    >
                        <label for="promo" class="text-sm font-bold"
                            >Promo code</label
                        >
                        <div class="flex gap-2">
                            <input
                                id="promo"
                                v-model="promo.promo_code"
                                type="text"
                                autocomplete="off"
                                maxlength="40"
                                class="min-h-11 min-w-0 flex-1 rounded-lg border bg-background px-3 uppercase"
                                :aria-invalid="Boolean(promo.errors.promo_code)"
                                aria-describedby="promo_help"
                            />
                            <button
                                type="submit"
                                class="min-h-11 rounded-lg border px-4 text-sm font-bold hover:bg-accent disabled:opacity-50"
                                :disabled="
                                    promo.processing || promo.promo_code === ''
                                "
                            >
                                Apply
                            </button>
                        </div>
                        <p
                            v-if="promo.errors.promo_code"
                            id="promo_help"
                            class="text-sm text-destructive"
                        >
                            {{ promo.errors.promo_code }}
                        </p>
                        <button
                            v-else-if="checkout.promo_code"
                            type="button"
                            class="self-start text-sm font-bold text-primary hover:underline"
                            @click="removePromo"
                        >
                            Remove {{ checkout.promo_code }}
                        </button>
                    </form>

                    <p
                        v-if="confirmError"
                        role="alert"
                        class="rounded-lg bg-destructive/10 p-3 text-sm font-semibold text-destructive"
                    >
                        {{ confirmError }}
                    </p>

                    <button
                        type="button"
                        class="min-h-12 rounded-xl bg-primary font-extrabold text-primary-foreground disabled:opacity-60"
                        :disabled="confirm.processing"
                        @click="placeOrder"
                    >
                        {{
                            checkout.total.amount_minor === 0
                                ? 'Confirm free order'
                                : `Continue to payment · ${checkout.total.formatted}`
                        }}
                    </button>
                    <button
                        type="button"
                        class="min-h-11 rounded-xl border text-sm font-bold hover:bg-accent disabled:opacity-60"
                        :disabled="release.processing"
                        @click="cancel"
                    >
                        Release my tickets
                    </button>
                </template>

                <div
                    v-else-if="
                        checkout.status === 'awaiting_payment' && checkout.order
                    "
                    class="flex flex-col gap-3 rounded-2xl border bg-card p-5"
                >
                    <ReceiptText
                        class="size-6 text-primary"
                        aria-hidden="true"
                    />
                    <p class="text-sm text-muted-foreground">Order reference</p>
                    <p class="text-2xl font-extrabold tracking-wide">
                        {{ checkout.order.reference }}
                    </p>
                    <p class="text-sm">
                        Status:
                        <strong>{{ checkout.order.status_label }}</strong>
                    </p>
                    <p
                        v-if="checkout.order.payment_declined"
                        role="alert"
                        class="rounded-lg bg-destructive/10 p-3 text-sm font-semibold text-destructive"
                    >
                        Your payment was declined. No money was taken. Try again
                        or use another card; your tickets are still held.
                    </p>
                    <p
                        v-else
                        class="rounded-lg bg-violet-50 p-3 text-sm text-violet-900 dark:bg-violet-950/40 dark:text-violet-100"
                    >
                        Your tickets stay reserved until the timer runs out.
                    </p>
                    <p
                        v-if="paymentError"
                        role="alert"
                        class="rounded-lg bg-destructive/10 p-3 text-sm font-semibold text-destructive"
                    >
                        {{ paymentError }}
                    </p>
                    <button
                        type="button"
                        class="min-h-12 rounded-xl bg-primary font-extrabold text-primary-foreground disabled:opacity-60"
                        :disabled="payment.processing"
                        @click="pay"
                    >
                        {{
                            payment.processing
                                ? 'Opening payment…'
                                : checkout.order.payment_declined
                                  ? `Try again · ${checkout.total.formatted}`
                                  : `Pay ${checkout.total.formatted}`
                        }}
                    </button>
                    <button
                        type="button"
                        class="min-h-11 rounded-xl border text-sm font-bold hover:bg-accent disabled:opacity-60"
                        :disabled="release.processing"
                        @click="cancel"
                    >
                        Cancel order
                    </button>
                </div>
            </aside>
        </div>
    </div>
</template>
