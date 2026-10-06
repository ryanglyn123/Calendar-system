<script setup lang="ts">
import { Check, ChevronDown, Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
import LabelsDialog from '@/components/calendar/LabelsDialog.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useColorLabels } from '@/composables/useColorLabels';
import { eventInk } from '@/lib/calendarMath';
import { sameColor } from '@/lib/eventColors';

/**
 * Google Calendar's color menu: the person's own colors (Google's palette
 * until they change it, named by their labels), a pencil that opens the
 * Labels editor, and "Default" (null: the event follows its type or
 * calendar). The trigger is the chosen color's dot with a caret, unless a
 * page passes its own through the `trigger` slot.
 */
const props = withDefaults(
    defineProps<{
        /** The color "Default" stands for (the calendar's or type's). */
        defaultColor: string | null;
        /** Names what is being colored, for screen readers. */
        label?: string;
        /** Offer "Default" (events can follow their calendar; calendars can't). */
        allowDefault?: boolean;
    }>(),
    { label: undefined, allowDefault: true },
);

const model = defineModel<string | null>({ required: true });

const { visible, nameOf, labelOf } = useColorLabels();

const open = ref(false);
const editing = ref(false);

const shown = computed(() => model.value ?? props.defaultColor ?? '#616161');

/** The menu's colors; a current color the person hid still shows, last. */
const colors = computed(() => {
    const list = visible.value.map((l) => l.color);

    return model.value && !list.some((c) => sameColor(c, model.value))
        ? [...list, model.value]
        : list;
});

const pick = (hex: string | null) => {
    model.value = hex;
    open.value = false;
};

const editLabels = () => {
    open.value = false;
    editing.value = true;
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <slot name="trigger" :color="shown">
                <button
                    type="button"
                    class="inline-flex h-9 items-center gap-2 rounded-md bg-muted/70 px-3 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/60"
                    :aria-label="`${label ?? 'Color'}: ${model ? nameOf(model) : 'Default'}`"
                >
                    <span
                        class="size-4 rounded-full"
                        :style="{ background: shown }"
                        aria-hidden="true"
                    />
                    <span
                        v-if="labelOf(model)"
                        class="max-w-28 truncate text-sm"
                        >{{ labelOf(model) }}</span
                    >
                    <ChevronDown
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                </button>
            </slot>
        </PopoverTrigger>

        <PopoverContent
            align="start"
            class="w-auto max-w-[min(26rem,95vw)] p-3"
        >
            <!-- Name colors, hide unused ones, add your own: the Labels editor. -->
            <button
                type="button"
                class="mb-2 flex size-9 items-center justify-center rounded-full ring-1 ring-border outline-none ring-inset hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/60"
                aria-label="Edit labels"
                title="Edit labels"
                @click="editLabels"
            >
                <Pencil class="size-4 text-foreground/80" aria-hidden="true" />
            </button>

            <div
                class="flex max-w-[calc(13*1.75rem+12*0.375rem)] flex-wrap gap-1.5"
                role="radiogroup"
                :aria-label="label ?? 'Color'"
            >
                <button
                    v-for="hex in colors"
                    :key="hex"
                    type="button"
                    role="radio"
                    class="flex size-7 items-center justify-center rounded-full outline-none hover:scale-110 focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:ring-offset-2 focus-visible:ring-offset-popover motion-safe:transition-transform motion-safe:duration-150"
                    :style="{ background: hex }"
                    :title="nameOf(hex)"
                    :aria-label="nameOf(hex)"
                    :aria-checked="sameColor(hex, model)"
                    @click="pick(hex)"
                >
                    <Check
                        v-if="sameColor(hex, model)"
                        class="size-4"
                        :style="{ color: eventInk(hex) }"
                        stroke-width="3"
                        aria-hidden="true"
                    />
                </button>
            </div>

            <p
                v-if="labelOf(model)"
                class="mt-2 text-[13px] text-muted-foreground"
            >
                {{ labelOf(model) }}
            </p>

            <button
                v-if="allowDefault"
                type="button"
                class="mt-3 flex h-10 w-full items-center justify-center gap-2.5 rounded-xl bg-muted text-sm outline-none hover:bg-muted/70 focus-visible:ring-2 focus-visible:ring-ring/60"
                :aria-pressed="model === null"
                @click="pick(null)"
            >
                <span
                    class="flex size-5 items-center justify-center rounded-full"
                    :style="{ background: defaultColor ?? '#616161' }"
                    aria-hidden="true"
                >
                    <Check
                        v-if="model === null"
                        class="size-3.5"
                        :style="{ color: eventInk(defaultColor ?? '#616161') }"
                        stroke-width="3"
                    />
                </span>
                Default
            </button>
        </PopoverContent>
    </Popover>

    <LabelsDialog v-model:open="editing" />
</template>
