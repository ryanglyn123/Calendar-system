<script setup lang="ts">
import { CircleCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { DeskQueueItem } from '@/types';
import DeskQueueRow from './DeskQueueRow.vue';
import DeskSection from './DeskSection.vue';

const props = defineProps<{
    items: DeskQueueItem[];
    now: Date;
    /** What this person can be asked to decide, for the empty state. */
    receives: string;
}>();

const emit = defineEmits<{
    spotlight: [bookingId: number | null];
    show: [date: string, bookingId: number];
}>();

type Filter = 'all' | DeskQueueItem['kind'];

const FILTERS: { value: Filter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'booking', label: 'Bookings' },
    { value: 'change', label: 'Changes' },
    { value: 'registration', label: 'Sign-ups' },
];

const COLLAPSED = 4;

const filter = ref<Filter>('all');
const expanded = ref(false);

const counts = computed(() => {
    const tally: Record<Filter, number> = {
        all: props.items.length,
        booking: 0,
        change: 0,
        registration: 0,
    };

    props.items.forEach((item) => tally[item.kind]++);

    return tally;
});

/** Only offer the filter once there is more than one kind to tell apart. */
const filters = computed(() =>
    FILTERS.filter(
        (option) => option.value === 'all' || counts.value[option.value] > 0,
    ),
);

/** Deciding the last item of a kind falls back to everything. */
const active = computed<Filter>(() =>
    counts.value[filter.value] > 0 ? filter.value : 'all',
);

/** Soonest start first: a request for this afternoon outranks next week's. Sign-ups trail. */
const sorted = computed(() => {
    const rank = (item: DeskQueueItem) =>
        item.kind === 'registration'
            ? Number.MAX_SAFE_INTEGER
            : new Date(item.starts_at).getTime();

    return props.items
        .filter((item) => active.value === 'all' || item.kind === active.value)
        .sort((a, b) => rank(a) - rank(b));
});

const visible = computed(() =>
    expanded.value ? sorted.value : sorted.value.slice(0, COLLAPSED),
);

const hidden = computed(() => sorted.value.length - visible.value.length);
</script>

<template>
    <DeskSection
        title="Waiting on you"
        :count="items.length || null"
        heading-id="desk-queue"
    >
        <template #actions>
            <div
                v-if="filters.length > 2"
                class="flex flex-wrap gap-1"
                role="group"
                aria-label="Filter requests"
            >
                <button
                    v-for="option in filters"
                    :key="option.value"
                    type="button"
                    class="inline-flex h-7 shrink-0 items-center gap-1.5 rounded-full px-2.5 text-[13px] transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring/60"
                    :class="
                        active === option.value
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                    "
                    :aria-pressed="active === option.value"
                    @click="filter = option.value"
                >
                    {{ option.label }}
                    <span class="tabular-nums opacity-70">{{
                        counts[option.value]
                    }}</span>
                </button>
            </div>
        </template>

        <div
            v-if="items.length === 0"
            class="flex items-start gap-3 rounded-xl bg-muted/50 px-4 py-4"
        >
            <CircleCheck
                class="mt-0.5 size-5 shrink-0 text-success"
                aria-hidden="true"
            />
            <div class="space-y-0.5">
                <p class="text-sm font-medium">Nothing is waiting on you.</p>
                <p class="max-w-prose text-[13px] text-muted-foreground">
                    New {{ receives }} land here, soonest first. The requester
                    hears back the moment you decide.
                </p>
            </div>
        </div>

        <template v-else>
            <TransitionGroup
                tag="ul"
                class="divide-y divide-border border-y border-border"
                enter-from-class="opacity-0"
                enter-active-class="motion-safe:transition-opacity motion-safe:duration-200"
                leave-active-class="motion-safe:transition-opacity motion-safe:duration-150"
                leave-to-class="opacity-0"
            >
                <DeskQueueRow
                    v-for="item in visible"
                    :key="item.key"
                    :item="item"
                    :now="now"
                    @spotlight="(id) => emit('spotlight', id)"
                    @show="(date, id) => emit('show', date, id)"
                />
            </TransitionGroup>

            <button
                v-if="sorted.length > COLLAPSED"
                type="button"
                class="mt-2 rounded-full px-3 py-1 text-[13px] font-medium text-muted-foreground transition-colors duration-150 outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60"
                :aria-expanded="expanded"
                @click="expanded = !expanded"
            >
                {{ expanded ? 'Show fewer' : `Show ${hidden} more` }}
            </button>
        </template>
    </DeskSection>
</template>
