<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CalendarShell from '@/components/calendar/CalendarShell.vue';
import type {
    CalendarItem,
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

// Resource calendars live on their own section pages; the merged calendar
// carries everything else.
const scope: CalendarScope = {
    types: ['organization', 'division', 'personal', 'google'],
};
</script>

<template>
    <Head title="My Calendar" />

    <CalendarShell
        :event-types="eventTypes.data"
        :week-starts-on="weekStartsOn"
        :initial-calendars="initialCalendars"
        :initial-events="initialEvents"
        :scope="scope"
    />
</template>
