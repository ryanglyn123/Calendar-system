<script setup lang="ts">
import { computed } from 'vue';
import {
    addMonths,
    format,
    isSameDay,
    isSameMonth,
    monthGridDays,
} from '@/lib/calendarMath';
import type { WeekStart } from '@/lib/calendarMath';

export type HolidayKind = 'regular' | 'special_non_working' | 'company';

export type HolidayRow = {
    id: number;
    name: string;
    date: string;
    recurs_annually: boolean;
    type: HolidayKind;
    blocks_bookings: boolean;
};

/** A holiday placed on a day of the selected year (yearly ones projected). */
export type HolidayOccurrence = { iso: string; holiday: HolidayRow };

const props = defineProps<{
    year: number;
    weekStartsOn: WeekStart;
    occurrences: HolidayOccurrence[];
    labels: Record<HolidayKind, string>;
}>();

const emit = defineEmits<{
    edit: [holiday: HolidayRow];
    create: [date: string];
}>();

/**
 * Kinds differ by shape and weight, not hue: a filled ink disc, an ink
 * ring, a muted disc. Readable in grayscale and in both themes.
 */
const marks: Record<HolidayKind, string> = {
    regular: 'bg-foreground font-semibold text-background',
    special_non_working: 'border-[1.5px] border-foreground font-semibold',
    company: 'bg-muted-foreground/25 font-semibold',
};

const today = new Date();

const months = computed(() => {
    const byDay = new Map<string, HolidayRow[]>();

    for (const { iso, holiday } of props.occurrences) {
        byDay.set(iso, [...(byDay.get(iso) ?? []), holiday]);
    }

    const january = new Date(props.year, 0, 1);

    return Array.from({ length: 12 }, (_, i) => {
        const anchor = addMonths(january, i);
        const days = monthGridDays(anchor, props.weekStartsOn);

        return {
            anchor,
            // The sixth row is empty for most months; drop it.
            cells: days
                .slice(0, isSameMonth(days[35], anchor) ? 42 : 35)
                .map((day) => {
                    const iso = format(day, 'yyyy-MM-dd');
                    const holidays = byDay.get(iso) ?? [];

                    return {
                        day,
                        iso,
                        inMonth: isSameMonth(day, anchor),
                        holidays,
                        names: holidays.map((h) => h.name).join(', '),
                        weekend: [0, 6].includes(day.getDay()),
                        today: isSameDay(day, today),
                    };
                }),
        };
    });
});

const weekdayLabels = computed(() =>
    months.value[0].cells.slice(0, 7).map((c) => format(c.day, 'EEEEE')),
);

type Cell = (typeof months.value)[number]['cells'][number];

// ponytail: a day with two holidays opens the first; the list view reaches both.
const open = (cell: Cell) =>
    cell.holidays.length
        ? emit('edit', cell.holidays[0])
        : emit('create', cell.iso);
</script>

<template>
    <div class="space-y-5">
        <ul
            class="flex flex-wrap gap-x-4 gap-y-1 text-xs font-medium text-muted-foreground"
            aria-label="Legend"
        >
            <li
                v-for="(label, kind) in labels"
                :key="kind"
                class="flex items-center gap-1.5"
            >
                <span
                    class="size-3 rounded-full"
                    :class="marks[kind]"
                    aria-hidden="true"
                />
                {{ label }}
            </li>
        </ul>

        <div
            class="grid grid-cols-2 gap-6 select-none md:grid-cols-3 xl:grid-cols-4"
        >
            <section v-for="month in months" :key="month.anchor.getMonth()">
                <h3 class="mb-1 px-1 text-[13px] font-semibold">
                    {{ format(month.anchor, 'MMMM') }}
                </h3>

                <div
                    class="grid grid-cols-7 gap-y-0.5 text-center text-xs tabular-nums"
                >
                    <span
                        v-for="(label, i) in weekdayLabels"
                        :key="i"
                        class="py-1 text-[11px] text-muted-foreground"
                        aria-hidden="true"
                    >
                        {{ label }}
                    </span>

                    <template v-for="cell in month.cells" :key="cell.iso">
                        <span v-if="!cell.inMonth" aria-hidden="true" />
                        <!-- Empty days are a mouse shortcut to "Add holiday"; the
                             header button serves keyboards without 300+ tab stops. -->
                        <button
                            v-else
                            type="button"
                            class="relative mx-auto flex aspect-square w-full max-w-7 items-center justify-center rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring/60 motion-safe:transition-[color,background-color,opacity] motion-safe:duration-150"
                            :class="
                                cell.holidays.length
                                    ? [
                                          marks[cell.holidays[0].type],
                                          'hover:opacity-80',
                                      ]
                                    : [
                                          'hover:bg-muted',
                                          cell.weekend &&
                                              'text-muted-foreground',
                                      ]
                            "
                            :tabindex="cell.holidays.length ? undefined : -1"
                            :title="cell.names || undefined"
                            :aria-label="
                                cell.holidays.length
                                    ? `${format(cell.day, 'EEEE, MMMM d')}: ${cell.names}`
                                    : `Add a holiday on ${format(cell.day, 'EEEE, MMMM d, yyyy')}`
                            "
                            :aria-current="cell.today ? 'date' : undefined"
                            :data-today="cell.today || undefined"
                            @click="open(cell)"
                        >
                            {{ format(cell.day, 'd') }}
                        </button>
                    </template>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
/* Today: a small dot under the date, in the day's own ink. */
[data-today]::after {
    content: '';
    position: absolute;
    bottom: 2px;
    left: 50%;
    width: 3px;
    height: 3px;
    border-radius: 9999px;
    background: currentColor;
    transform: translateX(-50%);
}
</style>
