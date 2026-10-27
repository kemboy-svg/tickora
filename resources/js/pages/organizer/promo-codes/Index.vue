<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import EmptyState from '@/components/tickora/EmptyState.vue';
import type { PromoCodeRow } from '@/types';

const props = defineProps<{
    codes: PromoCodeRow[];
    events: Array<{ id: string; title: string; currency: string }>;
    defaultCurrency: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Promo codes', href: '/organizer/promo-codes' }],
    },
});

const inputClass =
    'min-h-11 w-full rounded-lg border bg-background px-3 text-base';

const showForm = ref(props.codes.length === 0);

const form = useForm({
    code: '',
    discount_type: 'percent' as 'percent' | 'fixed',
    percent: '',
    amount: '',
    event: '',
    starts_at: '',
    ends_at: '',
    max_redemptions: '',
    max_per_customer: '',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
});

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const amountCurrency = computed(
    () =>
        props.events.find((e) => e.id === form.event)?.currency ??
        props.defaultCurrency,
);

function submit(): void {
    form.post('/organizer/promo-codes', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('code', 'percent', 'amount', 'max_redemptions');
            showForm.value = false;
        },
    });
}

function toggle(code: PromoCodeRow): void {
    router.put(
        `/organizer/promo-codes/${code.code}`,
        { is_active: !code.is_active },
        { preserveScroll: true },
    );
}

function remove(code: PromoCodeRow): void {
    router.delete(`/organizer/promo-codes/${code.code}`, {
        preserveScroll: true,
    });
}

const dateFormat = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function validity(code: PromoCodeRow): string {
    const from = code.starts_at
        ? dateFormat.format(new Date(code.starts_at))
        : null;
    const to = code.ends_at ? dateFormat.format(new Date(code.ends_at)) : null;

    if (from && to) {
        return `${from} – ${to}`;
    }

    if (to) {
        return `Until ${to}`;
    }

    return from ? `From ${from}` : 'No end date';
}
</script>

<template>
    <Head title="Promo codes" />

    <div class="flex flex-col gap-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight">
                    Promo codes
                </h1>
                <p class="text-sm text-muted-foreground">
                    Discounts customers enter at checkout. A code works for one
                    event or all of your events.
                </p>
            </div>
            <button
                v-if="!showForm"
                type="button"
                class="min-h-11 rounded-xl bg-primary px-5 font-extrabold text-primary-foreground"
                @click="showForm = true"
            >
                New promo code
            </button>
        </div>

        <form
            v-if="showForm"
            class="flex max-w-3xl flex-col gap-5 rounded-2xl border bg-card p-5"
            @submit.prevent="submit"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <label for="code" class="text-sm font-bold">Code</label>
                    <input
                        id="code"
                        v-model="form.code"
                        type="text"
                        required
                        maxlength="40"
                        autocomplete="off"
                        placeholder="e.g. EARLYBIRD"
                        :class="[inputClass, 'uppercase']"
                    />
                    <InputError :message="errors.code" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="event" class="text-sm font-bold"
                        >Valid for</label
                    >
                    <select id="event" v-model="form.event" :class="inputClass">
                        <option value="">All my events</option>
                        <option v-for="e in events" :key="e.id" :value="e.id">
                            {{ e.title }}
                        </option>
                    </select>
                    <InputError :message="errors.event" />
                </div>
            </div>

            <fieldset class="flex flex-col gap-3">
                <legend class="mb-1.5 text-sm font-bold">Discount</legend>
                <div class="flex flex-wrap gap-4">
                    <label class="flex min-h-11 items-center gap-2">
                        <input
                            v-model="form.discount_type"
                            type="radio"
                            value="percent"
                            class="size-4"
                        />
                        Percentage off
                    </label>
                    <label class="flex min-h-11 items-center gap-2">
                        <input
                            v-model="form.discount_type"
                            type="radio"
                            value="fixed"
                            class="size-4"
                        />
                        Fixed amount off
                    </label>
                </div>
                <div
                    v-if="form.discount_type === 'percent'"
                    class="flex max-w-xs flex-col gap-1.5"
                >
                    <label for="percent" class="text-sm font-bold"
                        >Percent off</label
                    >
                    <input
                        id="percent"
                        v-model="form.percent"
                        type="number"
                        min="0.01"
                        max="100"
                        step="0.01"
                        inputmode="decimal"
                        :class="inputClass"
                    />
                    <InputError :message="errors.percent" />
                </div>
                <div v-else class="flex max-w-xs flex-col gap-1.5">
                    <label for="amount" class="text-sm font-bold"
                        >Amount off ({{ amountCurrency }})</label
                    >
                    <input
                        id="amount"
                        v-model="form.amount"
                        type="text"
                        inputmode="decimal"
                        placeholder="e.g. 100"
                        :class="inputClass"
                    />
                    <InputError :message="errors.amount" />
                </div>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <label for="starts_at" class="text-sm font-bold"
                        >Starts
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></label
                    >
                    <input
                        id="starts_at"
                        v-model="form.starts_at"
                        type="datetime-local"
                        :class="inputClass"
                    />
                    <InputError :message="errors.starts_at" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="ends_at" class="text-sm font-bold"
                        >Ends
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></label
                    >
                    <input
                        id="ends_at"
                        v-model="form.ends_at"
                        type="datetime-local"
                        :class="inputClass"
                    />
                    <InputError :message="errors.ends_at" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="max_redemptions" class="text-sm font-bold"
                        >Total uses
                        <span class="font-normal text-muted-foreground"
                            >(blank = unlimited)</span
                        ></label
                    >
                    <input
                        id="max_redemptions"
                        v-model="form.max_redemptions"
                        type="number"
                        min="1"
                        :class="inputClass"
                    />
                    <InputError :message="errors.max_redemptions" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="max_per_customer" class="text-sm font-bold"
                        >Uses per customer
                        <span class="font-normal text-muted-foreground"
                            >(blank = unlimited)</span
                        ></label
                    >
                    <input
                        id="max_per_customer"
                        v-model="form.max_per_customer"
                        type="number"
                        min="1"
                        :class="inputClass"
                    />
                    <InputError :message="errors.max_per_customer" />
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                Times are in your timezone ({{ form.timezone }}).
            </p>

            <div class="flex flex-wrap gap-3">
                <button
                    type="submit"
                    class="min-h-11 rounded-xl bg-primary px-5 font-extrabold text-primary-foreground disabled:opacity-60"
                    :disabled="form.processing"
                >
                    Create code
                </button>
                <button
                    v-if="codes.length"
                    type="button"
                    class="min-h-11 rounded-xl border px-5 font-bold hover:bg-accent"
                    @click="showForm = false"
                >
                    Cancel
                </button>
            </div>
        </form>

        <EmptyState
            v-if="!codes.length && !showForm"
            title="No promo codes yet"
            description="Create a code to offer early-bird or group discounts."
        />

        <div
            v-if="codes.length"
            class="overflow-x-auto rounded-2xl border bg-card"
        >
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead class="border-b bg-muted/50 text-xs uppercase">
                    <tr>
                        <th scope="col" class="p-3">Code</th>
                        <th scope="col" class="p-3">Discount</th>
                        <th scope="col" class="p-3">Valid for</th>
                        <th scope="col" class="p-3">When</th>
                        <th scope="col" class="p-3">Used</th>
                        <th scope="col" class="p-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="code in codes"
                        :key="code.code"
                        class="border-b last:border-0"
                    >
                        <td class="p-3">
                            <span class="font-mono font-bold">{{
                                code.code
                            }}</span>
                            <span
                                v-if="!code.is_active"
                                class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-700"
                                >Paused</span
                            >
                        </td>
                        <td class="p-3">{{ code.discount_label }}</td>
                        <td class="p-3">{{ code.event ?? 'All events' }}</td>
                        <td class="p-3">{{ validity(code) }}</td>
                        <td class="p-3 tabular-nums">
                            {{ code.redemptions
                            }}<template v-if="code.max_redemptions">
                                / {{ code.max_redemptions }}</template
                            >
                        </td>
                        <td class="p-3">
                            <div class="flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="min-h-9 rounded-lg border px-3 font-bold hover:bg-accent"
                                    @click="toggle(code)"
                                >
                                    {{ code.is_active ? 'Pause' : 'Activate' }}
                                </button>
                                <button
                                    v-if="code.redemptions === 0"
                                    type="button"
                                    class="min-h-9 rounded-lg border px-3 font-bold text-destructive hover:bg-destructive/10"
                                    @click="remove(code)"
                                >
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
