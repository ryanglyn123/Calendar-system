<script setup lang="ts">
import {
    AlignLeft,
    Bell,
    CalendarDays,
    Check,
    CircleHelp,
    Clock,
    Lock,
    MapPin,
    MessageSquareText,
    Pencil,
    Repeat,
    Send,
    Trash2,
    Users,
    Video,
    X,
} from '@lucide/vue';
import { computed, ref, useTemplateRef, watch } from 'vue';
import { toast } from 'vue-sonner';
import BookingPanel from '@/components/booking/BookingPanel.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useAuth } from '@/composables/useAuth';
import { useColorLabels } from '@/composables/useColorLabels';
import { getInitials } from '@/composables/useInitials';
import { api, ApiError } from '@/lib/api';
import { format, isSameDay, parseISO } from '@/lib/calendarMath';
import {
    describeRecurrence,
    eventTiming,
    formatDuration,
    formatReminder,
    formatTimezone,
} from '@/lib/eventDisplay';
import { rsvp as eventsRsvp } from '@/routes/api/events';
import eventComments from '@/routes/api/events/comments';
import { useCalendarListStore } from '@/stores/calendarList';
import type { EventOccurrence, RsvpStatus } from '@/types/calendar';

const props = defineProps<{
    occurrence: EventOccurrence | null;
    /** Separate from `occurrence`, which stays set while the card animates out. */
    open: boolean;
    /** Viewport centre of the pill that opened the card. */
    origin?: { x: number; y: number } | null;
}>();

const { labelOf } = useColorLabels();

const emit = defineEmits<{
    close: [];
    edit: [occurrence: EventOccurrence];
    delete: [occurrence: EventOccurrence];
    /** A booking decision landed (or was refused as stale): reload it. */
    bookingChanged: [];
}>();

const list = useCalendarListStore();

const calendarName = computed(
    () => list.byId.get(props.occurrence?.calendar_id ?? -1)?.name ?? '',
);

const when = computed(() => {
    if (!props.occurrence) {
        return '';
    }

    const start = new Date(props.occurrence.starts_at);
    const end = new Date(props.occurrence.ends_at);

    if (props.occurrence.all_day) {
        const inclusiveEnd = new Date(end.getTime() - 1);

        return isSameDay(start, inclusiveEnd)
            ? format(start, 'EEEE, MMMM d, yyyy')
            : `${format(start, 'MMM d')} - ${format(inclusiveEnd, 'MMM d, yyyy')}`;
    }

    return isSameDay(start, end)
        ? `${format(start, 'EEEE, MMMM d')} · ${format(start, 'HH:mm')} - ${format(end, 'HH:mm')}`
        : `${format(start, 'MMM d, HH:mm')} - ${format(end, 'MMM d, HH:mm')}`;
});

const durationText = computed(() =>
    props.occurrence
        ? formatDuration(
              props.occurrence.starts_at,
              props.occurrence.ends_at,
              props.occurrence.all_day,
          )
        : null,
);

const timezoneText = computed(() =>
    props.occurrence && !props.occurrence.all_day
        ? formatTimezone(props.occurrence.timezone, props.occurrence.starts_at)
        : null,
);

const timing = computed(() =>
    props.occurrence
        ? eventTiming(
              props.occurrence.starts_at,
              props.occurrence.ends_at,
              props.occurrence.all_day,
              Date.now(),
          )
        : null,
);

const statusPill = computed(() => {
    const state = timing.value?.state;

    if (state === 'now') {
        return 'bg-success/10 text-success';
    }

    if (state === 'upcoming') {
        return 'bg-foreground/[0.06] text-foreground/80';
    }

    return 'bg-muted text-muted-foreground';
});

const recurrenceText = computed(() => {
    if (!props.occurrence?.is_recurring) {
        return null;
    }

    return describeRecurrence(props.occurrence.rrule) ?? 'Repeats';
});

const reminders = computed(() =>
    [...(props.occurrence?.my_reminder_minutes ?? [])]
        .sort((a, b) => a - b)
        .map((minutes) => formatReminder(minutes)),
);

const peopleCount = computed(() => {
    if (!props.occurrence) {
        return 0;
    }

    return (
        (props.occurrence.organizer ? 1 : 0) + props.occurrence.attendees.length
    );
});

const createdLabel = computed(() => {
    const at = props.occurrence?.created_at;

    return at ? `Added ${format(parseISO(at), 'MMM d, yyyy')}` : null;
});

/** Mix an arbitrary event/status color toward the surface for soft tints. */
const mix = (color: string, percent: number) =>
    `color-mix(in oklab, ${color} ${percent}%, var(--background))`;

const headerStyle = computed(() => ({
    background: props.occurrence
        ? `linear-gradient(180deg, ${mix(props.occurrence.color, 14)}, ${mix(props.occurrence.color, 4)})`
        : undefined,
}));

const myRsvp = ref<RsvpStatus | null>(null);

const respond = async (status: RsvpStatus) => {
    if (!props.occurrence) {
        return;
    }

    try {
        await api.put(eventsRsvp(props.occurrence.event_id).url, { status });
        myRsvp.value = status;
        toast.success('Response saved.');
    } catch (error) {
        toast.error(
            error instanceof ApiError ? error.message : 'Could not respond.',
        );
    }
};

const effectiveRsvp = computed(
    () => myRsvp.value ?? props.occurrence?.my_rsvp ?? null,
);

const rsvpOptions: { status: RsvpStatus; label: string; icon: unknown }[] = [
    { status: 'accepted', label: 'Going', icon: Check },
    { status: 'tentative', label: 'Maybe', icon: CircleHelp },
    { status: 'declined', label: 'No', icon: X },
];

// --- Discussion: comment thread shared by everyone who can see the event ---

type CommentItem = {
    id: number;
    user: { id: number; name: string; avatar_path: string | null } | null;
    body: string;
    created_at: string;
};

const { user: authUser } = useAuth();

const canDiscuss = computed(
    () => props.occurrence !== null && !props.occurrence.is_masked,
);

const comments = ref<CommentItem[]>([]);
const commentBody = ref('');
const posting = ref(false);

watch(
    () => props.occurrence?.event_id,
    async (eventId) => {
        comments.value = [];

        if (!eventId || props.occurrence?.is_masked) {
            return;
        }

        try {
            const { data } = await api.get<{ data: CommentItem[] }>(
                eventComments.index(eventId).url,
            );
            comments.value = data;
        } catch {
            // Thread stays empty; posting surfaces any real error.
        }
    },
    { immediate: true },
);

const postComment = async () => {
    const body = commentBody.value.trim();

    if (!body || !props.occurrence || posting.value) {
        return;
    }

    posting.value = true;

    try {
        const { data } = await api.post<{ data: CommentItem }>(
            eventComments.store(props.occurrence.event_id).url,
            { body },
        );
        comments.value.push(data);
        commentBody.value = '';
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'Could not post comment.',
        );
    } finally {
        posting.value = false;
    }
};

const removeComment = async (comment: CommentItem) => {
    try {
        await api.delete(eventComments.destroy(comment.id).url);
        comments.value = comments.value.filter((c) => c.id !== comment.id);
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'Could not delete comment.',
        );
    }
};

const commentTime = (iso: string) => format(parseISO(iso), 'MMM d, HH:mm');

/* Aim the card's zoom at its pill, so it opens out of that pill and closes
   back into it. Runs once per opening, before the first frame paints.
   offsetLeft/Top ignore the running transform; the overlay sits at 0,0. */
const header = useTemplateRef<HTMLElement>('header');

watch(
    header,
    (el) => {
        const card = el?.closest<HTMLElement>('[role="dialog"]');

        if (card && props.origin) {
            card.style.transformOrigin = `${props.origin.x - card.offsetLeft}px ${props.origin.y - card.offsetTop}px`;
        }
    },
    { flush: 'post' },
);
</script>

<template>
    <Dialog :open="open" @update:open="(open) => !open && emit('close')">
        <DialogScrollContent
            v-if="occurrence"
            data-quick-view
            class="max-w-lg gap-0 overflow-hidden p-0 ease-[cubic-bezier(0.16,1,0.3,1)] data-[state=closed]:animate-out data-[state=closed]:duration-150 data-[state=closed]:ease-in data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[state=open]:animate-in data-[state=open]:duration-[260ms] data-[state=open]:fade-in-0 data-[state=open]:zoom-in-90"
        >
            <!-- Header: soft event-color wash carries timing and status -->
            <header
                ref="header"
                class="border-b px-6 pt-6 pb-5"
                :style="headerStyle"
            >
                <div class="mb-3 flex flex-wrap items-center gap-1.5">
                    <span
                        v-if="timing"
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                        :class="statusPill"
                    >
                        <span
                            v-if="timing.state === 'now'"
                            class="size-1.5 rounded-full bg-current motion-safe:animate-pulse"
                            aria-hidden="true"
                        />
                        <span
                            v-else-if="timing.state === 'upcoming'"
                            class="size-1.5 rounded-full"
                            :style="{ backgroundColor: occurrence.color }"
                            aria-hidden="true"
                        />
                        {{ timing.label }}
                    </span>
                    <Badge
                        v-if="recurrenceText"
                        variant="outline"
                        class="gap-1 bg-card/70 font-normal"
                    >
                        <Repeat class="size-3" aria-hidden="true" />
                        Recurring
                    </Badge>
                    <Badge
                        v-if="occurrence.is_private"
                        variant="outline"
                        class="gap-1 bg-card/70 font-normal"
                    >
                        <Lock class="size-3" aria-hidden="true" />
                        Private
                    </Badge>
                    <!-- The viewer's own name for this color, if they gave it one. -->
                    <Badge
                        v-if="labelOf(occurrence.color)"
                        variant="outline"
                        class="gap-1.5 bg-card/70 font-normal"
                    >
                        <span
                            class="size-2 rounded-full"
                            :style="{ backgroundColor: occurrence.color }"
                            aria-hidden="true"
                        />
                        {{ labelOf(occurrence.color) }}
                    </Badge>
                </div>

                <DialogTitle
                    class="flex items-start gap-2.5 pr-8 text-lg leading-snug font-semibold tracking-tight"
                >
                    <span
                        class="mt-1.5 size-2.5 shrink-0 rounded-full"
                        :style="{ backgroundColor: occurrence.color }"
                        aria-hidden="true"
                    />
                    <span>{{ occurrence.title }}</span>
                </DialogTitle>
                <DialogDescription class="sr-only">
                    Event details
                </DialogDescription>

                <div class="mt-3 flex items-start gap-2.5">
                    <Clock
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <div class="text-sm">
                        <p class="font-medium">{{ when }}</p>
                        <p
                            v-if="durationText || timezoneText"
                            class="text-xs text-muted-foreground"
                        >
                            <span v-if="durationText">{{ durationText }}</span>
                            <span v-if="durationText && timezoneText"> · </span>
                            <span v-if="timezoneText">{{ timezoneText }}</span>
                        </p>
                        <p
                            v-if="recurrenceText"
                            class="text-xs text-muted-foreground"
                        >
                            {{ recurrenceText }}
                        </p>
                    </div>
                </div>
            </header>

            <div class="space-y-5 px-6 py-5 text-sm">
                <!-- Calendar & type -->
                <div
                    class="qv-reveal flex flex-wrap items-center gap-2.5"
                    style="--i: 0"
                >
                    <CalendarDays
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span>{{ calendarName }}</span>
                    <Badge
                        v-if="occurrence.event_type"
                        variant="outline"
                        class="gap-1.5"
                    >
                        <span
                            class="size-2 rounded-full"
                            :style="{
                                backgroundColor: occurrence.event_type.color,
                            }"
                            aria-hidden="true"
                        />
                        {{ occurrence.event_type.name }}
                    </Badge>
                </div>

                <!-- Where -->
                <div
                    v-if="occurrence.location || occurrence.meeting_url"
                    class="qv-reveal space-y-2.5"
                    style="--i: 1"
                >
                    <div
                        v-if="occurrence.location"
                        class="flex items-center gap-2.5"
                    >
                        <MapPin
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span>{{ occurrence.location }}</span>
                    </div>

                    <div
                        v-if="occurrence.meeting_url"
                        class="flex items-center gap-2.5"
                    >
                        <Video
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <a
                            :href="occurrence.meeting_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="truncate font-medium text-fdcp-blue underline-offset-4 hover:underline"
                        >
                            Join meeting
                        </a>
                    </div>
                </div>

                <!-- Resource booking: state, details and decisions -->
                <BookingPanel
                    v-if="occurrence.booking"
                    class="qv-reveal"
                    style="--i: 2"
                    :occurrence="occurrence"
                    @changed="emit('bookingChanged')"
                />

                <!-- People -->
                <section
                    v-if="occurrence.organizer || occurrence.attendees.length"
                    class="qv-reveal space-y-3"
                    style="--i: 3"
                >
                    <h3
                        class="flex items-center gap-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        <Users class="size-3.5" aria-hidden="true" />
                        People
                        <span
                            v-if="peopleCount"
                            class="font-normal normal-case"
                        >
                            · {{ peopleCount }}
                        </span>
                    </h3>

                    <div
                        v-if="occurrence.organizer"
                        class="flex items-center gap-2.5"
                    >
                        <Avatar class="size-8">
                            <AvatarImage
                                v-if="occurrence.organizer.avatar_path"
                                :src="occurrence.organizer.avatar_path"
                                :alt="occurrence.organizer.name"
                            />
                            <AvatarFallback class="text-xs">
                                {{ getInitials(occurrence.organizer.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <div class="leading-tight">
                            <p class="font-medium">
                                {{ occurrence.organizer.name }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Organizer
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="occurrence.attendees.length"
                        class="flex flex-wrap gap-1.5"
                    >
                        <span
                            v-for="attendee in occurrence.attendees"
                            :key="attendee.user_id ?? attendee.name"
                            class="inline-flex items-center gap-1.5 rounded-full border bg-card py-0.5 pr-2.5 pl-0.5 text-xs"
                        >
                            <Avatar class="size-6">
                                <AvatarImage
                                    v-if="attendee.avatar_path"
                                    :src="attendee.avatar_path"
                                    :alt="attendee.name"
                                />
                                <AvatarFallback class="text-[10px]">
                                    {{ getInitials(attendee.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <span>{{ attendee.name }}</span>
                            <Check
                                v-if="attendee.rsvp_status === 'accepted'"
                                class="size-3 text-success"
                                aria-label="Accepted"
                            />
                            <X
                                v-else-if="attendee.rsvp_status === 'declined'"
                                class="size-3 text-destructive"
                                aria-label="Declined"
                            />
                            <CircleHelp
                                v-else
                                class="size-3 text-muted-foreground"
                                aria-label="No response yet"
                            />
                        </span>
                    </div>

                    <div
                        v-if="occurrence.my_rsvp !== null"
                        class="flex items-center gap-2 pt-0.5"
                    >
                        <span class="text-xs text-muted-foreground">
                            Going?
                        </span>
                        <Button
                            v-for="option in rsvpOptions"
                            :key="option.status"
                            size="sm"
                            :variant="
                                effectiveRsvp === option.status
                                    ? 'default'
                                    : 'outline'
                            "
                            class="h-7"
                            @click="respond(option.status)"
                        >
                            <component :is="option.icon" aria-hidden="true" />
                            {{ option.label }}
                        </Button>
                    </div>
                </section>

                <!-- Reminders -->
                <div
                    v-if="reminders.length"
                    class="qv-reveal flex items-start gap-2.5"
                    style="--i: 4"
                >
                    <Bell
                        class="mt-1 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <div class="flex flex-wrap gap-1.5">
                        <Badge
                            v-for="label in reminders"
                            :key="label"
                            variant="secondary"
                            class="font-normal"
                        >
                            {{ label }}
                        </Badge>
                    </div>
                </div>

                <!-- Notes -->
                <div
                    v-if="occurrence.description"
                    class="qv-reveal flex items-start gap-2.5"
                    style="--i: 5"
                >
                    <AlignLeft
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <p
                        class="rounded-lg bg-muted/50 px-3 py-2 whitespace-pre-line text-muted-foreground"
                    >
                        {{ occurrence.description }}
                    </p>
                </div>

                <!-- Discussion -->
                <section
                    v-if="canDiscuss"
                    class="qv-reveal space-y-3 border-t pt-4"
                    style="--i: 6"
                >
                    <h3
                        class="flex items-center gap-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        <MessageSquareText
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        Discussion
                        <span
                            v-if="comments.length"
                            class="font-normal normal-case"
                        >
                            · {{ comments.length }}
                        </span>
                    </h3>

                    <ul v-if="comments.length" class="space-y-3">
                        <li
                            v-for="comment in comments"
                            :key="comment.id"
                            class="group flex items-start gap-2.5"
                        >
                            <Avatar class="size-7">
                                <AvatarImage
                                    v-if="comment.user?.avatar_path"
                                    :src="comment.user.avatar_path"
                                    :alt="comment.user.name"
                                />
                                <AvatarFallback class="text-[10px]">
                                    {{ getInitials(comment.user?.name ?? '?') }}
                                </AvatarFallback>
                            </Avatar>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs text-muted-foreground">
                                    <span class="font-medium text-foreground">
                                        {{
                                            comment.user?.name ?? 'Former user'
                                        }}
                                    </span>
                                    · {{ commentTime(comment.created_at) }}
                                </p>
                                <p class="break-words whitespace-pre-line">
                                    {{ comment.body }}
                                </p>
                            </div>
                            <button
                                v-if="comment.user?.id === authUser.id"
                                type="button"
                                class="mt-0.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 hover:text-destructive focus-visible:opacity-100"
                                aria-label="Delete comment"
                                @click="removeComment(comment)"
                            >
                                <Trash2 class="size-3.5" aria-hidden="true" />
                            </button>
                        </li>
                    </ul>
                    <p v-else class="text-xs text-muted-foreground">
                        No comments yet.
                    </p>

                    <form
                        class="flex items-center gap-2"
                        @submit.prevent="postComment"
                    >
                        <Input
                            v-model="commentBody"
                            placeholder="Write a comment..."
                            maxlength="2000"
                            class="h-8 text-sm"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            class="h-8"
                            :disabled="posting || !commentBody.trim()"
                            aria-label="Post comment"
                        >
                            <Send class="size-3.5" aria-hidden="true" />
                        </Button>
                    </form>
                </section>

                <!-- Provenance -->
                <p
                    v-if="createdLabel"
                    class="border-t pt-3 text-xs text-muted-foreground"
                >
                    {{ createdLabel }}
                </p>
            </div>

            <div
                v-if="occurrence.can_edit"
                class="flex justify-end gap-2 border-t px-6 py-4"
            >
                <Button
                    variant="destructive"
                    size="sm"
                    @click="emit('delete', occurrence)"
                >
                    <Trash2 aria-hidden="true" />
                    Delete
                </Button>
                <Button size="sm" @click="emit('edit', occurrence)">
                    <Pencil aria-hidden="true" />
                    Edit
                </Button>
            </div>
        </DialogScrollContent>
    </Dialog>
</template>

<style scoped>
/* Calm staggered reveal for detail rows; static under reduced motion. */
@media (prefers-reduced-motion: no-preference) {
    .qv-reveal {
        opacity: 0;
        animation: qv-reveal 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        animation-delay: calc(var(--i, 0) * 50ms);
    }

    @keyframes qv-reveal {
        from {
            opacity: 0;
            transform: translateY(6px);
        }

        to {
            opacity: 1;
            transform: none;
        }
    }
}
</style>
