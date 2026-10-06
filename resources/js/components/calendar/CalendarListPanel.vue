<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { useConfirm } from '@/composables/useConfirm';
import { colorName, DEFAULT_COLOR, EVENT_COLORS } from '@/lib/eventColors';
import { store as calendarsStore } from '@/routes/calendars';
import divisionUnits from '@/routes/division-units';
import { useCalendarListStore } from '@/stores/calendarList';
import type { CalendarItem, CalendarPanelGroup } from '@/types/calendar';

const list = useCalendarListStore();
const { confirm } = useConfirm();
const page = usePage();

const props = defineProps<{
    groups?: CalendarPanelGroup[];
}>();

// Resource calendars are absent from the default (merged) panel on purpose:
// each resource type has its own section page now.
const groups = computed<CalendarPanelGroup[]>(
    () =>
        props.groups ?? [
            { key: 'personal', label: 'My calendars' },
            { key: 'organization', label: page.props.organization.acronym },
            { key: 'division', label: 'Division' },
        ],
);

const hasPersonalGroup = computed(() =>
    groups.value.some((group) => group.key === 'personal'),
);

// Google's palette, so calendars and events share one set of colors.
const swatches = EVENT_COLORS.map((c) => c.hex);

const creating = ref(false);

const form = useForm({
    name: '',
    color: DEFAULT_COLOR,
});

const submit = () => {
    form.post(calendarsStore().url, {
        preserveScroll: true,
        onSuccess: () => {
            creating.value = false;
            form.reset();
            void list.load(true);
        },
    });
};

const toggle = (calendar: CalendarItem) => {
    void list.toggleVisibility(calendar);
};

// --- Unit calendars: member-managed sub-calendars of the division ---

const unitDialogOpen = ref(false);
const editingUnit = ref<CalendarItem | null>(null);
const unitForm = useForm({ name: '', color: DEFAULT_COLOR });

const openUnitCreate = () => {
    editingUnit.value = null;
    unitForm.reset();
    unitForm.clearErrors();
    unitDialogOpen.value = true;
};

const openUnitEdit = (calendar: CalendarItem) => {
    editingUnit.value = calendar;
    unitForm.name = calendar.name;
    unitForm.color = calendar.color;
    unitForm.clearErrors();
    unitDialogOpen.value = true;
};

const submitUnit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            unitDialogOpen.value = false;
            unitForm.reset();
            void list.load(true);
        },
    };

    // Units are divisions: edit/delete target the calendar's division.
    const unitDivisionId = editingUnit.value?.division?.id;

    if (unitDivisionId != null) {
        unitForm.patch(divisionUnits.update(unitDivisionId).url, options);
    } else {
        unitForm.post(divisionUnits.store().url, options);
    }
};

const removeUnit = async () => {
    const unit = editingUnit.value;
    const unitDivisionId = unit?.division?.id;

    if (!unit || unitDivisionId == null) {
        return;
    }

    const confirmed = await confirm({
        title: `Delete “${unit.name}”?`,
        description:
            'The unit, its calendar and its events are removed. Units with members cannot be deleted.',
        confirmLabel: 'Delete unit',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    unitForm.delete(divisionUnits.destroy(unitDivisionId).url, {
        preserveScroll: true,
        onSuccess: () => {
            unitDialogOpen.value = false;
            void list.load(true);
        },
    });
};
</script>

<template>
    <div class="space-y-4">
        <template v-for="group in groups" :key="group.key">
            <section v-if="list.grouped[group.key].length > 0">
                <div class="flex items-center justify-between px-1 pb-1">
                    <h3
                        class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ group.label }}
                    </h3>
                    <Button
                        v-if="group.key === 'personal'"
                        variant="ghost"
                        size="icon"
                        class="size-5"
                        aria-label="New personal calendar"
                        @click="creating = true"
                    >
                        <Plus class="size-3.5" aria-hidden="true" />
                    </Button>
                    <Button
                        v-if="group.manageUnits"
                        variant="ghost"
                        size="icon"
                        class="size-5"
                        aria-label="New unit calendar"
                        @click="openUnitCreate"
                    >
                        <Plus class="size-3.5" aria-hidden="true" />
                    </Button>
                </div>

                <ul class="space-y-0.5">
                    <li
                        v-for="calendar in list.grouped[group.key]"
                        :key="calendar.id"
                        class="group/cal relative flex items-center gap-0.5"
                    >
                        <Label
                            class="flex min-w-0 flex-1 cursor-pointer items-center gap-2 rounded-md px-1.5 py-1 text-sm font-normal hover:bg-muted"
                        >
                            <Checkbox
                                :model-value="calendar.is_visible"
                                class="border-(--primary)"
                                :style="{
                                    '--primary': calendar.effective_color,
                                }"
                                :aria-label="`Toggle ${calendar.name}`"
                                @update:model-value="() => toggle(calendar)"
                            />
                            <span class="truncate" :title="calendar.name">
                                {{ calendar.name }}
                            </span>
                        </Label>
                        <Button
                            v-if="group.manageUnits && calendar.unit_manageable"
                            variant="ghost"
                            size="icon"
                            class="size-5 shrink-0 opacity-0 group-hover/cal:opacity-100 focus-visible:opacity-100"
                            :aria-label="`Edit unit ${calendar.name}`"
                            @click="openUnitEdit(calendar)"
                        >
                            <Pencil class="size-3" aria-hidden="true" />
                        </Button>
                    </li>
                </ul>
            </section>
        </template>

        <section
            v-if="
                hasPersonalGroup &&
                list.grouped.personal.length === 0 &&
                list.loaded
            "
            class="px-1"
        >
            <Button
                variant="outline"
                size="sm"
                class="w-full"
                @click="creating = true"
            >
                <Plus aria-hidden="true" />
                New calendar
            </Button>
        </section>

        <Dialog v-model:open="creating">
            <DialogContent class="max-w-sm">
                <DialogHeader>
                    <DialogTitle>New personal calendar</DialogTitle>
                    <DialogDescription>
                        Only you can see it. Share it with teammates later.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="new-calendar-name">Name</Label>
                        <Input
                            id="new-calendar-name"
                            v-model="form.name"
                            placeholder="e.g. Projects"
                        />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label>Color</Label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="swatch in swatches"
                                :key="swatch"
                                type="button"
                                class="size-6 rounded-full transition-transform hover:scale-110"
                                :class="{
                                    'ring-2 ring-foreground ring-offset-2 ring-offset-background':
                                        form.color === swatch,
                                }"
                                :style="{ backgroundColor: swatch }"
                                :aria-label="colorName(swatch)"
                                :title="colorName(swatch)"
                                :aria-pressed="form.color === swatch"
                                @click="form.color = swatch"
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

                <DialogFooter>
                    <Button variant="outline" @click="creating = false">
                        Cancel
                    </Button>
                    <Button :disabled="form.processing" @click="submit">
                        Create calendar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Unit calendar create / edit -->
        <Dialog v-model:open="unitDialogOpen">
            <DialogContent class="max-w-sm">
                <DialogHeader>
                    <DialogTitle>
                        {{ editingUnit ? 'Edit unit' : 'New unit' }}
                    </DialogTitle>
                    <DialogDescription>
                        A unit calendar the whole division can see and use.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="unit-name">Name</Label>
                        <Input
                            id="unit-name"
                            v-model="unitForm.name"
                            placeholder="e.g. Cinema Evaluation Unit"
                        />
                        <p
                            v-if="unitForm.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ unitForm.errors.name }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label>Color</Label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="swatch in swatches"
                                :key="swatch"
                                type="button"
                                class="size-6 rounded-full transition-transform hover:scale-110"
                                :class="{
                                    'ring-2 ring-foreground ring-offset-2 ring-offset-background':
                                        unitForm.color === swatch,
                                }"
                                :style="{ backgroundColor: swatch }"
                                :aria-label="colorName(swatch)"
                                :title="colorName(swatch)"
                                :aria-pressed="unitForm.color === swatch"
                                @click="unitForm.color = swatch"
                            />
                        </div>
                        <p
                            v-if="unitForm.errors.color"
                            class="text-sm text-destructive"
                        >
                            {{ unitForm.errors.color }}
                        </p>
                    </div>
                </div>

                <DialogFooter class="sm:justify-between">
                    <Button
                        v-if="editingUnit"
                        variant="outline"
                        class="text-destructive"
                        :disabled="unitForm.processing"
                        @click="removeUnit"
                    >
                        Delete
                    </Button>
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            @click="unitDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            :disabled="unitForm.processing"
                            @click="submitUnit"
                        >
                            {{ editingUnit ? 'Save' : 'Create unit' }}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
