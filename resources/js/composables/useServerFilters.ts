import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { reactive, watch } from 'vue';

type FilterValue = string | undefined;

/**
 * Filters, search and sort for a server-paginated admin list. Every change
 * reloads the page props through the query string, so results, page links
 * and the address all agree and a filtered view can be shared or bookmarked.
 *
 * `'all'` and empty strings are left out of the query: they mean "no filter".
 * Changing any filter returns to page 1 (the old page may no longer exist).
 */
export function useServerFilters<T extends Record<string, FilterValue>>(
    url: () => string,
    initial: T,
    options: { only?: string[]; debounce?: number } = {},
): T {
    const filters = reactive({ ...initial }) as T;

    const apply = useDebounceFn(() => {
        const query = Object.fromEntries(
            Object.entries(filters).filter(
                ([, value]) =>
                    value !== undefined && value !== '' && value !== 'all',
            ),
        );

        router.get(url(), query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: options.only,
        });
    }, options.debounce ?? 300);

    watch(() => ({ ...filters }), apply);

    return filters;
}
