<script setup lang="ts">
/**
 * The review workspace: the list of what waits on one side, the request
 * being decided on the other. On narrow screens it is one or the other:
 * the list until a request is open, then that request with a way back.
 */
defineProps<{
    /** A request is open (shows the detail on narrow screens). */
    hasSelection: boolean;
    /** The list pane's accessible name. */
    listLabel: string;
}>();
</script>

<template>
    <div class="flex min-h-0 flex-1 items-start gap-6 lg:gap-8">
        <nav
            class="w-full shrink-0 flex-col lg:sticky lg:top-4 lg:flex lg:max-h-[calc(100svh-6rem)] lg:w-[21rem] lg:overflow-y-auto"
            :class="hasSelection ? 'hidden' : 'flex'"
            :aria-label="listLabel"
        >
            <slot name="list" />
        </nav>

        <section
            class="min-w-0 flex-1 flex-col lg:flex lg:border-l lg:border-border lg:pl-8"
            :class="hasSelection ? 'flex' : 'hidden'"
        >
            <slot name="detail" />
        </section>
    </div>
</template>
