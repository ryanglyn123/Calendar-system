<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Paginated } from '@/types';

defineProps<{
    meta: Paginated<unknown>['meta'];
    /** Plural noun for the range line, e.g. "users". */
    noun: string;
}>();

const label = (raw: string) =>
    raw
        .replace('&laquo;', '«')
        .replace('&raquo;', '»')
        .replace('&hellip;', '…');
</script>

<template>
    <nav
        v-if="meta.total > 0"
        class="flex flex-wrap items-center justify-between gap-3 text-[13px] text-muted-foreground"
        aria-label="Pages"
    >
        <p class="tabular-nums">
            {{ meta.from }}–{{ meta.to }} of {{ meta.total }} {{ noun }}
        </p>

        <div v-if="meta.last_page > 1" class="flex items-center gap-0.5">
            <template v-for="(link, i) in meta.links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    class="inline-flex h-8 min-w-8 items-center justify-center rounded-full px-2.5 tabular-nums outline-none focus-visible:ring-2 focus-visible:ring-ring/60"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-muted hover:text-foreground'
                    "
                    :aria-current="link.active ? 'page' : undefined"
                >
                    {{ label(link.label) }}
                </Link>
                <span
                    v-else
                    class="inline-flex h-8 min-w-8 items-center justify-center px-2.5 opacity-50"
                    aria-hidden="true"
                >
                    {{ label(link.label) }}
                </span>
            </template>
        </div>
    </nav>
</template>
