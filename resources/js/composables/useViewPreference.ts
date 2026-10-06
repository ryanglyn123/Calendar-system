import { useStorage } from '@vueuse/core';
import { computed } from 'vue';
import type { WritableComputedRef } from 'vue';

/**
 * Which view an admin page shows, remembered per page in this browser (a
 * per-viewer convenience, so local storage rather than the account). A stored
 * view the page no longer offers falls back to the first one.
 *
 * Read after mount so the server-rendered first paint and hydration agree.
 */
export function useViewPreference<T extends string>(
    page: string,
    views: readonly T[],
): WritableComputedRef<T> {
    const stored = useStorage<string>(
        `fdcp-admin-view:${page}`,
        views[0],
        undefined,
        { initOnMounted: true },
    );

    return computed<T>({
        get: () =>
            views.includes(stored.value as T) ? (stored.value as T) : views[0],
        set: (view) => {
            stored.value = view;
        },
    });
}
