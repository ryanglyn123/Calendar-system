<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarDays,
    Download,
    Hourglass,
    Pencil,
    Printer,
    ShieldCheck,
} from '@lucide/vue';
import { defaultDocument, defaultWindow, useEventListener } from '@vueuse/core';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import PeopleEditor from '@/components/booking/PeopleEditor.vue';
import PeopleRoster from '@/components/booking/PeopleRoster.vue';
import { isoDate, pillPrimary, pillQuiet } from '@/components/desk/desk';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useConfirm } from '@/composables/useConfirm';
import { api, ApiError } from '@/lib/api';
import { BOOKING_STATE } from '@/lib/bookingDisplay';
import { format, isSameDay, parseISO } from '@/lib/calendarMath';
import { peopleProblems, peopleToSend } from '@/lib/people';
import { resourceNouns, resourceTypeIcon } from '@/lib/resourceTypeIcon';
import adminBookings from '@/routes/admin/bookings';
import bookingsApi from '@/routes/api/bookings';
import editRequestsApi from '@/routes/api/bookings/edit-requests';
import bookingsPage from '@/routes/bookings';
import resourcesPage from '@/routes/resources';
import type { BookingStatus } from '@/types/calendar';

type BookingDetail = {
    id: number;
    reference: string;
    title: string;
    status: BookingStatus;
    starts_at: string;
    ends_at: string;
    purpose: string | null;
    attendee_count: number | null;
    people: string[];
    type: {
        name: string;
        slug: string;
        icon: string | null;
        requires_approval: boolean;
    };
    resource: {
        id: number;
        name: string;
        location: string | null;
        capacity: number | null;
        color: string | null;
    };
    requester: {
        name: string | null;
        email: string | null;
        division: string | null;
    };
    requested_at: string | null;
    reviewed_by: string | null;
    reviewed_at: string | null;
    rejection_reason: string | null;
    cancelled_by: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    cancelled_by_reviewer: boolean;
    pending_change: {
        starts_at: string | null;
        ends_at: string | null;
        purpose: string | null;
        people: string[] | null;
        note: string | null;
        requested_at: string | null;
    } | null;
    is_mine: boolean;
    version: string | null;
    can: {
        print: boolean;
        revise: boolean;
        request_change: boolean;
        cancel: boolean;
        review: boolean;
    };
};

/**
 * One booking, for its requester and its type's approvers: where it
 * stands, everything on record, and, once approved, its printable form.
 * This is where "your booking was approved" lands. It keeps itself
 * current, so a decision made elsewhere shows up without a refresh.
 */
const props = defineProps<{ booking: BookingDetail }>();

const b = computed(() => props.booking);
const nouns = computed(() => resourceNouns(b.value.type));
const vehicle = computed(() => nouns.value.one === 'vehicle');
const personNoun = computed(() => (vehicle.value ? 'passenger' : 'attendee'));
const state = computed(() => BOOKING_STATE[b.value.status]);

const reload = () => router.reload({ only: ['booking'] });

// A decision can land at any moment: keep checking while it can change.
usePoll(30_000, { only: ['booking'] }, { keepAlive: false });
useEventListener(defaultDocument, 'visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        reload();
    }
});
useEventListener(defaultWindow, 'bookings:changed', reload);

/* ---- Wording ------------------------------------------------------------ */

const stamp = (iso: string | null) =>
    iso ? format(parseISO(iso), 'MMM d, yyyy, HH:mm') : null;

const when = computed(() => {
    const start = parseISO(b.value.starts_at);
    const end = parseISO(b.value.ends_at);

    return isSameDay(start, end)
        ? `${format(start, 'EEEE, MMMM d, yyyy')} · ${format(start, 'HH:mm')}–${format(end, 'HH:mm')}`
        : `${format(start, 'EEE, MMM d, HH:mm')} – ${format(end, 'EEE, MMM d, yyyy, HH:mm')}`;
});

const ended = computed(() => parseISO(b.value.ends_at).getTime() <= Date.now());

/** One line on where the booking stands, for this viewer. */
const banner = computed(() => {
    const x = b.value;

    switch (x.status) {
        case 'pending':
            return {
                tone: 'warning',
                title: x.is_mine
                    ? `Waiting for a ${x.type.name} approver`
                    : 'Waiting for a decision',
                body: x.is_mine
                    ? 'You’ll get a notification the moment it’s decided. Until then you can still correct the details or the people.'
                    : `Requested by ${x.requester.name ?? 'someone'} ${stamp(x.requested_at) ? `on ${stamp(x.requested_at)}` : ''}.`,
            };
        case 'approved':
            return {
                tone: 'success',
                title: 'Approved',
                body: x.reviewed_by
                    ? `By ${x.reviewed_by}, ${stamp(x.reviewed_at)}. Print the booking form for everyone to sign on the day.`
                    : 'Confirmed. Print the booking form for everyone to sign on the day.',
            };
        case 'rejected':
            return {
                tone: 'danger',
                title: `Rejected${x.reviewed_by ? ` by ${x.reviewed_by}` : ''}`,
                body: x.rejection_reason ?? 'No reason was given.',
            };
        default:
            return {
                tone: 'muted',
                title: x.cancelled_by_reviewer
                    ? `Cancelled by ${x.cancelled_by ?? 'a reviewer'}`
                    : `Cancelled${x.is_mine ? ' by you' : ''}`,
                body: `${x.cancellation_reason ? `${x.cancellation_reason} ` : ''}The slot is free again, and this booking has no form to print.`,
            };
    }
});

const bannerClass = computed(
    () =>
        ({
            warning: 'bg-warning/12 ring-warning/30',
            success: 'bg-success/12 ring-success/30',
            danger: 'bg-destructive/8 ring-destructive/25',
            muted: 'bg-muted/70 ring-border',
        })[banner.value.tone],
);

const calendarLink = computed(() =>
    resourcesPage.section(b.value.type.slug, {
        query: {
            date: isoDate(parseISO(b.value.starts_at)),
            resource: b.value.resource.id,
        },
    }),
);

/* ---- Editing: a pending request directly, an approved one by request ---- */

type Mode = 'view' | 'revise' | 'change';

const mode = ref<Mode>('view');
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});
const draft = ref({ title: '', purpose: '', people: [] as string[], note: '' });

function start(next: Mode): void {
    draft.value = {
        title: b.value.title,
        purpose: b.value.purpose ?? '',
        people: b.value.people.length > 0 ? [...b.value.people] : [''],
        note: '',
    };
    errors.value = {};
    mode.value = next;
}

const clearPeopleErrors = () => {
    errors.value = Object.fromEntries(
        Object.entries(errors.value).filter(([k]) => !k.startsWith('people')),
    );
};

async function save(): Promise<void> {
    if (saving.value) {
        return;
    }

    const problems = {
        ...(mode.value === 'revise' && !draft.value.title.trim()
            ? { title: ['Give the booking a title.'] }
            : {}),
        ...peopleProblems(draft.value.people, {
            capacity: b.value.resource.capacity,
            name: b.value.resource.name,
        }),
    };

    if (Object.keys(problems).length > 0) {
        errors.value = problems;

        return;
    }

    saving.value = true;
    errors.value = {};

    const people = peopleToSend(draft.value.people);

    try {
        if (mode.value === 'revise') {
            await api.patch(bookingsApi.update(b.value.id).url, {
                title: draft.value.title.trim(),
                purpose: draft.value.purpose.trim() || null,
                people,
                version: b.value.version,
            });
            toast.success(
                'Request updated. The approver sees the new details.',
            );
        } else {
            const peopleChanged =
                people.join('\n') !== b.value.people.join('\n');
            const purpose = draft.value.purpose.trim();

            await api.post(editRequestsApi.store(b.value.id).url, {
                people: peopleChanged ? people : null,
                purpose:
                    purpose !== (b.value.purpose ?? '')
                        ? purpose || null
                        : null,
                note: draft.value.note.trim() || null,
            });
            toast.success('Change requested. An approver will review it.');
        }

        mode.value = 'view';
        reload();
    } catch (error) {
        if (error instanceof ApiError && error.status === 409) {
            toast.error(error.message);
            mode.value = 'view';
            reload();
        } else if (error instanceof ApiError) {
            errors.value =
                Object.keys(error.errors).length > 0
                    ? error.errors
                    : { form: [error.message] };

            // Decided meanwhile: show where it stands now.
            if (errors.value.status) {
                reload();
            }
        } else {
            errors.value = {
                form: [
                    'Could not reach the server. Check your connection and try again.',
                ],
            };
        }
    } finally {
        saving.value = false;
    }
}

/* ---- Withdrawing ------------------------------------------------------- */

const { confirm } = useConfirm();

async function withdraw(): Promise<void> {
    const pending = b.value.status === 'pending';
    const ok = await confirm({
        title: pending ? 'Withdraw this request?' : 'Cancel this booking?',
        description: pending
            ? 'The approver won’t see it any more, and the slot opens for others.'
            : 'The slot opens for others, and its booking form stops being valid.',
        confirmLabel: pending ? 'Withdraw request' : 'Cancel booking',
        destructive: true,
    });

    if (!ok) {
        return;
    }

    try {
        await api.post(bookingsApi.cancel(b.value.id).url, {
            version: b.value.version,
        });
        toast.success(pending ? 'Request withdrawn.' : 'Booking cancelled.');
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'Could not reach the server.',
        );
    } finally {
        reload();
    }
}
</script>

<template>
    <Head :title="`${b.reference} · ${b.title}`" />

    <div
        class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 pt-5 pb-12 sm:px-6"
    >
        <Link
            :href="calendarLink"
            class="inline-flex w-fit items-center gap-1.5 rounded-full text-[13px] text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
            {{ b.type.name }} calendar
        </Link>

        <header class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="inline-flex h-7 items-center gap-1.5 rounded-full bg-foreground px-2.5 text-xs font-semibold text-background"
                >
                    <component
                        :is="resourceTypeIcon(b.type)"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    {{ b.type.name }}
                </span>
                <span
                    class="inline-flex h-7 items-center gap-1.5 rounded-full px-2.5 text-xs font-medium ring-1 ring-border ring-inset"
                >
                    <component
                        :is="state.icon"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    {{ state.label }}
                </span>
                <span
                    v-if="ended"
                    class="rounded-full bg-muted px-2 py-px text-[11px] font-medium text-muted-foreground"
                >
                    Ended
                </span>
                <span
                    class="ml-auto text-sm text-muted-foreground tabular-nums"
                >
                    {{ b.reference }}
                </span>
            </div>
            <h1
                class="text-2xl leading-tight font-semibold tracking-tight text-balance"
            >
                {{ b.title }}
            </h1>
            <p class="text-[15px] text-muted-foreground tabular-nums">
                {{ b.resource.name }} · {{ when }}
            </p>
        </header>

        <!-- Where it stands, in words, with what to do next -->
        <section
            class="flex flex-col gap-3 rounded-2xl p-4 ring-1 ring-inset sm:flex-row sm:items-center"
            :class="bannerClass"
            role="status"
            aria-live="polite"
        >
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <component
                    :is="b.status === 'pending' ? Hourglass : state.icon"
                    class="mt-0.5 size-5 shrink-0"
                    aria-hidden="true"
                />
                <div class="min-w-0">
                    <p class="font-semibold">{{ banner.title }}</p>
                    <p class="mt-0.5 text-sm text-foreground/80">
                        {{ banner.body }}
                    </p>
                </div>
            </div>
            <div
                v-if="b.can.print"
                class="flex shrink-0 flex-wrap gap-2 sm:justify-end"
            >
                <!-- A new tab: the PDF opens in the browser's viewer, ready to print. -->
                <a
                    :href="bookingsPage.pdf(b.id).url"
                    target="_blank"
                    rel="noopener"
                    :class="pillPrimary"
                    data-test="print-form"
                >
                    <Printer aria-hidden="true" />
                    Print booking form
                </a>
                <a
                    :href="
                        bookingsPage.pdf(b.id, { query: { download: 1 } }).url
                    "
                    :class="pillQuiet"
                >
                    <Download aria-hidden="true" />
                    PDF
                </a>
            </div>
            <Link
                v-else-if="b.can.review && b.status === 'pending'"
                :href="adminBookings.show(b.id)"
                :class="[pillPrimary, 'shrink-0']"
            >
                <ShieldCheck aria-hidden="true" />
                Review it
            </Link>
        </section>

        <!-- A change waiting on an approver -->
        <section
            v-if="b.pending_change"
            class="rounded-2xl bg-muted/60 p-4 text-sm"
        >
            <p class="font-semibold">A change is waiting for review</p>
            <p class="mt-0.5 text-muted-foreground">
                Until an approver applies it, the booking (and its form) stays
                as below.
            </p>
            <ul class="mt-2 space-y-1">
                <li v-if="b.pending_change.people">
                    {{
                        nouns.many === 'vehicles' ? 'Passengers' : 'Attendees'
                    }}:
                    {{ b.pending_change.people.join(', ') }}
                </li>
                <li v-if="b.pending_change.purpose">
                    Purpose: {{ b.pending_change.purpose }}
                </li>
                <li
                    v-if="
                        b.pending_change.starts_at && b.pending_change.ends_at
                    "
                >
                    Time:
                    {{
                        format(
                            parseISO(b.pending_change.starts_at),
                            'EEE, MMM d, HH:mm',
                        )
                    }}–{{ format(parseISO(b.pending_change.ends_at), 'HH:mm') }}
                </li>
                <li v-if="b.pending_change.note" class="text-muted-foreground">
                    “{{ b.pending_change.note }}”
                </li>
            </ul>
        </section>

        <!-- The record -->
        <section
            v-if="mode === 'view'"
            class="rounded-2xl bg-card p-5 ring-1 ring-border sm:p-6"
            aria-labelledby="details-title"
        >
            <h2 id="details-title" class="sr-only">Booking details</h2>
            <dl
                class="grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2 [&_dd]:mt-0.5 [&_dt]:text-[13px] [&_dt]:text-muted-foreground"
            >
                <div>
                    <dt>When</dt>
                    <dd class="font-medium tabular-nums">{{ when }}</dd>
                </div>
                <div>
                    <dt>{{ vehicle ? 'Vehicle' : 'Room' }}</dt>
                    <dd class="flex items-center gap-2 font-medium">
                        <span
                            class="size-2.5 shrink-0 rounded-full"
                            :style="{
                                background:
                                    b.resource.color ??
                                    'var(--muted-foreground)',
                            }"
                            aria-hidden="true"
                        />
                        <span>
                            {{ b.resource.name }}
                            <span
                                v-if="b.resource.location"
                                class="font-normal text-muted-foreground"
                            >
                                · {{ b.resource.location }}
                            </span>
                        </span>
                    </dd>
                </div>
                <div>
                    <dt>Requested by</dt>
                    <dd>
                        <span class="font-medium">{{
                            b.is_mine ? 'You' : (b.requester.name ?? 'Unknown')
                        }}</span>
                        <span
                            v-if="b.requester.division"
                            class="text-muted-foreground"
                        >
                            · {{ b.requester.division }}</span
                        >
                        <span
                            v-if="b.requested_at"
                            class="block text-[13px] text-muted-foreground"
                        >
                            {{ stamp(b.requested_at) }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt>{{ vehicle ? 'Passengers' : 'Attendees' }}</dt>
                    <dd class="tabular-nums">
                        <span class="font-medium">{{
                            b.people.length || b.attendee_count || '—'
                        }}</span>
                        <span
                            v-if="b.resource.capacity"
                            class="text-muted-foreground"
                        >
                            of {{ b.resource.capacity }} seats</span
                        >
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt>Purpose</dt>
                    <dd class="max-w-prose whitespace-pre-line">
                        {{ b.purpose || 'None given' }}
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt>{{ vehicle ? 'Passengers' : 'Attendees' }} by name</dt>
                    <dd class="mt-1.5!">
                        <PeopleRoster
                            :people="b.people"
                            :count="b.attendee_count"
                            :fold="12"
                        />
                    </dd>
                </div>
            </dl>

            <div class="mt-6 flex flex-wrap items-center gap-2 border-t pt-4">
                <button
                    v-if="b.can.revise"
                    type="button"
                    :class="pillPrimary"
                    @click="start('revise')"
                >
                    <Pencil aria-hidden="true" />
                    Edit request
                </button>
                <button
                    v-if="b.can.request_change"
                    type="button"
                    :class="pillQuiet"
                    @click="start('change')"
                >
                    <Pencil aria-hidden="true" />
                    Request a change
                </button>
                <Link :href="calendarLink" :class="pillQuiet">
                    <CalendarDays aria-hidden="true" />
                    See it on the calendar
                </Link>
                <button
                    v-if="b.is_mine && b.can.cancel && !ended"
                    type="button"
                    :class="[pillQuiet, 'ml-auto text-destructive']"
                    @click="withdraw"
                >
                    {{
                        b.status === 'pending'
                            ? 'Withdraw request'
                            : 'Cancel booking'
                    }}
                </button>
            </div>
        </section>

        <!-- Editing -->
        <form
            v-else
            class="grid gap-5 rounded-2xl bg-card p-5 ring-1 ring-border sm:p-6"
            :aria-label="
                mode === 'revise' ? 'Edit request' : 'Request a change'
            "
            @submit.prevent="save"
        >
            <div>
                <h2 class="text-[15px] font-semibold tracking-tight">
                    {{
                        mode === 'revise'
                            ? 'Edit your request'
                            : 'Request a change'
                    }}
                </h2>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    {{
                        mode === 'revise'
                            ? 'It is still waiting for review, so changes apply straight away. To change the time or the room, withdraw it and book again.'
                            : 'The booking is approved, so an approver reviews changes first. Until then it stays as it is.'
                    }}
                </p>
            </div>

            <div v-if="mode === 'revise'" class="grid gap-1.5">
                <Label for="edit-title">Title</Label>
                <Input
                    id="edit-title"
                    v-model="draft.title"
                    class="h-10 rounded-xl"
                    maxlength="255"
                    :aria-invalid="!!errors.title"
                />
                <p v-if="errors.title" class="text-sm text-destructive">
                    {{ errors.title[0] }}
                </p>
            </div>

            <div class="grid gap-1.5">
                <Label for="edit-purpose">Purpose</Label>
                <Textarea
                    id="edit-purpose"
                    v-model="draft.purpose"
                    rows="3"
                    maxlength="2000"
                    class="min-h-20 resize-none rounded-xl"
                />
                <p v-if="errors.purpose" class="text-sm text-destructive">
                    {{ errors.purpose[0] }}
                </p>
            </div>

            <PeopleEditor
                id="edit-people"
                v-model="draft.people"
                :noun="personNoun"
                :capacity="b.resource.capacity"
                :resource-name="b.resource.name"
                :errors="errors"
                @clear="clearPeopleErrors"
            />

            <div v-if="mode === 'change'" class="grid gap-1.5">
                <Label for="edit-note"
                    >Note for the approver
                    <span class="font-normal text-muted-foreground"
                        >(optional)</span
                    ></Label
                >
                <Input
                    id="edit-note"
                    v-model="draft.note"
                    class="h-10 rounded-xl"
                    maxlength="1000"
                    placeholder="e.g. Ben joins from Finance"
                />
            </div>

            <p
                v-if="errors.form || errors.status || errors.booking"
                class="text-sm text-destructive"
                role="alert"
            >
                {{ (errors.form ?? errors.status ?? errors.booking)?.[0] }}
            </p>

            <div class="flex flex-wrap gap-2">
                <button type="submit" :class="pillPrimary" :disabled="saving">
                    <Spinner v-if="saving" />
                    {{ mode === 'revise' ? 'Save changes' : 'Send for review' }}
                </button>
                <button type="button" :class="pillQuiet" @click="mode = 'view'">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</template>
