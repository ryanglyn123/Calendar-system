<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    ArrowRight,
    ChevronDown,
    CircleCheckBig,
    Inbox,
    UserPlus,
} from '@lucide/vue';
import { useNow } from '@vueuse/core';
import { computed, ref } from 'vue';
import DeskDay from '@/components/desk/DeskDay.vue';
import DeskRequestCard from '@/components/desk/DeskRequestCard.vue';
import { format, parseISO } from '@/lib/calendarMath';
import { resourceNouns, resourceTypeIcon } from '@/lib/resourceTypeIcon';
import admin from '@/routes/admin';
import adminPending from '@/routes/admin/pending-registrations';
import resourcePages from '@/routes/resources';
import type { DeskRequest, DeskResource, DeskTab, DeskWeekDay } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin', href: admin.dashboard() }],
    },
});

type DeskType = { slug: string; name: string; icon: string | null };

const props = defineProps<{
    today: string;
    date: string;
    /** Every desk this person may open; more than one only for super admins. */
    tabs: DeskTab[];
    type: DeskType | null;
    requests: DeskRequest[];
    resources: DeskResource[];
    week: DeskWeekDay[];
    signups: number | null;
}>();

const now = useNow({ interval: 60_000 });

// Other reviewers and requesters act meanwhile; Inertia pauses polling
// while the tab is hidden.
usePoll(60_000, {
    only: ['tabs', 'requests', 'resources', 'week', 'signups'],
});

const typeIcon = computed(() =>
    props.type ? resourceTypeIcon(props.type) : Inbox,
);
const nouns = computed(() => (props.type ? resourceNouns(props.type) : null));
const people = computed(() =>
    nouns.value?.many === 'vehicles' ? 'Passengers' : 'Attendees',
);

// A request whose time is over can still be decided, but nobody is waiting
// on it any more: fold those away so the live ones lead. A change is over
// once its new time starts, because it can no longer be applied.
const isOver = (request: DeskRequest) =>
    parseISO(request.kind === 'change' ? request.starts_at : request.ends_at) <=
    now.value;

const sorted = computed(() =>
    [...props.requests].sort(
        (a, b) =>
            parseISO(a.starts_at).getTime() - parseISO(b.starts_at).getTime(),
    ),
);
const upcoming = computed(() => sorted.value.filter((r) => !isOver(r)));
const over = computed(() => sorted.value.filter(isOver));

/** Two rows of cards; the rest wait behind one button. */
const FIRST = 4;
const showAll = ref(false);
const visible = computed(() =>
    showAll.value ? upcoming.value : upcoming.value.slice(0, FIRST),
);
const overOpen = ref(false);

const loading = ref(false);

/** Move the schedule to another day, reloading only what shows it. */
const goTo = (date: string) =>
    router.get(
        admin.dashboard({
            query: {
                type: props.type?.slug,
                date: date === props.today ? undefined : date,
            },
        }).url,
        {},
        {
            only: ['date', 'resources', 'week'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );
</script>

<template>
    <Head :title="type ? `${type.name} desk` : 'Admin desk'" />

    <div
        class="mx-auto flex w-full max-w-6xl flex-col gap-10 px-4 pt-6 pb-12 sm:px-6 lg:px-8"
    >
        <header
            class="flex flex-wrap items-center justify-between gap-x-6 gap-y-4"
        >
            <div class="flex items-center gap-4">
                <span
                    class="grid size-12 shrink-0 place-items-center rounded-2xl bg-muted"
                    aria-hidden="true"
                >
                    <component :is="typeIcon" class="size-6" />
                </span>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ type ? `${type.name} desk` : 'Admin desk' }}
                    </h1>
                    <p class="text-[15px] text-muted-foreground">
                        {{ format(parseISO(today), 'EEEE, MMMM d') }}
                    </p>
                </div>
            </div>

            <!-- Only super admins review more than one type: one desk each,
                 switched here. -->
            <nav
                v-if="tabs.length > 1"
                aria-label="Desks"
                class="inline-flex gap-1 rounded-full bg-muted p-1"
            >
                <Link
                    v-for="tab in tabs"
                    :key="tab.slug"
                    :href="admin.dashboard({ query: { type: tab.slug } })"
                    class="inline-flex h-10 items-center gap-2 rounded-full px-4 text-sm font-medium transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring/60"
                    :class="
                        tab.slug === type?.slug
                            ? 'bg-card text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-current="tab.slug === type?.slug ? 'page' : undefined"
                >
                    <component
                        :is="resourceTypeIcon(tab)"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ tab.name }}
                    <span
                        v-if="tab.waiting"
                        class="grid h-5 min-w-5 place-items-center rounded-full bg-warning/20 px-1.5 text-xs font-semibold text-warning-ink tabular-nums"
                    >
                        {{ tab.waiting }}
                        <span class="sr-only">waiting</span>
                    </span>
                </Link>
            </nav>
        </header>

        <Link
            v-if="signups"
            :href="adminPending.index()"
            class="group flex items-center gap-4 rounded-2xl bg-muted/60 px-5 py-4 transition-colors duration-150 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/60"
        >
            <span
                class="grid size-10 shrink-0 place-items-center rounded-full bg-card ring-1 ring-border"
                aria-hidden="true"
            >
                <UserPlus class="size-5" />
            </span>
            <span class="min-w-0 flex-1">
                <span class="block font-medium">
                    {{ signups }}
                    {{ signups === 1 ? 'person wants' : 'people want' }} to join
                </span>
                <span class="block text-sm text-muted-foreground">
                    Check their details and let them in.
                </span>
            </span>
            <span class="inline-flex items-center gap-1 text-sm font-medium">
                Review
                <ArrowRight
                    class="size-4 transition-transform duration-150 group-hover:translate-x-0.5"
                    aria-hidden="true"
                />
            </span>
        </Link>

        <template v-if="type">
            <section aria-labelledby="desk-waiting-title" class="space-y-5">
                <div>
                    <h2
                        id="desk-waiting-title"
                        class="flex items-center gap-2 text-xl font-semibold tracking-tight"
                    >
                        Waiting for you
                        <span
                            v-if="upcoming.length"
                            class="grid h-6 min-w-6 place-items-center rounded-full bg-warning/20 px-2 text-sm font-semibold text-warning-ink tabular-nums"
                        >
                            {{ upcoming.length }}
                        </span>
                    </h2>
                    <p class="text-[15px] text-muted-foreground">
                        Approve or reject each request. The person who asked is
                        told either way.
                    </p>
                </div>

                <!-- Rows stretch so each pair's buttons line up. -->
                <div v-if="upcoming.length" class="grid gap-4 xl:grid-cols-2">
                    <DeskRequestCard
                        v-for="request in visible"
                        :key="request.key"
                        :request="request"
                        :now="now"
                        :people="people"
                    />
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 rounded-2xl bg-muted/40 px-6 py-10 text-center"
                >
                    <span
                        class="grid size-14 place-items-center rounded-full bg-success/12 text-success"
                        aria-hidden="true"
                    >
                        <CircleCheckBig class="size-7" />
                    </span>
                    <p class="text-lg font-semibold">All caught up!</p>
                    <p class="max-w-sm text-[15px] text-muted-foreground">
                        New {{ type.name }} requests will show up here.
                    </p>
                    <Link
                        :href="resourcePages.section(type.slug)"
                        class="mt-1 inline-flex items-center gap-1 rounded-md text-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/60"
                    >
                        Open the {{ type.name }} calendar
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </div>

                <button
                    v-if="upcoming.length > FIRST && !showAll"
                    type="button"
                    class="flex h-11 w-full items-center justify-center gap-2 rounded-2xl text-sm font-medium ring-1 ring-border transition-colors duration-150 outline-none ring-inset hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/60"
                    @click="showAll = true"
                >
                    Show {{ upcoming.length - FIRST }} more
                    <ChevronDown class="size-4" aria-hidden="true" />
                </button>

                <div v-if="over.length" class="pt-2">
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-md text-[15px] font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring/60"
                        :aria-expanded="overOpen"
                        aria-controls="desk-over"
                        @click="overOpen = !overOpen"
                    >
                        <ChevronDown
                            class="size-4 transition-transform duration-200 motion-reduce:transition-none"
                            :class="{ '-rotate-90': !overOpen }"
                            aria-hidden="true"
                        />
                        Time already passed
                        <span class="text-muted-foreground tabular-nums"
                            >({{ over.length }})</span
                        >
                    </button>
                    <p class="mt-1 pl-6 text-sm text-muted-foreground">
                        These dates are over. Deciding now only records what
                        happened.
                    </p>
                    <div
                        v-if="overOpen"
                        id="desk-over"
                        class="mt-4 grid gap-4 xl:grid-cols-2"
                    >
                        <DeskRequestCard
                            v-for="request in over"
                            :key="request.key"
                            :request="request"
                            :now="now"
                            :people="people"
                        />
                    </div>
                </div>
            </section>

            <DeskDay
                :date="date"
                :today="today"
                :week="week"
                :resources="resources"
                :people="people"
                :many="nouns?.many ?? 'items'"
                :loading="loading"
                @date="goTo"
            />
        </template>

        <div
            v-else
            class="flex flex-col items-center gap-3 rounded-2xl bg-muted/40 px-6 py-12 text-center"
        >
            <span
                class="grid size-14 place-items-center rounded-full bg-card ring-1 ring-border"
                aria-hidden="true"
            >
                <Inbox class="size-7 text-muted-foreground" />
            </span>
            <p class="text-lg font-semibold">No bookings to review here</p>
            <p class="max-w-md text-[15px] text-muted-foreground">
                Room and vehicle requests go to their own approvers. The rest of
                the admin tools are in the menu.
            </p>
        </div>
    </div>
</template>
