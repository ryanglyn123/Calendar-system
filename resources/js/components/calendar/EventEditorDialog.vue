<script setup lang="ts">
import {
    AlignLeft,
    Bell,
    Clock,
    Lock,
    MapPin,
    Plus,
    Repeat,
    Users,
    Video,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AttendeePicker from '@/components/calendar/AttendeePicker.vue';
import ColorPicker from '@/components/calendar/ColorPicker.vue';
import RecurrenceEditor from '@/components/calendar/RecurrenceEditor.vue';
import RecurrenceScopeDialog from '@/components/calendar/RecurrenceScopeDialog.vue';
import type { RecurrenceScope } from '@/components/calendar/RecurrenceScopeDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useAuth } from '@/composables/useAuth';
import { api, ApiError } from '@/lib/api';
import { format, parseISO } from '@/lib/calendarMath';
import { DEFAULT_COLOR } from '@/lib/eventColors';
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
    /** Values carried over from the quick-create card ("More options"). */
    draft?: EventDraft | null;
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
const newType = reactive({ name: '', color: DEFAULT_COLOR });
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
            {
                name: newType.name.trim(),
                color: newType.color,
                is_active: true,
            },
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

export type EventDraft = Partial<FormState>;

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
    { minutes: 10, label: '10 min' },
    { minutes: 30, label: '30 min' },
    { minutes: 60, label: '1 hour' },
    { minutes: 1440, label: '1 day' },
];

// Borderless fields that only show a surface on hover and focus.
const ghost =
    'h-9 border-transparent bg-transparent px-2 shadow-none hover:bg-muted/60 focus-visible:bg-background dark:bg-transparent dark:hover:bg-muted/40 dark:[color-scheme:dark] [&::-webkit-calendar-picker-indicator]:opacity-40';
const chip =
    'h-8 rounded-full border-transparent bg-muted/70 px-3 text-xs font-medium shadow-none hover:bg-muted dark:bg-muted/50 dark:hover:bg-muted';

// Preview for the title dot; "Auto" follows the type, then the calendar.
const autoColor = computed(
    () =>
        allTypes.value.find((t) => t.id === form.event_type_id)?.color ??
        list.writableCalendars.find((c) => c.id === form.calendar_id)
            ?.effective_color ??
        null,
);

const duration = computed(() => {
    if (!form.startDate || !form.endDate) {
        return '';
    }

    if (form.all_day) {
        const days =
            (Date.parse(form.endDate) - Date.parse(form.startDate)) / 864e5 + 1;

        return days > 1 ? `${days} days` : '';
    }

    const mins = Math.round(
        (new Date(`${form.endDate}T${form.endTime}`).getTime() -
            new Date(`${form.startDate}T${form.startTime}`).getTime()) /
            60_000,
    );

    if (!(mins > 0)) {
        return '';
    }

    const d = Math.floor(mins / 1440);
    const h = Math.floor((mins % 1440) / 60);
    const m = mins % 60;

    return [d && `${d} d`, h && `${h} hr`, m && `${m} min`]
        .filter(Boolean)
        .join(' ');
});

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
            Object.assign(form, props.draft ?? {});
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
    <!-- Centred, like the quick-create card it extends; the page behind
         scrolls the form when it is taller than the window. -->
    <Dialog :open="open" @update:open="(value) => !value && emit('close')">
        <DialogScrollContent
            class="max-w-[min(36rem,calc(100vw-1rem))] gap-0 p-0 sm:rounded-3xl"
        >
            <DialogHeader class="px-6 pt-5 pb-0 text-left">
                <DialogTitle class="text-sm font-medium text-muted-foreground">
                    {{ isEditing ? 'Edit event' : 'New event' }}
                </DialogTitle>
                <DialogDescription class="sr-only">
                    Event editor
                </DialogDescription>
            </DialogHeader>

            <div
                class="flex flex-1 flex-col divide-y divide-border/70 px-6 [&>section]:py-5"
            >
                <!-- Title, color, calendar and type -->
                <section class="flex gap-3 pt-3!">
                    <ColorPicker
                        v-model="form.color"
                        :default-color="autoColor"
                        label="Event color"
                    >
                        <template #trigger="{ color }">
                            <button
                                type="button"
                                class="-m-1.5 mt-1.5 flex size-7 shrink-0 items-center justify-center rounded-full outline-none hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                aria-label="Event color"
                            >
                                <span
                                    class="size-3.5 rounded-full"
                                    :style="{ background: color }"
                                />
                            </button>
                        </template>
                    </ColorPicker>

                    <div class="min-w-0 flex-1">
                        <input
                            id="event-title"
                            v-model="form.title"
                            placeholder="Add title"
                            aria-label="Title"
                            autofocus
                            class="w-full border-b border-transparent bg-transparent pb-1 text-xl font-semibold tracking-tight outline-none placeholder:text-muted-foreground/50 focus:border-border sm:text-2xl"
                            @keydown.enter.prevent="form.title && save()"
                        />
                        <p
                            v-if="fieldError('title')"
                            class="mt-1 text-sm text-destructive"
                        >
                            {{ fieldError('title') }}
                        </p>

                        <div class="mt-3 flex flex-wrap gap-1.5">
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
                                <SelectTrigger
                                    id="event-calendar"
                                    size="sm"
                                    :class="chip"
                                    aria-label="Calendar"
                                >
                                    <SelectValue
                                        placeholder="Pick a calendar"
                                    />
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

                            <Select
                                :model-value="
                                    form.event_type_id === null
                                        ? 'none'
                                        : String(form.event_type_id)
                                "
                                @update:model-value="
                                    (v) => onTypeSelect(v as string)
                                "
                            >
                                <SelectTrigger
                                    id="event-type"
                                    size="sm"
                                    :class="[
                                        chip,
                                        form.event_type_id === null &&
                                            'text-muted-foreground',
                                    ]"
                                    aria-label="Event type"
                                >
                                    <SelectValue placeholder="No type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none"
                                        >No type</SelectItem
                                    >
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
                                            <span
                                                class="flex items-center gap-2 text-primary"
                                            >
                                                <Plus class="size-3.5" />
                                                New type
                                            </span>
                                        </SelectItem>
                                    </template>
                                </SelectContent>
                            </Select>
                        </div>
                        <p
                            v-if="fieldError('calendar_id')"
                            class="mt-1 text-sm text-destructive"
                        >
                            {{ fieldError('calendar_id') }}
                        </p>

                        <div
                            v-if="canManageTypes && addingType"
                            class="mt-3 flex items-start gap-2"
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
                                    aria-label="Type name"
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
                </section>

                <!-- When -->
                <section class="grid gap-1">
                    <div class="flex gap-3">
                        <Clock
                            class="mt-2.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <div class="-ml-2 grid min-w-0 flex-1 gap-0.5">
                            <div class="flex items-center gap-1">
                                <Input
                                    id="event-start-date"
                                    v-model="form.startDate"
                                    type="date"
                                    aria-label="Start date"
                                    :class="[ghost, 'w-36']"
                                />
                                <Input
                                    v-if="!form.all_day"
                                    v-model="form.startTime"
                                    type="time"
                                    aria-label="Start time"
                                    :class="[ghost, 'w-[7.5rem]']"
                                />
                            </div>
                            <div class="flex items-center gap-1">
                                <Input
                                    id="event-end-date"
                                    v-model="form.endDate"
                                    type="date"
                                    :min="form.startDate"
                                    aria-label="End date"
                                    :class="[ghost, 'w-36']"
                                />
                                <Input
                                    v-if="!form.all_day"
                                    v-model="form.endTime"
                                    type="time"
                                    aria-label="End time"
                                    :class="[ghost, 'w-[7.5rem]']"
                                />
                                <span
                                    v-if="duration"
                                    class="pl-1 text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ duration }}
                                </span>
                            </div>
                            <Label
                                class="flex h-9 w-fit items-center gap-2.5 px-2 text-sm font-normal"
                            >
                                <Switch v-model="form.all_day" />
                                All day
                            </Label>
                            <p
                                v-for="key in ['starts_at', 'ends_at']"
                                v-show="fieldError(key)"
                                :key="key"
                                class="px-2 text-sm text-destructive"
                            >
                                {{ fieldError(key) }}
                            </p>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <Repeat
                            class="mt-2.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <div class="-ml-2 min-w-0 flex-1">
                            <RecurrenceEditor
                                v-model="form.rrule"
                                :start-date="startDateAsDate"
                            />
                        </div>
                    </div>
                </section>

                <!-- Who -->
                <section class="grid gap-1">
                    <div class="flex gap-3">
                        <Users
                            class="mt-2.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <div class="-ml-2 min-w-0 flex-1">
                            <AttendeePicker v-model="form.attendees" />
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <Bell
                            class="mt-2.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <div
                            class="flex min-h-9 flex-wrap items-center gap-1.5"
                            role="group"
                            aria-label="Reminders"
                        >
                            <button
                                v-for="preset in REMINDER_PRESETS"
                                :key="preset.minutes"
                                type="button"
                                class="h-7 rounded-full border px-2.5 text-xs transition-colors"
                                :class="
                                    form.reminders.includes(preset.minutes)
                                        ? 'border-foreground bg-foreground text-background'
                                        : 'text-muted-foreground hover:border-foreground/30 hover:text-foreground'
                                "
                                :aria-pressed="
                                    form.reminders.includes(preset.minutes)
                                "
                                :aria-label="`Remind ${preset.label} before`"
                                @click="toggleReminder(preset.minutes)"
                            >
                                {{ preset.label }}
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Where and what -->
                <section class="grid gap-1">
                    <div class="flex gap-3">
                        <MapPin
                            class="mt-2.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <Input
                            id="event-location"
                            v-model="form.location"
                            placeholder="Add location"
                            aria-label="Location"
                            :class="[ghost, '-ml-2 flex-1']"
                        />
                    </div>

                    <div class="flex gap-3">
                        <Video
                            class="mt-2.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <div class="-ml-2 min-w-0 flex-1">
                            <Input
                                id="event-url"
                                v-model="form.meeting_url"
                                type="url"
                                placeholder="Add meeting link"
                                aria-label="Meeting link"
                                :class="ghost"
                            />
                            <p
                                v-if="fieldError('meeting_url')"
                                class="px-2 text-sm text-destructive"
                            >
                                {{ fieldError('meeting_url') }}
                            </p>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <AlignLeft
                            class="mt-2.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <Textarea
                            id="event-description"
                            v-model="form.description"
                            placeholder="Add description"
                            aria-label="Description"
                            :class="[
                                ghost,
                                '-ml-2 h-auto min-h-9 flex-1 resize-none py-2',
                            ]"
                        />
                    </div>
                </section>

                <!-- Privacy -->
                <section>
                    <Label class="flex items-center gap-3 font-normal">
                        <Lock class="size-4 shrink-0 text-muted-foreground" />
                        <span class="flex-1">
                            Private
                            <span class="block text-xs text-muted-foreground">
                                Others only see “Busy”
                            </span>
                        </span>
                        <Switch
                            :model-value="form.visibility === 'private'"
                            @update:model-value="
                                (v) =>
                                    (form.visibility = v
                                        ? 'private'
                                        : 'default')
                            "
                        />
                    </Label>
                </section>
            </div>

            <DialogFooter
                class="sticky bottom-0 flex-row justify-end gap-2 rounded-b-3xl border-t bg-background px-6 py-4"
            >
                <Button
                    variant="ghost"
                    :disabled="saving"
                    @click="emit('close')"
                >
                    Cancel
                </Button>
                <Button :disabled="saving || !form.title" @click="save">
                    <Spinner v-if="saving" />
                    {{ isEditing ? 'Save changes' : 'Create event' }}
                </Button>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>

    <RecurrenceScopeDialog
        :open="scopeDialogOpen"
        action="edit"
        @choose="saveWithScope"
        @cancel="scopeDialogOpen = false"
    />
</template>
