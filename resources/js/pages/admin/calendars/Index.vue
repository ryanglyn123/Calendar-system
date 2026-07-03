<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarDays, Pencil, Plus, Star, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
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
import adminCalendars from '@/routes/admin/calendars';
import type { CalendarItem, Division } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Calendars', href: adminCalendars.index() },
        ],
    },
});

const props = defineProps<{
    calendars: { data: CalendarItem[] };
    divisions: { data: Division[] };
}>();

const organizationCalendars = computed(() =>
    props.calendars.data.filter((c) => c.type === 'organization'),
);

const divisionCalendars = computed(() =>
    props.calendars.data.filter((c) => c.type === 'division'),
);

const editing = ref<CalendarItem | null>(null);
const creating = ref(false);

const form = useForm({
    type: 'organization',
    name: '',
    description: '',
    color: '#CC8500',
    division_id: null as number | null,
    is_default: false,
    is_active: true,
});

const openCreate = () => {
    form.reset();
    form.clearErrors();
    creating.value = true;
};

const openEdit = (calendar: CalendarItem) => {
    form.clearErrors();
    form.type = calendar.type;
    form.name = calendar.name;
    form.description = calendar.description ?? '';
    form.color = calendar.color;
    form.division_id = calendar.division?.id ?? null;
    form.is_default = calendar.is_default;
    form.is_active = calendar.is_active;
    editing.value = calendar;
};

const close = () => {
    creating.value = false;
    editing.value = null;
};

const submit = () => {
    if (editing.value) {
        form.patch(adminCalendars.update(editing.value.id).url, {
            preserveScroll: true,
            onSuccess: close,
        });
    } else {
        form.post(adminCalendars.store().url, {
            preserveScroll: true,
            onSuccess: close,
        });
    }
};

const deleting = ref<CalendarItem | null>(null);

const destroy = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(adminCalendars.destroy(deleting.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = null;
        },
    });
};
</script>

<template>
    <Head title="Calendars" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Calendars"
            description="Organization calendars are visible to everyone; starred ones are on by default. Division calendars belong to their members."
        >
            <template #actions>
                <Button @click="openCreate">
                    <Plus aria-hidden="true" />
                    New calendar
                </Button>
            </template>
        </PageHeader>

        <section
            v-for="group in [
                {
                    title: 'Organization calendars',
                    items: organizationCalendars,
                },
                { title: 'Division calendars', items: divisionCalendars },
            ]"
            :key="group.title"
            class="space-y-2"
        >
            <h2 class="text-sm font-semibold text-muted-foreground">
                {{ group.title }}
            </h2>
            <div class="rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Calendar</TableHead>
                            <TableHead>Division</TableHead>
                            <TableHead>Default</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="w-24 text-right">
                                Actions
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableEmpty
                            v-if="group.items.length === 0"
                            :colspan="5"
                        >
                            No calendars.
                        </TableEmpty>
                        <TableRow
                            v-for="calendar in group.items"
                            :key="calendar.id"
                        >
                            <TableCell>
                                <div class="flex items-center gap-2.5">
                                    <span
                                        class="flex size-7 items-center justify-center rounded-md"
                                        :style="{
                                            backgroundColor:
                                                calendar.color + '22',
                                            color: calendar.color,
                                        }"
                                        aria-hidden="true"
                                    >
                                        <CalendarDays class="size-4" />
                                    </span>
                                    <div>
                                        <div class="font-medium">
                                            {{ calendar.name }}
                                        </div>
                                        <div
                                            v-if="calendar.description"
                                            class="max-w-md truncate text-xs text-muted-foreground"
                                        >
                                            {{ calendar.description }}
                                        </div>
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell>
                                {{ calendar.division?.name ?? '-' }}
                            </TableCell>
                            <TableCell>
                                <Star
                                    v-if="calendar.is_default"
                                    class="size-4 fill-primary text-primary"
                                    aria-label="Default calendar"
                                />
                                <span v-else class="text-muted-foreground">
                                    -
                                </span>
                            </TableCell>
                            <TableCell>
                                <Badge
                                    :variant="
                                        calendar.is_active
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{
                                        calendar.is_active
                                            ? 'Active'
                                            : 'Archived'
                                    }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-right">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Edit ${calendar.name}`"
                                        @click="openEdit(calendar)"
                                    >
                                        <Pencil aria-hidden="true" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="text-destructive hover:text-destructive"
                                        :aria-label="`Delete ${calendar.name}`"
                                        @click="deleting = calendar"
                                    >
                                        <Trash2 aria-hidden="true" />
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>

        <Dialog
            :open="creating || editing !== null"
            @update:open="(open) => !open && close()"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{ editing ? `Edit ${editing.name}` : 'New calendar' }}
                    </DialogTitle>
                    <DialogDescription>
                        Managed calendars are provisioned for the whole
                        organization or a division.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div v-if="!editing" class="grid gap-2">
                        <Label for="calendar-type">Type</Label>
                        <Select v-model="form.type">
                            <SelectTrigger id="calendar-type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="organization">
                                    Organization
                                </SelectItem>
                                <SelectItem value="division">
                                    Division
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="form.errors.type"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.type }}
                        </p>
                    </div>

                    <div
                        v-if="!editing && form.type === 'division'"
                        class="grid gap-2"
                    >
                        <Label for="calendar-division">Division</Label>
                        <Select
                            :model-value="
                                form.division_id === null
                                    ? undefined
                                    : String(form.division_id)
                            "
                            @update:model-value="
                                (v) => (form.division_id = Number(v))
                            "
                        >
                            <SelectTrigger id="calendar-division">
                                <SelectValue placeholder="Select a division" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="d in divisions.data"
                                    :key="d.id"
                                    :value="String(d.id)"
                                >
                                    {{ d.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="form.errors.division_id"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.division_id }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="calendar-name">Name</Label>
                        <Input id="calendar-name" v-model="form.name" />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="calendar-description">Description</Label>
                        <Textarea
                            id="calendar-description"
                            v-model="form.description"
                            rows="2"
                        />
                    </div>

                    <div class="grid grid-cols-2 items-end gap-4">
                        <div class="grid gap-2">
                            <Label for="calendar-color">Color</Label>
                            <div class="flex items-center gap-2">
                                <input
                                    id="calendar-color"
                                    v-model="form.color"
                                    type="color"
                                    class="size-9 cursor-pointer rounded-md border border-input bg-transparent p-1"
                                />
                                <Input
                                    v-model="form.color"
                                    class="font-mono uppercase"
                                    maxlength="7"
                                    aria-label="Hex color"
                                />
                            </div>
                            <p
                                v-if="form.errors.color"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.color }}
                            </p>
                        </div>

                        <div class="grid gap-3 pb-1">
                            <Label
                                class="flex items-center gap-3 font-normal"
                            >
                                <Switch v-model="form.is_default" />
                                Visible to everyone by default
                            </Label>
                            <Label
                                class="flex items-center gap-3 font-normal"
                            >
                                <Switch v-model="form.is_active" />
                                Active
                            </Label>
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="close">Cancel</Button>
                    <Button :disabled="form.processing" @click="submit">
                        {{ editing ? 'Save changes' : 'Create calendar' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="deleting !== null"
            @update:open="(open) => !open && (deleting = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete {{ deleting?.name }}?</DialogTitle>
                    <DialogDescription>
                        The calendar is archived (soft-deleted) along with its
                        events. Consider marking it inactive instead if you
                        only want to hide it.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="deleting = null">
                        Cancel
                    </Button>
                    <Button variant="destructive" @click="destroy">
                        Delete calendar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
