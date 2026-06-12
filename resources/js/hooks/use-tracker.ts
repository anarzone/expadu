/**
 * Fire-and-forget event tracking hook.
 * Sends events to POST /api/track without blocking the UI.
 *
 * Module-scope dedup prevents React strict mode double-mount from
 * firing identical events twice within 1 second.
 */
let lastEvent = { type: '', time: 0 };

export function useTracker() {
    function track(eventType: string, payload?: Record<string, unknown>) {
        const now = Date.now();

        if (eventType === lastEvent.type && now - lastEvent.time < 1000) {
            return;
        }

        lastEvent = { type: eventType, time: now };

        fetch('/api/track', {
            method: 'POST',
            // Survive page navigations — aborted beacons log console errors.
            keepalive: true,
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
