<script setup lang="ts">
import { computed } from 'vue';
import { timeRange } from '@/components/desk/desk';
import { eventInk, eventWash, parseISO } from '@/lib/calendarMath';

type Slot = { starts_at: string; ends_at: string };

export type StripBooking = Slot & {
    id: number;
    title: string;
    status: 'pending' | 'approved';
    requester?: string | null;
};

/**
 * One resource's day as a single lane: the slot under review in solid ink,
 * everything else the resource holds that day around it (approved as the
 * wash, pending hatched), so "is it free?" is answered at a glance. A change
 * request also shows where the booking sits now, as a dashed ghost.
 */
const props = defineProps<{
    /** Office day, Y-m-d. */
    date: string;
    bookings: StripBooking[];
    /** The slot being decided. */
    focus: Slot;
    /** The booking's own id, so it isn't drawn twice. */
    focusId?: number | null;
    /** Where the booking sits now (change requests). */
    ghost?: Slot | null;
    /** Bookings that collide with the focus slot. */
    clashIds?: number[];
    color: string | null;
    now: Date;
    /** Text on the focus block. */
    focusLabel?: string;
}>();

const dayStart = computed(() => parseISO(`${props.date}T00:00:00`).getTime());
const HOUR = 3_600_000;

const hourOf = (iso: string) =>
    (parseISO(iso).getTime() - dayStart.value) / HOUR;

/** Office hours, stretched to whatever the day actually holds. */
const frame = computed(() => {
    const slots = [
        props.focus,
        ...props.bookings,
        ...(props.ghost ? [props.ghost] : []),
    ];
    const starts = slots.map((s) => Math.max(0, hourOf(s.starts_at)));
    const ends = slots.map((s) => Math.min(24, hourOf(s.ends_at)));

    return {
        from: Math.max(0, Math.min(7, Math.floor(Math.min(...starts)))),
        to: Math.min(24, Math.max(19, Math.ceil(Math.max(...ends)))),
    };
});

const span = computed(() => frame.value.to - frame.value.from);

const place = (slot: Slot) => {
    const start = Math.max(frame.value.from, hourOf(slot.starts_at));
    const end = Math.min(frame.value.to, hourOf(slot.ends_at));

    return {
        left: `${((start - frame.value.from) / span.value) * 100}%`,
        width: `${(Math.max(end - start, 0.25) / span.value) * 100}%`,
    };
};

const others = computed(() =>
    props.bookings.filter((b) => b.id !== props.focusId),
);

/** Every other hour on long frames, so the ruler never crowds. */
const ticks = computed(() => {
    const step = span.value > 14 ? 3 : 2;
    const list: number[] = [];

    for (
        let h = Math.ceil(frame.value.from / step) * step;
        h <= frame.value.to;
        h += step
    ) {
        list.push(h);
    }

    return list;
});

const nowAt = computed(() => {
    const h = (props.now.getTime() - dayStart.value) / HOUR;

    return h > frame.value.from && h < frame.value.to
        ? `${((h - frame.value.from) / span.value) * 100}%`
        : null;
});

const tone = computed(() => ({
    '--ev': props.color ?? '#d4d8dd',
    '--wash': eventWash(props.color),
    '--ink': eventInk(props.color),
}));
</script>

<template>
    <div>
        <!-- Hour ruler -->
        <div
            class="relative h-4 text-[11px] text-muted-foreground tabular-nums"
            aria-hidden="true"
        >
            <span
                v-for="h in ticks"
                :key="h"
                class="absolute -translate-x-1/2 first:translate-x-0"
                :style="{ left: `${((h - frame.from) / span) * 100}%` }"
                >{{ String(h).padStart(2, '0') }}:00</span
            >
        </div>

        <!-- The lane -->
        <div
            class="relative mt-1 h-9 overflow-hidden rounded-md bg-muted/50 ring-1 ring-border ring-inset"
            :style="tone"
            aria-hidden="true"
        >
            <span
                v-for="h in ticks"
                :key="h"
                class="absolute inset-y-0 w-px bg-border/70"
                :style="{ left: `${((h - frame.from) / span) * 100}%` }"
            />

            <!-- Where the booking sits now (change requests) -->
            <span
                v-if="ghost"
                class="absolute inset-y-1 rounded-[5px] outline-1 -outline-offset-1 outline-muted-foreground/60 outline-dashed"
                :style="place(ghost)"
            />

            <!-- Everything else that day -->
            <span
                v-for="b in others"
                :key="b.id"
                class="absolute inset-y-1.5 overflow-hidden rounded-[5px] px-1.5 text-[11px] leading-6 font-medium whitespace-nowrap"
                :class="[
                    b.status === 'approved' ? 'ev-wash' : 'bk-hold',
                    clashIds?.includes(b.id)
                        ? 'ring-2 ring-destructive ring-offset-1 ring-offset-card'
                        : '',
                ]"
                :style="place(b)"
                >{{ b.title }}</span
            >

            <!-- The slot being decided -->
            <span
                class="ev-wash absolute inset-y-0.5 overflow-hidden rounded-[6px] px-2 text-[11px] leading-7 font-semibold whitespace-nowrap ring-2 ring-foreground"
                :style="place(focus)"
                >{{ focusLabel ?? 'This request' }}</span
            >

            <span
                v-if="nowAt"
                class="absolute inset-y-0 w-0.5 bg-destructive/70"
                :style="{ left: nowAt }"
            />
        </div>

        <!-- The same picture in words, for screen readers. -->
        <ul class="sr-only">
            <li>
                This request: {{ timeRange(focus.starts_at, focus.ends_at) }}
            </li>
            <li v-if="ghost">
                Booked now: {{ timeRange(ghost.starts_at, ghost.ends_at) }}
            </li>
            <li v-for="b in others" :key="b.id">
                {{ b.status === 'approved' ? 'Approved' : 'Pending' }}:
                {{ b.title }},
                {{ timeRange(b.starts_at, b.ends_at) }}
            </li>
            <li v-if="others.length === 0">Nothing else is booked that day.</li>
        </ul>
    </div>
</template>
