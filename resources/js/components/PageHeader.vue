<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <header
        class="flex flex-col gap-3 border-b border-border/70 pb-5 sm:flex-row sm:items-end sm:justify-between"
    >
        <div class="space-y-1">
            <h1
                class="text-2xl font-semibold tracking-tight text-foreground text-balance"
            >
                {{ title }}
            </h1>
            <p
                v-if="description"
                class="max-w-prose text-sm text-muted-foreground"
            >
                {{ description }}
            </p>
        </div>
        <div
            v-if="$slots.actions"
            class="flex shrink-0 items-center gap-2 sm:pb-0.5"
        >
            <slot name="actions" />
        </div>
    </header>
</template>
