/**
 * Fire-and-forget event tracking hook.
 * Sends events to POST /api/track without blocking the UI.
 */
export function useTracker() {
    function track(eventType: string, payload?: Record<string, unknown>) {
        fetch('/api/track', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                event_type: eventType,
                ...(payload ? { payload } : {}),
            }),
        }).catch(() => {});
    }

    return { track };
}
