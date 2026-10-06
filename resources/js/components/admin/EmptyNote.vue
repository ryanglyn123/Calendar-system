<script setup lang="ts">
import type { Component } from 'vue';

/**
 * The design system's empty note: a muted, unboxed block saying what would
 * be here and what to do next. Used for both "nothing yet" and "no match".
 */
defineProps<{
    icon: Component;
    title: string;
}>();
</script>

<template>
    <div class="flex items-start gap-3 rounded-xl bg-muted/50 px-4 py-4">
        <component
            :is="icon"
            class="mt-0.5 size-5 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
        <div class="space-y-0.5">
            <p class="text-sm font-medium">{{ title }}</p>
            <div
                v-if="$slots.default"
                class="max-w-prose text-[13px] text-muted-foreground"
            >
                <slot />
            </div>
        </div>
        <div v-if="$slots.action" class="ml-auto shrink-0">
            <slot name="action" />
        </div>
    </div>
</template>
