export type Division = {
    id: number;
    name: string;
    acronym: string | null;
    description: string | null;
    color: string;
    is_active: boolean;
    sort_order: number;
    users_count?: number;
};

export type Role = {
    id: number;
    name: string;
    description: string | null;
    is_system: boolean;
    permissions?: string[];
    users_count?: number;
};

export type AdminUser = {
    id: number;
    name: string;
    email: string;
    status: 'pending' | 'active' | 'suspended';
    status_label: string;
    timezone: string;
    avatar_path: string | null;
    division: Division | null;
    roles: string[];
    approved_at: string | null;
    created_at: string;
    two_factor_enabled: boolean;
};

export type Invitation = {
    id: number;
    email: string;
    division: Division | null;
    role: string | null;
    inviter: string;
    expires_at: string;
    is_expired: boolean;
    accepted_at: string | null;
    created_at: string;
};

export type CalendarItem = {
    id: number;
    type: 'organization' | 'division' | 'resource' | 'personal';
    name: string;
    description: string | null;
    color: string;
    effective_color: string;
    icon: string | null;
    is_default: boolean;
    is_active: boolean;
    is_owner: boolean;
    is_visible: boolean;
    sort_order: number;
    division?: Division | null;
    can_write_events: boolean;
    can_manage: boolean;
};

export type EventTypeItem = {
    id: number;
    name: string;
    color: string;
    icon: string | null;
    description: string | null;
    is_active: boolean;
    sort_order: number;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        per_page: number;
        to: number | null;
        total: number;
    };
};
