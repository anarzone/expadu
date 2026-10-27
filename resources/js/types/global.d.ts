import type { Auth } from '@/types/auth';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            auth: Auth;
            vapidPublicKey: string | null;
            /** The person's own saved needs (HandleInertiaRequests). */
            needs: { step_free: boolean } | null;
            [key: string]: unknown;
        };
    }
}
