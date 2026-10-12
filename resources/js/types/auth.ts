export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    /** Zdjęcie profilowe albo domyślny awatar */
    avatar_url?: string;
    avatar_path?: string | null;
    organization_id?: number | null;
    barcode?: string | null;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
