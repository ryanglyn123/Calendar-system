import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { api } from '@/lib/api';
import { colorName, defaultLabels, sameColor } from '@/lib/eventColors';
import type { ColorLabel } from '@/lib/eventColors';
import colorLabels from '@/routes/api/color-labels';

/**
 * The signed-in person's color labels: their own names for event colors and
 * which colors their menu shows. Shared with every page, so saving reloads
 * just that and every open color menu follows.
 */
export function useColorLabels() {
    const page = usePage();

    const labels = computed<ColorLabel[]>(
        () => page.props.auth.user?.color_labels ?? defaultLabels(),
    );

    const visible = computed(() => labels.value.filter((l) => !l.hidden));

    /** "Urgent" for a labelled color, else its palette name or hex. */
    const nameOf = (hex: string | null | undefined): string =>
        labels.value.find((l) => sameColor(l.color, hex))?.name ??
        colorName(hex);

    /** The person's own label for a color, if they gave it one. */
    const labelOf = (hex: string | null | undefined): string | null =>
        labels.value.find((l) => sameColor(l.color, hex))?.name ?? null;

    const save = async (list: ColorLabel[]) => {
        await api.put(colorLabels.update().url, { labels: list });
        router.reload({ only: ['auth'] });
    };

    return { labels, visible, nameOf, labelOf, save };
}
