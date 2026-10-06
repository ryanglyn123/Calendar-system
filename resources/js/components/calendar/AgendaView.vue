<script setup lang="ts">
import { CalendarX2 } from '@lucide/vue';
import { computed } from 'vue';
import {
    BOOKING_STATE,
    bookingTone,
    chipFill,
    chipState,
    isShown,
} from '@/lib/bookingDisplay';
import { addDays, format, isSameDay, startOfDay } from '@/lib/calendarMath';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import { useCalendarListStore } from '@/stores/calendarList';
import type { EventOccurrence } from '@/types/calendar';

const DAYS_AHEAD = 30;

const emit = defineEmits<{
    open: [occurrence: EventOccurrence];
}>();

const grid = useCalendarGridStore();
const list = useCalendarListStore();

const groups = computed(() => {
    const start = startOfDay(grid.focusDate);
    const end = addDays(start, DAYS_AHEAD);
    const visibleIds = new Set(list.visibleCalendarIds);
    const listedIds = new Set(list.calendars.map((c) => c.id));

    const occurrences = grid
        .occurrencesBetween(start, end)
        .filter((o) => isShown(o, visibleIds, listedIds));

    const byDay = new Map<string, { day: Date; items: EventOccurrence[] }>();

    for (const occurrence of occurrences) {
        const day = startOfDay(new Date(occurrence.starts_at));

        if (day < start) {
            continue;
        }

        const key = format(day, 'yyyy-MM-dd');
        const bucket = byDay.get(key) ?? { day, items: [] };
        bucket.items.push(occurrence);
        byDay.set(key, bucket);
    }

    return [...byDay.values()].sort(
        (a, b) => a.day.getTime() - b.day.getTime(),
    );
});

const today = new Date();

const timeLabel = (occurrence: EventOccurrence) =>
    occurrence.all_day
        ? 'All day'
        : `${format(new Date(occurrence.starts_at), 'HH:mm')} - ${format(new Date(occurrence.ends_at), 'HH:mm')}`;
</script>

<template>
    <div class="h-full overflow-y-auto p-4">
        <div
            v-if="groups.length === 0"
            class="flex h-full flex-col items-center justify-center gap-3 text-center"
        >
            <CalendarX2
                class="size-10 text-muted-foreground"
                aria-hidden="true"
            />
            <p class="text-sm text-muted-foreground">
                Nothing scheduled in the next {{ DAYS_AHEAD }} days.
            </p>
        </div>

        <div v-else class="mx-auto max-w-2xl space-y-6">
            <section v-for="group in groups" :key="group.day.toISOString()">
                <h2
                    class="mb-2 flex items-baseline gap-2 text-sm font-semibold"
                >
                    <span
                        class="flex size-8 items-center justify-center rounded-full text-sm"
                        :class="
                            isSameDay(group.day, today)
                                ? 'bg-gradient-accent font-semibold'
                                : 'bg-muted'
                        "
                    >
                        {{ format(group.day, 'd') }}
                    </span>
                    {{ format(group.day, 'EEEE, MMMM d') }}
                </h2>

                <ul class="space-y-1">
                    <li
                        v-for="occurrence in group.items"
                        :key="occurrence.occurrence_id"
                    >
                        <button
                            type="button"
                            data-event
                            class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left transition-colors hover:bg-muted"
                            :class="{
                                'line-through opacity-70':
                                    occurrence.status === 'cancelled' &&
                                    !occurrence.booking,
                            }"
                            :aria-label="`${chipState(occurrence)}${occurrence.title}, ${timeLabel(occurrence)}`"
                            @click="emit('open', occurrence)"
                        >
                            <span
                                class="size-3 shrink-0 rounded-full"
                                :class="
                                    occurrence.booking
                                        ? chipFill(occurrence)
                                        : ''
                                "
                                :style="{
                                    backgroundColor: occurrence.booking
                                        ? undefined
                                        : occurrence.color,
                                    '--ev':
                                        bookingTone(occurrence) ?? '#d4d8dd',
                                    '--wash':
                                        bookingTone(occurrence) ?? '#d4d8dd',
                                }"
                                aria-hidden="true"
                            />
                            <span
                                class="w-28 shrink-0 text-xs text-muted-foreground"
                            >
                                {{ timeLabel(occurrence) }}
                            </span>
                            <span
                                class="truncate text-sm font-medium"
                                :class="{
                                    'text-muted-foreground line-through':
                                        occurrence.booking &&
                                        !BOOKING_STATE[
                                            occurrence.booking.status
                                        ].active,
                                }"
                            >
                                {{ occurrence.title }}
                            </span>
                            <span
                                v-if="occurrence.booking"
                                class="ml-auto inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                            >
                                <component
                                    :is="
                                        BOOKING_STATE[occurrence.booking.status]
                                            .icon
                                    "
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{
                                    BOOKING_STATE[occurrence.booking.status]
                                        .label
                                }}
                            </span>
                            <span
                                v-if="occurrence.location"
                                class="ml-auto hidden truncate text-xs text-muted-foreground sm:block"
                            >
                                {{ occurrence.location }}
                            </span>
                        </button>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
