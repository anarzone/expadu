import { useCallback, useEffect, useRef, useState } from 'react';

/** A timeline node the live cursor can sit on — scheduled time + coordinates. */
export type ProgressStep = {
    at: number;
    lat: number | null;
    lng: number | null;
};

/** Metres between two lat/lng points (haversine). */
function metres(
    aLat: number,
    aLng: number,
    bLat: number,
    bLng: number,
): number {
    const R = 6371000;
    const dLat = ((bLat - aLat) * Math.PI) / 180;
    const dLng = ((bLng - aLng) * Math.PI) / 180;
    const s1 = Math.sin(dLat / 2);
    const s2 = Math.sin(dLng / 2);
    const lat1 = (aLat * Math.PI) / 180;
    const lat2 = (bLat * Math.PI) / 180;
    const a = s1 * s1 + Math.cos(lat1) * Math.cos(lat2) * s2 * s2;

    return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
}

/** You count as "at" a stop within this radius (widened by GPS accuracy). */
const MATCH_RADIUS_M = 120;

type Fix = { lat: number; lng: number; acc: number };

export type TripProgress = {
    /** The furthest step reached, or -1 before the trip has begun. */
    currentIdx: number;
    /** True while a real GPS fix is driving the cursor (vs. the schedule clock). */
    live: boolean;
};

/** Last step whose scheduled time has passed; -1 before the first. */
function scheduleIdx(steps: ProgressStep[], now: number): number {
    let idx = -1;

    for (let i = 0; i < steps.length; i++) {
        if (steps[i].at <= now) {
            idx = i;
        }
    }

    return idx;
}

/** Nearest located step within range of a fix, else -1. */
function gpsIdx(steps: ProgressStep[], fix: Fix): number {
    let bestD = Infinity;
    let bestI = -1;

    for (let i = 0; i < steps.length; i++) {
        const s = steps[i];

        if (s.lat == null || s.lng == null) {
            continue;
        }

        const d = metres(fix.lat, fix.lng, s.lat, s.lng);

        if (d < bestD) {
            bestD = d;
            bestI = i;
        }
    }

    return bestI >= 0 && bestD <= MATCH_RADIUS_M + fix.acc ? bestI : -1;
}

/** A stable key for a step list, to reset progress when the journey swaps. */
function stepsKey(steps: ProgressStep[]): string {
    if (steps.length === 0) {
        return '';
    }

    return `${steps.length}:${steps[0].at}:${steps[steps.length - 1].at}`;
}

/**
 * The live position on a journey timeline. When enabled (an actually-started
 * trip, in the foreground) it watches GPS and snaps the cursor to the nearest
 * planned stop you've reached; otherwise — or before the first fix — it falls
 * back to the schedule clock. The cursor only ever moves forward, so a noisy
 * fix or a re-sort can't make the timeline jump backwards.
 *
 * State updates come only from the interval tick and GPS callbacks (never a
 * render-time write), and progress resets to the schedule position whenever the
 * journey (step list) changes, via the adjust-state-in-render pattern.
 */
export function useTripProgress(
    steps: ProgressStep[],
    enabled: boolean,
): TripProgress {
    // Starts before-departure (-1); the mount/journey-change effect below fills
    // in the real schedule position immediately (via a 0ms timer, off-render).
    const [reached, setReached] = useState(-1);
    const [hasFix, setHasFix] = useState(false);

    // Latest inputs for the async callbacks, kept in refs so they read fresh
    // values without re-subscribing the interval/watch on every render.
    const stepsRef = useRef(steps);
    const posRef = useRef<Fix | null>(null);
    const enabledRef = useRef(enabled);

    useEffect(() => {
        stepsRef.current = steps;
    }, [steps]);
    useEffect(() => {
        enabledRef.current = enabled;
    }, [enabled]);

    // Reset the high-water mark when the journey changes (switch trip / re-plan)
    // — the documented adjust-in-render pattern. The effect below then recomputes
    // the real position off-render (Date.now() may not be read during render).
    const key = stepsKey(steps);
    const [prevKey, setPrevKey] = useState(key);

    if (prevKey !== key) {
        setPrevKey(key);
        setReached(-1);
    }

    // Fuse schedule + GPS into the next forward-only cursor. Called only from
    // timers/GPS callbacks, so this never sets state during render or an effect.
    const applyProgress = useCallback(() => {
        const s = stepsRef.current;
        const fix = enabledRef.current ? posRef.current : null;
        const sched = scheduleIdx(s, Date.now());
        const gps = fix ? gpsIdx(s, fix) : -1;

        setReached((prev) => {
            // With a fix, trust GPS and hold between stops; else use the clock.
            const raw = fix ? (gps >= 0 ? gps : prev) : sched;

            return Math.max(prev, raw);
        });
    }, []);

    // Compute the real position now (and whenever the journey changes) — off
    // the render pass, so reading the clock stays out of render.
    useEffect(() => {
        const t = setTimeout(applyProgress, 0);

        return () => clearTimeout(t);
    }, [key, applyProgress]);

    // Schedule clock — advances the cursor even without a GPS fix.
    useEffect(() => {
        const id = setInterval(applyProgress, 15_000);

        return () => clearInterval(id);
    }, [applyProgress]);

    // Watch GPS only for a live trip, and only in the foreground (battery).
    useEffect(() => {
        if (!enabled || !('geolocation' in navigator)) {
            return;
        }

        let watchId: number | null = null;

        const startWatch = () => {
            if (watchId !== null) {
                return;
            }

            watchId = navigator.geolocation.watchPosition(
                (p) => {
                    posRef.current = {
                        lat: p.coords.latitude,
                        lng: p.coords.longitude,
                        acc: p.coords.accuracy,
                    };
                    setHasFix(true);
                    applyProgress();
                },
                () => {},
                {
                    enableHighAccuracy: true,
                    maximumAge: 5_000,
                    timeout: 20_000,
                },
            );
        };

        const stopWatch = () => {
            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
        };

        const onVisibility = () => {
            if (document.hidden) {
                stopWatch();
            } else {
                startWatch();
            }
        };

        if (!document.hidden) {
            startWatch();
        }

        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            stopWatch();
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [enabled, applyProgress]);

    return { currentIdx: reached, live: enabled && hasFix };
}
