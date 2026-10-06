<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, CircleAlert, Clock } from '@lucide/vue';
import { formatDistanceToNowStrict } from 'date-fns';
import { computed, onMounted, ref } from 'vue';
import { dayLabel, isoDate, timeRange } from '@/components/desk/desk';
import { parseISO } from '@/lib/calendarMath';
import adminEditRequests from '@/routes/admin/edit-requests';
import DayStrip from './DayStrip.vue';
import type { StripBooking } from './DayStrip.vue';
import DecisionBar from './DecisionBar.vue';

type Slot = {
    starts_at: string | null;
    ends_at: string | null;
    purpose: string | null;
    attendee_count: number | null;
    people: string[] | null;
};

export type PendingChange = {
    id: number;
    booking_id: number;
    reference: string;
    title: string;
    type: string | null;
    resource: {
        name: string | null;
        location: string | null;
        capacity: number | null;
        color: string | null;
    };
    requester: { name: string | null; division: string | null };
    requested_at: string | null;
    note: string | null;
    current: Slot & { starts_at: string; ends_at: string; people: string[] };
    proposed: Slot;
    passed: boolean;
    clash: (StripBooking & { requester: string | null }) | null;
    day: { date: string; bookings: StripBooking[] };
};

/**
 * One change request: what moves, against the day it would land on, and
 * only the fields that actually change. Applying is refused by the server
 * when the new slot is taken or has started, so both say so up front.
 */
const props = defineProps<{
    change: PendingChange;
    now: Date;
}>();

const emit = defineEmits<{ back: [] }>();

const c = computed(() => props.change);

const movesTime = computed(
    () =>
        c.value.proposed.starts_at !== null ||
        c.value.proposed.ends_at !== null,
);

const target = computed(() => ({
    starts_at: c.value.proposed.starts_at ?? c.value.current.starts_at,
    ends_at: c.value.proposed.ends_at ?? c.value.current.ends_at,
}));

/** The old slot is drawn only when it sits on the same day as the new one. */
const ghostHere = computed(
    () =>
        movesTime.value &&
        isoDate(parseISO(c.value.current.starts_at)) === c.value.day.date,
);

const when = (slot: { starts_at: string; ends_at: string }) =>
    `${dayLabel(slot.starts_at, props.now)} · ${timeRange(slot.starts_at, slot.ends_at)}`;

const same = (a: string, b: string) =>
    a.toLocaleLowerCase() === b.toLocaleLowerCase();

/** Who a new list adds and drops, against the booking as it is. */
const people = computed(() => {
    const next = c.value.proposed.people;

    if (!next) {
        return null;
    }

    const now = c.value.current.people;

    return {
        added: next.filter((n) => !now.some((m) => same(m, n))),
        removed: now.filter((n) => !next.some((m) => same(m, n))),
    };
});

const count = computed(() => {
    const next =
        c.value.proposed.attendee_count ??
        c.value.proposed.people?.length ??
        null;
    const now = c.value.current.attendee_count ?? c.value.current.people.length;

    return next !== null && next !== now ? { now, next } : null;
});

const purposeChanges = computed(
    () =>
        c.value.proposed.purpose !== null &&
        c.value.proposed.purpose !== c.value.current.purpose,
);

/** What stays put, in one muted line instead of a row per field. */
const unchanged = computed(
    () =>
        [
            !movesTime.value && 'time',
            !purposeChanges.value && 'purpose',
            !people.value && !count.value && 'people',
        ].filter(Boolean) as string[],
);

/** "Time stays the same", "Purpose and people stay the same". */
const unchangedText = computed(() => {
    const list = unchanged.value;
    const joined =
        list.length > 1
            ? `${list.slice(0, -1).join(', ')} and ${list.at(-1)}`
            : list[0];

    return `${joined.charAt(0).toUpperCase()}${joined.slice(1)} ${list.length === 1 ? 'stays' : 'stay'} the same.`;
});

const hydrated = ref(false);
onMounted(() => (hydrated.value = true));

/* ---- Decision -------------------------------------------------------- */

const applyForm = useForm({});
const rejectForm = useForm({ reason: '' });

const apply = () =>
    applyForm.post(adminEditRequests.apply(c.value.id).url, {
        preserveScroll: true,
        preserveState: true,
    });

const reject = (reason: string) => {
    rejectForm.reason = reason;
    rejectForm.post(adminEditRequests.reject(c.value.id).url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => rejectForm.reset(),
    });
};

const error = computed(() => {
    const e = { ...applyForm.errors, ...rejectForm.errors } as Record<
        string,
        string | undefined
    >;

    return (
        e.starts_at ??
        e.ends_at ??
        e.status ??
        e.reason ??
        Object.values(e)[0] ??
        null
    );
});

const blocked = computed(() => {
    if (c.value.passed) {
        return 'The new time has already started.';
    }

    return c.value.clash?.status === 'approved'
        ? 'The new time is already taken.'
        : null;
});

const suggestions = computed(() => {
    const list: string[] = [];

    if (c.value.clash) {
        list.push(
            `${c.value.resource.name ?? 'The resource'} is already booked ${timeRange(c.value.clash.starts_at, c.value.clash.ends_at)} for “${c.value.clash.title}”.`,
        );
    }

    if (c.value.passed) {
        list.push(
            'The new time has already started; please send a new request.',
        );
    }

    list.push('Your booking stays as it was.');

    return list;
});
</script>

<template>
    <article class="flex min-h-full flex-col" aria-labelledby="change-title">
        <button
            type="button"
            class="mb-3 inline-flex items-center gap-1 self-start text-[13px] text-muted-foreground hover:text-foreground lg:hidden"
            @click="emit('back')"
        >
            <ArrowLeft class="size-3.5" aria-hidden="true" />
            All changes
        </button>

        <p
            class="flex flex-wrap items-center gap-x-2 text-[13px] text-muted-foreground"
        >
            <span v-if="c.type" class="font-medium text-foreground">{{
                c.type
            }}</span>
            <span class="tabular-nums">{{ c.reference }}</span>
            <span>· Change request</span>
        </p>

        <h2
            id="change-title"
            class="mt-1.5 text-xl leading-snug font-semibold tracking-tight text-balance"
        >
            {{ c.title }}
        </h2>

        <!-- The move, old to new; or the time it keeps. -->
        <p
            v-if="movesTime"
            class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[15px] tabular-nums"
        >
            <s class="text-muted-foreground decoration-1">{{
                when(c.current)
            }}</s>
            <ArrowRight
                class="size-4 text-muted-foreground"
                aria-label="moves to"
            />
            <span class="font-medium">{{ when(target) }}</span>
        </p>
        <p v-else class="mt-2 text-[15px] font-medium tabular-nums">
            {{ when(c.current) }}
        </p>

        <p class="mt-0.5 flex items-center gap-2 text-sm">
            <span
                class="size-2.5 shrink-0 rounded-full"
                :style="{
                    background: c.resource.color ?? 'var(--muted-foreground)',
                }"
                aria-hidden="true"
            />
            <span
                >{{ c.resource.name
                }}<span
                    v-if="c.resource.location"
                    class="text-muted-foreground"
                >
                    · {{ c.resource.location }}</span
                ></span
            >
        </p>

        <ul class="mt-4 space-y-1.5 text-sm empty:hidden">
            <li
                v-if="c.clash"
                class="flex items-start gap-2 text-destructive"
                role="alert"
            >
                <CircleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    <span class="font-medium">Taken.</span>
                    “{{ c.clash.title }}”{{
                        c.clash.requester ? ` (${c.clash.requester})` : ''
                    }}
                    is {{ c.clash.status }} for
                    <span class="tabular-nums">{{
                        timeRange(c.clash.starts_at, c.clash.ends_at)
                    }}</span
                    >, so the change can't be applied.
                </span>
            </li>
            <li
                v-if="c.passed"
                class="flex items-start gap-2 text-muted-foreground"
            >
                <Clock class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span
                    >The new time has already started, so it can't be applied.
                    Reject it and ask for a new request.</span
                >
            </li>
        </ul>

        <!-- The day it would land on -->
        <section class="mt-6" aria-labelledby="change-day">
            <h3 id="change-day" class="mb-2 text-[13px] font-medium">
                {{ c.resource.name }} · {{ dayLabel(target.starts_at, now) }}
            </h3>
            <DayStrip
                :date="c.day.date"
                :bookings="c.day.bookings"
                :focus="target"
                :focus-id="c.booking_id"
                :ghost="ghostHere ? c.current : null"
                :clash-ids="c.clash ? [c.clash.id] : []"
                :color="c.resource.color"
                :now="now"
                :focus-label="movesTime ? 'New time' : 'This booking'"
            />
            <p v-if="ghostHere" class="mt-2 text-[12px] text-muted-foreground">
                Dashed: where it is now.
            </p>
        </section>

        <!-- Only what changes -->
        <dl
            class="mt-6 grid gap-y-4 text-sm [&_dt]:text-[13px] [&_dt]:text-muted-foreground"
        >
            <div v-if="purposeChanges">
                <dt>Purpose</dt>
                <dd class="mt-0.5 max-w-prose">
                    <s
                        v-if="c.current.purpose"
                        class="block text-muted-foreground decoration-1"
                        >{{ c.current.purpose }}</s
                    >
                    <span class="font-medium">{{ c.proposed.purpose }}</span>
                </dd>
            </div>
            <div v-if="people || count">
                <dt>People</dt>
                <dd class="mt-0.5 space-y-0.5">
                    <p v-if="count" class="tabular-nums">
                        <span class="text-muted-foreground"
                            >{{ count.now }} →</span
                        >
                        <span class="font-medium">{{ count.next }}</span>
                        <span
                            v-if="c.resource.capacity"
                            class="text-muted-foreground"
                        >
                            of {{ c.resource.capacity }} seats</span
                        >
                    </p>
                    <p v-if="people?.added.length">
                        <span class="text-muted-foreground">Adds</span>
                        {{ people.added.join(', ') }}
                    </p>
                    <p v-if="people?.removed.length">
                        <span class="text-muted-foreground">Drops</span>
                        {{ people.removed.join(', ') }}
                    </p>
                </dd>
            </div>
            <p
                v-if="unchanged.length"
                class="text-[13px] text-muted-foreground"
            >
                {{ unchangedText }}
            </p>

            <div v-if="c.note">
                <dt>Note from {{ c.requester.name ?? 'the requester' }}</dt>
                <dd class="mt-0.5 max-w-prose whitespace-pre-line">
                    “{{ c.note }}”
                </dd>
            </div>
            <div>
                <dt>Requested by</dt>
                <dd class="mt-0.5">
                    <span class="font-medium">{{
                        c.requester.name ?? 'Unknown'
                    }}</span>
                    <span
                        v-if="c.requester.division"
                        class="text-muted-foreground"
                    >
                        · {{ c.requester.division }}</span
                    >
                    <span
                        v-if="c.requested_at && hydrated"
                        class="text-muted-foreground"
                    >
                        ·
                        {{
                            formatDistanceToNowStrict(parseISO(c.requested_at))
                        }}
                        ago</span
                    >
                </dd>
            </div>
        </dl>

        <div class="flex-1" />

        <DecisionBar
            class="mt-8"
            approve-label="Apply change"
            :blocked="blocked"
            :processing="applyForm.processing || rejectForm.processing"
            :requester="c.requester.name"
            :suggestions="suggestions"
            :error="error"
            @approve="apply"
            @reject="reject"
        />
    </article>
</template>
