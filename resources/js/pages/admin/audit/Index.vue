<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ChevronRight, ScrollText, SearchX } from '@lucide/vue';
import { computed } from 'vue';
import EmptyNote from '@/components/admin/EmptyNote.vue';
import ListPagination from '@/components/admin/ListPagination.vue';
import SearchField from '@/components/admin/SearchField.vue';
import { dayLabel, pillQuiet } from '@/components/desk/desk';
import PageHeader from '@/components/PageHeader.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useServerFilters } from '@/composables/useServerFilters';
import { format, parseISO } from '@/lib/calendarMath';
import admin from '@/routes/admin';
import adminAudit from '@/routes/admin/audit';
import type { Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Audit Log', href: adminAudit.index() },
        ],
    },
});

type ActivityRow = {
    id: number;
    description: string;
    event: string | null;
    causer: string | null;
    subject_type: string | null;
    subject_id: number | null;
    // spatie/activitylog's attribute diff; an empty array when nothing changed.
    changes: Partial<Record<'attributes' | 'old', Record<string, unknown>>>;
    created_at: string;
};

const props = defineProps<{
    activities: Paginated<ActivityRow>;
    events: string[];
    actors: { id: number; name: string }[];
    filters: {
        search?: string;
        event?: string;
        causer?: string;
        from?: string;
        to?: string;
    };
}>();

const blank = { search: '', event: 'all', causer: 'all', from: '', to: '' };

const filters = useServerFilters(
    () => adminAudit.index().url,
    {
        search: props.filters.search ?? '',
        event: props.filters.event ?? 'all',
        causer: props.filters.causer ?? 'all',
        from: props.filters.from ?? '',
        to: props.filters.to ?? '',
    },
    { only: ['activities', 'filters'] },
);

const filtered = computed(() =>
    Object.values(filters).some((value) => value && value !== 'all'),
);

const clearFilters = () => Object.assign(filters, blank);

const sentence = (value: string) =>
    value.charAt(0).toUpperCase() + value.slice(1);

const shown = (value: unknown): string => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
};

type Change = { field: string; before: string | null; after: string };

const changeList = (changes: ActivityRow['changes']): Change[] => {
    const after = changes.attributes ?? {};
    const before = changes.old ?? {};

    return [...new Set([...Object.keys(before), ...Object.keys(after)])].map(
        (field) => ({
            field: field.replaceAll('_', ' '),
            before: field in before ? shown(before[field]) : null,
            after: field in after ? shown(after[field]) : '—',
        }),
    );
};

/** The page's entries under one heading per day, newest first. */
const days = computed(() => {
    const groups = new Map<
        string,
        (ActivityRow & { tag: string | null; diff: Change[] })[]
    >();

    for (const activity of props.activities.data) {
        const day = format(parseISO(activity.created_at), 'yyyy-MM-dd');
        groups.set(day, [
            ...(groups.get(day) ?? []),
            {
                ...activity,
                // Model events log their event as the description: say it once.
                tag:
                    activity.event !== activity.description
                        ? activity.event
                        : null,
                diff: changeList(activity.changes),
            },
        ]);
    }

    const thisYear = format(new Date(), 'yyyy');

    return [...groups].map(([day, rows]) => ({
        day,
        label:
            dayLabel(day) +
            (day.startsWith(thisYear) ? '' : `, ${day.slice(0, 4)}`),
        rows,
    }));
});

const dateField =
    'h-8 rounded-full bg-transparent px-3 text-[13px] tabular-nums ring-1 ring-border outline-none ring-inset focus-visible:ring-2 focus-visible:ring-ring/60';
</script>

<template>
    <Head title="Audit Log" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Audit Log"
            description="Every administrative action, recorded and searchable."
        />

        <div class="flex flex-wrap items-center gap-2">
            <SearchField
                v-model="filters.search"
                label="Search activity"
                placeholder="Search descriptions…"
            />

            <Select v-model="filters.event">
                <SelectTrigger
                    size="sm"
                    class="w-36"
                    aria-label="Filter by event"
                >
                    <SelectValue>{{
                        filters.event && filters.event !== 'all'
                            ? sentence(filters.event)
                            : 'All events'
                    }}</SelectValue>
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All events</SelectItem>
                    <SelectItem
                        v-for="event in events"
                        :key="event"
                        :value="event"
                    >
                        {{ sentence(event) }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select v-model="filters.causer">
                <SelectTrigger
                    size="sm"
                    class="w-44"
                    aria-label="Filter by actor"
                >
                    <SelectValue>{{
                        actors.find((a) => String(a.id) === filters.causer)
                            ?.name ?? 'Anyone'
                    }}</SelectValue>
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">Anyone</SelectItem>
                    <SelectItem
                        v-for="actor in actors"
                        :key="actor.id"
                        :value="String(actor.id)"
                    >
                        {{ actor.name }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <div
                class="flex items-center gap-1.5"
                role="group"
                aria-label="Date range"
            >
                <input
                    v-model="filters.from"
                    type="date"
                    :max="filters.to || undefined"
                    :class="dateField"
                    aria-label="From date"
                />
                <span
                    class="text-[13px] text-muted-foreground"
                    aria-hidden="true"
                    >–</span
                >
                <input
                    v-model="filters.to"
                    type="date"
                    :min="filters.from || undefined"
                    :class="dateField"
                    aria-label="To date"
                />
            </div>

            <button
                v-if="filtered"
                type="button"
                :class="pillQuiet"
                @click="clearFilters"
            >
                Clear filters
            </button>
        </div>

        <EmptyNote
            v-if="activities.data.length === 0 && filtered"
            :icon="SearchX"
            title="No entries match these filters"
        >
            Try a wider date range, another actor or a shorter search.
            <template #action>
                <button type="button" :class="pillQuiet" @click="clearFilters">
                    Clear filters
                </button>
            </template>
        </EmptyNote>

        <EmptyNote
            v-else-if="activities.data.length === 0"
            :icon="ScrollText"
            title="Nothing recorded yet"
        >
            Administrative actions appear here as they happen.
        </EmptyNote>

        <div v-else class="flex flex-col gap-6">
            <section
                v-for="group in days"
                :key="group.day"
                :aria-labelledby="`audit-day-${group.day}`"
            >
                <h2
                    :id="`audit-day-${group.day}`"
                    class="pb-3 text-[15px] font-semibold tracking-tight"
                >
                    {{ group.label }}
                </h2>

                <ul class="divide-y divide-border border-y border-border">
                    <li
                        v-for="activity in group.rows"
                        :key="activity.id"
                        class="flex gap-3 px-2 py-3"
                    >
                        <time
                            :datetime="activity.created_at"
                            class="w-10 shrink-0 pt-px text-[13px] text-muted-foreground tabular-nums"
                        >
                            {{ format(parseISO(activity.created_at), 'HH:mm') }}
                        </time>

                        <div class="min-w-0 flex-1 md:flex md:gap-3">
                            <p
                                class="text-sm font-medium md:w-40 md:shrink-0 md:truncate"
                                :class="{
                                    'text-muted-foreground': !activity.causer,
                                }"
                            >
                                {{ activity.causer ?? 'System' }}
                            </p>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm break-words">
                                    {{ sentence(activity.description) }}
                                </p>

                                <p
                                    v-if="activity.subject_type || activity.tag"
                                    class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-muted-foreground"
                                >
                                    <span
                                        v-if="activity.subject_type"
                                        class="tabular-nums"
                                    >
                                        {{ activity.subject_type }}
                                        <template v-if="activity.subject_id">
                                            #{{ activity.subject_id }}
                                        </template>
                                    </span>
                                    <span
                                        v-if="activity.tag"
                                        class="rounded-full px-2 text-xs leading-5 ring-1 ring-border ring-inset"
                                    >
                                        {{ activity.tag }}
                                    </span>
                                </p>

                                <details
                                    v-if="activity.diff.length"
                                    class="group mt-1.5"
                                >
                                    <summary
                                        class="-ml-1 inline-flex cursor-pointer list-none items-center gap-1 rounded-full py-0.5 pr-2 pl-1 text-[13px] text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60 motion-safe:transition-colors motion-safe:duration-150 [&::-webkit-details-marker]:hidden"
                                    >
                                        <ChevronRight
                                            class="size-3.5 group-open:rotate-90"
                                            aria-hidden="true"
                                        />
                                        Changes
                                        <span class="tabular-nums opacity-70">
                                            {{ activity.diff.length }}
                                        </span>
                                    </summary>

                                    <dl
                                        class="mt-1.5 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1 pl-5 text-[13px]"
                                    >
                                        <template
                                            v-for="change in activity.diff"
                                            :key="change.field"
                                        >
                                            <dt class="text-muted-foreground">
                                                {{ sentence(change.field) }}
                                            </dt>
                                            <dd
                                                class="break-words tabular-nums"
                                            >
                                                <template
                                                    v-if="
                                                        change.before !== null
                                                    "
                                                >
                                                    <span
                                                        class="text-muted-foreground"
                                                    >
                                                        {{ change.before }}
                                                    </span>
                                                    <span aria-hidden="true">
                                                        →
                                                    </span>
                                                    <span class="sr-only"
                                                        >changed to</span
                                                    >
                                                </template>
                                                {{ change.after }}
                                            </dd>
                                        </template>
                                    </dl>
                                </details>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <ListPagination :meta="activities.meta" noun="entries" />
    </div>
</template>
