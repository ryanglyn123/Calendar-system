<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
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
import adminDivisions from '@/routes/admin/divisions';
import type { Division } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Divisions', href: adminDivisions.index() },
        ],
    },
});

const props = defineProps<{
    divisions: { data: Division[] };
}>();

const editing = ref<Division | null>(null);
const creating = ref(false);

const form = useForm({
    name: '',
    acronym: '',
    description: '',
    color: '#580076',
    parent_id: null as number | null,
    is_active: true,
});

// Only root divisions can be parents (units are one level deep).
const parentOptions = computed(() =>
    props.divisions.data.filter(
        (division) =>
            division.parent_id == null && division.id !== editing.value?.id,
    ),
);

const openCreate = () => {
    form.reset();
    form.clearErrors();
    creating.value = true;
};

const openEdit = (division: Division) => {
    form.clearErrors();
    form.name = division.name;
    form.acronym = division.acronym ?? '';
    form.description = division.description ?? '';
    form.color = division.color;
    form.parent_id = division.parent_id ?? null;
    form.is_active = division.is_active;
    editing.value = division;
};

const close = () => {
    creating.value = false;
    editing.value = null;
};

const submit = () => {
    if (editing.value) {
        form.patch(adminDivisions.update(editing.value.id).url, {
            preserveScroll: true,
            onSuccess: close,
        });
    } else {
        form.post(adminDivisions.store().url, {
            preserveScroll: true,
            onSuccess: close,
        });
    }
};

const deleting = ref<Division | null>(null);

const destroy = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(adminDivisions.destroy(deleting.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = null;
        },
    });
};
</script>

<template>
    <Head title="Divisions" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Divisions"
            description="Divisions scope users, calendars and resources."
        >
            <template #actions>
                <Button @click="openCreate">
                    <Plus aria-hidden="true" />
                    New division
                </Button>
            </template>
        </PageHeader>

        <div class="rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Division</TableHead>
                        <TableHead>Members</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="w-24 text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="divisions.data.length === 0" :colspan="4">
                        No divisions yet.
                    </TableEmpty>
                    <TableRow
                        v-for="division in divisions.data"
                        :key="division.id"
                    >
                        <TableCell>
                            <div class="flex items-center gap-2.5">
                                <span
                                    class="size-3 rounded-full"
                                    :style="{
                                        backgroundColor: division.color,
                                    }"
                                    aria-hidden="true"
                                />
                                <div>
                                    <div
                                        class="flex items-center gap-1.5 font-medium"
                                    >
                                        {{ division.name }}
                                        <Badge
                                            v-if="division.parent"
                                            variant="outline"
                                            class="font-normal"
                                        >
                                            Unit of {{ division.parent.name }}
                                        </Badge>
                                    </div>
                                    <div
                                        v-if="division.acronym"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ division.acronym }}
                                    </div>
                                </div>
                            </div>
                        </TableCell>
                        <TableCell>{{ division.users_count ?? 0 }}</TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    division.is_active ? 'success' : 'secondary'
                                "
                            >
                                {{ division.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Edit ${division.name}`"
                                    @click="openEdit(division)"
                                >
                                    <Pencil aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive hover:text-destructive"
                                    :disabled="(division.users_count ?? 0) > 0"
                                    :aria-label="`Delete ${division.name}`"
                                    @click="deleting = division"
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
                        {{ editing ? `Edit ${editing.name}` : 'New division' }}
                    </DialogTitle>
                    <DialogDescription>
                        Name, color and status drive how the division appears
                        across the calendar.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="division-name">Name</Label>
                        <Input id="division-name" v-model="form.name" />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="division-acronym">Acronym</Label>
                            <Input
                                id="division-acronym"
                                v-model="form.acronym"
                                placeholder="e.g. MIS"
                            />
                            <p
                                v-if="form.errors.acronym"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.acronym }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="division-color">Color</Label>
                            <div class="flex items-center gap-2">
                                <input
                                    id="division-color"
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
                    </div>

                    <div class="grid gap-2">
                        <Label for="division-parent">Parent division</Label>
                        <select
                            id="division-parent"
                            v-model="form.parent_id"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <option class="bg-background text-foreground" :value="null">
                                None — top-level division
                            </option>
                            <option
                                v-for="parent in parentOptions"
                                :key="parent.id"
                                :value="parent.id"
                                class="bg-background text-foreground"
                            >
                                {{ parent.name }}
                            </option>
                        </select>
                        <p
                            v-if="form.errors.parent_id"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.parent_id }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="division-description">Description</Label>
                        <Textarea
                            id="division-description"
                            v-model="form.description"
                            rows="3"
                        />
                        <p
                            v-if="form.errors.description"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.description }}
                        </p>
                    </div>

                    <Label class="flex items-center gap-3 font-normal">
                        <Switch v-model="form.is_active" />
                        Active
                    </Label>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="close">Cancel</Button>
                    <Button :disabled="form.processing" @click="submit">
                        {{ editing ? 'Save changes' : 'Create division' }}
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
                        Only divisions without members can be deleted.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="deleting = null">
                        Cancel
                    </Button>
                    <Button variant="destructive" @click="destroy">
                        Delete division
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
