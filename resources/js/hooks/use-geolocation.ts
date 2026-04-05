import { useEffect, useRef, useState } from 'react';

export type GeoPosition = {
    lat: number;
    lng: number;
    accuracy: number;
    timestamp: number;
};

export type GeoQuality = 'high' | 'medium' | 'low' | 'stale' | 'estimated';

const CSRF = () =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') || '';

// ── Constants ──
const MAX_ACCURACY_M = 200; // Reject readings worse than 200m
const MAX_SPEED_KMH = 150; // Reject if implied speed > 150 km/h
const STALE_THRESHOLD_MS = 120_000; // Position is "stale" after 2 min without update
const PING_COOLDOWN_MS = 60_000; // Min 60s between server pings
const ACCURACY_HIGH = 30;
const ACCURACY_MEDIUM = 100;

/** Calculate distance in meters between two lat/lng points */
function distanceMeters(
    lat1: number,
    lng1: number,
    lat2: number,
    lng2: number,
): number {
    return Math.sqrt(
        Math.pow((lat1 - lat2) * 111320, 2) +
            Math.pow(
                (lng1 - lng2) * 111320 * Math.cos((lat1 * Math.PI) / 180),
                2,
            ),
    );
}

/** Send a location ping to the server (fire-and-forget) */
function sendPing(
    lat: number,
    lng: number,
    accuracy: number,
    quality?: string,
    rejected?: string,
) {
    fetch('/api/track', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': CSRF(),
        },
        body: JSON.stringify({
            event_type: 'location_ping',
            payload: { lat, lng, accuracy, quality, rejected },
        }),
    }).catch(() => {});
}

/**
 * Continuous GPS tracking hook with accuracy filtering.
 *
 * Phase 1 — GPS filtering:
 * - Rejects readings with accuracy > 200m
 * - Rejects impossible speed jumps (> 150 km/h)
 * - Keeps last good position as fallback
 * - Exposes `quality` state for UI indicators
 *
 * Phase 2 — Smart fallback:
 * - Detects GPS degradation (stale/lost signal)
 * - Falls back to last good position when GPS is unreliable
 * - Re-pings on visibility change with high accuracy request
 *
 * Quality levels:
 * - "high": accuracy < 30m
 * - "medium": accuracy 30-100m
 * - "low": accuracy 100-200m (borderline, used with warning)
 * - "stale": no update for > 30s, using last good position
 * - "estimated": GPS lost, using cached/estimated position
 */
export function useGeolocation(pingIntervalMs = 300_000) {
    const [position, setPosition] = useState<GeoPosition | null>(null);
    const [quality, setQuality] = useState<GeoQuality>('high');
    const [error, setError] = useState<string | null>(null);

    // Refs for filtering logic (not reactive — no re-renders)
    const lastGoodRef = useRef<GeoPosition | null>(null);
    const lastPingRef = useRef<{ lat: number; lng: number; time: number }>({
        lat: 0,
        lng: 0,
        time: 0,
    });
    const lastUpdateRef = useRef<number>(Date.now());
    const watchIdRef = useRef<number | null>(null);
    const staleTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

    function classifyQuality(accuracy: number): GeoQuality {
        if (accuracy <= ACCURACY_HIGH) return 'high';
        if (accuracy <= ACCURACY_MEDIUM) return 'medium';
        return 'low';
    }

    function handlePosition(pos: GeolocationPosition) {
        const raw: GeoPosition = {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            accuracy: pos.coords.accuracy,
            timestamp: pos.timestamp,
        };

        // ── Filter 1: Reject readings with accuracy > 200m ──
        if (raw.accuracy > MAX_ACCURACY_M) {
            sendPing(
                raw.lat,
                raw.lng,
                raw.accuracy,
                'rejected',
                `accuracy_${Math.round(raw.accuracy)}m`,
            );
            if (lastGoodRef.current) {
                setPosition(lastGoodRef.current);
                setQuality('low');
            }
            return;
        }

        // ── Filter 2: Speed-based rejection ──
        const lastGood = lastGoodRef.current;
        if (lastGood) {
            const dist = distanceMeters(
                lastGood.lat,
                lastGood.lng,
                raw.lat,
                raw.lng,
            );
            const timeDiffSec = Math.max(
                (raw.timestamp - lastGood.timestamp) / 1000,
                0.1,
            );
            const speedKmh = (dist / 1000 / timeDiffSec) * 3600;

            // If implied speed is impossibly fast, reject this reading
            if (speedKmh > MAX_SPEED_KMH && dist > 500) {
                sendPing(
                    raw.lat,
                    raw.lng,
                    raw.accuracy,
                    'rejected',
                    `speed_${Math.round(speedKmh)}kmh`,
                );
                setQuality('low');
                return;
            }
        }

        // ── Reading passed filters — accept it ──
        lastGoodRef.current = raw;
        lastUpdateRef.current = Date.now();
        setPosition(raw);
        setQuality(classifyQuality(raw.accuracy));
        setError(null);

        // ── Server ping (throttled) ──
        const last = lastPingRef.current;
        const elapsed = Date.now() - last.time;

        if (elapsed < PING_COOLDOWN_MS) {
            return;
        }

        const dist = distanceMeters(last.lat, last.lng, raw.lat, raw.lng);

        if (dist > 50 || elapsed > pingIntervalMs) {
            sendPing(
                raw.lat,
                raw.lng,
                raw.accuracy,
                classifyQuality(raw.accuracy),
            );
            lastPingRef.current = {
                lat: raw.lat,
                lng: raw.lng,
                time: Date.now(),
            };
        }
    }

    function handleError(err: GeolocationPositionError) {
        setError(err.message);
        // GPS error — mark as stale if we have a cached position
        if (lastGoodRef.current) {
            setQuality('stale');
        }
    }

    function fetchOnce() {
        if (!navigator.geolocation) {
            return;
        }

        navigator.geolocation.getCurrentPosition(handlePosition, handleError, {
            enableHighAccuracy: true,
            timeout: 10_000,
            maximumAge: 10_000,
        });
    }

    useEffect(() => {
        if (!navigator.geolocation) {
            setError('Geolocation not supported');
            return;
        }

        // Try watchPosition first — continuous updates
        try {
            watchIdRef.current = navigator.geolocation.watchPosition(
                handlePosition,
                (err) => {
                    handleError(err);
                    fetchOnce();
                },
                {
                    enableHighAccuracy: true,
                    timeout: 15_000,
                    maximumAge: 10_000,
                },
            );
        } catch {
            fetchOnce();
        }

        // Periodic ping as backup
        const pingTimer = setInterval(fetchOnce, pingIntervalMs);

        // ── Phase 2: Staleness detection ──
        // Check every 5s if position is going stale
        staleTimerRef.current = setInterval(() => {
            const age = Date.now() - lastUpdateRef.current;
            if (age > STALE_THRESHOLD_MS && lastGoodRef.current) {
                setQuality((prev) =>
                    prev === 'high' || prev === 'medium' ? 'stale' : prev,
                );
            }
        }, 5_000);

        // Re-fetch with high accuracy when user returns to app
        function onVisibilityChange() {
            if (document.visibilityState === 'visible') {
                // Reset stale timer
                lastUpdateRef.current = Date.now();
                fetchOnce();
            }
        }
        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            clearInterval(pingTimer);
            if (staleTimerRef.current) clearInterval(staleTimerRef.current);
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
            if (watchIdRef.current !== null) {
                navigator.geolocation.clearWatch(watchIdRef.current);
            }
        };
    }, []);

    return { position, quality, error, refresh: fetchOnce };
}
