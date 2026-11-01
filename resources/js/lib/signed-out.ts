import { useSyncExternalStore } from 'react';

/*
 * When the session ends while a page is open (owner, 24 Oct 2026), every
 * request fails with 401, or 419 for the stale CSRF token, and trying again
 * can't help. One place notices it: any same-origin fetch on a page that
 * was rendered signed in, and Inertia's own requests (app.tsx). The shell's
 * toast then says so, with a sign-in that comes back to this page
 * (/login?return=, FortifyServiceProvider), in place of any "try again".
 */

export const SIGNED_OUT = 'You’ve been signed out.';

let signedIn = false;
let signedOut = false;
const listeners = new Set<() => void>();

export const isSignedOutStatus = (status: number | undefined) =>
    status === 401 || status === 419;

/** The page was rendered for a signed-in person (Inertia's auth.user). */
export function setSignedIn(on: boolean): void {
    signedIn = on;
}

/** A request came back signed out: say so, once. False on a guest's page. */
export function markSignedOut(): boolean {
    if (!signedIn) {
        return false;
    }

    if (!signedOut) {
        signedOut = true;
        listeners.forEach((notify) => notify());
    }

    return true;
}

function subscribe(notify: () => void): () => void {
    listeners.add(notify);

    return () => listeners.delete(notify);
}

export function useSignedOut(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => signedOut,
        () => false,
    );
}

/** Watches every same-origin fetch for a signed-out answer. */
export function watchFetch(): void {
    const original = window.fetch.bind(window);

    window.fetch = async (...args: Parameters<typeof fetch>) => {
        const response = await original(...args);

        if (isSignedOutStatus(response.status)) {
            try {
                if (new URL(response.url).origin === window.location.origin) {
                    markSignedOut();
                }
            } catch {
                // No URL to compare: not ours to judge.
            }
        }

        return response;
    };
}
