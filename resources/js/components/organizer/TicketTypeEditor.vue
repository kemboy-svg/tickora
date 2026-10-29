<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import DateTimeField from '@/components/tickora/DateTimeField.vue';
import StatusBadge from '@/components/tickora/StatusBadge.vue';
import type { TicketTypeFormValues, TicketTypeStatus } from '@/types';

const props = defineProps<{
    eventId: string;
    currency: string;
    ticketTypes: TicketTypeFormValues[];
    statuses: string[];
    canManage: boolean;
}>();

type TicketPayload = {
    name: string;
    description: string;
    price: string;
    quantity_total: number;
    min_per_order: number;
    max_per_order: number;
    sales_starts_at: string;
    sales_ends_at: string;
    status: TicketTypeStatus;
};

const blank: TicketPayload = {
    name: '',
    description: '',
    price: '',
    quantity_total: 100,
    min_per_order: 1,
    max_per_order: 10,
    sales_starts_at: '',
    sales_ends_at: '',
    status: 'active',
};

// null = adding a new type; a public ID = editing that type.
const editing = ref<string | null | undefined>(undefined);
const editingType = computed(() =>
    props.ticketTypes.find((t) => t.id === editing.value),
);

const form = useForm<TicketPayload>({ ...blank });
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const base = computed(() => `/organizer/events/${props.eventId}/ticket-types`);
const inputClass =
    'min-h-11 w-full rounded-lg border bg-background px-3 text-base disabled:bg-muted disabled:text-muted-foreground';

function startAdd(): void {
    form.defaults({ ...blank });
    form.reset();
    form.clearErrors();
    editing.value = null;
}

function startEdit(type: TicketTypeFormValues): void {
    form.defaults({
        name: type.name,
        description: type.description ?? '',
        price: type.price,
        quantity_total: type.quantity_total,
        min_per_order: type.min_per_order,
        max_per_order: type.max_per_order,
        sales_starts_at: type.sales_starts_at ?? '',
        sales_ends_at: type.sales_ends_at ?? '',
        status: type.status,
    });
    form.reset();
    form.clearErrors();
    editing.value = type.id;
}

function close(): void {
    editing.value = undefined;
}

function save(): void {
    const options = { preserveScroll: true, onSuccess: close };

    if (editing.value === null) {
        form.post(base.value, options);
    } else if (editing.value) {
        form.put(`${base.value}/${editing.value}`, options);
    }
}

function remove(type: TicketTypeFormValues): void {
    if (!window.confirm(`Delete the "${type.name}" ticket type?`)) {
        return;
    }
    router.delete(`${base.value}/${type.id}`, { preserveScroll: true });
}

function statusLabel(status: string): string {
    return (
        {
            active: 'On sale',
            paused: 'Paused — visible, not purchasable',
            hidden: 'Hidden — not shown to customers',
        }[status] ?? status
    );
}
</script>

<template>
    <section
        aria-labelledby="ticket-types-title"
        class="flex flex-col gap-4 rounded-2xl border bg-card p-6"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="ticket-types-title" class="text-lg font-extrabold">
                    Ticket types
                </h2>
                <p class="text-sm text-muted-foreground">
                    Prices in {{ currency }}. Use 0 for free tickets.
                </p>
            </div>
            <button
                v-if="canManage && editing === undefined"
                type="button"
                class="min-h-11 rounded-lg bg-primary px-4 font-bold text-primary-foreground hover:bg-primary/90"
                @click="startAdd"
            >
                Add ticket type
            </button>
        </div>

        <div class="overflow-x-auto">
            <table
                v-if="ticketTypes.length"
                class="w-full min-w-[640px] text-sm"
            >
                <thead>
                    <tr
                        class="border-b text-left text-xs text-muted-foreground"
                    >
                        <th scope="col" class="py-2 pr-3 font-bold">Name</th>
                        <th scope="col" class="py-2 pr-3 font-bold">Price</th>
                        <th scope="col" class="py-2 pr-3 font-bold">
                            Sold / capacity
                        </th>
                        <th scope="col" class="py-2 pr-3 font-bold">
                            Per order
                        </th>
                        <th scope="col" class="py-2 pr-3 font-bold">Status</th>
                        <th scope="col" class="py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="type in ticketTypes"
                        :key="type.id"
                        class="border-b"
                    >
                        <td class="py-3 pr-3 font-bold">{{ type.name }}</td>
                        <td class="py-3 pr-3 tabular-nums">
                            {{ type.price_label }}
                        </td>
                        <td class="py-3 pr-3 tabular-nums">
                            {{ type.quantity_sold }} / {{ type.quantity_total }}
                        </td>
                        <td class="py-3 pr-3 tabular-nums">
                            {{ type.min_per_order }}–{{ type.max_per_order }}
                        </td>
                        <td class="py-3 pr-3">
                            <StatusBadge :status="type.status" />
                        </td>
                        <td class="py-3 text-right whitespace-nowrap">
                            <template v-if="canManage">
                                <button
                                    type="button"
                                    class="min-h-10 rounded-lg px-3 font-semibold text-primary hover:bg-accent"
                                    @click="startEdit(type)"
                                >
                                    Edit<span class="sr-only">
                                        {{ type.name }}</span
                                    >
                                </button>
                                <button
                                    v-if="!type.has_sales"
                                    type="button"
                                    class="min-h-10 rounded-lg px-3 font-semibold text-red-700 hover:bg-red-50"
                                    @click="remove(type)"
                                >
                                    Delete<span class="sr-only">
                                        {{ type.name }}</span
                                    >
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p
                v-else-if="editing === undefined"
                class="text-sm text-muted-foreground"
            >
                No ticket types yet. Add at least one before submitting the
                event for review.
            </p>
        </div>
        <InputError :message="errors.ticket_type" />

        <form
            v-if="editing !== undefined"
            class="flex flex-col gap-4 rounded-xl border border-primary/40 bg-background p-5"
            novalidate
            @submit.prevent="save"
        >
            <h3 class="font-extrabold">
                {{
                    editing === null
                        ? 'New ticket type'
                        : `Edit ${editingType?.name}`
                }}
            </h3>

            <p
                v-if="editingType?.has_sales"
                class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900"
            >
                {{ editingType.quantity_sold }} sold — the price is locked and
                capacity can't go below that.
            </p>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="flex flex-col gap-1.5 sm:col-span-2">
                    <label for="tt-name" class="text-sm font-bold">Name</label>
                    <input
                        id="tt-name"
                        v-model="form.name"
                        type="text"
                        maxlength="80"
                        placeholder="e.g. Early Bird, Regular, VIP"
                        :class="inputClass"
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="tt-price" class="text-sm font-bold"
                        >Price ({{ currency }})</label
                    >
                    <input
                        id="tt-price"
                        v-model="form.price"
                        type="text"
                        inputmode="decimal"
                        placeholder="850.00"
                        :disabled="editingType?.has_sales"
                        :class="inputClass"
                    />
                    <InputError :message="errors.price" />
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="tt-description" class="text-sm font-bold"
                    >What's included
                    <span class="font-normal text-muted-foreground"
                        >(optional)</span
                    ></label
                >
                <input
                    id="tt-description"
                    v-model="form.description"
                    type="text"
                    maxlength="500"
                    :class="inputClass"
                />
                <InputError :message="errors.description" />
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <div class="flex flex-col gap-1.5">
                    <label for="tt-qty" class="text-sm font-bold"
                        >Capacity</label
                    >
                    <input
                        id="tt-qty"
                        v-model.number="form.quantity_total"
                        type="number"
                        min="1"
                        :class="inputClass"
                    />
                    <InputError :message="errors.quantity_total" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="tt-min" class="text-sm font-bold"
                        >Min per order</label
                    >
                    <input
                        id="tt-min"
                        v-model.number="form.min_per_order"
                        type="number"
                        min="1"
                        :class="inputClass"
                    />
                    <InputError :message="errors.min_per_order" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="tt-max" class="text-sm font-bold"
                        >Max per order</label
                    >
                    <input
                        id="tt-max"
                        v-model.number="form.max_per_order"
                        type="number"
                        min="1"
                        :class="inputClass"
                    />
                    <InputError :message="errors.max_per_order" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="tt-status" class="text-sm font-bold"
                        >Status</label
                    >
                    <select
                        id="tt-status"
                        v-model="form.status"
                        :class="inputClass"
                    >
                        <option v-for="s in statuses" :key="s" :value="s">
                            {{ statusLabel(s) }}
                        </option>
                    </select>
                    <InputError :message="errors.status" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <label for="tt-sales-start" class="text-sm font-bold"
                        >Sales open
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></label
                    >
                    <DateTimeField
                        id="tt-sales-start"
                        v-model="form.sales_starts_at"
                        :invalid="Boolean(errors.sales_starts_at)"
                    />
                    <InputError :message="errors.sales_starts_at" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="tt-sales-end" class="text-sm font-bold"
                        >Sales close
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></label
                    >
                    <DateTimeField
                        id="tt-sales-end"
                        v-model="form.sales_ends_at"
                        :min="form.sales_starts_at || null"
                        :invalid="Boolean(errors.sales_ends_at)"
                    />
                    <InputError :message="errors.sales_ends_at" />
                </div>
            </div>

            <div class="flex gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="min-h-11 rounded-lg bg-primary px-5 font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
                >
                    {{
                        editing === null
                            ? 'Add ticket type'
                            : 'Save ticket type'
                    }}
                </button>
                <button
                    type="button"
                    class="min-h-11 rounded-lg border px-4 font-semibold hover:bg-accent"
                    @click="close"
                >
                    Cancel
                </button>
            </div>
        </form>
    </section>
</template>
