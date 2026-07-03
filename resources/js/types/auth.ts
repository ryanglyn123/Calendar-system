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

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
