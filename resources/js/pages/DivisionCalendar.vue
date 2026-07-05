<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import CalendarShell from '@/components/calendar/CalendarShell.vue';
import type {
    CalendarItem,
    CalendarPanelGroup,
    CalendarScope,
    EventOccurrence,
    EventTypeItem,
} from '@/types/calendar';

const props = defineProps<{
    divisionName: string;
    eventTypes: { data: EventTypeItem[] };
    weekStartsOn: 0 | 1;
    initialCalendars: CalendarItem[];
    initialEvents: EventOccurrence[];
}>();

// The user's own division calendars only; every member can write.
const scope: CalendarScope = { types: ['division'] };

const panelGroups = computed<CalendarPanelGroup[]>(() => [
    // manageUnits: members can add/edit/delete unit calendars here.
    { key: 'division', label: props.divisionName, manageUnits: true },
]);
</script>

<template>
    <Head title="Division Calendar" />

    <CalendarShell
        :event-types="eventTypes.data"
        :week-starts-on="weekStartsOn"
        :initial-calendars="initialCalendars"
        :initial-events="initialEvents"
        :scope="scope"
        :panel-groups="panelGroups"
    />
</template>
