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
</template>
