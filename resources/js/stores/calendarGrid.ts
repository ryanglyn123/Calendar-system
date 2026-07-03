import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api } from '@/lib/api';
import { monthFetchRange, monthKey, windowKeysAround } from '@/lib/calendarMath';
import type { WeekStart } from '@/lib/calendarMath';
import {
    index as eventsIndex,
    update as eventsUpdate,
} from '@/routes/api/events';
import occurrenceRoutes from '@/routes/api/events/occurrence';
import type { CalendarView, EventOccurrence } from '@/types/calendar';

export type RecurrenceScope = 'this' | 'following' | 'all';

/** The original start encoded in "{event id}:{ISO start}" occurrence ids. */
export function originalStartOf(occurrence: EventOccurrence): string {
    return occurrence.occurrence_id.slice(
        occurrence.occurrence_id.indexOf(':') + 1,
    );
}

type WindowState = {
    status: 'loading' | 'ready' | 'stale';
    occurrences: EventOccurrence[];
};

export const useCalendarGridStore = defineStore('calendarGrid', () => {
    const view = ref<CalendarView>('month');
    const focusDate = ref<Date>(new Date());
    const weekStartsOn = ref<WeekStart>(1);
    const windows = ref(new Map<string, WindowState>());

    /** Bumps whenever any window's contents change so views recompute. */
    const version = ref(0);

    const isLoading = computed(() =>
        [...windows.value.values()].some((w) => w.status === 'loading'),
    );

    function occurrencesBetween(start: Date, end: Date): EventOccurrence[] {
        // Touch version so callers re-run when windows refresh.
        void version.value;

        const seen = new Set<string>();
        const result: EventOccurrence[] = [];

        for (const window of windows.value.values()) {
            if (window.status === 'loading') {
                continue;
            }

            for (const occurrence of window.occurrences) {
                if (seen.has(occurrence.occurrence_id)) {
                    continue;
                }

                const startsAt = new Date(occurrence.starts_at);
                const endsAt = new Date(occurrence.ends_at);

                if (startsAt < end && endsAt > start) {
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

    async function fetchWindow(key: string): Promise<void> {
        const existing = windows.value.get(key);

        if (existing && existing.status !== 'stale') {
            return;
        }

        windows.value.set(key, {
            status: 'loading',
            occurrences: existing?.occurrences ?? [],
        });

        const range = monthFetchRange(key, weekStartsOn.value);

        try {
            const response = await api.get<{ data: EventOccurrence[] }>(
                eventsIndex({
                    query: {
                        start: range.start.toISOString(),
                        end: range.end.toISOString(),
                    },
                }).url,
            );

            windows.value.set(key, {
                status: 'ready',
                occurrences: response.data,
            });
        } catch (error) {
            windows.value.delete(key);

            throw error;
        } finally {
            version.value++;
        }
    }

    async function ensureWindowsAround(date: Date): Promise<void> {
        await Promise.all(windowKeysAround(date).map((key) => fetchWindow(key)));
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
        windows,
        version,
        isLoading,
        occurrencesBetween,
        fetchWindow,
        ensureWindowsAround,
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
