<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Tag, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
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
import admin from '@/routes/admin';
import adminEventTypes from '@/routes/admin/event-types';
import type { EventTypeItem } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Event Types', href: adminEventTypes.index() },
        ],
    },
});

defineProps<{
    eventTypes: { data: EventTypeItem[] };
}>();

const editing = ref<EventTypeItem | null>(null);
const creating = ref(false);

const form = useForm({
    name: '',
    color: '#0096E2',
    description: '',
    is_active: true,
});

const openCreate = () => {
    form.reset();
    form.clearErrors();
    creating.value = true;
};

const openEdit = (eventType: EventTypeItem) => {
    form.clearErrors();
    form.name = eventType.name;
    form.color = eventType.color;
    form.description = eventType.description ?? '';
    form.is_active = eventType.is_active;
    editing.value = eventType;
};

const close = () => {
    creating.value = false;
    editing.value = null;
};

const submit = () => {
    if (editing.value) {
        form.patch(adminEventTypes.update(editing.value.id).url, {
            preserveScroll: true,
            onSuccess: close,
        });
    } else {
        form.post(adminEventTypes.store().url, {
            preserveScroll: true,
            onSuccess: close,
        });
    }
};

const deleting = ref<EventTypeItem | null>(null);

const destroy = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(adminEventTypes.destroy(deleting.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = null;
        },
    });
};
</script>

<template>
    <Head title="Event Types" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <p class="text-sm text-muted-foreground">
                Event types give events a category and a default color.
            </p>
            <Button @click="openCreate">
                <Plus aria-hidden="true" />
                New event type
            </Button>
        </div>

        <div class="rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Type</TableHead>
                        <TableHead>Description</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="w-24 text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="eventTypes.data.length === 0" :colspan="4">
                        No event types yet.
                    </TableEmpty>
                    <TableRow
                        v-for="eventType in eventTypes.data"
                        :key="eventType.id"
                    >
                        <TableCell>
                            <span class="inline-flex items-center gap-2">
                                <span
                                    class="flex size-6 items-center justify-center rounded-md"
                                    :style="{
                                        backgroundColor: eventType.color + '22',
                                        color: eventType.color,
                                    }"
                                    aria-hidden="true"
                                >
                                    <Tag class="size-3.5" />
                                </span>
                                <span class="font-medium">
                                    {{ eventType.name }}
                                </span>
                            </span>
                        </TableCell>
                        <TableCell class="max-w-md truncate text-muted-foreground">
                            {{ eventType.description ?? '-' }}
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    eventType.is_active
                                        ? 'default'
                                        : 'secondary'
                                "
                            >
                                {{ eventType.is_active ? 'Active' : 'Hidden' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Edit ${eventType.name}`"
                                    @click="openEdit(eventType)"
                                >
                                    <Pencil aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Delete ${eventType.name}`"
                                    @click="deleting = eventType"
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
                        {{
                            editing ? `Edit ${editing.name}` : 'New event type'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        Events using this type inherit its color unless they
                        set their own.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="event-type-name">Name</Label>
                        <Input id="event-type-name" v-model="form.name" />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="event-type-description">Description</Label>
                        <Input
                            id="event-type-description"
                            v-model="form.description"
                        />
                    </div>

                    <div class="grid grid-cols-2 items-end gap-4">
                        <div class="grid gap-2">
                            <Label for="event-type-color">Color</Label>
                            <div class="flex items-center gap-2">
                                <input
                                    id="event-type-color"
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

                        <Label
                            class="flex items-center gap-3 pb-1 font-normal"
                        >
                            <Switch v-model="form.is_active" />
                            Active
                        </Label>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="close">Cancel</Button>
                    <Button :disabled="form.processing" @click="submit">
                        {{ editing ? 'Save changes' : 'Create event type' }}
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
                        Events keep their own colors; they just lose this
                        category label.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="deleting = null">
                        Cancel
                    </Button>
                    <Button variant="destructive" @click="destroy">
                        Delete event type
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
