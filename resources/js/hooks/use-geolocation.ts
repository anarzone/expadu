import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { csrfHeaders } from '@/lib/csrf';

export type GeoPosition = {
    lat: number;
    lng: number;
    accuracy: number;
    timestamp: number;
};

export type GeoQuality = 'high' | 'medium' | 'low' | 'stale' | 'estimated';

// ── Constants ──
export const MAX_LOCATION_ACCURACY_M = 200; // Reject readings worse than 200m
const MAX_SPEED_KMH = 150; // Reject if implied speed > 150 km/h
const STALE_THRESHOLD_MS = 120_000; // Position is "stale" after 2 min without update
const PING_COOLDOWN_MS = 60_000; // Min 60s between server pings
const PING_DISTANCE_M = 200; // Only ping server when user moves 200m+
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

export function useLocationSharingEnabled(): boolean {
    const { userSettings } = usePage<{
        userSettings?: { share_location?: boolean };
    }>().props;

    return userSettings?.share_location !== false;
}

/** Send a location ping to the server (fire-and-forget) */
async function sendPing(
    signal: AbortSignal,
    lat: number,
    lng: number,
    accuracy: number,
    quality?: string,
    rejected?: string,
): Promise<boolean> {
    if (signal.aborted) {
        return false;
    }

    try {
        const response = await fetch('/api/track', {
            method: 'POST',
            signal,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                ...csrfHeaders(),
            },
            body: JSON.stringify({
                event_type: 'location_ping',
                payload: { lat, lng, accuracy, quality, rejected },
            }),
        });

        return !signal.aborted && response.ok;
    } catch {
        return false;
    }
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
 * - "stale": no update for > 2 min, using last good position
 * - "estimated": GPS lost, using cached/estimated position
 */
export function createGeolocationSession({
    pingIntervalMs = 300_000,
    onPosition: setPosition,
    onQuality: setQuality,
    onError: setError,
}: {
    pingIntervalMs?: number;
    onPosition: (position: GeoPosition | null) => void;
    onQuality: (quality: GeoQuality) => void;
    onError: (error: string | null) => void;
}) {
    const controller = new AbortController();
    const { signal } = controller;
    let lastGood: GeoPosition | null = null;
    let lastPing = { lat: 0, lng: 0, time: 0 };
    let lastUpdate = Date.now();
    let watchId: number | null = null;
    let pingTimer: ReturnType<typeof setInterval> | null = null;
    let staleTimer: ReturnType<typeof setInterval> | null = null;

    function classifyQuality(accuracy: number): GeoQuality {
        if (accuracy <= ACCURACY_HIGH) {
            return 'high';
        }

        if (accuracy <= ACCURACY_MEDIUM) {
            return 'medium';
        }

        return 'low';
    }

    function handlePosition(pos: GeolocationPosition) {
        if (signal.aborted) {
            return;
        }

        const raw: GeoPosition = {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            accuracy: pos.coords.accuracy,
            timestamp: pos.timestamp,
        };

        // ── Filter 1: Reject readings with accuracy > 200m ──
        if (raw.accuracy > MAX_LOCATION_ACCURACY_M) {
            void sendPing(
                signal,
                raw.lat,
                raw.lng,
                raw.accuracy,
                'rejected',
                `accuracy_${Math.round(raw.accuracy)}m`,
            );

            if (lastGood) {
                setPosition(lastGood);
                setQuality('low');
            }

            return;
        }

        // ── Filter 2: Speed-based rejection ──
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
                void sendPing(
                    signal,
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
        lastGood = raw;
        lastUpdate = Date.now();
        setPosition(raw);
        setQuality(classifyQuality(raw.accuracy));
        setError(null);

        // ── Server ping (throttled) ──
        const last = lastPing;
        const elapsed = Date.now() - last.time;

        if (elapsed < PING_COOLDOWN_MS) {
            return;
        }

        const dist = distanceMeters(last.lat, last.lng, raw.lat, raw.lng);

        if (dist > PING_DISTANCE_M || elapsed > pingIntervalMs) {
            void sendPing(
                signal,
                raw.lat,
                raw.lng,
                raw.accuracy,
                classifyQuality(raw.accuracy),
            ).then((stored) => {
                if (stored && !signal.aborted) {
                    window.dispatchEvent(
                        new CustomEvent('expadu:location-stored', {
                            detail: raw,
                        }),
                    );
                }
            });
            lastPing = {
                lat: raw.lat,
                lng: raw.lng,
                time: Date.now(),
            };
        }
    }

    function handleError(err: GeolocationPositionError) {
        if (signal.aborted) {
            return;
        }

        setError(err.message);

        // GPS error — mark as stale if we have a cached position
        if (lastGood) {
            setQuality('stale');
        }
    }

    function fetchOnce() {
        if (signal.aborted || !navigator.geolocation) {
            return;
        }

        navigator.geolocation.getCurrentPosition(handlePosition, handleError, {
            enableHighAccuracy: true,
            timeout: 10_000,
            maximumAge: 10_000,
        });
    }

    if (!navigator.geolocation) {
        setError('Geolocation not supported');
    } else {
        // Try watchPosition first — continuous updates
        try {
            watchId = navigator.geolocation.watchPosition(
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
        pingTimer = setInterval(fetchOnce, pingIntervalMs);

        // ── Phase 2: Staleness detection ──
        // Check every 5s if position is going stale
        staleTimer = setInterval(() => {
            const age = Date.now() - lastUpdate;

            if (!signal.aborted && age > STALE_THRESHOLD_MS && lastGood) {
                setQuality('stale');
            }
        }, 5_000);

        // Re-fetch with high accuracy when user returns to app
        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('pageshow', fetchOnce);
        window.addEventListener('focus', fetchOnce);
    }

    function onVisibilityChange() {
        if (document.visibilityState === 'visible') {
            fetchOnce();
        }
    }

    return {
        refresh: fetchOnce,
        stop() {
            if (signal.aborted) {
                return;
            }

            controller.abort();

            if (pingTimer !== null) {
                clearInterval(pingTimer);
            }

            if (staleTimer !== null) {
                clearInterval(staleTimer);
            }

            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
            window.removeEventListener('pageshow', fetchOnce);
            window.removeEventListener('focus', fetchOnce);

            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
            }

            lastGood = null;
            setPosition(null);
            setQuality('estimated');
            setError(null);
        },
    };
}

export function observeGeolocationPermission(
    onChange: (granted: boolean) => void,
): () => void {
    let disposed = false;
    let status: PermissionStatus | null = null;
    const sync = () => {
        if (!disposed) {
            onChange(status?.state === 'granted');
        }
    };

    onChange(false);
    navigator.permissions
        ?.query?.({ name: 'geolocation' })
        .then((result) => {
            if (disposed) {
                return;
            }

            status = result;
            sync();
            status.addEventListener('change', sync);
        })
        .catch(() => {});

    return () => {
        disposed = true;
        status?.removeEventListener('change', sync);
        onChange(false);
    };
}

// ── Background tracking, one session per page load ──
// The app layout remounts on every Inertia visit. Without sharing, each mount
// started a fresh high-accuracy watch and its first fix sent a new ping (on
// Travel that ping reloaded the boards again). The layout now borrows one
// module-level session: a remount within the grace window keeps the running
// watch and its ping cooldown. Opting out or revoking permission still stops
// it at once.
const BACKGROUND_RELEASE_GRACE_MS = 3_000;

let backgroundSession: ReturnType<typeof createGeolocationSession> | null =
    null;
let backgroundUsers = 0;
let backgroundGranted = false;
let stopBackgroundPermission: (() => void) | null = null;
let backgroundReleaseTimer: ReturnType<typeof setTimeout> | null = null;

const ignore = () => {};

function syncBackgroundSession() {
    if (backgroundUsers > 0 && backgroundGranted && !backgroundSession) {
        backgroundSession = createGeolocationSession({
            onPosition: ignore,
            onQuality: ignore,
            onError: ignore,
        });
    } else if (!backgroundGranted && backgroundSession) {
        backgroundSession.stop();
        backgroundSession = null;
    }
}

/**
 * Join the shared background session. It starts only once the browser
 * permission is already granted — this never prompts. Returns the release
 * function; the session outlives a release by a short grace period so a
 * remounting layout picks it straight back up.
 */
export function acquireBackgroundLocation(): () => void {
    if (backgroundReleaseTimer !== null) {
        clearTimeout(backgroundReleaseTimer);
        backgroundReleaseTimer = null;
    }

    backgroundUsers++;
    stopBackgroundPermission ??= observeGeolocationPermission((granted) => {
        backgroundGranted = granted;
        syncBackgroundSession();
    });
    syncBackgroundSession();

    let released = false;

    return () => {
        if (released) {
            return;
        }

        released = true;
        backgroundUsers = Math.max(0, backgroundUsers - 1);

        if (backgroundUsers === 0) {
            backgroundReleaseTimer = setTimeout(() => {
                backgroundReleaseTimer = null;

                if (backgroundUsers === 0) {
                    stopBackgroundLocation();
                }
            }, BACKGROUND_RELEASE_GRACE_MS);
        }
    };
}

/** Stop background tracking now (opting out), whoever still holds it. */
export function stopBackgroundLocation() {
    if (backgroundReleaseTimer !== null) {
        clearTimeout(backgroundReleaseTimer);
        backgroundReleaseTimer = null;
    }

    backgroundSession?.stop();
    backgroundSession = null;
    backgroundUsers = 0;
    stopBackgroundPermission?.();
    stopBackgroundPermission = null;
    backgroundGranted = false;
}

export function useGeolocation({
    enabled = true,
    pingIntervalMs = 300_000,
}: { enabled?: boolean; pingIntervalMs?: number } = {}) {
    const sharingEnabled = useLocationSharingEnabled();
    const tracking = enabled && sharingEnabled;
    const [position, setPosition] = useState<GeoPosition | null>(null);
    const [quality, setQuality] = useState<GeoQuality>('high');
    const [error, setError] = useState<string | null>(null);
    const session = useRef<ReturnType<typeof createGeolocationSession> | null>(
        null,
    );

    useEffect(() => {
        if (!tracking) {
            return;
        }

        const current = createGeolocationSession({
            pingIntervalMs,
            onPosition: setPosition,
            onQuality: setQuality,
            onError: setError,
        });
        session.current = current;

        return () => {
            current.stop();
            session.current = null;
        };
    }, [tracking, pingIntervalMs]);

    const refresh = useCallback(() => {
        if (tracking) {
            session.current?.refresh();
        }
    }, [tracking]);

    return {
        position: tracking ? position : null,
        quality: tracking ? quality : ('estimated' as GeoQuality),
        error: tracking ? error : null,
        refresh,
    };
}
