<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { layoutWeekLanes } from '@/components/calendar/monthLanes';
import {
    addDays,
    eventInk,
    eventWash,
    format,
    isMultiDay,
    isSameDay,
    isSameMonth,
    monthGridDays,
    occurrenceTouchesDay,
    parseISO,
    startOfDay,
} from '@/lib/calendarMath';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import { useCalendarListStore } from '@/stores/calendarList';
import type { EventOccurrence } from '@/types/calendar';

const MAX_LANES = 2;
const LANE_HEIGHT = 24;

// The chip cap is measured, not fixed: show as many timed chips as the cell's
// height fits. Heights in px — tuning knobs if chip/label styling changes.
const CHIP_HEIGHT = 22; // chip (20) + gap-0.5 (2)
const LABEL_HEIGHT = 32; // day-number block
const CELL_PAD = 4; // pb-1
const MIN_ROW_HEIGHT = 112; // min-h-28 floor — the pre-measure seed

const emit = defineEmits<{
    createAt: [date: Date];
    open: [occurrence: EventOccurrence];
    showDay: [date: Date, occurrences: EventOccurrence[]];
    moveRecurring: [occurrence: EventOccurrence, start: Date, end: Date];
}>();

const grid = useCalendarGridStore();
const list = useCalendarListStore();

const days = computed(() => monthGridDays(grid.focusDate, grid.weekStartsOn));

const weeks = computed(() =>
    Array.from({ length: 6 }, (_, w) => days.value.slice(w * 7, w * 7 + 7)),
);

const visible = computed(() => {
    const start = days.value[0];
    const end = addDays(days.value[41], 1);
    const visibleIds = new Set(list.visibleCalendarIds);

    return grid
        .occurrencesBetween(start, end)
        .filter((o) => visibleIds.has(o.calendar_id));
});

type WeekLayout = {
    laneSegments: ReturnType<typeof layoutWeekLanes>;
    laneCount: number;
    hiddenLaneOccurrences: Map<string, EventOccurrence[]>;
    chipsByDay: Map<string, EventOccurrence[]>;
};

const dayKey = (day: Date) => format(day, 'yyyy-MM-dd');

const weekLayouts = computed<WeekLayout[]>(() =>
    weeks.value.map((weekDaysArr) => {
        const laneItems = visible.value.filter((o) => {
            const start = new Date(o.starts_at);
            const end = new Date(o.ends_at);

            return o.all_day || isMultiDay(start, end);
        });

        const timedItems = visible.value.filter((o) => {
            const start = new Date(o.starts_at);
            const end = new Date(o.ends_at);

            return !(o.all_day || isMultiDay(start, end));
        });

        const allSegments = layoutWeekLanes(laneItems, weekDaysArr);
        const laneSegments = allSegments.filter((s) => s.lane < MAX_LANES);
        const overflow = allSegments.filter((s) => s.lane >= MAX_LANES);

        const hiddenLaneOccurrences = new Map<string, EventOccurrence[]>();

        for (const segment of overflow) {
            for (let i = 0; i < segment.span; i++) {
                const key = dayKey(weekDaysArr[segment.startCol + i]);
                const bucket = hiddenLaneOccurrences.get(key) ?? [];
                bucket.push(segment.occurrence);
                hiddenLaneOccurrences.set(key, bucket);
            }
        }

        const chipsByDay = new Map<string, EventOccurrence[]>();

        for (const day of weekDaysArr) {
            chipsByDay.set(
                dayKey(day),
                timedItems.filter((o) =>
                    occurrenceTouchesDay(
                        new Date(o.starts_at),
                        new Date(o.ends_at),
                        day,
                    ),
                ),
            );
        }

        const laneCount = Math.min(
            MAX_LANES,
            allSegments.reduce((max, s) => Math.max(max, s.lane + 1), 0),
        );

        return { laneSegments, laneCount, hiddenLaneOccurrences, chipsByDay };
    }),
);

const today = new Date();

const dayOccurrences = (day: Date): EventOccurrence[] =>
    visible.value.filter((o) =>
        occurrenceTouchesDay(new Date(o.starts_at), new Date(o.ends_at), day),
    );

// All six week-rows share one height (auto-rows-fr); measure it once and let
// each cell fit as many chips as its remaining space allows. Seed with the
// min-h-28 floor so pre-measure rows never overflow the page (a taller seed
// would balloon rows, add a scrollbar, then shift the layout when it settles).
const gridEl = ref<HTMLElement | null>(null);
const rowHeight = ref(MIN_ROW_HEIGHT);
// The server can't measure the viewport, so it can't know the real chip budget.
// Until the client measures, we cap chips (to avoid overflow) but never render
// "+N more" — a guessed one flashes on refresh on days that actually fit.
const measured = ref(false);
let rowObserver: ResizeObserver | undefined;

onMounted(() => {
    if (!gridEl.value) {
        return;
    }

    const measure = () => {
        // Clamp to the min-h-28 floor: a not-yet-laid-out or mid-transition
        // read can report a tiny height, which would drop the chip budget and
        // flash "+N more" on days that actually fit. Rows never shrink below
        // the floor (CSS min-height), so a smaller measurement is never real.
        rowHeight.value = Math.max(
            MIN_ROW_HEIGHT,
            (gridEl.value?.clientHeight ?? 0) / 6,
        );
    };

    measure(); // sync, before paint — avoids a wrong "+more" on the first frame
    measured.value = true;
    rowObserver = new ResizeObserver(measure);
    rowObserver.observe(gridEl.value);
});

onBeforeUnmount(() => rowObserver?.disconnect());

const maxChips = (laneCount: number): number => {
    const avail =
        rowHeight.value - LABEL_HEIGHT - laneCount * LANE_HEIGHT - CELL_PAD;

    return Math.max(1, Math.floor(avail / CHIP_HEIGHT));
};

type ChipPlan = { shown: EventOccurrence[]; hiddenCount: number };

// Per week: how many chips to render per day and the "+N more" remainder,
// reserving the last row for the more-link whenever anything overflows.
const chipPlans = computed<Map<string, ChipPlan>[]>(() =>
    weekLayouts.value.map((week) => {
        const budget = maxChips(week.laneCount);
        const plans = new Map<string, ChipPlan>();

        for (const [key, chips] of week.chipsByDay) {
            const hiddenLanes =
                week.hiddenLaneOccurrences.get(key)?.length ?? 0;

            // Pre-measure: fill up to the (conservative) budget, no "+more".
            if (!measured.value) {
                plans.set(key, {
                    shown: chips.slice(0, budget),
                    hiddenCount: 0,
                });
                continue;
            }

            if (chips.length <= budget && hiddenLanes === 0) {
                plans.set(key, { shown: chips, hiddenCount: 0 });
                continue;
            }

            const shown = chips.slice(0, Math.max(0, budget - 1));
            plans.set(key, {
                shown,
                hiddenCount: chips.length - shown.length + hiddenLanes,
            });
        }

        return plans;
    }),
);

const weekdayHeaders = computed(() =>
    days.value.slice(0, 7).map((d) => format(d, 'EEE')),
);

const onCellClick = (day: Date, event: MouseEvent) => {
    if (
        suppressNextClick ||
        (event.target as HTMLElement).closest('[data-event]')
    ) {
        return;
    }

    const slot = startOfDay(day);
    slot.setHours(9);

    emit('createAt', slot);
};

/* ------------------------------------------------------------------ */
/* Drag an event chip / bar onto another day (time of day preserved).  */
/* ------------------------------------------------------------------ */

type MonthDrag = {
    occurrence: EventOccurrence;
    originX: number;
    originY: number;
    active: boolean;
};

const monthDrag = ref<MonthDrag | null>(null);
const dropTargetKey = ref<string | null>(null);
let suppressNextClick = false;

function onChipPointerDown(
    event: PointerEvent,
    occurrence: EventOccurrence,
): void {
    if (event.button !== 0 || !occurrence.can_edit) {
        return;
    }

    monthDrag.value = {
        occurrence,
        originX: event.clientX,
        originY: event.clientY,
        active: false,
    };

    window.addEventListener('pointermove', onDragMove);
    window.addEventListener('pointerup', onDragUp);
    window.addEventListener('keydown', onDragKeydown);
}

function onDragMove(event: PointerEvent): void {
    const state = monthDrag.value;

    if (!state) {
        return;
    }

    if (!state.active) {
        const distance = Math.hypot(
            event.clientX - state.originX,
            event.clientY - state.originY,
        );

        if (distance < 4) {
            return;
        }

        state.active = true;
    }

    event.preventDefault();

    const cell = document
        .elementFromPoint(event.clientX, event.clientY)
        ?.closest('[data-daycell]');

    dropTargetKey.value = cell?.getAttribute('data-daycell') ?? null;
}

async function onDragUp(): Promise<void> {
    const state = monthDrag.value;
    const target = dropTargetKey.value;

    teardownDrag();

    if (!state?.active) {
        return;
    }

    suppressNextClick = true;
    setTimeout(() => (suppressNextClick = false), 0);

    if (!target) {
        return;
    }

    const start = new Date(state.occurrence.starts_at);
    const targetDay = startOfDay(parseISO(`${target}T00:00:00`));
    const deltaMs = targetDay.getTime() - startOfDay(start).getTime();

    if (deltaMs === 0) {
        return;
    }

    const end = new Date(state.occurrence.ends_at);
    const newStart = new Date(start.getTime() + deltaMs);
    const newEnd = new Date(end.getTime() + deltaMs);

    if (state.occurrence.is_recurring) {
        emit('moveRecurring', state.occurrence, newStart, newEnd);

        return;
    }

    try {
        await grid.moveEvent(state.occurrence, newStart, newEnd);
    } catch {
        toast.error('Could not reschedule the event.');
    }
}

function onDragKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        teardownDrag();
    }
}

function teardownDrag(): void {
    monthDrag.value = null;
    dropTargetKey.value = null;
    window.removeEventListener('pointermove', onDragMove);
    window.removeEventListener('pointerup', onDragUp);
    window.removeEventListener('keydown', onDragKeydown);
}

onBeforeUnmount(teardownDrag);

const onChipClick = (occurrence: EventOccurrence): void => {
    if (suppressNextClick) {
        return;
    }

    emit('open', occurrence);
};
</script>

<template>
    <div class="flex h-full flex-col" role="grid" aria-label="Month view">
        <div
            class="grid grid-cols-7 border-b text-center text-xs font-medium text-muted-foreground"
            role="row"
        >
            <div
                v-for="label in weekdayHeaders"
                :key="label"
                class="py-1.5"
                role="columnheader"
            >
                {{ label }}
            </div>
        </div>

        <div ref="gridEl" class="grid flex-1 auto-rows-fr">
            <div
                v-for="(week, w) in weeks"
                :key="w"
                class="relative grid grid-cols-7 border-b last:border-b-0"
                role="row"
            >
                <!-- Spanning lane bars -->
                <button
                    v-for="segment in weekLayouts[w].laneSegments"
                    :key="`${segment.occurrence.occurrence_id}-${segment.startCol}`"
                    type="button"
                    data-event
                    class="absolute z-10 truncate rounded-md px-2 text-left text-xs leading-5 font-medium transition-opacity hover:opacity-90"
                    :class="{
                        'rounded-l-none': segment.continuesBefore,
                        'rounded-r-none': segment.continuesAfter,
                        'line-through opacity-70':
                            segment.occurrence.status === 'cancelled',
                    }"
                    :style="{
                        left: `calc(${(segment.startCol / 7) * 100}% + 4px)`,
                        width: `calc(${(segment.span / 7) * 100}% - 8px)`,
                        top: `${28 + segment.lane * LANE_HEIGHT}px`,
                        height: '20px',
                        background: eventWash(segment.occurrence.color),
                        color: eventInk(segment.occurrence.color),
                    }"
                    :aria-label="`${segment.occurrence.title}, ${format(new Date(segment.occurrence.starts_at), 'MMM d')}`"
                    @pointerdown="
                        (e) => onChipPointerDown(e, segment.occurrence)
                    "
                    @click="onChipClick(segment.occurrence)"
                >
                    {{ segment.occurrence.title }}
                </button>

                <!-- Day cells -->
                <div
                    v-for="day in week"
                    :key="day.toISOString()"
                    role="gridcell"
                    class="flex min-h-28 cursor-pointer flex-col border-r px-1 pb-1 transition-colors last:border-r-0 hover:bg-muted/40"
                    :class="{
                        'bg-muted/20 text-muted-foreground': !isSameMonth(
                            day,
                            grid.focusDate,
                        ),
                        'bg-primary/10 ring-2 ring-primary ring-inset':
                            dropTargetKey === format(day, 'yyyy-MM-dd'),
                    }"
                    :data-daycell="format(day, 'yyyy-MM-dd')"
                    :aria-label="format(day, 'MMMM d, yyyy')"
                    @click="(e) => onCellClick(day, e)"
                >
                    <div class="flex justify-center py-1">
                        <span
                            class="flex size-6 items-center justify-center rounded-full text-xs"
                            :class="
                                isSameDay(day, today)
                                    ? 'bg-gradient-accent font-semibold'
                                    : ''
                            "
                        >
                            {{ format(day, 'd') }}
                        </span>
                    </div>

                    <!-- Reserved space for lane bars -->
                    <div
                        :style="{
                            height: `${weekLayouts[w].laneCount * LANE_HEIGHT}px`,
                        }"
                        aria-hidden="true"
                    />

                    <div class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-hidden">
                        <button
                            v-for="occurrence in chipPlans[w].get(
                                format(day, 'yyyy-MM-dd'),
                            )?.shown ?? []"
                            :key="occurrence.occurrence_id"
                            type="button"
                            data-event
                            class="flex items-center gap-1.5 truncate rounded-md px-1.5 py-0.5 text-left text-xs transition-opacity hover:opacity-90"
                            :class="{
                                'line-through opacity-70':
                                    occurrence.status === 'cancelled',
                            }"
                            :style="{
                                background: eventWash(occurrence.color),
                                color: eventInk(occurrence.color),
                            }"
                            :aria-label="`${occurrence.title}, ${format(new Date(occurrence.starts_at), 'h:mm a')}`"
                            @pointerdown="
                                (e) => onChipPointerDown(e, occurrence)
                            "
                            @click="onChipClick(occurrence)"
                        >
                            <span class="shrink-0 text-[10px] opacity-75">
                                {{
                                    format(
                                        new Date(occurrence.starts_at),
                                        'HH:mm',
                                    )
                                }}
                            </span>
                            <span class="truncate font-medium">
                                {{ occurrence.title }}
                            </span>
                        </button>

                        <button
                            v-if="
                                (chipPlans[w].get(format(day, 'yyyy-MM-dd'))
                                    ?.hiddenCount ?? 0) > 0
                            "
                            type="button"
                            data-event
                            class="shrink-0 rounded px-1 py-0.5 text-left text-xs font-medium text-muted-foreground hover:bg-muted"
                            @click="emit('showDay', day, dayOccurrences(day))"
                        >
                            +{{
                                chipPlans[w].get(format(day, 'yyyy-MM-dd'))
                                    ?.hiddenCount
                            }}
                            more
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
