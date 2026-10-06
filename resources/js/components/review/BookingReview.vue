<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    CircleAlert,
    CircleSlash,
    Clock,
    Printer,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { formatDistanceToNowStrict } from 'date-fns';
import { computed, onMounted, ref } from 'vue';
import PeopleRoster from '@/components/booking/PeopleRoster.vue';
import {
    dayLabel,
    hoursLabel,
    isoDate,
    pillPrimary,
    pillQuiet,
    timeRange,
} from '@/components/desk/desk';
import { parseISO } from '@/lib/calendarMath';
import { resourceTypeIcon } from '@/lib/resourceTypeIcon';
import admin from '@/routes/admin';
import adminBookings from '@/routes/admin/bookings';
import bookingsPage from '@/routes/bookings';
import resourcesPage from '@/routes/resources';
import type { DeskSchedule, ReviewDetail, ReviewMissing } from '@/types';
import DayStrip from './DayStrip.vue';
import DecisionBar from './DecisionBar.vue';

const props = defineProps<{
    review: ReviewDetail | ReviewMissing;
    /** The request's day, for the lane and the free alternatives. */
    schedule: DeskSchedule;
    now: Date;
    /** The next request in the list, opened after deciding this one. */
    next: number | null;
    /** The list, with its filter (and the way back on narrow screens). */
    listHref: string;
}>();

const r = computed(() =>
    'missing' in props.review ? null : (props.review as ReviewDetail),
);

const vehicle = computed(() =>
    /car|vehicle|van|shuttle|pool|transport/i.test(r.value?.type?.name ?? ''),
);
const people = computed(() => (vehicle.value ? 'passengers' : 'attendees'));
const People = computed(() => (vehicle.value ? 'Passengers' : 'Attendees'));

// "6 days ago" is filled in after hydration, so server and browser agree.
const hydrated = ref(false);
onMounted(() => (hydrated.value = true));
const ago = (iso: string | null) =>
    iso && hydrated.value
        ? `${formatDistanceToNowStrict(parseISO(iso))} ago`
        : '';

const decidable = computed(
    () => r.value?.status === 'pending' && !r.value.removed,
);
const passed = computed(
    () => !!r.value && parseISO(r.value.ends_at) <= props.now,
);

const minutes = computed(() =>
    r.value
        ? Math.round(
              (parseISO(r.value.ends_at).getTime() -
                  parseISO(r.value.starts_at).getTime()) /
                  60_000,
          )
        : 0,
);

const blockedBy = computed(
    () => r.value?.conflicts.filter((c) => c.status === 'approved') ?? [],
);
const rivals = computed(
    () => r.value?.conflicts.filter((c) => c.status === 'pending') ?? [],
);

const overCapacity = computed(
    () =>
        !!r.value?.resource.capacity &&
        (r.value.attendee_count ?? 0) > r.value.resource.capacity,
);

/** The purpose only earns its line when it says more than the title. */
const purpose = computed(() => {
    const text = r.value?.purpose?.trim();

    return text && text !== r.value?.title.trim() ? text : null;
});

/* ---- The day, from the schedule loaded for this request ------------- */

const lane = computed(() =>
    props.schedule.types
        .flatMap((t) => t.resources)
        .find((res) => res.id === r.value?.resource.id),
);

/**
 * Same-type resources with nothing booked across this slot: what to
 * suggest when this one is taken. Only offered when there is a conflict.
 */
const freeInstead = computed(() => {
    const b = r.value;

    if (!b || (blockedBy.value.length === 0 && rivals.value.length === 0)) {
        return [];
    }

    const start = parseISO(b.starts_at).getTime();
    const end = parseISO(b.ends_at).getTime();

    return (
        props.schedule.types
            .find((t) => t.slug === b.type?.slug)
            ?.resources.filter(
                (res) =>
                    res.id !== b.resource.id &&
                    res.bookings.every(
                        (x) =>
                            parseISO(x.ends_at).getTime() <= start ||
                            parseISO(x.starts_at).getTime() >= end,
                    ),
            )
            .map((res) => res.name) ?? []
    );
});

/* ---- Decision -------------------------------------------------------- */

const approveForm = useForm<{ version: string | null }>({ version: null });
const rejectForm = useForm({ reason: '', version: null as string | null });

const goNext = () => {
    if (props.next) {
        router.visit(adminBookings.show(props.next).url, {
            preserveState: true,
            only: ['focus', 'schedule'],
        });
    }
};

const approve = () => {
    if (!r.value) {
        return;
    }

    approveForm.version = r.value.version;
    approveForm.post(adminBookings.approve(r.value.id).url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: goNext,
    });
};

const reject = (reason: string) => {
    if (!r.value) {
        return;
    }

    rejectForm.reason = reason;
    rejectForm.version = r.value.version;
    rejectForm.post(adminBookings.reject(r.value.id).url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            rejectForm.reset();
            goNext();
        },
    });
};

const error = computed(() => {
    const e = { ...approveForm.errors, ...rejectForm.errors } as Record<
        string,
        string | undefined
    >;

    return e.status ?? e.reason ?? e.version ?? null;
});

const blocked = computed(() =>
    blockedBy.value.length > 0 ? 'An approved booking holds this time.' : null,
);

/** Reasons that fit this request, so rejecting never starts from blank. */
const suggestions = computed(() => {
    const b = r.value;

    if (!b) {
        return [];
    }

    const list: string[] = [];
    const where = b.resource.name ?? 'This resource';

    if (blockedBy.value[0]) {
        list.push(
            `${where} is already booked ${timeRange(blockedBy.value[0].starts_at, blockedBy.value[0].ends_at)} for “${blockedBy.value[0].title}”.`,
        );
    }

    if (freeInstead.value[0]) {
        list.push(
            `${freeInstead.value[0]} is free then; please request it instead.`,
        );
    }

    if (overCapacity.value) {
        list.push(
            `${where} takes ${b.resource.capacity}; ${b.attendee_count} people won't fit.`,
        );
    }

    if (passed.value) {
        list.push('This request was not reviewed before its time passed.');
    }

    return list;
});

/** What happened, for a request that no longer waits. */
const outcome = computed(() => {
    const b = r.value;

    if (!b) {
        return null;
    }

    if (b.removed) {
        return {
            icon: CircleSlash,
            text: 'This request was removed.',
            reason: null,
        };
    }

    switch (b.status) {
        case 'approved':
            return {
                icon: Check,
                text: `Approved${b.reviewed_by ? ` by ${b.reviewed_by}` : ''} ${ago(b.reviewed_at)}`,
                reason: null,
            };
        case 'rejected':
            return {
                icon: X,
                text: `Rejected${b.reviewed_by ? ` by ${b.reviewed_by}` : ''} ${ago(b.reviewed_at)}`,
                reason: b.rejection_reason,
            };
        case 'cancelled':
            return {
                icon: CircleSlash,
                text: b.cancelled_by
                    ? `Cancelled by ${b.cancelled_by} ${ago(b.cancelled_at)}`
                    : `Withdrawn ${ago(b.cancelled_at)}`,
                reason: b.cancellation_reason,
            };
        default:
            return null;
    }
});
</script>

<template>
    <!-- A link that leads nowhere says so plainly. -->
    <div v-if="!r" class="py-10">
        <CircleSlash class="size-6 text-muted-foreground" aria-hidden="true" />
        <h2 class="mt-3 text-lg font-semibold tracking-tight">
            There is no request
            {{
                (review as ReviewMissing).id
                    ? `#${(review as ReviewMissing).id}`
                    : 'at this link'
            }}.
        </h2>
        <p class="mt-1 max-w-prose text-sm text-muted-foreground">
            It may have been withdrawn and removed, or the link is wrong.
            Nothing waits on you here.
        </p>
        <Link :href="listHref" :class="[pillQuiet, 'mt-4']"
            >Back to requests</Link
        >
    </div>

    <article
        v-else
        class="flex min-h-full flex-col"
        aria-labelledby="review-title"
    >
        <!-- Narrow screens: the way back to the list. -->
        <Link
            :href="listHref"
            class="mb-3 inline-flex items-center gap-1 self-start text-[13px] text-muted-foreground hover:text-foreground lg:hidden"
        >
            <ArrowLeft class="size-3.5" aria-hidden="true" />
            All requests
        </Link>

        <p
            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-muted-foreground"
        >
            <span
                v-if="r.type"
                class="inline-flex items-center gap-1.5 font-medium text-foreground"
            >
                <component
                    :is="resourceTypeIcon(r.type)"
                    class="size-3.5"
                    aria-hidden="true"
                />
                {{ r.type.name }}
            </span>
            <span class="tabular-nums">{{ r.reference }}</span>
            <span v-if="!decidable" class="font-medium text-foreground">
                ·
                {{
                    r.removed
                        ? 'Removed'
                        : r.status.charAt(0).toUpperCase() + r.status.slice(1)
                }}
            </span>
        </p>

        <h2
            id="review-title"
            class="mt-1.5 text-xl leading-snug font-semibold tracking-tight text-balance"
        >
            {{ r.title }}
        </h2>

        <p class="mt-2 text-[15px] font-medium tabular-nums">
            {{ dayLabel(r.starts_at, now) }} ·
            {{ timeRange(r.starts_at, r.ends_at) }}
            <span class="font-normal text-muted-foreground"
                >· {{ hoursLabel(minutes) }}</span
            >
        </p>
        <p class="mt-0.5 flex items-center gap-2 text-sm">
            <span
                class="size-2.5 shrink-0 rounded-full"
                :style="{
                    background: r.resource.color ?? 'var(--muted-foreground)',
                }"
                aria-hidden="true"
            />
            <span
                >{{ r.resource.name
                }}<span
                    v-if="r.resource.location"
                    class="text-muted-foreground"
                >
                    · {{ r.resource.location }}</span
                ></span
            >
        </p>

        <!-- What could change the decision, one sentence each, before anything else. -->
        <ul v-if="decidable" class="mt-4 space-y-1.5 text-sm empty:hidden">
            <li
                v-if="blockedBy.length"
                class="flex items-start gap-2 text-destructive"
                role="alert"
            >
                <CircleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    <span class="font-medium">Clash.</span>
                    “{{ blockedBy[0].title }}”{{
                        blockedBy[0].requester
                            ? ` (${blockedBy[0].requester})`
                            : ''
                    }}
                    is approved for
                    <span class="tabular-nums">{{
                        timeRange(blockedBy[0].starts_at, blockedBy[0].ends_at)
                    }}</span
                    >, so this can't be approved. Reject it, or cancel that
                    booking first.
                </span>
            </li>
            <li
                v-else-if="rivals.length"
                class="flex items-start gap-2 text-warning-ink"
            >
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    <span class="font-medium">Overlap.</span>
                    Approving this turns away
                    <template v-for="(c, i) in rivals" :key="c.id">
                        <Link
                            :href="adminBookings.show(c.id)"
                            preserve-state
                            :only="['focus', 'schedule']"
                            class="underline underline-offset-2 hover:text-foreground"
                            >“{{ c.title }}”</Link
                        >
                        <span class="tabular-nums">
                            {{ timeRange(c.starts_at, c.ends_at) }}</span
                        >{{ i < rivals.length - 1 ? ', ' : '.' }}
                    </template>
                </span>
            </li>
            <li
                v-if="overCapacity"
                class="flex items-start gap-2 text-warning-ink"
            >
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    <span class="font-medium">Over capacity.</span>
                    {{ r.attendee_count }} {{ people }};
                    {{ r.resource.name }} takes {{ r.resource.capacity }}.
                </span>
            </li>
            <li
                v-if="passed"
                class="flex items-start gap-2 text-muted-foreground"
            >
                <Clock class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span
                    >This time has passed. Deciding now only records the
                    outcome.</span
                >
            </li>
        </ul>

        <!-- The resource's day: is it free? -->
        <section class="mt-6" aria-labelledby="review-day">
            <div class="mb-2 flex items-baseline justify-between gap-3">
                <h3 id="review-day" class="text-[13px] font-medium">
                    {{ r.resource.name }} · {{ dayLabel(r.starts_at, now) }}
                </h3>
                <Link
                    :href="
                        admin.dashboard({
                            query: { date: isoDate(parseISO(r.starts_at)) },
                        })
                    "
                    class="text-[12px] text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    Every resource that day
                </Link>
            </div>
            <DayStrip
                :date="schedule.date"
                :bookings="lane?.bookings ?? []"
                :focus="r"
                :focus-id="r.id"
                :clash-ids="blockedBy.map((c) => c.id)"
                :color="r.resource.color"
                :now="now"
                :focus-label="decidable ? 'This request' : 'This booking'"
            />
            <p
                v-if="freeInstead.length"
                class="mt-2 text-[13px] text-muted-foreground"
            >
                Free at that time:
                <span class="text-foreground">{{
                    freeInstead.join(', ')
                }}</span>
            </p>
        </section>

        <!-- Who and why -->
        <dl
            class="mt-6 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2 [&_dt]:text-[13px] [&_dt]:text-muted-foreground"
        >
            <div>
                <dt>Requested by</dt>
                <dd class="mt-0.5">
                    <span class="font-medium">{{
                        r.requester.name ?? 'Unknown'
                    }}</span>
                    <span
                        v-if="r.requester.division"
                        class="text-muted-foreground"
                    >
                        · {{ r.requester.division }}</span
                    >
                    <span
                        v-if="r.requested_at && hydrated"
                        class="block text-[13px] text-muted-foreground"
                    >
                        {{ ago(r.requested_at) }}
                    </span>
                </dd>
            </div>
            <div>
                <dt>{{ People }}</dt>
                <dd class="mt-0.5 tabular-nums">
                    <span class="font-medium">{{
                        r.attendee_count ?? 'Not given'
                    }}</span>
                    <span
                        v-if="r.resource.capacity"
                        class="text-muted-foreground"
                    >
                        of {{ r.resource.capacity }} seats</span
                    >
                </dd>
            </div>
            <div v-if="purpose" class="sm:col-span-2">
                <dt>Purpose</dt>
                <dd class="mt-0.5 max-w-prose whitespace-pre-line">
                    {{ purpose }}
                </dd>
            </div>
            <div v-if="r.people.length" class="sm:col-span-2">
                <dt>{{ People }} by name</dt>
                <dd class="mt-1.5">
                    <PeopleRoster
                        :people="r.people"
                        :count="r.attendee_count"
                        :fold="6"
                    />
                </dd>
            </div>
        </dl>

        <div class="flex-1" />

        <!-- Decide -->
        <DecisionBar
            v-if="decidable"
            class="mt-8"
            approve-label="Approve"
            :blocked="blocked"
            :processing="approveForm.processing || rejectForm.processing"
            :requester="r.requester.name"
            :suggestions="suggestions"
            :error="error"
            @approve="approve"
            @reject="reject"
        />

        <!-- Decided (by anyone): the outcome, and where to go from here. -->
        <div v-else class="mt-8 border-t border-border pt-4" role="status">
            <p v-if="outcome" class="flex items-start gap-2 text-sm">
                <component
                    :is="outcome.icon"
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    <span class="font-medium">{{ outcome.text }}.</span>
                    <span
                        v-if="outcome.reason"
                        class="block text-muted-foreground"
                        >“{{ outcome.reason }}”</span
                    >
                </span>
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <Link
                    v-if="next"
                    :href="adminBookings.show(next)"
                    preserve-state
                    :only="['focus', 'schedule']"
                    :class="pillPrimary"
                >
                    Next request
                    <ArrowRight aria-hidden="true" />
                </Link>
                <!-- The PDF opens in a new tab, ready to print. -->
                <a
                    v-if="r.status === 'approved' && !r.removed"
                    :href="bookingsPage.pdf(r.id).url"
                    target="_blank"
                    rel="noopener"
                    :class="next ? pillQuiet : pillPrimary"
                >
                    <Printer aria-hidden="true" />
                    Print booking form
                </a>
                <Link
                    v-if="r.status === 'approved' && !r.removed && r.type"
                    :href="
                        resourcesPage.section(r.type.slug, {
                            query: {
                                date: isoDate(parseISO(r.starts_at)),
                                resource: r.resource.id,
                            },
                        })
                    "
                    :class="pillQuiet"
                >
                    Open in the {{ r.type.name }} calendar
                </Link>
            </div>
        </div>
    </article>
</template>
