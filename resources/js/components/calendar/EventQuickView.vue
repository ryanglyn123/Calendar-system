<script setup lang="ts">
import {
    AlignLeft,
    Ban,
    Bell,
    CalendarDays,
    Check,
    CircleHelp,
    Clock,
    DoorOpen,
    FileText,
    Lock,
    MapPin,
    Pencil,
    Repeat,
    ShieldCheck,
    Trash2,
    UserCheck,
    Users,
    Video,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { useCalendarListStore } from '@/stores/calendarList';
import type { EventOccurrence, RsvpStatus } from '@/types/calendar';

const props = defineProps<{
    occurrence: EventOccurrence | null;
}>();

const emit = defineEmits<{
    close: [];
    edit: [occurrence: EventOccurrence];
    delete: [occurrence: EventOccurrence];
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

const reviewedAt = computed(() => {
    const at = props.occurrence?.booking?.reviewed_at;

    return at ? format(parseISO(at), 'MMM d, yyyy · HH:mm') : null;
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
</script>

<template>
    <Dialog
        :open="occurrence !== null"
        @update:open="(open) => !open && emit('close')"
    >
        <DialogScrollContent
            v-if="occurrence"
            class="max-w-lg gap-0 overflow-hidden p-0"
        >
            <!-- Header: soft event-color wash carries timing and status -->
            <header class="border-b px-6 pt-6 pb-5" :style="headerStyle">
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

                <!-- Resource booking -->
                <section
                    v-if="occurrence.booking"
                    class="qv-reveal overflow-hidden rounded-xl border"
                    style="--i: 2"
                >
                    <div
                        class="flex items-center justify-between gap-3 border-b bg-muted/40 px-3.5 py-2.5"
                    >
                        <div class="flex items-center gap-2 font-medium">
                            <DoorOpen
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Resource booking
                        </div>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium"
                            :style="{
                                backgroundColor: mix(
                                    occurrence.booking.status_color,
                                    18,
                                ),
                                borderColor: mix(
                                    occurrence.booking.status_color,
                                    40,
                                ),
                            }"
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :style="{
                                    backgroundColor:
                                        occurrence.booking.status_color,
                                }"
                                aria-hidden="true"
                            />
                            {{ occurrence.booking.status_label }}
                        </span>
                    </div>

                    <dl class="grid gap-2.5 px-3.5 py-3">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-muted-foreground">Resource</dt>
                            <dd class="text-right font-medium">
                                {{ occurrence.booking.resource ?? '—' }}
                                <span
                                    v-if="occurrence.booking.resource_type"
                                    class="font-normal text-muted-foreground"
                                >
                                    · {{ occurrence.booking.resource_type }}
                                </span>
                            </dd>
                        </div>

                        <div
                            v-if="
                                occurrence.booking.attendee_count ||
                                occurrence.booking.capacity
                            "
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt
                                class="flex items-center gap-1.5 text-muted-foreground"
                            >
                                <Users class="size-3.5" aria-hidden="true" />
                                Attendees
                            </dt>
                            <dd class="text-right font-medium">
                                <span v-if="occurrence.booking.attendee_count">
                                    {{ occurrence.booking.attendee_count }}
                                </span>
                                <span v-else>—</span>
                                <span
                                    v-if="occurrence.booking.capacity"
                                    class="font-normal text-muted-foreground"
                                >
                                    / {{ occurrence.booking.capacity }} seats
                                </span>
                            </dd>
                        </div>

                        <div
                            v-if="occurrence.booking.purpose"
                            class="flex items-start justify-between gap-3"
                        >
                            <dt
                                class="flex shrink-0 items-center gap-1.5 text-muted-foreground"
                            >
                                <FileText class="size-3.5" aria-hidden="true" />
                                Purpose
                            </dt>
                            <dd class="text-right">
                                {{ occurrence.booking.purpose }}
                            </dd>
                        </div>

                        <div
                            v-if="occurrence.booking.requested_by"
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt
                                class="flex items-center gap-1.5 text-muted-foreground"
                            >
                                <UserCheck
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Requested by
                            </dt>
                            <dd class="text-right font-medium">
                                {{ occurrence.booking.requested_by }}
                            </dd>
                        </div>

                        <div
                            v-if="occurrence.booking.reviewed_by"
                            class="flex items-baseline justify-between gap-3"
                        >
                            <dt
                                class="flex items-center gap-1.5 text-muted-foreground"
                            >
                                <ShieldCheck
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Reviewed by
                            </dt>
                            <dd class="text-right font-medium">
                                {{ occurrence.booking.reviewed_by }}
                                <span
                                    v-if="reviewedAt"
                                    class="block text-xs font-normal text-muted-foreground"
                                >
                                    {{ reviewedAt }}
                                </span>
                            </dd>
                        </div>

                        <p
                            v-if="
                                occurrence.booking.rejection_reason ||
                                occurrence.booking.cancellation_reason
                            "
                            class="flex items-start gap-1.5 rounded-lg bg-destructive/10 px-2.5 py-2 text-xs text-destructive"
                        >
                            <Ban
                                class="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>
                                {{
                                    occurrence.booking.rejection_reason ??
                                    occurrence.booking.cancellation_reason
                                }}
                            </span>
                        </p>
                    </dl>
                </section>

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
                    variant="outline"
                    size="sm"
                    class="text-destructive"
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
