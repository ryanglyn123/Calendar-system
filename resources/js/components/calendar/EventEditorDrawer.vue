<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AttendeePicker from '@/components/calendar/AttendeePicker.vue';
import RecurrenceEditor from '@/components/calendar/RecurrenceEditor.vue';
import RecurrenceScopeDialog from '@/components/calendar/RecurrenceScopeDialog.vue';
import type { RecurrenceScope } from '@/components/calendar/RecurrenceScopeDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useAuth } from '@/composables/useAuth';
import { api, ApiError } from '@/lib/api';
import { format, parseISO } from '@/lib/calendarMath';
import eventTypesApi from '@/routes/api/event-types';
import {
    store as eventsStore,
    update as eventsUpdate,
} from '@/routes/api/events';
import occurrenceRoutes from '@/routes/api/events/occurrence';
import { originalStartOf, useCalendarGridStore } from '@/stores/calendarGrid';
import { useCalendarListStore } from '@/stores/calendarList';
import type { EventOccurrence, EventTypeItem } from '@/types/calendar';

const props = defineProps<{
    open: boolean;
    /** Editing target; null means creating. */
    occurrence: EventOccurrence | null;
    /** Prefilled range for creation. */
    initialStart: Date | null;
    initialEnd: Date | null;
    eventTypes: EventTypeItem[];
}>();

const emit = defineEmits<{
    close: [];
    saved: [];
}>();

const grid = useCalendarGridStore();
const list = useCalendarListStore();
const { hasPermission } = useAuth();

// Types created inline live here until the page reloads and props catch up.
const canManageTypes = hasPermission('event-types.manage');
const localTypes = ref<EventTypeItem[]>([]);
const allTypes = computed(() => [...props.eventTypes, ...localTypes.value]);

const addingType = ref(false);
const creatingType = ref(false);

// '__new__' is the bottom dropdown row; picking it opens the inline creator
// instead of setting a type.
const onTypeSelect = (v: string) => {
    if (v === '__new__') {
        addingType.value = true;
        return;
    }
    form.event_type_id = v === 'none' ? null : Number(v);
};
const newType = reactive({ name: '', color: '#86C6F5' });
const newTypeError = ref('');

const createType = async () => {
    if (!newType.name.trim() || creatingType.value) {
        return;
    }
    creatingType.value = true;
    newTypeError.value = '';
    try {
        const { data } = await api.post<{ data: EventTypeItem }>(
            eventTypesApi.store().url,
            { name: newType.name.trim(), color: newType.color, is_active: true },
        );
        localTypes.value.push(data);
        form.event_type_id = data.id;
        addingType.value = false;
        newType.name = '';
    } catch (error) {
        newTypeError.value =
            error instanceof ApiError
                ? (error.errors.name?.[0] ?? error.message)
                : 'Could not create type.';
    } finally {
        creatingType.value = false;
    }
};

const swatches = [
    '#EFD95F',
    '#F5CD7E',
    '#BFA8F5',
    '#F5A6D8',
    '#86C6F5',
    '#86DBAE',
    '#F7A79B',
    '#B29AF5',
];

type FormState = {
    calendar_id: number | null;
    event_type_id: number | null;
    title: string;
    description: string;
    location: string;
    meeting_url: string;
    startDate: string;
    startTime: string;
    endDate: string;
    endTime: string;
    all_day: boolean;
    color: string | null;
    visibility: 'default' | 'private';
    rrule: string | null;
    attendees: { id: number; name: string }[];
    reminders: number[];
};

const form = reactive<FormState>({
    calendar_id: null,
    event_type_id: null,
    title: '',
    description: '',
    location: '',
    meeting_url: '',
    startDate: '',
    startTime: '09:00',
    endDate: '',
    endTime: '10:00',
    all_day: false,
    color: null,
    visibility: 'default',
    rrule: null,
    attendees: [],
    reminders: [],
});

const REMINDER_PRESETS = [
    { minutes: 10, label: '10 min before' },
    { minutes: 30, label: '30 min before' },
    { minutes: 60, label: '1 hour before' },
    { minutes: 1440, label: '1 day before' },
];

const toggleReminder = (minutes: number) => {
    form.reminders = form.reminders.includes(minutes)
        ? form.reminders.filter((m) => m !== minutes)
        : [...form.reminders, minutes];
};

const scopeDialogOpen = ref(false);

const startDateAsDate = computed(() =>
    form.startDate ? parseISO(`${form.startDate}T00:00:00`) : new Date(),
);

const errors = ref<Record<string, string[]>>({});
const saving = ref(false);

const isEditing = computed(() => props.occurrence !== null);

watch(
    () => [props.open, props.occurrence, props.initialStart] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        errors.value = {};

        if (props.occurrence) {
            const start = new Date(props.occurrence.starts_at);
            const end = new Date(props.occurrence.ends_at);
            const inclusiveEnd = props.occurrence.all_day
                ? new Date(end.getTime() - 1)
                : end;

            form.calendar_id = props.occurrence.calendar_id;
            form.event_type_id = props.occurrence.event_type?.id ?? null;
            form.title = props.occurrence.title;
            form.description = props.occurrence.description ?? '';
            form.location = props.occurrence.location ?? '';
            form.meeting_url = props.occurrence.meeting_url ?? '';
            form.startDate = format(start, 'yyyy-MM-dd');
            form.startTime = format(start, 'HH:mm');
            form.endDate = format(inclusiveEnd, 'yyyy-MM-dd');
            form.endTime = format(end, 'HH:mm');
            form.all_day = props.occurrence.all_day;
            form.color = null;
            form.visibility = props.occurrence.is_private
                ? 'private'
                : 'default';
            form.rrule = props.occurrence.rrule;
            form.attendees = props.occurrence.attendees
                .filter((a) => a.user_id !== null)
                .map((a) => ({ id: a.user_id!, name: a.name }));
            form.reminders = [...props.occurrence.my_reminder_minutes];
        } else {
            const start = props.initialStart ?? new Date();
            const end =
                props.initialEnd ?? new Date(start.getTime() + 60 * 60 * 1000);

            form.calendar_id =
                list.writableCalendars.find((c) => c.type === 'personal')?.id ??
                list.writableCalendars[0]?.id ??
                null;
            form.event_type_id = null;
            form.title = '';
            form.description = '';
            form.location = '';
            form.meeting_url = '';
            form.startDate = format(start, 'yyyy-MM-dd');
            form.startTime = format(start, 'HH:mm');
            form.endDate = format(end, 'yyyy-MM-dd');
            form.endTime = format(end, 'HH:mm');
            form.all_day = false;
            form.color = null;
            form.visibility = 'default';
            form.rrule = null;
            form.attendees = [];
            form.reminders = [];
        }
    },
);

// Keep the end on/after the start as the user edits.
watch(
    () => [form.startDate, form.startTime] as const,
    () => {
        if (!form.startDate || !form.endDate) {
            return;
        }

        const start = new Date(
            `${form.startDate}T${form.startTime || '00:00'}`,
        );
        const end = new Date(`${form.endDate}T${form.endTime || '00:00'}`);

        if (end <= start) {
            const bumped = new Date(start.getTime() + 60 * 60 * 1000);
            form.endDate = format(bumped, 'yyyy-MM-dd');
            form.endTime = format(bumped, 'HH:mm');
        }
    },
);

const fieldError = (key: string): string | undefined => errors.value[key]?.[0];

const payload = () => ({
    calendar_id: form.calendar_id,
    event_type_id: form.event_type_id,
    title: form.title,
    description: form.description || null,
    location: form.location || null,
    meeting_url: form.meeting_url || null,
    starts_at: form.all_day
        ? `${form.startDate}T00:00:00`
        : new Date(`${form.startDate}T${form.startTime}`).toISOString(),
    ends_at: form.all_day
        ? `${form.endDate}T00:00:00`
        : new Date(`${form.endDate}T${form.endTime}`).toISOString(),
    all_day: form.all_day,
    color: form.color,
    visibility: form.visibility,
    rrule: form.rrule,
    attendees: form.attendees.map((a) => a.id),
    reminders: form.reminders,
});

const save = async () => {
    // Editing an existing series needs an explicit scope first.
    if (props.occurrence?.is_recurring) {
        scopeDialogOpen.value = true;

        return;
    }

    await submit(() =>
        props.occurrence
            ? api.patch(eventsUpdate(props.occurrence.event_id).url, payload())
            : api.post(eventsStore().url, payload()),
    );
};

const saveWithScope = async (scope: RecurrenceScope) => {
    scopeDialogOpen.value = false;

    const occurrence = props.occurrence!;
    const body = payload();

    await submit(async () => {
        // Rule changes only make sense series-wide.
        if (scope === 'all' && body.rrule !== occurrence.rrule) {
            await api.patch(eventsUpdate(occurrence.event_id).url, {
                rrule: body.rrule,
            });
        }

        await api.patch(occurrenceRoutes.update(occurrence.event_id).url, {
            scope,
            original_starts_at: originalStartOf(occurrence),
            title: body.title,
            description: body.description,
            location: body.location,
            meeting_url: body.meeting_url,
            starts_at: body.starts_at,
            ends_at: body.ends_at,
            color: body.color,
            event_type_id: body.event_type_id,
        });
    });
};

const submit = async (action: () => Promise<unknown>) => {
    saving.value = true;
    errors.value = {};

    try {
        await action();

        await grid.invalidateAround(new Date(`${form.startDate}T00:00:00`));

        toast.success(isEditing.value ? 'Event updated.' : 'Event created.');
        emit('saved');
        emit('close');
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = error.errors;

            if (Object.keys(error.errors).length === 0) {
                toast.error(error.message);
            }
        } else {
            toast.error('Something went wrong while saving.');
        }
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <Sheet :open="open" @update:open="(value) => !value && emit('close')">
        <SheetContent
            side="right"
            class="flex w-full flex-col gap-0 overflow-y-auto sm:max-w-md"
        >
            <SheetHeader>
                <SheetTitle>
                    {{ isEditing ? 'Edit event' : 'New event' }}
                </SheetTitle>
                <SheetDescription class="sr-only">
                    Event editor
                </SheetDescription>
            </SheetHeader>

            <div class="flex flex-1 flex-col gap-4 px-4 pb-4">
                <div class="grid gap-2">
                    <Label for="event-title">Title</Label>
                    <Input
                        id="event-title"
                        v-model="form.title"
                        placeholder="Add a title"
                        autofocus
                    />
                    <p
                        v-if="fieldError('title')"
                        class="text-sm text-destructive"
                    >
                        {{ fieldError('title') }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="event-calendar">Calendar</Label>
                    <Select
                        :model-value="
                            form.calendar_id === null
                                ? undefined
                                : String(form.calendar_id)
                        "
                        @update:model-value="
                            (v) => (form.calendar_id = Number(v))
                        "
                    >
                        <SelectTrigger id="event-calendar">
                            <SelectValue placeholder="Pick a calendar" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="calendar in list.writableCalendars"
                                :key="calendar.id"
                                :value="String(calendar.id)"
                            >
                                <span class="flex items-center gap-2">
                                    <span
                                        class="size-2.5 rounded-full"
                                        :style="{
                                            backgroundColor:
                                                calendar.effective_color,
                                        }"
                                    />
                                    {{ calendar.name }}
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="fieldError('calendar_id')"
                        class="text-sm text-destructive"
                    >
                        {{ fieldError('calendar_id') }}
                    </p>
                </div>

                <Label class="flex items-center gap-3 font-normal">
                    <Switch v-model="form.all_day" />
                    All day
                </Label>

                <div class="grid grid-cols-2 items-start gap-3">
                    <div class="grid gap-2">
                        <Label for="event-start-date">Starts</Label>
                        <Input
                            id="event-start-date"
                            v-model="form.startDate"
                            type="date"
                        />
                        <Input
                            v-if="!form.all_day"
                            v-model="form.startTime"
                            type="time"
                            aria-label="Start time"
                        />
                        <p
                            v-if="fieldError('starts_at')"
                            class="text-sm text-destructive"
                        >
                            {{ fieldError('starts_at') }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="event-end-date">Ends</Label>
                        <Input
                            id="event-end-date"
                            v-model="form.endDate"
                            type="date"
                        />
                        <Input
                            v-if="!form.all_day"
                            v-model="form.endTime"
                            type="time"
                            aria-label="End time"
                        />
                        <p
                            v-if="fieldError('ends_at')"
                            class="text-sm text-destructive"
                        >
                            {{ fieldError('ends_at') }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="event-type">Event type</Label>
                    <Select
                        :model-value="
                            form.event_type_id === null
                                ? 'none'
                                : String(form.event_type_id)
                        "
                        @update:model-value="(v) => onTypeSelect(v as string)"
                    >
                        <SelectTrigger id="event-type">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">None</SelectItem>
                            <SelectItem
                                v-for="type in allTypes"
                                :key="type.id"
                                :value="String(type.id)"
                            >
                                <span class="flex items-center gap-2">
                                    <span
                                        class="size-2.5 rounded-full"
                                        :style="{
                                            backgroundColor: type.color,
                                        }"
                                    />
                                    {{ type.name }}
                                </span>
                            </SelectItem>
                            <template v-if="canManageTypes">
                                <SelectSeparator />
                                <SelectItem value="__new__">
                                    <span class="flex items-center gap-2 text-primary">
                                        <Plus class="size-3.5" />
                                        New type
                                    </span>
                                </SelectItem>
                            </template>
                        </SelectContent>
                    </Select>

                    <div
                        v-if="canManageTypes && addingType"
                        class="flex items-start gap-2"
                    >
                        <input
                            v-model="newType.color"
                            type="color"
                            aria-label="Type color"
                            class="size-9 shrink-0 cursor-pointer rounded-md border border-input bg-transparent p-1"
                        />
                        <div class="grid flex-1 gap-1">
                            <Input
                                v-model="newType.name"
                                placeholder="Type name"
                                @keydown.enter.prevent="createType"
                            />
                            <p
                                v-if="newTypeError"
                                class="text-sm text-destructive"
                            >
                                {{ newTypeError }}
                            </p>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            :disabled="creatingType || !newType.name.trim()"
                            @click="createType"
                        >
                            Add
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="addingType = false"
                        >
                            Cancel
                        </Button>
                    </div>
                </div>

                <RecurrenceEditor
                    v-model="form.rrule"
                    :start-date="startDateAsDate"
                />

                <AttendeePicker v-model="form.attendees" />

                <div class="grid gap-2">
                    <Label>Reminders</Label>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="preset in REMINDER_PRESETS"
                            :key="preset.minutes"
                            type="button"
                            class="rounded-full border px-2.5 py-1 text-xs transition-colors"
                            :class="
                                form.reminders.includes(preset.minutes)
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'hover:bg-muted'
                            "
                            :aria-pressed="
                                form.reminders.includes(preset.minutes)
                            "
                            @click="toggleReminder(preset.minutes)"
                        >
                            {{ preset.label }}
                        </button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="event-location">Location</Label>
                    <Input
                        id="event-location"
                        v-model="form.location"
                        placeholder="Add a location"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="event-url">Meeting link</Label>
                    <Input
                        id="event-url"
                        v-model="form.meeting_url"
                        type="url"
                        placeholder="https://…"
                    />
                    <p
                        v-if="fieldError('meeting_url')"
                        class="text-sm text-destructive"
                    >
                        {{ fieldError('meeting_url') }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="event-description">Description</Label>
                    <Textarea
                        id="event-description"
                        v-model="form.description"
                        rows="3"
                        placeholder="Add details"
                    />
                </div>

                <div class="grid gap-2">
                    <Label>Color</Label>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="rounded-full border px-2.5 py-1 text-xs"
                            :class="{
                                'ring-2 ring-foreground ring-offset-1 ring-offset-background':
                                    form.color === null,
                            }"
                            @click="form.color = null"
                        >
                            Auto
                        </button>
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
                </div>

                <Label class="flex items-center gap-3 font-normal">
                    <Switch
                        :model-value="form.visibility === 'private'"
                        @update:model-value="
                            (v) => (form.visibility = v ? 'private' : 'default')
                        "
                    />
                    Private (others see “Busy”)
                </Label>
            </div>

            <SheetFooter class="border-t">
                <Button
                    variant="outline"
                    :disabled="saving"
                    @click="emit('close')"
                >
                    Cancel
                </Button>
                <Button :disabled="saving || !form.title" @click="save">
                    <Spinner v-if="saving" />
                    {{ isEditing ? 'Save changes' : 'Create event' }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>

    <RecurrenceScopeDialog
        :open="scopeDialogOpen"
        action="edit"
        @choose="saveWithScope"
        @cancel="scopeDialogOpen = false"
    />
</template>
