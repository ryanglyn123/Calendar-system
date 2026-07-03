<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { layoutWeekLanes } from '@/components/calendar/monthLanes';
import {
    addDays,
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

const props = defineProps<{
    mode: 'week' | 'day';
}>();

const emit = defineEmits<{
    createAt: [date: Date];
    open: [occurrence: EventOccurrence];
}>();

const grid = useCalendarGridStore();
const list = useCalendarListStore();

const days = computed(() =>
    props.mode === 'day'
        ? [startOfDay(grid.focusDate)]
        : weekDays(grid.focusDate, grid.weekStartsOn),
);

const visible = computed(() => {
    const start = days.value[0];
    const end = addDays(days.value[days.value.length - 1], 1);
    const visibleIds = new Set(list.visibleCalendarIds);

    return grid
        .occurrencesBetween(start, end)
        .filter((o) => visibleIds.has(o.calendar_id));
});

const laneItems = computed(() =>
    visible.value.filter((o) => {
        const start = new Date(o.starts_at);
        const end = new Date(o.ends_at);

        return o.all_day || isMultiDay(start, end);
    }),
);

const timedItems = computed(() =>
    visible.value.filter(
        (o) => !laneItems.value.includes(o),
    ),
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

const columns = computed<PositionedBox[][]>(() =>
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
                    Math.max(new Date(o.starts_at).getTime(), dayStart.getTime()),
                );
                const end = new Date(
                    Math.min(new Date(o.ends_at).getTime(), dayEnd.getTime()),
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

        const boxes = layoutDayColumn(
            items.map((i) => ({
                key: i.occurrence.occurrence_id,
                startMinutes: i.startMinutes,
                endMinutes: i.endMinutes,
            })),
        );

        const byKey = new Map(items.map((i) => [i.occurrence.occurrence_id, i]));

        return boxes.map((box) => ({
            occurrence: byKey.get(box.key)!.occurrence,
            top: (box.startMinutes / 60) * HOUR_HEIGHT,
            height: Math.max(
                20,
                ((box.endMinutes - box.startMinutes) / 60) * HOUR_HEIGHT,
            ),
            left: box.left,
            width: box.width,
        }));
    }),
);

const hours = Array.from({ length: 24 }, (_, h) => h);

const today = new Date();

const now = ref(new Date());

onMounted(() => {
    const interval = setInterval(() => {
        now.value = new Date();
    }, 60_000);

    scroller.value?.scrollTo({
        top: 7 * HOUR_HEIGHT,
    });

    return () => clearInterval(interval);
});

const scroller = ref<HTMLElement | null>(null);

const nowTop = computed(
    () => (minutesIntoDay(now.value) / 60) * HOUR_HEIGHT,
);

const onSlotClick = (day: Date, event: MouseEvent) => {
    if ((event.target as HTMLElement).closest('[data-event]')) {
        return;
    }

    const bounds = (
        event.currentTarget as HTMLElement
    ).getBoundingClientRect();

    const minutes = Math.floor(
        (((event.clientY - bounds.top) / HOUR_HEIGHT) * 60) / SNAP_MINUTES,
    ) * SNAP_MINUTES;

    const slot = startOfDay(day);
    slot.setMinutes(minutes);

    emit('createAt', slot);
};

const timeLabel = (occurrence: EventOccurrence) =>
    `${format(new Date(occurrence.starts_at), 'HH:mm')} - ${format(new Date(occurrence.ends_at), 'HH:mm')}`;
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
                                ? 'bg-gradient-accent text-white'
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
                    class="absolute z-10 truncate rounded-md px-2 text-left text-xs leading-5 font-medium text-white hover:opacity-90"
                    :class="{
                        'rounded-l-none': segment.continuesBefore,
                        'rounded-r-none': segment.continuesAfter,
                    }"
                    :style="{
                        left: `calc(${(segment.startCol / days.length) * 100}% + 4px)`,
                        width: `calc(${(segment.span / days.length) * 100}% - 8px)`,
                        top: `${4 + segment.lane * 24}px`,
                        height: '20px',
                        backgroundColor: segment.occurrence.color,
                    }"
                    @click="emit('open', segment.occurrence)"
                >
                    {{ segment.occurrence.title }}
                </button>
            </div>
        </div>

        <!-- Scrollable time grid -->
        <div ref="scroller" class="flex-1 overflow-y-auto">
            <div
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
                    class="relative cursor-pointer border-l"
                    role="gridcell"
                    :aria-label="format(day, 'EEEE, MMMM d')"
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
                    <button
                        v-for="box in columns[d]"
                        :key="box.occurrence.occurrence_id"
                        type="button"
                        data-event
                        class="absolute overflow-hidden rounded-md border-l-3 px-1.5 py-0.5 text-left text-xs transition-shadow hover:shadow-md"
                        :class="{
                            'opacity-70 line-through':
                                box.occurrence.status === 'cancelled',
                        }"
                        :style="{
                            top: `${box.top}px`,
                            height: `${box.height}px`,
                            left: `calc(${box.left * 100}% + 2px)`,
                            width: `calc(${box.width * 100}% - 4px)`,
                            backgroundColor: box.occurrence.color + '26',
                            borderLeftColor: box.occurrence.color,
                        }"
                        :aria-label="`${box.occurrence.title}, ${timeLabel(box.occurrence)}`"
                        @click="emit('open', box.occurrence)"
                    >
                        <div class="truncate font-medium">
                            {{ box.occurrence.title }}
                        </div>
                        <div
                            v-if="box.height > 32"
                            class="truncate text-[10px] text-muted-foreground"
                        >
                            {{ timeLabel(box.occurrence) }}
                        </div>
                    </button>

                    <!-- Now indicator -->
                    <div
                        v-if="isSameDay(day, now)"
                        class="pointer-events-none absolute right-0 left-0 z-20"
                        :style="{ top: `${nowTop}px` }"
                        aria-hidden="true"
                    >
                        <div class="relative border-t-2 border-fdcp-magenta">
                            <span
                                class="absolute -top-[5px] -left-1 size-2 rounded-full bg-fdcp-magenta"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
