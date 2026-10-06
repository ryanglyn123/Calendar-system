<script lang="ts">
export type BookableResource = {
    id: number;
    name: string;
    location: string | null;
    capacity: number | null;
    description: string | null;
    calendar_id: number;
    color: string | null;
};
</script>

<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { useIntervalFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { iconButton, pillQuiet } from '@/components/desk/desk';
import { peekClose, peekEnter, peekFocus } from '@/composables/useEventPeek';
import {
    BOOKING_STATE,
    bookingTone,
    chipFill,
    chipIcon,
    chipState,
    holdsSlot,
    liveStatus,
} from '@/lib/bookingDisplay';
import {
    addDays,
    eventInk,
    eventWash,
    format,
    isPastDay,
    isSameDay,
    minutesIntoDay,
    startOfDay,
    weekDays,
} from '@/lib/calendarMath';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import { useCalendarListStore } from '@/stores/calendarList';
import type { EventOccurrence } from '@/types/calendar';

/**
 * A booking calendar drawn around the things being booked: one row per room
 * or vehicle. Day view lays each row out as an hour lane, week view as seven
 * day cells, and phones get the focused day as a list per resource with its
 * free gaps. Empty time is the call to action: clicking it books that
 * resource at that time.
 */
const props = defineProps<{
    resources: BookableResource[];
    nouns: { one: string; many: string };
}>();

const emit = defineEmits<{
    open: [occurrence: EventOccurrence];
    book: [resourceId: number, start: Date, end: Date | null];
}>();

const grid = useCalendarGridStore();
const list = useCalendarListStore();

/** Office hours; the day lane stretches past them only for what it holds. */
const OPEN = 7 * 60;
const CLOSE = 19 * 60;
const SLOT = 30;
const HOUR = 3_600_000;
const ROW = 40;
const GAP = 4;
const PAD = 6;
const CHIPS = 3;

const now = ref(new Date());
useIntervalFn(() => (now.value = new Date()), 60_000);

const isWeek = computed(() => grid.view === 'week');
const day = computed(() => startOfDay(grid.focusDate));
const week = computed(() => weekDays(grid.focusDate, grid.weekStartsOn));

/** The rooms or vehicles the filter keeps on screen. */
const shown = computed(() => {
    if (!list.loaded) {
        return props.resources;
    }

    const visible = new Set(list.visibleCalendarIds);

    return props.resources.filter((r) => visible.has(r.calendar_id));
});

const time = (iso: string) => new Date(iso).getTime();

const touches = (o: EventOccurrence, from: Date, to: Date) =>
    time(o.starts_at) < to.getTime() && time(o.ends_at) > from.getTime();

/** The week's occurrences per resource calendar, soonest first. */
const weekItems = computed(() => {
    const items = new Map(
        shown.value.map((r) => [r.calendar_id, [] as EventOccurrence[]]),
    );

    grid.occurrencesBetween(week.value[0], addDays(week.value[6], 1)).forEach(
        (o) => items.get(o.calendar_id)?.push(o),
    );

    return items;
});

const itemsOn = (calendarId: number, d: Date) =>
    (weekItems.value.get(calendarId) ?? []).filter((o) =>
        touches(o, startOfDay(d), addDays(startOfDay(d), 1)),
    );

/** Pending requests that overlap another live booking of the same resource. */
const clashes = computed(() => {
    const ids = new Set<string>();

    weekItems.value.forEach((items) =>
        items.forEach((o) => {
            if (
                o.booking?.status === 'pending' &&
                items.some(
                    (other) =>
                        other !== o &&
                        holdsSlot(other) &&
                        touches(
                            other,
                            new Date(o.starts_at),
                            new Date(o.ends_at),
                        ),
                )
            ) {
                ids.add(o.occurrence_id);
            }
        }),
    );

    return ids;
});

const tone = (o: EventOccurrence) => {
    const color = bookingTone(o);

    return {
        '--ev': color ?? '#d4d8dd',
        '--wash': eventWash(color),
        '--ink': eventInk(color),
    };
};

const range = (o: EventOccurrence) =>
    `${format(new Date(o.starts_at), 'HH:mm')}–${format(new Date(o.ends_at), 'HH:mm')}`;

const who = (o: EventOccurrence) =>
    o.booking?.is_mine ? 'You' : (o.booking?.requested_by ?? null);

const describe = (o: EventOccurrence) =>
    `${chipState(o)}${o.title}, ${format(new Date(o.starts_at), 'EEE MMM d, HH:mm')} to ${format(new Date(o.ends_at), 'HH:mm')}${clashes.value.has(o.occurrence_id) ? ', clashes with another booking' : ''}`;

const details = (r: BookableResource) =>
    [r.location, r.capacity ? `${r.capacity} seats` : null]
        .filter(Boolean)
        .join(' · ');

/** Live state per resource, while today is on screen. */
const status = computed(() => {
    const today = now.value;
    const onScreen = isWeek.value
        ? week.value.some((d) => isSameDay(d, today))
        : isSameDay(day.value, today);

    if (!onScreen) {
        return null;
    }

    return new Map(
        shown.value.map((r) => [
            r.calendar_id,
            liveStatus(itemsOn(r.calendar_id, today), today),
        ]),
    );
});

/** Book a resource from its row: the day on screen, or today if that's past. */
const bookFromRow = (r: BookableResource) =>
    emit('book', r.id, isPastDay(day.value) ? now.value : day.value, null);

const at = (d: Date, minutes: number) => {
    const date = startOfDay(d);
    date.setMinutes(minutes);

    return date;
};

/* ---- Day lanes -------------------------------------------------------- */

const minutesOn = (iso: string) =>
    Math.round((time(iso) - day.value.getTime()) / 60_000);

const dayItems = computed(
    () =>
        new Map(
            shown.value.map((r) => [
                r.calendar_id,
                itemsOn(r.calendar_id, day.value),
            ]),
        ),
);

/** Office hours, stretched to whatever the day actually holds. */
const frame = computed(() => {
    let from = OPEN;
    let to = CLOSE;

    dayItems.value.forEach((items) =>
        items.forEach((o) => {
            from = Math.min(from, Math.max(0, minutesOn(o.starts_at)));
            to = Math.max(to, Math.min(1440, minutesOn(o.ends_at)));
        }),
    );

    const start = Math.floor(from / 60);
    const end = Math.ceil(to / 60);

    return {
        start: start * 60,
        span: (end - start) * 60,
        hours: Array.from({ length: end - start }, (_, i) => start + i),
    };
});

const pct = (minutes: number) =>
    ((minutes - frame.value.start) / frame.value.span) * 100;

type Block = {
    occurrence: EventOccurrence;
    row: number;
    left: number;
    width: number;
};

/** Pack each lane greedily so overlapping requests stack instead of hiding. */
const lanes = computed(() => {
    const result = new Map<number, { blocks: Block[]; rows: number }>();
    const end = frame.value.start + frame.value.span;

    dayItems.value.forEach((items, calendarId) => {
        const rowEnds: number[] = [];
        const blocks = items.map((occurrence): Block => {
            let row = rowEnds.findIndex(
                (rowEnd) => rowEnd <= time(occurrence.starts_at),
            );

            if (row === -1) {
                row = rowEnds.length;
            }

            rowEnds[row] = time(occurrence.ends_at);

            const left = pct(
                Math.max(frame.value.start, minutesOn(occurrence.starts_at)),
            );

            return {
                occurrence,
                row,
                left,
                width: pct(Math.min(end, minutesOn(occurrence.ends_at))) - left,
            };
        });

        result.set(calendarId, { blocks, rows: Math.max(1, rowEnds.length) });
    });

    return result;
});

const laneHeight = (calendarId: number) => {
    const rows = lanes.value.get(calendarId)?.rows ?? 1;

    return rows * ROW + (rows - 1) * GAP + PAD * 2;
};

const blockStyle = (block: Block) => ({
    left: `calc(${block.left}% + 1px)`,
    width: `max(calc(${block.width}% - 2px), 1.5rem)`,
    top: `${PAD + block.row * (ROW + GAP)}px`,
    height: `${ROW}px`,
    ...tone(block.occurrence),
});

const blockMeta = (o: EventOccurrence) =>
    [clashes.value.has(o.occurrence_id) ? 'Clash' : null, range(o), who(o)]
        .filter(Boolean)
        .join(' · ');

const nowMinutes = computed(() => minutesIntoDay(now.value));

/** How much of the lane is already behind us. */
const pastPct = computed(() => {
    if (isPastDay(day.value)) {
        return 100;
    }

    if (!isSameDay(day.value, now.value)) {
        return 0;
    }

    return Math.min(100, Math.max(0, pct(nowMinutes.value)));
});

const nowPct = computed(() => {
    if (!isSameDay(day.value, now.value)) {
        return null;
    }

    const position = pct(nowMinutes.value);

    return position >= 0 && position <= 100 ? position : null;
});

/** Hour labels the now flag would sit on. */
const nearNow = (hour: number) =>
    nowPct.value !== null && Math.abs(hour * 60 - nowMinutes.value) < 40;

/** The half hour under the pointer, as a one-hour ghost booking. */
const hover = ref<{ calendarId: number; minutes: number } | null>(null);

const slotAt = (event: MouseEvent) => {
    const box = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const minutes =
        frame.value.start +
        ((event.clientX - box.left) / box.width) * frame.value.span;

    return Math.min(
        Math.floor(minutes / SLOT) * SLOT,
        frame.value.start + frame.value.span - 60,
    );
};

const openSlot = (minutes: number) => at(day.value, minutes) > now.value;

const onLaneMove = (event: PointerEvent, r: BookableResource) => {
    if (
        event.pointerType !== 'mouse' ||
        (event.target as Element).closest('[data-event]')
    ) {
        hover.value = null;

        return;
    }

    const minutes = slotAt(event);

    hover.value = openSlot(minutes)
        ? { calendarId: r.calendar_id, minutes }
        : null;
};

const onLaneClick = (event: MouseEvent, r: BookableResource) => {
    if ((event.target as Element).closest('[data-event]')) {
        return;
    }

    const minutes = slotAt(event);

    if (openSlot(minutes)) {
        const start = at(day.value, minutes);
        emit('book', r.id, start, new Date(start.getTime() + HOUR));
    }
};

/* ---- Week cells ------------------------------------------------------- */

const cells = computed(
    () =>
        new Map(
            shown.value.map((r) => [
                r.calendar_id,
                week.value.map((d) => itemsOn(r.calendar_id, d)),
            ]),
        ),
);

/** A time for a chip in one day's cell; trips running over days say so. */
const cellTime = (o: EventOccurrence, d: Date) => {
    const start = new Date(o.starts_at);
    const lastMoment = new Date(time(o.ends_at) - 1);
    const startsHere = isSameDay(start, d);
    const endsHere = isSameDay(lastMoment, d);

    if (startsHere && endsHere) {
        return format(start, 'HH:mm');
    }

    if (startsHere) {
        return `${format(start, 'HH:mm')} →`;
    }

    return endsHere ? `→ ${format(new Date(o.ends_at), 'HH:mm')}` : 'All day';
};

/** Office-hours occupancy bar for one cell: solid booked, hatched waiting. */
const strip = (items: EventOccurrence[], d: Date) => {
    const open = at(d, OPEN).getTime();
    const close = at(d, CLOSE).getTime();
    const span = close - open;

    return items.filter(holdsSlot).flatMap((o) => {
        const from = Math.max(open, time(o.starts_at));
        const to = Math.min(close, time(o.ends_at));

        return to > from
            ? [
                  {
                      key: o.occurrence_id,
                      waiting: o.booking?.status === 'pending',
                      left: ((from - open) / span) * 100,
                      width: ((to - from) / span) * 100,
                  },
              ]
            : [];
    });
};

const openDay = (d: Date) => {
    grid.setFocusDate(new Date(d));
    grid.setView('day');
};

const onCellClick = (event: MouseEvent, r: BookableResource, d: Date) => {
    if (
        !(event.target as Element).closest('[data-event], button') &&
        !isPastDay(d)
    ) {
        emit('book', r.id, startOfDay(d), null);
    }
};

/* ---- Phone list --------------------------------------------------------- */

type AgendaRow =
    | { kind: 'booking'; at: number; occurrence: EventOccurrence }
    | { kind: 'free'; at: number; start: Date; end: Date };

/** The day's bookings with the bookable gaps (30 min or more) between them. */
const agenda = (r: BookableResource): AgendaRow[] => {
    const items = dayItems.value.get(r.calendar_id) ?? [];
    const close = at(day.value, CLOSE).getTime();
    const soonest =
        Math.ceil(now.value.getTime() / (SLOT * 60_000)) * SLOT * 60_000;
    let cursor = Math.max(at(day.value, OPEN).getTime(), soonest);
    const gaps: AgendaRow[] = [];

    const gap = (until: number) => {
        if (Math.min(until, close) - cursor >= SLOT * 60_000) {
            gaps.push({
                kind: 'free',
                at: cursor,
                start: new Date(cursor),
                end: new Date(Math.min(until, close)),
            });
        }
    };

    items.filter(holdsSlot).forEach((o) => {
        gap(time(o.starts_at));
        cursor = Math.max(cursor, time(o.ends_at));
    });
    gap(close);

    return [
        ...items.map((o): AgendaRow => ({
            kind: 'booking',
            at: time(o.starts_at),
            occurrence: o,
        })),
        ...gaps,
    ].sort((a, b) => a.at - b.at);
};

const busyOn = (d: Date) =>
    shown.value.some((r) => itemsOn(r.calendar_id, d).some(holdsSlot));

/* ---- Shared ------------------------------------------------------------- */

/** Anyone else's booking drawn in neutral gray needs a word in the key. */
const othersInGray = computed(() =>
    [...weekItems.value.values()].some((items) =>
        items.some(
            (o) => o.booking && !o.booking.is_mine && !o.booking.can_review,
        ),
    ),
);

const nothingBooked = computed(() =>
    [...(isWeek.value ? weekItems : dayItems).value.values()].every(
        (items) => items.length === 0,
    ),
);

/** Which way the period moved, so the new one slides in from that side. */
const direction = ref(0);

watch(
    () => grid.focusDate,
    (next, previous) => {
        direction.value = Math.sign(next.getTime() - previous.getTime());
    },
);

watch(
    () => grid.view,
    () => (direction.value = 0),
);

const periodKey = computed(
    () =>
        `${grid.view}|${format(isWeek.value ? week.value[0] : day.value, 'yyyy-MM-dd')}`,
);
</script>

<template>
    <div class="@container">
        <div
            class="transition-opacity duration-200 [--name:10.5rem] @5xl:[--name:14rem]"
            :class="{ 'opacity-60': grid.isLoading }"
            :aria-busy="grid.isLoading"
        >
            <div
                v-if="resources.length === 0"
                class="mx-4 my-6 rounded-xl bg-muted/50 px-4 py-4 text-sm sm:mx-6"
            >
                <p class="font-medium">No {{ nouns.many }} are bookable yet.</p>
                <p class="text-[13px] text-muted-foreground">
                    Once an admin adds active {{ nouns.many }} to this section,
                    each one gets its own row here.
                </p>
            </div>

            <div
                v-else-if="shown.length === 0"
                class="mx-4 my-6 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-muted/50 px-4 py-4 text-sm sm:mx-6"
            >
                <p>All {{ nouns.many }} are filtered out.</p>
                <button
                    type="button"
                    :class="pillQuiet"
                    @click="list.picked = null"
                >
                    Show all {{ nouns.many }}
                </button>
            </div>

            <template v-else>
                <!-- Desktop and tablet: lanes (day) or cells (week) -->
                <div
                    :key="periodKey"
                    class="sched-in relative hidden @2xl:block"
                    :style="{ '--dir': direction }"
                >
                    <!-- Day: an hour lane per resource -->
                    <template v-if="!isWeek">
                        <div
                            class="sticky top-0 z-20 grid grid-cols-[var(--name)_1fr] border-b border-border bg-card px-4 sm:px-6"
                            aria-hidden="true"
                        >
                            <span
                                class="self-end pb-1.5 text-xs text-muted-foreground"
                            >
                                {{ shown.length }}
                                {{
                                    shown.length === 1 ? nouns.one : nouns.many
                                }}
                            </span>
                            <div class="relative h-7">
                                <span
                                    v-for="(hour, index) in frame.hours"
                                    :key="hour"
                                    class="absolute bottom-1.5 -translate-x-1/2 text-[11px] text-muted-foreground tabular-nums first:translate-x-0"
                                    :class="[
                                        { invisible: nearNow(hour) },
                                        index % 2 && '@max-3xl:hidden',
                                    ]"
                                    :style="{
                                        left: `${(index / frame.hours.length) * 100}%`,
                                    }"
                                >
                                    {{ String(hour).padStart(2, '0') }}:00
                                </span>
                                <span
                                    v-if="nowPct !== null"
                                    class="absolute bottom-1 -translate-x-1/2 rounded-[4px] bg-foreground px-1 text-[10px] leading-4 font-semibold text-background tabular-nums"
                                    :style="{ left: `${nowPct}%` }"
                                >
                                    {{ format(now, 'HH:mm') }}
                                </span>
                            </div>
                        </div>

                        <ul
                            class="relative px-(--gutter) [--gutter:1rem] sm:[--gutter:1.5rem]"
                        >
                            <li
                                v-for="resource in shown"
                                :key="resource.id"
                                class="group/row grid grid-cols-[var(--name)_1fr] border-b border-border last:border-b-0"
                            >
                                <div
                                    class="relative flex min-w-0 items-center gap-2.5 py-2 pr-3"
                                >
                                    <span
                                        class="size-2.5 shrink-0 rounded-full"
                                        :style="{
                                            backgroundColor:
                                                resource.color ?? '#d4d8dd',
                                        }"
                                        aria-hidden="true"
                                    />
                                    <div
                                        class="min-w-0 flex-1"
                                        :title="details(resource) || undefined"
                                    >
                                        <p
                                            class="truncate text-[13px] font-medium"
                                        >
                                            {{ resource.name }}
                                        </p>
                                        <p
                                            v-if="status"
                                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                                        >
                                            <span
                                                class="size-1.5 shrink-0 rounded-full"
                                                :class="
                                                    status.get(
                                                        resource.calendar_id,
                                                    )?.free
                                                        ? 'bg-success'
                                                        : 'bg-foreground/60'
                                                "
                                                aria-hidden="true"
                                            />
                                            <span class="truncate">
                                                {{
                                                    status.get(
                                                        resource.calendar_id,
                                                    )?.label
                                                }}
                                            </span>
                                        </p>
                                        <p
                                            v-else-if="details(resource)"
                                            class="truncate text-xs text-muted-foreground"
                                        >
                                            {{ details(resource) }}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        :class="iconButton"
                                        class="absolute right-2 size-7 bg-card opacity-0 group-hover/row:opacity-100 hover:bg-muted focus-visible:opacity-100 [@media(hover:none)]:opacity-100"
                                        :aria-label="`Book ${resource.name}`"
                                        @click="bookFromRow(resource)"
                                    >
                                        <Plus aria-hidden="true" />
                                    </button>
                                </div>

                                <div
                                    class="relative my-1.5 cursor-pointer rounded-md bg-muted/45 bg-[linear-gradient(to_right,var(--border)_1px,transparent_1px)]"
                                    :style="{
                                        height: `${laneHeight(resource.calendar_id)}px`,
                                        backgroundSize: `calc(100% / ${frame.hours.length}) 100%`,
                                    }"
                                    @pointermove="
                                        (e) => onLaneMove(e, resource)
                                    "
                                    @pointerleave="hover = null"
                                    @click="(e) => onLaneClick(e, resource)"
                                >
                                    <!-- Time already gone: not bookable -->
                                    <div
                                        v-if="pastPct > 0"
                                        class="pointer-events-none absolute inset-y-0 left-0 rounded-l-md bg-card/55"
                                        :class="{
                                            'rounded-r-md': pastPct >= 100,
                                        }"
                                        :style="{ width: `${pastPct}%` }"
                                        aria-hidden="true"
                                    />

                                    <div
                                        v-if="
                                            hover?.calendarId ===
                                            resource.calendar_id
                                        "
                                        class="pointer-events-none absolute inset-y-1.5 flex items-center gap-1 rounded-[6px] border border-dashed border-foreground/35 bg-card/70 px-2 text-xs font-medium text-foreground/70 tabular-nums"
                                        :style="{
                                            left: `${pct(hover.minutes)}%`,
                                            width: `${(60 / frame.span) * 100}%`,
                                        }"
                                        aria-hidden="true"
                                    >
                                        <Plus class="size-3 shrink-0" />
                                        <span class="truncate">
                                            {{
                                                format(
                                                    at(day, hover.minutes),
                                                    'HH:mm',
                                                )
                                            }}
                                        </span>
                                    </div>

                                    <button
                                        v-for="block in lanes.get(
                                            resource.calendar_id,
                                        )?.blocks ?? []"
                                        :key="block.occurrence.occurrence_id"
                                        type="button"
                                        data-event
                                        data-peek="clipped"
                                        class="ev-pill @container absolute flex flex-col justify-center overflow-hidden rounded-[6px] px-2 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:outline-none"
                                        :class="[
                                            chipFill(block.occurrence),
                                            clashes.has(
                                                block.occurrence.occurrence_id,
                                            ) &&
                                                'outline-2 -outline-offset-1 outline-destructive',
                                        ]"
                                        :style="blockStyle(block)"
                                        :aria-label="describe(block.occurrence)"
                                        @click="emit('open', block.occurrence)"
                                        @pointerenter="
                                            (e) =>
                                                peekEnter(e, block.occurrence)
                                        "
                                        @pointerleave="peekClose()"
                                        @focus="
                                            (e) =>
                                                peekFocus(e, block.occurrence)
                                        "
                                        @blur="peekClose()"
                                    >
                                        <span
                                            class="bk-title flex max-w-full items-center gap-1 text-xs leading-4 font-medium"
                                        >
                                            <component
                                                :is="chipIcon(block.occurrence)"
                                                v-if="
                                                    chipIcon(block.occurrence)
                                                "
                                                class="size-3 shrink-0"
                                                aria-hidden="true"
                                            />
                                            <span class="truncate">
                                                {{ block.occurrence.title }}
                                            </span>
                                        </span>
                                        <span
                                            data-peek-time
                                            class="hidden max-w-full truncate text-[11px] leading-4 tabular-nums opacity-80 @min-[5.5rem]:block"
                                        >
                                            {{ blockMeta(block.occurrence) }}
                                        </span>
                                    </button>
                                </div>
                            </li>

                            <!-- Now -->
                            <li
                                v-if="nowPct !== null"
                                class="pointer-events-none absolute inset-y-0 z-10 w-0 border-l-2 border-foreground"
                                :style="{
                                    left: `calc(var(--gutter) + var(--name) + (100% - 2 * var(--gutter) - var(--name)) * ${nowPct / 100} - 1px)`,
                                }"
                                aria-hidden="true"
                            />
                        </ul>
                    </template>

                    <!-- Week: seven day cells per resource -->
                    <template v-else>
                        <div
                            class="sticky top-0 z-20 grid grid-cols-[var(--name)_repeat(7,minmax(0,1fr))] border-b border-border bg-card px-4 sm:px-6"
                        >
                            <span
                                class="self-end pb-2 text-xs text-muted-foreground"
                            >
                                {{ shown.length }}
                                {{
                                    shown.length === 1 ? nouns.one : nouns.many
                                }}
                            </span>
                            <button
                                v-for="d in week"
                                :key="d.toISOString()"
                                type="button"
                                class="flex items-baseline gap-1.5 rounded-md px-2 pt-2 pb-1.5 text-left outline-none hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring/60"
                                :aria-label="`${format(d, 'EEEE, MMMM d')}, open day`"
                                @click="openDay(d)"
                            >
                                <span
                                    class="text-xs"
                                    :class="
                                        isPastDay(d)
                                            ? 'text-muted-foreground/70'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ format(d, 'EEE') }}
                                </span>
                                <span
                                    class="text-sm font-semibold tabular-nums"
                                    :class="[
                                        isSameDay(d, now) &&
                                            'rounded-full bg-primary px-1.5 text-primary-foreground',
                                        isPastDay(d) && 'text-muted-foreground',
                                    ]"
                                >
                                    {{ format(d, 'd') }}
                                </span>
                            </button>
                        </div>

                        <ul class="px-4 sm:px-6">
                            <li
                                v-for="resource in shown"
                                :key="resource.id"
                                class="group/row grid grid-cols-[var(--name)_repeat(7,minmax(0,1fr))] border-b border-border last:border-b-0"
                            >
                                <div
                                    class="relative flex min-w-0 items-start gap-2.5 py-2.5 pr-3"
                                >
                                    <span
                                        class="mt-1 size-2.5 shrink-0 rounded-full"
                                        :style="{
                                            backgroundColor:
                                                resource.color ?? '#d4d8dd',
                                        }"
                                        aria-hidden="true"
                                    />
                                    <div
                                        class="min-w-0 flex-1"
                                        :title="details(resource) || undefined"
                                    >
                                        <p
                                            class="truncate text-[13px] font-medium"
                                        >
                                            {{ resource.name }}
                                        </p>
                                        <p
                                            v-if="status"
                                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                                        >
                                            <span
                                                class="size-1.5 shrink-0 rounded-full"
                                                :class="
                                                    status.get(
                                                        resource.calendar_id,
                                                    )?.free
                                                        ? 'bg-success'
                                                        : 'bg-foreground/60'
                                                "
                                                aria-hidden="true"
                                            />
                                            <span class="truncate">
                                                {{
                                                    status.get(
                                                        resource.calendar_id,
                                                    )?.label
                                                }}
                                            </span>
                                        </p>
                                        <p
                                            v-else-if="details(resource)"
                                            class="truncate text-xs text-muted-foreground"
                                        >
                                            {{ details(resource) }}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        :class="iconButton"
                                        class="absolute right-2 size-7 bg-card opacity-0 group-hover/row:opacity-100 hover:bg-muted focus-visible:opacity-100 [@media(hover:none)]:opacity-100"
                                        :aria-label="`Book ${resource.name}`"
                                        @click="bookFromRow(resource)"
                                    >
                                        <Plus aria-hidden="true" />
                                    </button>
                                </div>

                                <div
                                    v-for="(d, i) in week"
                                    :key="d.toISOString()"
                                    class="group/cell relative min-h-[5.25rem] min-w-0 border-l border-border px-1 pt-2 pb-1.5"
                                    :class="
                                        isPastDay(d)
                                            ? 'bg-muted/35'
                                            : 'cursor-pointer transition-colors duration-150 hover:bg-muted/40'
                                    "
                                    @click="(e) => onCellClick(e, resource, d)"
                                >
                                    <div
                                        class="relative mx-0.5 mb-1.5 h-1.5 overflow-hidden rounded-full bg-muted"
                                        :aria-label="`${format(d, 'EEE')} office hours, ${cells.get(resource.calendar_id)?.[i].filter(holdsSlot).length ?? 0} booked`"
                                        role="img"
                                    >
                                        <span
                                            v-for="seg in strip(
                                                cells.get(
                                                    resource.calendar_id,
                                                )?.[i] ?? [],
                                                d,
                                            )"
                                            :key="seg.key"
                                            class="absolute inset-y-0"
                                            :class="
                                                seg.waiting
                                                    ? 'desk-hatch'
                                                    : 'bg-muted-foreground/65'
                                            "
                                            :style="{
                                                left: `${seg.left}%`,
                                                width: `${seg.width}%`,
                                            }"
                                        />
                                    </div>

                                    <ul class="space-y-0.5">
                                        <li
                                            v-for="o in (
                                                cells.get(
                                                    resource.calendar_id,
                                                )?.[i] ?? []
                                            ).slice(0, CHIPS)"
                                            :key="o.occurrence_id"
                                        >
                                            <button
                                                type="button"
                                                data-event
                                                data-peek="clipped"
                                                class="ev-pill @container relative flex h-[22px] w-full items-center gap-1 rounded-[5px] px-1 text-left text-[11.5px] leading-none font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                :class="[
                                                    chipFill(o),
                                                    clashes.has(
                                                        o.occurrence_id,
                                                    ) &&
                                                        'outline-2 -outline-offset-1 outline-destructive',
                                                ]"
                                                :style="tone(o)"
                                                :aria-label="describe(o)"
                                                @click="emit('open', o)"
                                                @pointerenter="
                                                    (e) => peekEnter(e, o)
                                                "
                                                @pointerleave="peekClose()"
                                                @focus="(e) => peekFocus(e, o)"
                                                @blur="peekClose()"
                                            >
                                                <span
                                                    data-peek-time
                                                    data-peek-lead
                                                    class="hidden shrink-0 tabular-nums opacity-75 @[6.5rem]:inline"
                                                >
                                                    {{ cellTime(o, d) }}
                                                </span>
                                                <span class="bk-title truncate">
                                                    {{ o.title }}
                                                </span>
                                            </button>
                                        </li>
                                    </ul>

                                    <button
                                        v-if="
                                            (cells.get(resource.calendar_id)?.[
                                                i
                                            ].length ?? 0) > CHIPS
                                        "
                                        type="button"
                                        class="mt-0.5 rounded-sm px-1 text-[11px] text-muted-foreground tabular-nums outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                                        @click="openDay(d)"
                                    >
                                        +{{
                                            (cells.get(resource.calendar_id)?.[
                                                i
                                            ].length ?? 0) - CHIPS
                                        }}
                                        more
                                    </button>

                                    <span
                                        v-if="!isPastDay(d)"
                                        class="pointer-events-none absolute right-1.5 bottom-1.5 flex items-center gap-0.5 text-[11px] text-muted-foreground opacity-0 transition-opacity duration-150 group-hover/cell:opacity-100"
                                        aria-hidden="true"
                                    >
                                        <Plus class="size-3" />
                                        Book
                                    </span>
                                </div>
                            </li>
                        </ul>
                    </template>
                </div>

                <!-- Phones: the focused day as a list per resource -->
                <div class="@2xl:hidden">
                    <div
                        class="grid grid-cols-7 gap-1 border-b border-border px-3 pb-2"
                        role="group"
                        aria-label="Pick a day"
                    >
                        <button
                            v-for="d in week"
                            :key="d.toISOString()"
                            type="button"
                            class="flex h-14 flex-col items-center justify-center gap-0.5 rounded-xl text-xs outline-none focus-visible:ring-2 focus-visible:ring-ring/60"
                            :class="
                                isSameDay(d, day)
                                    ? 'bg-primary text-primary-foreground'
                                    : isPastDay(d)
                                      ? 'text-muted-foreground'
                                      : 'hover:bg-muted'
                            "
                            :aria-pressed="isSameDay(d, day)"
                            :aria-label="format(d, 'EEEE, MMMM d')"
                            @click="grid.setFocusDate(new Date(d))"
                        >
                            <span class="opacity-75">{{
                                format(d, 'EEEEE')
                            }}</span>
                            <span
                                class="text-sm font-semibold tabular-nums"
                                :class="{
                                    underline:
                                        isSameDay(d, now) && !isSameDay(d, day),
                                }"
                            >
                                {{ format(d, 'd') }}
                            </span>
                            <span
                                class="size-1 rounded-full"
                                :class="
                                    busyOn(d) ? 'bg-current' : 'bg-transparent'
                                "
                                aria-hidden="true"
                            />
                        </button>
                    </div>

                    <div
                        :key="periodKey"
                        class="sched-in"
                        :style="{ '--dir': direction }"
                    >
                        <section
                            v-for="resource in shown"
                            :key="resource.id"
                            class="border-b border-border px-4 py-3 last:border-b-0"
                            :aria-label="resource.name"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="size-2.5 shrink-0 rounded-full"
                                    :style="{
                                        backgroundColor:
                                            resource.color ?? '#d4d8dd',
                                    }"
                                    aria-hidden="true"
                                />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">
                                        {{ resource.name }}
                                    </p>
                                    <p
                                        v-if="status && isSameDay(day, now)"
                                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                                    >
                                        <span
                                            class="size-1.5 shrink-0 rounded-full"
                                            :class="
                                                status.get(resource.calendar_id)
                                                    ?.free
                                                    ? 'bg-success'
                                                    : 'bg-foreground/60'
                                            "
                                            aria-hidden="true"
                                        />
                                        {{
                                            status.get(resource.calendar_id)
                                                ?.label
                                        }}
                                    </p>
                                    <p
                                        v-else-if="details(resource)"
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ details(resource) }}
                                    </p>
                                </div>
                                <button
                                    v-if="!isPastDay(day)"
                                    type="button"
                                    :class="pillQuiet"
                                    class="h-9"
                                    :aria-label="`Book ${resource.name}`"
                                    @click="
                                        emit('book', resource.id, day, null)
                                    "
                                >
                                    <Plus aria-hidden="true" />
                                    Book
                                </button>
                            </div>

                            <ul class="mt-2.5 space-y-1.5">
                                <li
                                    v-for="row in agenda(resource)"
                                    :key="
                                        row.kind === 'booking'
                                            ? row.occurrence.occurrence_id
                                            : `free-${row.at}`
                                    "
                                >
                                    <button
                                        v-if="row.kind === 'booking'"
                                        type="button"
                                        data-event
                                        class="ev-pill relative flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-1.5 text-left text-[13px] focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        :class="[
                                            chipFill(row.occurrence),
                                            clashes.has(
                                                row.occurrence.occurrence_id,
                                            ) &&
                                                'outline-2 -outline-offset-1 outline-destructive',
                                        ]"
                                        :style="tone(row.occurrence)"
                                        :aria-label="describe(row.occurrence)"
                                        @click="emit('open', row.occurrence)"
                                    >
                                        <span
                                            class="shrink-0 text-xs font-medium tabular-nums"
                                        >
                                            {{ range(row.occurrence) }}
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span
                                                class="bk-title block truncate font-medium"
                                            >
                                                {{ row.occurrence.title }}
                                            </span>
                                            <span
                                                v-if="who(row.occurrence)"
                                                class="block truncate text-xs opacity-80"
                                            >
                                                {{ who(row.occurrence) }}
                                            </span>
                                        </span>
                                        <span
                                            v-if="
                                                row.occurrence.booking &&
                                                row.occurrence.booking
                                                    .status !== 'approved'
                                            "
                                            class="shrink-0 text-xs"
                                        >
                                            {{
                                                clashes.has(
                                                    row.occurrence
                                                        .occurrence_id,
                                                )
                                                    ? 'Clash'
                                                    : BOOKING_STATE[
                                                          row.occurrence.booking
                                                              .status
                                                      ].label
                                            }}
                                        </span>
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        class="flex min-h-11 w-full items-center gap-2 rounded-lg border border-dashed border-border px-3 text-left text-[13px] text-muted-foreground outline-none hover:border-foreground/30 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                                        @click="
                                            emit(
                                                'book',
                                                resource.id,
                                                row.start,
                                                row.end,
                                            )
                                        "
                                    >
                                        <span class="tabular-nums">
                                            Free
                                            {{ format(row.start, 'HH:mm') }}–{{
                                                format(row.end, 'HH:mm')
                                            }}
                                        </span>
                                        <Plus
                                            class="ml-auto size-4"
                                            aria-hidden="true"
                                        />
                                        <span class="sr-only">, book it</span>
                                    </button>
                                </li>
                                <li
                                    v-if="agenda(resource).length === 0"
                                    class="text-[13px] text-muted-foreground"
                                >
                                    Nothing booked.
                                </li>
                            </ul>
                        </section>
                    </div>
                </div>

                <!-- Key -->
                <p
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-border px-4 py-3 text-xs text-muted-foreground sm:px-6"
                >
                    <span class="inline-flex items-center gap-1.5">
                        <span
                            class="ev-wash inline-block h-3 w-5 rounded-[3px]"
                            :style="{
                                '--ev': '#c3c8cf',
                                '--wash': eventWash('#c3c8cf'),
                            }"
                            aria-hidden="true"
                        />
                        Approved
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span
                            class="bk-hold inline-block h-3 w-5 rounded-[3px]"
                            style="--ev: var(--muted-foreground)"
                            aria-hidden="true"
                        />
                        Waiting for approval
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span
                            class="inline-block h-3 w-5 rounded-[3px] outline-1 -outline-offset-1 outline-muted-foreground outline-dashed"
                            aria-hidden="true"
                        />
                        Rejected or cancelled
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span
                            class="inline-block h-3 w-5 rounded-[3px] outline-2 -outline-offset-1 outline-destructive"
                            aria-hidden="true"
                        />
                        Clash
                    </span>
                    <span v-if="othersInGray"
                        >Gray: booked by someone else</span
                    >
                    <span v-if="nothingBooked" class="sm:ml-auto">
                        Nothing booked {{ isWeek ? 'this week' : 'this day' }}.
                    </span>
                </p>
            </template>
        </div>
    </div>
</template>

<style scoped>
/* The new day or week slides in from the side it came from; a view switch
   just settles in place. Short, and off under reduced motion. */
@media (prefers-reduced-motion: no-preference) {
    .sched-in {
        animation: sched-in 220ms cubic-bezier(0.25, 1, 0.5, 1) both;
    }

    @keyframes sched-in {
        from {
            opacity: 0.4;
            transform: translateX(calc(var(--dir, 0) * 12px));
        }
    }
}
</style>
