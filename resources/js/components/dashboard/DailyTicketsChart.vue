<script setup lang="ts">
import { computed, ref } from 'vue';

export type DailyPoint = {
    date: string;
    label: string;
    tickets: number;
    orders: number;
};

/**
 * Tickets sold per day as columns. One series, so no legend: the heading
 * names it. Hover (or focus) a day for its figures; the peak day is labelled
 * directly, and the full data is available as a table.
 */
const props = defineProps<{
    title: string;
    subtitle?: string;
    points: DailyPoint[];
}>();

const active = ref<number | null>(null);

const total = computed(() =>
    props.points.reduce((sum, p) => sum + p.tickets, 0),
);

// Round the axis up to a clean number: 1, 2 or 5 × 10^n, in 4 steps.
const axisMax = computed(() => {
    const max = Math.max(...props.points.map((p) => p.tickets), 0);

    if (max === 0) {
        return 4;
    }

    const rough = max / 4;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step =
        [1, 2, 5, 10].map((m) => m * magnitude).find((s) => s >= rough) ??
        rough;

    return step * 4;
});

const ticks = computed(() =>
    [4, 3, 2, 1, 0].map((i) => (axisMax.value / 4) * i),
);

const peakIndex = computed(() => {
    let best = -1;
    props.points.forEach((p, i) => {
        if (
            p.tickets > 0 &&
            (best < 0 || p.tickets > props.points[best].tickets)
        ) {
            best = i;
        }
    });
    return best;
});

function compact(n: number): string {
    return n >= 1000
        ? `${(n / 1000).toFixed(n % 1000 === 0 ? 0 : 1)}K`
        : String(n);
}

function height(n: number): string {
    return `${(n / axisMax.value) * 100}%`;
}

const showTable = ref(false);
</script>

<template>
    <section class="flex flex-col gap-4 rounded-2xl border bg-card p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h2 class="text-lg font-extrabold">{{ title }}</h2>
                <p v-if="subtitle" class="text-sm text-muted-foreground">
                    {{ subtitle }}
                </p>
            </div>
            <span class="text-sm text-muted-foreground">
                <strong class="text-base text-foreground tabular-nums">{{
                    total.toLocaleString()
                }}</strong>
                tickets in {{ points.length }} days
            </span>
        </div>

        <div
            v-if="total === 0"
            class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            No tickets sold in this period yet.
        </div>

        <div v-else class="flex gap-2" aria-hidden="true">
            <!-- y-axis labels -->
            <div
                class="flex h-48 shrink-0 flex-col justify-between text-right text-xs text-muted-foreground tabular-nums"
            >
                <span v-for="t in ticks" :key="t" class="-my-2 leading-4">{{
                    compact(t)
                }}</span>
            </div>

            <div class="relative h-48 min-w-0 flex-1">
                <!-- hairline gridlines -->
                <div
                    class="pointer-events-none absolute inset-0 flex flex-col justify-between"
                >
                    <span
                        v-for="t in ticks"
                        :key="t"
                        class="block h-px w-full bg-border"
                    />
                </div>

                <!-- columns; each slot is the full-height hover target -->
                <div class="absolute inset-0 flex items-end gap-[2px]">
                    <button
                        v-for="(p, i) in points"
                        :key="p.date"
                        type="button"
                        tabindex="-1"
                        class="relative flex h-full min-w-0 flex-1 cursor-default items-end justify-center outline-none"
                        @mouseenter="active = i"
                        @mouseleave="active = null"
                        @focus="active = i"
                        @blur="active = null"
                    >
                        <span
                            class="block w-full max-w-6 rounded-t-[4px] transition-opacity"
                            :class="[
                                'bg-[#6D4AFF] dark:bg-[#8466FF]',
                                active !== null && active !== i
                                    ? 'opacity-40'
                                    : '',
                            ]"
                            :style="{
                                height: height(p.tickets),
                                minHeight: p.tickets > 0 ? '2px' : '0',
                            }"
                        />
                        <span
                            v-if="i === peakIndex && active === null"
                            class="absolute left-1/2 -translate-x-1/2 text-xs font-bold whitespace-nowrap text-foreground tabular-nums"
                            :style="{
                                bottom: `calc(${height(p.tickets)} + 4px)`,
                            }"
                            >{{ p.tickets }}</span
                        >
                    </button>
                </div>

                <!-- tooltip -->
                <div
                    v-if="active !== null"
                    class="pointer-events-none absolute top-1 z-10 -translate-x-1/2 rounded-lg border bg-popover px-3 py-2 text-xs whitespace-nowrap text-popover-foreground shadow-lg"
                    :style="{
                        // Keep the tooltip inside the plot near the edges.
                        left: `${Math.min(88, Math.max(12, ((active + 0.5) / points.length) * 100))}%`,
                    }"
                >
                    <div class="font-bold">{{ points[active].label }}</div>
                    <div class="tabular-nums">
                        {{ points[active].tickets }} tickets ·
                        {{ points[active].orders }} orders
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="total > 0"
            class="ml-10 flex justify-between text-xs text-muted-foreground"
            aria-hidden="true"
        >
            <span>{{ points[0]?.label }}</span>
            <span class="hidden sm:inline">{{
                points[Math.floor(points.length / 2)]?.label
            }}</span>
            <span>{{ points[points.length - 1]?.label }}</span>
        </div>

        <div>
            <button
                type="button"
                class="text-sm font-bold text-primary hover:underline"
                :aria-expanded="showTable"
                @click="showTable = !showTable"
            >
                {{ showTable ? 'Hide table' : 'Show as table' }}
            </button>
            <div
                v-if="showTable"
                class="mt-3 max-h-72 overflow-auto rounded-xl border"
            >
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        {{
                            title
                        }}
                    </caption>
                    <thead class="sticky top-0 bg-muted text-xs uppercase">
                        <tr>
                            <th scope="col" class="p-2">Day</th>
                            <th scope="col" class="p-2 text-right">Tickets</th>
                            <th scope="col" class="p-2 text-right">Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in [...points].reverse()"
                            :key="p.date"
                            class="border-t"
                        >
                            <td class="p-2">{{ p.label }}</td>
                            <td class="p-2 text-right tabular-nums">
                                {{ p.tickets }}
                            </td>
                            <td class="p-2 text-right tabular-nums">
                                {{ p.orders }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</template>
