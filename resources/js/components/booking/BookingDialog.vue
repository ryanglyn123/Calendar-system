<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    CalendarDays,
    CalendarOff,
    Check,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    CircleCheck,
    MapPin,
    RotateCcw,
    ShieldCheck,
    Users,
    X,
    Zap,
} from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import PeopleEditor from '@/components/booking/PeopleEditor.vue';
import TimeWheel from '@/components/booking/TimeWheel.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { api, ApiError } from '@/lib/api';
import {
    addDays,
    eventInk,
    eventWash,
    format,
    isSameDay,
    parseISO,
} from '@/lib/calendarMath';
import { peopleProblems, peopleToSend, rowErrors } from '@/lib/people';
import { resourceNouns, resourceTypeIcon } from '@/lib/resourceTypeIcon';
import bookingsApi from '@/routes/api/bookings';
import resourcesApi from '@/routes/api/resources';
import bookingsPage from '@/routes/bookings';

type BookableResource = {
    id: number;
    name: string;
    location: string | null;
    capacity: number | null;
    /** The resource's calendar color, shared with the grid. */
    color?: string | null;
};

type Availability = {
    data: { starts_at: string; ends_at: string; status: string }[];
    /** Opening hours for the day; null when the type has no hours limit. */
    hours: { opens: string; closes: string } | null;
    closed: boolean;
    holiday: string | null;
};

const props = defineProps<{
    type: {
        id: number;
        name: string;
        icon?: string | null;
        requires_approval: boolean;
        max_duration_minutes?: number | null;
    };
    resources: BookableResource[];
    /** Prefill the date (yyyy-MM-dd) when opened from a calendar cell. */
    initialDate?: string | null;
    /** Prefill the slot (minutes since midnight) from a week/day click. */
    initialTime?: { start: number; end: number } | null;
    /** Preselect the room or vehicle the calendar is showing. */
    initialResourceId?: number | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    /** A booking was created; starts_at ISO string for cache invalidation. */
    created: [startsAt: string];
}>();

/** Slot size for the pickers and the day strip, in minutes. */
const STEP = 30;
const DAY = 24 * 60;

const clamp = (value: number, min: number, max: number) =>
    Math.min(Math.max(value, min), max);
const todayKey = () => format(new Date(), 'yyyy-MM-dd');

/** Minutes since midnight as a clock label: 570 -> "9:30am". */
const clock = (minutes: number) =>
    format(new Date(2000, 0, 1, 0, minutes), 'h:mmaaa');

/** Compact clock for tight spaces: 540 -> "9am", 570 -> "9:30am". */
const shortTime = (minutes: number) =>
    format(new Date(2000, 0, 1, 0, minutes), minutes % 60 ? 'h:mmaaa' : 'haaa');

/** Compact range: "9–10am", "11:30am–1pm". */
const shortRange = (from: number, to: number) => {
    const morning = (m: number) => m % DAY < 720;
    const start = shortTime(from);

    return `${morning(from) === morning(to) ? start.replace(/[ap]m$/, '') : start}–${shortTime(to)}`;
};

const duration = (minutes: number) => {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return [h ? `${h} h` : '', m ? `${m} min` : ''].filter(Boolean).join(' ');
};

/** Every slot start from `from` to `to`, inclusive. */
const slots = (from: number, to: number) => {
    const out: number[] = [];

    for (let m = from; m <= to; m += STEP) {
        out.push(m);
    }

    return out;
};

/* ---- Form & draft -------------------------------------------------- */

/** The day the calendar asked for, but never one that is already over. */
const requestedDate = () =>
    props.initialDate && props.initialDate < todayKey()
        ? todayKey()
        : props.initialDate;

/** Most people book for something they attend; one tap removes them. */
const page = usePage();
const ownName = () => page.props.auth.user?.name ?? '';

/** "attendee" for rooms, "passenger" for vehicles. */
const personNoun = computed(() =>
    resourceNouns(props.type).one === 'vehicle' ? 'passenger' : 'attendee',
);

const blank = () => ({
    resource_id:
        props.initialResourceId ??
        (props.resources.length === 1 ? props.resources[0].id : null),
    title: '',
    date: requestedDate() ?? format(addDays(new Date(), 1), 'yyyy-MM-dd'),
    start: props.initialTime?.start ?? 9 * 60,
    end: props.initialTime?.end ?? 10 * 60,
    purpose: '',
    people: [ownName()],
});

const form = ref(blank());
/** The user picked a time themselves, so stop placing it for them. */
const timeTouched = ref(false);
/** The time came from the slot they clicked: keep it, but it isn't a draft. */
const timeRequested = ref(false);
const errors = ref<Record<string, string[]>>({});
const saving = ref(false);
/** Shown after closing mid-way and coming back to the same section. */
const restored = ref(false);
let draftTypeId: number | null = null;

type Receipt = {
    id: number;
    reference: string;
    people: number;
    resource: string;
    color: string | null;
    title: string;
    day: Date;
    start: number;
    end: number;
    pending: boolean;
};

/** Set once the booking is in; the dialog turns into its confirmation. */
const receipt = ref<Receipt | null>(null);

const dirty = computed(
    () =>
        form.value.title.trim() !== '' ||
        form.value.purpose.trim() !== '' ||
        form.value.people.join('\n') !== ownName() ||
        timeTouched.value,
);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    // Closing keeps what was typed and reopening picks it back up. A
    // finished booking or a different section starts fresh.
    const keep =
        receipt.value === null && draftTypeId === props.type.id && dirty.value;

    if (keep) {
        form.value.date =
            requestedDate() ??
            (form.value.date < todayKey() ? todayKey() : form.value.date);

        form.value.resource_id =
            props.initialResourceId ?? form.value.resource_id;

        if (props.initialTime) {
            form.value.start = props.initialTime.start;
            form.value.end = props.initialTime.end;
        }
    } else {
        form.value = blank();
        timeTouched.value = false;
    }

    timeRequested.value = !!props.initialTime;

    openWheel.value = null;
    restored.value = keep;
    receipt.value = null;
    errors.value = {};
    draftTypeId = props.type.id;
});

function startOver(): void {
    form.value = blank();
    timeTouched.value = false;
    timeRequested.value = !!props.initialTime;
    restored.value = false;
    errors.value = {};
}

function clearError(...keys: string[]): void {
    if (!keys.some((key) => errors.value[key])) {
        return;
    }

    const next = { ...errors.value };
    keys.forEach((key) => delete next[key]);
    errors.value = next;
}

const fieldError = (key: string) => errors.value[key]?.[0];

watch(
    () => form.value.title,
    () => clearError('title'),
);
watch(
    () => form.value.resource_id,
    () => clearError('resource_id', 'people'),
);

/** Rows shift as people are added and removed: their errors go with them. */
function clearPeopleErrors(): void {
    clearError(
        ...Object.keys(errors.value).filter((k) => k.startsWith('people')),
    );
}
watch(
    () => [form.value.date, form.value.start, form.value.end],
    () => clearError('starts_at', 'ends_at'),
);

const chosen = computed(() =>
    props.resources.find((r) => r.id === form.value.resource_id),
);

/* ---- Availability for the chosen resource and day ------------------ */

const availability = ref<(Availability & { key: string }) | null>(null);
const loadFailed = ref(false);
let requestId = 0;

const availabilityKey = computed(
    () => `${form.value.resource_id}|${form.value.date}`,
);

/** Availability for exactly the current resource and day, else null. */
const info = computed(() =>
    availability.value?.key === availabilityKey.value
        ? availability.value
        : null,
);

/*
 * While the next resource or day loads, the last answer keeps the layout
 * steady: its opening hours frame the strip (hours rarely differ between
 * rooms), and a closed day stays closed while only the room changes.
 * Bookings themselves only ever come from the exact answer.
 */
const frame = computed(() => info.value ?? availability.value);
const sameDay = computed(() =>
    availability.value?.key.endsWith(`|${form.value.date}`)
        ? availability.value
        : null,
);
const dayInfo = computed(() => info.value ?? sameDay.value);

const loading = computed(
    () =>
        form.value.resource_id !== null &&
        info.value === null &&
        !loadFailed.value,
);

async function loadAvailability(): Promise<void> {
    const id = ++requestId;
    const { resource_id: resourceId, date } = form.value;
    const key = availabilityKey.value;

    loadFailed.value = false;

    if (resourceId === null || !date) {
        return;
    }

    try {
        const response = await api.get<Availability>(
            resourcesApi.availability(resourceId, { query: { date } }).url,
        );

        if (id === requestId) {
            availability.value = { ...response, key };
            fitSelection();
        }
    } catch {
        if (id === requestId) {
            loadFailed.value = true;
        }
    }
}

watch(
    () => [open.value, form.value.resource_id, form.value.date] as const,
    ([isOpen]) => {
        if (isOpen) {
            void loadAvailability();
        }
    },
);

const day = computed(() => parseISO(`${form.value.date}T00:00:00`));

const toMinutes = (hhmm: string) => {
    const [h, m] = hhmm.split(':').map(Number);

    return h * 60 + m;
};

/** Where on the chosen day an ISO instant falls, clamped to the day. */
const minutesOf = (iso: string) =>
    clamp(
        Math.round((new Date(iso).getTime() - day.value.getTime()) / 60_000),
        0,
        DAY,
    );

/** The bookable span of the day: opening hours, or the whole day. */
const bounds = computed(() =>
    frame.value?.hours
        ? {
              from: toMinutes(frame.value.hours.opens),
              to: toMinutes(frame.value.hours.closes),
          }
        : { from: 0, to: DAY },
);

const busy = computed(() =>
    (info.value?.data ?? []).map((slot) => ({
        start: minutesOf(slot.starts_at),
        end: minutesOf(slot.ends_at),
        pending: slot.status === 'pending',
    })),
);

/** Minutes of the chosen day already gone: 0 for future days. */
const pastUntil = computed(() =>
    clamp(Math.ceil((Date.now() - day.value.getTime()) / 60_000), 0, DAY),
);

const overlapping = (from: number, to: number) =>
    busy.value.find((b) => b.start < to && b.end > from);

const maxLength = computed(() => props.type.max_duration_minutes ?? null);

/** Why nothing can be booked on the chosen day, if that is the case. */
const blocked = computed<{ title: string; detail: string } | null>(() => {
    if (pastUntil.value >= DAY) {
        return { title: 'This day has passed', detail: 'Try a later day.' };
    }

    if (!dayInfo.value) {
        return null;
    }

    if (dayInfo.value.holiday) {
        return {
            title: dayInfo.value.holiday,
            detail: 'Bookings are paused for the holiday.',
        };
    }

    if (dayInfo.value.closed) {
        return {
            title: `Closed on ${format(day.value, 'EEEE')}s`,
            detail: 'Try another day.',
        };
    }

    if (pastUntil.value >= bounds.value.to - STEP) {
        return { title: 'Closed for today', detail: 'Try a later day.' };
    }

    return null;
});

/**
 * Keep the picked time sensible once the day's picture arrives. A time the
 * user chose is only pulled back inside opening hours; one we chose moves
 * to the first free slot of the same length.
 */
function fitSelection(): void {
    const { from, to } = bounds.value;
    const length = clamp(form.value.end - form.value.start, STEP, to - from);

    const place = (start: number) => {
        form.value.start = start;
        form.value.end = start + length;
    };

    const inside = (start: number) => start >= from && start + length <= to;

    if (timeTouched.value || timeRequested.value) {
        if (!inside(form.value.start)) {
            place(clamp(form.value.start, from, to - length));
        }

        return;
    }

    const free = (start: number) =>
        inside(start) &&
        start >= pastUntil.value &&
        !overlapping(start, start + length);

    if (free(form.value.start)) {
        return;
    }

    const first = slots(from, to - length).find(free);
    place(first ?? clamp(form.value.start, from, to - length));
}

/* ---- Picking a time ------------------------------------------------ */

function setStart(start: number): void {
    const length = form.value.end - form.value.start;

    form.value.start = start;
    form.value.end = Math.max(
        Math.min(start + length, bounds.value.to),
        start + STEP,
    );
    timeTouched.value = true;
}

function setEnd(end: number): void {
    form.value.end = end;
    timeTouched.value = true;
}

/** Which time wheel is open under its row; one at a time. */
const openWheel = ref<'start' | 'end' | null>(null);

const toggleWheel = (which: 'start' | 'end') => {
    openWheel.value = openWheel.value === which ? null : which;

    if (openWheel.value) {
        // Bring the whole wheel into view when it opens low in the form.
        void nextTick(() =>
            document.getElementById(`booking-${which}-wheel`)?.scrollIntoView({
                block: 'nearest',
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                    .matches
                    ? 'auto'
                    : 'smooth',
            }),
        );
    }
};

// Wheels hint at times that won't work by drawing them quieter.
const startUnavailable = (m: number) =>
    m < pastUntil.value ||
    m < bounds.value.from ||
    m >= bounds.value.to ||
    busy.value.some((b) => m >= b.start && m < b.end);

const endUnavailable = (m: number) =>
    m <= form.value.start ||
    m > bounds.value.to ||
    !!overlapping(form.value.start, m) ||
    (maxLength.value !== null && m - form.value.start > maxLength.value);

const startModel = computed({
    get: () => form.value.start,
    set: setStart,
});

const endModel = computed({
    get: () => form.value.end,
    set: setEnd,
});

/** "9:00 AM", as the pickers write it. */
const pillTime = (minutes: number) =>
    format(new Date(2000, 0, 1, 0, minutes), 'h:mm a');

watch(
    () => blocked.value,
    (isBlocked) => {
        if (isBlocked) {
            openWheel.value = null;
        }
    },
);

const shiftDay = (days: number) => {
    form.value.date = format(addDays(day.value, days), 'yyyy-MM-dd');
};

const canGoBack = computed(() => form.value.date > todayKey());

const dayTag = computed(() => {
    if (isSameDay(day.value, new Date())) {
        return 'Today';
    }

    return isSameDay(day.value, addDays(new Date(), 1)) ? 'Tomorrow' : null;
});

// The native picker stays hidden; the readable day label opens it.
const dateInput = useTemplateRef<HTMLInputElement>('dateInput');

function pickDate(): void {
    try {
        dateInput.value?.showPicker();
    } catch {
        dateInput.value?.focus();
    }
}

function onDatePicked(event: Event): void {
    const value = (event.target as HTMLInputElement).value;

    if (value) {
        form.value.date = value < todayKey() ? todayKey() : value;
    }
}

/** What is wrong with the picked time, in words, if anything. */
const timeIssue = computed<string | null>(() => {
    const { start, end } = form.value;

    if (end <= start) {
        return 'The end time needs to be after the start.';
    }

    if (start < pastUntil.value) {
        return 'That time has already passed.';
    }

    const clash = overlapping(start, end);

    if (clash) {
        return `Overlaps a booking from ${clock(clash.start)} to ${clock(clash.end)}. Pick a free time.`;
    }

    if (maxLength.value !== null && end - start > maxLength.value) {
        return `${props.type.name} bookings are limited to ${duration(maxLength.value)}.`;
    }

    if (
        frame.value?.hours &&
        (start < bounds.value.from || end > bounds.value.to)
    ) {
        return `Open ${clock(bounds.value.from)} to ${clock(bounds.value.to)} on this day.`;
    }

    return null;
});

const whenError = computed(
    () => fieldError('starts_at') ?? fieldError('ends_at'),
);

/* ---- Day strip ----------------------------------------------------- */

/** The stretch of the day the strip draws. */
const track = computed(() => {
    if (frame.value?.hours) {
        return bounds.value;
    }

    // No opening hours: frame the working day, widened to fit what is
    // booked or picked.
    const from = Math.min(
        6 * 60,
        form.value.start,
        ...busy.value.map((b) => b.start),
    );
    const to = Math.max(
        22 * 60,
        form.value.end,
        ...busy.value.map((b) => b.end),
    );

    return {
        from: Math.floor(from / 60) * 60,
        to: Math.min(DAY, Math.ceil(to / 60) * 60),
    };
});

/** Position on the strip as a percentage of its width. */
const pct = (minutes: number) =>
    ((clamp(minutes, track.value.from, track.value.to) - track.value.from) /
        (track.value.to - track.value.from)) *
    100;

const span = (from: number, to: number) => ({
    left: `${pct(from)}%`,
    width: `${pct(to) - pct(from)}%`,
});

const trackSlots = computed(() =>
    slots(track.value.from, track.value.to - STEP).map((m) => ({
        start: m,
        free:
            m >= pastUntil.value &&
            !busy.value.some((b) => m >= b.start && m < b.end),
    })),
);

const ticks = computed(() => {
    const hours = (track.value.to - track.value.from) / 60;
    const every = hours > 12 ? 120 : 60;
    const first = Math.ceil(track.value.from / every) * every;

    return slots(first, track.value.to)
        .filter((m) => m % every === 0)
        .map((m, i) => ({
            minutes: m,
            label: format(new Date(2000, 0, 1, 0, m), 'haaa'),
            // Every other label drops on narrow screens.
            minor: i % 2 === 1,
        }));
});

/** Where a click would drop the booking, previewed on hover. */
const hoverStart = ref<number | null>(null);

/*
 * Dragging on the strip: grab the pick to slide it, grab an edge to
 * stretch it, or sweep across empty track to draw a new one. A plain tap on
 * empty track moves the pick there, keeping its length.
 */
const SNAP = 15;
const trackEl = useTemplateRef<HTMLDivElement>('trackEl');
const dragging = ref<'move' | 'start' | 'end' | 'draw' | null>(null);

let stripDrag: {
    mode: 'move' | 'start' | 'end' | 'draw';
    rect: DOMRect;
    /** The strip's span when the drag began; frozen so it can't shift. */
    from: number;
    to: number;
    downX: number;
    grabAt: number;
    start: number;
    end: number;
    moved: boolean;
    touch: boolean;
} | null = null;

const snapTo = (minutes: number) => Math.round(minutes / SNAP) * SNAP;

function minuteAtX(x: number): number {
    const d = stripDrag!;

    return d.from + ((x - d.rect.left) / d.rect.width) * (d.to - d.from);
}

function onStripDown(
    event: PointerEvent,
    mode: 'move' | 'start' | 'end' | 'draw',
): void {
    if (event.button !== 0 || !trackEl.value) {
        return;
    }

    event.stopPropagation();

    // No text selection or focus jump while dragging with a mouse.
    if (event.pointerType === 'mouse') {
        event.preventDefault();
    }

    stripDrag = {
        mode,
        rect: trackEl.value.getBoundingClientRect(),
        from: track.value.from,
        to: track.value.to,
        downX: event.clientX,
        grabAt: 0,
        start: form.value.start,
        end: form.value.end,
        moved: false,
        touch: event.pointerType === 'touch',
    };
    stripDrag.grabAt = minuteAtX(event.clientX);
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    hoverStart.value = null;

    if (mode !== 'draw') {
        dragging.value = mode;
    }
}

function onStripMove(event: PointerEvent): void {
    const d = stripDrag;

    if (!d) {
        return;
    }

    if (!d.moved && Math.abs(event.clientX - d.downX) < 4) {
        return;
    }

    d.moved = true;
    dragging.value = d.mode;

    const at = minuteAtX(event.clientX);
    // Never earlier than now or opening, never past closing.
    const lo = Math.max(
        d.from,
        bounds.value.from,
        Math.ceil(pastUntil.value / SNAP) * SNAP,
    );
    const hi = Math.min(d.to, bounds.value.to);
    let { start, end } = d;

    if (d.mode === 'move') {
        const length = end - start;

        start = clamp(snapTo(d.start + at - d.grabAt), lo, hi - length);
        end = start + length;
    } else if (d.mode === 'start') {
        start = clamp(snapTo(at), lo, end - SNAP);
    } else if (d.mode === 'end') {
        end = clamp(snapTo(at), Math.max(start, lo) + SNAP, hi);
    } else {
        const anchor = clamp(snapTo(d.grabAt), lo, hi - SNAP);
        const here = clamp(snapTo(at), lo, hi);

        start = Math.min(anchor, here);
        end = Math.max(anchor + SNAP, here);
    }

    if (start !== form.value.start || end !== form.value.end) {
        form.value.start = start;
        form.value.end = end;
        timeTouched.value = true;

        // A detent per step under the finger, on phones that can buzz.
        if (d.touch) {
            navigator.vibrate?.(4);
        }
    }
}

function onStripUp(event: PointerEvent): void {
    const d = stripDrag;
    const at = d ? minuteAtX(event.clientX) : 0;

    stripDrag = null;
    dragging.value = null;

    // A tap on empty track: move the pick to that half hour.
    if (d && !d.moved && d.mode === 'draw') {
        const slot = trackSlots.value.find(
            (s) => at >= s.start && at < s.start + STEP,
        );

        if (slot?.free) {
            setStart(slot.start);
        }
    }
}

const selectionColor = computed(() => chosen.value?.color ?? null);

const busySummary = computed(() => {
    if (!info.value || blocked.value) {
        return '';
    }

    return busy.value.length === 0
        ? 'Free all day.'
        : `Booked ${busy.value.map((b) => `${clock(b.start)} to ${clock(b.end)}`).join(', ')}.`;
});

/* ---- Submit -------------------------------------------------------- */

/** Field -> element to focus when it holds the first error. */
const FIELD_TARGETS: [string, string][] = [
    ['resource_id', 'booking-resource'],
    ['starts_at', 'booking-start'],
    ['ends_at', 'booking-end'],
    ['title', 'booking-title'],
    ['purpose', 'booking-purpose'],
];

/** The people row an error points at, or the first row for the list. */
const peopleTarget = () => {
    const row = rowErrors(form.value.people, errors.value).findIndex(Boolean);

    if (row !== -1) {
        return `booking-people-${row}`;
    }

    return errors.value.people ? 'booking-people-0' : null;
};

async function focusFirstError(): Promise<void> {
    await nextTick();

    const hit = FIELD_TARGETS.find(([key]) => errors.value[key]);
    const target = hit?.[1] ?? peopleTarget();
    const el = target ? document.getElementById(target) : null;

    el?.focus({ preventScroll: true });
    el?.scrollIntoView({
        block: 'center',
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'auto'
            : 'smooth',
    });
}

/** The same checks the server runs, answered before the round trip. */
function validate(): Record<string, string[]> {
    const problems: Record<string, string[]> = {};

    if (form.value.resource_id === null) {
        problems.resource_id = ['Choose one to book.'];
    }

    if (blocked.value) {
        problems.starts_at = [
            `${blocked.value.title}. ${blocked.value.detail}`,
        ];
    } else if (timeIssue.value) {
        problems.starts_at = [timeIssue.value];
    }

    if (!form.value.title.trim()) {
        problems.title = ['Give the booking a title.'];
    }

    return {
        ...problems,
        ...peopleProblems(form.value.people, {
            capacity: chosen.value?.capacity,
            name: chosen.value?.name,
        }),
    };
}

/** A local Date for minutes into the chosen day (1440 = next midnight). */
const at = (minutes: number) =>
    new Date(
        day.value.getFullYear(),
        day.value.getMonth(),
        day.value.getDate(),
        0,
        minutes,
    );

async function submit(): Promise<void> {
    if (saving.value) {
        return;
    }

    const problems = validate();

    if (Object.keys(problems).length > 0) {
        errors.value = problems;
        void focusFirstError();

        return;
    }

    saving.value = true;
    errors.value = {};

    const startsAt = at(form.value.start).toISOString();

    try {
        const people = peopleToSend(form.value.people);
        const response = await api.post<{
            data: { id: number; reference: string; status: string };
        }>(bookingsApi.store().url, {
            resource_id: form.value.resource_id,
            title: form.value.title.trim(),
            starts_at: startsAt,
            ends_at: at(form.value.end).toISOString(),
            purpose: form.value.purpose.trim() || null,
            people,
        });

        receipt.value = {
            id: response.data.id,
            reference: response.data.reference,
            people: people.length,
            resource: chosen.value?.name ?? props.type.name,
            color: chosen.value?.color ?? null,
            title: form.value.title.trim(),
            day: day.value,
            start: form.value.start,
            end: form.value.end,
            // What the server decided, not what the type usually does.
            pending: response.data.status === 'pending',
        };
        emit('created', startsAt);
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value =
                Object.keys(error.errors).length > 0
                    ? error.errors
                    : { form: [error.message] };

            // The server saw a clash we did not: refresh the day, but leave
            // the picked time where it is so the message still makes sense.
            if (errors.value.starts_at) {
                timeTouched.value = true;
                void loadAvailability();
            }

            void focusFirstError();
        } else {
            errors.value = {
                form: [
                    'Could not reach the server. Check your connection and try again.',
                ],
            };
        }
    } finally {
        saving.value = false;
    }
}

/** Land focus on the first real choice instead of the close button. */
function onOpenAutoFocus(event: Event): void {
    event.preventDefault();

    const target =
        document.querySelector<HTMLElement>(
            '[data-booking-dialog] input[name="booking-resource"]:checked',
        ) ??
        document.getElementById('booking-resource') ??
        document.getElementById('booking-title');

    target?.focus({ preventScroll: true });
}

const typeIcon = computed(() => resourceTypeIcon(props.type));

const summary = computed(() => {
    if (!chosen.value) {
        return '';
    }

    const when = format(day.value, 'EEE, MMM d');

    return blocked.value
        ? `${chosen.value.name} · ${when}`
        : `${chosen.value.name} · ${when} · ${clock(form.value.start)}–${clock(form.value.end)}`;
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            data-booking-dialog
            :show-close-button="false"
            class="flex h-[min(92dvh,54rem)] flex-col gap-0 overflow-hidden rounded-[1.75rem] p-0 max-sm:top-auto max-sm:bottom-0 max-sm:max-w-full max-sm:translate-y-0 max-sm:rounded-b-none max-sm:data-[state=closed]:slide-out-to-bottom-10 max-sm:data-[state=open]:slide-in-from-bottom-10 sm:max-w-[38rem]"
            @open-auto-focus="onOpenAutoFocus"
        >
            <!-- ================= Confirmation ================= -->
            <div
                v-if="receipt"
                class="flex min-h-0 flex-1 flex-col items-center justify-center overflow-y-auto px-6 pt-10 pb-7 text-center"
            >
                <span
                    class="booking-pop grid size-12 place-items-center rounded-full bg-success/15 text-success"
                >
                    <Check
                        class="size-6"
                        :stroke-width="2.5"
                        aria-hidden="true"
                    />
                </span>
                <DialogTitle class="mt-4 text-xl font-semibold tracking-tight">
                    {{ receipt.pending ? 'Request sent' : 'You’re booked' }}
                </DialogTitle>
                <DialogDescription
                    class="mt-1.5 max-w-sm text-sm text-muted-foreground"
                >
                    {{
                        receipt.pending
                            ? 'An approver will review it. You’ll get a notification once they decide.'
                            : 'It’s on the calendar now.'
                    }}
                </DialogDescription>

                <!-- The confirmation reads like an admission ticket: the
                     booking in its room color, torn from a stub. -->
                <div
                    class="booking-rise mt-7 w-full max-w-sm drop-shadow-[0_14px_22px_rgb(20_30_40/0.12)]"
                >
                    <div class="booking-ticket text-left">
                        <div
                            class="px-5 pt-5 pb-6"
                            :style="{
                                background: eventWash(receipt.color),
                                color: eventInk(receipt.color),
                            }"
                        >
                            <p
                                class="flex justify-between gap-3 text-xs font-medium opacity-75"
                            >
                                <span class="truncate">{{
                                    receipt.resource
                                }}</span>
                                <span class="shrink-0 tabular-nums">{{
                                    receipt.reference
                                }}</span>
                            </p>
                            <p
                                class="mt-1 line-clamp-2 text-base leading-snug font-semibold"
                            >
                                {{ receipt.title }}
                            </p>
                            <p
                                class="mt-5 text-2xl font-semibold tracking-tight tabular-nums"
                            >
                                {{ clock(receipt.start) }}–{{
                                    clock(receipt.end)
                                }}
                            </p>
                            <p class="text-sm opacity-75">
                                {{ format(receipt.day, 'EEEE, MMMM d') }} ·
                                {{ receipt.people }}
                                {{ receipt.people === 1 ? 'person' : 'people' }}
                            </p>
                        </div>
                        <div
                            class="flex h-14 items-center justify-between border-t-2 border-dashed border-foreground/10 bg-muted px-5 text-sm"
                        >
                            <span
                                class="inline-flex items-center gap-2 font-medium"
                            >
                                <span
                                    class="size-2 rounded-full"
                                    :class="
                                        receipt.pending
                                            ? 'bg-warning'
                                            : 'bg-success'
                                    "
                                    aria-hidden="true"
                                />
                                {{
                                    receipt.pending
                                        ? 'Awaiting approval'
                                        : 'Confirmed'
                                }}
                            </span>
                            <span class="text-muted-foreground tabular-nums">
                                {{ duration(receipt.end - receipt.start) }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="mt-8 flex w-full max-w-sm gap-2">
                    <Button
                        variant="outline"
                        class="h-10 flex-1 rounded-full"
                        as-child
                    >
                        <Link :href="bookingsPage.show(receipt.id)">
                            Open booking
                        </Link>
                    </Button>
                    <Button
                        class="h-10 flex-1 rounded-full"
                        @click="open = false"
                    >
                        Done
                    </Button>
                </div>
            </div>

            <!-- ================= Booking form ================= -->
            <template v-else>
                <header class="flex items-start gap-3.5 px-6 pt-6 pb-5">
                    <span
                        class="grid size-11 shrink-0 place-items-center rounded-2xl bg-muted"
                    >
                        <component
                            :is="typeIcon"
                            class="size-5"
                            aria-hidden="true"
                        />
                    </span>
                    <div class="min-w-0 flex-1 pt-0.5">
                        <DialogTitle
                            class="text-lg leading-tight font-semibold tracking-tight"
                        >
                            New {{ type.name }} booking
                        </DialogTitle>
                        <DialogDescription
                            class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <component
                                    :is="
                                        type.requires_approval
                                            ? ShieldCheck
                                            : Zap
                                    "
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{
                                    type.requires_approval
                                        ? 'Needs approval'
                                        : 'Confirmed right away'
                                }}
                            </span>
                            <span
                                v-if="maxLength"
                                class="before:mr-2 before:content-['·']"
                            >
                                Up to {{ duration(maxLength) }}
                            </span>
                        </DialogDescription>
                    </div>
                    <DialogClose as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="-mt-1 -mr-2 shrink-0 rounded-full text-muted-foreground"
                            aria-label="Close"
                        >
                            <X class="size-5" />
                        </Button>
                    </DialogClose>
                </header>

                <div
                    v-if="restored"
                    class="mx-6 mb-4 flex items-center justify-between gap-3 rounded-xl bg-muted px-3.5 py-2 text-sm"
                >
                    <span class="text-muted-foreground">
                        Picked up where you left off.
                    </span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 font-medium hover:bg-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @click="startOver"
                    >
                        <RotateCcw class="size-3.5" aria-hidden="true" />
                        Start over
                    </button>
                </div>

                <!-- Nothing bookable yet -->
                <div
                    v-if="resources.length === 0"
                    class="mx-6 mb-6 flex flex-col items-center gap-2 rounded-2xl border border-dashed px-6 py-10 text-center"
                >
                    <component
                        :is="typeIcon"
                        class="size-7 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <p class="font-medium">
                        Nothing in {{ type.name }} to book yet
                    </p>
                    <p class="max-w-xs text-sm text-muted-foreground">
                        An administrator can add one under Settings, Resources.
                    </p>
                </div>

                <form
                    v-else
                    id="booking-form"
                    class="grid min-h-0 flex-1 [scrollbar-width:thin] grid-cols-[minmax(0,1fr)] gap-7 overflow-y-auto overscroll-contain px-6 pb-6"
                    novalidate
                    @submit.prevent="submit"
                >
                    <!-- ---------- Which one ---------- -->
                    <fieldset
                        class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-2.5"
                    >
                        <legend class="mb-2.5 text-sm font-medium">
                            {{ type.name }}
                        </legend>
                        <div
                            class="grid gap-2"
                            :class="resources.length > 1 && 'sm:grid-cols-2'"
                        >
                            <label
                                v-for="(resource, i) in resources"
                                :key="resource.id"
                                class="group relative flex min-h-16 cursor-pointer items-center gap-3 rounded-2xl border bg-background/50 px-3.5 py-3 transition-colors duration-150 hover:bg-muted/70 has-[:checked]:border-[color-mix(in_oklch,var(--room)_55%,var(--foreground))] has-[:checked]:bg-[color-mix(in_oklch,var(--room)_22%,transparent)] has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2 has-[:focus-visible]:ring-offset-background dark:bg-transparent"
                                :style="{
                                    '--room':
                                        resource.color ??
                                        'var(--muted-foreground)',
                                }"
                            >
                                <input
                                    :id="
                                        i === 0 ? 'booking-resource' : undefined
                                    "
                                    v-model="form.resource_id"
                                    type="radio"
                                    name="booking-resource"
                                    :value="resource.id"
                                    class="sr-only"
                                    :aria-invalid="!!fieldError('resource_id')"
                                />
                                <span
                                    class="size-3 shrink-0 rounded-full ring-4 ring-[color-mix(in_oklch,var(--room)_30%,transparent)]"
                                    :style="{ background: 'var(--room)' }"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block text-sm leading-snug font-medium text-pretty"
                                    >
                                        {{ resource.name }}
                                    </span>
                                    <span
                                        v-if="
                                            resource.location ||
                                            resource.capacity
                                        "
                                        class="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground"
                                    >
                                        <span
                                            v-if="resource.location"
                                            class="inline-flex min-w-0 items-center gap-1"
                                        >
                                            <MapPin
                                                class="size-3 shrink-0"
                                                aria-hidden="true"
                                            />
                                            <span class="truncate">
                                                {{ resource.location }}
                                            </span>
                                        </span>
                                        <span
                                            v-if="resource.capacity"
                                            class="inline-flex shrink-0 items-center gap-1"
                                        >
                                            <Users
                                                class="size-3"
                                                aria-hidden="true"
                                            />
                                            {{ resource.capacity }}
                                        </span>
                                    </span>
                                </span>
                                <span
                                    class="grid size-5 shrink-0 scale-75 place-items-center rounded-full bg-foreground text-background opacity-0 transition-[opacity,scale] duration-200 ease-out group-has-[:checked]:scale-100 group-has-[:checked]:opacity-100"
                                    aria-hidden="true"
                                >
                                    <Check class="size-3" :stroke-width="3" />
                                </span>
                            </label>
                        </div>
                        <p
                            v-if="fieldError('resource_id')"
                            class="text-sm text-destructive"
                            role="alert"
                        >
                            {{ fieldError('resource_id') }}
                        </p>
                    </fieldset>

                    <!-- ---------- When ---------- -->
                    <section
                        class="grid grid-cols-[minmax(0,1fr)] gap-3"
                        aria-labelledby="booking-when-label"
                    >
                        <h3 id="booking-when-label" class="text-sm font-medium">
                            Date &amp; time
                        </h3>

                        <!-- The day strip: the day at a glance, and the
                             quickest way to move the booking. -->
                        <div
                            class="rounded-2xl bg-muted/60 p-2 pb-3 dark:bg-muted/40"
                        >
                            <div class="flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="rounded-full"
                                    :disabled="!canGoBack"
                                    aria-label="Previous day"
                                    @click="shiftDay(-1)"
                                >
                                    <ChevronLeft class="size-5" />
                                </Button>
                                <div class="relative min-w-0 flex-1">
                                    <button
                                        type="button"
                                        class="flex h-9 w-full items-center justify-center gap-2 rounded-full px-3 text-sm font-medium transition-colors hover:bg-background/80 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        :aria-label="`Date: ${format(day, 'EEEE, MMMM d')}. Choose another date`"
                                        @click="pickDate"
                                    >
                                        <CalendarDays
                                            class="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <span class="truncate max-sm:hidden">
                                            {{ format(day, 'EEEE, MMMM d') }}
                                        </span>
                                        <span class="truncate sm:hidden">
                                            {{ format(day, 'EEE, MMM d') }}
                                        </span>
                                        <span
                                            v-if="dayTag"
                                            class="shrink-0 rounded-full bg-background px-2 py-0.5 text-xs font-normal text-muted-foreground"
                                        >
                                            {{ dayTag }}
                                        </span>
                                    </button>
                                    <input
                                        ref="dateInput"
                                        type="date"
                                        :value="form.date"
                                        :min="todayKey()"
                                        class="pointer-events-none absolute bottom-0 left-1/2 size-px opacity-0"
                                        tabindex="-1"
                                        aria-hidden="true"
                                        @change="onDatePicked"
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="rounded-full"
                                    aria-label="Next day"
                                    @click="shiftDay(1)"
                                >
                                    <ChevronRight class="size-5" />
                                </Button>
                            </div>

                            <!-- One fixed-height stage under the day
                                 picker, so switching room or day never
                                 resizes the dialog. -->
                            <div class="grid min-h-[6.75rem] content-center">
                                <!-- Closed, holiday, or the day is over -->
                                <div
                                    v-if="blocked"
                                    class="mx-1 flex items-center gap-3 rounded-xl bg-background/70 px-4 py-3.5"
                                    role="status"
                                >
                                    <CalendarOff
                                        class="size-5 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <div class="min-w-0 flex-1 text-sm">
                                        <p class="font-medium">
                                            {{ blocked.title }}
                                        </p>
                                        <p class="text-muted-foreground">
                                            {{ blocked.detail }}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="shrink-0 rounded-full"
                                        @click="shiftDay(1)"
                                    >
                                        Next day
                                    </Button>
                                </div>

                                <!-- Could not load the day -->
                                <div
                                    v-else-if="loadFailed"
                                    class="mx-1 flex items-center gap-3 rounded-xl bg-background/70 px-4 py-3 text-sm"
                                    role="alert"
                                >
                                    <CircleAlert
                                        class="size-4 shrink-0 text-destructive"
                                        aria-hidden="true"
                                    />
                                    <span class="min-w-0 flex-1">
                                        Couldn’t load this day’s bookings.
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="shrink-0 rounded-full"
                                        @click="loadAvailability"
                                    >
                                        Retry
                                    </Button>
                                </div>

                                <!-- Pick something first -->
                                <p
                                    v-else-if="form.resource_id === null"
                                    class="mx-1 rounded-xl border border-dashed border-foreground/15 px-4 py-6 text-center text-sm text-muted-foreground"
                                >
                                    Choose one above to see when it’s free.
                                </p>

                                <div v-else class="px-2 pt-1">
                                    <!-- Pointer-only helper: the Starts and
                                         Ends wheels below do the same job
                                         for keyboards and screen readers. -->
                                    <div
                                        ref="trackEl"
                                        class="relative h-11 touch-pan-y overflow-hidden rounded-xl bg-background shadow-[inset_0_0_0_1px_var(--border)] select-none dark:bg-background/50"
                                        aria-hidden="true"
                                        @mouseleave="hoverStart = null"
                                        @pointerdown="
                                            (e) => onStripDown(e, 'draw')
                                        "
                                        @pointermove="onStripMove"
                                        @pointerup="onStripUp"
                                        @pointercancel="onStripUp"
                                    >
                                        <!-- Clickable half-hour slots -->
                                        <div class="absolute inset-0 flex">
                                            <div
                                                v-for="slot in trackSlots"
                                                :key="slot.start"
                                                class="h-full flex-1 border-foreground/[0.07]"
                                                :class="[
                                                    slot.start % 60 === 0 &&
                                                        slot.start !==
                                                            track.from &&
                                                        'border-l',
                                                    slot.free
                                                        ? 'cursor-pointer'
                                                        : 'cursor-not-allowed',
                                                ]"
                                                @mouseenter="
                                                    hoverStart =
                                                        slot.free && !dragging
                                                            ? slot.start
                                                            : null
                                                "
                                            />
                                        </div>

                                        <!-- Already gone today -->
                                        <div
                                            v-if="pastUntil > track.from"
                                            class="booking-past pointer-events-none absolute inset-y-0 left-0"
                                            :style="{
                                                width: `${pct(pastUntil)}%`,
                                            }"
                                        />

                                        <!-- Taken -->
                                        <div
                                            v-for="(b, i) in busy"
                                            :key="i"
                                            class="booking-busy pointer-events-none absolute inset-y-1 rounded-lg"
                                            :class="b.pending && 'is-pending'"
                                            :style="span(b.start, b.end)"
                                        />

                                        <!-- Hover preview -->
                                        <div
                                            v-if="
                                                hoverStart !== null &&
                                                hoverStart !== form.start
                                            "
                                            class="pointer-events-none absolute inset-y-1 rounded-lg border-2 border-dashed border-foreground/25"
                                            :style="
                                                span(
                                                    hoverStart,
                                                    hoverStart +
                                                        (form.end - form.start),
                                                )
                                            "
                                        />

                                        <!-- The pick, in the room's color:
                                             grab it to slide, grab an edge
                                             to stretch. -->
                                        <div
                                            class="group/pick absolute inset-y-1 touch-none"
                                            :class="
                                                dragging === 'move'
                                                    ? 'cursor-grabbing'
                                                    : 'cursor-grab'
                                            "
                                            :style="span(form.start, form.end)"
                                            @pointerdown="
                                                (e) => onStripDown(e, 'move')
                                            "
                                        >
                                            <!-- Its label only shows when
                                                 it fits. -->
                                            <div
                                                class="@container flex size-full items-center justify-center overflow-hidden rounded-lg px-1 text-[11px] font-semibold whitespace-nowrap tabular-nums transition-[box-shadow,scale] duration-150 ease-out"
                                                :class="[
                                                    timeIssue
                                                        ? 'ring-2 ring-destructive'
                                                        : 'ring-1 ring-foreground/10',
                                                    dragging
                                                        ? 'scale-y-110 shadow-lg'
                                                        : 'shadow-sm',
                                                ]"
                                                :style="{
                                                    background:
                                                        eventWash(
                                                            selectionColor,
                                                        ),
                                                    color: eventInk(
                                                        selectionColor,
                                                    ),
                                                }"
                                            >
                                                <span
                                                    class="hidden @min-[2.25rem]:inline @min-[4.5rem]:hidden"
                                                >
                                                    {{ shortTime(form.start) }}
                                                </span>
                                                <span
                                                    class="hidden @min-[4.5rem]:inline"
                                                >
                                                    {{
                                                        shortRange(
                                                            form.start,
                                                            form.end,
                                                        )
                                                    }}
                                                </span>
                                            </div>

                                            <!-- Edge grips sit mostly outside
                                                 the pick, so even a short
                                                 booking stays grabbable to
                                                 slide. -->
                                            <div
                                                v-for="edge in [
                                                    'start',
                                                    'end',
                                                ] as const"
                                                :key="edge"
                                                class="absolute inset-y-0 flex w-3.5 cursor-ew-resize items-center"
                                                :class="
                                                    edge === 'start'
                                                        ? '-left-2.5 justify-end'
                                                        : '-right-2.5 justify-start'
                                                "
                                                @pointerdown.stop="
                                                    (e) => onStripDown(e, edge)
                                                "
                                            >
                                                <span
                                                    class="h-4 w-1 rounded-full transition-opacity duration-150"
                                                    :class="
                                                        dragging === edge
                                                            ? 'opacity-60'
                                                            : 'opacity-0 group-hover/pick:opacity-45 pointer-coarse:opacity-45'
                                                    "
                                                    :style="{
                                                        background:
                                                            eventInk(
                                                                selectionColor,
                                                            ),
                                                    }"
                                                />
                                            </div>
                                        </div>

                                        <!-- Now -->
                                        <div
                                            v-if="
                                                pastUntil > track.from &&
                                                pastUntil < track.to
                                            "
                                            class="pointer-events-none absolute inset-y-0 w-0.5 -translate-x-1/2 bg-foreground"
                                            :style="{
                                                left: `${pct(pastUntil)}%`,
                                            }"
                                        />

                                        <!-- Loading the day: a soft shimmer
                                             over the strip, not a swap. -->
                                        <div
                                            v-if="loading"
                                            class="pointer-events-none absolute inset-0 animate-pulse bg-muted/70"
                                        />
                                    </div>

                                    <!-- Hour marks -->
                                    <div
                                        class="relative mt-1.5 h-4 text-[11px] text-muted-foreground tabular-nums"
                                        aria-hidden="true"
                                    >
                                        <span
                                            v-for="(tick, i) in ticks"
                                            :key="tick.minutes"
                                            class="absolute top-0"
                                            :class="[
                                                i === 0
                                                    ? ''
                                                    : i === ticks.length - 1
                                                      ? '-translate-x-full'
                                                      : '-translate-x-1/2',
                                                tick.minor && 'max-sm:hidden',
                                            ]"
                                            :style="{
                                                left: `${pct(tick.minutes)}%`,
                                            }"
                                        >
                                            {{ tick.label }}
                                        </span>
                                    </div>

                                    <div
                                        class="mt-2 flex h-4 flex-wrap items-center gap-x-4 text-xs text-muted-foreground"
                                        aria-hidden="true"
                                    >
                                        <template v-if="loading">
                                            Checking availability…
                                        </template>
                                        <template v-else-if="busy.length === 0">
                                            Free all day
                                        </template>
                                        <template v-else>
                                            <span
                                                v-if="
                                                    busy.some((b) => !b.pending)
                                                "
                                                class="inline-flex items-center gap-1.5"
                                            >
                                                <span
                                                    class="booking-busy inline-block h-2.5 w-4 rounded-sm"
                                                />
                                                Booked
                                            </span>
                                            <span
                                                v-if="
                                                    busy.some((b) => b.pending)
                                                "
                                                class="inline-flex items-center gap-1.5"
                                            >
                                                <span
                                                    class="booking-busy is-pending inline-block h-2.5 w-4 rounded-sm"
                                                />
                                                Awaiting approval
                                            </span>
                                        </template>
                                    </div>
                                    <p class="sr-only" aria-live="polite">
                                        {{ busySummary }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Times: tap one to turn its wheel. -->
                        <div
                            class="overflow-hidden rounded-2xl border bg-background/60 transition-opacity dark:bg-transparent"
                            :class="blocked && 'pointer-events-none opacity-50'"
                        >
                            <div
                                class="flex min-h-13 items-center gap-3 px-4 py-2"
                            >
                                <span class="flex-1 text-sm font-medium">
                                    Starts
                                </span>
                                <button
                                    id="booking-start"
                                    type="button"
                                    class="h-9 rounded-full px-4 text-[15px] tabular-nums transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed"
                                    :class="
                                        openWheel === 'start'
                                            ? 'bg-foreground text-background'
                                            : 'bg-muted hover:bg-foreground/10'
                                    "
                                    :aria-label="`Start time, ${pillTime(form.start)}`"
                                    :aria-expanded="openWheel === 'start'"
                                    aria-controls="booking-start-wheel"
                                    :aria-invalid="!!whenError"
                                    :disabled="!!blocked"
                                    @click="toggleWheel('start')"
                                >
                                    {{ pillTime(form.start) }}
                                </button>
                            </div>
                            <div
                                v-if="openWheel === 'start'"
                                id="booking-start-wheel"
                                class="booking-wheel-in border-t px-4 py-3"
                            >
                                <TimeWheel
                                    v-model="startModel"
                                    label="Start"
                                    :unavailable="startUnavailable"
                                />
                            </div>

                            <div
                                class="flex min-h-13 items-center gap-3 border-t px-4 py-2"
                            >
                                <span class="flex-1 text-sm font-medium">
                                    Ends
                                </span>
                                <span
                                    v-if="form.end > form.start"
                                    class="text-sm text-muted-foreground tabular-nums"
                                >
                                    {{ duration(form.end - form.start) }}
                                </span>
                                <button
                                    id="booking-end"
                                    type="button"
                                    class="h-9 rounded-full px-4 text-[15px] tabular-nums transition-colors duration-150 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed"
                                    :class="
                                        openWheel === 'end'
                                            ? 'bg-foreground text-background'
                                            : 'bg-muted hover:bg-foreground/10'
                                    "
                                    :aria-label="`End time, ${pillTime(form.end)}`"
                                    :aria-expanded="openWheel === 'end'"
                                    aria-controls="booking-end-wheel"
                                    :aria-invalid="!!whenError"
                                    :disabled="!!blocked"
                                    @click="toggleWheel('end')"
                                >
                                    {{ pillTime(form.end) }}
                                </button>
                            </div>
                            <div
                                v-if="openWheel === 'end'"
                                id="booking-end-wheel"
                                class="booking-wheel-in border-t px-4 py-3"
                            >
                                <TimeWheel
                                    v-model="endModel"
                                    label="End"
                                    :unavailable="endUnavailable"
                                />
                            </div>
                        </div>

                        <!-- Always one line tall, so the form below never
                             hops as the message changes. -->
                        <p
                            class="flex min-h-5 items-start gap-2 text-sm"
                            :class="
                                whenError || timeIssue
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                            aria-live="polite"
                        >
                            <template
                                v-if="blocked || form.resource_id === null"
                            />
                            <template v-else-if="whenError || timeIssue">
                                <CircleAlert
                                    class="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                {{ whenError ?? timeIssue }}
                            </template>
                            <template v-else-if="info">
                                <CircleCheck
                                    class="mt-0.5 size-4 shrink-0 text-success"
                                    aria-hidden="true"
                                />
                                <span>
                                    <span class="font-medium text-foreground">
                                        Free
                                    </span>
                                    for {{ duration(form.end - form.start) }}
                                </span>
                            </template>
                        </p>
                    </section>

                    <!-- ---------- Details ---------- -->
                    <section
                        class="grid gap-4"
                        aria-labelledby="booking-details-label"
                    >
                        <h3
                            id="booking-details-label"
                            class="text-sm font-medium"
                        >
                            Details
                        </h3>

                        <div class="grid gap-4">
                            <div class="grid content-start gap-1.5">
                                <Label for="booking-title">Title</Label>
                                <Input
                                    id="booking-title"
                                    v-model="form.title"
                                    class="h-10 rounded-xl"
                                    maxlength="255"
                                    placeholder="e.g. Budget review with Finance"
                                    autocomplete="off"
                                    :aria-invalid="!!fieldError('title')"
                                    :aria-describedby="
                                        fieldError('title')
                                            ? 'booking-title-error'
                                            : undefined
                                    "
                                />
                                <p
                                    v-if="fieldError('title')"
                                    id="booking-title-error"
                                    class="text-sm text-destructive"
                                >
                                    {{ fieldError('title') }}
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="booking-purpose" class="gap-1.5">
                                Purpose
                                <span class="font-normal text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Textarea
                                id="booking-purpose"
                                v-model="form.purpose"
                                rows="3"
                                maxlength="2000"
                                class="min-h-20 resize-none rounded-xl"
                                :placeholder="
                                    type.requires_approval
                                        ? 'What it’s for, so the approver can decide quickly'
                                        : 'What it’s for'
                                "
                            />
                            <p
                                v-if="fieldError('purpose')"
                                class="text-sm text-destructive"
                            >
                                {{ fieldError('purpose') }}
                            </p>
                        </div>
                    </section>

                    <!-- ---------- People ---------- -->
                    <PeopleEditor
                        id="booking-people"
                        v-model="form.people"
                        :noun="personNoun"
                        :capacity="chosen?.capacity"
                        :resource-name="chosen?.name"
                        :errors="errors"
                        @clear="clearPeopleErrors"
                    />
                </form>

                <footer
                    class="flex items-center gap-3 border-t px-6 py-4 max-sm:pb-[max(1rem,env(safe-area-inset-bottom))]"
                >
                    <p
                        v-if="fieldError('form')"
                        class="flex min-w-0 flex-1 items-start gap-2 text-sm text-destructive"
                        role="alert"
                    >
                        <CircleAlert
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {{ fieldError('form') }}
                    </p>
                    <p
                        v-else
                        class="min-w-0 flex-1 truncate text-sm text-muted-foreground max-sm:hidden"
                    >
                        {{ summary }}
                    </p>
                    <template v-if="resources.length > 0">
                        <Button
                            type="button"
                            variant="ghost"
                            class="h-10 rounded-full max-sm:flex-1"
                            @click="open = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form="booking-form"
                            class="h-10 rounded-full px-5 max-sm:flex-[2]"
                            :disabled="saving"
                            data-test="booking-submit"
                        >
                            <Spinner v-if="saving" />
                            {{
                                type.requires_approval
                                    ? 'Request booking'
                                    : 'Book now'
                            }}
                        </Button>
                    </template>
                    <Button
                        v-else
                        type="button"
                        variant="outline"
                        class="h-10 rounded-full"
                        @click="open = false"
                    >
                        Close
                    </Button>
                </footer>
            </template>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
/* Taken time: hatched, so it reads as "not available" without color. */
.booking-busy {
    background:
        repeating-linear-gradient(
            -45deg,
            color-mix(in oklch, var(--foreground) 22%, transparent) 0 2px,
            transparent 2px 7px
        ),
        color-mix(in oklch, var(--foreground) 9%, transparent);
}

.booking-busy.is-pending {
    background: repeating-linear-gradient(
        -45deg,
        color-mix(in oklch, var(--foreground) 14%, transparent) 0 2px,
        transparent 2px 7px
    );
    box-shadow: inset 0 0 0 1px
        color-mix(in oklch, var(--foreground) 18%, transparent);
}

/* Earlier today: faded, not hatched, so it doesn't read as a booking. */
.booking-past {
    background: color-mix(in oklch, var(--muted) 75%, transparent);
}

/* Ticket: two round notches bitten out where the stub tears off. The stub
   is h-14 (3.5rem), so the notches sit on its top edge. */
.booking-ticket {
    --notch: 0.7rem;
    --cut: calc(100% - 3.5rem);
    border-radius: 1.25rem;
    overflow: hidden;
    mask:
        radial-gradient(
                circle var(--notch) at 0 var(--cut),
                transparent 98%,
                black
            )
            left / 51% 100% no-repeat,
        radial-gradient(
                circle var(--notch) at 100% var(--cut),
                transparent 98%,
                black
            )
            right / 51% 100% no-repeat;
}

@media (prefers-reduced-motion: no-preference) {
    .booking-pop {
        animation: booking-pop 0.45s cubic-bezier(0.16, 1, 0.3, 1) both;
    }

    .booking-rise {
        animation: booking-rise 0.5s cubic-bezier(0.16, 1, 0.3, 1) 0.08s both;
    }

    .booking-wheel-in {
        animation: booking-rise 0.28s cubic-bezier(0.16, 1, 0.3, 1) both;
    }
}

@keyframes booking-pop {
    from {
        opacity: 0;
        transform: scale(0.6);
    }
}

@keyframes booking-rise {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
}
</style>
