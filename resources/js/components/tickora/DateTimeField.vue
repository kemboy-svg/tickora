<script setup lang="ts">
import { CalendarDays } from '@lucide/vue';
import { ref } from 'vue';

/**
 * A date-and-time input that behaves the same in every browser:
 * clicking anywhere in the field (or the calendar button) opens the
 * native picker, the calendar icon stays visible in dark mode, and the
 * value is always "YYYY-MM-DDTHH:MM" — never with seconds — which is the
 * format the server expects.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        disabled?: boolean;
        required?: boolean;
        min?: string | null;
        invalid?: boolean;
        describedBy?: string;
    }>(),
    {
        disabled: false,
        required: false,
        min: null,
        invalid: false,
        describedBy: undefined,
    },
);

const model = defineModel<string | null>({ required: true });

const input = ref<HTMLInputElement | null>(null);

function update(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    model.value = value === '' ? '' : value.slice(0, 16);
}

function openPicker(): void {
    const el = input.value;

    if (!el || props.disabled) {
        return;
    }

    el.focus();

    try {
        // Not every browser supports it (and it throws without a user
        // gesture); the field still works by typing in that case.
        el.showPicker?.();
    } catch {
        // Ignore: typing into the field remains possible.
    }
}
</script>

<template>
    <div class="relative">
        <input
            :id="id"
            ref="input"
            type="datetime-local"
            step="60"
            :value="model ?? ''"
            :min="min ?? undefined"
            :required="required"
            :disabled="disabled"
            :aria-invalid="invalid || undefined"
            :aria-describedby="describedBy"
            class="min-h-11 w-full cursor-pointer rounded-lg border bg-background py-2 pr-12 pl-3 text-base tabular-nums disabled:cursor-not-allowed disabled:bg-muted disabled:text-muted-foreground aria-invalid:border-destructive [&::-webkit-calendar-picker-indicator]:hidden"
            @input="update"
            @change="update"
            @click="openPicker"
        />
        <button
            type="button"
            class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-muted-foreground hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="disabled"
            :aria-label="`Open calendar for ${id.replace(/_/g, ' ')}`"
            tabindex="-1"
            @click="openPicker"
        >
            <CalendarDays class="size-5" aria-hidden="true" />
        </button>
    </div>
</template>
