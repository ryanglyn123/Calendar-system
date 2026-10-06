<script setup lang="ts">
import { useIntervalFn } from '@vueuse/core';
import { RadioGroupItem, RadioGroupRoot } from 'reka-ui';
import { computed, ref } from 'vue';
import type { BookableResource } from '@/components/booking/ResourceSchedule.vue';
import { liveStatus } from '@/lib/bookingDisplay';
import { addDays, startOfDay } from '@/lib/calendarMath';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import { useCalendarListStore } from '@/stores/calendarList';

/**
 * Which room or vehicle the calendar shows: all of them, or one. Each row
 * says whether it is free right now, so the pick doubles as a glance at
 * availability. Arrow keys move the pick, like any radio group.
 */
const props = defineProps<{
    resources: BookableResource[];
    nouns: { one: string; many: string };
}>();

const grid = useCalendarGridStore();
const list = useCalendarListStore();

const now = ref(new Date());

useIntervalFn(() => (now.value = new Date()), 60_000);

const status = computed(() => {
    const today = startOfDay(now.value);
    const items = grid.occurrencesBetween(today, addDays(today, 1));

    return new Map(
        props.resources.map((r) => [
            r.calendar_id,
            liveStatus(
                items.filter((o) => o.calendar_id === r.calendar_id),
                now.value,
            ),
        ]),
    );
});

const freeNow = computed(
    () => [...status.value.values()].filter((s) => s.free).length,
);

const picked = computed({
    get: () => (list.picked === null ? 'all' : String(list.picked)),
    set: (value: string) => {
        list.picked = value === 'all' ? null : Number(value);
    },
});

const seats = (r: BookableResource) =>
    r.capacity ? `${r.capacity} ${r.capacity === 1 ? 'seat' : 'seats'}` : null;

const row =
    'group flex w-full items-start gap-2.5 rounded-xl px-2.5 py-2 text-left transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring/60 data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=unchecked]:hover:bg-muted';
</script>

<template>
    <section aria-labelledby="resource-picker-label">
        <h2
            id="resource-picker-label"
            class="px-2.5 pb-1.5 text-xs font-medium text-muted-foreground capitalize"
        >
            {{ nouns.many }}
        </h2>

        <RadioGroupRoot
            v-model="picked"
            class="flex flex-col gap-0.5"
            aria-labelledby="resource-picker-label"
            :loop="false"
        >
            <RadioGroupItem value="all" :class="row">
                <span
                    class="mt-1 grid size-3 shrink-0 grid-cols-2 gap-px"
                    aria-hidden="true"
                >
                    <span
                        v-for="r in resources.slice(0, 4)"
                        :key="r.id"
                        class="rounded-full"
                        :style="{ backgroundColor: r.color ?? '#d4d8dd' }"
                    />
                </span>
                <span class="flex min-w-0 flex-col">
                    <span class="truncate text-[13px] font-medium">
                        All {{ nouns.many }}
                    </span>
                    <span
                        class="text-xs text-muted-foreground tabular-nums group-data-[state=checked]:text-primary-foreground/75"
                    >
                        {{ freeNow }} of {{ resources.length }} free now
                    </span>
                </span>
            </RadioGroupItem>

            <RadioGroupItem
                v-for="r in resources"
                :key="r.id"
                :value="String(r.calendar_id)"
                :class="row"
            >
                <span
                    class="mt-1 size-3 shrink-0 rounded-full ring-2 ring-transparent group-data-[state=checked]:ring-primary-foreground/30"
                    :style="{ backgroundColor: r.color ?? '#d4d8dd' }"
                    aria-hidden="true"
                />
                <span class="flex min-w-0 flex-col">
                    <span
                        class="truncate text-[13px] font-medium"
                        :title="r.name"
                    >
                        {{ r.name }}
                    </span>
                    <span
                        class="flex items-center gap-1.5 text-xs text-muted-foreground group-data-[state=checked]:text-primary-foreground/75"
                    >
                        <span
                            class="size-1.5 shrink-0 rounded-full"
                            :class="
                                status.get(r.calendar_id)?.free
                                    ? 'bg-success'
                                    : 'bg-foreground/45 group-data-[state=checked]:bg-primary-foreground/60'
                            "
                            aria-hidden="true"
                        />
                        <span class="truncate tabular-nums">
                            {{ status.get(r.calendar_id)?.label }}
                            <template v-if="seats(r)">
                                · {{ seats(r) }}
                            </template>
                        </span>
                    </span>
                </span>
            </RadioGroupItem>
        </RadioGroupRoot>
    </section>
</template>
