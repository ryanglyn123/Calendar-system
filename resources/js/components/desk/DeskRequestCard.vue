<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    ArrowDown,
    ArrowRight,
    CalendarClock,
    CalendarDays,
    Check,
    CircleAlert,
    History,
    MapPin,
    Sparkles,
    TriangleAlert,
    UserRound,
    UsersRound,
} from '@lucide/vue';
import { differenceInMinutes, formatDistanceToNowStrict } from 'date-fns';
import { computed, onMounted, ref, useId } from 'vue';
import { parseISO } from '@/lib/calendarMath';
import { cn } from '@/lib/utils';
import adminBookings from '@/routes/admin/bookings';
import adminEditRequests from '@/routes/admin/edit-requests';
import type { DeskRequest } from '@/types';
import {
    dayLabel,
    decisionUrls,
    hoursLabel,
    pillQuiet,
    pillSuccess,
    timeRange,
} from './desk';
import DeskRejectForm from './DeskRejectForm.vue';

const props = defineProps<{
    request: DeskRequest;
    now: Date;
    /** "Attendees" for rooms, "Passengers" for vehicles. */
    people: string;
}>();

const titleId = useId();
const rejecting = ref(false);
// Approval has no fields; the server reports a late clash under `status`.
const approveForm = useForm<{ status?: string }>({});

// "2 days ago" is filled in after hydration, so server and browser agree.
const hydrated = ref(false);
onMounted(() => (hydrated.value = true));

const asked = computed(() =>
    hydrated.value && props.request.requested_at
        ? `Asked ${formatDistanceToNowStrict(parseISO(props.request.requested_at))} ago`
        : '',
);

const slot = (startIso: string, endIso: string) => ({
    day: dayLabel(startIso, props.now),
    time: timeRange(startIso, endIso),
    length: hoursLabel(
        differenceInMinutes(parseISO(endIso), parseISO(startIso)),
    ),
});

const proposed = computed(() =>
    slot(props.request.starts_at, props.request.ends_at),
);

/** For a change: the booking as it stands, and what else moves. */
const change = computed(() => {
    const r = props.request;

    if (r.kind !== 'change') {
        return null;
    }

    return {
        now: slot(r.current.starts_at, r.current.ends_at),
        moves:
            r.current.starts_at !== r.starts_at ||
            r.current.ends_at !== r.ends_at,
        purpose:
            r.proposed_purpose !== null &&
            r.proposed_purpose !== r.current.purpose
                ? r.proposed_purpose
                : null,
        count:
            r.proposed_attendee_count !== null &&
            r.proposed_attendee_count !== r.current.attendee_count
                ? {
                      from: r.current.attendee_count,
                      to: r.proposed_attendee_count,
                  }
                : null,
    };
});

/** The purpose, when it says more than the title already does. */
const purpose = computed(() => {
    const r = props.request;
    const text = r.kind === 'booking' ? r.purpose : r.current.purpose;

    return text && text.trim() !== r.title.trim() ? text : null;
});

type Notice = {
    tone: 'danger' | 'warn' | 'quiet';
    icon: LucideIcon;
    title: string;
    text: string;
    /** The server would refuse approval, so the button is off. */
    blocks?: boolean;
};

/** At most one notice, the thing most likely to change the decision. */
const notice = computed<Notice | null>(() => {
    const r = props.request;
    const nowMs = props.now.getTime();

    if (r.kind === 'change') {
        return parseISO(r.starts_at).getTime() <= nowMs
            ? {
                  tone: 'quiet',
                  icon: History,
                  title: 'Too late to apply',
                  text: 'The new time has already started. Reject it and ask for a new request.',
                  blocks: true,
              }
            : null;
    }

    if (r.clash?.status === 'approved') {
        return {
            tone: 'danger',
            icon: CircleAlert,
            title: 'Already booked',
            text: `“${r.clash.title}” has ${timeRange(r.clash.starts_at, r.clash.ends_at)}. Reject this one, or cancel that booking first.`,
            blocks: true,
        };
    }

    if (r.clash) {
        return {
            tone: 'warn',
            icon: TriangleAlert,
            title: 'Another request wants this time',
            text: `“${r.clash.title}” (${timeRange(r.clash.starts_at, r.clash.ends_at)}) is waiting too. Approve one and the other can’t be approved.`,
        };
    }

    if (r.capacity !== null && (r.attendee_count ?? 0) > r.capacity) {
        return {
            tone: 'warn',
            icon: TriangleAlert,
            title: 'More people than seats',
            text: `${r.attendee_count} ${props.people.toLowerCase()}, but it seats ${r.capacity}.`,
        };
    }

    if (parseISO(r.ends_at).getTime() <= nowMs) {
        return {
            tone: 'quiet',
            icon: History,
            title: 'This date has passed',
            text: 'Deciding now only records what happened.',
        };
    }

    return null;
});

const TONES: Record<Notice['tone'], { box: string; ink: string }> = {
    danger: { box: 'bg-destructive/8', ink: 'text-destructive' },
    warn: { box: 'bg-warning/12', ink: 'text-warning-ink' },
    quiet: { box: 'bg-muted', ink: 'text-muted-foreground' },
};

const details = computed(() =>
    props.request.kind === 'booking'
        ? adminBookings.show(props.request.id)
        : adminEditRequests.index({ query: { request: props.request.id } }),
);

const big = 'h-11 px-5 text-sm [&_svg]:size-4';

const approve = () =>
    approveForm.post(
        decisionUrls[props.request.kind].approve(props.request.id),
        {
            preserveScroll: true,
            preserveState: true,
        },
    );
</script>

<template>
    <article
        class="flex flex-col gap-5 rounded-2xl bg-card p-5 ring-1 ring-border sm:p-6"
        :aria-labelledby="titleId"
    >
        <header class="flex flex-wrap items-center justify-between gap-2">
            <span
                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                :class="
                    request.kind === 'change'
                        ? 'bg-warning/15 text-warning-ink'
                        : 'bg-primary/8 text-foreground'
                "
            >
                <component
                    :is="request.kind === 'change' ? CalendarClock : Sparkles"
                    class="size-3.5"
                    aria-hidden="true"
                />
                {{
                    request.kind === 'change' ? 'Change request' : 'New request'
                }}
            </span>
            <span class="min-h-5 text-sm text-muted-foreground">{{
                asked
            }}</span>
        </header>

        <div class="space-y-1.5">
            <h3
                :id="titleId"
                class="text-lg leading-snug font-semibold text-balance [overflow-wrap:anywhere]"
            >
                {{ request.title }}
            </h3>
            <p
                v-if="purpose"
                class="text-[15px] leading-relaxed [overflow-wrap:anywhere] text-muted-foreground"
            >
                {{ purpose }}
            </p>
        </div>

        <!-- A change reads as before → after, so the difference is the
             first thing seen rather than something to work out. -->
        <div
            v-if="change && change.moves"
            class="grid items-center gap-3 rounded-xl bg-muted/60 p-4 sm:grid-cols-[1fr_auto_1fr]"
        >
            <div>
                <p class="text-xs font-semibold text-muted-foreground">
                    Booked now
                </p>
                <p class="mt-1 text-[15px] tabular-nums">
                    {{ change.now.day }} · {{ change.now.time }}
                </p>
            </div>
            <ArrowRight
                class="hidden size-5 text-muted-foreground sm:block"
                aria-hidden="true"
            />
            <ArrowDown
                class="size-5 text-muted-foreground sm:hidden"
                aria-hidden="true"
            />
            <div>
                <p class="text-xs font-semibold text-muted-foreground">
                    Wants to move to
                </p>
                <p class="mt-1 text-[15px] font-semibold tabular-nums">
                    {{ proposed.day }} · {{ proposed.time }}
                </p>
            </div>
        </div>

        <dl class="grid gap-x-6 gap-y-3 text-[15px] sm:grid-cols-2">
            <div v-if="!change || !change.moves" class="flex gap-3">
                <dt class="sr-only">When</dt>
                <CalendarDays
                    class="mt-0.5 size-[18px] shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <dd>
                    <span class="block font-medium">{{ proposed.day }}</span>
                    <span class="block text-muted-foreground tabular-nums">
                        {{ proposed.time }} · {{ proposed.length }}
                    </span>
                </dd>
            </div>
            <div class="flex gap-3">
                <dt class="sr-only">Where</dt>
                <MapPin
                    class="mt-0.5 size-[18px] shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <dd class="min-w-0">
                    <span
                        class="mr-2 inline-block size-2.5 rounded-full align-[1px]"
                        :style="{
                            background:
                                request.color ?? 'var(--muted-foreground)',
                        }"
                        aria-hidden="true"
                    />{{ request.resource }}
                </dd>
            </div>
            <div class="flex gap-3">
                <dt class="sr-only">Requested by</dt>
                <UserRound
                    class="mt-0.5 size-[18px] shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <dd class="min-w-0">
                    {{ request.requester ?? 'Unknown' }}
                    <span v-if="request.division" class="text-muted-foreground">
                        · {{ request.division }}</span
                    >
                </dd>
            </div>
            <div
                v-if="request.kind === 'booking' && request.attendee_count"
                class="flex gap-3"
            >
                <dt class="sr-only">{{ people }}</dt>
                <UsersRound
                    class="mt-0.5 size-[18px] shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <dd>
                    {{ request.attendee_count }}
                    {{ people.toLowerCase() }}
                    <span v-if="request.capacity" class="text-muted-foreground">
                        · seats {{ request.capacity }}</span
                    >
                </dd>
            </div>
        </dl>

        <ul
            v-if="change && (change.purpose || change.count)"
            class="space-y-1.5 text-[15px]"
        >
            <li v-if="change.purpose" class="[overflow-wrap:anywhere]">
                <span class="text-muted-foreground">New purpose:</span>
                {{ change.purpose }}
            </li>
            <li v-if="change.count" class="tabular-nums">
                <span class="text-muted-foreground">{{ people }}:</span>
                {{ change.count.from ?? 0 }} → {{ change.count.to }}
            </li>
        </ul>

        <blockquote
            v-if="request.kind === 'change' && request.note"
            class="border-l-2 border-border pl-4 text-[15px] leading-relaxed [overflow-wrap:anywhere]"
        >
            “{{ request.note }}”
            <footer class="mt-1 text-sm text-muted-foreground">
                {{ request.requester ?? 'The requester' }}
            </footer>
        </blockquote>

        <div
            v-if="notice"
            class="flex gap-3 rounded-xl px-4 py-3"
            :class="TONES[notice.tone].box"
        >
            <component
                :is="notice.icon"
                class="mt-0.5 size-[18px] shrink-0"
                :class="TONES[notice.tone].ink"
                aria-hidden="true"
            />
            <div class="text-sm leading-relaxed">
                <p class="font-semibold" :class="TONES[notice.tone].ink">
                    {{ notice.title }}
                </p>
                <p class="text-foreground/80">{{ notice.text }}</p>
            </div>
        </div>

        <p
            v-if="approveForm.errors.status"
            class="text-sm text-destructive"
            role="alert"
        >
            {{ approveForm.errors.status }}
        </p>

        <footer
            class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-3 border-t pt-4"
        >
            <template v-if="!rejecting">
                <Link
                    :href="details"
                    class="rounded-md text-sm font-medium text-muted-foreground underline-offset-4 outline-none hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring/60"
                >
                    See full details
                </Link>
                <div class="ml-auto flex items-center gap-3">
                    <button
                        type="button"
                        :class="cn(pillQuiet, big)"
                        @click="rejecting = true"
                    >
                        Reject
                    </button>
                    <button
                        type="button"
                        :class="cn(pillSuccess, big)"
                        :disabled="approveForm.processing || notice?.blocks"
                        @click="approve"
                    >
                        <Check aria-hidden="true" />
                        {{
                            request.kind === 'change'
                                ? 'Apply change'
                                : 'Approve'
                        }}
                    </button>
                </div>
            </template>
            <DeskRejectForm
                v-else
                class="w-full"
                :kind="request.kind"
                :id="request.id"
                @cancel="rejecting = false"
            />
        </footer>
    </article>
</template>
