<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export type RowFlag = {
    text: string;
    tone: 'danger' | 'warn' | 'quiet';
    /** The full sentence, for the tooltip and screen readers. */
    detail?: string;
};

/**
 * One request in the review list: what, where and when on two quiet lines,
 * at most one flag for the thing most likely to change the decision. No
 * buttons: deciding happens beside the full request, never blind.
 */
defineProps<{
    title: string;
    meta: string;
    color: string | null;
    selected: boolean;
    flag?: RowFlag | null;
    /** Opens by URL (bookings have review pages); otherwise emits select. */
    href?: string;
}>();

const emit = defineEmits<{ select: [] }>();

const TONE: Record<RowFlag['tone'], string> = {
    danger: 'text-destructive',
    warn: 'text-warning-ink',
    quiet: 'text-muted-foreground',
};
</script>

<template>
    <li>
        <component
            :is="href ? Link : 'button'"
            :href="href"
            :type="href ? undefined : 'button'"
            :preserve-state="href ? true : undefined"
            :preserve-scroll="href ? true : undefined"
            :only="href ? ['focus', 'schedule'] : undefined"
            class="relative flex w-full items-start gap-3 rounded-lg px-3 py-2.5 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring/60 motion-safe:transition-colors motion-safe:duration-150"
            :class="selected ? 'bg-muted' : 'hover:bg-muted/60'"
            :aria-current="selected ? 'true' : undefined"
            data-review-row
            @click="!href && emit('select')"
        >
            <span
                class="mt-1.5 size-2 shrink-0 rounded-full"
                :style="{ background: color ?? 'var(--muted-foreground)' }"
                aria-hidden="true"
            />
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium">
                    {{ title }}
                </span>
                <span
                    class="block truncate text-[13px] text-muted-foreground tabular-nums"
                >
                    {{ meta }}
                </span>
            </span>
            <span
                v-if="flag"
                class="mt-0.5 shrink-0 text-[12px] font-medium"
                :class="TONE[flag.tone]"
                :title="flag.detail"
            >
                {{ flag.text
                }}<span v-if="flag.detail" class="sr-only"
                    >: {{ flag.detail }}</span
                >
            </span>
        </component>
    </li>
</template>
