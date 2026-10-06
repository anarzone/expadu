/** Remove only Expadu account data; leave public assets and device appearance alone. */
export async function clearPrivateBrowserData(): Promise<void> {
    try {
        for (const key of Object.keys(sessionStorage)) {
            if (key.startsWith('expadu')) {
                sessionStorage.removeItem(key);
            }
        }
    } catch {
        // Storage may be disabled by the browser.
    }

    try {
        for (const key of Object.keys(localStorage)) {
            if (key.startsWith('expadu-events-saved-')) {
                localStorage.removeItem(key);
            }
        }
    } catch {
        // Legacy device-only saved events are also private account data.
    }

    try {
        if ('serviceWorker' in navigator) {
            const registration =
                await navigator.serviceWorker.getRegistration();
            const subscription =
                await registration?.pushManager.getSubscription();
            await subscription?.unsubscribe();
        }
    } catch {
        // Logout remains possible if this device no longer exposes push.
    }

    try {
        if ('caches' in window) {
            await Promise.all(
                ['inertia-pages', 'api'].map((key) => caches.delete(key)),
            );
        }
    } catch {
        // Private responses are never cached by the current worker.
    }
}
