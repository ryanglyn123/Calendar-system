import { differenceInCalendarDays } from 'date-fns';
import { format, parseISO } from '@/lib/calendarMath';
import adminBookings from '@/routes/admin/bookings';
import adminEditRequests from '@/routes/admin/edit-requests';

export type DecisionKind = 'booking' | 'change';

/** Where each decision posts; both return to the desk with fresh props. */
export const decisionUrls: Record<
    DecisionKind,
    { approve: (id: number) => string; reject: (id: number) => string }
> = {
    booking: {
        approve: (id) => adminBookings.approve(id).url,
        reject: (id) => adminBookings.reject(id).url,
    },
    change: {
        approve: (id) => adminEditRequests.apply(id).url,
        reject: (id) => adminEditRequests.reject(id).url,
    },
};

/** The desk's one button family: pills, the black one decides. */
export const pill =
    'inline-flex h-8 shrink-0 items-center justify-center gap-1.5 rounded-full px-3.5 text-[13px] font-medium whitespace-nowrap transition-[background-color,color,box-shadow,opacity] duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring/60 focus-visible:ring-offset-2 focus-visible:ring-offset-card disabled:pointer-events-none disabled:opacity-45 [&_svg]:size-3.5 [&_svg]:shrink-0';

export const pillPrimary = `${pill} bg-primary text-primary-foreground hover:bg-primary/85`;

export const pillSuccess = `${pill} bg-success text-success-foreground hover:bg-success/90`;

export const pillQuiet = `${pill} text-foreground/80 ring-1 ring-border ring-inset hover:bg-muted hover:text-foreground`;

export const pillDanger = `${pill} bg-destructive text-destructive-foreground hover:bg-destructive/90`;

/** Round icon button for steppers and toggles. */
export const iconButton =
    'inline-flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors duration-150 outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/60 disabled:pointer-events-none disabled:opacity-40 [&_svg]:size-4';

/** "Today", "Tomorrow", "Yesterday" or "Wed, Sep 30". */
export function dayLabel(iso: string, now: Date = new Date()): string {
    const date = parseISO(iso);
    const offset = differenceInCalendarDays(date, now);

    if (offset === 0) {
        return 'Today';
    }

    if (offset === 1) {
        return 'Tomorrow';
    }

    if (offset === -1) {
        return 'Yesterday';
    }

    return format(date, 'EEE, MMM d');
}

/** "09:00–11:30" in the office's 24-hour style. */
export function timeRange(startIso: string, endIso: string): string {
    return `${format(parseISO(startIso), 'HH:mm')}–${format(parseISO(endIso), 'HH:mm')}`;
}

/** Booked minutes as "7 h", "7.5 h" or "45 min". */
export function hoursLabel(minutes: number): string {
    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = Math.round((minutes / 60) * 10) / 10;

    return `${hours} h`;
}

/** Local YYYY-MM-DD for a Date, matching the desk's `date` query. */
export function isoDate(date: Date): string {
    return format(date, 'yyyy-MM-dd');
}
