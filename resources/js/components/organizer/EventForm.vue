<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import type { EventFormOptions, VenueFormValues } from '@/types';

export type EventPayload = {
    title: string;
    category: string;
    summary: string;
    description: string;
    timezone: string;
    currency: string;
    starts_at: string;
    ends_at: string;
    sales_starts_at: string;
    sales_ends_at: string;
    visibility: 'public' | 'unlisted';
    refund_policy: string;
    min_age: string;
    venue: VenueFormValues;
};

const props = defineProps<{
    initial: EventPayload;
    options: EventFormOptions;
    method: 'post' | 'put';
    url: string;
    submitLabel: string;
    // Once an event has left draft, its schedule, venue and currency are fixed.
    scheduleLocked?: boolean;
    currencyLocked?: boolean;
}>();

const form = useForm<EventPayload>(structuredClone(props.initial));

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const inputClass =
    'min-h-11 w-full rounded-lg border bg-background px-3 text-base disabled:cursor-not-allowed disabled:bg-muted disabled:text-muted-foreground';

function submit(): void {
    form.submit(props.method, props.url, { preserveScroll: true });
}
</script>

<template>
    <form class="flex flex-col gap-8" novalidate @submit.prevent="submit">
        <fieldset class="flex flex-col gap-4 rounded-2xl border bg-card p-6">
            <legend class="px-1 text-lg font-extrabold">Basics</legend>

            <div class="flex flex-col gap-1.5">
                <label for="title" class="text-sm font-bold">Event title</label>
                <input
                    id="title"
                    v-model="form.title"
                    type="text"
                    maxlength="150"
                    required
                    :aria-invalid="!!errors.title"
                    aria-describedby="title-error"
                    :class="inputClass"
                />
                <InputError id="title-error" :message="errors.title" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <label for="category" class="text-sm font-bold"
                        >Category</label
                    >
                    <select
                        id="category"
                        v-model="form.category"
                        required
                        :class="inputClass"
                    >
                        <option value="" disabled>Choose a category</option>
                        <option
                            v-for="c in options.categories"
                            :key="c.slug"
                            :value="c.slug"
                        >
                            {{ c.name }}
                        </option>
                    </select>
                    <InputError :message="errors.category" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="visibility" class="text-sm font-bold"
                        >Visibility</label
                    >
                    <select
                        id="visibility"
                        v-model="form.visibility"
                        :class="inputClass"
                    >
                        <option value="public">
                            Public — listed in search
                        </option>
                        <option value="unlisted">Unlisted — link only</option>
                    </select>
                    <InputError :message="errors.visibility" />
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="summary" class="text-sm font-bold"
                    >Short summary
                    <span class="font-normal text-muted-foreground"
                        >(shown on event cards)</span
                    ></label
                >
                <input
                    id="summary"
                    v-model="form.summary"
                    type="text"
                    maxlength="280"
                    :class="inputClass"
                />
                <InputError :message="errors.summary" />
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="description" class="text-sm font-bold"
                    >Description</label
                >
                <textarea
                    id="description"
                    v-model="form.description"
                    rows="7"
                    maxlength="10000"
                    class="w-full rounded-lg border bg-background p-3 text-base"
                />
                <InputError :message="errors.description" />
            </div>
        </fieldset>

        <fieldset class="flex flex-col gap-4 rounded-2xl border bg-card p-6">
            <legend class="px-1 text-lg font-extrabold">Date and venue</legend>
            <p
                v-if="scheduleLocked"
                class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900"
            >
                The date, venue and currency are fixed once an event is
                submitted. To reschedule a live event, contact support so ticket
                holders are notified.
            </p>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="flex flex-col gap-1.5">
                    <label for="starts_at" class="text-sm font-bold"
                        >Starts</label
                    >
                    <input
                        id="starts_at"
                        v-model="form.starts_at"
                        type="datetime-local"
                        required
                        :disabled="scheduleLocked"
                        :class="inputClass"
                    />
                    <InputError :message="errors.starts_at" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="ends_at" class="text-sm font-bold">Ends</label>
                    <input
                        id="ends_at"
                        v-model="form.ends_at"
                        type="datetime-local"
                        required
                        :disabled="scheduleLocked"
                        :class="inputClass"
                    />
                    <InputError :message="errors.ends_at" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="timezone" class="text-sm font-bold"
                        >Timezone</label
                    >
                    <select
                        id="timezone"
                        v-model="form.timezone"
                        :disabled="scheduleLocked"
                        :class="inputClass"
                    >
                        <option
                            v-for="tz in options.timezones"
                            :key="tz"
                            :value="tz"
                        >
                            {{ tz }}
                        </option>
                    </select>
                    <InputError :message="errors.timezone" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <label for="sales_starts_at" class="text-sm font-bold"
                        >Ticket sales open
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></label
                    >
                    <input
                        id="sales_starts_at"
                        v-model="form.sales_starts_at"
                        type="datetime-local"
                        :disabled="scheduleLocked"
                        :class="inputClass"
                    />
                    <InputError :message="errors.sales_starts_at" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="sales_ends_at" class="text-sm font-bold"
                        >Ticket sales close
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></label
                    >
                    <input
                        id="sales_ends_at"
                        v-model="form.sales_ends_at"
                        type="datetime-local"
                        :disabled="scheduleLocked"
                        :class="inputClass"
                    />
                    <InputError :message="errors.sales_ends_at" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <label for="venue_name" class="text-sm font-bold"
                        >Venue name</label
                    >
                    <input
                        id="venue_name"
                        v-model="form.venue.name"
                        type="text"
                        required
                        :disabled="scheduleLocked"
                        :class="inputClass"
                    />
                    <InputError :message="errors['venue.name']" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="venue_address" class="text-sm font-bold"
                        >Address</label
                    >
                    <input
                        id="venue_address"
                        v-model="form.venue.address_line"
                        type="text"
                        required
                        autocomplete="street-address"
                        :disabled="scheduleLocked"
                        :class="inputClass"
                    />
                    <InputError :message="errors['venue.address_line']" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="venue_city" class="text-sm font-bold"
                        >City</label
                    >
                    <input
                        id="venue_city"
                        v-model="form.venue.city"
                        type="text"
                        required
                        :disabled="scheduleLocked"
                        :class="inputClass"
                    />
                    <InputError :message="errors['venue.city']" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="venue_country" class="text-sm font-bold"
                        >Country code
                        <span class="font-normal text-muted-foreground"
                            >(e.g. MU, KE)</span
                        ></label
                    >
                    <input
                        id="venue_country"
                        v-model="form.venue.country_code"
                        type="text"
                        maxlength="2"
                        required
                        :disabled="scheduleLocked"
                        :class="[inputClass, 'uppercase']"
                    />
                    <InputError :message="errors['venue.country_code']" />
                </div>
            </div>
        </fieldset>

        <fieldset class="flex flex-col gap-4 rounded-2xl border bg-card p-6">
            <legend class="px-1 text-lg font-extrabold">Policies</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <label for="currency" class="text-sm font-bold"
                        >Currency</label
                    >
                    <select
                        id="currency"
                        v-model="form.currency"
                        :disabled="scheduleLocked || currencyLocked"
                        aria-describedby="currency-help"
                        :class="inputClass"
                    >
                        <option
                            v-for="c in options.currencies"
                            :key="c"
                            :value="c"
                        >
                            {{ c }}
                        </option>
                    </select>
                    <p id="currency-help" class="text-xs text-muted-foreground">
                        Every ticket for this event is sold in this currency.
                    </p>
                    <InputError :message="errors.currency" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="min_age" class="text-sm font-bold"
                        >Minimum age
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></label
                    >
                    <input
                        id="min_age"
                        v-model="form.min_age"
                        type="number"
                        min="0"
                        max="21"
                        :class="inputClass"
                    />
                    <InputError :message="errors.min_age" />
                </div>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="refund_policy" class="text-sm font-bold"
                    >Refund policy</label
                >
                <textarea
                    id="refund_policy"
                    v-model="form.refund_policy"
                    rows="3"
                    placeholder="e.g. Refunds up to 7 days before the event, excluding the booking fee."
                    class="w-full rounded-lg border bg-background p-3 text-base"
                />
                <InputError :message="errors.refund_policy" />
            </div>
        </fieldset>

        <div class="flex flex-wrap items-center gap-3">
            <button
                type="submit"
                :disabled="form.processing"
                class="min-h-12 rounded-xl bg-primary px-6 font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
            >
                {{ form.processing ? 'Saving…' : submitLabel }}
            </button>
            <span
                v-if="form.recentlySuccessful"
                class="text-sm text-green-700"
                role="status"
                >Saved</span
            >
            <span
                v-if="form.hasErrors"
                class="text-sm font-semibold text-red-700"
                role="alert"
                >Please fix the highlighted fields.</span
            >
        </div>
    </form>
</template>
