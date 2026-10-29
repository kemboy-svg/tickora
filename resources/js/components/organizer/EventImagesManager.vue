<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ImagePlus, Star, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    ACCEPTED_IMAGE_TYPES,
    isAcceptedImage,
    prepareImage,
} from '@/lib/images';
import type { EventImage } from '@/types';

/**
 * Photos of an existing event: upload (saved immediately), choose the
 * cover, remove. The first image is the cover shown on listings.
 */
const props = defineProps<{
    eventId: string;
    images: EventImage[];
    max: number;
    maxKb: number;
    canManage: boolean;
}>();

const input = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const localError = ref<string | null>(null);
const page = usePage();

const remaining = computed(() => props.max - props.images.length);
const base = computed(() => `/organizer/events/${props.eventId}/images`);

const serverErrors = computed(() =>
    Object.entries(page.props.errors ?? {})
        .filter(([key]) => key === 'images' || key.startsWith('images.'))
        .map(([, message]) => message),
);

async function upload(list: FileList | null): Promise<void> {
    localError.value = null;

    if (!list || list.length === 0) {
        return;
    }

    const chosen = Array.from(list);

    if (chosen.some((file) => !isAcceptedImage(file))) {
        localError.value = 'Only JPG, PNG and WebP images can be added.';
        return;
    }

    if (chosen.length > remaining.value) {
        localError.value = `You can add ${remaining.value} more image${remaining.value === 1 ? '' : 's'}.`;
        return;
    }

    uploading.value = true;
    const files = await Promise.all(chosen.map(prepareImage));

    if (files.some((file) => file.size > props.maxKb * 1024)) {
        uploading.value = false;
        localError.value = `Each image must be ${Math.round(props.maxKb / 1024)} MB or smaller.`;
        return;
    }

    router.post(
        base.value,
        { images: files },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                uploading.value = false;

                if (input.value) {
                    input.value.value = '';
                }
            },
        },
    );
}

function makeCover(image: EventImage): void {
    router.post(
        `${base.value}/${image.id}/cover`,
        {},
        { preserveScroll: true },
    );
}

function remove(image: EventImage): void {
    router.delete(`${base.value}/${image.id}`, { preserveScroll: true });
}
</script>

<template>
    <section
        aria-labelledby="images-heading"
        class="flex flex-col gap-4 rounded-2xl border bg-card p-6"
    >
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="images-heading" class="text-lg font-extrabold">
                    Photos
                </h2>
                <p class="text-sm text-muted-foreground">
                    The first photo is the cover on listings and the event page.
                    A wide 16:10 image looks best.
                </p>
            </div>
            <label
                v-if="canManage && remaining > 0"
                for="event-images-upload"
                class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border px-4 font-bold hover:bg-accent has-[input:focus-visible]:ring-[3px] has-[input:focus-visible]:ring-ring/50"
                :class="uploading ? 'pointer-events-none opacity-60' : ''"
            >
                <ImagePlus class="size-5" aria-hidden="true" />
                {{ uploading ? 'Uploading…' : 'Add photos' }}
                <input
                    id="event-images-upload"
                    ref="input"
                    type="file"
                    multiple
                    :accept="ACCEPTED_IMAGE_TYPES.join(',')"
                    class="sr-only"
                    :disabled="uploading"
                    @change="upload(($event.target as HTMLInputElement).files)"
                />
            </label>
        </div>

        <div
            v-if="images.length"
            class="grid grid-cols-2 gap-3 sm:grid-cols-4"
            role="list"
            aria-label="Event photos"
        >
            <figure
                v-for="(image, index) in images"
                :key="image.id"
                role="listitem"
                class="relative overflow-hidden rounded-xl border bg-muted"
            >
                <img
                    :src="image.url"
                    :alt="`Event photo ${index + 1}`"
                    :width="image.width"
                    :height="image.height"
                    loading="lazy"
                    class="aspect-[16/10] w-full object-cover"
                />
                <span
                    v-if="index === 0"
                    class="absolute top-2 left-2 rounded-full bg-primary px-2 py-0.5 text-xs font-bold text-primary-foreground"
                    >Cover</span
                >
                <figcaption
                    v-if="canManage"
                    class="flex items-center justify-end gap-1 px-2 py-1.5"
                >
                    <button
                        v-if="index !== 0"
                        type="button"
                        class="flex min-h-8 items-center gap-1 rounded-lg px-2 text-xs font-bold text-muted-foreground hover:bg-accent hover:text-foreground"
                        @click="makeCover(image)"
                    >
                        <Star class="size-4" aria-hidden="true" />
                        Make cover
                    </button>
                    <button
                        type="button"
                        class="flex size-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                        :aria-label="`Remove photo ${index + 1}`"
                        title="Remove"
                        @click="remove(image)"
                    >
                        <Trash2 class="size-4" aria-hidden="true" />
                    </button>
                </figcaption>
            </figure>
        </div>

        <p
            v-else
            class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            No photos yet. Events with a cover photo get noticeably more clicks.
        </p>

        <ul
            v-if="localError || serverErrors.length"
            class="flex flex-col gap-1 text-sm text-destructive"
            role="alert"
        >
            <li v-if="localError">{{ localError }}</li>
            <li v-for="message in serverErrors" :key="message">
                {{ message }}
            </li>
        </ul>
    </section>
</template>
