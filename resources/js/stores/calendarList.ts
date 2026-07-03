import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api } from '@/lib/api';
import { index as calendarsIndex, preference } from '@/routes/api/calendars';
import type { CalendarItem } from '@/types/calendar';

export const useCalendarListStore = defineStore('calendarList', () => {
    const calendars = ref<CalendarItem[]>([]);
    const loaded = ref(false);
    const loading = ref(false);

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

    async function load(force = false): Promise<void> {
        if (loading.value || (loaded.value && !force)) {
            return;
        }

        loading.value = true;

        try {
            const response = await api.get<{ data: CalendarItem[] }>(
                calendarsIndex().url,
            );

            calendars.value = response.data;
            loaded.value = true;
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
        visibleCalendarIds,
        byId,
        grouped,
        writableCalendars,
        load,
        toggleVisibility,
    };
});
