<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
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
    eventInk,
    eventWash,
    format,
    isMultiDay,
    isSameDay,
    minutesIntoDay,
    occurrenceTouchesDay,
    startOfDay,
    weekDays,
} from '@/lib/calendarMath';
import { layoutDayColumn } from '@/lib/eventLayout';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import { useCalendarListStore } from '@/stores/calendarList';
import type { EventOccurrence } from '@/types/calendar';

const HOUR_HEIGHT = 48;
const SNAP_MINUTES = 30;
const DRAG_THRESHOLD_PX = 4;
const TOUCH_HOLD_MS = 350;
const MIN_EVENT_MINUTES = 30;
/** Narrower than this, a side-by-side event is a sliver nobody can read or hit. */
const MIN_COLUMN_PX = 40;

const props = defineProps<{
    mode: 'week' | 'day' | 'days4';
}>();

const emit = defineEmits<{
    createRange: [start: Date, end: Date];
    open: [occurrence: EventOccurrence];
    moveRecurring: [occurrence: EventOccurrence, start: Date, end: Date];
    showDay: [date: Date, occurrences: EventOccurrence[]];
}>();

const grid = useCalendarGridStore();
const list = useCalendarListStore();

const days = computed(() => {
    const focus = startOfDay(grid.focusDate);

    if (props.mode === 'day') {
        return [focus];
    }

    if (props.mode === 'days4') {
        return Array.from({ length: 4 }, (_, i) => addDays(focus, i));
    }

    // Weekends sit at the ends of the week, so what's left stays contiguous.
    return weekDays(grid.focusDate, grid.weekStartsOn).filter(
        (d) => grid.showWeekends || ![0, 6].includes(d.getDay()),
    );
});

const visible = computed(() => {
    const start = days.value[0];
    const end = addDays(days.value[days.value.length - 1], 1);
    const visibleIds = new Set(list.visibleCalendarIds);
    const listedIds = new Set(list.calendars.map((c) => c.id));

    return grid
        .occurrencesBetween(start, end)
        .filter((o) => isShown(o, visibleIds, listedIds));
});

const laneItems = computed(() =>
    visible.value.filter((o) => {
        const start = new Date(o.starts_at);
        const end = new Date(o.ends_at);

        return o.all_day || isMultiDay(start, end);
    }),
);

const timedItems = computed(() =>
    visible.value.filter((o) => !laneItems.value.includes(o)),
);

const laneSegments = computed(() => {
    if (props.mode === 'day') {
        return laneItems.value
            .filter((o) =>
                occurrenceTouchesDay(
                    new Date(o.starts_at),
                    new Date(o.ends_at),
                    days.value[0],
                ),
            )
            .map((occurrence, index) => ({
                occurrence,
                startCol: 0,
                span: 1,
                lane: index,
                continuesBefore: false,
                continuesAfter: false,
            }));
    }

    return layoutWeekLanes(laneItems.value, days.value);
});

const laneCount = computed(() =>
    laneSegments.value.reduce((max, s) => Math.max(max, s.lane + 1), 0),
);

type PositionedBox = {
    occurrence: EventOccurrence;
    top: number;
    height: number;
    left: number;
    width: number;
};

/** A "+N" tab standing in for the events a crowded cluster could not fit. */
type FoldBox = Omit<PositionedBox, 'occurrence'> & {
    key: string;
    occurrences: EventOccurrence[];
};

const scroller = ref<HTMLElement | null>(null);
const bodyRef = ref<HTMLElement | null>(null);

// How many events fit side by side in one day column. Unmeasured (server
// render, first client frame) means no limit, so hydration matches.
const { width: bodyWidth } = useElementSize(bodyRef);
const maxColumns = computed(() =>
    bodyWidth.value > 0
        ? Math.max(
              1,
              Math.floor(
                  (bodyWidth.value - 64) / days.value.length / MIN_COLUMN_PX,
              ),
          )
        : Infinity,
);

const dayLayouts = computed<{ boxes: PositionedBox[]; folds: FoldBox[] }[]>(
    () =>
        days.value.map((day) => {
            const dayStart = startOfDay(day);
            const dayEnd = addDays(dayStart, 1);

            const items = timedItems.value
                .filter((o) =>
                    occurrenceTouchesDay(
                        new Date(o.starts_at),
                        new Date(o.ends_at),
                        day,
                    ),
                )
                .map((o) => {
                    const start = new Date(
                        Math.max(
                            new Date(o.starts_at).getTime(),
                            dayStart.getTime(),
                        ),
                    );
                    const end = new Date(
                        Math.min(
                            new Date(o.ends_at).getTime(),
                            dayEnd.getTime(),
                        ),
                    );

                    return {
                        occurrence: o,
                        startMinutes: minutesIntoDay(start),
                        endMinutes:
                            end.getTime() === dayEnd.getTime()
                                ? 1440
                                : minutesIntoDay(end),
                    };
                });

            const laid = layoutDayColumn(
                items.map((i) => ({
                    key: i.occurrence.occurrence_id,
                    startMinutes: i.startMinutes,
                    endMinutes: i.endMinutes,
                })),
                maxColumns.value,
            );

            const byKey = new Map(
                items.map((i) => [i.occurrence.occurrence_id, i]),
            );

            const place = (box: (typeof laid)[number]) => ({
                top: (box.startMinutes / 60) * HOUR_HEIGHT,
                height: Math.max(
                    20,
                    ((box.endMinutes - box.startMinutes) / 60) * HOUR_HEIGHT,
                ),
                left: box.left,
                width: box.width,
            });

            return {
                boxes: laid
                    .filter((box) => !box.folded)
                    .map((box) => ({
                        occurrence: byKey.get(box.key)!.occurrence,
                        ...place(box),
                    })),
                folds: laid
                    .filter((box) => box.folded)
                    .map((box) => ({
                        key: box.key,
                        occurrences: box.folded!.map(
                            (key) => byKey.get(key)!.occurrence,
                        ),
                        ...place(box),
                    })),
            };
        }),
);

const columns = computed(() => dayLayouts.value.map((day) => day.boxes));
const folds = computed(() => dayLayouts.value.map((day) => day.folds));

const foldLabel = (fold: FoldBox) =>
    `${fold.occurrences.length} more events, ${format(new Date(fold.occurrences[0].starts_at), 'HH:mm')} onward`;

const hours = Array.from({ length: 24 }, (_, h) => h);

const today = new Date();

const now = ref(new Date());

let nowInterval: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    nowInterval = setInterval(() => {
        now.value = new Date();
    }, 60_000);

    scroller.value?.scrollTo({ top: 7 * HOUR_HEIGHT });
});

onBeforeUnmount(() => {
    clearInterval(nowInterval);
});

const nowTop = computed(() => (minutesIntoDay(now.value) / 60) * HOUR_HEIGHT);

const timeLabel = (occurrence: EventOccurrence) =>
    `${format(new Date(occurrence.starts_at), 'HH:mm')} - ${format(new Date(occurrence.ends_at), 'HH:mm')}`;

/* ------------------------------------------------------------------ */
/* Drag interactions: create on empty slots, move / resize on events.  */
/* ------------------------------------------------------------------ */

type DragState = {
    mode: 'create' | 'move' | 'resize';
    occurrence: EventOccurrence | null;
    dayIndex: number;
    startMinutes: number;
    endMinutes: number;
    /** Where inside the event the pointer grabbed it (move only). */
    grabOffsetMinutes: number;
    anchorMinutes: number;
    active: boolean;
    originX: number;
    originY: number;
};

const drag = ref<DragState | null>(null);
let suppressNextClick = false;
let touchHoldTimer: ReturnType<typeof setTimeout> | undefined;

const snap = (minutes: number) =>
    Math.round(minutes / SNAP_MINUTES) * SNAP_MINUTES;

const clampMinutes = (minutes: number) => Math.min(1440, Math.max(0, minutes));

function pointerPosition(event: PointerEvent): {
    dayIndex: number;
    minutes: number;
} | null {
    const body = bodyRef.value;

    if (!body) {
        return null;
    }

    const bounds = body.getBoundingClientRect();
    const gutter = 64;
    const usable = bounds.width - gutter;
    const x = event.clientX - bounds.left - gutter;
    const y = event.clientY - bounds.top;

    const dayIndex = Math.min(
        days.value.length - 1,
        Math.max(0, Math.floor((x / usable) * days.value.length)),
    );

    const minutes = clampMinutes((y / HOUR_HEIGHT) * 60);

    return { dayIndex, minutes };
}

const dateAt = (dayIndex: number, minutes: number): Date => {
    const date = startOfDay(days.value[dayIndex]);
    date.setMinutes(minutes);

    return date;
};

function beginDrag(event: PointerEvent, state: DragState): void {
    if (event.pointerType === 'touch') {
        // Long-press before dragging so scrolling wins by default.
        touchHoldTimer = setTimeout(() => {
            drag.value = { ...state, active: true };
        }, TOUCH_HOLD_MS);
    }

    drag.value = state;

    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('keydown', onDragKeydown);
}

function onEmptyPointerDown(event: PointerEvent): void {
    if (
        event.button !== 0 ||
        (event.target as HTMLElement).closest('[data-event]')
    ) {
        return;
    }

    const position = pointerPosition(event);

    if (!position) {
        return;
    }

    const start = snap(position.minutes);

    beginDrag(event, {
        mode: 'create',
        occurrence: null,
        dayIndex: position.dayIndex,
        startMinutes: start,
        endMinutes: start + SNAP_MINUTES,
        grabOffsetMinutes: 0,
        anchorMinutes: start,
        active: false,
        originX: event.clientX,
        originY: event.clientY,
    });
}

function onEventPointerDown(
    event: PointerEvent,
    box: PositionedBox,
    dayIndex: number,
): void {
    if (event.button !== 0 || !box.occurrence.can_edit) {
        return;
    }

    event.stopPropagation();

    const startMinutes = box.top / (HOUR_HEIGHT / 60);
    const endMinutes = startMinutes + box.height / (HOUR_HEIGHT / 60);
    const position = pointerPosition(event);

    beginDrag(event, {
        mode: 'move',
        occurrence: box.occurrence,
        dayIndex,
        startMinutes,
        endMinutes,
        grabOffsetMinutes: (position?.minutes ?? startMinutes) - startMinutes,
        anchorMinutes: startMinutes,
        active: false,
        originX: event.clientX,
        originY: event.clientY,
    });
}

function onResizePointerDown(
    event: PointerEvent,
    box: PositionedBox,
    dayIndex: number,
): void {
    if (event.button !== 0 || !box.occurrence.can_edit) {
        return;
    }

    event.stopPropagation();

    const startMinutes = box.top / (HOUR_HEIGHT / 60);
    const endMinutes = startMinutes + box.height / (HOUR_HEIGHT / 60);

    beginDrag(event, {
        mode: 'resize',
        occurrence: box.occurrence,
        dayIndex,
        startMinutes,
        endMinutes,
        grabOffsetMinutes: 0,
        anchorMinutes: startMinutes,
        active: false,
        originX: event.clientX,
        originY: event.clientY,
    });
}

function onPointerMove(event: PointerEvent): void {
    const state = drag.value;

    if (!state) {
        return;
    }

    if (!state.active) {
        const distance = Math.hypot(
            event.clientX - state.originX,
            event.clientY - state.originY,
        );

        if (event.pointerType === 'touch') {
            // Scrolling gesture: give up the pending long-press drag.
            if (distance > DRAG_THRESHOLD_PX * 3) {
                cancelDrag();
            }

            return;
        }

        if (distance < DRAG_THRESHOLD_PX) {
            return;
        }

        state.active = true;
    }

    event.preventDefault();

    const position = pointerPosition(event);

    if (!position) {
        return;
    }

    if (state.mode === 'create') {
        const pointerSnap = snap(position.minutes);

        state.startMinutes = Math.min(state.anchorMinutes, pointerSnap);
        state.endMinutes = Math.max(
            state.anchorMinutes + SNAP_MINUTES,
            pointerSnap,
        );
    } else if (state.mode === 'move') {
        const duration = state.endMinutes - state.startMinutes;
        const nextStart = clampMinutes(
            snap(position.minutes - state.grabOffsetMinutes),
        );

        state.dayIndex = position.dayIndex;
        state.startMinutes = Math.min(nextStart, 1440 - duration);
        state.endMinutes = state.startMinutes + duration;
    } else {
        state.endMinutes = Math.max(
            state.startMinutes + MIN_EVENT_MINUTES,
            snap(position.minutes),
        );
    }
}

async function onPointerUp(): Promise<void> {
    const state = drag.value;

    teardownListeners();

    if (!state) {
        return;
    }

    drag.value = null;

    if (!state.active) {
        return;
    }

    suppressNextClick = true;
    setTimeout(() => (suppressNextClick = false), 0);

    if (state.mode === 'create') {
        emit(
            'createRange',
            dateAt(state.dayIndex, state.startMinutes),
            dateAt(state.dayIndex, state.endMinutes),
        );

        return;
    }

    const occurrence = state.occurrence!;
    const newStart = dateAt(state.dayIndex, state.startMinutes);
    const newEnd = dateAt(state.dayIndex, state.endMinutes);

    if (
        newStart.toISOString() === occurrence.starts_at &&
        newEnd.toISOString() === occurrence.ends_at
    ) {
        return;
    }

    if (occurrence.is_recurring) {
        emit('moveRecurring', occurrence, newStart, newEnd);

        return;
    }

    try {
        await grid.moveEvent(occurrence, newStart, newEnd);
    } catch {
        toast.error('Could not reschedule the event.');
    }
}

function onDragKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        cancelDrag();
    }
}

function cancelDrag(): void {
    drag.value = null;
    teardownListeners();
}

function teardownListeners(): void {
    clearTimeout(touchHoldTimer);
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    window.removeEventListener('keydown', onDragKeydown);
}

onBeforeUnmount(teardownListeners);

function onSlotClick(day: Date, event: MouseEvent): void {
    if (
        suppressNextClick ||
        (event.target as HTMLElement).closest('[data-event]')
    ) {
        return;
    }

    const bounds = (event.currentTarget as HTMLElement).getBoundingClientRect();

    const minutes =
        Math.floor(
            (((event.clientY - bounds.top) / HOUR_HEIGHT) * 60) / SNAP_MINUTES,
        ) * SNAP_MINUTES;

    const start = startOfDay(day);
    start.setMinutes(minutes);

    const end = new Date(start.getTime() + 60 * 60 * 1000);

    emit('createRange', start, end);
}

function onEventClick(occurrence: EventOccurrence): void {
    if (suppressNextClick) {
        return;
    }

    emit('open', occurrence);
}

/**
 * Keyboard rescheduling on a focused event block:
 * Ctrl+Arrows move by one snap step (or one day), Shift+Up/Down resize.
 */
async function onEventKeydown(
    event: KeyboardEvent,
    box: PositionedBox,
): Promise<void> {
    if (!box.occurrence.can_edit) {
        return;
    }

    const isArrow = [
        'ArrowUp',
        'ArrowDown',
        'ArrowLeft',
        'ArrowRight',
    ].includes(event.key);

    if (!isArrow || (!event.ctrlKey && !event.shiftKey)) {
        return;
    }

    event.preventDefault();

    const start = new Date(box.occurrence.starts_at);
    const end = new Date(box.occurrence.ends_at);
    const step = SNAP_MINUTES * 60 * 1000;
    const day = 24 * 60 * 60 * 1000;

    let newStart = start;
    let newEnd = end;

    if (event.shiftKey && !event.ctrlKey) {
        if (event.key === 'ArrowDown') {
            newEnd = new Date(end.getTime() + step);
        } else if (event.key === 'ArrowUp') {
            newEnd = new Date(
                Math.max(
                    start.getTime() + MIN_EVENT_MINUTES * 60 * 1000,
                    end.getTime() - step,
                ),
            );
        } else {
            return;
        }
    } else if (event.ctrlKey) {
        const delta =
            event.key === 'ArrowUp'
                ? -step
                : event.key === 'ArrowDown'
                  ? step
                  : event.key === 'ArrowLeft'
                    ? -day
                    : day;

        newStart = new Date(start.getTime() + delta);
        newEnd = new Date(end.getTime() + delta);
    }

    if (box.occurrence.is_recurring) {
        emit('moveRecurring', box.occurrence, newStart, newEnd);

        return;
    }

    try {
        await grid.moveEvent(box.occurrence, newStart, newEnd);
    } catch {
        toast.error('Could not reschedule the event.');
    }
}

const ghost = computed(() => {
    const state = drag.value;

    if (!state || !state.active) {
        return null;
    }

    return {
        dayIndex: state.dayIndex,
        top: (state.startMinutes / 60) * HOUR_HEIGHT,
        height: Math.max(
            14,
            ((state.endMinutes - state.startMinutes) / 60) * HOUR_HEIGHT,
        ),
        label: `${format(dateAt(state.dayIndex, state.startMinutes), 'HH:mm')} - ${format(dateAt(state.dayIndex, state.endMinutes), 'HH:mm')}`,
        color: state.occurrence?.color ?? null,
        mode: state.mode,
    };
});

const draggingEventId = computed(() =>
    drag.value?.active ? drag.value.occurrence?.event_id : undefined,
);
</script>

<template>
    <div class="flex h-full flex-col">
        <!-- Day headers -->
        <div
            class="grid border-b"
            :style="{
                gridTemplateColumns: `64px repeat(${days.length}, minmax(0, 1fr))`,
            }"
        >
            <div aria-hidden="true" />
            <div
                v-for="day in days"
                :key="day.toISOString()"
                class="border-l py-2 text-center"
            >
                <button
                    type="button"
                    class="inline-flex flex-col items-center gap-0.5"
                    :aria-label="format(day, 'EEEE, MMMM d')"
                    @click="
                        grid.setFocusDate(new Date(day));
                        grid.setView('day');
                    "
                >
                    <span class="text-xs text-muted-foreground">
                        {{ format(day, 'EEE') }}
                    </span>
                    <span
                        class="flex size-7 items-center justify-center rounded-full text-sm font-medium"
                        :class="
                            isSameDay(day, today)
                                ? 'bg-gradient-accent font-semibold'
                                : ''
                        "
                    >
                        {{ format(day, 'd') }}
                    </span>
                </button>
            </div>
        </div>

        <!-- All-day lane -->
        <div
            v-if="laneCount > 0"
            class="flex border-b bg-muted/20"
            :style="{ height: `${laneCount * 24 + 8}px` }"
        >
            <div
                class="w-16 shrink-0 py-1 pr-2 text-right text-[10px] text-muted-foreground"
            >
                all-day
            </div>
            <div class="relative flex-1">
                <div
                    v-for="(day, d) in days"
                    :key="day.toISOString()"
                    class="absolute top-0 bottom-0 border-l"
                    :style="{ left: `${(d / days.length) * 100}%` }"
                    aria-hidden="true"
                />

                <button
                    v-for="segment in laneSegments"
                    :key="segment.occurrence.occurrence_id + segment.startCol"
                    type="button"
                    data-event
                    class="ev-pill absolute z-10 flex items-center gap-1 truncate rounded-[6px] px-2 text-left text-xs leading-5 font-medium"
                    :class="[
                        chipFill(segment.occurrence),
                        {
                            'rounded-l-none': segment.continuesBefore,
                            'rounded-r-none': segment.continuesAfter,
                        },
                    ]"
                    :style="{
                        left: `calc(${(segment.startCol / days.length) * 100}% + 4px)`,
                        width: `calc(${(segment.span / days.length) * 100}% - 8px)`,
                        top: `${4 + segment.lane * 24}px`,
                        height: '20px',
                        '--wash': eventWash(bookingTone(segment.occurrence)),
                        '--ink': eventInk(bookingTone(segment.occurrence)),
                        '--ev': bookingTone(segment.occurrence) ?? '#d4d8dd',
                    }"
                    :aria-label="`${chipState(segment.occurrence)}${segment.occurrence.title}`"
                    @click="emit('open', segment.occurrence)"
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
            </div>
        </div>

        <!-- Scrollable time grid -->
        <div ref="scroller" class="flex-1 overflow-y-auto">
            <div
                ref="bodyRef"
                class="relative grid"
                :style="{
                    gridTemplateColumns: `64px repeat(${days.length}, minmax(0, 1fr))`,
                    height: `${24 * HOUR_HEIGHT}px`,
                }"
            >
                <!-- Hour gutter -->
                <div class="relative">
                    <div
                        v-for="hour in hours"
                        :key="hour"
                        class="absolute right-2 -translate-y-1/2 text-[10px] text-muted-foreground"
                        :style="{ top: `${hour * HOUR_HEIGHT}px` }"
                    >
                        <template v-if="hour > 0">
                            {{ String(hour).padStart(2, '0') }}:00
                        </template>
                    </div>
                </div>

                <!-- Day columns -->
                <div
                    v-for="(day, d) in days"
                    :key="day.toISOString()"
                    class="relative cursor-pointer touch-pan-y border-l"
                    role="gridcell"
                    :aria-label="format(day, 'EEEE, MMMM d')"
                    @pointerdown="onEmptyPointerDown"
                    @click="(e) => onSlotClick(day, e)"
                >
                    <!-- Hour lines -->
                    <div
                        v-for="hour in hours"
                        :key="hour"
                        class="absolute right-0 left-0 border-t border-border/60"
                        :style="{ top: `${hour * HOUR_HEIGHT}px` }"
                        aria-hidden="true"
                    />

                    <!-- Events -->
                    <div
                        v-for="box in columns[d]"
                        :key="box.occurrence.occurrence_id"
                        data-event
                        class="ev-pill group absolute overflow-hidden rounded-[6px] shadow-xs"
                        :class="{
                            [chipFill(box.occurrence)]: true,
                            'opacity-40':
                                draggingEventId === box.occurrence.event_id,
                            'cursor-grab active:cursor-grabbing':
                                box.occurrence.can_edit,
                        }"
                        :style="{
                            top: `${box.top}px`,
                            height: `${box.height}px`,
                            left: `calc(${box.left * 100}% + 2px)`,
                            width: `calc(${box.width * 100}% - 4px)`,
                            '--wash': eventWash(bookingTone(box.occurrence)),
                            '--ink': eventInk(bookingTone(box.occurrence)),
                            '--ev': bookingTone(box.occurrence) ?? '#d4d8dd',
                        }"
                        data-peek="clipped"
                        @pointerdown="(e) => onEventPointerDown(e, box, d)"
                        @pointerenter="(e) => peekEnter(e, box.occurrence)"
                        @pointerleave="peekClose()"
                    >
                        <button
                            type="button"
                            class="block h-full w-full px-1.5 py-0.5 text-left text-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :class="{
                                'line-through opacity-70':
                                    box.occurrence.status === 'cancelled' &&
                                    !box.occurrence.booking,
                            }"
                            :aria-label="`${chipState(box.occurrence)}${box.occurrence.title}, ${timeLabel(box.occurrence)}${box.occurrence.can_edit ? '. Ctrl+arrows to move, Shift+arrows to resize' : ''}`"
                            @click="onEventClick(box.occurrence)"
                            @focus="(e) => peekFocus(e, box.occurrence)"
                            @blur="peekClose()"
                            @keydown="(e) => onEventKeydown(e, box)"
                        >
                            <span
                                class="bk-title bk-label flex max-w-full items-center gap-1 font-medium"
                            >
                                <component
                                    :is="chipIcon(box.occurrence)"
                                    v-if="chipIcon(box.occurrence)"
                                    class="size-3 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="truncate">{{
                                    box.occurrence.title
                                }}</span>
                            </span>
                            <span
                                v-if="box.height > 32"
                                data-peek-time
                                class="bk-label mt-0.5 block w-fit max-w-full truncate text-[10px] opacity-75"
                            >
                                {{ timeLabel(box.occurrence) }}
                            </span>
                        </button>

                        <!-- Resize handle -->
                        <div
                            v-if="box.occurrence.can_edit"
                            class="absolute right-0 bottom-0 left-0 h-2 cursor-ns-resize opacity-0 transition-opacity group-hover:opacity-100"
                            :style="{
                                backgroundColor: box.occurrence.color + '55',
                            }"
                            aria-hidden="true"
                            @pointerdown="(e) => onResizePointerDown(e, box, d)"
                        />
                    </div>

                    <!-- Crowded clusters: the events that did not fit. -->
                    <button
                        v-for="fold in folds[d]"
                        :key="fold.key"
                        type="button"
                        data-event
                        class="ev-pill absolute z-10 flex items-start justify-center overflow-hidden rounded-[6px] bg-muted px-1 py-0.5 text-xs font-medium text-muted-foreground tabular-nums ring-1 ring-border ring-inset hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :style="{
                            top: `${fold.top}px`,
                            height: `${fold.height}px`,
                            left: `calc(${fold.left * 100}% + 2px)`,
                            width: `calc(${fold.width * 100}% - 4px)`,
                        }"
                        :aria-label="foldLabel(fold)"
                        @click="emit('showDay', day, fold.occurrences)"
                    >
                        {{
                            fold.width === 1
                                ? `${fold.occurrences.length} events`
                                : `+${fold.occurrences.length}`
                        }}
                    </button>

                    <!-- Drag ghost -->
                    <div
                        v-if="ghost && ghost.dayIndex === d"
                        class="pointer-events-none absolute right-1 left-1 z-30 rounded-[6px] border-2 border-dashed px-1.5 py-0.5 text-xs font-medium"
                        :style="{
                            top: `${ghost.top}px`,
                            height: `${ghost.height}px`,
                            borderColor: ghost.color ?? 'var(--primary)',
                            backgroundColor: (ghost.color ?? '#F6BF26') + '22',
                        }"
                        aria-hidden="true"
                    >
                        {{ ghost.label }}
                    </div>

                    <!-- Now indicator -->
                    <div
                        v-if="isSameDay(day, now)"
                        class="pointer-events-none absolute right-0 left-0 z-20"
                        :style="{ top: `${nowTop}px` }"
                        aria-hidden="true"
                    >
                        <div class="relative border-t-2 border-foreground">
                            <span
                                class="absolute -top-[5px] -left-1 size-2 rounded-full bg-foreground"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
