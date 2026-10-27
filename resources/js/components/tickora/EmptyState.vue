<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div
        class="flex flex-col items-center gap-3 rounded-2xl border border-dashed bg-card px-6 py-14 text-center"
    >
        <svg
            width="44"
            height="44"
            viewBox="0 0 32 32"
            aria-hidden="true"
            class="opacity-80"
        >
            <path
                d="M6 6H26A4 4 0 0 1 30 10V13A3 3 0 0 0 30 19V22A4 4 0 0 1 26 26H6A4 4 0 0 1 2 22V19A3 3 0 0 0 2 13V10A4 4 0 0 1 6 6Z"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                class="text-muted-foreground"
            />
        </svg>
        <h3 class="text-lg font-bold">{{ title }}</h3>
        <p v-if="description" class="max-w-md text-sm text-muted-foreground">
            {{ description }}
        </p>
        <slot />
    </div>
</template>
