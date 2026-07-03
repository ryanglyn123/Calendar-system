<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CalendarShell from '@/components/calendar/CalendarShell.vue';
import type { EventTypeItem } from '@/types/calendar';

defineProps<{
    eventTypes: { data: EventTypeItem[] };
    weekStartsOn: 0 | 1;
}>();
</script>

<template>
    <Head title="Calendar" />

    <CalendarShell
        :event-types="eventTypes.data"
        :week-starts-on="weekStartsOn"
    />
</template>
