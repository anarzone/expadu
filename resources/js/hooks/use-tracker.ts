/**
 * Fire-and-forget event tracking hook.
 * Sends events to POST /api/track without blocking the UI.
 *
 * Module-scope dedup prevents React strict mode double-mount from
 * firing identical events twice within 1 second.
 */
import { useCallback } from 'react';

let lastEvent = { type: '', time: 0 };

export function useTracker() {
    // Stable across renders so callers can list `track` in an effect's deps
    // without the effect re-firing every render. It closes over nothing
    // reactive — the dedup state is module scope and the CSRF token is read
    // from the DOM at call time.
    const track = useCallback(function track(
        eventType: string,
        payload?: Record<string, unknown>,
    ) {
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
    }, []);

    return { track };
}
