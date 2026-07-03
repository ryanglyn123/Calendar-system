<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
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
import { api, ApiError } from '@/lib/api';
import { format } from '@/lib/calendarMath';
import { store as eventsStore, update as eventsUpdate } from '@/routes/api/events';
import { useCalendarGridStore } from '@/stores/calendarGrid';
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
});

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
        } else {
            const start = props.initialStart ?? new Date();
            const end =
                props.initialEnd ?? new Date(start.getTime() + 60 * 60 * 1000);

            form.calendar_id =
                list.writableCalendars.find((c) => c.type === 'personal')
                    ?.id ??
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

        const start = new Date(`${form.startDate}T${form.startTime || '00:00'}`);
        const end = new Date(`${form.endDate}T${form.endTime || '00:00'}`);

        if (end <= start) {
            const bumped = new Date(start.getTime() + 60 * 60 * 1000);
            form.endDate = format(bumped, 'yyyy-MM-dd');
            form.endTime = format(bumped, 'HH:mm');
        }
    },
);

const fieldError = (key: string): string | undefined =>
    errors.value[key]?.[0];

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
});

const save = async () => {
    saving.value = true;
    errors.value = {};

    try {
        if (props.occurrence) {
            await api.patch(eventsUpdate(props.occurrence.event_id).url, payload());
        } else {
            await api.post(eventsStore().url, payload());
        }

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
    <Sheet
        :open="open"
        @update:open="(value) => !value && emit('close')"
    >
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
                    <p v-if="fieldError('title')" class="text-sm text-destructive">
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

                <div class="grid grid-cols-2 gap-3">
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
                        @update:model-value="
                            (v) =>
                                (form.event_type_id =
                                    v === 'none' ? null : Number(v))
                        "
                    >
                        <SelectTrigger id="event-type">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">None</SelectItem>
                            <SelectItem
                                v-for="type in eventTypes"
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
                        </SelectContent>
                    </Select>
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
                            (v) =>
                                (form.visibility = v ? 'private' : 'default')
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
</template>
