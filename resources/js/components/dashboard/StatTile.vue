<script setup lang="ts">
import type { Component } from 'vue';

/**
 * A headline number with a label and an optional supporting line. For
 * money in several currencies, pass the first as `value` and the rest as
 * `extra` lines.
 */
defineProps<{
    label: string;
    value: string | number;
    hint?: string | null;
    extra?: string[];
    icon?: Component;
}>();
</script>

<template>
    <div class="flex flex-col gap-1.5 rounded-2xl border bg-card p-5">
        <span
            class="flex items-center gap-2 text-sm font-semibold text-muted-foreground"
        >
            <component
                :is="icon"
                v-if="icon"
                class="size-4 text-primary"
                aria-hidden="true"
            />
            {{ label }}
        </span>
        <span
            class="text-2xl font-extrabold tracking-tight tabular-nums sm:text-3xl"
            >{{ value }}</span
        >
        <span
            v-for="line in extra ?? []"
            :key="line"
            class="text-sm font-bold tabular-nums"
            >{{ line }}</span
        >
        <span v-if="hint" class="text-sm text-muted-foreground">{{
            hint
        }}</span>
    </div>
</template>
