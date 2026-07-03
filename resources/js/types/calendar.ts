import type { CalendarItem, EventTypeItem } from './admin';

export type CalendarView = 'month' | 'week' | 'day' | 'agenda';

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
    is_private: boolean;
    is_masked: boolean;
    can_edit: boolean;
    created_by: number | null;
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
