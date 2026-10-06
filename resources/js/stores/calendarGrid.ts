import { useStorage } from '@vueuse/core';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api } from '@/lib/api';
import {
    monthFetchRange,
    monthKey,
    windowKeysAround,
} from '@/lib/calendarMath';
import type { WeekStart } from '@/lib/calendarMath';
import {
    index as eventsIndex,
    update as eventsUpdate,
} from '@/routes/api/events';
import occurrenceRoutes from '@/routes/api/events/occurrence';
import type {
    CalendarScope,
    CalendarView,
    EventOccurrence,
} from '@/types/calendar';

export type RecurrenceScope = 'this' | 'following' | 'all';

/** The original start encoded in "{event id}:{ISO start}" occurrence ids. */
export function originalStartOf(occurrence: EventOccurrence): string {
    return occurrence.occurrence_id.slice(
        occurrence.occurrence_id.indexOf(':') + 1,
    );
}

type WindowState = {
    /**
     * `stale` and `refreshing` windows keep showing their last data, so a
     * background refresh never blanks the grid; only a first load does.
     */
    status: 'loading' | 'ready' | 'stale' | 'refreshing';
    occurrences: EventOccurrence[];
};

export const useCalendarGridStore = defineStore('calendarGrid', () => {
    const view = ref<CalendarView>('month');
    const focusDate = ref<Date>(new Date());
    const weekStartsOn = ref<WeekStart>(1);

    // View options from the view menu. Per-viewer conveniences, kept in the
    // browser; read after mount so server and client first paint agree.
    const showWeekends = useStorage('fdcp-calendar-weekends', true, undefined, {
        initOnMounted: true,
    });
    const showDeclined = useStorage('fdcp-calendar-declined', true, undefined, {
        initOnMounted: true,
    });
    const windows = ref(new Map<string, WindowState>());

    /**
     * The page's calendar scope. Windows are cached per scope so switching
     * between the dashboard, public calendar and resource sections never
     * mixes their event sets or reuses the wrong cache.
     */
    const scope = ref<CalendarScope | null>(null);

    const scopeKey = computed(() => {
        if (scope.value === null) {
            return 'all';
        }

        return `${scope.value.types?.join('+') ?? ''}#${scope.value.resourceTypeId ?? ''}`;
    });

    // Booking calendars (Boardroom, Carpool) open where their page says (it
    // knows the type and the viewer's role); leaving one restores the view
    // and date the other calendars were on.
    let outsideBooking = { view: view.value, date: focusDate.value };
    const isBooking = (s: CalendarScope | null) => s?.resourceTypeId != null;

    function setScope(
        next: CalendarScope | null,
        at?: { view: CalendarView; date: Date },
    ): void {
        if (isBooking(next) && !isBooking(scope.value)) {
            outsideBooking = { view: view.value, date: focusDate.value };
        } else if (!isBooking(next) && isBooking(scope.value)) {
            view.value = outsideBooking.view;
            focusDate.value = outsideBooking.date;
        }

        if (at) {
            view.value = at.view;
            focusDate.value = at.date;
        }

        scope.value = next;
        version.value++;
    }

    function scopeQuery(): Record<string, (string | number)[] | number> {
        const query: Record<string, (string | number)[] | number> = {};

        if (scope.value?.types !== undefined) {
            query.types = scope.value.types;
        }

        if (scope.value?.resourceTypeId !== undefined) {
            query.resource_type_id = scope.value.resourceTypeId;
        }

        return query;
    }

    /** Bumps whenever any window's contents change so views recompute. */
    const version = ref(0);

    const isLoading = computed(() =>
        [...windows.value.entries()].some(
            ([key, w]) =>
                key.startsWith(`${scopeKey.value}|`) && w.status === 'loading',
        ),
    );

    function occurrencesBetween(start: Date, end: Date): EventOccurrence[] {
        // Touch version so callers re-run when windows refresh.
        void version.value;

        const seen = new Set<string>();
        const result: EventOccurrence[] = [];

        for (const [key, window] of windows.value.entries()) {
            if (
                !key.startsWith(`${scopeKey.value}|`) ||
                window.status === 'loading'
            ) {
                continue;
            }

            for (const occurrence of window.occurrences) {
                if (seen.has(occurrence.occurrence_id)) {
                    continue;
                }

                const startsAt = new Date(occurrence.starts_at);
                const endsAt = new Date(occurrence.ends_at);

                if (
                    startsAt < end &&
                    endsAt > start &&
                    (showDeclined.value || occurrence.my_rsvp !== 'declined')
                ) {
                    seen.add(occurrence.occurrence_id);
                    result.push(occurrence);
                }
            }
        }

        return result.sort(
            (a, b) =>
                new Date(a.starts_at).getTime() -
                new Date(b.starts_at).getTime(),
        );
    }

    function overlapsRange(
        occurrence: EventOccurrence,
        range: { start: Date; end: Date },
    ): boolean {
        return (
            new Date(occurrence.starts_at) < range.end &&
            new Date(occurrence.ends_at) > range.start
        );
    }

    /**
     * Fetch several month windows as ONE spanning request (the keys are
     * always contiguous months), then partition the result per window.
     * One round trip instead of one per month.
     */
    async function fetchWindows(keys: string[]): Promise<void> {
        // Capture the scope at call time: a page switch mid-flight must not
        // write this response into the new scope's cache.
        const prefix = scopeKey.value;
        const scopedKey = (month: string) => `${prefix}|${month}`;

        const missing = keys.filter((month) => {
            const existing = windows.value.get(scopedKey(month));

            return !existing || existing.status === 'stale';
        });

        if (missing.length === 0) {
            return;
        }

        const ranges = new Map(
            missing.map((month) => [
                month,
                monthFetchRange(month, weekStartsOn.value),
            ]),
        );

        const start = new Date(
            Math.min(...[...ranges.values()].map((r) => r.start.getTime())),
        );
        const end = new Date(
            Math.max(...[...ranges.values()].map((r) => r.end.getTime())),
        );

        for (const month of missing) {
            const existing = windows.value.get(scopedKey(month));

            windows.value.set(scopedKey(month), {
                status: existing ? 'refreshing' : 'loading',
                occurrences: existing?.occurrences ?? [],
            });
        }

        try {
            const response = await api.get<{ data: EventOccurrence[] }>(
                eventsIndex({
                    query: {
                        start: start.toISOString(),
                        end: end.toISOString(),
                        ...scopeQuery(),
                    },
                }).url,
            );

            for (const month of missing) {
                const range = ranges.get(month)!;

                windows.value.set(scopedKey(month), {
                    status: 'ready',
                    occurrences: response.data.filter((occurrence) =>
                        overlapsRange(occurrence, range),
                    ),
                });
            }
        } catch (error) {
            for (const month of missing) {
                const existing = windows.value.get(scopedKey(month));

                // A failed refresh keeps the last good data, marked for retry.
                if (existing?.status === 'refreshing') {
                    existing.status = 'stale';
                } else {
                    windows.value.delete(scopedKey(month));
                }
            }

            throw error;
        } finally {
            version.value++;
        }
    }

    async function fetchWindow(key: string): Promise<void> {
        await fetchWindows([key]);
    }

    async function ensureWindowsAround(date: Date): Promise<void> {
        await fetchWindows(windowKeysAround(date));
    }

    /**
     * Hydrate the windows around a date from server-provided occurrences
     * (inlined with the dashboard page), so the grid paints full on first
     * render without any API round trip.
     */
    function primeAround(date: Date, occurrences: EventOccurrence[]): void {
        for (const month of windowKeysAround(date)) {
            const range = monthFetchRange(month, weekStartsOn.value);

            windows.value.set(`${scopeKey.value}|${month}`, {
                status: 'ready',
                occurrences: occurrences.filter((occurrence) =>
                    overlapsRange(occurrence, range),
                ),
            });
        }

        version.value++;
    }

    /** Mark every window overlapping the event's span stale and refetch the visible ones. */
    async function invalidateAround(date: Date): Promise<void> {
        for (const [key, window] of windows.value) {
            void key;
            window.status = 'stale';
        }

        version.value++;

        await ensureWindowsAround(date);
    }

    function setView(next: CalendarView): void {
        view.value = next;
    }

    function setFocusDate(date: Date): void {
        focusDate.value = date;
        void ensureWindowsAround(date);
    }

    function goToday(): void {
        setFocusDate(new Date());
    }

    /** Replace an occurrence in place (optimistic update helper). */
    function replaceOccurrence(updated: EventOccurrence): void {
        for (const window of windows.value.values()) {
            const index = window.occurrences.findIndex(
                (o) => o.occurrence_id === updated.occurrence_id,
            );

            if (index !== -1) {
                window.occurrences.splice(index, 1, updated);
            }
        }

        version.value++;
    }

    function removeEvent(eventId: number): void {
        for (const window of windows.value.values()) {
            window.occurrences = window.occurrences.filter(
                (o) => o.event_id !== eventId,
            );
        }

        version.value++;
    }

    /**
     * Optimistically move/resize an event: the grid updates instantly, the
     * PATCH runs in the background, and on failure the snapshot is restored.
     */
    async function moveEvent(
        occurrence: EventOccurrence,
        newStart: Date,
        newEnd: Date,
    ): Promise<void> {
        const snapshot: [EventOccurrence[], EventOccurrence][] = [];

        for (const window of windows.value.values()) {
            for (let i = 0; i < window.occurrences.length; i++) {
                const current = window.occurrences[i];

                if (current.event_id === occurrence.event_id) {
                    snapshot.push([window.occurrences, current]);
                    window.occurrences.splice(i, 1, {
                        ...current,
                        starts_at: newStart.toISOString(),
                        ends_at: newEnd.toISOString(),
                    });
                }
            }
        }

        version.value++;

        try {
            await api.patch(eventsUpdate(occurrence.event_id).url, {
                starts_at: newStart.toISOString(),
                ends_at: newEnd.toISOString(),
            });

            // Reconcile occurrence ids and window membership in the background.
            void invalidateAround(newStart);
        } catch (error) {
            for (const [occurrences, original] of snapshot) {
                const index = occurrences.findIndex(
                    (o) => o.event_id === original.event_id,
                );

                if (index !== -1) {
                    occurrences.splice(index, 1, original);
                }
            }

            version.value++;

            throw error;
        }
    }

    /**
     * Reschedule one occurrence of a recurring series with an edit scope.
     */
    async function moveOccurrenceScoped(
        occurrence: EventOccurrence,
        scope: RecurrenceScope,
        newStart: Date,
        newEnd: Date,
    ): Promise<void> {
        await api.patch(occurrenceRoutes.update(occurrence.event_id).url, {
            scope,
            original_starts_at: originalStartOf(occurrence),
            starts_at: newStart.toISOString(),
            ends_at: newEnd.toISOString(),
        });

        await invalidateAround(newStart);
    }

    /**
     * Delete occurrences of a recurring series with an edit scope.
     */
    async function deleteOccurrenceScoped(
        occurrence: EventOccurrence,
        scope: RecurrenceScope,
    ): Promise<void> {
        await api.delete(
            `${occurrenceRoutes.destroy(occurrence.event_id).url}?${new URLSearchParams(
                {
                    scope,
                    original_starts_at: originalStartOf(occurrence),
                },
            )}`,
        );

        await invalidateAround(new Date(occurrence.starts_at));
    }

    return {
        view,
        focusDate,
        weekStartsOn,
        showWeekends,
        showDeclined,
        windows,
        scope,
        setScope,
        version,
        isLoading,
        occurrencesBetween,
        fetchWindow,
        ensureWindowsAround,
        primeAround,
        invalidateAround,
        setView,
        setFocusDate,
        goToday,
        replaceOccurrence,
        removeEvent,
        moveEvent,
        moveOccurrenceScoped,
        deleteOccurrenceScoped,
        currentMonthKey: computed(() => monthKey(focusDate.value)),
    };
});
