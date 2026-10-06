<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import {
    defaultDocument,
    defaultWindow,
    until,
    useEventListener,
    useIntervalFn,
} from '@vueuse/core';
import { isValid } from 'date-fns';
import { computed, onMounted, ref, useTemplateRef, watch } from 'vue';
import BookingDialog from '@/components/booking/BookingDialog.vue';
import BookingToolbar from '@/components/booking/BookingToolbar.vue';
import type { ReviewItem } from '@/components/booking/BookingToolbar.vue';
import MyBookings from '@/components/booking/MyBookings.vue';
import type { MyBooking } from '@/components/booking/MyBookings.vue';
import ResourcePicker from '@/components/booking/ResourcePicker.vue';
import type { BookableResource } from '@/components/booking/ResourceSchedule.vue';
import CalendarShell from '@/components/calendar/CalendarShell.vue';
import MiniMonth from '@/components/calendar/MiniMonth.vue';
import type { DayMark } from '@/components/calendar/MiniMonth.vue';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { holdsSlot } from '@/lib/bookingDisplay';
import {
    addDays,
    format,
    isSameDay,
    parseISO,
    startOfDay,
} from '@/lib/calendarMath';
import { BOOKING_VIEWS, resourceNouns } from '@/lib/resourceTypeIcon';
import type { BookingView } from '@/lib/resourceTypeIcon';
import adminBookings from '@/routes/admin/bookings';
import bookingsApi from '@/routes/api/bookings';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import { useCalendarListStore } from '@/stores/calendarList';
import type {
    CalendarItem,
    CalendarScope,
    EventOccurrence,
} from '@/types/calendar';

type SectionResourceType = {
    id: number;
    name: string;
    slug: string;
    icon: string | null;
    description: string | null;
    requires_approval: boolean;
    max_duration_minutes: number | null;
};

const props = defineProps<{
    resourceType: SectionResourceType;
    resources: BookableResource[];
    weekStartsOn: 0 | 1;
    initialCalendars: CalendarItem[];
    initialEvents: EventOccurrence[];
    initialBookings: MyBooking[];
    /** Present for people who review this type's requests. */
    review: {
        count: number;
        booking: number | null;
        items: ReviewItem[];
    } | null;
    /** The view this browser last chose here (a cookie), if any. */
    rememberedView: string | null;
}>();

const grid = useCalendarGridStore();
const list = useCalendarListStore();

const nouns = computed(() => resourceNouns(props.resourceType));

const scope = computed<CalendarScope>(() => ({
    resourceTypeId: props.resourceType.id,
}));

/* ---- Where the calendar opens ---------------------------------------------
   Each type and role is its own calendar: Carpool's approver, Carpool's
   staff, Boardroom's approver and Boardroom's staff each open on their own
   view (see resourceNouns) and remember the last one they chose, in this
   browser, without touching the others. The tab's own state (view, date,
   room or vehicle) lives in the URL, so refresh, Back and a second tab each
   keep theirs. Inertia remounts the page on every visit and on Back, so
   this runs once per arrival. */

/** Approvers of this type manage it; everyone else books from it. */
const role = props.review ? 'manage' : 'book';
const context = `${props.resourceType.slug}:${role}`;
const isBookingView = (value: unknown): value is BookingView =>
    BOOKING_VIEWS.includes(value as BookingView);

// From the page, not window.location: this runs on the server too.
const query = new URLSearchParams(usePage().url.split('?')[1] ?? '');
const askedDate = parseISO(query.get('date') ?? '');

grid.setScope(scope.value, {
    view:
        [query.get('view'), props.rememberedView].find(isBookingView) ??
        nouns.value.view[role],
    date:
        /^\d{4}-\d{2}-\d{2}$/.test(query.get('date') ?? '') &&
        isValid(askedDate)
            ? askedDate
            : new Date(),
});
list.setScope(scope.value);
list.picked =
    props.resources.find((r) => String(r.id) === query.get('resource'))
        ?.calendar_id ?? null;

/**
 * A view the viewer picks becomes this calendar's default; one arriving in
 * a link doesn't. Kept in a cookie (per browser, like the theme), so the
 * server renders the same view the browser will show.
 */
const VIEWS_COOKIE = 'booking_views';

watch(
    () => grid.view,
    (view) => {
        if (!isBookingView(view)) {
            return;
        }

        let views: Record<string, string> = {};

        try {
            const raw = document.cookie.match(
                new RegExp(`(?:^|;\\s*)${VIEWS_COOKIE}=([^;]*)`),
            )?.[1];
            views = raw ? JSON.parse(decodeURIComponent(raw)) : {};
        } catch {
            views = {};
        }

        views[context] = view;
        document.cookie = `${VIEWS_COOKIE}=${encodeURIComponent(JSON.stringify(views))};path=/;max-age=31536000;SameSite=Lax`;
    },
);

/** The room or vehicle on screen, when one is picked. */
const pickedResource = computed(
    () => props.resources.find((r) => r.calendar_id === list.picked) ?? null,
);

/** Mirror the tab's state into its URL, replacing (not adding) history.
 *  Keys go in alphabetical order, as Laravel writes them back on load. */
const syncUrl = () => {
    const next = new URLSearchParams();

    // Today is the default, so a refresh tomorrow opens on tomorrow.
    if (!isSameDay(grid.focusDate, new Date())) {
        next.set('date', format(grid.focusDate, 'yyyy-MM-dd'));
    }

    if (pickedResource.value) {
        next.set('resource', String(pickedResource.value.id));
    }

    if (isBookingView(grid.view)) {
        next.set('view', grid.view);
    }

    const url = `${window.location.pathname}?${next}`;

    if (url !== `${window.location.pathname}${window.location.search}`) {
        router.replace({ url, preserveState: true, preserveScroll: true });
    }
};

onMounted(syncUrl);
watch([() => grid.view, () => grid.focusDate, () => list.picked], syncUrl);

/** With one room or vehicle picked, a new booking is for it; showing all,
 *  the booking form asks. */
const viewedResourceId = computed(() => pickedResource.value?.id ?? null);

/** Busy days for the mini calendar, among the rooms or vehicles shown. A
 *  request waiting on the viewer (theirs to decide, or theirs pending)
 *  outranks a plain booking. */
const dayMarks = computed(() => {
    const marks = new Map<string, DayMark>();
    const shown = new Set(list.visibleCalendarIds);

    grid.occurrencesBetween(new Date(0), new Date(8.64e15)).forEach((o) => {
        if (!shown.has(o.calendar_id) || !holdsSlot(o)) {
            return;
        }

        const waiting =
            o.booking?.status === 'pending' &&
            (props.review ? o.booking.can_review : o.booking.is_mine);
        const end = new Date(o.ends_at);

        for (
            let d = startOfDay(new Date(o.starts_at));
            d < end;
            d = addDays(d, 1)
        ) {
            const key = format(d, 'yyyy-MM-dd');

            if (waiting) {
                marks.set(key, 'waiting');
            } else if (!marks.has(key)) {
                marks.set(key, 'booked');
            }
        }
    });

    return marks;
});

const markOf = (day: Date) =>
    dayMarks.value.get(format(day, 'yyyy-MM-dd')) ?? null;

/* The requester's bookings in this section. Inlined with the page so it
   paints in one request; reloaded after any booking change and on the
   same cadence as the calendar, so a decision made elsewhere shows up. */
const mine = ref<MyBooking[]>(props.initialBookings);
const loadingMine = ref(false);

const loadMine = async () => {
    loadingMine.value = true;

    try {
        const response = await api.get<{ data: MyBooking[] }>(
            bookingsApi.mine({
                query: { resource_type_id: props.resourceType.id },
            }).url,
        );

        mine.value = response.data;
    } catch {
        // Keep the last list; the next tick retries.
    } finally {
        loadingMine.value = false;
    }
};

/** Everything booking-shaped on the page, brought up to date. */
const syncBookings = () => {
    void loadMine();

    if (props.review) {
        router.reload({ only: ['review'] });
    }
};

useIntervalFn(() => {
    if (document.visibilityState === 'visible') {
        void loadMine();
    }
}, 60_000);

useEventListener(defaultDocument, 'visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        syncBookings();
    }
});

// The notification poll saw booking news: a decision on one of these
// bookings, or a new request for a reviewer.
useEventListener(defaultWindow, 'bookings:changed', syncBookings);

// Inertia reuses this component when hopping between sections
// (Boardroom -> Carpool); each visit brings fresh inlined bookings.
watch(
    () => props.initialBookings,
    (next) => {
        mine.value = next;
    },
);

const mineSummary = computed(() => ({
    total: mine.value.length,
    pending: mine.value.filter((item) => item.status === 'pending').length,
}));

const booking = ref(false);
/** Prefilled date (yyyy-MM-dd) when the dialog is opened from a calendar cell. */
const bookingDate = ref<string | null>(null);
/** Prefilled slot (minutes since midnight) when a lane slot was picked. */
const bookingTime = ref<{ start: number; end: number } | null>(null);
/** The room or vehicle whose row was picked. */
const bookingResourceId = ref<number | null>(null);

const openNewBooking = () => {
    bookingDate.value = null;
    bookingTime.value = null;
    bookingResourceId.value = null;
    booking.value = true;
};

// Month cells give a day; lanes also give the resource and the time.
const openBookingOn = (start: Date, end: Date | null, resourceId?: number) => {
    const minutes = (d: Date) =>
        Math.round((d.getTime() - startOfDay(start).getTime()) / 60_000);

    bookingDate.value = format(start, 'yyyy-MM-dd');
    bookingTime.value = end
        ? { start: minutes(start), end: minutes(end) }
        : null;
    bookingResourceId.value = resourceId ?? null;
    booking.value = true;
};

const shell = useTemplateRef<InstanceType<typeof CalendarShell>>('shell');
const calendarArea = useTemplateRef<HTMLElement>('calendarArea');
const bookingsSection = useTemplateRef<HTMLElement>('bookingsSection');

const smooth = (): ScrollBehavior =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ? 'auto'
        : 'smooth';

const scrollToBookings = () =>
    bookingsSection.value?.scrollIntoView({
        behavior: smooth(),
        block: 'start',
    });

/** Put a date on the calendar and bring the calendar into view. */
const showOnCalendar = (iso: string) => {
    grid.setFocusDate(parseISO(iso));
    calendarArea.value?.scrollIntoView({ behavior: smooth(), block: 'start' });
};

/**
 * A waiting request picked from the review list: its date in the current
 * view, with its room or vehicle on screen, and its card open to decide. If
 * the calendar can't place it, the review page can.
 */
const showRequest = async (item: ReviewItem) => {
    const start = parseISO(item.starts_at);
    const day = startOfDay(start);
    const resource = props.resources.find((r) => r.id === item.resource_id);

    if (
        resource &&
        list.picked !== null &&
        list.picked !== resource.calendar_id
    ) {
        list.picked = null;
    }

    grid.setFocusDate(start);
    calendarArea.value?.scrollIntoView({ behavior: smooth(), block: 'start' });

    await until(() => grid.isLoading).toBe(false, { timeout: 8000 });

    const occurrence = grid
        .occurrencesBetween(day, addDays(day, 1))
        .find((o) => o.booking?.id === item.id);

    if (occurrence) {
        shell.value?.openOccurrence(occurrence);
    } else {
        router.visit(adminBookings.show(item.id).url);
    }
};

const onBooked = (startsAt: string) => {
    void loadMine();
    void grid.invalidateAround(new Date(startsAt));
};
</script>

<template>
    <Head :title="resourceType.name" />

    <div class="flex h-full min-h-0 flex-1">
        <!-- Wide screens: the mini calendar on top, as on the other
             calendars, then the room or vehicle to show, beside the
             calendar they drive. Narrower ones get the same two from the
             toolbar's pickers. -->
        <aside
            class="hidden w-64 shrink-0 flex-col gap-5 overflow-y-auto border-r border-border px-3 pt-5 pb-6 xl:flex"
            :aria-label="`${resourceType.name} calendar controls`"
        >
            <!-- The calendar's Create button, as booking. -->
            <Button
                class="h-14 w-fit gap-3 rounded-2xl bg-foreground pr-5 pl-4 text-background shadow-md hover:bg-foreground/85 hover:shadow-lg"
                data-test="new-booking-panel"
                @click="openNewBooking"
            >
                <Plus class="size-6" aria-hidden="true" />
                {{ nouns.action }}
            </Button>
            <div class="px-1">
                <MiniMonth :mark="markOf" />
            </div>
            <ResourcePicker
                v-if="resources.length > 1"
                :resources="resources"
                :nouns="nouns"
            />
        </aside>

        <div class="flex min-w-0 flex-1 flex-col overflow-y-auto">
            <BookingToolbar
                :type="resourceType"
                :resources="resources"
                :nouns="nouns"
                :review="review"
                :mine="mineSummary"
                :mark="markOf"
                @book="openNewBooking"
                @show-mine="scrollToBookings"
                @show-request="showRequest"
            />

            <!-- One row per room or vehicle (day, week); month keeps the grid
             and needs a fixed height to lay out its six weeks. Keyed so
             hopping between sections remounts the shell and its scope. -->
            <div
                ref="calendarArea"
                class="shrink-0 scroll-mt-2 border-t border-border"
                :class="{
                    'h-[max(36rem,calc(100dvh-13rem))] overflow-hidden':
                        grid.view === 'month',
                }"
            >
                <CalendarShell
                    ref="shell"
                    :key="resourceType.id"
                    :event-types="[]"
                    :week-starts-on="weekStartsOn"
                    :initial-calendars="initialCalendars"
                    :initial-events="initialEvents"
                    :scope="scope"
                    :booking-resources="resources"
                    :booking-nouns="nouns"
                    @create-booking="openBookingOn"
                    @booking-changed="syncBookings"
                />
            </div>

            <section
                ref="bookingsSection"
                class="flex scroll-mt-2 flex-col gap-3 border-t border-border px-4 pt-5 pb-8 sm:px-6"
                aria-labelledby="your-bookings"
            >
                <h2
                    id="your-bookings"
                    class="text-[15px] font-semibold tracking-tight"
                >
                    Your {{ resourceType.name }} bookings
                </h2>
                <MyBookings
                    :bookings="mine"
                    :type-name="resourceType.name"
                    :loading="loadingMine"
                    @changed="
                        () => {
                            syncBookings();
                            void grid.invalidateAround(grid.focusDate);
                        }
                    "
                    @show="showOnCalendar"
                    @create="openNewBooking"
                />
            </section>

            <BookingDialog
                v-model:open="booking"
                :type="resourceType"
                :resources="resources"
                :initial-date="bookingDate"
                :initial-time="bookingTime"
                :initial-resource-id="bookingResourceId ?? viewedResourceId"
                @created="onBooked"
            />
        </div>
    </div>
</template>
