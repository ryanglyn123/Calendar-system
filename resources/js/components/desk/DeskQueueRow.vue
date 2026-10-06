<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Check } from '@lucide/vue';
import { computed, ref } from 'vue';
import { parseISO } from '@/lib/calendarMath';
import adminBookings from '@/routes/admin/bookings';
import adminPending from '@/routes/admin/pending-registrations';
import type { DeskQueueItem } from '@/types';
import {
    dayLabel,
    decisionUrls,
    isoDate,
    pillQuiet,
    pillSuccess,
    timeRange,
} from './desk';
import DeskRejectForm from './DeskRejectForm.vue';

const props = defineProps<{
    item: DeskQueueItem;
    now: Date;
    /** The list already heads each day, so the row shows times only. */
    dayless?: boolean;
    /** This row's request is open on the review page above. */
    current?: boolean;
}>();

const emit = defineEmits<{
    /** Light (or clear) this request's booking on the schedule. */
    spotlight: [bookingId: number | null];
    /** Move the schedule to the request's day and point at it. */
    show: [date: string, bookingId: number];
}>();

const rejecting = ref(false);
// Approval has no fields; the server reports a late clash under `status`.
const approveForm = useForm<{ status?: string }>({});

const bookingId = computed(() => {
    if (props.item.kind === 'booking') {
        return props.item.id;
    }

    return props.item.kind === 'change' ? props.item.booking_id : null;
});

/** An approved booking already holds the slot: the server refuses approval. */
const blockedBy = computed(() =>
    props.item.kind === 'booking' && props.item.clash?.status === 'approved'
        ? props.item.clash
        : null,
);

type Tag = { text: string; tone: 'danger' | 'warn' | 'quiet'; detail?: string };

/**
 * At most one short tag per row, the thing most likely to change the
 * decision. The full sentence rides along as its tooltip and for readers.
 */
const tag = computed<Tag | null>(() => {
    const item = props.item;

    if (item.kind === 'registration') {
        return { text: 'Sign-up', tone: 'quiet' };
    }

    if (item.kind === 'change') {
        return {
            text: 'Change',
            tone: 'quiet',
            detail: item.note ?? undefined,
        };
    }

    if (item.clash) {
        const detail = `Overlaps ${item.clash.status} “${item.clash.title}” ${timeRange(item.clash.starts_at, item.clash.ends_at)}`;

        return item.clash.status === 'approved'
            ? {
                  text: 'Clash',
                  tone: 'danger',
                  detail: `${detail}. Reject it, or cancel that booking first.`,
              }
            : {
                  text: 'Overlap',
                  tone: 'warn',
                  detail: `${detail}. Approving this one blocks it.`,
              };
    }

    if (item.capacity != null && (item.attendee_count ?? 0) > item.capacity) {
        return {
            text: 'Over capacity',
            tone: 'warn',
            detail: `${item.attendee_count} people, seats ${item.capacity}`,
        };
    }

    const start = new Date(item.starts_at).getTime();
    const end = new Date(item.ends_at).getTime();
    const nowMs = props.now.getTime();

    if (end <= nowMs) {
        return {
            text: 'Past',
            tone: 'quiet',
            detail: 'This time has already passed',
        };
    }

    if (start - nowMs < 86_400_000) {
        return {
            text: start <= nowMs ? 'Now' : 'Soon',
            tone: 'warn',
            detail: 'Starts within a day',
        };
    }

    return null;
});

const TAG_TONE: Record<Tag['tone'], string> = {
    danger: 'bg-destructive/10 text-destructive',
    warn: 'bg-warning/15 text-warning-ink',
    quiet: 'bg-muted text-muted-foreground',
};

const approve = () => {
    if (props.item.kind === 'registration') {
        return;
    }

    approveForm.post(decisionUrls[props.item.kind].approve(props.item.id), {
        preserveScroll: true,
        preserveState: true,
    });
};

const show = () => {
    if (props.item.kind !== 'registration' && bookingId.value !== null) {
        emit('show', isoDate(parseISO(props.item.starts_at)), bookingId.value);
    }
};
</script>

<template>
    <li
        class="group/row flex flex-wrap items-center gap-x-4 gap-y-2 px-2 py-2.5 transition-colors duration-150 hover:bg-muted/45"
        :class="{ 'bg-muted/60': current }"
        :aria-current="current ? 'true' : undefined"
        @mouseenter="emit('spotlight', bookingId)"
        @mouseleave="emit('spotlight', null)"
        @focusin="emit('spotlight', bookingId)"
        @focusout="emit('spotlight', null)"
    >
        <div class="min-w-0 flex-1 basis-64">
            <p class="flex min-w-0 items-center gap-2 text-sm">
                <!-- A booking's title opens its review page: details, every
                     conflict, and the decision in one place. -->
                <Link
                    v-if="item.kind === 'booking'"
                    :href="adminBookings.show(item.id)"
                    class="truncate font-medium underline-offset-4 hover:underline"
                    preserve-scroll
                >
                    {{ item.title }}
                </Link>
                <span v-else class="truncate font-medium">{{
                    item.title
                }}</span>
                <span
                    v-if="tag"
                    class="shrink-0 rounded-full px-2 py-px text-[11px] leading-4 font-medium"
                    :class="TAG_TONE[tag.tone]"
                    :title="tag.detail"
                >
                    {{ tag.text }}
                    <span v-if="tag.detail" class="sr-only">
                        : {{ tag.detail }}</span
                    >
                </span>
            </p>

            <p class="truncate text-[13px] text-muted-foreground">
                <template v-if="item.kind === 'registration'">
                    {{ item.division ?? 'No division' }} · {{ item.email }}
                </template>
                <template v-else>
                    <span
                        class="mr-1 inline-block size-2 rounded-full align-[1px]"
                        :style="{
                            background: item.color ?? 'var(--muted-foreground)',
                        }"
                        aria-hidden="true"
                    />{{ item.resource }} ·
                    <button
                        type="button"
                        class="rounded-sm tabular-nums underline-offset-4 outline-none hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring/60"
                        @click="show"
                    >
                        <template v-if="!dayless">{{
                            dayLabel(item.starts_at, now)
                        }}</template>
                        <template v-if="item.kind === 'change'">
                            <s class="decoration-1">{{
                                timeRange(
                                    item.current.starts_at,
                                    item.current.ends_at,
                                )
                            }}</s>
                            <ArrowRight
                                class="mx-0.5 inline size-3 align-[-1px]"
                                aria-label="moves to"
                            />
                        </template>
                        {{ timeRange(item.starts_at, item.ends_at) }}
                        <span class="sr-only">, show on the schedule</span>
                    </button>
                    · {{ item.requester ?? 'Unknown' }}
                </template>
            </p>
        </div>

        <div v-if="!rejecting" class="flex items-center gap-2">
            <Link
                v-if="item.kind === 'registration'"
                :href="adminPending.index()"
                :class="pillQuiet"
            >
                Review
            </Link>
            <template v-else>
                <button
                    type="button"
                    :class="pillQuiet"
                    @click="rejecting = true"
                >
                    Reject
                </button>
                <button
                    type="button"
                    :class="pillSuccess"
                    :disabled="approveForm.processing || blockedBy !== null"
                    :title="blockedBy ? tag?.detail : undefined"
                    @click="approve"
                >
                    <Check aria-hidden="true" />
                    {{ item.kind === 'change' ? 'Apply' : 'Approve' }}
                </button>
            </template>
        </div>

        <p
            v-if="approveForm.errors.status"
            class="w-full text-[13px] text-destructive"
            role="alert"
        >
            {{ approveForm.errors.status }}
        </p>

        <div
            v-if="rejecting && item.kind !== 'registration'"
            class="w-full max-w-xl"
        >
            <DeskRejectForm
                :kind="item.kind"
                :id="item.id"
                @cancel="rejecting = false"
            />
        </div>
    </li>
</template>
