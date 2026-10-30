<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Check, Copy, PlugZap } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';

export type ProviderSettings = {
    provider: 'stripe' | 'paystack';
    label: string;
    enabled: boolean;
    is_default: boolean;
    usable: boolean;
    mode: 'test' | 'live' | null;
    public_key: string | null;
    secret_key_hint: string | null;
    webhook_secret_hint: string | null;
    uses_webhook_secret: boolean;
    webhook_url: string;
    currencies: string[];
    updated_label: string | null;
    updated_by: string | null;
};

const props = defineProps<{ settings: ProviderSettings }>();

// Secret fields always start empty: saved secrets are never sent to the
// browser, and leaving a field blank keeps what is saved.
const form = useForm({
    enabled: props.settings.enabled,
    public_key: props.settings.public_key ?? '',
    secret_key: '',
    webhook_secret: '',
});

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const inputClass =
    'min-h-11 w-full rounded-lg border bg-background px-3 font-mono text-sm';

function save(): void {
    form.put(`/admin/payments/${props.settings.provider}`, {
        preserveScroll: true,
        onSuccess: () => form.reset('secret_key', 'webhook_secret'),
    });
}

const testing = ref(false);

function test(): void {
    testing.value = true;
    router.post(
        `/admin/payments/${props.settings.provider}/test`,
        {},
        { preserveScroll: true, onFinish: () => (testing.value = false) },
    );
}

const copied = ref(false);

async function copyWebhookUrl(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.settings.webhook_url);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}

const docs = computed(() =>
    props.settings.provider === 'stripe'
        ? {
              keys: 'Stripe Dashboard → Developers → API keys',
              webhook:
                  'Stripe Dashboard → Developers → Webhooks → Add endpoint. Send the events checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed and checkout.session.expired, then paste the signing secret below.',
          }
        : {
              keys: 'Paystack Dashboard → Settings → API Keys & Webhooks',
              webhook:
                  'Paystack Dashboard → Settings → API Keys & Webhooks → Webhook URL. Paystack signs webhooks with your secret key, so there is no separate signing secret.',
          },
);
</script>

<template>
    <section
        :aria-labelledby="`${settings.provider}-heading`"
        class="flex flex-col gap-5 rounded-2xl border bg-card p-6"
    >
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2
                    :id="`${settings.provider}-heading`"
                    class="flex items-center gap-2 text-xl font-extrabold"
                >
                    {{ settings.label }}
                    <span
                        v-if="settings.usable"
                        class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-bold text-green-800 dark:bg-green-950 dark:text-green-200"
                        >On</span
                    >
                    <span
                        v-else
                        class="rounded-full bg-muted px-2 py-0.5 text-xs font-bold text-muted-foreground"
                        >Off</span
                    >
                    <span
                        v-if="settings.mode"
                        class="rounded-full px-2 py-0.5 text-xs font-bold"
                        :class="
                            settings.mode === 'live'
                                ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200'
                                : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200'
                        "
                        >{{
                            settings.mode === 'live' ? 'Live' : 'Test'
                        }}
                        keys</span
                    >
                    <span
                        v-if="settings.is_default"
                        class="rounded-full bg-violet-100 px-2 py-0.5 text-xs font-bold text-violet-800 dark:bg-violet-950 dark:text-violet-200"
                        >Default</span
                    >
                </h2>
                <p class="text-sm text-muted-foreground">
                    Currencies: {{ settings.currencies.join(', ') }}
                </p>
                <p
                    v-if="settings.updated_label"
                    class="text-xs text-muted-foreground"
                >
                    Last changed {{ settings.updated_label
                    }}<template v-if="settings.updated_by">
                        by {{ settings.updated_by }}</template
                    >
                </p>
            </div>
            <button
                v-if="settings.secret_key_hint"
                type="button"
                class="inline-flex min-h-11 items-center gap-2 rounded-xl border px-4 text-sm font-bold hover:bg-accent disabled:opacity-60"
                :disabled="testing"
                @click="test"
            >
                <PlugZap class="size-4" aria-hidden="true" />
                {{ testing ? 'Testing…' : 'Test connection' }}
            </button>
        </header>

        <form class="flex flex-col gap-4" @submit.prevent="save">
            <label class="flex min-h-11 items-center gap-3">
                <input
                    v-model="form.enabled"
                    type="checkbox"
                    class="size-5 rounded"
                />
                <span class="font-bold"
                    >Take payments with {{ settings.label }}</span
                >
            </label>
            <InputError :message="errors.enabled" />

            <p class="text-sm text-muted-foreground">
                Find your keys in {{ docs.keys }}. Use test keys until you are
                ready to take real money.
            </p>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <label
                        :for="`${settings.provider}-secret`"
                        class="text-sm font-bold"
                        >Secret key</label
                    >
                    <input
                        :id="`${settings.provider}-secret`"
                        v-model="form.secret_key"
                        type="password"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="
                            settings.secret_key_hint
                                ? `Saved (${settings.secret_key_hint}). Leave blank to keep.`
                                : 'sk_test_…'
                        "
                        :class="inputClass"
                    />
                    <InputError :message="errors.secret_key" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label
                        :for="`${settings.provider}-public`"
                        class="text-sm font-bold"
                        >Public key
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></label
                    >
                    <input
                        :id="`${settings.provider}-public`"
                        v-model="form.public_key"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="pk_test_…"
                        :class="inputClass"
                    />
                    <InputError :message="errors.public_key" />
                </div>
            </div>

            <div class="flex flex-col gap-1.5 rounded-xl bg-muted/60 p-4">
                <span class="text-sm font-bold">Webhook URL</span>
                <div class="flex gap-2">
                    <code
                        class="min-w-0 flex-1 truncate rounded-lg border bg-background px-3 py-2.5 text-xs"
                        >{{ settings.webhook_url }}</code
                    >
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center gap-1.5 rounded-lg border bg-background px-3 text-sm font-bold hover:bg-accent"
                        @click="copyWebhookUrl"
                    >
                        <component
                            :is="copied ? Check : Copy"
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ copied ? 'Copied' : 'Copy' }}
                    </button>
                </div>
                <p class="text-xs text-muted-foreground">{{ docs.webhook }}</p>
            </div>

            <div
                v-if="settings.uses_webhook_secret"
                class="flex flex-col gap-1.5"
            >
                <label
                    :for="`${settings.provider}-webhook`"
                    class="text-sm font-bold"
                    >Webhook signing secret</label
                >
                <input
                    :id="`${settings.provider}-webhook`"
                    v-model="form.webhook_secret"
                    type="password"
                    autocomplete="off"
                    spellcheck="false"
                    :placeholder="
                        settings.webhook_secret_hint
                            ? `Saved (${settings.webhook_secret_hint}). Leave blank to keep.`
                            : 'whsec_…'
                    "
                    :class="inputClass"
                />
                <InputError :message="errors.webhook_secret" />
                <p
                    v-if="!settings.webhook_secret_hint"
                    class="text-xs text-muted-foreground"
                >
                    Without it, payments still complete when customers return
                    from Stripe, but Stripe's notifications are refused.
                </p>
            </div>

            <div>
                <button
                    type="submit"
                    class="min-h-11 rounded-xl bg-primary px-5 font-extrabold text-primary-foreground disabled:opacity-60"
                    :disabled="form.processing"
                >
                    Save {{ settings.label }} settings
                </button>
            </div>
        </form>
    </section>
</template>
