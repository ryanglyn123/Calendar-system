<script setup lang="ts">
import { computed, ref } from 'vue';
import { format, parseISO } from '@/lib/calendarMath';
import type { DeskDemandDay } from '@/types';
import { dayLabel, hoursLabel } from './desk';
import DeskSection from './DeskSection.vue';

const props = defineProps<{
    days: DeskDemandDay[];
    /** The day the schedule shows. */
    selected: string;
    today: string;
}>();

const emit = defineEmits<{ select: [date: string] }>();

const hovered = ref<string | null>(null);

const total = (day: DeskDemandDay) => day.approved + day.pending;

/** At least an office day of scale, so one short meeting never fills a column. */
const scale = computed(() => Math.max(480, ...props.days.map(total)));

const peak = computed(() =>
    props.days.reduce<DeskDemandDay | null>(
        (best, day) =>
            total(day) > 0 && (best === null || total(day) > total(best))
                ? day
                : best,
        null,
    ),
);

const quiet = computed(() => peak.value === null);

const readoutDay = computed(
    () =>
        props.days.find((day) => day.date === hovered.value) ??
        props.days.find((day) => day.date === props.selected) ??
        peak.value,
);

const describe = (day: DeskDemandDay) => {
    const when = format(parseISO(day.date), 'EEEE, MMM d');

    if (day.count === 0) {
        return `${when}: nothing booked`;
    }

    const parts = [`${hoursLabel(day.approved)} approved`];

    if (day.pending > 0) {
        parts.push(`${hoursLabel(day.pending)} waiting`);
    }

    return `${when}: ${parts.join(', ')}, ${day.count} ${day.count === 1 ? 'booking' : 'bookings'}`;
};

/** The muted remainder of the readout, after the booked hours. */
const tail = (day: DeskDemandDay) =>
    [
        day.pending > 0 ? `, ${hoursLabel(day.pending)} pending` : '',
        ` · ${day.count} ${day.count === 1 ? 'booking' : 'bookings'}`,
        day.date === peak.value?.date ? ' · busiest day ahead' : '',
    ].join('');

const isWeekend = (day: DeskDemandDay) => {
    const weekday = parseISO(day.date).getDay();

    return weekday === 0 || weekday === 6;
};
</script>

<template>
    <DeskSection title="Next two weeks" heading-id="desk-horizon">
        <template #actions>
            <span
                class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
            >
                <span
                    class="inline-block size-2.5 rounded-[2px] bg-foreground/75"
                    aria-hidden="true"
                />
                Approved
            </span>
            <span
                class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
            >
                <span
                    class="desk-hatch inline-block size-2.5 rounded-[2px]"
                    aria-hidden="true"
                />
                Pending
            </span>
        </template>

        <p class="mb-2 min-h-5 text-[13px] tabular-nums" aria-live="polite">
            <template v-if="quiet">
                Nothing is booked in the next two weeks yet.
            </template>
            <template v-else-if="readoutDay">
                <span>{{ `${dayLabel(readoutDay.date)} · ` }}</span>
                <span
                    v-if="readoutDay.count === 0"
                    class="text-muted-foreground"
                >
                    nothing booked
                </span>
                <template v-else>
                    <span class="font-semibold">{{
                        `${hoursLabel(total(readoutDay))} booked`
                    }}</span>
                    <span class="text-muted-foreground">{{
                        tail(readoutDay)
                    }}</span>
                </template>
            </template>
        </p>

        <div
            class="grid grid-cols-14 gap-px"
            role="group"
            aria-label="Pick a day"
            @mouseleave="hovered = null"
        >
            <button
                v-for="day in days"
                :key="day.date"
                type="button"
                class="group/day flex h-28 flex-col items-center rounded-lg pt-2 pb-1.5 outline-none focus-visible:ring-2 focus-visible:ring-ring/60"
                :class="[
                    day.date === selected
                        ? 'bg-muted ring-1 ring-foreground/15 ring-inset'
                        : isWeekend(day)
                          ? 'bg-muted/25 hover:bg-muted/70'
                          : 'hover:bg-muted/70',
                ]"
                :aria-label="describe(day)"
                :aria-pressed="day.date === selected"
                @mouseenter="hovered = day.date"
                @focus="hovered = day.date"
                @blur="hovered = null"
                @click="emit('select', day.date)"
            >
                <span
                    class="flex w-full flex-1 flex-col items-center justify-end gap-[2px]"
                    aria-hidden="true"
                >
                    <span
                        v-if="day.pending > 0"
                        class="desk-hatch w-full max-w-6 rounded-t-[4px] motion-safe:transition-[height] motion-safe:duration-300"
                        :style="{ height: `${(day.pending / scale) * 100}%` }"
                    />
                    <span
                        v-if="day.approved > 0"
                        class="w-full max-w-6 bg-foreground/75 motion-safe:transition-[height] motion-safe:duration-300"
                        :class="{ 'rounded-t-[4px]': day.pending === 0 }"
                        :style="{ height: `${(day.approved / scale) * 100}%` }"
                    />
                    <span
                        v-if="total(day) === 0"
                        class="h-[2px] w-full max-w-6 rounded-full bg-border"
                    />
                </span>
                <span
                    class="mt-1.5 text-[11px] leading-none text-muted-foreground"
                    aria-hidden="true"
                >
                    {{ format(parseISO(day.date), 'EEEEE') }}
                </span>
                <span
                    class="mt-1 inline-flex size-6 items-center justify-center rounded-full text-xs leading-none tabular-nums"
                    :class="
                        day.date === selected
                            ? 'bg-primary font-semibold text-primary-foreground'
                            : day.date === today
                              ? 'font-semibold text-foreground ring-1 ring-foreground/40'
                              : 'text-foreground'
                    "
                    aria-hidden="true"
                >
                    {{ format(parseISO(day.date), 'd') }}
                </span>
            </button>
        </div>
    </DeskSection>
</template>
