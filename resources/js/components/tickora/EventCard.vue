<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatusBadge from '@/components/tickora/StatusBadge.vue';
import type { EventCard } from '@/types';

const props = defineProps<{ event: EventCard }>();

// Fallback cover tone per category for events without a photo.
const tones = ['#2E1F7A', '#1E293B', '#7C2D12', '#134E4A', '#581C47'];
const tone = computed(() => {
    let hash = 0;
    for (const char of props.event.category_slug) {
        hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    }
    return tones[hash % tones.length];
});
</script>

<template>
    <Link
        :href="`/events/${event.slug}`"
        class="group flex flex-col overflow-hidden rounded-2xl border bg-card text-card-foreground transition-shadow hover:shadow-lg focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none motion-safe:transition-transform motion-safe:hover:-translate-y-0.5"
    >
        <div
            class="relative flex aspect-[16/10] flex-col justify-between overflow-hidden p-3.5"
            :style="{ backgroundColor: tone }"
        >
            <img
                v-if="event.cover_url"
                :src="event.cover_url"
                alt=""
                loading="lazy"
                decoding="async"
                class="absolute inset-0 size-full object-cover motion-safe:transition-transform motion-safe:duration-300 motion-safe:group-hover:scale-[1.03]"
            />
            <span
                class="relative self-start rounded-full bg-black/45 px-2.5 py-1 text-xs font-bold text-white backdrop-blur-sm"
            >
                {{ event.category }}
            </span>
        </div>
        <div class="flex flex-1 flex-col gap-1.5 p-4">
            <span
                class="text-xs font-extrabold tracking-wider text-primary uppercase"
            >
                {{ event.starts_label }}
            </span>
            <h3 class="text-lg leading-snug font-extrabold tracking-tight">
                {{ event.title }}
            </h3>
            <p v-if="event.venue" class="text-sm text-muted-foreground">
                {{ event.venue
                }}<template v-if="event.city"> · {{ event.city }}</template>
            </p>
            <div
                class="mt-auto flex items-center justify-between gap-2 border-t pt-3"
            >
                <span class="text-sm text-muted-foreground">
                    <template v-if="event.is_free">
                        <strong class="text-base text-foreground">Free</strong>
                    </template>
                    <template v-else-if="event.price_from">
                        From
                        <strong class="text-base text-foreground">{{
                            event.price_from.formatted
                        }}</strong>
                    </template>
                </span>
                <StatusBadge :status="event.availability" />
            </div>
        </div>
    </Link>
</template>
