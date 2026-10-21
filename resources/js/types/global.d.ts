import type { Auth } from '@/types/auth';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            vapidPublicKey: string | null;
            [key: string]: unknown;
        };
    }
}
