/**
 * Column-packing layout for the time grid (week/day views), Google style:
 * overlapping events are grouped into collision clusters, greedily assigned
 * the lowest free column, then expanded rightward into free space.
 */

export type LayoutInput = {
    key: string;
    startMinutes: number;
    endMinutes: number;
};

export type LayoutBox = {
    key: string;
    /** 0..1 fraction of the day-column width. */
    left: number;
    /** 0..1 fraction of the day-column width. */
    width: number;
    startMinutes: number;
    endMinutes: number;
};

const MIN_RENDER_MINUTES = 30;

function overlaps(a: LayoutInput, b: LayoutInput): boolean {
    return (
        renderEnd(a) > b.startMinutes && a.startMinutes < renderEnd(b)
    );
}

function renderEnd(item: LayoutInput): number {
    return Math.max(item.endMinutes, item.startMinutes + MIN_RENDER_MINUTES);
}

export function layoutDayColumn(items: LayoutInput[]): LayoutBox[] {
    const sorted = [...items].sort(
        (a, b) => a.startMinutes - b.startMinutes || b.endMinutes - a.endMinutes,
    );

    // 1. Sweep into collision clusters (connected components of overlap).
    const clusters: LayoutInput[][] = [];
    let current: LayoutInput[] = [];
    let clusterEnd = -1;

    for (const item of sorted) {
        if (current.length > 0 && item.startMinutes >= clusterEnd) {
            clusters.push(current);
            current = [];
        }

        current.push(item);
        clusterEnd = Math.max(clusterEnd, renderEnd(item));
    }

    if (current.length > 0) {
        clusters.push(current);
    }

    const boxes: LayoutBox[] = [];

    for (const cluster of clusters) {
        // 2. Greedy column assignment within the cluster.
        const columns: LayoutInput[][] = [];
        const columnOf = new Map<string, number>();

        for (const item of cluster) {
            let placed = false;

            for (let c = 0; c < columns.length; c++) {
                const last = columns[c][columns[c].length - 1];

                if (renderEnd(last) <= item.startMinutes) {
                    columns[c].push(item);
                    columnOf.set(item.key, c);
                    placed = true;
                    break;
                }
            }

            if (!placed) {
                columns.push([item]);
                columnOf.set(item.key, columns.length - 1);
            }
        }

        const columnCount = columns.length;

        // 3. Expand each item rightward while the next columns are free.
        for (const item of cluster) {
            const col = columnOf.get(item.key)!;
            let span = 1;

            for (let next = col + 1; next < columnCount; next++) {
                const blocked = columns[next].some((other) =>
                    overlaps(item, other),
                );

                if (blocked) {
                    break;
                }

                span++;
            }

            boxes.push({
                key: item.key,
                left: col / columnCount,
                width: span / columnCount,
                startMinutes: item.startMinutes,
                endMinutes: renderEnd(item),
            });
        }
    }

    return boxes;
}
