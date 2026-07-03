<script setup lang="ts">
import { ChevronLeft, ChevronRight, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { addDays, addMonths, format } from '@/lib/calendarMath';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import type { CalendarView } from '@/types/calendar';

const emit = defineEmits<{
    create: [];
}>();

const grid = useCalendarGridStore();

const title = computed(() => {
    switch (grid.view) {
        case 'day':
            return format(grid.focusDate, 'EEEE, MMMM d, yyyy');
        case 'week':
            return format(grid.focusDate, 'MMMM yyyy');
        case 'agenda':
            return 'Upcoming';
        default:
            return format(grid.focusDate, 'MMMM yyyy');
    }
});

const step = (direction: 1 | -1) => {
    switch (grid.view) {
        case 'day':
            grid.setFocusDate(addDays(grid.focusDate, direction));
            break;
        case 'week':
            grid.setFocusDate(addDays(grid.focusDate, 7 * direction));
            break;
        default:
            grid.setFocusDate(addMonths(grid.focusDate, direction));
    }
};

const views: { value: CalendarView; label: string }[] = [
    { value: 'month', label: 'Month' },
    { value: 'week', label: 'Week' },
    { value: 'day', label: 'Day' },
    { value: 'agenda', label: 'Agenda' },
];
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 border-b px-4 py-3">
        <Button class="mr-2" data-test="create-event" @click="emit('create')">
            <Plus aria-hidden="true" />
            <span class="hidden sm:inline">Create</span>
        </Button>

        <Button variant="outline" size="sm" @click="grid.goToday()">
            Today
        </Button>

        <div class="flex items-center">
            <Button
                variant="ghost"
                size="icon"
                aria-label="Previous period"
                @click="step(-1)"
            >
                <ChevronLeft aria-hidden="true" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                aria-label="Next period"
                @click="step(1)"
            >
                <ChevronRight aria-hidden="true" />
            </Button>
        </div>

        <h1 class="ml-1 text-lg font-semibold" aria-live="polite">
            {{ title }}
        </h1>

        <Spinner v-if="grid.isLoading" class="ml-2 size-4" />

        <div class="ml-auto">
            <Select
                :model-value="grid.view"
                @update:model-value="(v) => grid.setView(v as CalendarView)"
            >
                <SelectTrigger class="w-28" aria-label="Calendar view">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in views"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>
    </div>
</template>
