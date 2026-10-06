<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import CalendarShell from '@/components/calendar/CalendarShell.vue';
import type {
    CalendarItem,
    CalendarPanelGroup,
    CalendarScope,
    EventOccurrence,
    EventTypeItem,
} from '@/types/calendar';

defineProps<{
    eventTypes: { data: EventTypeItem[] };
    weekStartsOn: 0 | 1;
    initialCalendars: CalendarItem[];
    initialEvents: EventOccurrence[];
}>();

// Organization-wide calendars only: the events every staff member sees.
const scope: CalendarScope = { types: ['organization'] };

const acronym = usePage().props.organization.acronym;

const panelGroups: CalendarPanelGroup[] = [
    { key: 'organization', label: `${acronym} calendars` },
];
</script>

<template>
    <Head :title="`${acronym} Events`" />

    <CalendarShell
        :event-types="eventTypes.data"
        :week-starts-on="weekStartsOn"
        :initial-calendars="initialCalendars"
        :initial-events="initialEvents"
        :scope="scope"
        :panel-groups="panelGroups"
    />
</template>
