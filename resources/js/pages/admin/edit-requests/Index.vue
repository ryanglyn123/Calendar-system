<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Inbox } from '@lucide/vue';
import { defaultWindow, useEventListener, useNow } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import EmptyNote from '@/components/admin/EmptyNote.vue';
import { dayLabel, timeRange } from '@/components/desk/desk';
import ChangeReview from '@/components/review/ChangeReview.vue';
import type { PendingChange } from '@/components/review/ChangeReview.vue';
import ReviewInbox from '@/components/review/ReviewInbox.vue';
import ReviewRow from '@/components/review/ReviewRow.vue';
import type { RowFlag } from '@/components/review/ReviewRow.vue';
import admin from '@/routes/admin';
import adminEditRequests from '@/routes/admin/edit-requests';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Change Requests', href: adminEditRequests.index() },
        ],
    },
});

const props = defineProps<{
    today: string;
    pending: PendingChange[];
}>();

const now = useNow({ interval: 60_000 });

/* ---- Selection: changes have no page of their own, so it's held here -- */

// ?request=12 opens one directly (a link from a notification or the desk).
const fromUrl =
    Number(
        new URL(usePage().url, 'http://localhost').searchParams.get('request'),
    ) || null;

const selectedId = ref<number | null>(
    props.pending.some((p) => p.id === fromUrl) ? fromUrl : null,
);
const selected = computed(
    () => props.pending.find((p) => p.id === selectedId.value) ?? null,
);

// Where the open one sat, so deciding it opens whatever moved into its place.
const lastIndex = ref(0);
watch(
    () => props.pending.findIndex((p) => p.id === selectedId.value),
    (i) => i >= 0 && (lastIndex.value = i),
    { immediate: true },
);

watch(
    () => props.pending,
    (list) => {
        if (
            selectedId.value !== null &&
            !list.some((p) => p.id === selectedId.value)
        ) {
            selectedId.value =
                (list[lastIndex.value] ?? list.at(-1))?.id ?? null;
        }
    },
);

onMounted(() => {
    // Wide screens open the first change straight away; narrow ones show the list.
    if (
        selectedId.value === null &&
        props.pending.length > 0 &&
        window.matchMedia('(min-width: 1024px)').matches
    ) {
        selectedId.value = props.pending[0].id;
    }
});

const step = (by: 1 | -1) => {
    const i = props.pending.findIndex((p) => p.id === selectedId.value);
    const next = props.pending[i + by];

    if (next) {
        selectedId.value = next.id;
    }
};

useEventListener(defaultWindow, 'keydown', (e: KeyboardEvent) => {
    const target = e.target as HTMLElement | null;

    if (
        e.ctrlKey ||
        e.metaKey ||
        e.altKey ||
        target?.closest('input, textarea, select, [contenteditable]') ||
        document.querySelector('[role="dialog"], [role="menu"]')
    ) {
        return;
    }

    if (e.key === 'j') {
        step(1);
    } else if (e.key === 'k') {
        step(-1);
    }
});

/* ---- Rows ------------------------------------------------------------- */

/** What the request does, in a few words: the move first, else the rest. */
const summary = (c: PendingChange) => {
    if (c.proposed.starts_at || c.proposed.ends_at) {
        const start = c.proposed.starts_at ?? c.current.starts_at;
        const end = c.proposed.ends_at ?? c.current.ends_at;

        return `Moves to ${dayLabel(start, now.value)} ${timeRange(start, end)}`;
    }

    const parts = [
        c.proposed.purpose !== null && 'new purpose',
        (c.proposed.people !== null || c.proposed.attendee_count !== null) &&
            'new people',
    ].filter(Boolean) as string[];

    const text = parts.join(', ') || 'No visible change';

    return text.charAt(0).toUpperCase() + text.slice(1);
};

const flagOf = (c: PendingChange): RowFlag | null => {
    if (c.clash?.status === 'approved') {
        return {
            text: 'Taken',
            tone: 'danger',
            detail: `“${c.clash.title}” holds ${timeRange(c.clash.starts_at, c.clash.ends_at)}`,
        };
    }

    if (c.passed) {
        return {
            text: 'Passed',
            tone: 'quiet',
            detail: 'The new time has already started',
        };
    }

    return c.clash
        ? {
              text: 'Overlap',
              tone: 'warn',
              detail: `“${c.clash.title}” is also requested then`,
          }
        : null;
};
</script>

<template>
    <Head title="Change Requests" />

    <div class="flex flex-1 flex-col gap-5 p-4 sm:p-6">
        <header class="flex items-baseline gap-3">
            <h1 class="text-2xl font-semibold tracking-tight">
                Change requests
            </h1>
            <span
                v-if="pending.length"
                class="text-sm text-muted-foreground tabular-nums"
            >
                {{ pending.length }} waiting
            </span>
        </header>

        <EmptyNote
            v-if="pending.length === 0"
            :icon="Inbox"
            title="No changes are waiting."
        >
            When someone asks to move or reword an approved booking, it shows up
            here for a decision.
        </EmptyNote>

        <ReviewInbox
            v-else
            :has-selection="selected !== null"
            list-label="Change requests waiting for review"
        >
            <template #list>
                <ul>
                    <ReviewRow
                        v-for="change in pending"
                        :key="change.id"
                        :title="change.title"
                        :meta="`${summary(change)} · ${change.resource.name}`"
                        :color="change.resource.color"
                        :flag="flagOf(change)"
                        :selected="change.id === selectedId"
                        @select="selectedId = change.id"
                    />
                </ul>
            </template>

            <template #detail>
                <div class="flex min-h-[calc(100svh-12rem)] flex-1 flex-col">
                    <ChangeReview
                        v-if="selected"
                        :key="selected.id"
                        :change="selected"
                        :now="now"
                        @back="selectedId = null"
                    />
                    <p
                        v-else
                        class="flex flex-1 items-center justify-center text-sm text-muted-foreground"
                    >
                        Pick a change to review it.
                    </p>
                </div>
            </template>
        </ReviewInbox>
    </div>
</template>
