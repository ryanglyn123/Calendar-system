<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Ban,
    CalendarDays,
    CalendarX2,
    ChevronLeft,
    ChevronRight,
    List,
    Pencil,
    Plus,
    Repeat,
    Trash2,
} from '@lucide/vue';
import { isValid } from 'date-fns';
import { computed, ref } from 'vue';
import EmptyNote from '@/components/admin/EmptyNote.vue';
import HolidayYear from '@/components/admin/HolidayYear.vue';
import type {
    HolidayKind,
    HolidayOccurrence,
    HolidayRow,
} from '@/components/admin/HolidayYear.vue';
import ViewSwitcher from '@/components/admin/ViewSwitcher.vue';
import type { ViewOption } from '@/components/admin/ViewSwitcher.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useConfirm } from '@/composables/useConfirm';
import { useViewPreference } from '@/composables/useViewPreference';
import { format, parseISO } from '@/lib/calendarMath';
import type { WeekStart } from '@/lib/calendarMath';
import admin from '@/routes/admin';
import adminHolidays from '@/routes/admin/holidays';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Holidays', href: adminHolidays.index() },
        ],
    },
});

const props = defineProps<{
    holidays: HolidayRow[];
    weekStartsOn: WeekStart;
    google: {
        configured: boolean;
        calendar_ids: string[];
        synced_at: string | null;
        error: string | null;
    };
}>();

/* ---- Google public holiday calendars feed this list ---- */

const importing = ref(false);
const sourcesOpen = ref(false);
const sourcesForm = useForm({ calendar_ids: [] as string[] });
const sourcesText = ref('');

const sourceName = (id: string) =>
    id
        .replace(/#holiday@group\.v\.calendar\.google\.com$/i, '')
        .replace(/^[a-z]{2}\./i, '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());

const importNow = () => {
    importing.value = true;
    router.post(
        adminHolidays.googleSync().url,
        {},
        { preserveScroll: true, onFinish: () => (importing.value = false) },
    );
};

const openSources = () => {
    sourcesText.value = props.google.calendar_ids.join('\n');
    sourcesForm.clearErrors();
    sourcesOpen.value = true;
};

const saveSources = () => {
    sourcesForm.calendar_ids = sourcesText.value
        .split(/\s+/)
        .map((id) => id.trim())
        .filter(Boolean);
    sourcesForm.put(adminHolidays.googleSources().url, {
        preserveScroll: true,
        onSuccess: () => (sourcesOpen.value = false),
    });
};

const sourcesError = computed(
    () =>
        Object.entries(sourcesForm.errors).find(([key]) =>
            key.startsWith('calendar_ids'),
        )?.[1] ?? null,
);

const typeLabels: Record<HolidayKind, string> = {
    regular: 'Regular holiday',
    special_non_working: 'Special non-working',
    company: 'FDCP holiday',
    observance: 'Observance',
};

const viewOptions: ViewOption<'list' | 'year'>[] = [
    { value: 'list', label: 'List', icon: List, hint: 'Holidays by month' },
    {
        value: 'year',
        label: 'Year',
        icon: CalendarDays,
        hint: 'All twelve months; click a date to add or edit',
    },
];

const view = useViewPreference('holidays', ['list', 'year'] as const);
const year = ref(new Date().getFullYear());

/**
 * The selected year's holidays by date. Yearly ones land on the same month
 * and day, as the booking check matches them (so Feb 29 only in leap years).
 */
const occurrences = computed<HolidayOccurrence[]>(() =>
    props.holidays
        .map((holiday) => ({
            holiday,
            iso: holiday.recurs_annually
                ? `${year.value}${holiday.date.slice(4)}`
                : holiday.date,
        }))
        .filter(
            ({ iso }) =>
                iso.startsWith(`${year.value}-`) && isValid(parseISO(iso)),
        )
        .sort((a, b) => a.iso.localeCompare(b.iso)),
);

const months = computed(() => {
    const groups = new Map<string, HolidayOccurrence[]>();

    for (const occurrence of occurrences.value) {
        const key = occurrence.iso.slice(0, 7);
        groups.set(key, [...(groups.get(key) ?? []), occurrence]);
    }

    return [...groups].map(([key, items]) => ({
        key,
        label: format(parseISO(`${key}-01`), 'MMMM'),
        items,
    }));
});

const iconButton =
    'rounded-full text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60';

const editing = ref<HolidayRow | null>(null);
const creating = ref(false);

const form = useForm({
    name: '',
    date: '',
    recurs_annually: true,
    type: 'regular' as HolidayKind,
    blocks_bookings: true,
});

const openCreate = (date?: string) => {
    form.reset();
    form.clearErrors();
    form.date = date ?? '';
    creating.value = true;
};

const openEdit = (holiday: HolidayRow) => {
    form.clearErrors();
    form.name = holiday.name;
    form.date = holiday.date;
    form.recurs_annually = holiday.recurs_annually;
    form.type = holiday.type;
    form.blocks_bookings = holiday.blocks_bookings;
    editing.value = holiday;
};

const close = () => {
    creating.value = false;
    editing.value = null;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: close };

    if (editing.value) {
        form.patch(adminHolidays.update(editing.value.id).url, options);
    } else {
        form.post(adminHolidays.store().url, options);
    }
};

const { confirm } = useConfirm();

const destroy = async (holiday: HolidayRow) => {
    const confirmed = await confirm({
        title: `Delete ${holiday.name}?`,
        description: holiday.recurs_annually
            ? 'It repeats every year, so this removes it from every year.'
            : undefined,
        confirmLabel: 'Delete',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    router.delete(adminHolidays.destroy(holiday.id).url, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Holidays" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Holidays"
            description="Holidays can block bookings on resource types that opt in."
        >
            <template #actions>
                <Button class="rounded-full" @click="openCreate()">
                    <Plus aria-hidden="true" />
                    Add holiday
                </Button>
            </template>
        </PageHeader>

        <!-- Where the list comes from: Google's public holiday calendars -->
        <section
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm"
            aria-label="Google holiday import"
        >
            <div class="min-w-0">
                <p v-if="google.configured && google.calendar_ids.length">
                    Imported from Google:
                    <span class="font-medium">
                        {{ google.calendar_ids.map(sourceName).join(', ') }}
                    </span>
                </p>
                <p v-else-if="google.configured">
                    Not importing public holidays from Google.
                </p>
                <p v-else class="text-muted-foreground">
                    Public holidays can be imported from Google Calendar once a
                    Google API key is set on the server.
                </p>
                <p
                    v-if="google.error"
                    class="text-[13px] text-destructive"
                    role="status"
                >
                    {{ google.error }}
                </p>
                <p
                    v-else-if="google.synced_at"
                    class="text-[13px] text-muted-foreground"
                >
                    Last imported
                    {{ format(parseISO(google.synced_at), 'MMM d, h:mm a') }}.
                    Edited or deleted imports stay as you left them.
                </p>
            </div>
            <div v-if="google.configured" class="flex shrink-0 gap-2">
                <Button variant="ghost" size="sm" @click="openSources">
                    Sources
                </Button>
                <Button
                    v-if="google.calendar_ids.length"
                    variant="outline"
                    size="sm"
                    :disabled="importing"
                    @click="importNow"
                >
                    {{ importing ? 'Importing…' : 'Import now' }}
                </Button>
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="icon-sm"
                    :class="iconButton"
                    aria-label="Previous year"
                    @click="year--"
                >
                    <ChevronLeft aria-hidden="true" />
                </Button>
                <h2
                    class="min-w-12 text-center text-[15px] font-semibold tracking-tight tabular-nums"
                    aria-live="polite"
                >
                    {{ year }}
                </h2>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    :class="iconButton"
                    aria-label="Next year"
                    @click="year++"
                >
                    <ChevronRight aria-hidden="true" />
                </Button>
                <span class="ml-2 text-sm text-muted-foreground tabular-nums">
                    {{ occurrences.length }}
                    {{ occurrences.length === 1 ? 'holiday' : 'holidays' }}
                </span>
            </div>

            <ViewSwitcher
                v-model="view"
                :options="viewOptions"
                label="Holiday view"
            />
        </div>

        <HolidayYear
            v-if="view === 'year'"
            :year="year"
            :week-starts-on="weekStartsOn"
            :occurrences="occurrences"
            :labels="typeLabels"
            @edit="openEdit"
            @create="openCreate"
        />

        <EmptyNote
            v-else-if="months.length === 0"
            :icon="CalendarX2"
            :title="`No holidays in ${year}`"
        >
            Yearly holidays show in every year; one-off holidays only in their
            own.
            <template #action>
                <Button
                    variant="outline"
                    size="sm"
                    class="rounded-full"
                    @click="openCreate()"
                >
                    <Plus aria-hidden="true" />
                    Add holiday
                </Button>
            </template>
        </EmptyNote>

        <div v-else class="space-y-6">
            <section v-for="month in months" :key="month.key">
                <h3
                    class="flex items-baseline gap-2 px-2 pb-3 text-[15px] font-semibold tracking-tight"
                >
                    {{ month.label }}
                    <span
                        class="text-sm font-normal text-muted-foreground tabular-nums"
                    >
                        {{ month.items.length }}
                    </span>
                </h3>

                <ul class="divide-y divide-border border-y border-border">
                    <li
                        v-for="{ iso, holiday } in month.items"
                        :key="holiday.id"
                        class="flex items-center gap-4 px-2 py-3"
                    >
                        <span
                            class="inline-flex w-14 shrink-0 gap-1 text-[13px] tabular-nums"
                        >
                            <span class="text-muted-foreground">
                                {{ format(parseISO(iso), 'EEE') }}
                            </span>
                            <span class="font-medium">
                                {{ format(parseISO(iso), 'd') }}
                            </span>
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ holiday.name }}
                            </p>
                            <p
                                class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[13px] text-muted-foreground"
                            >
                                <span>{{ typeLabels[holiday.type] }}</span>
                                <span
                                    v-if="holiday.source === 'google'"
                                    :title="
                                        holiday.overridden
                                            ? 'Imported from Google, then edited here; imports leave it alone'
                                            : 'Imported from Google Calendar'
                                    "
                                >
                                    {{
                                        holiday.overridden
                                            ? 'From Google, edited'
                                            : 'From Google'
                                    }}
                                </span>
                                <span
                                    v-if="holiday.recurs_annually"
                                    class="inline-flex items-center gap-1"
                                >
                                    <Repeat
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Every year
                                </span>
                                <span
                                    v-if="holiday.blocks_bookings"
                                    class="inline-flex items-center gap-1"
                                >
                                    <Ban class="size-3.5" aria-hidden="true" />
                                    Blocks bookings
                                </span>
                            </p>
                        </div>

                        <div class="flex shrink-0 gap-1">
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :class="iconButton"
                                :aria-label="`Edit ${holiday.name}`"
                                @click="openEdit(holiday)"
                            >
                                <Pencil aria-hidden="true" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :class="[
                                    iconButton,
                                    'text-destructive hover:bg-destructive/10 hover:text-destructive',
                                ]"
                                :aria-label="`Delete ${holiday.name}`"
                                @click="destroy(holiday)"
                            >
                                <Trash2 aria-hidden="true" />
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <Dialog
            :open="creating || editing !== null"
            @update:open="(open) => !open && close()"
        >
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {{ editing ? `Edit ${editing.name}` : 'Add holiday' }}
                    </DialogTitle>
                    <DialogDescription>
                        Yearly holidays repeat automatically on the same date.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="holiday-name">Name</Label>
                        <Input id="holiday-name" v-model="form.name" />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="holiday-date">Date</Label>
                            <Input
                                id="holiday-date"
                                v-model="form.date"
                                type="date"
                            />
                            <p
                                v-if="form.errors.date"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.date }}
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label for="holiday-type">Kind</Label>
                            <Select v-model="form.type">
                                <SelectTrigger id="holiday-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="regular">
                                        Regular holiday
                                    </SelectItem>
                                    <SelectItem value="special_non_working">
                                        Special non-working
                                    </SelectItem>
                                    <SelectItem value="company">
                                        FDCP holiday
                                    </SelectItem>
                                    <SelectItem value="observance">
                                        Observance (no day off)
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <Label class="flex items-center gap-3 font-normal">
                        <Switch v-model="form.recurs_annually" />
                        Repeats every year
                    </Label>
                    <Label class="flex items-center gap-3 font-normal">
                        <Switch v-model="form.blocks_bookings" />
                        Blocks bookings
                    </Label>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="close">Cancel</Button>
                    <Button :disabled="form.processing" @click="submit">
                        {{ editing ? 'Save changes' : 'Add holiday' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="sourcesOpen">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Google holiday calendars</DialogTitle>
                    <DialogDescription>
                        One Google holiday calendar id per line. Imports run
                        nightly and when you press Import now.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="holiday-sources">Calendar ids</Label>
                    <textarea
                        id="holiday-sources"
                        v-model="sourcesText"
                        rows="4"
                        spellcheck="false"
                        class="w-full rounded-md border bg-transparent px-3 py-2 font-mono text-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        placeholder="en.philippines#holiday@group.v.calendar.google.com"
                    />
                    <p v-if="sourcesError" class="text-sm text-destructive">
                        {{ sourcesError }}
                    </p>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="sourcesOpen = false">
                        Cancel
                    </Button>
                    <Button
                        :disabled="sourcesForm.processing"
                        @click="saveSources"
                    >
                        Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
