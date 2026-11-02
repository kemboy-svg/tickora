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
            fill-rule="evenodd"
            d="M32 10.5a21.5 21.5 0 1 1 0 43 21.5 21.5 0 0 1 0-43Zm0 11a10.5 10.5 0 1 0 0 21 10.5 10.5 0 0 0 0-21Z"
        />
        <rect x="7" y="28.5" width="12" height="7" rx="3.5" fill="#6D4AFF" />
        <rect x="45" y="28.5" width="12" height="7" rx="3.5" fill="#6D4AFF" />
        <circle cx="32" cy="32" r="5.5" fill="#FF6B6B" />
    </svg>
</template>
