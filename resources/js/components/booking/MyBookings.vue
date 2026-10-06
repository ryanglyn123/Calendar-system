<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarSearch, CircleAlert, Printer } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { api, ApiError } from '@/lib/api';
import { BOOKING_STATE } from '@/lib/bookingDisplay';
import { eventInk, eventWash, format, parseISO } from '@/lib/calendarMath';
import bookingsApi from '@/routes/api/bookings';
import bookingsPage from '@/routes/bookings';
import type { BookingStatus } from '@/types/calendar';

export type MyBooking = {
    id: number;
    /** "BK-000123", as printed on the booking form. */
    reference: string;
    title: string;
    resource: { id: number; name: string; type: string; color: string | null };
    status: BookingStatus;
    starts_at: string;
    ends_at: string;
    purpose: string | null;
    attendee_count: number | null;
    people: string[];
    rejection_reason: string | null;
    cancellation_reason: string | null;
    cancelled_by_reviewer: boolean;
    reviewed_by: string | null;
    reviewed_at: string | null;
    requested_at: string | null;
    has_pending_edit: boolean;
    can_cancel: boolean;
    version: string | null;
};

/**
 * The requester's own bookings in one section, grouped by what they need
 * to know: what still waits, what is confirmed, what did not go through.
 * Each row repeats the calendar's state treatment (hue, fill, icon, word).
 */
const props = defineProps<{
    bookings: MyBooking[];
    typeName: string;
    loading?: boolean;
}>();

const emit = defineEmits<{
    changed: [];
    show: [startsAt: string];
    create: [];
}>();

const now = () => Date.now();
const ended = (b: MyBooking) => new Date(b.ends_at).getTime() <= now();

const groups = computed(() => {
    const byStart = (a: MyBooking, b: MyBooking) =>
        a.starts_at.localeCompare(b.starts_at);

    return [
        {
            key: 'pending',
            title: 'Waiting for approval',
            items: props.bookings
                .filter((b) => b.status === 'pending' && !ended(b))
                .sort(byStart),
        },
        {
            key: 'approved',
            title: 'Coming up',
            items: props.bookings
                .filter((b) => b.status === 'approved' && !ended(b))
                .sort(byStart),
        },
        {
            key: 'closed',
            title: 'Declined or cancelled',
            items: props.bookings
                .filter((b) => !BOOKING_STATE[b.status].active)
                .sort(byStart)
                .reverse(),
        },
    ].filter((group) => group.items.length > 0);
});

const past = computed(() =>
    props.bookings
        .filter((b) => BOOKING_STATE[b.status].active && ended(b))
        .sort((a, b) => b.starts_at.localeCompare(a.starts_at)),
);

const when = (b: MyBooking) =>
    `${format(parseISO(b.starts_at), 'EEE, MMM d · HH:mm')}–${format(parseISO(b.ends_at), 'HH:mm')}`;

const confirming = ref<number | null>(null);
const busy = ref(false);

async function cancel(b: MyBooking): Promise<void> {
    busy.value = true;

    try {
        await api.post(bookingsApi.cancel(b.id).url, { version: b.version });
        toast.success(
            b.status === 'pending'
                ? 'Request withdrawn.'
                : 'Booking cancelled.',
        );
    } catch (failure) {
        toast.error(
            failure instanceof ApiError
                ? failure.message
                : 'Could not reach the server. Nothing was changed.',
        );
    } finally {
        busy.value = false;
        confirming.value = null;
        // Either way, show what the server now says.
        emit('changed');
    }
}

const swatch = (b: MyBooking) => ({
    '--ev': b.resource.color ?? '#d4d8dd',
    '--wash': eventWash(b.resource.color),
    '--ink': eventInk(b.resource.color),
});
</script>

<template>
    <div :aria-busy="loading">
        <div
            v-if="bookings.length === 0 && !loading"
            class="rounded-xl bg-muted/50 px-4 py-4 text-sm"
        >
            <p class="font-medium">You have no {{ typeName }} bookings yet.</p>
            <p class="mt-0.5 max-w-prose text-[13px] text-muted-foreground">
                Pick a time on the calendar above or start a new booking. Each
                request shows here the moment you send it, with its status as it
                changes.
            </p>
            <Button size="sm" class="mt-3" @click="emit('create')">
                New booking
            </Button>
        </div>

        <div v-else class="space-y-5">
            <section v-for="group in groups" :key="group.key">
                <h3 class="mb-1 text-[13px] font-medium text-muted-foreground">
                    {{ group.title }}
                    <span class="tabular-nums">{{ group.items.length }}</span>
                </h3>
                <ul class="divide-y divide-border border-y border-border">
                    <li
                        v-for="b in group.items"
                        :key="b.id"
                        class="flex flex-wrap items-center gap-x-4 gap-y-2 py-2.5"
                    >
                        <span
                            class="h-4 w-6 shrink-0 rounded-[4px]"
                            :class="BOOKING_STATE[b.status].fill"
                            :style="swatch(b)"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 flex-1 basis-56">
                            <p class="flex items-center gap-2 text-sm">
                                <Link
                                    :href="bookingsPage.show(b.id)"
                                    class="truncate rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/60"
                                    :class="{
                                        'text-muted-foreground line-through':
                                            !BOOKING_STATE[b.status].active,
                                    }"
                                >
                                    {{ b.title }}
                                </Link>
                                <span
                                    class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <component
                                        :is="BOOKING_STATE[b.status].icon"
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{ BOOKING_STATE[b.status].label }}
                                </span>
                                <span
                                    v-if="b.has_pending_edit"
                                    class="shrink-0 rounded-full bg-muted px-2 py-px text-[11px] text-muted-foreground"
                                >
                                    Change requested
                                </span>
                            </p>
                            <p
                                class="truncate text-[13px] text-muted-foreground"
                            >
                                {{ b.resource.name }} · {{ when(b) }}
                                <template v-if="b.people.length > 0">
                                    ·
                                    {{ b.people.length }}
                                    {{
                                        b.people.length === 1
                                            ? 'person'
                                            : 'people'
                                    }}
                                </template>
                            </p>
                            <p
                                v-if="
                                    b.rejection_reason || b.cancellation_reason
                                "
                                class="mt-1 flex items-start gap-1.5 text-[13px]"
                            >
                                <CircleAlert
                                    class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span>
                                    <span class="text-muted-foreground">
                                        {{
                                            b.status === 'rejected'
                                                ? `Rejected${b.reviewed_by ? ` by ${b.reviewed_by}` : ''}:`
                                                : b.cancelled_by_reviewer
                                                  ? 'Cancelled by a reviewer:'
                                                  : 'You noted:'
                                        }}
                                    </span>
                                    {{
                                        b.rejection_reason ??
                                        b.cancellation_reason
                                    }}
                                </span>
                            </p>
                        </div>

                        <div
                            v-if="confirming === b.id"
                            class="flex items-center gap-2"
                        >
                            <span class="text-[13px]">
                                {{
                                    b.status === 'pending'
                                        ? 'Withdraw this request?'
                                        : 'Cancel this booking?'
                                }}
                            </span>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="confirming = null"
                            >
                                Keep it
                            </Button>
                            <Button
                                variant="destructive"
                                size="sm"
                                :disabled="busy"
                                @click="cancel(b)"
                            >
                                {{
                                    b.status === 'pending'
                                        ? 'Withdraw'
                                        : 'Cancel'
                                }}
                            </Button>
                        </div>
                        <div v-else class="flex items-center gap-1">
                            <Button
                                v-if="b.status === 'approved'"
                                variant="ghost"
                                size="sm"
                                as-child
                            >
                                <a
                                    :href="bookingsPage.pdf(b.id).url"
                                    target="_blank"
                                    rel="noopener"
                                    :aria-label="`Print the booking form for ${b.title}`"
                                >
                                    <Printer aria-hidden="true" />
                                    <span class="hidden sm:inline">Print</span>
                                </a>
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                :aria-label="`Show ${b.title} on the calendar`"
                                @click="emit('show', b.starts_at)"
                            >
                                <CalendarSearch aria-hidden="true" />
                                <span class="hidden sm:inline">Show</span>
                            </Button>
                            <Button
                                v-if="b.can_cancel"
                                variant="outline"
                                size="sm"
                                @click="confirming = b.id"
                            >
                                {{
                                    b.status === 'pending'
                                        ? 'Withdraw'
                                        : 'Cancel'
                                }}
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>

            <details v-if="past.length > 0" class="group">
                <summary
                    class="cursor-pointer text-[13px] font-medium text-muted-foreground select-none hover:text-foreground"
                >
                    Past bookings
                    <span class="tabular-nums">{{ past.length }}</span>
                </summary>
                <ul class="mt-1 divide-y divide-border border-y border-border">
                    <li
                        v-for="b in past"
                        :key="b.id"
                        class="flex items-center gap-3 py-2 text-[13px] text-muted-foreground"
                    >
                        <span
                            class="h-3 w-5 shrink-0 rounded-[3px]"
                            :class="BOOKING_STATE[b.status].fill"
                            :style="swatch(b)"
                            aria-hidden="true"
                        />
                        <span class="truncate text-foreground">{{
                            b.title
                        }}</span>
                        <span class="truncate"
                            >{{ b.resource.name }} · {{ when(b) }}</span
                        >
                        <span class="ml-auto shrink-0">
                            {{ BOOKING_STATE[b.status].label }}
                        </span>
                    </li>
                </ul>
            </details>
        </div>
    </div>
</template>
