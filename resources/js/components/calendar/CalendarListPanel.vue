<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
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
import { store as calendarsStore } from '@/routes/calendars';
import { useCalendarListStore } from '@/stores/calendarList';
import type { CalendarItem } from '@/types/calendar';

const list = useCalendarListStore();

const groups: {
    key: 'organization' | 'division' | 'personal' | 'resource';
    label: string;
}[] = [
    { key: 'organization', label: 'FDCP' },
    { key: 'division', label: 'Division' },
    { key: 'personal', label: 'My calendars' },
    { key: 'resource', label: 'Rooms & vehicles' },
];

const swatches = [
    '#CC8500',
    '#FFCC00',
    '#580076',
    '#E200A9',
    '#0096E2',
    '#10B981',
    '#DC2626',
    '#7C3AED',
];

const creating = ref(false);

const form = useForm({
    name: '',
    color: '#0096E2',
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
                </div>

                <ul class="space-y-0.5">
                    <li
                        v-for="calendar in list.grouped[group.key]"
                        :key="calendar.id"
                    >
                        <Label
                            class="flex cursor-pointer items-center gap-2 rounded-md px-1.5 py-1 text-sm font-normal hover:bg-muted"
                        >
                            <Checkbox
                                :model-value="calendar.is_visible"
                                :style="{
                                    '--primary': calendar.effective_color,
                                }"
                                :aria-label="`Toggle ${calendar.name}`"
                                @update:model-value="() => toggle(calendar)"
                            />
                            <span class="truncate">{{ calendar.name }}</span>
                        </Label>
                    </li>
                </ul>
            </section>
        </template>

        <section
            v-if="list.grouped.personal.length === 0 && list.loaded"
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
                                :aria-label="`Color ${swatch}`"
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
    </div>
</template>
