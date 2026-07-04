<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import AgendaView from '@/components/calendar/AgendaView.vue';
import CalendarListPanel from '@/components/calendar/CalendarListPanel.vue';
import CalendarToolbar from '@/components/calendar/CalendarToolbar.vue';
import DayOverflowDialog from '@/components/calendar/DayOverflowDialog.vue';
import EventEditorDrawer from '@/components/calendar/EventEditorDrawer.vue';
import EventQuickView from '@/components/calendar/EventQuickView.vue';
import MiniMonth from '@/components/calendar/MiniMonth.vue';
import MonthView from '@/components/calendar/MonthView.vue';
import RecurrenceScopeDialog from '@/components/calendar/RecurrenceScopeDialog.vue';
import type { RecurrenceScope } from '@/components/calendar/RecurrenceScopeDialog.vue';
import TimeGridView from '@/components/calendar/TimeGridView.vue';
import { Separator } from '@/components/ui/separator';
import { useConfirm } from '@/composables/useConfirm';
import { api, ApiError } from '@/lib/api';
import { destroy as eventsDestroy } from '@/routes/api/events';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import { useCalendarListStore } from '@/stores/calendarList';
import type {
    CalendarItem,
    CalendarPanelGroup,
    CalendarScope,
    EventOccurrence,
    EventTypeItem,
} from '@/types/calendar';

const props = defineProps<{
    eventTypes: EventTypeItem[];
    weekStartsOn: 0 | 1;
    initialCalendars?: CalendarItem[];
    initialEvents?: EventOccurrence[];
    /** Narrows the shell to a page scope (public calendar, resource section). */
    scope?: CalendarScope;
    /** Which calendar groups the side panel renders, with their labels. */
    panelGroups?: CalendarPanelGroup[];
}>();

const emit = defineEmits<{
    /**
     * A date cell was picked in a scope the shell can't create events in
     * (resource sections). The page turns this into a resource booking.
     */
    createBooking: [date: Date];
}>();

const grid = useCalendarGridStore();
const list = useCalendarListStore();
const { confirm } = useConfirm();

grid.weekStartsOn = props.weekStartsOn;

// The scope must be in place before priming: window caches are keyed by it
// and list refreshes re-request the same subset.
grid.setScope(props.scope ?? null);
list.setScope(props.scope ?? null);

// Server-inlined data: the grid and sidebar render full on first paint,
// with no follow-up API round trips.
if (props.initialCalendars) {
    list.prime(props.initialCalendars);
}

// The server span is built around "now", so prime around today; if the
// user left the grid focused elsewhere, onMounted fetches that instead.
if (props.initialEvents) {
    grid.primeAround(new Date(), props.initialEvents);
}

onMounted(() => {
    void list.load();
    void grid.ensureWindowsAround(grid.focusDate);
});

/**
 * Whether the user can create events anywhere in this scope. Resource
 * calendars are never writable (bookings own their events), and the public
 * calendar needs the org-events permission.
 */
const canCreate = computed(() => list.writableCalendars.length > 0);

const quickViewTarget = ref<EventOccurrence | null>(null);

const editorOpen = ref(false);
const editorTarget = ref<EventOccurrence | null>(null);
const editorInitialStart = ref<Date | null>(null);
const editorInitialEnd = ref<Date | null>(null);

const overflowDay = ref<Date | null>(null);
const overflowItems = ref<EventOccurrence[]>([]);

const openQuickView = (occurrence: EventOccurrence) => {
    quickViewTarget.value = occurrence;
};

const openCreate = (start?: Date, end?: Date) => {
    if (!canCreate.value) {
        // Resource sections can't create events directly; a picked date
        // becomes a booking request handled by the page.
        if (props.scope?.resourceTypeId != null && start) {
            emit('createBooking', start);
        }

        return;
    }

    editorTarget.value = null;
    editorInitialStart.value = start ?? null;
    editorInitialEnd.value = end ?? null;
    editorOpen.value = true;
};

const openEdit = (occurrence: EventOccurrence) => {
    quickViewTarget.value = null;
    editorTarget.value = occurrence;
    editorInitialStart.value = null;
    editorInitialEnd.value = null;
    editorOpen.value = true;
};

const showDayOverflow = (day: Date, occurrences: EventOccurrence[]) => {
    overflowDay.value = day;
    overflowItems.value = occurrences;
};

/** Pending recurring operation awaiting a scope choice. */
const scopeAction = ref<'edit' | 'delete'>('edit');
const scopeResolver = ref<((scope: RecurrenceScope | null) => void) | null>(
    null,
);

const askScope = (
    action: 'edit' | 'delete',
): Promise<RecurrenceScope | null> => {
    scopeAction.value = action;

    return new Promise((resolve) => {
        scopeResolver.value = resolve;
    });
};

const settleScope = (scope: RecurrenceScope | null) => {
    scopeResolver.value?.(scope);
    scopeResolver.value = null;
};

const moveRecurring = async (
    occurrence: EventOccurrence,
    start: Date,
    end: Date,
) => {
    const scope = await askScope('edit');

    if (scope === null) {
        return;
    }

    try {
        await grid.moveOccurrenceScoped(occurrence, scope, start, end);
        toast.success('Event rescheduled.');
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'Could not reschedule the event.',
        );
    }
};

const removeEvent = async (occurrence: EventOccurrence) => {
    if (occurrence.is_recurring) {
        const scope = await askScope('delete');

        if (scope === null) {
            return;
        }

        try {
            await grid.deleteOccurrenceScoped(occurrence, scope);
            quickViewTarget.value = null;
            toast.success('Event deleted.');
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Could not delete the event.',
            );
        }

        return;
    }

    const confirmed = await confirm({
        title: `Delete “${occurrence.title}”?`,
        description: 'The event is removed from the calendar for everyone.',
        confirmLabel: 'Delete event',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    try {
        await api.delete(eventsDestroy(occurrence.event_id).url);

        grid.removeEvent(occurrence.event_id);
        quickViewTarget.value = null;
        toast.success('Event deleted.');
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'Could not delete the event.',
        );
    }
};
</script>

<template>
    <div class="flex h-full min-h-0 flex-1">
        <!-- Calendar side panel -->
        <aside
            class="hidden w-56 shrink-0 flex-col gap-4 overflow-y-auto border-r p-3 lg:flex"
            aria-label="Calendars"
        >
            <MiniMonth />
            <Separator />
            <CalendarListPanel :groups="panelGroups" />
        </aside>

        <!-- Main area -->
        <div class="flex min-w-0 flex-1 flex-col">
            <CalendarToolbar :can-create="canCreate" @create="openCreate()" />

            <div class="min-h-0 flex-1">
                <MonthView
                    v-if="grid.view === 'month'"
                    @create-at="openCreate"
                    @open="openQuickView"
                    @show-day="showDayOverflow"
                    @move-recurring="moveRecurring"
                />
                <TimeGridView
                    v-else-if="grid.view === 'week' || grid.view === 'day'"
                    :mode="grid.view"
                    @create-range="openCreate"
                    @open="openQuickView"
                    @move-recurring="moveRecurring"
                />
                <AgendaView v-else @open="openQuickView" />
            </div>
        </div>

        <EventQuickView
            :occurrence="quickViewTarget"
            @close="quickViewTarget = null"
            @edit="openEdit"
            @delete="removeEvent"
        />

        <EventEditorDrawer
            :open="editorOpen"
            :occurrence="editorTarget"
            :initial-start="editorInitialStart"
            :initial-end="editorInitialEnd"
            :event-types="eventTypes"
            @close="editorOpen = false"
            @saved="quickViewTarget = null"
        />

        <DayOverflowDialog
            :day="overflowDay"
            :occurrences="overflowItems"
            @close="overflowDay = null"
            @open="
                (occurrence) => {
                    overflowDay = null;
                    openQuickView(occurrence);
                }
            "
        />

        <RecurrenceScopeDialog
            :open="scopeResolver !== null"
            :action="scopeAction"
            @choose="settleScope"
            @cancel="settleScope(null)"
        />
    </div>
</template>
