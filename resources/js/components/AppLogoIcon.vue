<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- The Tickora mark; see components/tickora/TickoraLogo.vue. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 64 64"
        role="img"
        aria-label="Tickora"
        :class="className"
        v-bind="$attrs"
    >
        <rect width="64" height="64" rx="15" fill="#6D4AFF" />
        <path
            fill="#FFFFFF"
            d="M15 11h34a5 5 0 0 1 5 5v1.6a4.4 4.4 0 0 0 0 8.8V28a5 5 0 0 1-5 5h-9.5v13.5a7.5 7.5 0 0 1-15 0V33H15a5 5 0 0 1-5-5v-1.6a4.4 4.4 0 0 0 0-8.8V16a5 5 0 0 1 5-5z"
        />
        <circle cx="32" cy="46" r="3.8" fill="#FF6B6B" />
    </svg>
</template>
