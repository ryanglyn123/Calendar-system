<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CalendarShell from '@/components/calendar/CalendarShell.vue';
import type {
    CalendarItem,
    EventOccurrence,
    EventTypeItem,
} from '@/types/calendar';

defineProps<{
    eventTypes: { data: EventTypeItem[] };
    weekStartsOn: 0 | 1;
    initialCalendars: CalendarItem[];
    initialEvents: EventOccurrence[];
}>();
</script>

<template>
    <Head title="Calendar" />

    <CalendarShell
        :event-types="eventTypes.data"
        :week-starts-on="weekStartsOn"
        :initial-calendars="initialCalendars"
        :initial-events="initialEvents"
    />
</template>
