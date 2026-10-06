<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    addDays,
    addMonths,
    format,
    isSameDay,
    isSameMonth,
    monthGridDays,
    startOfDay,
    weekDays,
} from '@/lib/calendarMath';
import { useCalendarGridStore } from '@/stores/calendarGrid';

/** A day worth a dot: something booked, or a request waiting on the viewer. */
export type DayMark = 'booked' | 'waiting';

const props = defineProps<{
    /** Booking calendars mark their busy days; others pass nothing. */
    mark?: (day: Date) => DayMark | null;
}>();

const grid = useCalendarGridStore();

const anchor = ref(new Date(grid.focusDate));

watch(
    () => grid.focusDate,
    (next) => {
        anchor.value = new Date(next);
    },
);

// Marks need the month's bookings; browsing ahead loads them like the grid does.
watch(anchor, (month) => {
    if (props.mark) {
        void grid.ensureWindowsAround(month).catch(() => {});
    }
});

const days = computed(() => monthGridDays(anchor.value, grid.weekStartsOn));

const weekdayLabels = computed(() =>
    days.value.slice(0, 7).map((d) => format(d, 'EEEEE')),
);

const today = new Date();

/** The days the main calendar shows, drawn as a band (day and week views). */
const period = computed(() => {
    const focus = startOfDay(grid.focusDate);

    if (grid.view === 'week') {
        const week = weekDays(focus, grid.weekStartsOn);

        return { start: week[0], end: week[6] };
    }

    if (grid.view === 'days4') {
        return { start: focus, end: addDays(focus, 3) };
    }

    return { start: focus, end: focus };
});

const inPeriod = (d: Date) => d >= period.value.start && d <= period.value.end;

const describe = (d: Date) => {
    const mark = props.mark?.(d);

    return [
        format(d, 'EEEE, MMMM d, yyyy'),
        isSameDay(d, today) ? 'today' : null,
        mark === 'waiting'
            ? 'requests waiting'
            : mark === 'booked'
              ? 'has bookings'
              : null,
    ]
        .filter(Boolean)
        .join(', ');
};
</script>

<template>
    <div class="select-none">
        <div class="flex items-center justify-between px-1 pb-2">
            <span class="text-sm font-semibold" aria-live="polite">
                {{ format(anchor, 'MMMM yyyy') }}
            </span>
            <span class="flex">
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-6"
                    aria-label="Previous month"
                    @click="anchor = addMonths(anchor, -1)"
                >
                    <ChevronLeft class="size-4" aria-hidden="true" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-6"
                    aria-label="Next month"
                    @click="anchor = addMonths(anchor, 1)"
                >
                    <ChevronRight class="size-4" aria-hidden="true" />
                </Button>
            </span>
        </div>

        <div class="grid grid-cols-7 gap-y-0.5 text-center">
            <span
                v-for="(label, i) in weekdayLabels"
                :key="i"
                class="pb-1 text-[10px] font-medium text-muted-foreground"
                aria-hidden="true"
            >
                {{ label }}
            </span>

            <!-- The band runs edge to edge behind the period's days, with
                 round ends on its first and last day. -->
            <div
                v-for="day in days"
                :key="day.toISOString()"
                class="flex justify-center"
                :class="
                    inPeriod(day) && grid.view !== 'month'
                        ? [
                              'bg-muted',
                              isSameDay(day, period.start) ||
                              day.getDay() === grid.weekStartsOn
                                  ? 'rounded-l-full'
                                  : '',
                              isSameDay(day, period.end) ||
                              addDays(day, 1).getDay() === grid.weekStartsOn
                                  ? 'rounded-r-full'
                                  : '',
                          ]
                        : ''
                "
            >
                <button
                    type="button"
                    class="relative flex size-7 items-center justify-center rounded-full text-xs tabular-nums transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/60"
                    :class="
                        isSameDay(day, today)
                            ? 'bg-gradient-accent font-semibold'
                            : isSameDay(day, grid.focusDate)
                              ? 'bg-foreground/15 font-semibold'
                              : [
                                    'hover:bg-foreground/10',
                                    isSameMonth(day, anchor)
                                        ? ''
                                        : 'text-muted-foreground/50',
                                ]
                    "
                    :aria-label="describe(day)"
                    :aria-pressed="isSameDay(day, grid.focusDate)"
                    :aria-current="isSameDay(day, today) ? 'date' : undefined"
                    @click="grid.setFocusDate(new Date(day))"
                >
                    {{ format(day, 'd') }}
                    <span
                        v-if="mark?.(day)"
                        class="absolute bottom-0.5 left-1/2 size-1 -translate-x-1/2 rounded-full"
                        :class="
                            mark(day) === 'waiting'
                                ? 'bg-warning'
                                : isSameDay(day, today)
                                  ? 'bg-primary-foreground/70'
                                  : 'bg-foreground/40'
                        "
                        aria-hidden="true"
                    />
                </button>
            </div>
        </div>
    </div>
</template>
