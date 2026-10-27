<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Shown on organiser pages. Lets members of several organisers switch which
// one they're working in; the server re-checks membership on every switch.
const page = usePage();
const workspace = computed(() => page.props.workspace);

function change(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    router.post('/organizer/switch', { organizer: value });
}
</script>

<template>
    <div
        v-if="workspace"
        class="px-4 py-2 group-data-[collapsible=icon]:hidden"
    >
        <label
            for="workspace"
            class="mb-1 block text-xs font-semibold text-muted-foreground"
            >Organiser</label
        >
        <select
            v-if="workspace.available.length > 1"
            id="workspace"
            :value="workspace.current.id"
            class="min-h-10 w-full rounded-lg border bg-background px-2 text-sm font-semibold"
            @change="change"
        >
            <option v-for="o in workspace.available" :key="o.id" :value="o.id">
                {{ o.name }}
            </option>
        </select>
        <p v-else id="workspace" class="text-sm font-bold">
            {{ workspace.current.name }}
        </p>
    </div>
</template>
