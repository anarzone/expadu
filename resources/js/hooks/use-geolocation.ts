import { useEffect, useRef, useState } from 'react';

type GeoPosition = {
    lat: number;
    lng: number;
    accuracy: number;
    timestamp: number;
};

const CSRF = () =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') || '';

/** Send a location ping to the server (fire-and-forget) */
function sendPing(lat: number, lng: number, accuracy: number) {
    fetch('/api/track', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF() },
        body: JSON.stringify({
            event_type: 'location_ping',
            payload: { lat, lng, accuracy },
        }),
    }).catch(() => {});
}

/**
 * Continuous GPS tracking hook.
 *
 * - Uses `watchPosition` for real-time updates on all devices (foreground + background on mobile PWA)
 * - Falls back to `getCurrentPosition` polling if watchPosition fails
 * - Sends `location_ping` to server every `pingIntervalMs` (default 5 min)
 * - Re-pings on `visibilitychange` when user returns to the app
 * - Deduplicates pings: only sends when moved >50m from last ping
 */
export function useGeolocation(pingIntervalMs = 300_000) {
    const [position, setPosition] = useState<GeoPosition | null>(null);
    const [error, setError] = useState<string | null>(null);
    const lastPingRef = useRef<{ lat: number; lng: number; time: number }>({
        lat: 0,
        lng: 0,
        time: 0,
    });
    const watchIdRef = useRef<number | null>(null);

    function handlePosition(pos: GeolocationPosition) {
        const p: GeoPosition = {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            accuracy: pos.coords.accuracy,
            timestamp: pos.timestamp,
        };
        setPosition(p);
        setError(null);

        // Send ping if moved >50m or enough time passed
        const last = lastPingRef.current;
        const dist = Math.sqrt(
            Math.pow((p.lat - last.lat) * 111320, 2) +
                Math.pow(
                    (p.lng - last.lng) *
                        111320 *
                        Math.cos((p.lat * Math.PI) / 180),
                    2,
                ),
        );
        const elapsed = Date.now() - last.time;

        // Skip if less than 60s since last ping (prevents burst on page load/refresh)
        if (elapsed < 60_000) {
            return;
        }

        if (dist > 50 || elapsed > pingIntervalMs) {
            sendPing(p.lat, p.lng, p.accuracy);
            lastPingRef.current = { lat: p.lat, lng: p.lng, time: Date.now() };
        }
    }

    function handleError(err: GeolocationPositionError) {
        setError(err.message);
    }

    function fetchOnce() {
        if (!navigator.geolocation) {
            return;
        }

        navigator.geolocation.getCurrentPosition(handlePosition, handleError, {
            enableHighAccuracy: false,
            timeout: 10_000,
            maximumAge: 60_000,
        });
    }

    useEffect(() => {
        if (!navigator.geolocation) {
            setError('Geolocation not supported');

            return;
        }

        // Try watchPosition first — provides continuous updates
        try {
            watchIdRef.current = navigator.geolocation.watchPosition(
                handlePosition,
                (err) => {
                    handleError(err);
                    // Fall back to polling if watch fails
                    fetchOnce();
                },
                {
                    enableHighAccuracy: false,
                    timeout: 15_000,
                    maximumAge: 60_000,
                },
            );
        } catch {
            // watchPosition not supported — fall back to polling
            fetchOnce();
        }

        // Periodic ping as backup (watchPosition may throttle in background)
        const timer = setInterval(fetchOnce, pingIntervalMs);

        // Re-ping when user returns to the app (tab becomes visible again)
        function onVisibilityChange() {
            if (document.visibilityState === 'visible') {
                fetchOnce();
            }
        }
        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            clearInterval(timer);
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );

            if (watchIdRef.current !== null) {
                navigator.geolocation.clearWatch(watchIdRef.current);
            }
        };
    }, []);

    return { position, error, refresh: fetchOnce };
}
