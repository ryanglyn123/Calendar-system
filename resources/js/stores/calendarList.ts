import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api } from '@/lib/api';
import { index as calendarsIndex, preference } from '@/routes/api/calendars';
import type { CalendarItem, CalendarScope } from '@/types/calendar';

export const useCalendarListStore = defineStore('calendarList', () => {
    const calendars = ref<CalendarItem[]>([]);
    const loaded = ref(false);
    const loading = ref(false);

    /**
     * The page's calendar scope; refreshes request the same subset the page
     * inlined, so a scoped page never flashes back to the full list.
     */
    const scope = ref<CalendarScope | null>(null);

    function setScope(next: CalendarScope | null): void {
        const changed = JSON.stringify(next) !== JSON.stringify(scope.value);

        scope.value = next;

        if (changed) {
            calendars.value = [];
            loaded.value = false;
        }
    }

    const visibleCalendarIds = computed(() =>
        calendars.value.filter((c) => c.is_visible).map((c) => c.id),
    );

    const byId = computed(
        () => new Map(calendars.value.map((c) => [c.id, c])),
    );

    const grouped = computed(() => ({
        organization: calendars.value.filter((c) => c.type === 'organization'),
        division: calendars.value.filter((c) => c.type === 'division'),
        personal: calendars.value.filter((c) => c.type === 'personal'),
        resource: calendars.value.filter((c) => c.type === 'resource'),
    }));

    const writableCalendars = computed(() =>
        calendars.value.filter((c) => c.can_write_events),
    );

    /** Hydrate from server-provided data inlined with the page. */
    function prime(data: CalendarItem[]): void {
        calendars.value = data;
        loaded.value = true;
    }

    async function load(force = false): Promise<void> {
        if (loading.value || (loaded.value && !force)) {
            return;
        }

        loading.value = true;

        const requestedScope = JSON.stringify(scope.value);

        const query: Record<string, (string | number)[] | number> = {};

        if (scope.value?.types !== undefined) {
            query.types = scope.value.types;
        }

        if (scope.value?.resourceTypeId !== undefined) {
            query.resource_type_id = scope.value.resourceTypeId;
        }

        try {
            const response = await api.get<{ data: CalendarItem[] }>(
                calendarsIndex({ query }).url,
            );

            // A page switch mid-flight changes the scope; drop the response
            // rather than overwrite the new page's list.
            if (JSON.stringify(scope.value) === requestedScope) {
                calendars.value = response.data;
                loaded.value = true;
            }
        } finally {
            loading.value = false;
        }
    }

    async function toggleVisibility(calendar: CalendarItem): Promise<void> {
        const next = !calendar.is_visible;

        calendar.is_visible = next;

        try {
            await api.put(preference(calendar.id).url, { is_visible: next });
        } catch (error) {
            calendar.is_visible = !next;

            throw error;
        }
    }

    return {
        calendars,
        loaded,
        loading,
        scope,
        setScope,
        visibleCalendarIds,
        byId,
        grouped,
        writableCalendars,
        prime,
        load,
        toggleVisibility,
    };
});
