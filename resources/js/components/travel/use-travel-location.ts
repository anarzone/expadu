import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useLocationSharingEnabled } from '@/hooks/use-geolocation';
import { csrfHeaders } from '@/lib/csrf';

/**
 * Where Travel starts from: the device's position, confirmed to the server
 * ("I'm here", kept two hours) so Nearby's closest station and the next trip
 * are worked out from it. Never prompts by itself: with permission already
 * granted it locates once on open (a precise fix only), then follows you,
 * re-rooting when you've moved 150 m; otherwise it waits for "Use my
 * location". The position goes to the server only, never into the URL.
 */

const FOLLOW_METRES = 150;
/**
 * Asked for, a fix this rough still answers: underground the phone has no GPS,
 * only masts and maybe Wi-Fi. It's said to be rough, and a precise one replaces it.
 */
const ROUGH_METRES = 1000;
const FOLLOW_FLOOR_MS = 20_000;
const AUTO_THROTTLE_MS = 5 * 60_000;

function usable(position: GeolocationPosition, maxAccuracy = 200): boolean {
    const { latitude, longitude, accuracy } = position.coords;
    const age = Date.now() - position.timestamp;

    return (
        Number.isFinite(latitude) &&
        Math.abs(latitude) <= 90 &&
        Number.isFinite(longitude) &&
        Math.abs(longitude) <= 180 &&
        Number.isFinite(accuracy) &&
        accuracy >= 0 &&
        accuracy <= maxAccuracy &&
        Number.isFinite(age) &&
        age >= -5000 &&
        age <= 120_000
    );
}

function metres(
    a: { lat: number; lng: number },
    b: { lat: number; lng: number },
) {
    const r = 6371000;
    const dLat = ((b.lat - a.lat) * Math.PI) / 180;
    const dLng = ((b.lng - a.lng) * Math.PI) / 180;
    const h =
        Math.sin(dLat / 2) ** 2 +
        Math.cos((a.lat * Math.PI) / 180) *
            Math.cos((b.lat * Math.PI) / 180) *
            Math.sin(dLng / 2) ** 2;

    return r * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
}

export type LocateError =
    | 'off'
    | 'unsupported'
    | 'blocked'
    | 'unavailable'
    | 'imprecise'
    | 'rough'
    | 'unsaved'
    | 'timeout';

export function useTravelLocation({
    onAnchored,
}: {
    /** `moved`: more than 100 m from where the page was worked out from. */
    onAnchored: (moved: boolean) => void;
}) {
    const enabled = useLocationSharingEnabled();
    const [locating, setLocating] = useState(false);
    const [error, setError] = useState<LocateError | null>(null);
    const [following, setFollowing] = useState(false);
    const anchor = useRef<{ lat: number; lng: number } | null>(null);
    const lastAt = useRef(0);
    const anchored = useRef(onAnchored);

    useEffect(() => {
        anchored.current = onAnchored;
    }, [onAnchored]);

    const confirm = useCallback(
        async (lat: number, lng: number): Promise<boolean> => {
            let moved = true;

            try {
                const response = await fetch('/api/location/confirm', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        ...csrfHeaders(),
                    },
                    body: JSON.stringify({ lat, lng }),
                });

                if (!response.ok) {
                    return false;
                }

                moved =
                    ((await response.json()) as { moved?: boolean }).moved !==
                    false;
            } catch {
                return false;
            }

            anchor.current = { lat, lng };
            lastAt.current = Date.now();
            anchored.current(moved);

            return true;
        },
        [],
    );

    /** Asks the browser, anchors, and starts following. */
    const fix = useCallback(() => {
        if (!navigator.geolocation) {
            setError('unsupported');

            return;
        }

        setLocating(true);
        setError(null);
        const ask = (options: PositionOptions) =>
            new Promise<GeolocationPosition>((resolve, reject) =>
                navigator.geolocation.getCurrentPosition(
                    resolve,
                    reject,
                    options,
                ),
            );
        const settle = async (
            position: GeolocationPosition,
            rough: boolean,
        ) => {
            const ok = await confirm(
                position.coords.latitude,
                position.coords.longitude,
            );
            setLocating(false);
            setError(ok ? (rough ? 'rough' : null) : 'unsaved');
            setFollowing(ok);
        };

        void (async () => {
            let first: GeolocationPosition | null = null;

            try {
                first = await ask({
                    enableHighAccuracy: false,
                    timeout: 15000,
                    maximumAge: 60000,
                });

                if (usable(first)) {
                    await settle(first, false);

                    return;
                }
            } catch (failure) {
                const code = (failure as GeolocationPositionError).code;

                // Waited long enough, or it isn't allowed: another try won't help.
                if (code !== 2) {
                    setLocating(false);
                    setError(code === 1 ? 'blocked' : 'timeout');

                    return;
                }
            }

            // Only a rough fix or none: once more, asking for the GPS.
            const second = await ask({
                enableHighAccuracy: true,
                timeout: 8000,
                maximumAge: 0,
            }).catch(() => null);

            if (second && usable(second)) {
                await settle(second, false);

                return;
            }

            const rough = [second, first]
                .filter(
                    (position): position is GeolocationPosition =>
                        position !== null && usable(position, ROUGH_METRES),
                )
                .sort((a, b) => a.coords.accuracy - b.coords.accuracy)[0];

            if (rough) {
                await settle(rough, true);

                return;
            }

            setLocating(false);
            setError(first || second ? 'imprecise' : 'unavailable');
        })();
    }, [confirm]);

    /** "Use my location": with location sharing on. */
    const locate = useCallback(() => {
        if (!enabled) {
            setError('off');

            return;
        }

        fix();
    }, [enabled, fix]);

    /**
     * Location sharing was off: asked here, where your location is wanted,
     * rather than in Settings. On, then located.
     */
    const allow = useCallback(async () => {
        setLocating(true);
        setError(null);

        try {
            const response = await fetch('/user-settings', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...csrfHeaders(),
                },
                body: JSON.stringify({ settings: { share_location: true } }),
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }
        } catch {
            setLocating(false);
            setError('unsaved');

            return;
        }

        router.reload({ only: ['userSettings'] });
        fix();
    }, [fix]);

    // On open, with permission already granted: one precise fix, at most
    // every five minutes across visits, silent on failure.
    useEffect(() => {
        if (
            !enabled ||
            !navigator.permissions?.query ||
            !navigator.geolocation
        ) {
            return;
        }

        let cancelled = false;
        navigator.permissions
            .query({ name: 'geolocation' as PermissionName })
            .then((status) => {
                const last = Number(
                    sessionStorage.getItem('travelAutoLocatedAt') ?? 0,
                );

                if (
                    cancelled ||
                    status.state !== 'granted' ||
                    Date.now() - last < AUTO_THROTTLE_MS
                ) {
                    return;
                }

                sessionStorage.setItem(
                    'travelAutoLocatedAt',
                    String(Date.now()),
                );
                navigator.geolocation.getCurrentPosition(
                    async (position) => {
                        if (cancelled || !usable(position, 150)) {
                            return;
                        }

                        if (
                            await confirm(
                                position.coords.latitude,
                                position.coords.longitude,
                            )
                        ) {
                            setFollowing(true);
                        }
                    },
                    () => {
                        // Silent: an automatic attempt never nags.
                    },
                    {
                        enableHighAccuracy: true,
                        timeout: 12000,
                        maximumAge: 60000,
                    },
                );
            })
            .catch(() => {
                // No Permissions API (older Safari): stay passive.
            });

        return () => {
            cancelled = true;
        };
    }, [enabled, confirm]);

    // Following: re-root once you've moved clear of where Nearby is rooted.
    useEffect(() => {
        if (!enabled || !following || !navigator.geolocation) {
            return;
        }

        let watch: number | null = null;
        const start = () => {
            if (watch !== null || document.hidden) {
                return;
            }

            watch = navigator.geolocation.watchPosition(
                (position) => {
                    if (document.hidden || !usable(position)) {
                        return;
                    }

                    // Back above ground: where it says you are isn't rough any more.
                    setError((error) => (error === 'rough' ? null : error));

                    const here = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude,
                    };

                    // A move, not the fix wobbling: past 150 m and past the
                    // fix's own accuracy (Wi-Fi positions jump about by that).
                    if (
                        anchor.current &&
                        metres(anchor.current, here) <
                            Math.max(FOLLOW_METRES, position.coords.accuracy)
                    ) {
                        return;
                    }

                    if (Date.now() - lastAt.current < FOLLOW_FLOOR_MS) {
                        return;
                    }

                    void confirm(here.lat, here.lng);
                },
                () => {
                    // Lost the fix: keep the last anchor.
                },
                {
                    enableHighAccuracy: false,
                    maximumAge: 30_000,
                    timeout: 20_000,
                },
            );
        };
        const stop = () => {
            if (watch !== null) {
                navigator.geolocation.clearWatch(watch);
                watch = null;
            }
        };
        const onVisibility = () => (document.hidden ? stop() : start());

        start();
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            stop();
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [enabled, following, confirm]);

    return {
        enabled,
        locating,
        error,
        following,
        locate,
        allow,
        stopFollowing: () => setFollowing(false),
    };
}

export const locateErrorText: Record<LocateError, string> = {
    off: 'Location sharing is off. Share your location to see the station closest to you, or pick a stop.',
    unsupported:
        'Location isn’t available on this device. Pick a stop instead.',
    blocked:
        'Location is blocked for this site. Allow it from the address bar, or pick a stop.',
    unavailable:
        'Your phone has no location signal here, which is normal underground. Pick a stop, or try again above ground.',
    imprecise:
        'Your phone can only place you very roughly here, which is normal underground. Pick a stop, or try again above ground.',
    rough: 'Your phone can only place you roughly here, so check this is your station, or pick a stop.',
    unsaved: 'Your location couldn’t be saved. Try again, or pick a stop.',
    timeout: 'Locating took too long. Try again, or pick a stop.',
};
