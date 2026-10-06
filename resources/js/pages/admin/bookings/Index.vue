<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { ChevronRight, CircleCheck } from '@lucide/vue';
import { defaultWindow, useEventListener, useNow } from '@vueuse/core';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import EmptyNote from '@/components/admin/EmptyNote.vue';
import FilterTabs from '@/components/admin/FilterTabs.vue';
import {
    dayLabel,
    isoDate,
    pillQuiet,
    timeRange,
} from '@/components/desk/desk';
import BookingReview from '@/components/review/BookingReview.vue';
import ReviewInbox from '@/components/review/ReviewInbox.vue';
import ReviewRow from '@/components/review/ReviewRow.vue';
import type { RowFlag } from '@/components/review/ReviewRow.vue';
import { parseISO } from '@/lib/calendarMath';
import admin from '@/routes/admin';
import adminBookings from '@/routes/admin/bookings';
import type {
    DeskBookingRequest,
    DeskSchedule,
    ReviewDetail,
    ReviewMissing,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Booking Approvals', href: adminBookings.index() },
        ],
    },
});

const props = defineProps<{
    today: string;
    requests: DeskBookingRequest[];
    /** The open request's day (or `date`, default today). */
    schedule: DeskSchedule;
    /** The request this URL is about (/admin/bookings/{id}), if any. */
    focus?: ReviewDetail | ReviewMissing | null;
}>();

const now = useNow({ interval: 60_000 });

// Other reviewers decide meanwhile; Inertia pauses this while the tab hides.
usePoll(60_000, { only: ['requests', 'schedule', 'focus'] });

// A request arrived (or was withdrawn) since the last poll: catch up now.
useEventListener(defaultWindow, 'bookings:changed', () =>
    router.reload({ only: ['requests', 'schedule', 'focus'] }),
);

const focusId = computed(() =>
    props.focus && !('missing' in props.focus) ? props.focus.id : null,
);

/* ---- Resource type: offered once more than one type is bookable ------ */

/* A link can pick the type (?type=vehicle, from Carpool); an open request
   picks its own, so the list beside it is the same system. */
const typeFromUrl = new URL(usePage().url, 'http://localhost').searchParams.get(
    'type',
);
const focusType =
    props.focus && !('missing' in props.focus)
        ? props.focus.type?.slug
        : undefined;

const type = ref(
    props.schedule.types.find((t) => t.slug === (focusType ?? typeFromUrl))
        ?.name ?? 'all',
);

const typeTabs = computed(() => [
    { value: 'all', label: 'All', count: props.requests.length },
    ...props.schedule.types.map((t) => ({
        value: t.name,
        label: t.name,
        count: props.requests.filter((r) => r.type === t.name).length,
    })),
]);

const listHref = computed(() => {
    const slug = props.schedule.types.find((t) => t.name === type.value)?.slug;

    return adminBookings.index({ query: slug ? { type: slug } : undefined })
        .url;
});

/* ---- The list -------------------------------------------------------- */

const ofType = computed(() =>
    [...props.requests]
        .filter((r) => type.value === 'all' || r.type === type.value)
        .sort((a, b) => a.starts_at.localeCompare(b.starts_at)),
);

const upcoming = computed(() =>
    ofType.value.filter((r) => parseISO(r.ends_at) > now.value),
);
const passed = computed(() =>
    ofType.value.filter((r) => parseISO(r.ends_at) <= now.value),
);

/** Upcoming requests read as a run of days. */
const days = computed(() => {
    const byDay = new Map<string, DeskBookingRequest[]>();

    for (const item of upcoming.value) {
        const key = isoDate(parseISO(item.starts_at));
        byDay.set(key, [...(byDay.get(key) ?? []), item]);
    }

    return [...byDay].map(([key, items]) => ({
        key,
        label: dayLabel(items[0].starts_at, now.value),
        items,
    }));
});

// Requests whose time has passed rarely need attention first: folded away,
// unless the open one is among them.
const showPassed = ref(passed.value.some((r) => r.id === focusId.value));

/** List order: what J/K and "next after deciding" walk through. */
const ordered = computed(() => [...upcoming.value, ...passed.value]);

/** One flag per row: the thing most likely to change the decision. */
const flagOf = (r: DeskBookingRequest): RowFlag | null => {
    if (r.clash) {
        const detail = `Overlaps ${r.clash.status} “${r.clash.title}” ${timeRange(r.clash.starts_at, r.clash.ends_at)}`;

        return r.clash.status === 'approved'
            ? { text: 'Clash', tone: 'danger', detail }
            : { text: 'Overlap', tone: 'warn', detail };
    }

    if (r.capacity != null && (r.attendee_count ?? 0) > r.capacity) {
        return {
            text: 'Over capacity',
            tone: 'warn',
            detail: `${r.attendee_count} people, ${r.capacity} seats`,
        };
    }

    return null;
};

/* ---- Moving through the list ----------------------------------------- */

const focusIndex = computed(() =>
    ordered.value.findIndex((r) => r.id === focusId.value),
);

// Where the open request sat, so "next" survives it leaving the list.
const lastIndex = ref(0);
watch(focusIndex, (i) => i >= 0 && (lastIndex.value = i), { immediate: true });

const next = computed(() => {
    const list = ordered.value;
    const i = focusIndex.value;

    if (i >= 0) {
        return (list[i + 1] ?? (i > 0 ? list[0] : undefined))?.id ?? null;
    }

    return (list[lastIndex.value] ?? list.at(-1))?.id ?? null;
});

const previous = computed(() => {
    const i = focusIndex.value;

    return i > 0 ? ordered.value[i - 1].id : null;
});

const open = (id: number | null, replace = false) => {
    if (id !== null) {
        router.visit(adminBookings.show(id).url, {
            preserveState: true,
            preserveScroll: true,
            replace,
            only: ['focus', 'schedule'],
        });
    }
};

useEventListener(defaultWindow, 'keydown', (e: KeyboardEvent) => {
    const target = e.target as HTMLElement | null;

    if (
        e.ctrlKey ||
        e.metaKey ||
        e.altKey ||
        target?.closest('input, textarea, select, [contenteditable]') ||
        document.querySelector('[role="dialog"], [role="menu"]')
    ) {
        return;
    }

    if (e.key === 'j') {
        open(
            focusIndex.value >= 0 ? next.value : (ordered.value[0]?.id ?? null),
        );
    } else if (e.key === 'k') {
        open(previous.value);
    }
});

// The open request's row stays in view as J/K walk the list.
watch(focusId, (id) => {
    if (id !== null && passed.value.some((r) => r.id === id)) {
        showPassed.value = true;
    }

    requestAnimationFrame(() =>
        document
            .querySelector('[data-review-row][aria-current="true"]')
            ?.scrollIntoView({ block: 'nearest' }),
    );
});

/* ---- Selection loading ------------------------------------------------ */

// Opening another request fades the old one rather than freezing it; polls
// (same URL) don't.
const loading = ref(false);
const offs: (() => void)[] = [];

onMounted(() => {
    offs.push(
        router.on('start', (event) => {
            if (event.detail.visit.url.pathname !== window.location.pathname) {
                loading.value = true;
            }
        }),
        router.on('finish', () => (loading.value = false)),
    );

    // Wide screens open the first request straight away; narrow ones show the list.
    if (
        !props.focus &&
        ordered.value.length > 0 &&
        window.matchMedia('(min-width: 1024px)').matches
    ) {
        open(ordered.value[0].id, true);
    }
});

onBeforeUnmount(() => offs.forEach((off) => off()));
</script>

<template>
    <Head title="Booking Approvals" />

    <div class="flex flex-1 flex-col gap-5 p-4 sm:p-6">
        <header class="flex items-baseline gap-3">
            <h1 class="text-2xl font-semibold tracking-tight">
                Booking approvals
            </h1>
            <span
                v-if="requests.length"
                class="text-sm text-muted-foreground tabular-nums"
            >
                {{ requests.length }} waiting
            </span>
        </header>

        <EmptyNote
            v-if="requests.length === 0 && !focus"
            :icon="CircleCheck"
            title="Nothing is waiting on you."
        >
            New room and vehicle requests land here. The requester hears back
            the moment you decide.
        </EmptyNote>

        <ReviewInbox
            v-else
            :has-selection="!!focus"
            list-label="Requests waiting for review"
        >
            <template #list>
                <FilterTabs
                    v-if="schedule.types.length > 1 && requests.length > 0"
                    v-model="type"
                    :options="typeTabs"
                    label="Resource type"
                    class="mb-3"
                />

                <p
                    v-if="requests.length > 0 && ofType.length === 0"
                    class="px-3 py-6 text-sm text-muted-foreground"
                >
                    No {{ type }} requests are waiting.
                    <button
                        type="button"
                        class="underline underline-offset-4 hover:text-foreground"
                        @click="type = 'all'"
                    >
                        Show all
                    </button>
                </p>

                <section
                    v-for="day in days"
                    :key="day.key"
                    class="mb-3"
                    :aria-label="day.label"
                >
                    <h2
                        class="px-3 pb-1 text-[12px] font-medium text-muted-foreground"
                    >
                        {{ day.label }}
                    </h2>
                    <ul>
                        <ReviewRow
                            v-for="item in day.items"
                            :key="item.id"
                            :href="adminBookings.show(item.id).url"
                            :title="item.title"
                            :meta="`${timeRange(item.starts_at, item.ends_at)} · ${item.resource}`"
                            :color="item.color"
                            :flag="flagOf(item)"
                            :selected="item.id === focusId"
                        />
                    </ul>
                </section>

                <p
                    v-if="days.length === 0 && passed.length > 0"
                    class="px-3 pb-3 text-sm text-muted-foreground"
                >
                    No upcoming requests.
                </p>

                <section
                    v-if="passed.length"
                    class="mt-1 border-t border-border pt-2"
                >
                    <button
                        type="button"
                        class="flex w-full items-center gap-1.5 rounded-md px-3 py-1.5 text-[12px] font-medium text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                        :aria-expanded="showPassed"
                        @click="showPassed = !showPassed"
                    >
                        <ChevronRight
                            class="size-3.5 motion-safe:transition-transform motion-safe:duration-150"
                            :class="{ 'rotate-90': showPassed }"
                            aria-hidden="true"
                        />
                        Time has passed
                        <span class="tabular-nums">{{ passed.length }}</span>
                    </button>
                    <ul v-show="showPassed" class="mt-1">
                        <ReviewRow
                            v-for="item in passed"
                            :key="item.id"
                            :href="adminBookings.show(item.id).url"
                            :title="item.title"
                            :meta="`${dayLabel(item.starts_at, now)} ${timeRange(item.starts_at, item.ends_at)} · ${item.resource}`"
                            :color="item.color"
                            :flag="flagOf(item)"
                            :selected="item.id === focusId"
                        />
                    </ul>
                </section>
            </template>

            <template #detail>
                <div
                    class="flex min-h-[calc(100svh-12rem)] flex-1 flex-col motion-safe:transition-opacity motion-safe:duration-150"
                    :class="{ 'opacity-50': loading }"
                    :aria-busy="loading"
                >
                    <BookingReview
                        v-if="focus"
                        :review="focus"
                        :schedule="schedule"
                        :now="now"
                        :next="next"
                        :list-href="listHref"
                    />
                    <div
                        v-else
                        class="flex flex-1 items-center justify-center text-sm text-muted-foreground"
                    >
                        <template v-if="ordered.length"
                            >Opening the next request…</template
                        >
                        <template v-else>
                            <span>
                                Nothing left in this list.
                                <button
                                    v-if="type !== 'all'"
                                    type="button"
                                    :class="[pillQuiet, 'ml-2']"
                                    @click="type = 'all'"
                                >
                                    Show all types
                                </button>
                            </span>
                        </template>
                    </div>
                </div>
            </template>
        </ReviewInbox>
    </div>
</template>
