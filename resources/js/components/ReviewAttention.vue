<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { dayLabel, timeRange } from '@/components/desk/desk';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { resourceTypeIcon } from '@/lib/resourceTypeIcon';
import adminBookings from '@/routes/admin/bookings';
import { useNotificationStore } from '@/stores/notifications';
import type { ReviewSummary } from '@/types';

/**
 * "What needs my decision?" for reviewers, on every page from the moment
 * they sign in. The count comes from the page, then from the notification
 * poll, so it drops as soon as anyone decides; at zero it disappears.
 */
const page = usePage();
const store = useNotificationStore();

watch(
    () => page.props.reviewQueue,
    (fresh) => (store.review = fresh ?? null),
    { immediate: true },
);

const queue = computed<ReviewSummary | null>(() => store.review);
const open = ref(false);

const typeOf = (slug: string) =>
    queue.value?.types.find((t) => t.slug === slug);

const TAG = {
    approved: { text: 'Clash', tone: 'bg-destructive/10 text-destructive' },
    pending: { text: 'Overlap', tone: 'bg-warning/15 text-warning-ink' },
    passed: { text: 'Past', tone: 'bg-muted text-muted-foreground' },
} as const;

const tagFor = (item: ReviewSummary['items'][number]) =>
    item.clash ? TAG[item.clash] : item.passed ? TAG.passed : null;

const more = computed(() =>
    queue.value ? queue.value.total - queue.value.items.length : 0,
);

// Introduce itself once per sign-in session; after that it just sits there.
const arriving = ref(false);

onMounted(() => {
    try {
        if (!sessionStorage.getItem('review-attention-seen')) {
            arriving.value = true;
            sessionStorage.setItem('review-attention-seen', '1');
        }
    } catch {
        // Storage blocked: skip the introduction.
    }
});
</script>

<template>
    <Popover v-if="queue && queue.total > 0" v-model:open="open">
        <PopoverTrigger as-child>
            <!-- The header's quiet pill; the count chip is the only ink. -->
            <button
                type="button"
                class="inline-flex h-8 items-center gap-2 rounded-full pr-1 pl-3.5 text-[13px] font-medium text-foreground/80 ring-1 ring-border transition-colors ring-inset hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:outline-none data-[state=open]:bg-muted data-[state=open]:text-foreground"
                :class="{ 'review-arrive': arriving }"
                :aria-label="`${queue.total} booking ${queue.total === 1 ? 'request' : 'requests'} waiting for your review`"
            >
                Review
                <span
                    class="grid h-6 min-w-6 place-items-center rounded-full bg-primary px-1.5 text-xs font-semibold text-primary-foreground tabular-nums"
                    aria-hidden="true"
                >
                    {{ queue.total > 99 ? '99+' : queue.total }}
                </span>
            </button>
        </PopoverTrigger>

        <PopoverContent align="end" class="w-[23rem] p-0">
            <div class="border-b px-4 pt-3 pb-2.5">
                <p class="text-sm font-semibold">Waiting on your decision</p>
                <!-- Per type, so Boardroom and Carpool never blur together. -->
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <Link
                        v-for="type in queue.types.filter((t) => t.count > 0)"
                        :key="type.slug"
                        :href="
                            adminBookings.index({ query: { type: type.slug } })
                        "
                        class="inline-flex h-7 items-center gap-1.5 rounded-full px-2.5 text-xs font-medium ring-1 ring-border ring-inset hover:bg-muted"
                        @click="open = false"
                    >
                        <component
                            :is="resourceTypeIcon(type)"
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        {{ type.name }}
                        <span class="text-muted-foreground tabular-nums">{{
                            type.count
                        }}</span>
                    </Link>
                </div>
            </div>

            <ul class="max-h-80 overflow-y-auto py-1">
                <li v-for="item in queue.items" :key="item.id">
                    <Link
                        :href="adminBookings.show(item.id)"
                        class="flex gap-3 px-4 py-2.5 hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                        @click="open = false"
                    >
                        <component
                            :is="
                                resourceTypeIcon(
                                    typeOf(item.type) ?? { name: item.type },
                                )
                            "
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            :aria-label="typeOf(item.type)?.name ?? item.type"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <span class="truncate text-sm font-medium">
                                    {{ item.title }}
                                </span>
                                <span
                                    v-if="tagFor(item)"
                                    class="shrink-0 rounded-full px-1.5 text-[11px] leading-4 font-medium"
                                    :class="tagFor(item)!.tone"
                                >
                                    {{ tagFor(item)!.text }}
                                </span>
                            </span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                            >
                                {{ item.resource }} ·
                                {{ item.requester ?? 'Unknown' }}
                            </span>
                            <span
                                class="block text-xs text-muted-foreground tabular-nums"
                            >
                                {{ dayLabel(item.starts_at) }} ·
                                {{ timeRange(item.starts_at, item.ends_at) }}
                            </span>
                        </span>
                    </Link>
                </li>
            </ul>

            <Link
                :href="adminBookings.index()"
                class="flex items-center justify-between border-t px-4 py-2.5 text-[13px] font-medium hover:bg-muted"
                @click="open = false"
            >
                {{
                    more > 0
                        ? `Open the review queue · ${more} more`
                        : 'Open the review queue'
                }}
                <ArrowRight class="size-4" aria-hidden="true" />
            </Link>
        </PopoverContent>
    </Popover>
</template>

<style scoped>
/* A single, short arrival after sign-in; never loops. */
@media (prefers-reduced-motion: no-preference) {
    .review-arrive {
        animation: review-arrive 0.5s cubic-bezier(0.16, 1, 0.3, 1) 0.3s both;
    }

    @keyframes review-arrive {
        from {
            opacity: 0;
            transform: translateY(-4px);
        }
    }
}
</style>
