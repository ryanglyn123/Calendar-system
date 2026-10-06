<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { useNow } from '@vueuse/core';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import DeskBoard from '@/components/desk/DeskBoard.vue';
import DeskHorizon from '@/components/desk/DeskHorizon.vue';
import DeskNotes from '@/components/desk/DeskNotes.vue';
import DeskQueue from '@/components/desk/DeskQueue.vue';
import { useAuth } from '@/composables/useAuth';
import { format, parseISO } from '@/lib/calendarMath';
import admin from '@/routes/admin';
import adminHealth from '@/routes/admin/health';
import type {
    DeskActivity,
    DeskAnnouncements,
    DeskDemandDay,
    DeskHealth,
    DeskPeople,
    DeskQueueItem,
    DeskSchedule,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin', href: admin.dashboard() }],
    },
});

const props = defineProps<{
    today: string;
    queue: DeskQueueItem[];
    schedule: DeskSchedule;
    demand: DeskDemandDay[];
    people: DeskPeople | null;
    notices: DeskAnnouncements | null;
    activity: DeskActivity[] | null;
    health: DeskHealth | null;
}>();

const { hasPermission, hasAnyPermission } = useAuth();

const now = useNow({ interval: 60_000 });

// Other reviewers and requesters act meanwhile: keep the queue, the day and
// the strip current. Inertia pauses polling while the tab is hidden.
usePoll(60_000, {
    only: ['queue', 'schedule', 'demand', 'health'],
});

/** What lands in this person's queue, in words, for its empty state. */
const receives = computed(() =>
    [
        hasPermission('bookings.approve') && 'booking and change requests',
        hasAnyPermission('users.approve', 'users.manage') && 'sign-ups',
    ]
        .filter(Boolean)
        .join(' and '),
);

const loading = ref(false);
const spotlight = ref<number | null>(null);
let spotlightTimer: ReturnType<typeof setTimeout> | undefined;

/** Move the schedule to another day, reloading only its lanes. */
const goTo = (date: string, onDone?: () => void) => {
    router.get(
        admin.dashboard({
            query: { date: date === props.today ? undefined : date },
        }).url,
        {},
        {
            only: ['schedule'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => {
                loading.value = false;
                onDone?.();
            },
        },
    );
};

/** From a queue row: bring its day onto the schedule and point at it. */
const show = (date: string, bookingId: number) => {
    const reveal = () => {
        clearTimeout(spotlightTimer);
        spotlight.value = bookingId;
        spotlightTimer = setTimeout(() => (spotlight.value = null), 2400);

        nextTick(() =>
            document.getElementById('desk-schedule')?.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                    .matches
                    ? 'auto'
                    : 'smooth',
                block: 'start',
            }),
        );
    };

    if (date === props.schedule.date) {
        reveal();
    } else {
        goTo(date, reveal);
    }
};

const hover = (bookingId: number | null) => {
    clearTimeout(spotlightTimer);
    spotlight.value = bookingId;
};

onBeforeUnmount(() => clearTimeout(spotlightTimer));
</script>

<template>
    <Head title="Admin desk" />

    <div
        class="mx-auto flex w-full max-w-[1400px] flex-col gap-6 px-4 pt-4 pb-10 sm:px-6 lg:px-8"
    >
        <header
            class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ format(parseISO(today), 'EEEE, MMMM d') }}
                </h1>
                <p class="text-sm text-muted-foreground">Admin desk</p>
            </div>

            <Link
                v-if="health"
                :href="adminHealth.index()"
                class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-[13px] ring-1 ring-border outline-none ring-inset hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/60"
            >
                <span
                    class="size-2 rounded-full"
                    :class="health.failed > 0 ? 'bg-warning' : 'bg-success'"
                    aria-hidden="true"
                />
                <span
                    v-if="health.failed > 0"
                    class="font-medium text-warning-ink tabular-nums"
                >
                    {{ health.failed }} failed
                    {{ health.failed === 1 ? 'job' : 'jobs' }}
                </span>
                <span v-else>Jobs running</span>
            </Link>
        </header>

        <DeskQueue
            v-if="receives"
            :items="queue"
            :now="now"
            :receives="receives"
            @spotlight="hover"
            @show="show"
        />

        <DeskBoard
            :schedule="schedule"
            :today="today"
            :now="now"
            :spotlight="spotlight"
            :loading="loading"
            @date="goTo"
        />

        <DeskHorizon
            :days="demand"
            :selected="schedule.date"
            :today="today"
            @select="goTo"
        />

        <DeskNotes
            :people="people"
            :announcements="notices"
            :activity="activity"
            :now="now"
            :can-invite="hasPermission('users.manage')"
        />
    </div>
</template>
