<script setup lang="ts">
import { computed } from 'vue';
import WheelColumn from '@/components/booking/WheelColumn.vue';
import type { WheelItem } from '@/components/booking/WheelColumn.vue';

/**
 * A drum-style time picker (hour, minute, AM/PM) over minutes since
 * midnight. Scroll, drag, flick, wheel, or use the arrow keys.
 */
const props = withDefaults(
    defineProps<{
        /** Accessible name prefix, e.g. "Start". */
        label: string;
        /** Minute step for the minute column. */
        step?: number;
        /** Times that would not work are drawn quieter. */
        unavailable?: (minutes: number) => boolean;
    }>(),
    { step: 5, unavailable: () => false },
);

const model = defineModel<number>({ required: true });

const hour12 = computed(() => Math.floor(model.value / 60) % 12 || 12);
const minute = computed(() => model.value % 60);
const pm = computed(() => Math.floor(model.value / 60) % 24 >= 12);

const compose = (hour: number, min: number, isPm: boolean) =>
    ((hour % 12) + (isPm ? 12 : 0)) * 60 + min;

const hours = computed<WheelItem[]>(() =>
    Array.from({ length: 12 }, (_, i) => {
        const hour = i + 1;

        return {
            value: hour,
            label: String(hour),
            dim: props.unavailable(compose(hour, minute.value, pm.value)),
        };
    }),
);

const minutes = computed<WheelItem[]>(() =>
    Array.from({ length: Math.ceil(60 / props.step) }, (_, i) => {
        const min = i * props.step;

        return {
            value: min,
            label: String(min).padStart(2, '0'),
            dim: props.unavailable(compose(hour12.value, min, pm.value)),
        };
    }),
);

const halves = computed<WheelItem[]>(() =>
    [false, true].map((isPm) => ({
        value: isPm ? 1 : 0,
        label: isPm ? 'PM' : 'AM',
        dim: props.unavailable(compose(hour12.value, minute.value, isPm)),
    })),
);
</script>

<template>
    <div class="relative mx-auto flex w-full max-w-72 justify-center gap-1">
        <!-- The band the picked row sits in. -->
        <div
            class="pointer-events-none absolute inset-x-0 top-1/2 h-[34px] -translate-y-1/2 rounded-xl bg-muted"
            aria-hidden="true"
        />
        <WheelColumn
            class="w-16"
            :items="hours"
            :label="`${label} hour`"
            :model-value="hour12"
            @update:model-value="(h) => (model = compose(h, minute, pm))"
        />
        <WheelColumn
            class="w-16"
            :items="minutes"
            :label="`${label} minute`"
            :model-value="minute"
            @update:model-value="(m) => (model = compose(hour12, m, pm))"
        />
        <WheelColumn
            class="w-16"
            :items="halves"
            :label="`${label} AM or PM`"
            :model-value="pm ? 1 : 0"
            @update:model-value="
                (h) => (model = compose(hour12, minute, h === 1))
            "
        />
    </div>
</template>
