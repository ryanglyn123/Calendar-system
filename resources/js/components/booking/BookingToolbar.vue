<script lang="ts">
/** A request waiting on this reviewer, as the section page inlines it. */
export type ReviewItem = {
    id: number;
    title: string;
    resource: string;
    resource_id: number;
    requester: string | null;
    starts_at: string;
    ends_at: string;
    passed: boolean;
    clash: 'approved' | 'pending' | null;
};
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, ChevronLeft, ChevronRight, Plus } from '@lucide/vue';
import { defaultDocument, useEventListener, useIntervalFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import type { BookableResource } from '@/components/booking/ResourceSchedule.vue';
import MiniMonth from '@/components/calendar/MiniMonth.vue';
import type { DayMark } from '@/components/calendar/MiniMonth.vue';
import { iconButton, pillPrimary, pillQuiet } from '@/components/desk/desk';
import LiquidSegments from '@/components/LiquidSegments.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { liveStatus } from '@/lib/bookingDisplay';
import {
    addDays,
    addMonths,
    format,
    isSameDay,
    isSameMonth,
    parseISO,
    startOfDay,
    weekDays,
} from '@/lib/calendarMath';
import { resourceTypeIcon } from '@/lib/resourceTypeIcon';
import adminBookings from '@/routes/admin/bookings';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import { useCalendarListStore } from '@/stores/calendarList';
import type { CalendarView } from '@/types/calendar';

/**
 * The top of a booking section, read as two lines. The first says what is
 * on screen (the type, which of its rooms or vehicles, how many are free
 * right now) and what waits on the viewer. The second is the period: the
 * date is the headline, with its steppers, the view and the one primary
 * action beside it.
 */
const props = defineProps<{
    type: { name: string; slug: string; icon: string | null };
    resources: BookableResource[];
    nouns: { one: string; many: string; action: string };
    review: { count: number; items: ReviewItem[] } | null;
    mine: { total: number; pending: number };
    /** Busy days, for the date picker's mini calendar. */
    mark?: (day: Date) => DayMark | null;
}>();

const emit = defineEmits<{
    book: [];
    showMine: [];
    showRequest: [item: ReviewItem];
}>();

const grid = useCalendarGridStore();
const list = useCalendarListStore();

/* ---- What is on screen ------------------------------------------------- */

const filterLabel = computed(() => {
    const total = props.resources.length;
    const one = props.resources.find((r) => r.calendar_id === list.picked);

    if (one) {
        return one.name;
    }

    return total === 1
        ? props.resources[0].name
        : `All ${total} ${props.nouns.many}`;
});

/** The phone and tablet picker; wide screens pick from the side panel. */
const pick = computed({
    get: () => (list.picked === null ? 'all' : String(list.picked)),
    set: (value: string) => {
        list.picked = value === 'all' ? null : Number(value);
    },
});

/** Free right now across the whole type, filter or not. */
const now = ref(new Date());

useIntervalFn(() => (now.value = new Date()), 60_000);

const freeNow = computed(() => {
    const today = startOfDay(now.value);
    const items = grid.occurrencesBetween(today, addDays(today, 1));

    return props.resources.filter(
        (r) =>
            liveStatus(
                items.filter((o) => o.calendar_id === r.calendar_id),
                now.value,
            ).free,
    ).length;
});

const freeLabel = computed(() => {
    const total = props.resources.length;

    if (freeNow.value === total) {
        return total === 1 ? 'Free now' : `All ${total} free now`;
    }

    return freeNow.value === 0
        ? 'None free now'
        : `${freeNow.value} of ${total} free now`;
});

/* ---- The period ---------------------------------------------------------- */

const views: { value: CalendarView; label: string; key: string }[] = [
    { value: 'day', label: 'Day', key: 'd' },
    { value: 'week', label: 'Week', key: 'w' },
    { value: 'month', label: 'Month', key: 'm' },
];

const thisYear = new Date().getFullYear();

const title = computed(() => {
    const focus = grid.focusDate;
    const year = (d: Date) =>
        d.getFullYear() === thisYear ? '' : `, ${d.getFullYear()}`;

    if (grid.view === 'day') {
        return `${format(focus, 'EEEE, MMMM d')}${year(focus)}`;
    }

    if (grid.view === 'week') {
        const days = weekDays(focus, grid.weekStartsOn);
        const [first, last] = [days[0], days[6]];

        if (isSameMonth(first, last)) {
            return `${format(first, 'MMMM d')}–${format(last, 'd')}${year(last)}`;
        }

        return first.getFullYear() === last.getFullYear()
            ? `${format(first, 'MMM d')} – ${format(last, 'MMM d')}${year(last)}`
            : `${format(first, 'MMM d, yyyy')} – ${format(last, 'MMM d, yyyy')}`;
    }

    return format(focus, 'MMMM yyyy');
});

/** Phones get the day in short so the steppers fit beside it. */
const shortTitle = computed(() =>
    grid.view === 'day'
        ? `${format(grid.focusDate, 'EEE, MMM d')}${grid.focusDate.getFullYear() === thisYear ? '' : `, ${grid.focusDate.getFullYear()}`}`
        : title.value,
);

/** Whether today is already on screen, so "Today" has nothing to do. */
const showingToday = computed(() => {
    const today = new Date();

    if (grid.view === 'day') {
        return isSameDay(grid.focusDate, today);
    }

    if (grid.view === 'week') {
        return weekDays(grid.focusDate, grid.weekStartsOn).some((d) =>
            isSameDay(d, today),
        );
    }

    return isSameMonth(grid.focusDate, today);
});

const step = (direction: 1 | -1) => {
    const focus = grid.focusDate;

    grid.setFocusDate(
        grid.view === 'day'
            ? addDays(focus, direction)
            : grid.view === 'week'
              ? addDays(focus, 7 * direction)
              : addMonths(focus, direction),
    );
};

const stepLabel = computed(
    () =>
        ({ day: 'day', week: 'week' })[grid.view as 'day' | 'week'] ?? 'month',
);

/** The date picker closes once a day is picked. */
const pickerOpen = ref(false);

watch(
    () => grid.focusDate,
    () => (pickerOpen.value = false),
);

// D / W / M switch the view and T goes to today, unless the user is typing
// or a menu or dialog is open on top.
useEventListener(defaultDocument, 'keydown', (e: KeyboardEvent) => {
    const target = e.target as HTMLElement | null;

    if (
        e.ctrlKey ||
        e.metaKey ||
        e.altKey ||
        target?.closest('input, textarea, select, [contenteditable]') ||
        document.querySelector(
            ':is([role="dialog"], [role="alertdialog"], [role="menu"])[data-state="open"]',
        )
    ) {
        return;
    }

    const key = e.key.toLowerCase();
    const view = views.find((v) => v.key === key);

    if (view) {
        grid.setView(view.value);
    } else if (key === 't') {
        grid.goToday();
    }
});

/* ---- Waiting on the viewer ----------------------------------------------- */

const reviewOpen = ref(false);

const colorOf = (item: ReviewItem) =>
    props.resources.find((r) => r.id === item.resource_id)?.color ?? '#d4d8dd';

const when = (item: ReviewItem) => {
    const start = parseISO(item.starts_at);
    const end = parseISO(item.ends_at);

    return `${format(start, 'EEE, MMM d')} · ${format(start, 'HH:mm')}–${format(end, isSameDay(start, end) ? 'HH:mm' : 'MMM d, HH:mm')}`;
};

const showRequest = (item: ReviewItem) => {
    reviewOpen.value = false;
    emit('showRequest', item);
};
</script>

<template>
    <header class="px-4 pt-4 pb-3 sm:px-6 sm:pt-5">
        <!-- What is on screen, and what waits on the viewer -->
        <div
            class="flex flex-wrap items-center gap-x-2 gap-y-1.5 text-[13px] text-muted-foreground"
        >
            <component
                :is="resourceTypeIcon(type)"
                class="size-4 shrink-0 text-foreground/70"
                aria-hidden="true"
            />
            <span class="font-medium text-foreground">{{ type.name }}</span>

            <template v-if="resources.length > 1">
                <span aria-hidden="true">/</span>
                <span class="hidden font-medium text-foreground/85 xl:inline">
                    {{ filterLabel }}
                </span>
                <DropdownMenu>
                    <DropdownMenuTrigger
                        class="-mx-1.5 inline-flex h-7 items-center gap-1 rounded-full px-1.5 font-medium text-foreground/85 outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60 data-[state=open]:bg-muted xl:hidden"
                        :aria-label="`Showing ${filterLabel}. Choose a ${nouns.one}`"
                    >
                        {{ filterLabel }}
                        <ChevronDown class="size-3.5" aria-hidden="true" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-64">
                        <DropdownMenuRadioGroup v-model="pick">
                            <DropdownMenuRadioItem value="all">
                                All {{ nouns.many }}
                            </DropdownMenuRadioItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuRadioItem
                                v-for="resource in resources"
                                :key="resource.id"
                                :value="String(resource.calendar_id)"
                            >
                                <span
                                    class="size-2 shrink-0 rounded-full"
                                    :style="{
                                        backgroundColor:
                                            resource.color ?? '#d4d8dd',
                                    }"
                                    aria-hidden="true"
                                />
                                <span class="truncate">{{
                                    resource.name
                                }}</span>
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>

            <span
                v-if="resources.length > 0"
                class="inline-flex items-center gap-1.5"
            >
                <span
                    class="ml-1.5 size-1.5 rounded-full"
                    :class="freeNow > 0 ? 'bg-success' : 'bg-foreground/60'"
                    aria-hidden="true"
                />
                {{ freeLabel }}
            </span>

            <div class="ml-auto flex items-center gap-1">
                <button
                    v-if="mine.total > 0"
                    type="button"
                    class="inline-flex h-7 items-center gap-1.5 rounded-full px-2.5 text-foreground/85 outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                    @click="emit('showMine')"
                >
                    Your bookings
                    <span class="text-muted-foreground tabular-nums">
                        {{
                            mine.pending > 0
                                ? `${mine.pending} waiting`
                                : mine.total
                        }}
                    </span>
                </button>

                <Popover
                    v-if="review && review.count > 0"
                    v-model:open="reviewOpen"
                >
                    <!-- Same quiet pill and count chip as the header's Review. -->
                    <PopoverTrigger
                        class="inline-flex h-7 items-center gap-1.5 rounded-full pr-1 pl-3 font-medium text-foreground/80 ring-1 ring-border outline-none ring-inset hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60 data-[state=open]:bg-muted data-[state=open]:text-foreground"
                        :aria-label="`${review.count} ${review.count === 1 ? 'request' : 'requests'} waiting for your review`"
                    >
                        Review
                        <span
                            class="grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1.5 text-[11px] font-semibold text-primary-foreground tabular-nums"
                            aria-hidden="true"
                        >
                            {{ review.count > 99 ? '99+' : review.count }}
                        </span>
                    </PopoverTrigger>
                    <PopoverContent
                        align="end"
                        :side-offset="8"
                        :collision-padding="16"
                        class="w-[23rem] max-w-[calc(100vw-2rem)] rounded-xl border-0 p-0 shadow-[0_12px_32px_-12px_oklch(0.25_0.02_250/0.35),0_0_0_1px_var(--border)]"
                    >
                        <p
                            class="flex items-baseline gap-2 px-4 pt-3.5 pb-2 text-[15px] font-semibold tracking-tight"
                        >
                            Waiting for your review
                            <span
                                class="text-sm font-normal text-muted-foreground tabular-nums"
                            >
                                {{ review.count }}
                            </span>
                        </p>
                        <ul
                            class="max-h-[22rem] overflow-y-auto border-y border-border"
                        >
                            <li
                                v-for="item in review.items"
                                :key="item.id"
                                class="border-b border-border last:border-b-0"
                            >
                                <button
                                    type="button"
                                    class="flex w-full flex-col gap-0.5 px-4 py-2.5 text-left outline-none hover:bg-muted/60 focus-visible:bg-muted/60"
                                    @click="showRequest(item)"
                                >
                                    <span
                                        class="flex w-full items-center gap-2 text-[13px] font-medium"
                                    >
                                        <span class="truncate">{{
                                            item.title
                                        }}</span>
                                        <span
                                            v-if="item.clash"
                                            class="shrink-0 rounded-full bg-destructive/10 px-1.5 text-[11px] leading-[18px] text-destructive"
                                        >
                                            Clash
                                        </span>
                                        <span
                                            v-else-if="item.passed"
                                            class="shrink-0 rounded-full bg-muted px-1.5 text-[11px] leading-[18px] text-muted-foreground"
                                        >
                                            Past
                                        </span>
                                    </span>
                                    <span
                                        class="flex w-full items-center gap-1.5 text-xs text-muted-foreground"
                                    >
                                        <span
                                            class="size-1.5 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor: colorOf(item),
                                            }"
                                            aria-hidden="true"
                                        />
                                        <span class="truncate tabular-nums">
                                            {{ item.resource }} ·
                                            {{ when(item) }}
                                            <template v-if="item.requester">
                                                · {{ item.requester }}
                                            </template>
                                        </span>
                                    </span>
                                </button>
                            </li>
                        </ul>
                        <div
                            class="flex items-center justify-between gap-3 px-4 py-2.5 text-xs text-muted-foreground"
                        >
                            <span>Pick one to decide it on the calendar.</span>
                            <Link
                                :href="
                                    adminBookings.index({
                                        query: { type: type.slug },
                                    })
                                "
                                class="shrink-0 rounded-sm font-medium text-foreground underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/60"
                            >
                                {{
                                    review.count > review.items.length
                                        ? `All ${review.count}`
                                        : 'Review queue'
                                }}
                            </Link>
                        </div>
                    </PopoverContent>
                </Popover>
            </div>
        </div>

        <!-- The period -->
        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-3">
            <h1
                class="hidden min-w-0 truncate text-2xl leading-8 font-semibold tracking-tight tabular-nums xl:block"
                aria-live="polite"
            >
                {{ title }}
            </h1>
            <Popover v-model:open="pickerOpen">
                <h1 class="min-w-0 xl:hidden">
                    <PopoverTrigger
                        class="-mx-1.5 flex items-center gap-1.5 rounded-lg px-1.5 text-[1.375rem] leading-8 font-semibold tracking-tight outline-none hover:bg-muted/70 focus-visible:ring-2 focus-visible:ring-ring/60 data-[state=open]:bg-muted/70 sm:text-2xl"
                        aria-live="polite"
                    >
                        <span class="truncate tabular-nums sm:hidden">
                            {{ shortTitle }}
                        </span>
                        <span class="hidden truncate tabular-nums sm:inline">
                            {{ title }}
                        </span>
                        <ChevronDown
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="sr-only">, pick a date</span>
                    </PopoverTrigger>
                </h1>
                <PopoverContent
                    align="start"
                    :side-offset="8"
                    :collision-padding="16"
                    class="w-[17.5rem] rounded-xl border-0 p-3 shadow-[0_12px_32px_-12px_oklch(0.25_0.02_250/0.35),0_0_0_1px_var(--border)]"
                >
                    <MiniMonth :mark="mark" />
                </PopoverContent>
            </Popover>

            <div class="flex items-center gap-0.5">
                <button
                    type="button"
                    :class="iconButton"
                    :aria-label="`Previous ${stepLabel}`"
                    @click="step(-1)"
                >
                    <ChevronLeft aria-hidden="true" />
                </button>
                <button
                    type="button"
                    :class="pillQuiet"
                    :disabled="showingToday"
                    title="Today (T)"
                    @click="grid.goToday()"
                >
                    Today
                </button>
                <button
                    type="button"
                    :class="iconButton"
                    :aria-label="`Next ${stepLabel}`"
                    @click="step(1)"
                >
                    <ChevronRight aria-hidden="true" />
                </button>
            </div>

            <div class="ml-auto flex items-center gap-2">
                <LiquidSegments
                    :model-value="grid.view"
                    :options="views"
                    @update:model-value="grid.setView"
                />

                <!-- Wide screens book from the side panel's big button. -->
                <button
                    type="button"
                    :class="[pillPrimary, 'xl:hidden']"
                    data-test="new-booking"
                    @click="emit('book')"
                >
                    <Plus aria-hidden="true" />
                    <span class="max-sm:sr-only">{{ nouns.action }}</span>
                    <span class="sm:hidden" aria-hidden="true">Book</span>
                </button>
            </div>
        </div>
    </header>
</template>
