<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { FlaskConical, Lock } from '@lucide/vue';
import { ref } from 'vue';
import type { Money } from '@/types';

/**
 * Stand-in for a payment provider's hosted checkout while no real provider
 * is connected. It collects no card details; the buttons decide the outcome.
 */
const props = defineProps<{
    payment: {
        id: string;
        amount: Money;
        reference: string;
        status: string;
        event: string;
        email: string;
        cancel_url: string;
    };
}>();

const busy = ref<'succeed' | 'decline' | null>(null);

function finish(outcome: 'succeed' | 'decline'): void {
    busy.value = outcome;
    router.post(
        `/sandbox/payments/${props.payment.id}`,
        { outcome },
        { onFinish: () => (busy.value = null) },
    );
}
</script>

<template>
    <Head title="Sandbox payment" />

    <div
        class="flex min-h-svh flex-col items-center bg-slate-100 px-4 py-10 text-slate-900"
    >
        <div
            class="mb-4 flex w-full max-w-md items-center gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950"
            role="note"
        >
            <FlaskConical class="size-5 shrink-0" aria-hidden="true" />
            <span
                ><strong>Test mode.</strong> This is Tickora's sandbox payment
                provider. No real money moves and no card details are
                collected.</span
            >
        </div>

        <main
            class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-xl"
        >
            <div class="border-b p-6">
                <p class="text-sm text-slate-500">Pay Tickora</p>
                <p
                    class="mt-1 text-4xl font-extrabold tracking-tight tabular-nums"
                >
                    {{ payment.amount.formatted }}
                </p>
                <p class="mt-2 text-sm text-slate-600">
                    {{ payment.event }} · Order {{ payment.reference }}
                </p>
                <p class="text-sm text-slate-500">{{ payment.email }}</p>
            </div>

            <div
                v-if="payment.status === 'created'"
                class="flex flex-col gap-3 p-6"
            >
                <button
                    type="button"
                    class="flex min-h-12 items-center justify-center gap-2 rounded-xl bg-[#6D4AFF] font-extrabold text-white disabled:opacity-60"
                    :disabled="busy !== null"
                    @click="finish('succeed')"
                >
                    <Lock class="size-4" aria-hidden="true" />
                    {{
                        busy === 'succeed'
                            ? 'Processing…'
                            : `Pay ${payment.amount.formatted}`
                    }}
                </button>
                <button
                    type="button"
                    class="min-h-12 rounded-xl border font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                    :disabled="busy !== null"
                    @click="finish('decline')"
                >
                    {{
                        busy === 'decline'
                            ? 'Declining…'
                            : 'Simulate a declined card'
                    }}
                </button>
                <a
                    :href="payment.cancel_url"
                    class="py-2 text-center text-sm font-semibold text-slate-500 hover:text-slate-800"
                    >Cancel and go back</a
                >
            </div>

            <div v-else class="flex flex-col gap-3 p-6 text-sm">
                <p>
                    This payment is already
                    <strong>{{ payment.status }}</strong
                    >.
                </p>
                <a
                    :href="payment.cancel_url"
                    class="font-bold text-[#6D4AFF] hover:underline"
                    >Back to Tickora</a
                >
            </div>
        </main>
    </div>
</template>
