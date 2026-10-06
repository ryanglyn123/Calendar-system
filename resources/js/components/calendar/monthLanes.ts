import { addDays, startOfDay } from 'date-fns';
import type { EventOccurrence } from '@/types/calendar';

export type WeekLaneSegment = {
    occurrence: EventOccurrence;
    /** 0-based column within the week row. */
    startCol: number;
    /** Number of columns the bar spans. */
    span: number;
    /** Assigned lane row (0-based). */
    lane: number;
    /** Bar continues from the previous week / into the next week. */
    continuesBefore: boolean;
    continuesAfter: boolean;
};

/**
 * Lay multi-day / all-day occurrences into stacked lanes for one week row,
 * Google style: first free lane wins, earlier + longer bars first.
 */
export function layoutWeekLanes(
    occurrences: EventOccurrence[],
    weekDays: Date[],
): WeekLaneSegment[] {
    const weekStart = startOfDay(weekDays[0]);
    const weekEnd = addDays(startOfDay(weekDays[weekDays.length - 1]), 1);

    const segments: Omit<WeekLaneSegment, 'lane'>[] = [];

    for (const occurrence of occurrences) {
        const startsAt = new Date(occurrence.starts_at);
        const endsAt = new Date(occurrence.ends_at);

        if (startsAt >= weekEnd || endsAt <= weekStart) {
            continue;
        }

        const clampedStart =
            startsAt < weekStart ? weekStart : startOfDay(startsAt);

        // End-exclusive: an event ending exactly at midnight covers up to the
        // previous day.
        const lastMoment = new Date(endsAt.getTime() - 1);
        const clampedEnd =
            lastMoment >= weekEnd
                ? addDays(weekEnd, -1)
                : startOfDay(lastMoment);

        const startCol = Math.round(
            (clampedStart.getTime() - weekStart.getTime()) / 86_400_000,
        );
        const endCol = Math.round(
            (clampedEnd.getTime() - weekStart.getTime()) / 86_400_000,
        );

        segments.push({
            occurrence,
            startCol: Math.max(0, startCol),
            span:
                Math.min(weekDays.length - 1, endCol) -
                Math.max(0, startCol) +
                1,
            continuesBefore: startsAt < weekStart,
            continuesAfter: endsAt > weekEnd,
        });
    }

    segments.sort(
        (a, b) =>
            a.startCol - b.startCol ||
            b.span - a.span ||
            a.occurrence.title.localeCompare(b.occurrence.title),
    );

    // Greedy lane assignment: lanes[i] = columns already taken in lane i.
    const lanes: boolean[][] = [];
    const placed: WeekLaneSegment[] = [];

    for (const segment of segments) {
        let lane = 0;

        while (true) {
            lanes[lane] ??= new Array(weekDays.length).fill(false);

            const free = Array.from(
                { length: segment.span },
                (_, i) => segment.startCol + i,
            ).every((col) => !lanes[lane][col]);

            if (free) {
                for (let i = 0; i < segment.span; i++) {
                    lanes[lane][segment.startCol + i] = true;
                }

                placed.push({ ...segment, lane });
                break;
            }

            lane++;
        }
    }

    return placed;
}
