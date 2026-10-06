<script setup lang="ts">
import { computed } from 'vue';
import {
    addMonths,
    format,
    isSameDay,
    isSameMonth,
    monthGridDays,
} from '@/lib/calendarMath';
import { useCalendarGridStore } from '@/stores/calendarGrid';

const grid = useCalendarGridStore();

const today = new Date();

const months = computed(() => {
    const january = new Date(grid.focusDate.getFullYear(), 0, 1);

    return Array.from({ length: 12 }, (_, i) => {
        const anchor = addMonths(january, i);
        const days = monthGridDays(anchor, grid.weekStartsOn);

        return {
            anchor,
            days: days.filter(
                (d) => grid.showWeekends || ![0, 6].includes(d.getDay()),
            ),
            // The sixth row is empty for most months; drop it.
            rows: isSameMonth(days[35], anchor) ? 6 : 5,
        };
    });
});

const weekdayLabels = computed(() =>
    months.value[0].days
        .slice(0, grid.showWeekends ? 7 : 5)
        .map((d) => format(d, 'EEEEE')),
);

const columns = computed(() => (grid.showWeekends ? 7 : 5));

const openMonth = (anchor: Date) => {
    grid.setFocusDate(anchor);
    grid.setView('month');
};

const openDay = (day: Date) => {
    grid.setFocusDate(new Date(day));
    grid.setView('day');
};
</script>

<template>
    <div
        class="grid h-full grid-cols-2 content-start gap-x-6 gap-y-5 overflow-y-auto p-4 select-none sm:grid-cols-3 xl:grid-cols-4"
        role="grid"
        aria-label="Year view"
    >
        <section v-for="month in months" :key="month.anchor.toISOString()">
            <button
                type="button"
                class="mb-1 rounded px-1 text-sm font-semibold hover:bg-muted"
                @click="openMonth(month.anchor)"
            >
                {{ format(month.anchor, 'MMMM') }}
            </button>

            <div
                class="grid gap-y-0.5 text-center text-xs"
                :style="{
                    gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`,
                }"
            >
                <span
                    v-for="(label, i) in weekdayLabels"
                    :key="i"
                    class="py-1 text-[11px] font-medium text-muted-foreground"
                >
                    {{ label }}
                </span>

                <template
                    v-for="day in month.days.slice(0, month.rows * columns)"
                    :key="day.toISOString()"
                >
                    <button
                        v-if="isSameMonth(day, month.anchor)"
                        type="button"
                        class="mx-auto flex size-7 items-center justify-center rounded-full hover:bg-muted"
                        :class="
                            isSameDay(day, today)
                                ? 'bg-gradient-accent font-semibold hover:bg-primary'
                                : ''
                        "
                        :aria-label="format(day, 'EEEE, MMMM d, yyyy')"
                        @click="openDay(day)"
                    >
                        {{ format(day, 'd') }}
                    </button>
                    <span v-else aria-hidden="true" />
                </template>
            </div>
        </section>
    </div>
</template>
