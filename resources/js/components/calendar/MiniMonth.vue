<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    addMonths,
    format,
    isSameDay,
    isSameMonth,
    monthGridDays,
} from '@/lib/calendarMath';
import { useCalendarGridStore } from '@/stores/calendarGrid';

const grid = useCalendarGridStore();

const anchor = ref(new Date(grid.focusDate));

watch(
    () => grid.focusDate,
    (next) => {
        anchor.value = new Date(next);
    },
);

const days = computed(() => monthGridDays(anchor.value, grid.weekStartsOn));

const weekdayLabels = computed(() =>
    days.value.slice(0, 7).map((d) => format(d, 'EEEEE')),
);

const today = new Date();
</script>

<template>
    <div class="select-none">
        <div class="flex items-center justify-between px-1 pb-2">
            <span class="text-sm font-semibold">
                {{ format(anchor, 'MMMM yyyy') }}
            </span>
            <span class="flex">
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-6"
                    aria-label="Previous month"
                    @click="anchor = addMonths(anchor, -1)"
                >
                    <ChevronLeft class="size-4" aria-hidden="true" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-6"
                    aria-label="Next month"
                    @click="anchor = addMonths(anchor, 1)"
                >
                    <ChevronRight class="size-4" aria-hidden="true" />
                </Button>
            </span>
        </div>

        <div class="grid grid-cols-7 text-center">
            <span
                v-for="(label, i) in weekdayLabels"
                :key="i"
                class="pb-1 text-[10px] font-medium text-muted-foreground"
                aria-hidden="true"
            >
                {{ label }}
            </span>

            <button
                v-for="day in days"
                :key="day.toISOString()"
                type="button"
                class="mx-auto flex size-7 items-center justify-center rounded-full text-xs transition-colors hover:bg-muted"
                :class="{
                    'text-muted-foreground/50': !isSameMonth(day, anchor),
                    'bg-gradient-accent font-semibold text-white':
                        isSameDay(day, today),
                    'ring-1 ring-primary':
                        isSameDay(day, grid.focusDate) &&
                        !isSameDay(day, today),
                }"
                :aria-label="format(day, 'MMMM d, yyyy')"
                @click="grid.setFocusDate(new Date(day))"
            >
                {{ format(day, 'd') }}
            </button>
        </div>
    </div>
</template>
