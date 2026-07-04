import { differenceInCalendarDays } from 'date-fns';
import { format, parseISO } from '@/lib/calendarMath';

const FREQ_UNIT: Record<string, { adverb: string; noun: string }> = {
    DAILY: { adverb: 'daily', noun: 'day' },
    WEEKLY: { adverb: 'weekly', noun: 'week' },
    MONTHLY: { adverb: 'monthly', noun: 'month' },
    YEARLY: { adverb: 'yearly', noun: 'year' },
};

const DAY_LABEL: Record<string, string> = {
    MO: 'Mon',
    TU: 'Tue',
    WE: 'Wed',
    TH: 'Thu',
    FR: 'Fri',
    SA: 'Sat',
    SU: 'Sun',
};

const parseRule = (rrule: string): Map<string, string> =>
    new Map(
        rrule.split(';').map((chunk) => {
            const [key, value] = chunk.split('=');

            return [key?.toUpperCase() ?? '', value ?? ''] as const;
        }),
    );

/** ICS UNTIL stamp (YYYYMMDD[THHMMSSZ]) into a friendly date. */
const untilDate = (raw: string): string | null => {
    if (raw.length < 8) {
        return null;
    }

    const iso = `${raw.slice(0, 4)}-${raw.slice(4, 6)}-${raw.slice(6, 8)}`;
    const parsed = parseISO(`${iso}T00:00:00`);

    return Number.isNaN(parsed.getTime())
        ? null
        : format(parsed, 'MMM d, yyyy');
};

/**
 * Turn an RRULE into a short human phrase, e.g. "Repeats weekly on Mon, Wed"
 * or "Repeats every 2 weeks, until Jan 1, 2026". Returns null for one-off
 * events. Best-effort: mirrors the subset the recurrence editor emits.
 */
export function describeRecurrence(rrule: string | null): string | null {
    if (!rrule) {
        return null;
    }

    const parts = parseRule(rrule);
    const unit = FREQ_UNIT[parts.get('FREQ') ?? ''];

    if (!unit) {
        return 'Repeats';
    }

    const interval = Math.max(1, Number(parts.get('INTERVAL') ?? '1') || 1);

    let phrase =
        interval === 1
            ? `Repeats ${unit.adverb}`
            : `Repeats every ${interval} ${unit.noun}s`;

    const days = (parts.get('BYDAY') ?? '')
        .split(',')
        .map((code) => DAY_LABEL[code])
        .filter(Boolean);

    if (days.length > 0) {
        phrase += ` on ${days.join(', ')}`;
    }

    if (parts.has('UNTIL')) {
        const until = untilDate(parts.get('UNTIL') ?? '');

        if (until) {
            phrase += `, until ${until}`;
        }
    } else if (parts.has('COUNT')) {
        phrase += `, ${parts.get('COUNT')} times`;
    }

    return phrase;
}

/** A lead time in minutes as "10 minutes before", "1 hour before", etc. */
export function formatReminder(minutes: number): string {
    if (minutes <= 0) {
        return 'At start';
    }

    if (minutes < 60) {
        return `${minutes} minute${minutes === 1 ? '' : 's'} before`;
    }

    if (minutes < 1440) {
        const hours = minutes / 60;

        return `${hours} hour${hours === 1 ? '' : 's'} before`;
    }

    const days = minutes / 1440;

    return `${days} day${days === 1 ? '' : 's'} before`;
}

/**
 * Span between two ISO datetimes as a compact label. All-day events report
 * whole days; timed events report hours and minutes. Returns null when there
 * is nothing worth showing (a single all-day date, a zero-length event).
 */
export function formatDuration(
    startIso: string,
    endIso: string,
    allDay: boolean,
): string | null {
    const start = new Date(startIso).getTime();
    const end = new Date(endIso).getTime();
    const totalMinutes = Math.round((end - start) / 60000);

    if (totalMinutes <= 0) {
        return null;
    }

    if (allDay) {
        const days = Math.round(totalMinutes / 1440);

        return days <= 1 ? null : `${days} days`;
    }

    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours === 0) {
        return `${minutes} min`;
    }

    if (hours < 24) {
        return minutes === 0 ? `${hours} hr` : `${hours} hr ${minutes} min`;
    }

    const days = Math.floor(hours / 24);
    const restHours = hours % 24;

    return restHours === 0
        ? `${days} day${days === 1 ? '' : 's'}`
        : `${days}d ${restHours}h`;
}

export type EventState = 'upcoming' | 'now' | 'ended';

const MINUTE = 60_000;
const HOUR = 3_600_000;
const DAY = 86_400_000;

const capitalize = (text: string) =>
    text.charAt(0).toUpperCase() + text.slice(1);

/** Coarse relative phrase between two instants, e.g. "in 3 days", "5 hr ago". */
const relative = (targetMs: number, nowMs: number): string => {
    const diff = targetMs - nowMs;
    const ahead = diff >= 0;
    const abs = Math.abs(diff);

    if (abs < MINUTE) {
        return ahead ? 'in moments' : 'moments ago';
    }

    if (abs < HOUR) {
        const n = Math.round(abs / MINUTE);

        return ahead ? `in ${n} min` : `${n} min ago`;
    }

    if (abs < DAY) {
        const n = Math.round(abs / HOUR);

        return ahead ? `in ${n} hr` : `${n} hr ago`;
    }

    const n = Math.round(abs / DAY);

    return ahead ? `in ${n} days` : `${n} days ago`;
};

/**
 * Where an occurrence sits relative to "now", with a short human label.
 * Timed events read at hour granularity; all-day events read in whole days
 * ("Today", "Tomorrow", "3 days ago") since a clock offset is meaningless.
 */
export function eventTiming(
    startIso: string,
    endIso: string,
    allDay: boolean,
    nowMs: number,
): { state: EventState; label: string } {
    const start = new Date(startIso).getTime();
    const end = new Date(endIso).getTime();

    if (allDay) {
        const now = new Date(nowMs);
        const days = differenceInCalendarDays(parseISO(startIso), now);

        if (nowMs >= start && nowMs < end) {
            return { state: 'now', label: 'Today' };
        }

        if (days > 0) {
            return {
                state: 'upcoming',
                label: days === 1 ? 'Tomorrow' : `In ${days} days`,
            };
        }

        return {
            state: 'ended',
            label: days === -1 ? 'Yesterday' : `${Math.abs(days)} days ago`,
        };
    }

    if (nowMs >= start && nowMs < end) {
        return { state: 'now', label: 'Happening now' };
    }

    if (nowMs < start) {
        return { state: 'upcoming', label: capitalize(relative(start, nowMs)) };
    }

    return { state: 'ended', label: `Ended ${relative(end, nowMs)}` };
}

/**
 * Friendly label for the event's stored timezone at a given instant, e.g.
 * "Manila · GMT+8". Falls back to the raw zone if the runtime can't resolve
 * an offset.
 */
export function formatTimezone(timezone: string, atIso: string): string {
    const city = (timezone.split('/').pop() ?? timezone).replace(/_/g, ' ');

    try {
        const offset = new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            timeZoneName: 'shortOffset',
        })
            .formatToParts(new Date(atIso))
            .find((part) => part.type === 'timeZoneName')?.value;

        return offset ? `${city} · ${offset}` : city;
    } catch {
        return city;
    }
}
