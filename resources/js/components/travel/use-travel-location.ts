import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useLocationSharingEnabled } from '@/hooks/use-geolocation';

/**
 * Where Travel starts from: the device's position, confirmed to the server
 * ("I'm here", kept two hours) so Nearby's closest station and the next trip
 * are worked out from it. Never prompts by itself: with permission already
 * granted it locates once on open (a precise fix only), then follows you,
 * re-rooting when you've moved 150 m; otherwise it waits for "Use my
 * location". The position goes to the server only, never into the URL.
 */

const FOLLOW_METRES = 150;
const FOLLOW_FLOOR_MS = 20_000;
const AUTO_THROTTLE_MS = 5 * 60_000;

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

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
    | 'unsaved'
    | 'timeout';

export function useTravelLocation({ onAnchored }: { onAnchored: () => void }) {
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
            try {
                const response = await fetch('/api/location/confirm', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ lat, lng }),
                });

                if (!response.ok) {
                    return false;
                }
            } catch {
                return false;
            }

            anchor.current = { lat, lng };
            lastAt.current = Date.now();
            anchored.current();

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
        navigator.geolocation.getCurrentPosition(
            async (position) => {
                if (!usable(position)) {
                    setLocating(false);
                    setError('imprecise');

                    return;
                }

                const ok = await confirm(
                    position.coords.latitude,
                    position.coords.longitude,
                );
                setLocating(false);
                setError(ok ? null : 'unsaved');
                setFollowing(ok);
            },
            (failure) => {
                setLocating(false);
                setError(
                    failure.code === failure.PERMISSION_DENIED
                        ? 'blocked'
                        : failure.code === failure.POSITION_UNAVAILABLE
                          ? 'unavailable'
                          : 'timeout',
                );
            },
            { enableHighAccuracy: false, timeout: 15000, maximumAge: 60000 },
        );
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
                    'X-CSRF-TOKEN': csrfToken(),
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
    unavailable: 'Your device couldn’t find a location. Pick a stop instead.',
    imprecise:
        'That location isn’t precise enough to pick a stop. Pick one instead.',
    unsaved: 'Your location couldn’t be saved. Try again, or pick a stop.',
    timeout: 'Locating took too long. Try again, or pick a stop.',
};
