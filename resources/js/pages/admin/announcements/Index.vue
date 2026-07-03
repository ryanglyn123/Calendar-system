<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
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
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
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

defineProps<{
    announcements: AnnouncementRow[];
    divisions: { data: Division[] };
}>();

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

const destroy = (announcement: AnnouncementRow) =>
    router.delete(adminAnnouncements.destroy(announcement.id).url, {
        preserveScroll: true,
    });

const levelVariant = (level: AnnouncementRow['level']) =>
    ((
        { info: 'secondary', warning: 'default', critical: 'destructive' }
    )[level]) as 'secondary' | 'default' | 'destructive';
</script>

<template>
    <Head title="Announcements" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Announcements"
            description="Banners shown to everyone, or to one division only."
        >
            <template #actions>
                <Button @click="openCreate">
                    <Plus aria-hidden="true" />
                    New announcement
                </Button>
            </template>
        </PageHeader>

        <div class="rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Announcement</TableHead>
                        <TableHead>Level</TableHead>
                        <TableHead>Audience</TableHead>
                        <TableHead>Runs</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="w-24 text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="announcements.length === 0" :colspan="6">
                        No announcements yet.
                    </TableEmpty>
                    <TableRow
                        v-for="announcement in announcements"
                        :key="announcement.id"
                    >
                        <TableCell>
                            <div class="font-medium">
                                {{ announcement.title }}
                            </div>
                            <div
                                class="max-w-72 truncate text-xs text-muted-foreground"
                            >
                                {{ announcement.body }}
                            </div>
                        </TableCell>
                        <TableCell>
                            <Badge :variant="levelVariant(announcement.level)">
                                {{ announcement.level }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            {{ announcement.division ?? 'Everyone' }}
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ announcement.starts_at }}
                            <template v-if="announcement.ends_at">
                                to {{ announcement.ends_at }}
                            </template>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    announcement.is_active
                                        ? 'default'
                                        : 'secondary'
                                "
                            >
                                {{
                                    announcement.is_active
                                        ? 'Active'
                                        : 'Draft'
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Edit ${announcement.title}`"
                                    @click="openEdit(announcement)"
                                >
                                    <Pencil aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Delete ${announcement.title}`"
                                    @click="destroy(announcement)"
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

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
                        <Input
                            id="announcement-title"
                            v-model="form.title"
                        />
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
