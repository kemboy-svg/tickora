<script setup lang="ts">
/**
 * The Tickora mark: a ticket whose crossbar and stem form a "T", with a
 * coral dot for the one ticket that gets you into every event.
 * Keep in sync with AppLogoIcon.vue and public/favicon.svg.
 */
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
            viewBox="0 0 64 64"
            aria-hidden="true"
            class="shrink-0"
        >
            <rect width="64" height="64" rx="15" fill="#6D4AFF" />
            <path
                fill="#FFFFFF"
                d="M15 11h34a5 5 0 0 1 5 5v1.6a4.4 4.4 0 0 0 0 8.8V28a5 5 0 0 1-5 5h-9.5v13.5a7.5 7.5 0 0 1-15 0V33H15a5 5 0 0 1-5-5v-1.6a4.4 4.4 0 0 0 0-8.8V16a5 5 0 0 1 5-5z"
            />
            <circle cx="32" cy="46" r="3.8" fill="#FF6B6B" />
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
