<script setup lang="ts" generic="T extends string">
import type { Component } from 'vue';

export type ViewOption<V extends string = string> = {
    value: V;
    label: string;
    icon: Component;
    /** What the view is for, as the button's tooltip. */
    hint?: string;
};

/**
 * Segmented switch between a page's views. Only pages with more than one
 * genuinely useful view render it. Labels show from sm up; below that the
 * icon carries the button and the label stays for screen readers.
 */
defineProps<{
    options: ViewOption<T>[];
    label?: string;
}>();

const model = defineModel<T>({ required: true });
</script>

<template>
    <div
        class="inline-flex shrink-0 items-center gap-0.5 rounded-full p-0.5 ring-1 ring-border ring-inset"
        role="group"
        :aria-label="label ?? 'View'"
    >
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            class="inline-flex h-7 items-center gap-1.5 rounded-full px-2.5 text-[13px] font-medium transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring/60 sm:px-3"
            :class="
                model === option.value
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-muted hover:text-foreground'
            "
            :aria-pressed="model === option.value"
            :title="option.hint"
            @click="model = option.value"
        >
            <component
                :is="option.icon"
                class="size-3.5 shrink-0"
                aria-hidden="true"
            />
            <span class="sr-only sm:not-sr-only">{{ option.label }}</span>
        </button>
    </div>
</template>
