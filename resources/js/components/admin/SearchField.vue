<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { Input } from '@/components/ui/input';

defineProps<{
    /** Names what is searched, for the placeholder and screen readers. */
    label: string;
    placeholder?: string;
}>();

const model = defineModel<string>({ default: '' });
</script>

<template>
    <div class="relative w-full max-w-xs">
        <Search
            class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
        />
        <Input
            v-model="model"
            type="search"
            :placeholder="placeholder ?? `${label}…`"
            class="pr-8 pl-8 [&::-webkit-search-cancel-button]:hidden"
            :aria-label="label"
        />
        <button
            v-if="model"
            type="button"
            class="absolute top-1/2 right-1.5 flex size-6 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
            aria-label="Clear search"
            @click="model = ''"
        >
            <X class="size-3.5" aria-hidden="true" />
        </button>
    </div>
</template>
