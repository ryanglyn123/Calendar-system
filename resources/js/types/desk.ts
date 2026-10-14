/** Admin desk props, as shaped by App\Services\Admin\AdminDashboard. */

export type DeskClash = {
    title: string;
    status: 'pending' | 'approved';
    starts_at: string;
    ends_at: string;
};

type DeskResourceFields = {
    resource: string;
    color: string | null;
    type: string;
    requester: string | null;
    /** The requester's division, acronym when it has one. */
    division: string | null;
    starts_at: string;
    ends_at: string;
};

export type DeskBookingRequest = DeskResourceFields & {
    key: string;
    kind: 'booking';
    id: number;
    title: string;
    purpose: string | null;
    attendee_count: number | null;
    capacity: number | null;
    requested_at: string | null;
    clash: DeskClash | null;
};

/** `starts_at`/`ends_at` are the proposed times; `current` is the booking now. */
export type DeskChangeRequest = DeskResourceFields & {
    key: string;
    kind: 'change';
    id: number;
    booking_id: number;
    title: string;
    current: {
        starts_at: string;
        ends_at: string;
        purpose: string | null;
        attendee_count: number | null;
    };
    proposed_purpose: string | null;
    proposed_attendee_count: number | null;
    note: string | null;
    requested_at: string | null;
};

export type DeskRequest = DeskBookingRequest | DeskChangeRequest;

/** One desk in the super admin's switcher. */
export type DeskTab = {
    slug: string;
    name: string;
    icon: string | null;
    waiting: number;
};

/** One day in the desk's week strip. */
export type DeskWeekDay = {
    date: string;
    bookings: number;
    waiting: number;
};

export type DeskBooking = {
    id: number;
    title: string;
    requester: string | null;
    starts_at: string;
    ends_at: string;
    status: 'pending' | 'approved';
    purpose: string | null;
    attendee_count: number | null;
    can_approve: boolean;
};

export type DeskResource = {
    id: number;
    name: string;
    location: string | null;
    color: string | null;
    bookings: DeskBooking[];
};

export type DeskResourceType = {
    id: number;
    name: string;
    slug: string;
    icon: string | null;
    resources: DeskResource[];
};

export type DeskSchedule = {
    date: string;
    types: DeskResourceType[];
};

/* ---- Booking review ---------------------------------------------------- */

export type ReviewTypeRef = {
    slug: string;
    name: string;
    icon: string | null;
};

/** What waits on a reviewer, live (shared prop and notification poll). */
export type ReviewSummary = {
    total: number;
    types: (ReviewTypeRef & { count: number })[];
    items: {
        id: number;
        type: string;
        title: string;
        resource: string;
        requester: string | null;
        starts_at: string;
        ends_at: string;
        passed: boolean;
        clash: 'approved' | 'pending' | null;
    }[];
};

/** One request on its review page, in whatever state it is now. */
export type ReviewDetail = {
    id: number;
    /** "BK-000123", as printed on the booking form. */
    reference: string;
    version: string | null;
    status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    removed: boolean;
    title: string;
    purpose: string | null;
    attendee_count: number | null;
    /** Named attendees or passengers; empty on bookings made before names were kept. */
    people: string[];
    type: ReviewTypeRef | null;
    resource: {
        id: number;
        name: string | null;
        location: string | null;
        capacity: number | null;
        color: string | null;
    };
    requester: {
        name: string | null;
        email: string | null;
        division: string | null;
    };
    starts_at: string;
    ends_at: string;
    requested_at: string | null;
    reviewed_by: string | null;
    reviewed_at: string | null;
    rejection_reason: string | null;
    cancelled_by: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    conflicts: {
        id: number;
        title: string;
        status: 'pending' | 'approved';
        requester: string | null;
        starts_at: string;
        ends_at: string;
    }[];
};

/** A review link that led nowhere: the id is unknown or was never valid. */
export type ReviewMissing = { id: number | null; missing: true };
