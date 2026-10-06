<script setup lang="ts">
import {
    AlignLeft,
    CalendarDays,
    Clock,
    GripHorizontal,
    MapPin,
    Users,
    Video,
    X,
} from '@lucide/vue';
import {
    DialogContent,
    DialogDescription,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, reactive, ref, useTemplateRef, watch } from 'vue';
import { toast } from 'vue-sonner';
import AttendeePicker from '@/components/calendar/AttendeePicker.vue';
import ColorPicker from '@/components/calendar/ColorPicker.vue';
import type { EventDraft } from '@/components/calendar/EventEditorDialog.vue';
import RecurrenceEditor from '@/components/calendar/RecurrenceEditor.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { api, ApiError } from '@/lib/api';
import { addDays, format, parseISO } from '@/lib/calendarMath';
import { store as eventsStore } from '@/routes/api/events';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import { useCalendarListStore } from '@/stores/calendarList';
import type { EventTypeItem } from '@/types/calendar';

/** What the card starts with. */
export type QuickCreateTarget = {
    start: Date;
    end: Date | null;
    allDay: boolean;
    /** Preset values, e.g. a category picked from the Create menu. */
    draft?: EventDraft;
};

const props = defineProps<{
    target: QuickCreateTarget | null;
    /** Every event type the viewer can use (organisation types and their own
     *  categories), offered as tabs beside plain "Event". */
    types: EventTypeItem[];
}>();

const emit = defineEmits<{
    close: [];
    /** Hand the typed values to the full editor. */
    more: [draft: EventDraft];
}>();

const grid = useCalendarGridStore();
const list = useCalendarListStore();

const REMINDERS = [
    { minutes: 10, label: 'Notify 10 minutes before' },
    { minutes: 30, label: 'Notify 30 minutes before' },
    { minutes: 60, label: 'Notify 1 hour before' },
    { minutes: 1440, label: 'Notify 1 day before' },
];

const form = reactive({
    title: '',
    event_type_id: null as number | null,
    calendar_id: null as number | null,
    all_day: true,
    startDate: '',
    startTime: '09:00',
    endDate: '',
    endTime: '10:00',
    rrule: null as string | null,
    attendees: [] as { id: number; name: string }[],
    meeting_url: '',
    location: '',
    description: '',
    visibility: 'default' as 'default' | 'private',
    reminders: [] as number[],
    /** Null follows the type, then the calendar. */
    color: null as string | null,
});

/** What "Default" means here: the type's color, else the calendar's. */
const defaultColor = computed(
    () =>
        props.types.find((t) => t.id === form.event_type_id)?.color ??
        list.writableCalendars.find((c) => c.id === form.calendar_id)
            ?.effective_color ??
        null,
);

const saving = ref(false);
const errors = ref<Record<string, string[]>>({});
const errorText = computed(() => Object.values(errors.value)[0]?.[0]);

/* ---- Dragging (the grip bar), kept inside the viewport ---------------- */

const EDGE = 8;
const offset = reactive({ x: 0, y: 0 });
let drag: {
    x: number;
    y: number;
    ox: number;
    oy: number;
    rect: DOMRect;
} | null = null;

const clamp = (v: number, min: number, max: number) =>
    Math.min(Math.max(v, min), Math.max(min, max));

function dragStart(event: PointerEvent): void {
    const bar = event.currentTarget as HTMLElement;
    const card = bar.parentElement;

    if (event.button !== 0 || !card) {
        return;
    }

    bar.setPointerCapture(event.pointerId);
    drag = {
        x: event.clientX,
        y: event.clientY,
        ox: offset.x,
        oy: offset.y,
        rect: card.getBoundingClientRect(),
    };
}

function dragMove(event: PointerEvent): void {
    if (!drag) {
        return;
    }

    const { rect } = drag;

    offset.x =
        drag.ox +
        clamp(
            event.clientX - drag.x,
            EDGE - rect.left,
            innerWidth - EDGE - rect.right,
        );
    offset.y =
        drag.oy +
        clamp(
            event.clientY - drag.y,
            EDGE - rect.top,
            innerHeight - EDGE - rect.bottom,
        );
}

const dragEnd = () => (drag = null);

/* ---- Opening ---------------------------------------------------------- */

watch(
    () => props.target,
    (target) => {
        if (!target) {
            return;
        }

        const end = target.end ?? new Date(target.start.getTime() + 3600_000);

        Object.assign(form, {
            title: '',
            event_type_id: null,
            calendar_id:
                list.writableCalendars.find((c) => c.type === 'personal')?.id ??
                list.writableCalendars[0]?.id ??
                null,
            all_day: target.allDay,
            startDate: format(target.start, 'yyyy-MM-dd'),
            startTime: format(target.start, 'HH:mm'),
            endDate: format(target.allDay ? target.start : end, 'yyyy-MM-dd'),
            endTime: format(end, 'HH:mm'),
            rrule: null,
            attendees: [],
            meeting_url: '',
            location: '',
            description: '',
            visibility: 'default',
            reminders: [],
            ...target.draft,
        });
        errors.value = {};
        offset.x = 0;
        offset.y = 0;
    },
);

const titleEl = useTemplateRef<HTMLInputElement>('titleEl');

/* ---- When ------------------------------------------------------------- */

const at = (date: string, time: string) => new Date(`${date}T${time}`);
const day = (date: string) => parseISO(`${date}T00:00:00`);

const multiDay = computed(() => form.endDate !== form.startDate);
const startAsDate = computed(() =>
    form.startDate ? day(form.startDate) : new Date(),
);

const dayLabel = (date: string) =>
    date ? format(day(date), 'EEEE, MMMM d') : '';
const timeLabel = (time: string) => format(at('2000-01-01', time), 'h:mmaaa');

// Native pickers stay hidden; the readable labels open them.
const startDateEl = useTemplateRef<HTMLInputElement>('startDateEl');
const endDateEl = useTemplateRef<HTMLInputElement>('endDateEl');
const startTimeEl = useTemplateRef<HTMLInputElement>('startTimeEl');
const endTimeEl = useTemplateRef<HTMLInputElement>('endTimeEl');

function pick(el: HTMLInputElement | null): void {
    try {
        el?.showPicker();
    } catch {
        el?.focus();
    }
}

const valueOf = (e: Event) => (e.target as HTMLInputElement).value;

// Moving the start keeps the event's length, as Google Calendar does.
function setStartDate(value: string): void {
    if (!value) {
        return;
    }

    const shift = Math.round(
        (day(value).getTime() - day(form.startDate).getTime()) / 864e5,
    );
    form.endDate = format(addDays(day(form.endDate), shift), 'yyyy-MM-dd');
    form.startDate = value;
}

function setStartTime(value: string): void {
    if (!value) {
        return;
    }

    const length =
        at(form.endDate, form.endTime).getTime() -
        at(form.startDate, form.startTime).getTime();
    const end = new Date(at(form.startDate, value).getTime() + length);

    form.startTime = value;
    form.endDate = format(end, 'yyyy-MM-dd');
    form.endTime = format(end, 'HH:mm');
}

/* ---- Calendar line: visibility and notification ----------------------- */

const reminderValue = computed({
    get: () => String(form.reminders[0] ?? 'none'),
    set: (value: string) =>
        (form.reminders = value === 'none' ? [] : [Number(value)]),
});

/* ---- Save / hand-off -------------------------------------------------- */

async function save(): Promise<void> {
    if (!form.title.trim() || saving.value) {
        return;
    }

    saving.value = true;
    errors.value = {};

    try {
        await api.post(eventsStore().url, {
            calendar_id: form.calendar_id,
            event_type_id: form.event_type_id,
            title: form.title.trim(),
            description: form.description || null,
            location: form.location || null,
            meeting_url: form.meeting_url || null,
            starts_at: form.all_day
                ? `${form.startDate}T00:00:00`
                : at(form.startDate, form.startTime).toISOString(),
            ends_at: form.all_day
                ? `${form.endDate}T00:00:00`
                : at(form.endDate, form.endTime).toISOString(),
            all_day: form.all_day,
            visibility: form.visibility,
            rrule: form.rrule,
            attendees: form.attendees.map((a) => a.id),
            reminders: form.reminders,
            color: form.color,
        });

        await grid.invalidateAround(day(form.startDate));
        toast.success('Event created.');
        emit('close');
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = Object.keys(error.errors).length
                ? error.errors
                : { form: [error.message] };
        } else {
            toast.error('Something went wrong while saving.');
        }
    } finally {
        saving.value = false;
    }
}

const more = () =>
    emit('more', {
        ...form,
        attendees: [...form.attendees],
        reminders: [...form.reminders],
    });

const ghost =
    'h-9 border-transparent bg-transparent px-2 shadow-none hover:bg-muted/60 focus-visible:bg-background dark:bg-transparent dark:hover:bg-muted/40';
const part =
    '-mx-1 rounded-md px-1 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
const inline =
    'h-7 w-fit gap-1 border-transparent bg-transparent px-1.5 text-xs text-muted-foreground shadow-none hover:bg-muted/60 hover:text-foreground dark:bg-transparent dark:hover:bg-muted/40';
const tab = (on: boolean) =>
    on
        ? 'bg-primary text-primary-foreground'
        : 'text-muted-foreground hover:bg-muted hover:text-foreground';
</script>

<template>
    <DialogRoot
        :open="target !== null"
        @update:open="(open) => !open && emit('close')"
    >
        <DialogPortal>
            <!-- Floats over the calendar without a scrim, like Google's
                 quick-create card; a click outside discards it.
                 transition-none: the duration classes are for the open and
                 close keyframes, and must not make the card lag a drag. -->
            <DialogContent
                data-create-card
                class="fixed top-1/2 left-1/2 z-50 flex max-h-[calc(100dvh-1rem)] w-[min(33rem,calc(100vw-1rem))] -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-3xl border bg-popover text-popover-foreground shadow-2xl transition-none outline-none data-[state=closed]:animate-out data-[state=closed]:duration-150 data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[state=open]:animate-in data-[state=open]:duration-200 data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95"
                :style="{
                    left: `calc(50% + ${offset.x}px)`,
                    top: `calc(50% + ${offset.y}px)`,
                }"
                @open-auto-focus.prevent="titleEl?.focus()"
            >
                <DialogTitle class="sr-only">New event</DialogTitle>
                <DialogDescription class="sr-only">
                    Add a title and time, then save. More options opens the full
                    editor.
                </DialogDescription>

                <!-- Grip bar: drag to move the card off what it covers. -->
                <div
                    class="flex h-12 shrink-0 cursor-grab touch-none items-center justify-between pr-2 pl-5 select-none active:cursor-grabbing"
                    @pointerdown="dragStart"
                    @pointermove="dragMove"
                    @pointerup="dragEnd"
                    @pointercancel="dragEnd"
                >
                    <GripHorizontal
                        class="size-5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <button
                        type="button"
                        class="inline-flex size-9 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        aria-label="Close"
                        @pointerdown.stop
                        @click="emit('close')"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <div class="grid gap-4 overflow-y-auto px-6 pb-5">
                    <div class="grid gap-3 pl-9">
                        <input
                            ref="titleEl"
                            v-model="form.title"
                            aria-label="Title"
                            placeholder="Add title"
                            class="border-b-2 border-border bg-transparent pb-1.5 text-2xl outline-none placeholder:text-muted-foreground/70 focus:border-primary"
                            @keydown.enter.prevent="save"
                        />

                        <!-- What kind: plain event or one of the viewer's
                             event types, the same list as the Create menu. -->
                        <div
                            class="flex flex-wrap gap-1"
                            role="group"
                            aria-label="Event type"
                        >
                            <button
                                type="button"
                                class="h-8 rounded-full px-3 text-sm font-medium transition-colors"
                                :class="tab(form.event_type_id === null)"
                                :aria-pressed="form.event_type_id === null"
                                @click="form.event_type_id = null"
                            >
                                Event
                            </button>
                            <button
                                v-for="category in types"
                                :key="category.id"
                                type="button"
                                class="inline-flex h-8 items-center gap-2 rounded-full px-3 text-sm font-medium transition-colors"
                                :class="tab(form.event_type_id === category.id)"
                                :aria-pressed="
                                    form.event_type_id === category.id
                                "
                                @click="form.event_type_id = category.id"
                            >
                                {{ category.name }}
                            </button>
                        </div>
                    </div>

                    <!-- When -->
                    <div class="flex gap-4">
                        <Clock
                            class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                        />
                        <div class="min-w-0 flex-1">
                            <div
                                class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1"
                            >
                                <p class="relative leading-6">
                                    <button
                                        type="button"
                                        :class="part"
                                        :aria-label="`Start date, ${dayLabel(form.startDate)}`"
                                        @click="pick(startDateEl)"
                                    >
                                        {{ dayLabel(form.startDate) }}</button
                                    ><template v-if="!form.all_day"
                                        >{{ multiDay ? ', ' : ' · '
                                        }}<button
                                            type="button"
                                            :class="part"
                                            :aria-label="`Start time, ${timeLabel(form.startTime)}`"
                                            @click="pick(startTimeEl)"
                                        >
                                            {{ timeLabel(form.startTime) }}
                                        </button></template
                                    ><template v-if="multiDay || !form.all_day">
                                        –
                                    </template>
                                    <template v-if="multiDay"
                                        ><button
                                            type="button"
                                            :class="part"
                                            :aria-label="`End date, ${dayLabel(form.endDate)}`"
                                            @click="pick(endDateEl)"
                                        >
                                            {{ dayLabel(form.endDate) }}</button
                                        ><template v-if="!form.all_day"
                                            >,
                                        </template></template
                                    ><button
                                        v-if="!form.all_day"
                                        type="button"
                                        :class="part"
                                        :aria-label="`End time, ${timeLabel(form.endTime)}`"
                                        @click="pick(endTimeEl)"
                                    >
                                        {{ timeLabel(form.endTime) }}
                                    </button>

                                    <input
                                        ref="startDateEl"
                                        type="date"
                                        :value="form.startDate"
                                        class="pointer-events-none absolute bottom-0 left-0 size-px opacity-0"
                                        tabindex="-1"
                                        aria-hidden="true"
                                        @change="
                                            (e) => setStartDate(valueOf(e))
                                        "
                                    />
                                    <input
                                        ref="startTimeEl"
                                        type="time"
                                        :value="form.startTime"
                                        class="pointer-events-none absolute bottom-0 left-0 size-px opacity-0"
                                        tabindex="-1"
                                        aria-hidden="true"
                                        @change="
                                            (e) => setStartTime(valueOf(e))
                                        "
                                    />
                                    <input
                                        ref="endDateEl"
                                        v-model="form.endDate"
                                        type="date"
                                        :min="form.startDate"
                                        class="pointer-events-none absolute right-0 bottom-0 size-px opacity-0"
                                        tabindex="-1"
                                        aria-hidden="true"
                                    />
                                    <input
                                        ref="endTimeEl"
                                        v-model.lazy="form.endTime"
                                        type="time"
                                        class="pointer-events-none absolute right-0 bottom-0 size-px opacity-0"
                                        tabindex="-1"
                                        aria-hidden="true"
                                    />
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="shrink-0 rounded-full"
                                    @click="form.all_day = !form.all_day"
                                >
                                    {{ form.all_day ? 'Add time' : 'All day' }}
                                </Button>
                            </div>
                            <div class="-ml-2">
                                <RecurrenceEditor
                                    v-model="form.rrule"
                                    :start-date="startAsDate"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-4">
                        <Users
                            class="mt-2 size-5 shrink-0 text-muted-foreground"
                        />
                        <div class="-ml-2 min-w-0 flex-1">
                            <AttendeePicker v-model="form.attendees" />
                        </div>
                    </div>

                    <div class="flex gap-4">
                        <Video
                            class="mt-2 size-5 shrink-0 text-muted-foreground"
                        />
                        <Input
                            v-model="form.meeting_url"
                            type="url"
                            placeholder="Add meeting link"
                            aria-label="Meeting link"
                            :class="[ghost, '-ml-2 flex-1']"
                        />
                    </div>

                    <div class="flex gap-4">
                        <MapPin
                            class="mt-2 size-5 shrink-0 text-muted-foreground"
                        />
                        <Input
                            v-model="form.location"
                            placeholder="Add location"
                            aria-label="Location"
                            :class="[ghost, '-ml-2 flex-1']"
                        />
                    </div>

                    <div class="flex gap-4">
                        <AlignLeft
                            class="mt-2 size-5 shrink-0 text-muted-foreground"
                        />
                        <Textarea
                            v-model="form.description"
                            placeholder="Add description"
                            aria-label="Description"
                            :class="[
                                ghost,
                                '-ml-2 h-auto min-h-9 flex-1 resize-none py-2',
                            ]"
                        />
                    </div>

                    <!-- Calendar, with how it shows and when it notifies -->
                    <div class="flex gap-4">
                        <CalendarDays
                            class="mt-2 size-5 shrink-0 text-muted-foreground"
                        />
                        <div class="-ml-2 min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
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
                                        :class="[ghost, 'w-fit']"
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
                                            <span
                                                class="flex items-center gap-2"
                                            >
                                                {{ calendar.name }}
                                                <span
                                                    class="size-2.5 rounded-full"
                                                    :style="{
                                                        backgroundColor:
                                                            calendar.effective_color,
                                                    }"
                                                />
                                            </span>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <ColorPicker
                                    v-model="form.color"
                                    :default-color="defaultColor"
                                    label="Event color"
                                />
                            </div>

                            <div class="flex flex-wrap items-center">
                                <Select
                                    :model-value="form.visibility"
                                    @update:model-value="
                                        (v) =>
                                            (form.visibility = v as
                                                'default' | 'private')
                                    "
                                >
                                    <SelectTrigger
                                        size="sm"
                                        :class="inline"
                                        aria-label="Visibility"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="default">
                                            Default visibility
                                        </SelectItem>
                                        <SelectItem value="private">
                                            Private (others see Busy)
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <span
                                    class="text-xs text-muted-foreground"
                                    aria-hidden="true"
                                    >·</span
                                >
                                <Select v-model="reminderValue">
                                    <SelectTrigger
                                        size="sm"
                                        :class="inline"
                                        aria-label="Notification"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            No notification
                                        </SelectItem>
                                        <SelectItem
                                            v-for="r in REMINDERS"
                                            :key="r.minutes"
                                            :value="String(r.minutes)"
                                        >
                                            {{ r.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="flex shrink-0 flex-wrap items-center justify-end gap-2 px-6 pb-5"
                >
                    <p
                        v-if="errorText"
                        class="mr-auto text-sm text-destructive"
                        role="alert"
                    >
                        {{ errorText }}
                    </p>
                    <Button
                        type="button"
                        variant="ghost"
                        class="rounded-full font-medium"
                        @click="more"
                    >
                        More options
                    </Button>
                    <Button
                        type="button"
                        class="rounded-full px-6"
                        :disabled="saving || !form.title.trim()"
                        @click="save"
                    >
                        <Spinner v-if="saving" />
                        Save
                    </Button>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
