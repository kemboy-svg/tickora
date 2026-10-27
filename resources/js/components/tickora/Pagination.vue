<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { PaginationLink } from '@/types';

defineProps<{ links: PaginationLink[] }>();

function label(raw: string): string {
    return raw.replace('&laquo;', '‹').replace('&raquo;', '›');
}
</script>

<template>
    <nav
        v-if="links.length > 3"
        aria-label="Pagination"
        class="flex flex-wrap items-center justify-center gap-1"
    >
        <template v-for="(link, index) in links" :key="index">
            <Link
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                class="inline-flex h-10 min-w-10 items-center justify-center rounded-lg border px-3 text-sm font-semibold"
                :class="
                    link.active
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'bg-card hover:bg-accent'
                "
                :aria-current="link.active ? 'page' : undefined"
            >
                {{ label(link.label) }}
            </Link>
            <span
                v-else
                class="inline-flex h-10 min-w-10 items-center justify-center px-3 text-sm text-muted-foreground"
            >
                {{ label(link.label) }}
            </span>
        </template>
    </nav>
</template>
