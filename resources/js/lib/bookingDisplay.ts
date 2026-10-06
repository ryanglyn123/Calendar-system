import type { LucideIcon } from '@lucide/vue';
import { Ban, CircleCheck, CircleX, Flag, Hourglass } from '@lucide/vue';
import { format, isSameDay } from '@/lib/calendarMath';
import type { BookingStatus, EventOccurrence } from '@/types/calendar';

/**
 * One vocabulary for booking state across chips, the booking panel and
 * lists. State never rides on hue alone: the room's or vehicle's color
 * says which resource, the fill says the state (solid approved, hatched
 * pending, hollow and struck through when it no longer holds the slot),
 * and an icon plus a word repeat it.
 */
export const BOOKING_STATE: Record<
    BookingStatus,
    {
        label: string;
        icon: LucideIcon;
        /** Chip/block fill class. */
        fill: string;
        /** Holds the slot, so it blocks other requests. */
        active: boolean;
    }
> = {
    pending: {
        label: 'Pending',
        icon: Hourglass,
        fill: 'bk-hold',
        active: true,
    },
    approved: {
        label: 'Approved',
        icon: CircleCheck,
        fill: 'ev-wash',
        active: true,
    },
    rejected: {
        label: 'Rejected',
        icon: CircleX,
        fill: 'bk-closed',
        active: false,
    },
    cancelled: {
        label: 'Cancelled',
        icon: Ban,
        fill: 'bk-closed',
        active: false,
    },
};

/** Other people's bookings read as neutral "taken" blocks for staff. */
const OTHERS = '#c3c8cf';

/**
 * The hue a booking chip is drawn in: its resource color when it is the
 * viewer's own or the viewer reviews it, a neutral gray otherwise, so a
 * requester's own bookings stand out from the rest of the room's traffic.
 */
export function bookingTone(occurrence: EventOccurrence): string | null {
    const booking = occurrence.booking;

    if (!booking || booking.is_mine || booking.can_review) {
        return occurrence.color;
    }

    return OTHERS;
}

/** Fill class for an occurrence's chip; ordinary events keep the wash. */
export function chipFill(occurrence: EventOccurrence): string {
    if (occurrence.source === 'holiday') {
        return 'ev-holiday';
    }

    // Google entries marked "free" (working location, declined, FYI) recede.
    if (occurrence.source === 'google' && occurrence.google?.busy === false) {
        return 'ev-wash ev-free';
    }

    return occurrence.booking
        ? BOOKING_STATE[occurrence.booking.status].fill
        : 'ev-wash';
}

/** Icon shown ahead of a booking chip's title, when its state needs one. */
export function chipIcon(occurrence: EventOccurrence): LucideIcon | null {
    if (occurrence.source === 'holiday') {
        return Flag;
    }

    const status = occurrence.booking?.status;

    return status && status !== 'approved' ? BOOKING_STATE[status].icon : null;
}

/** Spoken prefix for a chip: "Your booking, pending". */
export function chipState(occurrence: EventOccurrence): string {
    if (occurrence.source === 'holiday') {
        return occurrence.holiday?.blocks_bookings
            ? 'Holiday, bookings paused. '
            : 'Holiday. ';
    }

    if (occurrence.source === 'google') {
        return 'From Google Calendar. ';
    }

    const booking = occurrence.booking;

    if (!booking) {
        return '';
    }

    const whose = booking.is_mine
        ? 'Your booking'
        : `Booked by ${booking.requested_by ?? 'someone'}`;

    return `${whose}, ${BOOKING_STATE[booking.status].label.toLowerCase()}. `;
}

/** A pending or approved booking: it keeps others off the slot. */
export function holdsSlot(occurrence: EventOccurrence): boolean {
    return (
        occurrence.booking !== null &&
        BOOKING_STATE[occurrence.booking.status].active
    );
}

/**
 * Where one room or vehicle stands at `now`, from the occurrences on its
 * calendar: "In use until 11:00", "Free until 13:00", "Free rest of day".
 */
export function liveStatus(
    occurrences: EventOccurrence[],
    now: Date,
): { free: boolean; label: string } {
    const active = occurrences.filter(holdsSlot);
    const current = active.find(
        (o) => new Date(o.starts_at) <= now && new Date(o.ends_at) > now,
    );

    if (current) {
        const until = new Date(current.ends_at);
        const verb = current.booking!.status === 'pending' ? 'Held' : 'In use';

        return {
            free: false,
            label: `${verb} until ${format(until, isSameDay(until, now) ? 'HH:mm' : 'EEE HH:mm')}`,
        };
    }

    const next = active
        .map((o) => new Date(o.starts_at))
        .filter((start) => start > now && isSameDay(start, now))
        .sort((a, b) => a.getTime() - b.getTime())[0];

    return {
        free: true,
        label: next
            ? `Free until ${format(next, 'HH:mm')}`
            : 'Free rest of day',
    };
}

/**
 * Whether a view should draw an occurrence. My Calendar also carries the
 * viewer's own bookings, whose resource calendars are not in its list;
 * those pass through. Everything else follows the calendar toggles.
 */
export function isShown(
    occurrence: EventOccurrence,
    visibleIds: Set<number>,
    listedIds: Set<number>,
): boolean {
    // The organization's holidays show on every calendar.
    if (occurrence.source === 'holiday') {
        return true;
    }

    if (visibleIds.has(occurrence.calendar_id)) {
        return true;
    }

    return (
        occurrence.booking?.is_mine === true &&
        !listedIds.has(occurrence.calendar_id)
    );
}
