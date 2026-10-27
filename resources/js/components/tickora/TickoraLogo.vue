<script setup lang="ts">
withDefaults(
    defineProps<{
        size?: number;
        showWordmark?: boolean;
        inverted?: boolean;
    }>(),
    { size: 30, showWordmark: true, inverted: false },
);
</script>

<template>
    <span class="inline-flex items-center gap-2.5">
        <svg
            :width="size"
            :height="size"
            viewBox="0 0 32 32"
            aria-hidden="true"
            class="shrink-0"
        >
            <path
                d="M6 6H26A4 4 0 0 1 30 10V13A3 3 0 0 0 30 19V22A4 4 0 0 1 26 26H6A4 4 0 0 1 2 22V19A3 3 0 0 0 2 13V10A4 4 0 0 1 6 6Z"
                fill="#6D4AFF"
            />
            <path
                d="M11 10V22"
                stroke="#FFFFFF"
                stroke-width="2"
                stroke-linecap="round"
                stroke-dasharray="2 3"
            />
            <circle cx="20" cy="16" r="3.5" fill="#FF6B6B" />
        </svg>
        <span
            v-if="showWordmark"
            class="text-xl font-extrabold tracking-tight"
            :class="inverted ? 'text-white' : 'text-brand-navy dark:text-white'"
        >
            Tickora
        </span>
    </span>
</template>
