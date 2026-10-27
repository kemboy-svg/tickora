<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import EmptyState from '@/components/tickora/EmptyState.vue';
import Pagination from '@/components/tickora/Pagination.vue';
import StatusBadge from '@/components/tickora/StatusBadge.vue';
import type { OrganizerStatus, Paginated } from '@/types';

type OrganizerRow = {
    id: string;
    name: string;
    slug: string;
    status: OrganizerStatus;
    country_code: string;
    default_currency: string;
    owner_name: string;
    owner_email: string;
    support_email: string | null;
    description: string | null;
    events_count: number;
    created_label: string | null;
};

const props = defineProps<{
    organizers: Paginated<OrganizerRow>;
    filters: { status: string };
    statuses: OrganizerStatus[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Organisers', href: '/admin/organizers' }],
    },
});

const labels: Record<OrganizerStatus, string> = {
    pending: 'Pending',
    verified: 'Verified',
    suspended: 'Suspended',
    rejected: 'Rejected',
};

const form = useForm<{ status: OrganizerStatus | ''; reason: string }>({
    status: '',
    reason: '',
});
const acting = ref<{ id: string; status: OrganizerStatus } | null>(null);

function filter(status: string): void {
    router.get('/admin/organizers', { status }, { preserveState: true });
}

function start(id: string, status: OrganizerStatus): void {
    form.reset();
    form.clearErrors();
    form.status = status;
    acting.value = { id, status };

    if (status === 'verified') {
        submit();
    }
}

function submit(): void {
    if (!acting.value) {
        return;
    }
    form.patch(`/admin/organizers/${acting.value.id}/status`, {
        preserveScroll: true,
        onSuccess: () => {
            acting.value = null;
        },
    });
}
</script>

<template>
    <Head title="Organisers" />

    <div class="flex flex-col gap-5 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-extrabold tracking-tight">Organisers</h1>
            <p class="text-sm text-muted-foreground">
                Verify organisers before their events can be published.
            </p>
        </header>

        <div
            role="tablist"
            aria-label="Filter by status"
            class="flex flex-wrap gap-2"
        >
            <button
                v-for="status in statuses"
                :key="status"
                type="button"
                role="tab"
                :aria-selected="props.filters.status === status"
                class="min-h-10 rounded-full border px-4 text-sm font-semibold"
                :class="
                    props.filters.status === status
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'bg-card hover:bg-accent'
                "
                @click="filter(status)"
            >
                {{ labels[status] }}
            </button>
        </div>

        <EmptyState
            v-if="!organizers.data.length"
            title="No organisers in this list"
        />

        <article
            v-for="organizer in organizers.data"
            :key="organizer.id"
            class="flex flex-col gap-3 rounded-2xl border bg-card p-5"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="flex items-center gap-2 text-lg font-extrabold">
                        {{ organizer.name }}
                        <StatusBadge :status="organizer.status" />
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ organizer.owner_name }} ({{ organizer.owner_email }})
                        · {{ organizer.country_code }} ·
                        {{ organizer.default_currency }} ·
                        {{ organizer.events_count }} events · applied
                        {{ organizer.created_label }}
                    </p>
                </div>
            </div>
            <p v-if="organizer.description" class="text-sm">
                {{ organizer.description }}
            </p>

            <div
                v-if="
                    acting?.id !== organizer.id || acting.status === 'verified'
                "
                class="flex flex-wrap gap-2"
            >
                <button
                    v-if="organizer.status !== 'verified'"
                    type="button"
                    :disabled="form.processing"
                    class="min-h-11 rounded-lg bg-primary px-4 font-bold text-primary-foreground hover:bg-primary/90"
                    @click="start(organizer.id, 'verified')"
                >
                    Verify
                </button>
                <button
                    v-if="organizer.status === 'verified'"
                    type="button"
                    class="min-h-11 rounded-lg border border-red-300 px-4 font-semibold text-red-800 hover:bg-red-50"
                    @click="start(organizer.id, 'suspended')"
                >
                    Suspend…
                </button>
                <button
                    v-if="organizer.status === 'pending'"
                    type="button"
                    class="min-h-11 rounded-lg border px-4 font-semibold hover:bg-accent"
                    @click="start(organizer.id, 'rejected')"
                >
                    Reject…
                </button>
            </div>

            <form v-else class="flex flex-col gap-3" @submit.prevent="submit">
                <label :for="`reason-${organizer.id}`" class="text-sm font-bold"
                    >Reason ({{ labels[acting.status].toLowerCase() }})</label
                >
                <textarea
                    :id="`reason-${organizer.id}`"
                    v-model="form.reason"
                    rows="2"
                    class="w-full rounded-lg border bg-background p-3"
                />
                <InputError :message="form.errors.reason" />
                <div class="flex gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="min-h-11 rounded-lg bg-red-700 px-5 font-bold text-white hover:bg-red-800"
                    >
                        Confirm
                    </button>
                    <button
                        type="button"
                        class="min-h-11 rounded-lg border px-4 font-semibold"
                        @click="acting = null"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </article>

        <Pagination :links="organizers.links" />
    </div>
</template>
