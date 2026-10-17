import type { Auth } from '@/types/auth';
import type { Team } from '@/types/teams';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            canChat: boolean;
            apiKeyAllowed: boolean;
            unitLabel: string | null;
            units: { type: string; level: 'headquarters' | 'branch'; name: string }[];
            rolePanel: { base: string; name: string } | null;
            sidebarOpen: boolean;
            currentTeam: Team | null;
            teams: Team[];
            [key: string]: unknown;
        };
    }
}
