<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Building2, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyNote from '@/components/admin/EmptyNote.vue';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useConfirm } from '@/composables/useConfirm';
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

type Row = { division: Division; unit: boolean; last: boolean; units: number };

/**
 * Root divisions in the server's order, each followed by its units. A unit
 * whose parent is missing from the list is shown as a root so it never hides.
 */
const rows = computed<Row[]>(() => {
    const all = props.divisions.data;
    const ids = new Set(all.map((d) => d.id));

    return all
        .filter((d) => d.parent_id == null || !ids.has(d.parent_id))
        .flatMap((root) => {
            const units = all.filter((d) => d.parent_id === root.id);

            return [
                {
                    division: root,
                    unit: false,
                    last: false,
                    units: units.length,
                },
                ...units.map((division, i) => ({
                    division,
                    unit: true,
                    last: i === units.length - 1,
                    units: 0,
                })),
            ];
        });
});

const membersLabel = (count = 0) =>
    count === 1 ? '1 member' : `${count} members`;

/** Why a division can't be deleted (the server refuses the same cases). */
const deleteBlock = (row: Row) => {
    const members = row.division.users_count ?? 0;

    if (members > 0) {
        return `Move its ${membersLabel(members)} to another division first`;
    }

    return row.units > 0 ? 'Remove its units first' : null;
};

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

const { confirm } = useConfirm();

const destroy = async (division: Division) => {
    const confirmed = await confirm({
        title: `Delete ${division.name}?`,
        description: 'Only divisions without members or units can be deleted.',
        confirmLabel: 'Delete division',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    router.delete(adminDivisions.destroy(division.id).url, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Divisions" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Divisions"
            description="Divisions scope users, calendars and resources. Units sit under their division."
        >
            <template #actions>
                <Button class="rounded-full" size="sm" @click="openCreate">
                    <Plus aria-hidden="true" />
                    New division
                </Button>
            </template>
        </PageHeader>

        <EmptyNote
            v-if="rows.length === 0"
            :icon="Building2"
            title="No divisions yet"
        >
            Create a division to group people, calendars and resources.
            <template #action>
                <Button
                    variant="outline"
                    size="sm"
                    class="rounded-full"
                    @click="openCreate"
                >
                    New division
                </Button>
            </template>
        </EmptyNote>

        <ul v-else class="border-y border-border">
            <li
                v-for="row in rows"
                :key="row.division.id"
                class="relative"
                :class="
                    row.unit
                        ? 'pl-6'
                        : 'border-t border-border first:border-t-0'
                "
            >
                <!-- Hairline guide: from the division's dot down to each unit. -->
                <span
                    v-if="!row.unit && row.units > 0"
                    class="absolute top-[30px] bottom-0 left-3.5 border-l border-border"
                    aria-hidden="true"
                />
                <template v-if="row.unit">
                    <span
                        class="absolute top-0 left-3.5 h-[22px] w-3.5 rounded-bl-md border-b border-l border-border"
                        aria-hidden="true"
                    />
                    <span
                        v-if="!row.last"
                        class="absolute top-0 bottom-0 left-3.5 border-l border-border"
                        aria-hidden="true"
                    />
                </template>

                <div
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 px-2 py-3 hover:bg-muted/40 motion-safe:transition-colors motion-safe:duration-150 md:grid md:grid-cols-[minmax(0,1fr)_8rem_5rem_auto]"
                    :class="{ 'border-t border-border': row.unit }"
                >
                    <div
                        class="flex w-full min-w-0 items-center gap-2.5 md:w-auto"
                    >
                        <span
                            class="size-3 shrink-0 rounded-full"
                            :class="{ 'opacity-50': !row.division.is_active }"
                            :style="{ backgroundColor: row.division.color }"
                            aria-hidden="true"
                        />
                        <p
                            class="min-w-0 truncate text-sm"
                            :class="{
                                'text-muted-foreground':
                                    !row.division.is_active,
                            }"
                        >
                            <span class="font-medium">
                                {{ row.division.name }}
                            </span>
                            <span
                                v-if="row.division.acronym"
                                class="ml-1.5 text-[13px] text-muted-foreground"
                            >
                                {{ row.division.acronym }}
                            </span>
                        </p>
                    </div>

                    <span
                        class="text-[13px] text-muted-foreground tabular-nums"
                    >
                        {{ membersLabel(row.division.users_count) }}
                    </span>

                    <span>
                        <span
                            v-if="!row.division.is_active"
                            class="rounded-full bg-muted px-2 py-px text-[11px] leading-4 font-medium text-muted-foreground"
                        >
                            Archived
                        </span>
                    </span>

                    <div class="ml-auto flex items-center gap-1">
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="rounded-full text-muted-foreground"
                            :aria-label="`Edit ${row.division.name}`"
                            @click="openEdit(row.division)"
                        >
                            <Pencil aria-hidden="true" />
                        </Button>
                        <!-- Disabled buttons swallow hover, so the reason sits on a wrapper. -->
                        <span :title="deleteBlock(row) ?? undefined">
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="rounded-full text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :disabled="deleteBlock(row) !== null"
                                :aria-label="`Delete ${row.division.name}`"
                                @click="destroy(row.division)"
                            >
                                <Trash2 aria-hidden="true" />
                            </Button>
                        </span>
                    </div>
                </div>
            </li>
        </ul>

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
                            <option
                                class="bg-background text-foreground"
                                :value="null"
                            >
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
    </div>
</template>
