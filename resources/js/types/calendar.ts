import type { CalendarItem, EventTypeItem } from './admin';

export type CalendarView =
    'month' | 'week' | 'day' | 'days4' | 'year' | 'agenda';

/**
 * Narrows a calendar page to a subset of the accessible calendars: the
 * merged dashboard, the FDCP public calendar, and each resource type's
 * section page all render the same shell under different scopes.
 */
export type CalendarScope = {
    types?: ('organization' | 'division' | 'personal' | 'resource')[];
    resourceTypeId?: number;
};

export type CalendarPanelGroup = {
    key: 'organization' | 'division' | 'personal' | 'resource';
    label: string;
    /** Show unit-calendar management (create/edit/delete) for this group. */
    manageUnits?: boolean;
};

export type RsvpStatus = 'pending' | 'accepted' | 'declined' | 'tentative';

export type EventAttendee = {
    user_id: number | null;
    name: string;
    avatar_path: string | null;
    rsvp_status: RsvpStatus;
    is_organizer: boolean;
};

export type EventOrganizer = {
    user_id: number | null;
    name: string;
    avatar_path: string | null;
};

export type BookingStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';

export type EventBookingInfo = {
    id: number;
    /** "BK-000123", as printed on the booking form. */
    reference: string;
    status: BookingStatus;
    status_label: string;
    status_color: string;
    resource: string | null;
    resource_type: string | null;
    resource_type_slug: string | null;
    capacity: number | null;
    /** Who holds the slot; shown to everyone who can see the booking. */
    requested_by: string | null;
    is_mine: boolean;
    /** The viewer reviews this resource type (approve, reject, cancel). */
    can_review: boolean;
    can_cancel: boolean;
    /* The rest is null unless the viewer is the requester or a reviewer. */
    purpose: string | null;
    attendee_count: number | null;
    /** Named attendees or passengers; null when the viewer may not see them. */
    people: string[] | null;
    requested_at: string | null;
    reviewed_by: string | null;
    reviewed_at: string | null;
    rejection_reason: string | null;
    cancellation_reason: string | null;
    cancelled_by_reviewer: boolean;
    /** Echoed back on decisions so a stale view is refused, not acted on. */
    version: string | null;
};

export type UserSearchResult = {
    id: number;
    name: string;
    email: string;
    division: string | null;
};

export type AppNotification = {
    id: string;
    key: string | null;
    title: string;
    message: string;
    action_url: string | null;
    /** The booking a booking notice is about. */
    booking_id: number | null;
    /** For booking notices: where the booking stands now, which may have
     *  moved on since the notice was sent. */
    booking_status:
        'pending' | 'approved' | 'rejected' | 'cancelled' | 'removed' | null;
    read_at: string | null;
    created_at: string;
};

export type EventOccurrence = {
    occurrence_id: string;
    event_id: number;
    calendar_id: number;
    title: string;
    description: string | null;
    location: string | null;
    meeting_url: string | null;
    starts_at: string;
    ends_at: string;
    all_day: boolean;
    status: 'confirmed' | 'tentative' | 'cancelled';
    color: string;
    event_type: EventTypeItem | null;
    is_recurring: boolean;
    rrule: string | null;
    attendees: EventAttendee[];
    organizer: EventOrganizer | null;
    booking: EventBookingInfo | null;
    my_rsvp: RsvpStatus | null;
    my_reminder_minutes: number[];
    is_private: boolean;
    is_masked: boolean;
    can_edit: boolean;
    created_by: number | null;
    timezone: string;
    created_at: string | null;
};

export type EventDraft = {
    calendar_id: number | null;
    event_type_id: number | null;
    title: string;
    description: string;
    location: string;
    meeting_url: string;
    starts_at: string;
    ends_at: string;
    all_day: boolean;
    color: string | null;
    visibility: 'default' | 'private';
};

export type { CalendarItem, EventTypeItem };
