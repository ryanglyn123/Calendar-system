<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
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
import admin from '@/routes/admin';
import adminResourceTypes from '@/routes/admin/resource-types';
import type { Role } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Resource Types', href: adminResourceTypes.index() },
        ],
    },
});

type ResourceTypeRow = {
    id: number;
    name: string;
    description: string | null;
    approver_role_id: number;
    approver_role: string | null;
    requires_approval: boolean;
    min_lead_time_minutes: number;
    max_duration_minutes: number | null;
    max_advance_days: number | null;
    max_active_bookings_per_user: number | null;
    enforce_working_hours: boolean;
    block_holidays: boolean;
    is_active: boolean;
    resources_count: number;
};

defineProps<{
    resourceTypes: ResourceTypeRow[];
    roles: { data: Role[] };
}>();

const editing = ref<ResourceTypeRow | null>(null);
const creating = ref(false);

const form = useForm({
    name: '',
    description: '',
    approver_role_id: null as number | null,
    requires_approval: true,
    min_lead_time_minutes: 0,
    max_duration_minutes: null as number | null,
    max_advance_days: null as number | null,
    max_active_bookings_per_user: null as number | null,
    enforce_working_hours: false,
    block_holidays: false,
    is_active: true,
});

const openCreate = () => {
    form.reset();
    form.clearErrors();
    creating.value = true;
};

const openEdit = (type: ResourceTypeRow) => {
    form.clearErrors();
    form.name = type.name;
    form.description = type.description ?? '';
    form.approver_role_id = type.approver_role_id;
    form.requires_approval = type.requires_approval;
    form.min_lead_time_minutes = type.min_lead_time_minutes;
    form.max_duration_minutes = type.max_duration_minutes;
    form.max_advance_days = type.max_advance_days;
    form.max_active_bookings_per_user = type.max_active_bookings_per_user;
    form.enforce_working_hours = type.enforce_working_hours;
    form.block_holidays = type.block_holidays;
    form.is_active = type.is_active;
    editing.value = type;
};

const close = () => {
    creating.value = false;
    editing.value = null;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: close };

    if (editing.value) {
        form.patch(adminResourceTypes.update(editing.value.id).url, options);
    } else {
        form.post(adminResourceTypes.store().url, options);
    }
};

const destroy = (type: ResourceTypeRow) =>
    router.delete(adminResourceTypes.destroy(type.id).url, {
        preserveScroll: true,
    });

const numberOrNull = (value: string | number): number | null =>
    value === '' ? null : Number(value);
</script>

<template>
    <Head title="Resource Types" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <p class="text-sm text-muted-foreground">
                Each type sets who approves its bookings and which rules
                apply.
            </p>
            <Button @click="openCreate">
                <Plus aria-hidden="true" />
                New type
            </Button>
        </div>

        <div class="rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Type</TableHead>
                        <TableHead>Approver role</TableHead>
                        <TableHead>Rules</TableHead>
                        <TableHead>Resources</TableHead>
                        <TableHead class="w-24 text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="resourceTypes.length === 0" :colspan="5">
                        No resource types yet.
                    </TableEmpty>
                    <TableRow v-for="type in resourceTypes" :key="type.id">
                        <TableCell>
                            <div class="font-medium">{{ type.name }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ type.description ?? '' }}
                            </div>
                        </TableCell>
                        <TableCell>
                            <Badge variant="outline">
                                {{ type.approver_role ?? '-' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="max-w-64">
                            <div
                                class="flex flex-wrap gap-1 text-xs text-muted-foreground"
                            >
                                <Badge
                                    v-if="!type.requires_approval"
                                    variant="secondary"
                                >
                                    auto-approve
                                </Badge>
                                <Badge
                                    v-if="type.min_lead_time_minutes > 0"
                                    variant="secondary"
                                >
                                    lead {{ type.min_lead_time_minutes }}m
                                </Badge>
                                <Badge
                                    v-if="type.max_duration_minutes"
                                    variant="secondary"
                                >
                                    max {{ type.max_duration_minutes }}m
                                </Badge>
                                <Badge
                                    v-if="type.max_advance_days"
                                    variant="secondary"
                                >
                                    {{ type.max_advance_days }}d ahead
                                </Badge>
                                <Badge
                                    v-if="type.enforce_working_hours"
                                    variant="secondary"
                                >
                                    office hours
                                </Badge>
                                <Badge
                                    v-if="type.block_holidays"
                                    variant="secondary"
                                >
                                    no holidays
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell>{{ type.resources_count }}</TableCell>
                        <TableCell class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Edit ${type.name}`"
                                    @click="openEdit(type)"
                                >
                                    <Pencil aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive hover:text-destructive"
                                    :disabled="type.resources_count > 0"
                                    :aria-label="`Delete ${type.name}`"
                                    @click="destroy(type)"
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
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            editing
                                ? `Edit ${editing.name}`
                                : 'New resource type'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        Booking rules set here are enforced on every request.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="type-name">Name</Label>
                        <Input
                            id="type-name"
                            v-model="form.name"
                            placeholder="e.g. Equipment"
                        />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="type-description">Description</Label>
                        <Input
                            id="type-description"
                            v-model="form.description"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="type-approver">Approver role</Label>
                        <Select
                            :model-value="
                                form.approver_role_id === null
                                    ? undefined
                                    : String(form.approver_role_id)
                            "
                            @update:model-value="
                                (v) => (form.approver_role_id = Number(v))
                            "
                        >
                            <SelectTrigger id="type-approver">
                                <SelectValue placeholder="Pick a role" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="role in roles.data"
                                    :key="role.id"
                                    :value="String(role.id)"
                                >
                                    {{ role.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="form.errors.approver_role_id"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.approver_role_id }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="type-lead">Lead time (min)</Label>
                            <Input
                                id="type-lead"
                                v-model.number="form.min_lead_time_minutes"
                                type="number"
                                min="0"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="type-duration">
                                Max duration (min)
                            </Label>
                            <Input
                                id="type-duration"
                                :model-value="form.max_duration_minutes ?? ''"
                                type="number"
                                min="15"
                                placeholder="unlimited"
                                @update:model-value="
                                    (v) =>
                                        (form.max_duration_minutes =
                                            numberOrNull(v))
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="type-advance">
                                Book ahead (days)
                            </Label>
                            <Input
                                id="type-advance"
                                :model-value="form.max_advance_days ?? ''"
                                type="number"
                                min="1"
                                placeholder="unlimited"
                                @update:model-value="
                                    (v) =>
                                        (form.max_advance_days =
                                            numberOrNull(v))
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="type-cap">
                                Active per user
                            </Label>
                            <Input
                                id="type-cap"
                                :model-value="
                                    form.max_active_bookings_per_user ?? ''
                                "
                                type="number"
                                min="1"
                                placeholder="unlimited"
                                @update:model-value="
                                    (v) =>
                                        (form.max_active_bookings_per_user =
                                            numberOrNull(v))
                                "
                            />
                        </div>
                    </div>

                    <div class="grid gap-3">
                        <Label class="flex items-center gap-3 font-normal">
                            <Switch v-model="form.requires_approval" />
                            Bookings need approval
                        </Label>
                        <Label class="flex items-center gap-3 font-normal">
                            <Switch v-model="form.enforce_working_hours" />
                            Limit to working hours
                        </Label>
                        <Label class="flex items-center gap-3 font-normal">
                            <Switch v-model="form.block_holidays" />
                            Block holidays
                        </Label>
                        <Label class="flex items-center gap-3 font-normal">
                            <Switch v-model="form.is_active" />
                            Active
                        </Label>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="close">Cancel</Button>
                    <Button :disabled="form.processing" @click="submit">
                        {{ editing ? 'Save changes' : 'Create type' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
