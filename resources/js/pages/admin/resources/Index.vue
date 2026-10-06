<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Archive, Boxes, Pencil, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyNote from '@/components/admin/EmptyNote.vue';
import DeskSection from '@/components/desk/DeskSection.vue';
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

const props = defineProps<{
    resources: ResourceRow[];
    resourceTypes: { id: number; name: string }[];
}>();

/** One section per type that has resources, rows in the server's order. */
const groups = computed(() =>
    props.resourceTypes
        .map((type) => ({
            ...type,
            items: props.resources.filter(
                (resource) => resource.resource_type_id === type.id,
            ),
        }))
        .filter((group) => group.items.length > 0),
);

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

const { confirm } = useConfirm();

const archive = async (resource: ResourceRow) => {
    const confirmed = await confirm({
        title: `Archive ${resource.name}?`,
        description:
            'It leaves this list, can no longer be booked, and its calendar is archived.',
        confirmLabel: 'Archive resource',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    router.delete(adminResources.destroy(resource.id).url, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Resources" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Resources"
            description="Every resource gets its own calendar; bookings show up there."
        >
            <template #actions>
                <Button class="rounded-full" size="sm" @click="openCreate">
                    <Plus aria-hidden="true" />
                    New resource
                </Button>
            </template>
        </PageHeader>

        <EmptyNote
            v-if="groups.length === 0"
            :icon="Boxes"
            title="No resources yet"
        >
            Add the rooms, vehicles and equipment people book.
            <template #action>
                <Button
                    variant="outline"
                    size="sm"
                    class="rounded-full"
                    @click="openCreate"
                >
                    New resource
                </Button>
            </template>
        </EmptyNote>

        <DeskSection
            v-for="group in groups"
            :key="group.id"
            :title="group.name"
            :count="group.items.length"
            :heading-id="`resource-type-${group.id}`"
        >
            <ul class="divide-y divide-border border-y border-border">
                <li
                    v-for="resource in group.items"
                    :key="resource.id"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 px-2 py-3 hover:bg-muted/40 motion-safe:transition-colors motion-safe:duration-150 md:grid md:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_6rem_5rem_auto]"
                    :class="{ 'text-muted-foreground': !resource.is_active }"
                >
                    <div
                        class="flex w-full min-w-0 items-center gap-2.5 md:w-auto"
                    >
                        <span
                            class="size-2.5 shrink-0 rounded-full"
                            :class="{ 'opacity-50': !resource.is_active }"
                            :style="{
                                backgroundColor:
                                    resource.color ?? 'var(--muted-foreground)',
                            }"
                            aria-hidden="true"
                        />
                        <span class="truncate text-sm font-medium">
                            {{ resource.name }}
                        </span>
                    </div>

                    <span
                        class="min-w-0 truncate text-[13px] text-muted-foreground"
                    >
                        {{ resource.location ?? '—' }}
                    </span>

                    <span
                        class="text-[13px] text-muted-foreground tabular-nums"
                    >
                        {{
                            resource.capacity == null
                                ? '—'
                                : `${resource.capacity} ${resource.capacity === 1 ? 'seat' : 'seats'}`
                        }}
                    </span>

                    <span>
                        <span
                            v-if="!resource.is_active"
                            class="rounded-full bg-muted px-2 py-px text-[11px] leading-4 font-medium text-muted-foreground"
                        >
                            Inactive
                        </span>
                    </span>

                    <div class="ml-auto flex items-center gap-1">
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="rounded-full text-muted-foreground"
                            :aria-label="`Edit ${resource.name}`"
                            @click="openEdit(resource)"
                        >
                            <Pencil aria-hidden="true" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="rounded-full text-muted-foreground hover:text-destructive"
                            :aria-label="`Archive ${resource.name}`"
                            @click="archive(resource)"
                        >
                            <Archive aria-hidden="true" />
                        </Button>
                    </div>
                </li>
            </ul>
        </DeskSection>

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
                        <Label class="flex items-center gap-3 pb-1 font-normal">
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
