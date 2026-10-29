<script setup lang="ts">
import { ImagePlus, Star, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import {
    ACCEPTED_IMAGE_TYPES,
    formatBytes,
    isAcceptedImage,
    prepareImage,
} from '@/lib/images';

/**
 * Chooses photos for a new event before it is saved. The first photo is
 * the cover. Files are only uploaded when the form is submitted.
 */
const props = defineProps<{
    max: number;
    maxKb: number;
    errors: Record<string, string | undefined>;
}>();

const files = defineModel<File[]>({ required: true });

const input = ref<HTMLInputElement | null>(null);
const dragging = ref(false);
const busy = ref(false);
const localError = ref<string | null>(null);

// Object URLs for previews, revoked when files change or on unmount.
const previews = ref<string[]>([]);

watch(
    files,
    (list) => {
        previews.value.forEach((url) => URL.revokeObjectURL(url));
        previews.value = list.map((file) => URL.createObjectURL(file));
    },
    { immediate: true },
);

onBeforeUnmount(() =>
    previews.value.forEach((url) => URL.revokeObjectURL(url)),
);

const serverErrors = computed(() =>
    Object.entries(props.errors)
        .filter(([key]) => key === 'images' || key.startsWith('images.'))
        .map(([key, message]) => {
            const index = Number(key.split('.')[1]);
            return Number.isInteger(index) && files.value[index]
                ? `${files.value[index].name}: ${message}`
                : message;
        })
        .filter((m): m is string => Boolean(m)),
);

const remaining = computed(() => props.max - files.value.length);

async function add(list: FileList | File[] | null): Promise<void> {
    localError.value = null;

    if (!list || list.length === 0) {
        return;
    }

    const chosen = Array.from(list);
    const rejected = chosen.filter((file) => !isAcceptedImage(file));
    const accepted = chosen
        .filter(isAcceptedImage)
        .slice(0, Math.max(0, remaining.value));

    if (rejected.length) {
        localError.value = 'Only JPG, PNG and WebP images can be added.';
    } else if (chosen.length > accepted.length) {
        localError.value = `An event can have up to ${props.max} images.`;
    }

    busy.value = true;
    const prepared = await Promise.all(accepted.map(prepareImage));
    busy.value = false;

    const tooBig = prepared.filter((file) => file.size > props.maxKb * 1024);

    if (tooBig.length) {
        localError.value = `Each image must be ${Math.round(props.maxKb / 1024)} MB or smaller.`;
    }

    files.value = [
        ...files.value,
        ...prepared.filter((file) => file.size <= props.maxKb * 1024),
    ];

    if (input.value) {
        input.value.value = '';
    }
}

function remove(index: number): void {
    files.value = files.value.filter((_, i) => i !== index);
}

function makeCover(index: number): void {
    const list = [...files.value];
    const [file] = list.splice(index, 1);
    files.value = [file, ...list];
}

function onDrop(event: DragEvent): void {
    dragging.value = false;
    void add(event.dataTransfer?.files ?? null);
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div
            v-if="files.length"
            class="grid grid-cols-2 gap-3 sm:grid-cols-4"
            role="list"
            aria-label="Selected images"
        >
            <figure
                v-for="(file, index) in files"
                :key="previews[index]"
                role="listitem"
                class="group relative overflow-hidden rounded-xl border bg-muted"
            >
                <img
                    :src="previews[index]"
                    :alt="`Selected image ${index + 1}: ${file.name}`"
                    class="aspect-[16/10] w-full object-cover"
                />
                <span
                    v-if="index === 0"
                    class="absolute top-2 left-2 rounded-full bg-primary px-2 py-0.5 text-xs font-bold text-primary-foreground"
                    >Cover</span
                >
                <figcaption
                    class="flex items-center justify-between gap-1 px-2 py-1.5 text-xs text-muted-foreground"
                >
                    <span class="truncate">{{ formatBytes(file.size) }}</span>
                    <span class="flex gap-1">
                        <button
                            v-if="index !== 0"
                            type="button"
                            class="flex size-8 items-center justify-center rounded-lg hover:bg-accent hover:text-foreground"
                            :aria-label="`Make image ${index + 1} the cover`"
                            title="Make cover"
                            @click="makeCover(index)"
                        >
                            <Star class="size-4" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            class="flex size-8 items-center justify-center rounded-lg hover:bg-destructive/10 hover:text-destructive"
                            :aria-label="`Remove image ${index + 1}`"
                            title="Remove"
                            @click="remove(index)"
                        >
                            <X class="size-4" aria-hidden="true" />
                        </button>
                    </span>
                </figcaption>
            </figure>
        </div>

        <label
            v-if="remaining > 0"
            for="event-images"
            class="flex cursor-pointer flex-col items-center gap-2 rounded-2xl border-2 border-dashed p-6 text-center transition-colors hover:border-primary hover:bg-violet-50/60 has-[input:focus-visible]:ring-[3px] has-[input:focus-visible]:ring-ring/50 dark:hover:bg-violet-950/30"
            :class="dragging ? 'border-primary bg-violet-50/60' : ''"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <ImagePlus class="size-7 text-primary" aria-hidden="true" />
            <span class="font-bold">
                {{ busy ? 'Preparing images…' : 'Add photos' }}
            </span>
            <span class="text-sm text-muted-foreground">
                Drag images here or click to browse. JPG, PNG or WebP, at least
                600 × 300 px. The first photo is the cover; a wide 16:10 image
                looks best. {{ remaining }} of {{ max }} left.
            </span>
            <input
                id="event-images"
                ref="input"
                type="file"
                multiple
                :accept="ACCEPTED_IMAGE_TYPES.join(',')"
                class="sr-only"
                @change="add(($event.target as HTMLInputElement).files)"
            />
        </label>

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
    </div>
</template>
