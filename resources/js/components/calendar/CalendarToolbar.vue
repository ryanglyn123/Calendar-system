<script setup lang="ts">
import { ChevronLeft, ChevronRight, Plus, Triangle } from '@lucide/vue';
import { defaultDocument, useEventListener } from '@vueuse/core';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { addDays, addMonths, format } from '@/lib/calendarMath';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import type { CalendarView } from '@/types/calendar';

withDefaults(
    defineProps<{
        /** Hidden on scopes with no writable calendars (resource sections). */
        canCreate?: boolean;
    }>(),
    { canCreate: true },
);

const emit = defineEmits<{
    create: [];
}>();

const grid = useCalendarGridStore();

const title = computed(() => {
    switch (grid.view) {
        case 'day':
            return format(grid.focusDate, 'EEEE, MMMM d, yyyy');
        case 'year':
            return format(grid.focusDate, 'yyyy');
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
        case 'days4':
            grid.setFocusDate(addDays(grid.focusDate, 4 * direction));
            break;
        case 'week':
            grid.setFocusDate(addDays(grid.focusDate, 7 * direction));
            break;
        case 'year':
            grid.setFocusDate(addMonths(grid.focusDate, 12 * direction));
            break;
        default:
            grid.setFocusDate(addMonths(grid.focusDate, direction));
    }
};

// Same views and keys as Google Calendar.
const views: { value: CalendarView; label: string; key: string }[] = [
    { value: 'day', label: 'Day', key: 'd' },
    { value: 'week', label: 'Week', key: 'w' },
    { value: 'month', label: 'Month', key: 'm' },
    { value: 'year', label: 'Year', key: 'y' },
    { value: 'agenda', label: 'Schedule', key: 'a' },
    { value: 'days4', label: '4 days', key: 'x' },
];

const currentLabel = computed(
    () => views.find((v) => v.value === grid.view)?.label ?? 'Month',
);

const viewFor = (e: KeyboardEvent) =>
    e.ctrlKey || e.metaKey || e.altKey
        ? undefined
        : views.find((v) => v.key === e.key.toLowerCase());

// A bare letter switches the view, unless the user is typing or something
// (a menu, dialog, drawer) is open on top of the calendar. Only open ones
// count: a menu that is still animating shut must not swallow the key.
useEventListener(defaultDocument, 'keydown', (e: KeyboardEvent) => {
    const target = e.target as HTMLElement | null;
    const view = viewFor(e);

    if (
        !view ||
        target?.closest('input, textarea, select, [contenteditable]') ||
        document.querySelector(
            ':is([role="dialog"], [role="alertdialog"], [role="menu"])[data-state="open"]',
        )
    ) {
        return;
    }

    grid.setView(view.value);
});

// Inside the view menu the letters it lists pick that view, as in Google
// Calendar, instead of the menu's type-to-search.
const viewMenuOpen = ref(false);

const onViewMenuKey = (e: KeyboardEvent) => {
    const view = viewFor(e);

    if (!view) {
        return;
    }

    e.preventDefault();
    e.stopPropagation();
    grid.setView(view.value);
    viewMenuOpen.value = false;
};
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 border-b px-4 py-3">
        <!-- From lg up, Create sits atop the side panel (CalendarShell). -->
        <!-- The word hides on phones, so the label names the button there. -->
        <Button
            v-if="canCreate"
            class="mr-2 lg:hidden"
            aria-label="Create event"
            @click="emit('create')"
        >
            <Plus aria-hidden="true" />
            <span class="hidden sm:inline">Create</span>
        </Button>

        <Button
            variant="outline"
            class="rounded-full px-5"
            @click="grid.goToday()"
        >
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
            <DropdownMenu v-model:open="viewMenuOpen">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="outline"
                        class="rounded-full pr-4 pl-5"
                        aria-label="Calendar view"
                    >
                        {{ currentLabel }}
                        <Triangle
                            class="ml-1 size-2 rotate-180 fill-current"
                            aria-hidden="true"
                        />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    class="w-60 px-0 py-2"
                    @keydown.capture="onViewMenuKey"
                >
                    <DropdownMenuItem
                        v-for="option in views"
                        :key="option.value"
                        class="h-10 rounded-none px-4"
                        :class="{ 'bg-accent': grid.view === option.value }"
                        @select="grid.setView(option.value)"
                    >
                        {{ option.label }}
                        <DropdownMenuShortcut class="font-semibold">
                            {{ option.key.toUpperCase() }}
                        </DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuCheckboxItem
                        v-model="grid.showWeekends"
                        class="h-10 rounded-none pl-10"
                    >
                        Show weekends
                    </DropdownMenuCheckboxItem>
                    <DropdownMenuCheckboxItem
                        v-model="grid.showDeclined"
                        class="h-10 rounded-none pl-10"
                    >
                        Show declined events
                    </DropdownMenuCheckboxItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </div>
</template>
