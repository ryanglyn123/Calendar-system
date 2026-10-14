<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    Hourglass,
} from '@lucide/vue';
import { addDays } from 'date-fns';
import { format, parseISO } from '@/lib/calendarMath';
import { cn } from '@/lib/utils';
import adminBookings from '@/routes/admin/bookings';
import type { DeskResource, DeskWeekDay } from '@/types';
import { iconButton, isoDate, pillQuiet, timeRange } from './desk';

const props = defineProps<{
    date: string;
    today: string;
    week: DeskWeekDay[];
    resources: DeskResource[];
    /** "Attendees" or "Passengers". */
    people: string;
    /** "rooms" or "vehicles", for the empty state. */
    many: string;
    loading: boolean;
}>();

const emit = defineEmits<{ date: [date: string] }>();

const shift = (days: number) =>
    emit('date', isoDate(addDays(parseISO(props.date), days)));

const dayName = (day: DeskWeekDay) => {
    const counts =
        day.bookings === 0
            ? 'nothing booked'
            : `${day.bookings} booked${day.waiting ? `, ${day.waiting} waiting` : ''}`;

    return `${format(parseISO(day.date), 'EEEE, MMMM d')}: ${counts}`;
};
</script>

<template>
    <section aria-labelledby="desk-day-title" class="space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2
                    id="desk-day-title"
                    class="text-xl font-semibold tracking-tight"
                >
                    Schedule
                </h2>
                <p class="text-[15px] text-muted-foreground">
                    {{ format(parseISO(date), 'EEEE, MMMM d') }}
                </p>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    :class="cn(iconButton, 'size-10')"
                    aria-label="Previous week"
                    @click="shift(-7)"
                >
                    <ChevronLeft />
                </button>
                <button
                    type="button"
                    :class="cn(pillQuiet, 'h-10 px-4')"
                    :disabled="date === today"
                    @click="emit('date', today)"
                >
                    Today
                </button>
                <button
                    type="button"
                    :class="cn(iconButton, 'size-10')"
                    aria-label="Next week"
                    @click="shift(7)"
                >
                    <ChevronRight />
                </button>
            </div>
        </div>

        <!-- The week at a glance doubles as the day picker: how many
             bookings each day holds, an amber dot where some still wait. -->
        <div class="space-y-2">
            <div
                class="grid grid-cols-7 gap-1.5 sm:gap-2"
                role="group"
                aria-label="Pick a day"
            >
                <button
                    v-for="day in week"
                    :key="day.date"
                    type="button"
                    class="flex min-h-[4.5rem] flex-col items-center justify-center gap-1 rounded-2xl px-1 py-2 transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring/60"
                    :class="
                        day.date === date
                            ? 'bg-primary text-primary-foreground'
                            : day.date === today
                              ? 'ring-1 ring-border ring-inset hover:bg-muted'
                              : 'hover:bg-muted'
                    "
                    :aria-pressed="day.date === date"
                    :aria-current="day.date === today ? 'date' : undefined"
                    :aria-label="dayName(day)"
                    @click="emit('date', day.date)"
                >
                    <span class="text-xs font-medium opacity-75">
                        {{ format(parseISO(day.date), 'EEE') }}
                    </span>
                    <span
                        class="text-lg leading-none font-semibold tabular-nums"
                    >
                        {{ format(parseISO(day.date), 'd') }}
                    </span>
                    <span
                        class="flex h-4 items-center gap-1 text-xs tabular-nums"
                        aria-hidden="true"
                    >
                        <template v-if="day.bookings">
                            {{ day.bookings }}
                            <span class="hidden sm:inline">booked</span>
                            <span
                                v-if="day.waiting"
                                class="size-1.5 rounded-full bg-warning"
                            />
                        </template>
                    </span>
                </button>
            </div>
            <p
                class="flex items-center justify-end gap-1.5 text-xs text-muted-foreground"
                aria-hidden="true"
            >
                <span class="size-1.5 rounded-full bg-warning" />
                Has requests waiting
            </p>
        </div>

        <p
            v-if="!resources.length"
            class="rounded-2xl bg-muted/40 px-6 py-10 text-center text-[15px] text-muted-foreground"
        >
            No {{ many }} are set up yet.
        </p>

        <div
            v-else
            class="grid items-start gap-4 md:grid-cols-2"
            :class="{ 'opacity-60 transition-opacity': loading }"
            :aria-busy="loading"
        >
            <section
                v-for="resource in resources"
                :key="resource.id"
                class="@container overflow-hidden rounded-2xl bg-card ring-1 ring-border"
                :aria-label="resource.name"
            >
                <header class="flex items-center gap-3 px-5 py-4">
                    <span
                        class="size-3 shrink-0 rounded-full"
                        :style="{
                            background:
                                resource.color ?? 'var(--muted-foreground)',
                        }"
                        aria-hidden="true"
                    />
                    <div class="min-w-0">
                        <h3 class="leading-tight font-semibold">
                            {{ resource.name }}
                        </h3>
                        <p
                            v-if="resource.location"
                            class="text-sm text-muted-foreground"
                        >
                            {{ resource.location }}
                        </p>
                    </div>
                    <span
                        v-if="resource.bookings.length"
                        class="ml-auto shrink-0 text-sm text-muted-foreground tabular-nums"
                    >
                        {{ resource.bookings.length }} booked
                    </span>
                </header>

                <ul v-if="resource.bookings.length" class="divide-y border-t">
                    <li v-for="booking in resource.bookings" :key="booking.id">
                        <!-- Narrow card: time and status share the top line, the
                             title gets the full width below. Wide: one row. -->
                        <Link
                            :href="adminBookings.show(booking.id)"
                            class="grid grid-cols-[1fr_auto] items-start gap-x-4 gap-y-1 px-5 py-3.5 transition-colors duration-150 outline-none hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:ring-inset @md:grid-cols-[6.75rem_1fr_auto]"
                        >
                            <span
                                class="col-start-1 row-start-1 text-[15px] font-medium tabular-nums"
                            >
                                {{
                                    timeRange(
                                        booking.starts_at,
                                        booking.ends_at,
                                    )
                                }}
                            </span>
                            <span
                                class="col-span-2 row-start-2 min-w-0 @md:col-span-1 @md:col-start-2 @md:row-start-1"
                            >
                                <span
                                    class="block leading-snug font-medium [overflow-wrap:anywhere]"
                                >
                                    {{ booking.title }}
                                </span>
                                <span
                                    class="block text-sm text-muted-foreground"
                                >
                                    {{ booking.requester ?? 'Unknown' }}
                                    <template v-if="booking.attendee_count">
                                        · {{ booking.attendee_count }}
                                        {{ people.toLowerCase() }}
                                    </template>
                                </span>
                            </span>
                            <span
                                class="col-start-2 row-start-1 inline-flex items-center gap-1 justify-self-end rounded-full px-2.5 py-1 text-xs font-semibold @md:col-start-3"
                                :class="
                                    booking.status === 'approved'
                                        ? 'bg-success/10 text-success'
                                        : 'bg-warning/15 text-warning-ink'
                                "
                            >
                                <component
                                    :is="
                                        booking.status === 'approved'
                                            ? Check
                                            : Hourglass
                                    "
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{
                                    booking.status === 'approved'
                                        ? 'Approved'
                                        : 'Waiting'
                                }}
                            </span>
                        </Link>
                    </li>
                </ul>

                <p
                    v-else
                    class="flex items-center gap-2 border-t px-5 py-4 text-[15px] font-medium text-success"
                >
                    <CircleCheck class="size-[18px]" aria-hidden="true" />
                    Free all day
                </p>
            </section>
        </div>
    </section>
</template>
