<script setup lang="ts" generic="T extends string">
export type FilterTab<V extends string = string> = {
    value: V;
    label: string;
    /** Shown muted beside the label; omit when a count would mislead. */
    count?: number;
};

/** Pill filter tabs (aria-pressed toggles), the active one in pill ink. */
defineProps<{
    options: FilterTab<T>[];
    label: string;
}>();

const model = defineModel<T>({ required: true });
</script>

<template>
    <div class="flex flex-wrap gap-1" role="group" :aria-label="label">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            class="inline-flex h-7 shrink-0 items-center gap-1.5 rounded-full px-2.5 text-[13px] transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring/60"
            :class="
                model === option.value
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-muted hover:text-foreground'
            "
            :aria-pressed="model === option.value"
            @click="model = option.value"
        >
            {{ option.label }}
            <span
                v-if="option.count !== undefined"
                class="tabular-nums opacity-70"
                >{{ option.count }}</span
            >
        </button>
    </div>
</template>
