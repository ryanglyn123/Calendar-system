export type ColorLabel = {
    color: string;
    name: string | null;
    /** Left out of the color menu. */
    hidden: boolean;
};

export type AuthDivision = {
    id: number;
    name: string;
    acronym: string | null;
    color: string;
};

export type User = {
    id: number;
    name: string;
    email: string;
    avatar_path?: string | null;
    status: 'pending' | 'active' | 'suspended';
    timezone: string;
    /** Their own names for event colors; null until first edited. */
    color_labels?: ColorLabel[] | null;
    division: AuthDivision | null;
    roles: string[];
    permissions: string[];
    is_super_admin: boolean;
    email_verified_at?: string | null;
    two_factor_enabled?: boolean;
    created_at?: string;
    updated_at?: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
