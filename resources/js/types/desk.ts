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

export type DeskChangeRequest = DeskResourceFields & {
    key: string;
    kind: 'change';
    id: number;
    booking_id: number;
    title: string;
    current: { starts_at: string; ends_at: string; purpose: string | null };
    proposed_purpose: string | null;
    note: string | null;
    requested_at: string | null;
};

export type DeskRegistration = {
    key: string;
    kind: 'registration';
    id: number;
    title: string;
    email: string;
    division: string | null;
    requested_at: string | null;
};

export type DeskQueueItem =
    DeskBookingRequest | DeskChangeRequest | DeskRegistration;

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

export type DeskDemandDay = {
    date: string;
    approved: number;
    pending: number;
    count: number;
};

export type DeskPeople = {
    active: number;
    pending: number;
    suspended: number;
    without_division: number;
    invitations_open: number;
    invitations_expiring: number;
};

export type DeskAnnouncements = {
    live: {
        id: number;
        title: string;
        level: 'info' | 'warning' | 'critical';
        audience: string | null;
        ends_at: string | null;
    }[];
    scheduled: number;
};

export type DeskActivity = {
    id: number;
    description: string;
    event: string | null;
    causer: string | null;
    subject_type: string | null;
    subject_id: number | null;
    created_at: string;
};

export type DeskHealth = { failed: number; queued: number };

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
