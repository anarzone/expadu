import { useEffect, useState } from 'react';
import { useLocationSharingEnabled } from './use-geolocation';

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

type Fix = { lat: number; lng: number; accuracy: number; timestamp: number };

type ProgressState = {
    key: string;
    reached: number;
    confirmed?: number;
    checkedAt: number;
    fix: Fix | null;
    live: boolean;
};
type ProgressInput = {
    steps: ProgressStep[];
    enabled: boolean;
    visible: boolean;
    now: number;
    fix?: Fix;
    lost?: boolean;
};

const MAX_FIX_AGE_MS = 60_000;
const MAX_FIX_ACCURACY_M = 100;

function usableFix(fix: Fix | null, now: number): fix is Fix {
    return (
        fix !== null &&
        [fix.lat, fix.lng, fix.accuracy, fix.timestamp].every(
            Number.isFinite,
        ) &&
        Math.abs(fix.lat) <= 90 &&
        Math.abs(fix.lng) <= 180 &&
        fix.accuracy >= 0 &&
        fix.accuracy <= MAX_FIX_ACCURACY_M &&
        fix.timestamp <= now + 5_000 &&
        now - fix.timestamp <= MAX_FIX_AGE_MS
    );
}

export type TripProgress = {
    /** The furthest step reached, or -1 before the trip has begun. */
    currentIdx: number;
    /** True while a real GPS fix is driving the cursor (vs. the schedule clock). */
    live: boolean;
    /** Actual foreground location; never a route or vehicle estimate. */
    position: Fix | null;
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

    return bestI >= 0 && bestD <= MATCH_RADIUS_M + fix.accuracy ? bestI : -1;
}

function stepsKey(steps: ProgressStep[]): string {
    return JSON.stringify(steps.map(({ at, lat, lng }) => [at, lat, lng]));
}

export function advanceTripProgress(
    previous: ProgressState,
    input: ProgressInput,
): ProgressState {
    const key = stepsKey(input.steps);
    const changed = key !== previous.key;
    const confirmed = changed ? -1 : (previous.confirmed ?? -1);
    const candidate = input.fix ?? (changed ? null : previous.fix);
    const fix =
        input.enabled &&
        input.visible &&
        !input.lost &&
        usableFix(candidate, input.now)
            ? candidate
            : null;
    const gps = fix ? gpsIdx(input.steps, fix) : -1;
    const nextConfirmed = gps >= 0 ? Math.max(confirmed, gps) : confirmed;
    const reached = fix
        ? nextConfirmed
        : Math.max(nextConfirmed, scheduleIdx(input.steps, input.now));

    return {
        key,
        reached,
        confirmed: nextConfirmed,
        checkedAt: input.now,
        fix,
        live: fix !== null && gps >= 0,
    };
}

export function tripProgressSnapshot(
    state: ProgressState,
    {
        steps,
        tracking,
        visible,
        now,
    }: {
        steps: ProgressStep[];
        tracking: boolean;
        visible: boolean;
        now: number;
    },
): TripProgress {
    const sameRoute = state.key === stepsKey(steps);
    const position =
        tracking && visible && sameRoute && usableFix(state.fix, now)
            ? state.fix
            : null;

    return {
        currentIdx: sameRoute ? state.reached : -1,
        live: position !== null && state.live,
        position,
    };
}

export function createTripProgressSession({
    steps,
    enabled,
    onUpdate: setState,
}: {
    steps: ProgressStep[];
    enabled: boolean;
    onUpdate: (update: (previous: ProgressState) => ProgressState) => void;
}): () => void {
    let watchId: number | null = null;
    let disposed = false;
    const update = (event: { fix?: Fix; lost?: boolean } = {}) => {
        if (disposed) {
            return;
        }

        setState((previous) =>
            advanceTripProgress(previous, {
                steps,
                enabled,
                visible: !document.hidden,
                now: Date.now(),
                ...event,
            }),
        );
    };
    const stopWatch = () => {
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }
    };
    const startWatch = () => {
        if (
            !enabled ||
            document.hidden ||
            watchId !== null ||
            !navigator.geolocation
        ) {
            return;
        }

        watchId = navigator.geolocation.watchPosition(
            (position) =>
                update({
                    fix: {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                        timestamp: position.timestamp,
                    },
                }),
            () => update({ lost: true }),
            {
                enableHighAccuracy: true,
                maximumAge: 5_000,
                timeout: 20_000,
            },
        );
    };
    const onVisibility = () => {
        update({ lost: true });

        if (document.hidden) {
            stopWatch();
        } else {
            startWatch();
        }
    };
    const initial = setTimeout(() => {
        update({ lost: true });
        startWatch();
    }, 0);
    const timer = setInterval(() => update(), 5_000);
    document.addEventListener('visibilitychange', onVisibility);

    return () => {
        disposed = true;
        clearTimeout(initial);
        clearInterval(timer);
        stopWatch();
        document.removeEventListener('visibilitychange', onVisibility);
    };
}

export function useTripProgress(
    steps: ProgressStep[],
    enabled: boolean,
): TripProgress {
    const sharingEnabled = useLocationSharingEnabled();
    const tracking = enabled && sharingEnabled;
    const [state, setState] = useState<ProgressState>({
        key: '',
        checkedAt: 0,
        reached: -1,
        fix: null,
        live: false,
    });
    useEffect(
        () =>
            createTripProgressSession({
                steps,
                enabled: tracking,
                onUpdate: setState,
            }),
        [tracking, steps],
    );

    return tripProgressSnapshot(state, {
        steps,
        tracking,
        visible: typeof document !== 'undefined' && !document.hidden,
        // The session checks age every five seconds and on visibility changes.
        now: state.checkedAt,
    });
}
