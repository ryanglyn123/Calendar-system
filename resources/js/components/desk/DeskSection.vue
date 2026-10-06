<script setup lang="ts">
/**
 * One ruled block of the desk sheet: a hairline across the page, the title
 * with an optional muted count, controls on the right, then the body.
 */
defineProps<{
    title: string;
    count?: number | null;
    headingId: string;
}>();
</script>

<template>
    <section :aria-labelledby="headingId" class="border-t border-border pt-4">
        <div
            class="mb-3 flex min-h-8 flex-wrap items-center justify-between gap-x-4 gap-y-2"
        >
            <h2
                :id="headingId"
                class="flex items-baseline gap-2 text-[15px] font-semibold tracking-tight"
            >
                {{ title }}
                <span
                    v-if="count != null"
                    class="text-sm font-normal text-muted-foreground tabular-nums"
                >
                    {{ count }}
                </span>
            </h2>
            <div
                v-if="$slots.actions"
                class="flex flex-wrap items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </div>
        <slot />
    </section>
</template>
