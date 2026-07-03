<script setup lang="ts">
import {
    AlignLeft,
    CalendarDays,
    Check,
    CircleHelp,
    Clock,
    Link2,
    Lock,
    MapPin,
    Pencil,
    Repeat,
    Trash2,
    Users,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { api, ApiError } from '@/lib/api';
import { format, isSameDay } from '@/lib/calendarMath';
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
        <DialogContent v-if="occurrence" class="max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-start gap-2.5 pr-6">
                    <span
                        class="mt-1.5 size-3.5 shrink-0 rounded"
                        :style="{ backgroundColor: occurrence.color }"
                        aria-hidden="true"
                    />
                    <span>{{ occurrence.title }}</span>
                </DialogTitle>
                <DialogDescription class="sr-only">
                    Event details
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-2.5 text-sm">
                <div class="flex items-center gap-2.5">
                    <Clock
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span>{{ when }}</span>
                    <Repeat
                        v-if="occurrence.is_recurring"
                        class="size-3.5 text-muted-foreground"
                        aria-label="Repeats"
                    />
                </div>

                <div class="flex items-center gap-2.5">
                    <CalendarDays
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span>{{ calendarName }}</span>
                    <Badge
                        v-if="occurrence.event_type"
                        variant="outline"
                        :style="{ color: occurrence.event_type.color }"
                    >
                        {{ occurrence.event_type.name }}
                    </Badge>
                    <Badge
                        v-if="occurrence.status === 'tentative'"
                        variant="secondary"
                    >
                        Tentative
                    </Badge>
                    <Badge
                        v-if="occurrence.status === 'cancelled'"
                        variant="destructive"
                    >
                        Cancelled
                    </Badge>
                </div>

                <div
                    v-if="occurrence.is_private"
                    class="flex items-center gap-2.5 text-muted-foreground"
                >
                    <Lock class="size-4 shrink-0" aria-hidden="true" />
                    <span>Private event</span>
                </div>

                <div v-if="occurrence.location" class="flex items-center gap-2.5">
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
                    <Link2
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <a
                        :href="occurrence.meeting_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="truncate text-fdcp-blue underline-offset-4 hover:underline"
                    >
                        {{ occurrence.meeting_url }}
                    </a>
                </div>

                <div
                    v-if="occurrence.attendees.length > 0"
                    class="flex items-start gap-2.5"
                >
                    <Users
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="flex flex-wrap gap-1">
                        <Badge
                            v-for="attendee in occurrence.attendees"
                            :key="attendee.user_id ?? attendee.name"
                            variant="outline"
                            class="gap-1 font-normal"
                        >
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
                            {{ attendee.name }}
                        </Badge>
                    </span>
                </div>

                <div
                    v-if="occurrence.my_rsvp !== null"
                    class="flex items-center gap-2 pt-1"
                >
                    <span class="text-xs text-muted-foreground">Going?</span>
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

                <div
                    v-if="occurrence.description"
                    class="flex items-start gap-2.5"
                >
                    <AlignLeft
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <p class="whitespace-pre-line text-muted-foreground">
                        {{ occurrence.description }}
                    </p>
                </div>
            </div>

            <div
                v-if="occurrence.can_edit"
                class="flex justify-end gap-2 pt-2"
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
        </DialogContent>
    </Dialog>
</template>
