<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowUpRight,
    Check,
    CircleAlert,
    FileText,
    Printer,
    Users,
} from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import { toast } from 'vue-sonner';
import PeopleRoster from '@/components/booking/PeopleRoster.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { api, ApiError } from '@/lib/api';
import { BOOKING_STATE, bookingTone } from '@/lib/bookingDisplay';
import { eventInk, eventWash, format, parseISO } from '@/lib/calendarMath';
import { relative } from '@/lib/eventDisplay';
import bookingsApi from '@/routes/api/bookings';
import bookingsPage from '@/routes/bookings';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import type { EventOccurrence } from '@/types/calendar';

/**
 * The booking half of the event popup: what state it is in and why, in
 * words for this viewer, and the actions this viewer may take. Reviewers
 * decide here; cancelling an approved booking takes two different steps
 * (reason, then a typed confirmation). Every action sends the version the
 * viewer saw, so a decision on stale data is refused, not applied.
 */
const props = defineProps<{
    occurrence: EventOccurrence;
}>();

const emit = defineEmits<{
    /** The booking changed (or turned out stale); reload what is shown. */
    changed: [];
}>();

const grid = useCalendarGridStore();

const booking = computed(() => props.occurrence.booking!);
const state = computed(() => BOOKING_STATE[booking.value.status]);

type Mode =
    'idle' | 'rejecting' | 'withdrawing' | 'cancel-reason' | 'cancel-confirm';

const mode = ref<Mode>('idle');
const reason = ref('');
const typed = ref('');
const busy = ref(false);
const error = ref<string | null>(null);

/** The booking a cancel flow targets: this one, or a clashing one. */
const cancelTarget = ref<EventOccurrence | null>(null);

watch(
    () => props.occurrence.occurrence_id,
    () => reset(),
);

function reset(): void {
    mode.value = 'idle';
    reason.value = '';
    typed.value = '';
    error.value = null;
    cancelTarget.value = null;
}

const when = (target: EventOccurrence) =>
    `${format(parseISO(target.starts_at), 'EEE, MMM d · HH:mm')}–${format(parseISO(target.ends_at), 'HH:mm')}`;

const ago = (iso: string | null) =>
    iso ? relative(new Date(iso).getTime(), Date.now()) : null;

/** One sentence on where this booking stands, for this viewer. */
const standing = computed(() => {
    const b = booking.value;
    const who = b.is_mine ? 'You' : (b.requested_by ?? 'Someone');

    switch (b.status) {
        case 'pending':
            return b.can_review
                ? `${who} asked ${ago(b.requested_at) ?? 'recently'}. It holds the slot until someone decides.`
                : b.is_mine
                  ? `Waiting for a ${b.resource_type ?? 'resource'} reviewer. You'll be notified when it's decided.`
                  : `Requested by ${who}; the slot is held until it's decided.`;
        case 'approved':
            return b.reviewed_by
                ? `Approved by ${b.reviewed_by}${b.reviewed_at ? `, ${format(parseISO(b.reviewed_at), 'MMM d, HH:mm')}` : ''}.`
                : `Booked by ${who}.`;
        case 'rejected':
            return `Rejected${b.reviewed_by ? ` by ${b.reviewed_by}` : ''}. The slot is free again.`;
        case 'cancelled':
            return b.cancelled_by_reviewer
                ? 'Cancelled by a reviewer. The slot is free again.'
                : `${b.is_mine ? 'You' : who} cancelled this booking. The slot is free again.`;
    }

    return '';
});

const closingReason = computed(
    () => booking.value.rejection_reason ?? booking.value.cancellation_reason,
);

/**
 * Other bookings holding the same room or vehicle at an overlapping time.
 * Only reviewers see them; they are the ones who can resolve a clash.
 */
const conflicts = computed(() => {
    if (!booking.value.can_review || !state.value.active) {
        return [];
    }

    const start = new Date(props.occurrence.starts_at);
    const end = new Date(props.occurrence.ends_at);

    return grid
        .occurrencesBetween(start, end)
        .filter(
            (other) =>
                other.occurrence_id !== props.occurrence.occurrence_id &&
                other.calendar_id === props.occurrence.calendar_id &&
                other.booking !== null &&
                BOOKING_STATE[other.booking.status].active,
        );
});

const blockedByApproved = computed(
    () =>
        booking.value.status === 'pending' &&
        conflicts.value.some((other) => other.booking?.status === 'approved'),
);

async function send(
    url: string,
    body: Record<string, unknown>,
    success: string,
): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        await api.post(url, body);
        toast.success(success);
        reset();
        emit('changed');
    } catch (failure) {
        if (failure instanceof ApiError && failure.status === 409) {
            // Someone else changed it first: show the latest instead.
            toast.error(failure.message);
            reset();
            emit('changed');

            return;
        }

        error.value =
            failure instanceof ApiError
                ? (Object.values(failure.errors)[0]?.[0] ?? failure.message)
                : 'Could not reach the server. Nothing was changed; try again.';
    } finally {
        busy.value = false;
    }
}

const approve = () =>
    send(
        bookingsApi.approve(booking.value.id).url,
        { version: booking.value.version },
        `Approved. ${booking.value.requested_by ?? 'The requester'} has been notified.`,
    );

const reject = () =>
    send(
        bookingsApi.reject(booking.value.id).url,
        { reason: reason.value.trim(), version: booking.value.version },
        `Rejected. ${booking.value.requested_by ?? 'The requester'} will see your reason.`,
    );

const withdraw = () =>
    send(
        bookingsApi.cancel(booking.value.id).url,
        { version: booking.value.version },
        booking.value.status === 'pending'
            ? 'Request withdrawn.'
            : 'Booking cancelled. The slot is free again.',
    );

function startCancel(target: EventOccurrence): void {
    reset();
    cancelTarget.value = target;
    mode.value = 'cancel-reason';
}

const cancelConfirmed = computed(
    () => typed.value.trim().toLowerCase() === 'cancel',
);

const cancelApproved = () => {
    const target = cancelTarget.value;

    if (!target?.booking || !cancelConfirmed.value) {
        return;
    }

    void send(
        bookingsApi.cancel(target.booking.id).url,
        { reason: reason.value.trim(), version: target.booking.version },
        `Booking cancelled. ${target.booking.requested_by ?? 'The requester'} has been notified.`,
    );
};

const reasonId = useId();
const typedId = useId();

const swatch = (target: EventOccurrence) => ({
    '--ev': bookingTone(target) ?? '#d4d8dd',
    '--wash': eventWash(bookingTone(target)),
    '--ink': eventInk(bookingTone(target)),
});
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border"
        :aria-label="`Booking, ${state.label}`"
    >
        <!-- State -->
        <div class="flex items-start gap-3 border-b bg-muted/40 px-3.5 py-3">
            <span
                class="mt-0.5 flex h-5 w-8 shrink-0 items-center justify-center rounded-[5px]"
                :class="state.fill"
                :style="swatch(occurrence)"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span
                        class="inline-flex items-center gap-1.5 text-sm font-semibold"
                    >
                        <component
                            :is="state.icon"
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ state.label }}
                    </span>
                    <span
                        v-if="booking.is_mine"
                        class="rounded-full bg-primary px-2 py-px text-[11px] font-medium text-primary-foreground"
                    >
                        Your booking
                    </span>
                    <span
                        class="ml-auto text-[11px] text-muted-foreground tabular-nums"
                    >
                        {{ booking.reference }}
                    </span>
                </p>
                <p class="mt-0.5 text-[13px] text-muted-foreground">
                    {{ standing }}
                </p>
                <p
                    v-if="closingReason"
                    class="mt-2 rounded-lg bg-background px-2.5 py-2 text-[13px]"
                >
                    <span class="text-muted-foreground">Reason:</span>
                    {{ closingReason }}
                </p>
            </div>
        </div>

        <!-- Details -->
        <dl
            class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 px-3.5 py-3 text-[13px]"
        >
            <dt class="text-muted-foreground">
                {{ booking.resource_type ?? 'Resource' }}
            </dt>
            <dd class="text-right font-medium">
                {{ booking.resource ?? '—' }}
            </dd>
            <template v-if="booking.requested_by">
                <dt class="text-muted-foreground">Requested by</dt>
                <dd class="text-right">
                    {{ booking.is_mine ? 'You' : booking.requested_by }}
                </dd>
            </template>
            <template v-if="booking.attendee_count || booking.capacity">
                <dt class="flex items-center gap-1.5 text-muted-foreground">
                    <Users class="size-3.5" aria-hidden="true" />
                    People
                </dt>
                <dd class="text-right tabular-nums">
                    {{ booking.attendee_count ?? '—' }}
                    <span v-if="booking.capacity" class="text-muted-foreground">
                        / {{ booking.capacity }} seats
                    </span>
                </dd>
            </template>
            <template v-if="booking.purpose">
                <dt class="flex items-start gap-1.5 text-muted-foreground">
                    <FileText class="mt-0.5 size-3.5" aria-hidden="true" />
                    Purpose
                </dt>
                <dd class="text-right">{{ booking.purpose }}</dd>
            </template>
        </dl>

        <!-- Names: only the requester and this type's reviewers get them. -->
        <div v-if="booking.people !== null" class="border-t px-3.5 py-3">
            <PeopleRoster
                :people="booking.people"
                :count="booking.attendee_count"
                :fold="6"
            />
        </div>

        <!-- Clashes: reviewers only -->
        <div
            v-if="conflicts.length > 0 && mode === 'idle'"
            class="border-t px-3.5 py-3"
        >
            <p
                class="flex items-center gap-1.5 text-[13px] font-medium"
                :class="
                    blockedByApproved ? 'text-destructive' : 'text-warning-ink'
                "
            >
                <CircleAlert class="size-3.5" aria-hidden="true" />
                Overlaps {{ conflicts.length }}
                {{
                    conflicts.length === 1 ? 'other booking' : 'other bookings'
                }}
            </p>
            <ul class="mt-2 space-y-1.5">
                <li
                    v-for="other in conflicts"
                    :key="other.occurrence_id"
                    class="flex items-center gap-2.5 text-[13px]"
                >
                    <span
                        class="h-3.5 w-5 shrink-0 rounded-[3px]"
                        :class="BOOKING_STATE[other.booking!.status].fill"
                        :style="swatch(other)"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{
                            other.title
                        }}</span>
                        <span class="block text-xs text-muted-foreground">
                            {{ BOOKING_STATE[other.booking!.status].label }} ·
                            {{ other.booking!.requested_by }} ·
                            {{ format(parseISO(other.starts_at), 'HH:mm') }}–{{
                                format(parseISO(other.ends_at), 'HH:mm')
                            }}
                        </span>
                    </span>
                    <Button
                        v-if="
                            other.booking!.status === 'approved' &&
                            other.booking!.can_review
                        "
                        variant="outline"
                        size="sm"
                        class="h-7 shrink-0 text-xs"
                        @click="startCancel(other)"
                    >
                        Cancel it…
                    </Button>
                </li>
            </ul>
            <p
                v-if="blockedByApproved"
                class="mt-2 text-xs text-muted-foreground"
            >
                An approved booking already holds this time. Reject this
                request, or cancel the approved one first; nothing is resolved
                for you.
            </p>
        </div>

        <!-- Error from the last action -->
        <p
            v-if="error"
            class="flex items-start gap-1.5 border-t px-3.5 py-2.5 text-[13px] text-destructive"
            role="alert"
        >
            <CircleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            {{ error }}
        </p>

        <!-- Actions -->
        <div
            v-if="
                mode === 'idle' &&
                (booking.can_review || booking.can_cancel || booking.is_mine)
            "
            class="flex flex-wrap items-center justify-end gap-2 border-t px-3.5 py-3"
        >
            <!-- The booking's own page and, once approved, its form. -->
            <div class="mr-auto flex items-center gap-1">
                <Button variant="ghost" size="sm" class="h-8 px-2" as-child>
                    <Link :href="bookingsPage.show(booking.id)">
                        Details
                        <ArrowUpRight aria-hidden="true" />
                    </Link>
                </Button>
                <Button
                    v-if="booking.status === 'approved'"
                    variant="ghost"
                    size="sm"
                    class="h-8 px-2"
                    as-child
                >
                    <a
                        :href="bookingsPage.pdf(booking.id).url"
                        target="_blank"
                        rel="noopener"
                    >
                        <Printer aria-hidden="true" />
                        Print form
                    </a>
                </Button>
            </div>
            <template v-if="booking.can_review && booking.status === 'pending'">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="busy"
                    @click="mode = 'rejecting'"
                >
                    Reject
                </Button>
                <Button
                    variant="success"
                    size="sm"
                    :disabled="busy || blockedByApproved"
                    @click="approve"
                >
                    <Check aria-hidden="true" />
                    Approve
                </Button>
            </template>
            <Button
                v-else-if="booking.can_review && booking.status === 'approved'"
                variant="outline"
                size="sm"
                class="text-destructive"
                :disabled="busy"
                @click="startCancel(occurrence)"
            >
                Cancel booking…
            </Button>
            <Button
                v-else-if="booking.can_cancel"
                variant="outline"
                size="sm"
                :disabled="busy"
                @click="mode = 'withdrawing'"
            >
                {{
                    booking.status === 'pending'
                        ? 'Withdraw request'
                        : 'Cancel my booking'
                }}
            </Button>
        </div>

        <!-- Reject: reason first -->
        <form
            v-else-if="mode === 'rejecting'"
            class="grid gap-2 border-t px-3.5 py-3"
            @submit.prevent="reject"
        >
            <label :for="reasonId" class="text-[13px] font-medium">
                Why are you rejecting it?
                <span class="font-normal text-muted-foreground">
                    {{ booking.requested_by ?? 'The requester' }} sees this.
                </span>
            </label>
            <Textarea
                :id="reasonId"
                v-model="reason"
                rows="2"
                maxlength="1000"
                placeholder="e.g. The room is reserved for the board visit that morning."
            />
            <div class="flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" @click="reset">
                    Back
                </Button>
                <Button
                    type="submit"
                    variant="destructive"
                    size="sm"
                    :disabled="busy || reason.trim().length < 10"
                >
                    Send rejection
                </Button>
            </div>
        </form>

        <!-- Requester withdraws: one plain confirmation -->
        <div
            v-else-if="mode === 'withdrawing'"
            class="flex flex-wrap items-center justify-between gap-2 border-t px-3.5 py-3"
        >
            <p class="text-[13px]">
                {{
                    booking.status === 'pending'
                        ? 'Withdraw this request? The slot opens for others.'
                        : 'Cancel your approved booking? The slot opens for others.'
                }}
            </p>
            <div class="flex gap-2">
                <Button variant="ghost" size="sm" @click="reset"
                    >Keep it</Button
                >
                <Button
                    variant="destructive"
                    size="sm"
                    :disabled="busy"
                    @click="withdraw"
                >
                    {{
                        booking.status === 'pending'
                            ? 'Withdraw'
                            : 'Cancel booking'
                    }}
                </Button>
            </div>
        </div>

        <!-- Cancel an approved booking, step 1 of 2: what it means, and why -->
        <form
            v-else-if="mode === 'cancel-reason' && cancelTarget"
            class="grid gap-3 border-t bg-destructive/[0.04] px-3.5 py-3"
            @submit.prevent="mode = 'cancel-confirm'"
        >
            <p class="text-sm font-semibold">Cancel this approved booking?</p>
            <div class="rounded-lg border bg-background px-3 py-2 text-[13px]">
                <p class="font-medium">{{ cancelTarget.title }}</p>
                <p class="text-muted-foreground">
                    {{ cancelTarget.booking?.resource }} ·
                    {{ when(cancelTarget) }}
                </p>
                <p class="text-muted-foreground">
                    Requested by {{ cancelTarget.booking?.requested_by }}
                </p>
            </div>
            <ul
                class="list-disc space-y-0.5 pl-5 text-[13px] text-muted-foreground"
            >
                <li>
                    {{ cancelTarget.booking?.requested_by ?? 'The requester' }}
                    is notified in the app and by email, with your reason.
                </li>
                <li>The time opens for new requests right away.</li>
                <li>
                    The booking stays in its history and the audit log. It
                    cannot be restored; they would need to request again.
                </li>
            </ul>
            <label :for="reasonId" class="text-[13px] font-medium">
                Reason for the requester
            </label>
            <Textarea
                :id="reasonId"
                v-model="reason"
                rows="2"
                maxlength="1000"
                placeholder="e.g. The room is needed for the Chairperson's visit."
            />
            <div class="flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" @click="reset">
                    Keep booking
                </Button>
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    :disabled="reason.trim().length < 10"
                >
                    Continue
                </Button>
            </div>
        </form>

        <!-- Step 2 of 2: a typed confirmation, not a second click -->
        <form
            v-else-if="mode === 'cancel-confirm' && cancelTarget"
            class="grid gap-3 border-t bg-destructive/[0.06] px-3.5 py-3"
            @submit.prevent="cancelApproved"
        >
            <p class="text-sm">
                You are cancelling
                <span class="font-semibold">{{ cancelTarget.title }}</span>
                for {{ cancelTarget.booking?.requested_by }},
                {{ when(cancelTarget) }}.
            </p>
            <label :for="typedId" class="text-[13px]">
                Type <span class="font-semibold">cancel</span> to confirm.
            </label>
            <Input
                :id="typedId"
                v-model="typed"
                autocomplete="off"
                spellcheck="false"
                class="max-w-48"
            />
            <div class="flex flex-wrap justify-between gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="mode = 'cancel-reason'"
                >
                    <ArrowLeft aria-hidden="true" />
                    Back
                </Button>
                <Button
                    type="submit"
                    variant="destructive"
                    size="sm"
                    :disabled="busy || !cancelConfirmed"
                >
                    Cancel booking now
                </Button>
            </div>
        </form>
    </section>
</template>
