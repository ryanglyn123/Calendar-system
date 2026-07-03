<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import AgendaView from '@/components/calendar/AgendaView.vue';
import CalendarListPanel from '@/components/calendar/CalendarListPanel.vue';
import CalendarToolbar from '@/components/calendar/CalendarToolbar.vue';
import DayOverflowDialog from '@/components/calendar/DayOverflowDialog.vue';
import EventEditorDrawer from '@/components/calendar/EventEditorDrawer.vue';
import EventQuickView from '@/components/calendar/EventQuickView.vue';
import MiniMonth from '@/components/calendar/MiniMonth.vue';
import MonthView from '@/components/calendar/MonthView.vue';
import TimeGridView from '@/components/calendar/TimeGridView.vue';
import { Separator } from '@/components/ui/separator';
import { useConfirm } from '@/composables/useConfirm';
import { api, ApiError } from '@/lib/api';
import { destroy as eventsDestroy } from '@/routes/api/events';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import { useCalendarListStore } from '@/stores/calendarList';
import type { EventOccurrence, EventTypeItem } from '@/types/calendar';

const props = defineProps<{
    eventTypes: EventTypeItem[];
    weekStartsOn: 0 | 1;
}>();

const grid = useCalendarGridStore();
const list = useCalendarListStore();
const { confirm } = useConfirm();

grid.weekStartsOn = props.weekStartsOn;

onMounted(() => {
    void list.load();
    void grid.ensureWindowsAround(grid.focusDate);
});

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

const removeEvent = async (occurrence: EventOccurrence) => {
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
            <CalendarListPanel />
        </aside>

        <!-- Main area -->
        <div class="flex min-w-0 flex-1 flex-col">
            <CalendarToolbar @create="openCreate()" />

            <div class="min-h-0 flex-1">
                <MonthView
                    v-if="grid.view === 'month'"
                    @create-at="openCreate"
                    @open="openQuickView"
                    @show-day="showDayOverflow"
                />
                <TimeGridView
                    v-else-if="grid.view === 'week' || grid.view === 'day'"
                    :mode="grid.view"
                    @create-range="openCreate"
                    @open="openQuickView"
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
    </div>
</template>
