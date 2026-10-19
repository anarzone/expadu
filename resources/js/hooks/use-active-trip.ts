import { router, usePage } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';
import {
    persistTrip,
    TripRequestError,
} from '@/components/departures/trip-request';
import type { Journey } from '@/components/journey/take-me-there-sheet';

/** A place the trip runs from/to — the coordinates power live map-matching. */
export type TripPlace = {
    name: string;
    lat: number;
    lng: number;
    emoji?: string | null;
};

/**
 * The user's live trip, mirrored from the server (App\Models\ActiveTrip). Shared
 * on every page so the banner + Departures can reopen it. Null when no trip.
 */
export type ActiveTrip = {
    journey: Journey;
    origin: TripPlace | null;
    destination: TripPlace;
    started_at: string | null;
};

const CSRF = () =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') || '';

/**
 * Read + control the persisted trip session. Starting/ending POSTs to the API
 * and reloads the shared `activeTrip` prop so the banner (and any open planner)
 * update everywhere at once — the server row is the single source of truth.
 */
export function useActiveTrip() {
    const { activeTrip } = usePage<{ activeTrip: ActiveTrip | null }>().props;
    const [busy, setBusy] = useState(false);
    const busyRef = useRef(false);
    const [error, setError] = useState<string | null>(null);

    const post = useCallback(
        async (url: string, body?: Record<string, unknown>) => {
            if (busyRef.current) {
                return false;
            }

            busyRef.current = true;
            setBusy(true);
            setError(null);

            try {
                await persistTrip(
                    url,
                    body,
                    CSRF(),
                    () =>
                        new Promise<boolean>((resolve) => {
                            router.reload({
                                only: ['activeTrip'],
                                onSuccess: () => resolve(true),
                                onError: () => resolve(false),
                                onCancel: () => resolve(false),
                                onFinish: () => resolve(false),
                            });
                        }),
                );

                return true;
            } catch (err) {
                setError(
                    err instanceof TripRequestError
                        ? err.message
                        : 'The connection was lost. Your trip status is unconfirmed. Try again.',
                );

                return false;
            } finally {
                busyRef.current = false;
                setBusy(false);
            }
        },
        [],
    );

    // `replace`: the server keeps a running trip unless told to replace it
    // (409 trip_in_progress). Travel's trip page asks "Replace the trip
    // you're on?" first; the older planners replace as they always have.
    const start = useCallback(
        (
            journey: Journey,
            destination: TripPlace,
            origin: TripPlace | null,
            { replace = true }: { replace?: boolean } = {},
        ) => post('/api/trip/start', { journey, destination, origin, replace }),
        [post],
    );

    const end = useCallback(() => post('/api/trip/end'), [post]);

    return { activeTrip: activeTrip ?? null, start, end, busy, error };
}
