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
import adminResources from '@/routes/admin/resources';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Resources', href: adminResources.index() },
        ],
    },
});

type ResourceRow = {
    id: number;
    name: string;
    description: string | null;
    location: string | null;
    capacity: number | null;
    is_active: boolean;
    resource_type_id: number;
    type: string;
    color: string | null;
};

defineProps<{
    resources: ResourceRow[];
    resourceTypes: { id: number; name: string }[];
}>();

const editing = ref<ResourceRow | null>(null);
const creating = ref(false);

const form = useForm({
    resource_type_id: null as number | null,
    name: '',
    description: '',
    location: '',
    capacity: null as number | null,
    color: '#0096E2',
    is_active: true,
});

const openCreate = () => {
    form.reset();
    form.clearErrors();
    creating.value = true;
};

const openEdit = (resource: ResourceRow) => {
    form.clearErrors();
    form.resource_type_id = resource.resource_type_id;
    form.name = resource.name;
    form.description = resource.description ?? '';
    form.location = resource.location ?? '';
    form.capacity = resource.capacity;
    form.color = resource.color ?? '#0096E2';
    form.is_active = resource.is_active;
    editing.value = resource;
};

const close = () => {
    creating.value = false;
    editing.value = null;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: close };

    if (editing.value) {
        form.patch(adminResources.update(editing.value.id).url, options);
    } else {
        form.post(adminResources.store().url, options);
    }
};

const destroy = (resource: ResourceRow) =>
    router.delete(adminResources.destroy(resource.id).url, {
        preserveScroll: true,
    });
</script>

<template>
    <Head title="Resources" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Resources"
            description="Every resource gets its own calendar; bookings show up there."
        >
            <template #actions>
                <Button @click="openCreate">
                    <Plus aria-hidden="true" />
                    New resource
                </Button>
            </template>
        </PageHeader>

        <div class="rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Resource</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Location</TableHead>
                        <TableHead>Capacity</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="w-24 text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="resources.length === 0" :colspan="6">
                        No resources yet.
                    </TableEmpty>
                    <TableRow v-for="resource in resources" :key="resource.id">
                        <TableCell>
                            <span class="inline-flex items-center gap-2">
                                <span
                                    class="size-2.5 rounded-full"
                                    :style="{
                                        backgroundColor:
                                            resource.color ?? '#0096E2',
                                    }"
                                    aria-hidden="true"
                                />
                                <span class="font-medium">
                                    {{ resource.name }}
                                </span>
                            </span>
                        </TableCell>
                        <TableCell>{{ resource.type }}</TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ resource.location ?? '-' }}
                        </TableCell>
                        <TableCell>{{ resource.capacity ?? '-' }}</TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    resource.is_active
                                        ? 'default'
                                        : 'secondary'
                                "
                            >
                                {{
                                    resource.is_active ? 'Active' : 'Inactive'
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Edit ${resource.name}`"
                                    @click="openEdit(resource)"
                                >
                                    <Pencil aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Archive ${resource.name}`"
                                    @click="destroy(resource)"
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
                        {{ editing ? `Edit ${editing.name}` : 'New resource' }}
                    </DialogTitle>
                    <DialogDescription>
                        Rooms, vehicles, equipment: anything people book.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="resource-type">Type</Label>
                        <Select
                            :model-value="
                                form.resource_type_id === null
                                    ? undefined
                                    : String(form.resource_type_id)
                            "
                            @update:model-value="
                                (v) => (form.resource_type_id = Number(v))
                            "
                        >
                            <SelectTrigger id="resource-type">
                                <SelectValue placeholder="Pick a type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="type in resourceTypes"
                                    :key="type.id"
                                    :value="String(type.id)"
                                >
                                    {{ type.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="form.errors.resource_type_id"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.resource_type_id }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="resource-name">Name</Label>
                        <Input id="resource-name" v-model="form.name" />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="resource-description">Description</Label>
                        <Textarea
                            id="resource-description"
                            v-model="form.description"
                            rows="2"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="resource-location">Location</Label>
                            <Input
                                id="resource-location"
                                v-model="form.location"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="resource-capacity">Capacity</Label>
                            <Input
                                id="resource-capacity"
                                :model-value="form.capacity ?? ''"
                                type="number"
                                min="1"
                                placeholder="unlimited"
                                @update:model-value="
                                    (v) =>
                                        (form.capacity =
                                            v === '' ? null : Number(v))
                                "
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 items-end gap-4">
                        <div class="grid gap-2">
                            <Label for="resource-color">Calendar color</Label>
                            <div class="flex items-center gap-2">
                                <input
                                    id="resource-color"
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
                        {{ editing ? 'Save changes' : 'Create resource' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
