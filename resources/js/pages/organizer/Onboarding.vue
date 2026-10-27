<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';

const props = defineProps<{
    currencies: string[];
    hasOrganizers: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Become an organiser', href: '/organizer/onboarding' },
        ],
    },
});

const form = useForm({
    name: '',
    country_code: 'MU',
    default_currency: props.currencies.includes('MUR')
        ? 'MUR'
        : (props.currencies[0] ?? ''),
    description: '',
    support_email: '',
});

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);
const inputClass =
    'min-h-11 w-full rounded-lg border bg-background px-3 text-base';
</script>

<template>
    <Head title="Become an organiser" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 sm:p-6">
        <header class="flex flex-col gap-2">
            <h1 class="text-2xl font-extrabold tracking-tight">
                {{
                    hasOrganizers
                        ? 'Add another organiser'
                        : 'Start selling tickets'
                }}
            </h1>
            <p class="text-muted-foreground">
                Tell us who's running the events. You can create and prepare
                events straight away; publishing opens once our team has
                verified your organiser, usually within one working day.
            </p>
        </header>

        <form
            class="flex flex-col gap-5 rounded-2xl border bg-card p-6"
            novalidate
            @submit.prevent="form.post('/organizer/onboarding')"
        >
            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-bold"
                    >Organiser name</label
                >
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    required
                    autocomplete="organization"
                    placeholder="e.g. Kaz Productions Ltd"
                    :class="inputClass"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <label for="country" class="text-sm font-bold"
                        >Country code
                        <span class="font-normal text-muted-foreground"
                            >(where you're registered)</span
                        ></label
                    >
                    <input
                        id="country"
                        v-model="form.country_code"
                        type="text"
                        maxlength="2"
                        required
                        :class="[inputClass, 'uppercase']"
                    />
                    <InputError :message="errors.country_code" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="currency" class="text-sm font-bold"
                        >Default currency</label
                    >
                    <select
                        id="currency"
                        v-model="form.default_currency"
                        :class="inputClass"
                    >
                        <option v-for="c in currencies" :key="c" :value="c">
                            {{ c }}
                        </option>
                    </select>
                    <InputError :message="errors.default_currency" />
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="support_email" class="text-sm font-bold"
                    >Customer support email
                    <span class="font-normal text-muted-foreground"
                        >(defaults to yours)</span
                    ></label
                >
                <input
                    id="support_email"
                    v-model="form.support_email"
                    type="email"
                    autocomplete="email"
                    :class="inputClass"
                />
                <InputError :message="errors.support_email" />
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="description" class="text-sm font-bold"
                    >About you
                    <span class="font-normal text-muted-foreground"
                        >(optional)</span
                    ></label
                >
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="3"
                    class="w-full rounded-lg border bg-background p-3 text-base"
                />
                <InputError :message="errors.description" />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="min-h-12 rounded-xl bg-primary px-6 font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
            >
                Create organiser
            </button>
        </form>
    </div>
</template>
