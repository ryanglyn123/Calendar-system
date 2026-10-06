<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { layoutWeekLanes } from '@/components/calendar/monthLanes';
import { peekClose, peekEnter, peekFocus } from '@/composables/useEventPeek';
import {
    bookingTone,
    chipFill,
    chipIcon,
    chipState,
    isShown,
} from '@/lib/bookingDisplay';
import {
    addDays,
    addMonths,
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
const LANE_HEIGHT = 24; // bar (22, Google's pill height) + 2 gap

// The chip cap is measured, not fixed: show as many timed chips as the cell's
// height fits. Heights in px — tuning knobs if chip/label styling changes.
const CHIP_HEIGHT = 24; // chip (22) + gap-0.5 (2)
const LABEL_HEIGHT = 32; // day-number block
const WEEKDAY_HEIGHT = 20; // weekday label atop the first row's cells (h-5)
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

// Without weekends each row is the five weekdays (they stay contiguous, as
// weekends sit at the ends of the week).
const weeks = computed(() =>
    Array.from({ length: 6 }, (_, w) =>
        days.value
            .slice(w * 7, w * 7 + 7)
            .filter((d) => grid.showWeekends || ![0, 6].includes(d.getDay())),
    ),
);

const visible = computed(() => {
    const start = days.value[0];
    const end = addDays(days.value[41], 1);
    const visibleIds = new Set(list.visibleCalendarIds);
    const listedIds = new Set(list.calendars.map((c) => c.id));

    return grid
        .occurrencesBetween(start, end)
        .filter((o) => isShown(o, visibleIds, listedIds));
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

/** The first row also carries the weekday labels, like Google's grid. */
const weekdayOffset = (w: number) => (w === 0 ? WEEKDAY_HEIGHT : 0);

const maxChips = (laneCount: number, w: number): number => {
    const avail =
        rowHeight.value -
        LABEL_HEIGHT -
        weekdayOffset(w) -
        laneCount * LANE_HEIGHT -
        CELL_PAD;

    return Math.max(1, Math.floor(avail / CHIP_HEIGHT));
};

type ChipPlan = { shown: EventOccurrence[]; hiddenCount: number };

// Per week: how many chips to render per day and the "+N more" remainder,
// reserving the last row for the more-link whenever anything overflows.
const chipPlans = computed<Map<string, ChipPlan>[]>(() =>
    weekLayouts.value.map((week, w) => {
        const budget = maxChips(week.laneCount, w);
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

/** The pill being dragged dims, as in the week view, while its target cell lights. */
const isDragSource = (occurrence: EventOccurrence) =>
    monthDrag.value?.active === true &&
    monthDrag.value.occurrence.occurrence_id === occurrence.occurrence_id;

function teardownDrag(): void {
    monthDrag.value = null;
    dropTargetKey.value = null;
    window.removeEventListener('pointermove', onDragMove);
    window.removeEventListener('pointerup', onDragUp);
    window.removeEventListener('keydown', onDragKeydown);
}

onBeforeUnmount(teardownDrag);

/* ------------------------------------------------------------------ */
/* Wheel pages months, like Google Calendar: one gesture, one month.   */
/* ------------------------------------------------------------------ */

const WHEEL_THRESHOLD = 50; // px of scroll before a gesture counts
const WHEEL_IDLE = 200; // ms of quiet that ends a gesture (eats trackpad inertia)

let wheelSum = 0;
let wheelLocked = false;
let wheelTimer: number | undefined;

// A scrollable ancestor (e.g. the page on a short screen) scrolls first.
function ancestorCanScroll(from: Element | null, dy: number): boolean {
    for (let el = from; el; el = el.parentElement) {
        const scrollable =
            el === document.scrollingElement ||
            /auto|scroll/.test(getComputedStyle(el).overflowY);

        if (
            scrollable &&
            (dy > 0
                ? el.scrollTop + el.clientHeight < el.scrollHeight - 1
                : el.scrollTop > 0)
        ) {
            return true;
        }
    }

    return false;
}

function onWheel(event: WheelEvent): void {
    // Line/page-mode wheels (Firefox) report small numbers; scale to px.
    const dy = event.deltaMode ? event.deltaY * 40 : event.deltaY;

    if (
        // Resource sections (Boardroom, Carpool) keep plain scrolling.
        grid.scope?.resourceTypeId != null ||
        event.ctrlKey ||
        Math.abs(dy) < Math.abs(event.deltaX) ||
        ancestorCanScroll(event.target as Element, dy)
    ) {
        return;
    }

    event.preventDefault();
    window.clearTimeout(wheelTimer);
    wheelTimer = window.setTimeout(() => {
        wheelSum = 0;
        wheelLocked = false;
    }, WHEEL_IDLE);

    if (wheelLocked) {
        return;
    }

    wheelSum += dy;

    if (Math.abs(wheelSum) >= WHEEL_THRESHOLD) {
        wheelLocked = true;
        grid.setFocusDate(addMonths(grid.focusDate, Math.sign(wheelSum)));
    }
}

const onChipClick = (occurrence: EventOccurrence): void => {
    if (suppressNextClick) {
        return;
    }

    emit('open', occurrence);
};
</script>

<template>
    <div
        class="flex h-full flex-col"
        role="grid"
        aria-label="Month view"
        @wheel="onWheel"
    >
        <div ref="gridEl" class="grid flex-1 auto-rows-fr">
            <div
                v-for="(week, w) in weeks"
                :key="w"
                class="month-row relative grid border-b last:border-b-0"
                :style="{
                    gridTemplateColumns: `repeat(${week.length}, minmax(0, 1fr))`,
                }"
                role="row"
            >
                <!-- Spanning lane bars -->
                <button
                    v-for="segment in weekLayouts[w].laneSegments"
                    :key="`${segment.occurrence.occurrence_id}-${segment.startCol}`"
                    type="button"
                    data-event
                    class="ev-pill absolute z-10 flex items-center gap-1 truncate rounded-[6px] px-2 text-left text-xs leading-5 font-medium"
                    :class="[
                        chipFill(segment.occurrence),
                        {
                            'opacity-40': isDragSource(segment.occurrence),
                            'rounded-l-none': segment.continuesBefore,
                            'rounded-r-none': segment.continuesAfter,
                            'line-through opacity-70':
                                segment.occurrence.status === 'cancelled' &&
                                !segment.occurrence.booking,
                        },
                    ]"
                    :style="{
                        left: `calc(${(segment.startCol / week.length) * 100}% + 4px)`,
                        width: `calc(${(segment.span / week.length) * 100}% - 8px)`,
                        top: `${weekdayOffset(w) + 28 + segment.lane * LANE_HEIGHT}px`,
                        height: '22px',
                        '--wash': eventWash(bookingTone(segment.occurrence)),
                        '--ink': eventInk(bookingTone(segment.occurrence)),
                        '--ev': bookingTone(segment.occurrence) ?? '#d4d8dd',
                    }"
                    :aria-label="`${chipState(segment.occurrence)}${segment.occurrence.title}, ${format(new Date(segment.occurrence.starts_at), 'MMM d')}`"
                    @pointerdown="
                        (e) => onChipPointerDown(e, segment.occurrence)
                    "
                    @click="onChipClick(segment.occurrence)"
                    @pointerenter="(e) => peekEnter(e, segment.occurrence)"
                    @pointerleave="peekClose()"
                    @focus="(e) => peekFocus(e, segment.occurrence)"
                    @blur="peekClose()"
                >
                    <component
                        :is="chipIcon(segment.occurrence)"
                        v-if="chipIcon(segment.occurrence)"
                        class="size-3 shrink-0"
                        aria-hidden="true"
                    />
                    <span class="bk-title truncate">{{
                        segment.occurrence.title
                    }}</span>
                </button>

                <!-- Day cells -->
                <div
                    v-for="day in week"
                    :key="day.toISOString()"
                    role="gridcell"
                    class="month-cell flex min-h-28 cursor-pointer flex-col border-r px-1 pb-1 transition-colors last:border-r-0 hover:bg-muted/40"
                    :class="{
                        'is-today': isSameDay(day, today),
                        'is-outside': !isSameMonth(day, grid.focusDate),
                        'is-drop': dropTargetKey === format(day, 'yyyy-MM-dd'),
                        'bg-muted/20 text-muted-foreground': !isSameMonth(
                            day,
                            grid.focusDate,
                        ),
                        'bg-primary/10 ring-2 ring-primary ring-inset':
                            dropTargetKey === format(day, 'yyyy-MM-dd'),
                    }"
                    :data-daycell="format(day, 'yyyy-MM-dd')"
                    :aria-label="format(day, 'EEEE, MMMM d, yyyy')"
                    @click="(e) => onCellClick(day, e)"
                >
                    <div
                        v-if="w === 0"
                        class="flex h-5 items-end justify-center text-[11px] leading-none font-medium text-muted-foreground uppercase"
                        aria-hidden="true"
                    >
                        {{ format(day, 'EEE') }}
                    </div>
                    <div class="month-daynum flex justify-center py-1">
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

                    <div
                        class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-hidden"
                    >
                        <button
                            v-for="occurrence in chipPlans[w].get(
                                format(day, 'yyyy-MM-dd'),
                            )?.shown ?? []"
                            :key="occurrence.occurrence_id"
                            type="button"
                            data-event
                            class="ev-chip ev-pill relative flex h-[22px] shrink-0 items-center gap-1.5 truncate rounded-[6px] px-1.5 text-left text-xs"
                            :class="[
                                chipFill(occurrence),
                                {
                                    'opacity-40': isDragSource(occurrence),
                                    'line-through opacity-70':
                                        occurrence.status === 'cancelled' &&
                                        !occurrence.booking,
                                },
                            ]"
                            :style="{
                                '--wash': eventWash(bookingTone(occurrence)),
                                '--ink': eventInk(bookingTone(occurrence)),
                                '--ev': bookingTone(occurrence) ?? '#d4d8dd',
                            }"
                            :aria-label="`${chipState(occurrence)}${occurrence.title}, ${format(new Date(occurrence.starts_at), 'h:mm a')}`"
                            @pointerdown="
                                (e) => onChipPointerDown(e, occurrence)
                            "
                            @click="onChipClick(occurrence)"
                            @pointerenter="(e) => peekEnter(e, occurrence)"
                            @pointerleave="peekClose()"
                            @focus="(e) => peekFocus(e, occurrence)"
                            @blur="peekClose()"
                        >
                            <span
                                data-peek-lead
                                class="shrink-0 text-[10px] opacity-75"
                            >
                                {{
                                    format(
                                        new Date(occurrence.starts_at),
                                        'HH:mm',
                                    )
                                }}
                            </span>
                            <component
                                :is="chipIcon(occurrence)"
                                v-if="chipIcon(occurrence)"
                                class="size-3 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="bk-title truncate font-medium">
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
