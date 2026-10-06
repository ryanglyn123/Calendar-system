<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Megaphone, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watchEffect } from 'vue';
import EmptyNote from '@/components/admin/EmptyNote.vue';
import FilterTabs from '@/components/admin/FilterTabs.vue';
import type { FilterTab } from '@/components/admin/FilterTabs.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { useConfirm } from '@/composables/useConfirm';
import { format, parseISO } from '@/lib/calendarMath';
import admin from '@/routes/admin';
import adminAnnouncements from '@/routes/admin/announcements';
import type { Division } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Announcements', href: adminAnnouncements.index() },
        ],
    },
});

type AnnouncementRow = {
    id: number;
    title: string;
    body: string;
    level: 'info' | 'warning' | 'critical';
    starts_at: string;
    ends_at: string | null;
    division_id: number | null;
    division: string | null;
    is_dismissible: boolean;
    is_active: boolean;
};

type Status = 'live' | 'scheduled' | 'ended' | 'draft';

const props = defineProps<{
    /**
     * Not `announcements`: that name is the shared banner prop, and a page prop
     * of the same name would replace it (showing scheduled ones as banners).
     */
    rows: AnnouncementRow[];
    divisions: { data: Division[] };
    /** The office's date (Y-m-d); dates are compared as strings. */
    today: string;
}>();

/**
 * Mirrors the server's "live" test: active, started, and not yet at its end
 * date (the banner drops as the end day begins, so that day counts as ended).
 */
const statusOf = (announcement: AnnouncementRow): Status => {
    if (!announcement.is_active) {
        return 'draft';
    }

    if (announcement.starts_at > props.today) {
        return 'scheduled';
    }

    return announcement.ends_at !== null && announcement.ends_at <= props.today
        ? 'ended'
        : 'live';
};

const statusLabels: Record<Status, string> = {
    live: 'Live',
    scheduled: 'Scheduled',
    ended: 'Ended',
    draft: 'Draft',
};

const levels: Record<
    AnnouncementRow['level'],
    { label: string; class: string }
> = {
    critical: { label: 'Critical', class: 'text-destructive' },
    warning: { label: 'Warning', class: 'text-warning-ink' },
    info: { label: 'Info', class: 'text-muted-foreground' },
};

const rows = computed(() =>
    props.rows.map((announcement) => ({
        ...announcement,
        status: statusOf(announcement),
    })),
);

const filter = ref<Status | 'all'>('all');

const counts = computed(() => {
    const tally: Record<Status, number> = {
        live: 0,
        scheduled: 0,
        ended: 0,
        draft: 0,
    };

    rows.value.forEach((row) => tally[row.status]++);

    return tally;
});

const filterOptions = computed<FilterTab<Status | 'all'>[]>(() => [
    { value: 'all', label: 'All', count: rows.value.length },
    ...(Object.keys(statusLabels) as Status[])
        .filter((status) => counts.value[status] > 0)
        .map((status) => ({
            value: status,
            label: status === 'draft' ? 'Drafts' : statusLabels[status],
            count: counts.value[status],
        })),
]);

// An edit can empty the tab you are on; fall back rather than show nothing.
watchEffect(() => {
    if (filter.value !== 'all' && counts.value[filter.value] === 0) {
        filter.value = 'all';
    }
});

const visible = computed(() =>
    filter.value === 'all'
        ? rows.value
        : rows.value.filter((row) => row.status === filter.value),
);

const day = (iso: string) =>
    format(
        parseISO(iso),
        iso.startsWith(props.today.slice(0, 4)) ? 'MMM d' : 'MMM d, yyyy',
    );

const runs = (announcement: AnnouncementRow) =>
    announcement.ends_at
        ? `${day(announcement.starts_at)} – ${day(announcement.ends_at)}`
        : `From ${day(announcement.starts_at)}`;

const iconButton =
    'rounded-full text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60';

const editing = ref<AnnouncementRow | null>(null);
const creating = ref(false);

const form = useForm({
    title: '',
    body: '',
    level: 'info' as AnnouncementRow['level'],
    starts_at: new Date().toISOString().slice(0, 10),
    ends_at: null as string | null,
    division_id: null as number | null,
    is_dismissible: true,
    is_active: true,
});

const openCreate = () => {
    form.reset();
    form.clearErrors();
    creating.value = true;
};

const openEdit = (announcement: AnnouncementRow) => {
    form.clearErrors();
    form.title = announcement.title;
    form.body = announcement.body;
    form.level = announcement.level;
    form.starts_at = announcement.starts_at;
    form.ends_at = announcement.ends_at;
    form.division_id = announcement.division_id;
    form.is_dismissible = announcement.is_dismissible;
    form.is_active = announcement.is_active;
    editing.value = announcement;
};

const close = () => {
    creating.value = false;
    editing.value = null;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: close };

    if (editing.value) {
        form.patch(adminAnnouncements.update(editing.value.id).url, options);
    } else {
        form.post(adminAnnouncements.store().url, options);
    }
};

const { confirm } = useConfirm();

const destroy = async (announcement: AnnouncementRow) => {
    const confirmed = await confirm({
        title: `Delete “${announcement.title}”?`,
        description:
            statusOf(announcement) === 'live'
                ? 'It is live now; the banner disappears for its audience.'
                : undefined,
        confirmLabel: 'Delete',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    router.delete(adminAnnouncements.destroy(announcement.id).url, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Announcements" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Announcements"
            description="Banners shown to everyone, or to one division only."
        >
            <template #actions>
                <Button class="rounded-full" @click="openCreate">
                    <Plus aria-hidden="true" />
                    New announcement
                </Button>
            </template>
        </PageHeader>

        <EmptyNote
            v-if="rows.length === 0"
            :icon="Megaphone"
            title="No announcements yet"
        >
            A banner across the top of the calendar, for everyone or one
            division, between the dates you set.
            <template #action>
                <Button
                    variant="outline"
                    size="sm"
                    class="rounded-full"
                    @click="openCreate"
                >
                    <Plus aria-hidden="true" />
                    New announcement
                </Button>
            </template>
        </EmptyNote>

        <template v-else>
            <FilterTabs
                v-model="filter"
                :options="filterOptions"
                label="Filter announcements by status"
            />

            <ul class="divide-y divide-border border-y border-border">
                <li
                    v-for="announcement in visible"
                    :key="announcement.id"
                    class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-1 px-2 py-3 md:grid-cols-[minmax(0,1fr)_10rem_10rem_auto] md:items-center md:gap-x-6"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">
                            {{ announcement.title }}
                        </p>
                        <p class="truncate text-[13px] text-muted-foreground">
                            <span :class="levels[announcement.level].class">
                                {{ levels[announcement.level].label }}
                            </span>
                            · {{ announcement.body }}
                        </p>
                    </div>

                    <div
                        class="col-start-1 text-[13px] tabular-nums md:col-start-2 md:row-start-1"
                    >
                        <p
                            class="flex items-center gap-1.5"
                            :class="{
                                'font-medium': announcement.status === 'live',
                                'text-muted-foreground': [
                                    'ended',
                                    'draft',
                                ].includes(announcement.status),
                            }"
                        >
                            <span
                                v-if="announcement.status === 'live'"
                                class="size-1.5 rounded-full bg-success"
                                aria-hidden="true"
                            />
                            {{ statusLabels[announcement.status] }}
                        </p>
                        <p class="text-muted-foreground">
                            {{ runs(announcement) }}
                        </p>
                    </div>

                    <p
                        class="col-start-1 truncate text-[13px] text-muted-foreground md:col-start-3 md:row-start-1"
                    >
                        {{ announcement.division ?? 'Everyone' }}
                    </p>

                    <div
                        class="col-start-2 row-span-3 row-start-1 flex gap-1 self-start md:col-start-4 md:row-span-1 md:self-center"
                    >
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            :class="iconButton"
                            :aria-label="`Edit ${announcement.title}`"
                            @click="openEdit(announcement)"
                        >
                            <Pencil aria-hidden="true" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            :class="[iconButton, 'text-destructive hover:bg-destructive/10 hover:text-destructive']"
                            :aria-label="`Delete ${announcement.title}`"
                            @click="destroy(announcement)"
                        >
                            <Trash2 aria-hidden="true" />
                        </Button>
                    </div>
                </li>
            </ul>
        </template>

        <Dialog
            :open="creating || editing !== null"
            @update:open="(open) => !open && close()"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{ editing ? 'Edit announcement' : 'New announcement' }}
                    </DialogTitle>
                    <DialogDescription>
                        Critical announcements stand out; keep them rare.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="announcement-title">Title</Label>
                        <Input id="announcement-title" v-model="form.title" />
                        <p
                            v-if="form.errors.title"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.title }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="announcement-body">Message</Label>
                        <Textarea
                            id="announcement-body"
                            v-model="form.body"
                            rows="2"
                        />
                        <p
                            v-if="form.errors.body"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.body }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="announcement-level">Level</Label>
                            <Select v-model="form.level">
                                <SelectTrigger id="announcement-level">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="info">Info</SelectItem>
                                    <SelectItem value="warning">
                                        Warning
                                    </SelectItem>
                                    <SelectItem value="critical">
                                        Critical
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="announcement-division">
                                Audience
                            </Label>
                            <Select
                                :model-value="
                                    form.division_id === null
                                        ? 'all'
                                        : String(form.division_id)
                                "
                                @update:model-value="
                                    (v) =>
                                        (form.division_id =
                                            v === 'all' ? null : Number(v))
                                "
                            >
                                <SelectTrigger id="announcement-division">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Everyone
                                    </SelectItem>
                                    <SelectItem
                                        v-for="d in divisions.data"
                                        :key="d.id"
                                        :value="String(d.id)"
                                    >
                                        {{ d.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="announcement-start">Starts</Label>
                            <Input
                                id="announcement-start"
                                v-model="form.starts_at"
                                type="date"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="announcement-end">Ends</Label>
                            <Input
                                id="announcement-end"
                                :model-value="form.ends_at ?? ''"
                                type="date"
                                @update:model-value="
                                    (v) =>
                                        (form.ends_at =
                                            v === '' ? null : String(v))
                                "
                            />
                        </div>
                    </div>

                    <Label class="flex items-center gap-3 font-normal">
                        <Switch v-model="form.is_dismissible" />
                        Users can dismiss it
                    </Label>
                    <Label class="flex items-center gap-3 font-normal">
                        <Switch v-model="form.is_active" />
                        Active
                    </Label>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="close">Cancel</Button>
                    <Button :disabled="form.processing" @click="submit">
                        {{ editing ? 'Save changes' : 'Publish' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
