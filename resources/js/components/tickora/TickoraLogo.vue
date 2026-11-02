<script setup lang="ts">
/**
 * The Tickora mark: a ticket ring. A punched ticket seen from the front,
 * with the notches of a ticket stub on each side and a coral dot for the
 * one ticket that gets you into every event.
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
                fill-rule="evenodd"
                d="M32 10.5a21.5 21.5 0 1 1 0 43 21.5 21.5 0 0 1 0-43Zm0 11a10.5 10.5 0 1 0 0 21 10.5 10.5 0 0 0 0-21Z"
            />
            <rect
                x="7"
                y="28.5"
                width="12"
                height="7"
                rx="3.5"
                fill="#6D4AFF"
            />
            <rect
                x="45"
                y="28.5"
                width="12"
                height="7"
                rx="3.5"
                fill="#6D4AFF"
            />
            <circle cx="32" cy="32" r="5.5" fill="#FF6B6B" />
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
