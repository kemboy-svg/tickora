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
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill="#6D4AFF"
            d="M6 6H26A4 4 0 0 1 30 10V13A3 3 0 0 0 30 19V22A4 4 0 0 1 26 26H6A4 4 0 0 1 2 22V19A3 3 0 0 0 2 13V10A4 4 0 0 1 6 6Z"
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
</template>
