<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, ChevronLeft, ChevronRight } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import {
    addDays,
    eventInk,
    eventWash,
    format,
    parseISO,
} from '@/lib/calendarMath';
import { resourceTypeIcon } from '@/lib/resourceTypeIcon';
import resourcesPage from '@/routes/resources';
import type { DeskBooking, DeskResource, DeskSchedule } from '@/types';
import { iconButton, isoDate, pillQuiet, timeRange } from './desk';
import DeskBookingDetail from './DeskBookingDetail.vue';
import DeskSection from './DeskSection.vue';

const props = defineProps<{
    schedule: DeskSchedule;
    today: string;
    now: Date;
    /** Booking lit from the queue; everything else on the day steps back. */
    spotlight: number | null;
    loading: boolean;
}>();

const emit = defineEmits<{ date: [date: string] }>();

const ROW = 26;
const GAP = 4;
const PAD = 6;

const dayStart = computed(() => parseISO(`${props.schedule.date}T00:00:00`));
const isToday = computed(() => props.schedule.date === props.today);

const minutesOf = (iso: string) =>
    Math.round((parseISO(iso).getTime() - dayStart.value.getTime()) / 60_000);

const allBookings = computed(() =>
    props.schedule.types.flatMap((type) =>
        type.resources.flatMap((resource) => resource.bookings),
    ),
);

/** Office hours by default, stretched to whatever the day actually holds. */
const frame = computed(() => {
    let from = 7 * 60;
    let to = 19 * 60;

    allBookings.value.forEach((booking) => {
        from = Math.min(from, Math.max(0, minutesOf(booking.starts_at)));
        to = Math.max(to, Math.min(1440, minutesOf(booking.ends_at)));
    });

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
    booking: DeskBooking;
    row: number;
    left: number;
    width: number;
    clash: boolean;
};

const overlaps = (a: DeskBooking, b: DeskBooking) =>
    a.starts_at < b.ends_at && b.starts_at < a.ends_at;

/** Pack a lane greedily so overlapping holds stack instead of hiding each other. */
const lanes = computed(() => {
    const byResource = new Map<number, { blocks: Block[]; rows: number }>();

    props.schedule.types.forEach((type) =>
        type.resources.forEach((resource) => {
            const rowEnds: string[] = [];
            const blocks = [...resource.bookings]
                .sort((a, b) => a.starts_at.localeCompare(b.starts_at))
                .map((booking): Block => {
                    let row = rowEnds.findIndex(
                        (end) => end <= booking.starts_at,
                    );

                    if (row === -1) {
                        row = rowEnds.length;
                    }

                    rowEnds[row] = booking.ends_at;

                    const start = Math.max(
                        frame.value.start,
                        minutesOf(booking.starts_at),
                    );
                    const end = Math.min(
                        frame.value.start + frame.value.span,
                        minutesOf(booking.ends_at),
                    );

                    return {
                        booking,
                        row,
                        left: pct(start),
                        width: pct(end) - pct(start),
                        clash:
                            booking.status === 'pending' &&
                            resource.bookings.some(
                                (other) =>
                                    other.status === 'approved' &&
                                    overlaps(booking, other),
                            ),
                    };
                });

            byResource.set(resource.id, {
                blocks,
                rows: Math.max(1, rowEnds.length),
            });
        }),
    );

    return byResource;
});

const laneHeight = (resource: DeskResource) => {
    const rows = lanes.value.get(resource.id)?.rows ?? 1;

    return rows * ROW + (rows - 1) * GAP + PAD * 2;
};

const nowPct = computed(() => {
    if (!isToday.value) {
        return null;
    }

    const minutes = props.now.getHours() * 60 + props.now.getMinutes();
    const position = pct(minutes);

    return position >= 0 && position <= 100 ? position : null;
});

/**
 * Everything else steps back only when the lit block is actually on screen;
 * dimming the lanes for a hold below the fold would show nothing useful.
 */
const spotInView = ref(false);

watch(
    () => props.spotlight,
    async (id) => {
        spotInView.value = false;

        if (id === null) {
            return;
        }

        await nextTick();

        const rect = document
            .querySelector(`[data-desk-booking="${id}"]`)
            ?.getBoundingClientRect();

        spotInView.value =
            rect !== undefined &&
            rect.width > 0 &&
            rect.bottom > 0 &&
            rect.top < window.innerHeight;
    },
);

// ponytail: fixed 50-minute clearance, tuned for 39-75px per hour; measure the stamp if lanes get much narrower.
/** Hour labels the now stamp would cover. */
const nearNow = (hour: number) =>
    nowPct.value !== null &&
    Math.abs(hour * 60 - (props.now.getHours() * 60 + props.now.getMinutes())) <
        50;

const collapsed = reactive(new Set<number>());

const toggle = (id: number) =>
    collapsed.has(id) ? collapsed.delete(id) : collapsed.add(id);

const title = computed(() =>
    isToday.value
        ? "Today's schedule"
        : `Schedule for ${format(dayStart.value, 'EEE, MMM d')}`,
);

const step = (days: number) =>
    emit('date', isoDate(addDays(dayStart.value, days)));

const onPick = (event: Event) => {
    const value = (event.target as HTMLInputElement).value;

    if (value) {
        emit('date', value);
    }
};

const blockStyle = (block: Block, resource: DeskResource) => ({
    left: `calc(${block.left}% + 1px)`,
    width: `max(calc(${block.width}% - 2px), 1.75rem)`,
    top: `${PAD + block.row * (ROW + GAP)}px`,
    height: `${ROW}px`,
    '--ev': resource.color ?? '#d4d8dd',
    '--wash': eventWash(resource.color),
    '--ink': eventInk(resource.color),
});

const describe = (booking: DeskBooking, resource: DeskResource) =>
    `${booking.title}, ${booking.status === 'approved' ? 'approved' : 'waiting'}, ${timeRange(booking.starts_at, booking.ends_at)}, ${resource.name}`;

const booked = (resources: DeskResource[]) =>
    resources.filter((resource) => resource.bookings.length > 0).length;

const popoverClass =
    'z-50 w-80 max-w-[calc(100vw-2rem)] rounded-xl bg-popover p-4 text-popover-foreground shadow-[0_12px_32px_-12px_oklch(0.25_0.02_250/0.35),0_0_0_1px_var(--border)] outline-none data-[state=open]:motion-safe:animate-in data-[state=open]:motion-safe:fade-in-0 data-[state=open]:motion-safe:zoom-in-95';
</script>

<template>
    <DeskSection
        :title="title"
        :count="allBookings.length"
        heading-id="desk-schedule"
    >
        <template #actions>
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    :class="iconButton"
                    aria-label="Previous day"
                    @click="step(-1)"
                >
                    <ChevronLeft aria-hidden="true" />
                </button>
                <button
                    type="button"
                    :class="pillQuiet"
                    :disabled="isToday"
                    @click="emit('date', today)"
                >
                    Today
                </button>
                <button
                    type="button"
                    :class="iconButton"
                    aria-label="Next day"
                    @click="step(1)"
                >
                    <ChevronRight aria-hidden="true" />
                </button>
                <label class="sr-only" for="desk-date">Go to a date</label>
                <input
                    id="desk-date"
                    type="date"
                    :value="schedule.date"
                    class="ml-1 h-8 rounded-full bg-transparent px-3 text-[13px] tabular-nums ring-1 ring-border outline-none ring-inset focus-visible:ring-2 focus-visible:ring-ring/60"
                    @change="onPick"
                />
            </div>
        </template>

        <div
            v-if="schedule.types.length === 0"
            class="rounded-xl bg-muted/50 px-4 py-4 text-sm"
        >
            <p class="font-medium">No rooms or vehicles are bookable yet.</p>
            <p class="text-[13px] text-muted-foreground">
                Once a resource type and its resources are active, each one gets
                a lane here with the day's bookings.
            </p>
        </div>

        <div
            v-else
            class="transition-opacity duration-200"
            :class="{ 'opacity-50': loading }"
            :aria-busy="loading"
        >
            <!-- Lanes: wide screens -->
            <div
                class="relative hidden [--name:10.5rem] sm:block lg:[--name:13rem]"
            >
                <!-- Hour ruler -->
                <div
                    class="grid grid-cols-[var(--name)_1fr] border-b border-border pb-1"
                    aria-hidden="true"
                >
                    <span />
                    <div class="relative h-4">
                        <span
                            v-for="(hour, index) in frame.hours"
                            :key="hour"
                            class="absolute -translate-x-1/2 text-[11px] text-muted-foreground tabular-nums first:translate-x-0"
                            :class="{ invisible: nearNow(hour) }"
                            :style="{
                                left: `${(index / frame.hours.length) * 100}%`,
                            }"
                        >
                            {{ String(hour).padStart(2, '0') }}:00
                        </span>
                    </div>
                </div>

                <div
                    v-for="type in schedule.types"
                    :key="type.id"
                    class="border-b border-border last:border-b-0"
                >
                    <div class="flex items-center justify-between gap-3 py-2">
                        <button
                            type="button"
                            class="group/type -ml-1 inline-flex items-center gap-2 rounded-full py-1 pr-2 pl-1 text-[13px] font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/60"
                            :aria-expanded="!collapsed.has(type.id)"
                            @click="toggle(type.id)"
                        >
                            <ChevronDown
                                class="size-3.5 text-muted-foreground transition-transform duration-200"
                                :class="{
                                    '-rotate-90': collapsed.has(type.id),
                                }"
                                aria-hidden="true"
                            />
                            <component
                                :is="resourceTypeIcon(type)"
                                class="size-3.5 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{ type.name }}
                            <span
                                class="font-normal text-muted-foreground tabular-nums"
                            >
                                {{ booked(type.resources) }} of
                                {{ type.resources.length }} booked
                            </span>
                        </button>
                        <Link
                            :href="resourcesPage.section(type.slug)"
                            class="rounded-sm text-[13px] text-muted-foreground underline-offset-4 outline-none hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring/60"
                        >
                            Open calendar
                        </Link>
                    </div>

                    <ul v-if="!collapsed.has(type.id)" class="pb-2">
                        <li
                            v-for="resource in type.resources"
                            :key="resource.id"
                            class="grid grid-cols-[var(--name)_1fr] items-stretch"
                        >
                            <div class="flex min-w-0 items-center pr-3">
                                <p class="truncate text-[13px] font-medium">
                                    {{ resource.name }}
                                </p>
                            </div>
                            <div
                                class="relative rounded-md bg-muted/40 bg-[linear-gradient(to_right,var(--border)_1px,transparent_1px)]"
                                :style="{
                                    height: `${laneHeight(resource)}px`,
                                    backgroundSize: `calc(100% / ${frame.hours.length}) 100%`,
                                }"
                            >
                                <PopoverRoot
                                    v-for="block in lanes.get(resource.id)
                                        ?.blocks ?? []"
                                    :key="block.booking.id"
                                >
                                    <PopoverTrigger as-child>
                                        <button
                                            type="button"
                                            class="@container absolute flex items-center gap-1.5 overflow-hidden rounded-[6px] px-2 text-left text-xs font-medium whitespace-nowrap transition-[opacity,box-shadow] duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1"
                                            :class="[
                                                block.booking.status ===
                                                'approved'
                                                    ? 'ev-wash'
                                                    : 'bk-hold',
                                                block.clash &&
                                                    'outline-2 -outline-offset-1 outline-destructive',
                                                spotInView &&
                                                    spotlight !==
                                                        block.booking.id &&
                                                    'opacity-35',
                                                spotlight ===
                                                    block.booking.id &&
                                                    'z-10 ring-2 ring-foreground ring-offset-2 ring-offset-card',
                                            ]"
                                            :style="blockStyle(block, resource)"
                                            :data-desk-booking="
                                                block.booking.id
                                            "
                                            :aria-label="
                                                describe(
                                                    block.booking,
                                                    resource,
                                                )
                                            "
                                        >
                                            <span
                                                class="hidden shrink-0 tabular-nums opacity-75 @min-[9rem]:inline"
                                            >
                                                {{
                                                    timeRange(
                                                        block.booking.starts_at,
                                                        block.booking.ends_at,
                                                    ).split('–')[0]
                                                }}
                                            </span>
                                            <span class="truncate">
                                                {{ block.booking.title }}
                                            </span>
                                        </button>
                                    </PopoverTrigger>
                                    <PopoverPortal>
                                        <PopoverContent
                                            side="bottom"
                                            align="start"
                                            :side-offset="6"
                                            :collision-padding="16"
                                            :class="popoverClass"
                                        >
                                            <DeskBookingDetail
                                                :booking="block.booking"
                                                :resource="resource"
                                                :type="type"
                                                :clash="block.clash"
                                            />
                                        </PopoverContent>
                                    </PopoverPortal>
                                </PopoverRoot>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Now -->
                <div
                    v-if="nowPct !== null"
                    class="pointer-events-none absolute top-0 bottom-0 z-20 w-0 border-l-2 border-foreground"
                    :style="{
                        left: `calc(var(--name) + (100% - var(--name)) * ${nowPct / 100})`,
                    }"
                    aria-hidden="true"
                >
                    <span
                        class="absolute -top-0.5 left-[-1px] -translate-x-1/2 rounded-[4px] bg-foreground px-1 text-[11px] leading-4 font-semibold text-background tabular-nums"
                    >
                        {{ format(now, 'HH:mm') }}
                    </span>
                </div>
            </div>

            <!-- Agenda: phones -->
            <div class="space-y-4 sm:hidden">
                <div v-for="type in schedule.types" :key="type.id">
                    <p
                        class="mb-1 flex items-center gap-2 text-[13px] font-medium"
                    >
                        <component
                            :is="resourceTypeIcon(type)"
                            class="size-3.5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        {{ type.name }}
                        <span class="font-normal text-muted-foreground">
                            {{ booked(type.resources) }} of
                            {{ type.resources.length }} booked
                        </span>
                    </p>
                    <ul class="divide-y divide-border border-y border-border">
                        <li
                            v-for="resource in type.resources.filter(
                                (r) => r.bookings.length,
                            )"
                            :key="resource.id"
                            class="py-2"
                        >
                            <p class="text-[13px] font-medium">
                                {{ resource.name }}
                            </p>
                            <ul class="mt-1 space-y-1">
                                <li
                                    v-for="block in lanes.get(resource.id)
                                        ?.blocks ?? []"
                                    :key="block.booking.id"
                                >
                                    <PopoverRoot>
                                        <PopoverTrigger as-child>
                                            <button
                                                type="button"
                                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                :class="[
                                                    block.booking.status ===
                                                    'approved'
                                                        ? 'ev-wash'
                                                        : 'bk-hold',
                                                    block.clash &&
                                                        'outline-2 -outline-offset-1 outline-destructive',
                                                ]"
                                                :style="{
                                                    '--ev':
                                                        resource.color ??
                                                        '#d4d8dd',
                                                    '--wash': eventWash(
                                                        resource.color,
                                                    ),
                                                    '--ink': eventInk(
                                                        resource.color,
                                                    ),
                                                }"
                                            >
                                                <span
                                                    class="shrink-0 tabular-nums"
                                                >
                                                    {{
                                                        timeRange(
                                                            block.booking
                                                                .starts_at,
                                                            block.booking
                                                                .ends_at,
                                                        )
                                                    }}
                                                </span>
                                                <span class="truncate">
                                                    {{ block.booking.title }}
                                                </span>
                                                <span
                                                    v-if="
                                                        block.booking.status ===
                                                        'pending'
                                                    "
                                                    class="ml-auto shrink-0 font-normal"
                                                >
                                                    Waiting
                                                </span>
                                            </button>
                                        </PopoverTrigger>
                                        <PopoverPortal>
                                            <PopoverContent
                                                side="bottom"
                                                align="start"
                                                :side-offset="6"
                                                :collision-padding="16"
                                                :class="popoverClass"
                                            >
                                                <DeskBookingDetail
                                                    :booking="block.booking"
                                                    :resource="resource"
                                                    :type="type"
                                                    :clash="block.clash"
                                                />
                                            </PopoverContent>
                                        </PopoverPortal>
                                    </PopoverRoot>
                                </li>
                            </ul>
                        </li>
                        <li
                            v-if="
                                booked(type.resources) < type.resources.length
                            "
                            class="py-2 text-[13px] text-muted-foreground"
                        >
                            Free all day:
                            {{
                                type.resources
                                    .filter((r) => r.bookings.length === 0)
                                    .map((r) => r.name)
                                    .join(', ')
                            }}
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Key -->
            <p
                class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
            >
                <span class="inline-flex items-center gap-1.5">
                    <span
                        class="inline-block h-3 w-5 rounded-[3px] bg-muted-foreground/30"
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
                    Pending
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span
                        class="inline-block h-3 w-5 rounded-[3px] outline-2 -outline-offset-1 outline-destructive"
                        aria-hidden="true"
                    />
                    Clash
                </span>
                <span v-if="allBookings.length === 0">
                    · Nothing booked this day.
                </span>
            </p>
        </div>
    </DeskSection>
</template>
