export type User = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    transport_mode?: 'transit' | 'bike' | 'walk' | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    /** False for an account made with Google: it confirms with an emailed code. */
    has_password: boolean;
};
