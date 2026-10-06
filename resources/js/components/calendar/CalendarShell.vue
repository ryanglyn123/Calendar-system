<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChevronRight, Plus, Triangle } from '@lucide/vue';
import {
    defaultDocument,
    defaultWindow,
    useEventListener,
    useIntervalFn,
    watchDebounced,
} from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ResourceSchedule from '@/components/booking/ResourceSchedule.vue';
import type { BookableResource } from '@/components/booking/ResourceSchedule.vue';
import AgendaView from '@/components/calendar/AgendaView.vue';
import CalendarListPanel from '@/components/calendar/CalendarListPanel.vue';
import CalendarToolbar from '@/components/calendar/CalendarToolbar.vue';
import DayOverflowDialog from '@/components/calendar/DayOverflowDialog.vue';
import EventEditorDialog from '@/components/calendar/EventEditorDialog.vue';
import type { EventDraft } from '@/components/calendar/EventEditorDialog.vue';
import EventPeek from '@/components/calendar/EventPeek.vue';
import EventQuickView from '@/components/calendar/EventQuickView.vue';
import MiniMonth from '@/components/calendar/MiniMonth.vue';
import MonthView from '@/components/calendar/MonthView.vue';
import QuickCreateDialog from '@/components/calendar/QuickCreateDialog.vue';
import type { QuickCreateTarget } from '@/components/calendar/QuickCreateDialog.vue';
import RecurrenceScopeDialog from '@/components/calendar/RecurrenceScopeDialog.vue';
import type { RecurrenceScope } from '@/components/calendar/RecurrenceScopeDialog.vue';
import TimeGridView from '@/components/calendar/TimeGridView.vue';
import YearView from '@/components/calendar/YearView.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { useConfirm } from '@/composables/useConfirm';
import { api, ApiError } from '@/lib/api';
import { isPastDay } from '@/lib/calendarMath';
import eventCategoriesApi from '@/routes/api/event-categories';
import { destroy as eventsDestroy } from '@/routes/api/events';
import { useCalendarGridStore } from '@/stores/calendarGrid';
import { useCalendarListStore } from '@/stores/calendarList';
import type {
    CalendarItem,
    CalendarPanelGroup,
    CalendarScope,
    EventOccurrence,
    EventTypeItem,
} from '@/types/calendar';

const props = defineProps<{
    eventTypes: EventTypeItem[];
    weekStartsOn: 0 | 1;
    initialCalendars?: CalendarItem[];
    initialEvents?: EventOccurrence[];
    /** Narrows the shell to a page scope (public calendar, resource section). */
    scope?: CalendarScope;
    /** Which calendar groups the side panel renders, with their labels. */
    panelGroups?: CalendarPanelGroup[];
    /**
     * A booking section's rooms or vehicles. With these the shell drops
     * its side panel and toolbar (the page brings its own) and draws day
     * and week as one row per resource.
     */
    bookingResources?: BookableResource[];
    bookingNouns?: { one: string; many: string };
}>();

const emit = defineEmits<{
    /**
     * A date cell was picked in a scope the shell can't create events in
     * (resource sections). The page turns this into a resource booking.
     */
    createBooking: [start: Date, end: Date | null, resourceId?: number];
    /** A booking was decided or cancelled from the popup. */
    bookingChanged: [];
}>();

const grid = useCalendarGridStore();
const list = useCalendarListStore();
const { confirm } = useConfirm();

grid.weekStartsOn = props.weekStartsOn;

// The scope must be in place before priming: window caches are keyed by it
// and list refreshes re-request the same subset.
grid.setScope(props.scope ?? null);
list.setScope(props.scope ?? null);

// Server-inlined data: the grid and sidebar render full on first paint,
// with no follow-up API round trips.
if (props.initialCalendars) {
    list.prime(props.initialCalendars);
}

// The server span is built around "now", so prime around today; if the
// user left the grid focused elsewhere, onMounted fetches that instead.
if (props.initialEvents) {
    grid.primeAround(new Date(), props.initialEvents);
}

onMounted(() => {
    void list.load();
    void grid.ensureWindowsAround(grid.focusDate);
});

/**
 * Whether the user can create events anywhere in this scope. Resource
 * calendars are never writable (bookings own their events), and the public
 * calendar needs the org-events permission.
 */
const canCreate = computed(() => list.writableCalendars.length > 0);

/* Types for the editor, including the user's private categories; ones added
   from the Create menu join here without a page reload. */
const types = ref<EventTypeItem[]>([...props.eventTypes]);
const newCategory = ref('');
const categoryError = ref('');
let addingCategory = false;

const addCategory = async () => {
    const name = newCategory.value.trim();

    if (!name || addingCategory) {
        return;
    }

    addingCategory = true;
    categoryError.value = '';

    try {
        const { data } = await api.post<{ data: EventTypeItem }>(
            eventCategoriesApi.store().url,
            { name },
        );
        types.value.push(data);
        newCategory.value = '';
    } catch (error) {
        categoryError.value =
            error instanceof ApiError
                ? (error.errors.name?.[0] ?? error.message)
                : 'Could not add the category.';
    } finally {
        addingCategory = false;
    }
};

// Keys go to the field, not the menu's typeahead; Escape still closes it.
const onCategoryKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        return;
    }

    e.stopPropagation();

    if (e.key === 'Enter') {
        e.preventDefault();
        void addCategory();
    }
};

const quickViewTarget = ref<EventOccurrence | null>(null);
/* What the card shows. It outlives quickViewTarget by the card's exit, so
   the card can shrink back into its pill instead of vanishing. */
const quickViewShown = ref<EventOccurrence | null>(null);

watch(quickViewTarget, (occurrence) => {
    if (occurrence) {
        quickViewShown.value = occurrence;

        return;
    }

    // ponytail: 200ms covers the 150ms exit; lengthen both together.
    window.setTimeout(() => {
        if (!quickViewTarget.value) {
            quickViewShown.value = null;
        }
    }, 200);
});
/** Centre of the pill that opened the card, so the card grows out of it. */
const quickViewOrigin = ref<{ x: number; y: number } | null>(null);
let pillClicked: { x: number; y: number } | null = null;

// Capture runs before the views' own click handlers emit `open`.
useEventListener(
    defaultDocument,
    'click',
    (event) => {
        const pill = (event.target as Element | null)?.closest?.(
            '[data-event]',
        );
        const box = pill?.getBoundingClientRect();

        pillClicked = box
            ? { x: box.left + box.width / 2, y: box.top + box.height / 2 }
            : null;
    },
    { capture: true },
);

const editorOpen = ref(false);
const editorTarget = ref<EventOccurrence | null>(null);
const editorInitialStart = ref<Date | null>(null);
const editorInitialEnd = ref<Date | null>(null);
const editorDraft = ref<EventDraft | null>(null);

const overflowDay = ref<Date | null>(null);
const overflowItems = ref<EventOccurrence[]>([]);

const openQuickView = (occurrence: EventOccurrence) => {
    quickViewOrigin.value = pillClicked;
    quickViewTarget.value = occurrence;
};

/** Lets the page open an occurrence's card, e.g. from its review list. */
defineExpose({
    openOccurrence: (occurrence: EventOccurrence) => {
        pillClicked = null;
        openQuickView(occurrence);
    },
});

/**
 * Reload the windows around the focused date without blanking the grid,
 * then swap an open popup to the fresh copy of its occurrence (or close it
 * if the viewer can no longer see it). Bookings change under other people's
 * hands, so this runs after every decision, when the tab comes back into
 * view, and once a minute while it is visible.
 */
let lastRefresh = Date.now();

const refresh = async (around: Date = grid.focusDate) => {
    lastRefresh = Date.now();

    try {
        await grid.invalidateAround(around);
    } catch {
        return; // the last good data stays on screen; the next tick retries
    }

    const open = quickViewTarget.value;

    if (open) {
        quickViewTarget.value =
            grid
                .occurrencesBetween(
                    new Date(open.starts_at),
                    new Date(open.ends_at),
                )
                .find((o) => o.occurrence_id === open.occurrence_id) ?? null;
    }
};

const onBookingChanged = () => {
    emit('bookingChanged');

    return refresh(
        quickViewTarget.value
            ? new Date(quickViewTarget.value.starts_at)
            : grid.focusDate,
    );
};

useIntervalFn(() => {
    if (document.visibilityState === 'visible') {
        void refresh();
    }
}, 60_000);

// Coming back to the tab after a while: catch up before the user acts.
const refreshIfIdle = () => {
    if (
        document.visibilityState === 'visible' &&
        Date.now() - lastRefresh > 15_000
    ) {
        void refresh();
    }
};

useEventListener(defaultDocument, 'visibilitychange', refreshIfIdle);
useEventListener(defaultWindow, 'focus', refreshIfIdle);

// The notification poll saw booking news (a decision, a new request).
useEventListener(defaultWindow, 'bookings:changed', () => void refresh());

const openCreate = (
    start?: Date,
    end?: Date,
    draft: EventDraft | null = null,
) => {
    if (!canCreate.value) {
        // Resource sections can't create events directly; a picked date
        // becomes a booking request handled by the page.
        if (props.scope?.resourceTypeId != null && start) {
            emit('createBooking', start, end ?? null);
        }

        return;
    }

    editorTarget.value = null;
    editorInitialStart.value = start ?? null;
    editorInitialEnd.value = end ?? null;
    editorDraft.value = draft;
    editorOpen.value = true;
};

// Google-style quick create: one centred card for the Create button and for
// clicks on the grid; "More options" hands over to the full editor.
const quickCreate = ref<QuickCreateTarget | null>(null);

/** Create: today, from the next half hour, for an hour. Too late in the day
 *  for that, and it starts as an all-day event today. Either way editable. */
const openNew = (draft?: EventDraft) => {
    if (!canCreate.value) {
        return;
    }

    const now = new Date();
    const start = new Date(now);
    start.setSeconds(0, 0);
    start.setMinutes(Math.floor(now.getMinutes() / 30) * 30 + 30);
    const end = new Date(start.getTime() + 3600_000);

    quickCreate.value =
        end.getDate() === now.getDate()
            ? { start, end, allDay: false, draft }
            : { start: now, end: null, allDay: true, draft };
};

const openQuickCreate = (start: Date, end?: Date, allDay = false) => {
    if (!canCreate.value) {
        // Booking request; past slots open nothing since bookings can't be in the past.
        if (!isPastDay(start)) {
            openCreate(start, end);
        }

        return;
    }

    quickCreate.value = { start, end: end ?? null, allDay };
};

const moreOptions = (draft: EventDraft) => {
    const target = quickCreate.value;
    quickCreate.value = null;
    openCreate(target?.start, target?.end ?? undefined, draft);
};

const openEdit = (occurrence: EventOccurrence) => {
    quickViewTarget.value = null;
    editorTarget.value = occurrence;
    editorInitialStart.value = null;
    editorInitialEnd.value = null;
    editorOpen.value = true;
};

const showDayOverflow = (day: Date, occurrences: EventOccurrence[]) => {
    overflowDay.value = day;
    overflowItems.value = occurrences;
};

/** Pending recurring operation awaiting a scope choice. */
const scopeAction = ref<'edit' | 'delete'>('edit');
const scopeResolver = ref<((scope: RecurrenceScope | null) => void) | null>(
    null,
);

const askScope = (
    action: 'edit' | 'delete',
): Promise<RecurrenceScope | null> => {
    scopeAction.value = action;

    return new Promise((resolve) => {
        scopeResolver.value = resolve;
    });
};

const settleScope = (scope: RecurrenceScope | null) => {
    scopeResolver.value?.(scope);
    scopeResolver.value = null;
};

const moveRecurring = async (
    occurrence: EventOccurrence,
    start: Date,
    end: Date,
) => {
    const scope = await askScope('edit');

    if (scope === null) {
        return;
    }

    try {
        await grid.moveOccurrenceScoped(occurrence, scope, start, end);
        toast.success('Event rescheduled.');
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'Could not reschedule the event.',
        );
    }
};

const removeEvent = async (occurrence: EventOccurrence) => {
    if (occurrence.is_recurring) {
        const scope = await askScope('delete');

        if (scope === null) {
            return;
        }

        try {
            await grid.deleteOccurrenceScoped(occurrence, scope);
            quickViewTarget.value = null;
            toast.success('Event deleted.');
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Could not delete the event.',
            );
        }

        return;
    }

    const confirmed = await confirm({
        title: `Delete “${occurrence.title}”?`,
        description: 'The event is removed from the calendar for everyone.',
        confirmLabel: 'Delete event',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    try {
        await api.delete(eventsDestroy(occurrence.event_id).url);

        grid.removeEvent(occurrence.event_id);
        quickViewTarget.value = null;
        toast.success('Event deleted.');
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'Could not delete the event.',
        );
    }
};

// --- Side panel width: drag or arrow-key the divider until names fit ---

// Bounds are mirrored in HandleInertiaRequests, which validates the cookie.
const PANEL_WIDTH = { default: 224, min: 200, max: 400, step: 16 };
const PANEL_COOKIE = 'calendar_panel_width';
const LEGACY_PANEL_KEY = 'fdcp-calendar-panel-width';

// Kept in a cookie the server reads, so the panel paints at the user's
// width on the first frame instead of jumping after hydration.
const page = usePage();
const panelWidth = ref(page.props.calendarPanelWidth ?? PANEL_WIDTH.default);

const setPanelWidth = (width: number) => {
    panelWidth.value = Math.min(
        PANEL_WIDTH.max,
        Math.max(PANEL_WIDTH.min, Math.round(width)),
    );
};

// A drag fires a width per pointer move; save once it settles.
watchDebounced(
    panelWidth,
    (width) => {
        document.cookie = `${PANEL_COOKIE}=${width};path=/;max-age=31536000;SameSite=Lax`;
    },
    { debounce: 200 },
);

// One-time move of a width saved before the cookie existed.
onMounted(() => {
    try {
        const legacy = Number(localStorage.getItem(LEGACY_PANEL_KEY));

        localStorage.removeItem(LEGACY_PANEL_KEY);

        if (page.props.calendarPanelWidth === null && legacy) {
            setPanelWidth(legacy);
        }
    } catch {
        // Storage blocked: the default width stands.
    }
});

// --- Hiding: drag the divider well past the minimum and the panel tucks
// away; a pull tab at the edge brings it back at the width it had ---

// Dragging the edge this far left hides the panel (60px past the minimum).
const PANEL_HIDE_AT = PANEL_WIDTH.min - 60;
const PANEL_HIDDEN_COOKIE = 'calendar_panel_hidden';

const panelHidden = ref(page.props.calendarPanelHidden);
/** 0-1 while a drag is in the hide zone: the panel fades as a warning. */
const hidePull = ref(0);
const resizingPanel = ref(false);

watch(panelHidden, (hidden) => {
    document.cookie = `${PANEL_HIDDEN_COOKIE}=${hidden ? 1 : 0};path=/;max-age=31536000;SameSite=Lax`;
});

const startPanelResize = (event: PointerEvent) => {
    const handle = event.currentTarget as HTMLElement;
    const startX = event.clientX;
    const restoreWidth = panelWidth.value;
    const startWidth = panelHidden.value ? 0 : restoreWidth;

    // Capture keeps move/up on the handle even once the pointer crosses
    // the grid, so no window listeners to clean up.
    handle.setPointerCapture(event.pointerId);
    resizingPanel.value = true;

    const move = (e: PointerEvent) => {
        const width = startWidth + e.clientX - startX;

        panelHidden.value = width < PANEL_HIDE_AT;
        hidePull.value = Math.min(
            1,
            Math.max(
                0,
                (PANEL_WIDTH.min - width) / (PANEL_WIDTH.min - PANEL_HIDE_AT),
            ),
        );

        // Hidden keeps the width it had, so the tab restores that.
        if (panelHidden.value) {
            panelWidth.value = restoreWidth;
        } else {
            setPanelWidth(width);
        }
    };
    const stop = () => {
        resizingPanel.value = false;
        hidePull.value = 0;
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', stop);
        handle.removeEventListener('pointercancel', stop);
    };

    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', stop);
    handle.addEventListener('pointercancel', stop);
};
</script>

<template>
    <div class="flex h-full min-h-0 flex-1">
        <!-- Calendar side panel. Hiding slides its width to 0; the inner
             column keeps its width so the content is clipped, not squeezed.
             inert takes the hidden panel out of tab order and the a11y tree. -->
        <aside
            v-if="!bookingResources"
            id="calendar-panel"
            class="hidden shrink-0 overflow-hidden lg:block"
            :class="
                !resizingPanel &&
                'transition-[width] duration-200 ease-out motion-reduce:transition-none'
            "
            :style="{ width: panelHidden ? '0px' : `${panelWidth}px` }"
            :inert="panelHidden"
            aria-label="Calendars"
        >
            <div
                class="flex h-full flex-col gap-4 overflow-y-auto p-3 transition-opacity duration-150"
                :style="{
                    width: `${panelWidth}px`,
                    opacity: 1 - hidePull * 0.6,
                }"
            >
                <!-- Google-style Create: a raised button (inverted ink: dark in
                 light mode, light in dark) opening a menu of every event type
                 (organisation types, then the user's own categories, which
                 they add by typing a name + Enter). Each opens the create
                 card with that type picked. -->
                <DropdownMenu v-if="canCreate">
                    <DropdownMenuTrigger as-child>
                        <Button
                            class="h-14 w-fit gap-3 rounded-2xl bg-foreground pr-4 pl-4 text-background shadow-md hover:bg-foreground/85 hover:shadow-lg data-[state=open]:shadow-lg"
                            data-test="create-event"
                        >
                            <Plus class="size-6" aria-hidden="true" />
                            Create
                            <Triangle
                                class="ml-2 size-2 rotate-180 fill-current"
                                aria-hidden="true"
                            />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-56 px-0 py-2">
                        <DropdownMenuItem
                            class="h-10 rounded-none px-4"
                            @select="openNew()"
                        >
                            Event
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-for="category in types"
                            :key="category.id"
                            class="h-10 gap-3 rounded-none px-4"
                            @select="openNew({ event_type_id: category.id })"
                        >
                            <span class="truncate">{{ category.name }}</span>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <label class="flex h-10 items-center gap-3 px-4">
                            <Plus
                                class="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <input
                                v-model="newCategory"
                                type="text"
                                maxlength="100"
                                placeholder="Add category"
                                aria-label="Add category, press Enter to save"
                                class="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                                @keydown="onCategoryKeydown"
                                @input="categoryError = ''"
                            />
                        </label>
                        <p
                            v-if="categoryError"
                            class="px-4 pb-1 text-xs text-destructive"
                            role="alert"
                        >
                            {{ categoryError }}
                        </p>
                    </DropdownMenuContent>
                </DropdownMenu>
                <MiniMonth />
                <Separator />
                <CalendarListPanel :groups="panelGroups" />
            </div>
        </aside>

        <!-- The divider is a window splitter: drag it (far left hides the
             panel, back out shows it), or focus it and use the arrow keys;
             Enter hides/shows; double-click restores the default width. The
             ::after box widens the 1px line into a 12px grab area. -->
        <div
            v-if="!bookingResources"
            class="relative z-10 hidden w-px shrink-0 cursor-col-resize bg-border transition-colors after:absolute after:inset-y-0 after:-left-1.5 after:w-3 after:content-[''] hover:bg-primary focus-visible:bg-primary focus-visible:outline-none lg:block"
            :class="hidePull > 0 && !panelHidden && 'bg-primary'"
            role="separator"
            aria-orientation="vertical"
            aria-label="Resize calendar panel"
            aria-controls="calendar-panel"
            :aria-valuemin="0"
            :aria-valuemax="PANEL_WIDTH.max"
            :aria-valuenow="panelHidden ? 0 : panelWidth"
            tabindex="0"
            @pointerdown.prevent="startPanelResize"
            @keydown.left.prevent="
                !panelHidden && setPanelWidth(panelWidth - PANEL_WIDTH.step)
            "
            @keydown.right.prevent="
                panelHidden
                    ? (panelHidden = false)
                    : setPanelWidth(panelWidth + PANEL_WIDTH.step)
            "
            @keydown.home.prevent="setPanelWidth(PANEL_WIDTH.min)"
            @keydown.end.prevent="setPanelWidth(PANEL_WIDTH.max)"
            @keydown.enter.prevent="panelHidden = !panelHidden"
            @dblclick="
                panelHidden = false;
                setPanelWidth(PANEL_WIDTH.default);
            "
        />

        <!-- Main area. With the panel hidden it makes room for the pull tab
             so the tab never covers the toolbar or the grid. -->
        <div
            class="relative flex min-w-0 flex-1 flex-col"
            :class="[
                panelHidden && !bookingResources && 'lg:pl-6',
                !resizingPanel &&
                    'transition-[padding] duration-200 ease-out motion-reduce:transition-none',
            ]"
        >
            <!-- Pull tab: always visible while the panel is hidden (no
                 hover needed), hugging the edge the panel went behind. -->
            <button
                v-if="!bookingResources && panelHidden"
                type="button"
                class="absolute top-2.5 left-0 z-10 hidden h-11 w-6 items-center justify-center rounded-r-lg border border-l-0 bg-card text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:flex"
                aria-label="Show calendar panel"
                title="Show calendar panel"
                aria-controls="calendar-panel"
                :aria-expanded="false"
                @click="panelHidden = false"
            >
                <ChevronRight class="size-4" aria-hidden="true" />
            </button>
            <CalendarToolbar
                v-if="!bookingResources"
                :can-create="canCreate"
                @create="openNew()"
            />

            <div class="min-h-0 flex-1">
                <ResourceSchedule
                    v-if="bookingResources && grid.view !== 'month'"
                    :resources="bookingResources"
                    :nouns="bookingNouns ?? { one: 'item', many: 'items' }"
                    @open="openQuickView"
                    @book="
                        (resourceId, start, end) =>
                            emit('createBooking', start, end, resourceId)
                    "
                />
                <MonthView
                    v-else-if="grid.view === 'month'"
                    @create-at="(day) => openQuickCreate(day, undefined, true)"
                    @open="openQuickView"
                    @show-day="showDayOverflow"
                    @move-recurring="moveRecurring"
                />
                <TimeGridView
                    v-else-if="
                        grid.view === 'week' ||
                        grid.view === 'day' ||
                        grid.view === 'days4'
                    "
                    :mode="grid.view"
                    @create-range="(start, end) => openQuickCreate(start, end)"
                    @open="openQuickView"
                    @move-recurring="moveRecurring"
                    @show-day="showDayOverflow"
                />
                <YearView v-else-if="grid.view === 'year'" />
                <AgendaView v-else @open="openQuickView" />
            </div>
        </div>

        <EventPeek />

        <EventQuickView
            :occurrence="quickViewShown"
            :open="quickViewTarget !== null"
            :origin="quickViewOrigin"
            @close="quickViewTarget = null"
            @edit="openEdit"
            @delete="removeEvent"
            @booking-changed="onBookingChanged"
        />

        <QuickCreateDialog
            :target="quickCreate"
            :types="types"
            @close="quickCreate = null"
            @more="moreOptions"
        />

        <EventEditorDialog
            :open="editorOpen"
            :occurrence="editorTarget"
            :initial-start="editorInitialStart"
            :initial-end="editorInitialEnd"
            :event-types="types"
            :draft="editorDraft"
            @close="editorOpen = false"
            @saved="quickViewTarget = null"
        />

        <DayOverflowDialog
            :day="overflowDay"
            :occurrences="overflowItems"
            @close="overflowDay = null"
            @open="
                (occurrence) => {
                    overflowDay = null;
                    openQuickView(occurrence);
                }
            "
        />

        <RecurrenceScopeDialog
            :open="scopeResolver !== null"
            :action="scopeAction"
            @choose="settleScope"
            @cancel="settleScope(null)"
        />
    </div>
</template>
