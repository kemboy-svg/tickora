<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PaymentProviderCard from '@/components/admin/PaymentProviderCard.vue';
import type { ProviderSettings } from '@/components/admin/PaymentProviderCard.vue';

const props = defineProps<{
    providers: ProviderSettings[];
    default_provider: string;
    sandbox_available: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Payment settings', href: '/admin/payments' }],
    },
});

const defaultForm = useForm({ provider: props.default_provider });

const choices = computed(() => [
    ...props.providers.map((p) => ({
        value: p.provider as string,
        label: p.label,
        disabled: !p.usable,
    })),
    ...(props.sandbox_available
        ? [{ value: 'fake', label: 'Sandbox (test only)', disabled: false }]
        : []),
]);

function saveDefault(): void {
    defaultForm.put('/admin/payments/default', { preserveScroll: true });
}
</script>

<template>
    <Head title="Payment settings" />

    <div class="flex max-w-4xl flex-col gap-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-extrabold tracking-tight">
                Payment settings
            </h1>
            <p class="text-muted-foreground">
                Connect the providers customers pay through. Each event uses the
                default below unless you choose another one for it under Event
                review.
            </p>
        </header>

        <p
            class="flex items-start gap-3 rounded-xl border border-violet-200 bg-violet-50 p-4 text-sm text-violet-950 dark:border-violet-900 dark:bg-violet-950/40 dark:text-violet-100"
        >
            <ShieldCheck class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <span
                >Keys are encrypted before they are stored and are never shown
                again, only their last four characters. Card details are entered
                on the provider's own page and never reach Tickora.</span
            >
        </p>

        <form
            class="flex flex-col gap-3 rounded-2xl border bg-card p-6"
            @submit.prevent="saveDefault"
        >
            <label for="default-provider" class="text-lg font-extrabold"
                >Default for events</label
            >
            <div class="flex flex-wrap gap-3">
                <select
                    id="default-provider"
                    v-model="defaultForm.provider"
                    class="min-h-11 min-w-56 rounded-lg border bg-background px-3"
                >
                    <option
                        v-for="choice in choices"
                        :key="choice.value"
                        :value="choice.value"
                        :disabled="choice.disabled"
                    >
                        {{ choice.label
                        }}{{ choice.disabled ? ' (switch on first)' : '' }}
                    </option>
                </select>
                <button
                    type="submit"
                    class="min-h-11 rounded-xl border px-5 font-bold hover:bg-accent disabled:opacity-60"
                    :disabled="
                        defaultForm.processing ||
                        defaultForm.provider === default_provider
                    "
                >
                    Save default
                </button>
            </div>
            <InputError :message="defaultForm.errors.provider" />
        </form>

        <PaymentProviderCard
            v-for="provider in providers"
            :key="provider.provider"
            :settings="provider"
        />
    </div>
</template>
