<script setup lang="ts">
/**
 * The Tickora mark: a ticket torn at the door, violet body and coral stub.
 * The violet lightens on dark backgrounds via --tickora-stub (see app.css).
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
    <span
        class="inline-flex items-center gap-2.5"
        :class="inverted ? '[--tickora-stub:#8466FF]' : ''"
    >
        <svg
            :width="size"
            :height="size"
            viewBox="0 0 64 64"
            aria-hidden="true"
            class="shrink-0"
        >
            <g transform="rotate(-10 32 32)">
                <path
                    style="fill: var(--tickora-stub, #6d4aff)"
                    d="M8 14h28l-6 9 6 9-6 9 6 9H8a5 5 0 0 1-5-5V19a5 5 0 0 1 5-5z"
                />
                <path
                    fill="#FF6B6B"
                    d="M44 11h13a5 5 0 0 1 5 5v26a5 5 0 0 1-5 5H44l6-9-6-9 6-9-6-9z"
                />
            </g>
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
