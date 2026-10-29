<script setup lang="ts">
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';

/**
 * A short form (such as "confirm your password") shown inside the app
 * shell, so signed-in people keep their sidebar. Takes the same title and
 * description props as the auth layout.
 */
const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AppSidebarLayout :breadcrumbs="[{ title, href: '#' }]">
        <div class="flex flex-1 items-start justify-center p-4 sm:p-10">
            <div
                class="flex w-full max-w-sm flex-col gap-6 rounded-2xl border bg-card p-6"
            >
                <div class="space-y-2">
                    <h1 class="text-xl font-extrabold">{{ title }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </AppSidebarLayout>
</template>
