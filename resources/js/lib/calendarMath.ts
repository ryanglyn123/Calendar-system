import {
    addDays,
    addMonths,
    differenceInCalendarDays,
    endOfMonth,
    endOfWeek,
    format,
    isSameDay,
    isSameMonth,
    parseISO,
    startOfDay,
    startOfMonth,
    startOfWeek,
} from 'date-fns';

export type WeekStart = 0 | 1;

/** Month key used by the window cache, e.g. "2026-07". */
export function monthKey(date: Date): string {
    return format(date, 'yyyy-MM');
}

export function monthKeyToDate(key: string): Date {
    return parseISO(`${key}-01T00:00:00`);
}

/** The month keys a view needs, with a ±1 month buffer. */
export function windowKeysAround(date: Date): string[] {
    return [
        monthKey(addMonths(date, -1)),
        monthKey(date),
        monthKey(addMonths(date, 1)),
    ];
}

/** Fetch range for one month key: the full weeks containing the month. */
export function monthFetchRange(
    key: string,
    weekStartsOn: WeekStart,
): { start: Date; end: Date } {
    const first = monthKeyToDate(key);

    return {
        start: startOfWeek(startOfMonth(first), { weekStartsOn }),
        end: addDays(endOfWeek(endOfMonth(first), { weekStartsOn }), 1),
    };
}

/** The 42 cells (6 weeks) of a month grid. */
export function monthGridDays(anchor: Date, weekStartsOn: WeekStart): Date[] {
    const gridStart = startOfWeek(startOfMonth(anchor), { weekStartsOn });

    return Array.from({ length: 42 }, (_, i) => addDays(gridStart, i));
}

/** The 7 days of the week containing the anchor. */
export function weekDays(anchor: Date, weekStartsOn: WeekStart): Date[] {
    const start = startOfWeek(anchor, { weekStartsOn });

    return Array.from({ length: 7 }, (_, i) => addDays(start, i));
}

export function occurrenceTouchesDay(
    startsAt: Date,
    endsAt: Date,
    day: Date,
): boolean {
    const dayStart = startOfDay(day);
    const dayEnd = addDays(dayStart, 1);

    return startsAt < dayEnd && endsAt > dayStart;
}

/**
 * Multi-day when start and end fall on different calendar days. Ends at
 * exactly midnight count toward the previous day (all-day events are stored
 * end-exclusive), so a one-day all-day event is NOT multi-day.
 */
export function isMultiDay(startsAt: Date, endsAt: Date): boolean {
    const endsAtMidnight =
        endsAt.getHours() === 0 &&
        endsAt.getMinutes() === 0 &&
        endsAt.getSeconds() === 0;

    const effectiveEnd = endsAtMidnight ? addDays(endsAt, -1) : endsAt;

    return effectiveEnd > startsAt && !isSameDay(startsAt, effectiveEnd);
}

/** Calendar days an occurrence covers, honoring end-exclusive midnights. */
export function coveredDayCount(startsAt: Date, endsAt: Date): number {
    const endsAtMidnight =
        endsAt.getHours() === 0 &&
        endsAt.getMinutes() === 0 &&
        endsAt.getSeconds() === 0;

    const effectiveEnd = endsAtMidnight ? addDays(endsAt, -1) : endsAt;

    return Math.max(0, differenceInCalendarDays(effectiveEnd, startsAt)) + 1;
}

/** Minutes since midnight, for time-grid positioning. */
export function minutesIntoDay(date: Date): number {
    return date.getHours() * 60 + date.getMinutes();
}

export function toIso(date: Date): string {
    return date.toISOString();
}

/**
 * Pick a readable text ink for text sitting directly on an arbitrary
 * event/calendar color, using WCAG relative luminance. Light pastels get a
 * dark ink mixed from their own hue (dark purple on lavender, dark blue on
 * sky) instead of flat black; dark colors keep white text.
 */
export function eventInk(hex: string | null | undefined): string {
    const c = (hex ?? '').replace('#', '');

    if (c.length < 6) {
        return '#ffffff';
    }

    const channel = (v: number) =>
        v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;

    const r = channel(parseInt(c.slice(0, 2), 16) / 255);
    const g = channel(parseInt(c.slice(2, 4), 16) / 255);
    const b = channel(parseInt(c.slice(4, 6), 16) / 255);
    const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b;

    return luminance > 0.45
        ? `color-mix(in oklch, #${c} 38%, oklch(0.22 0.02 none))`
        : '#ffffff';
}

/**
 * Soft vertical wash for event surfaces: the color lightened at the top,
 * settling into itself at the bottom.
 */
export function eventWash(hex: string | null | undefined): string {
    const c = hex ?? '#d4d8dd';

    return `linear-gradient(180deg, color-mix(in oklab, ${c} 84%, white) 0%, ${c} 100%)`;
}

export {
    addDays,
    addMonths,
    format,
    isSameDay,
    isSameMonth,
    parseISO,
    startOfDay,
};
