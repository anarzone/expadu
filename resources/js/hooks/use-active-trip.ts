import { router, usePage } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';
import {
    persistTrip,
    TripConflictError,
    TripRequestError,
} from '@/components/departures/trip-request';
import type { TripConflict } from '@/components/departures/trip-request';
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
/** The plan a trip is for, as the server checked it against your day. */
export type TripTarget = {
    id: string;
    title: string;
    /** Where it is, as the plan says ("Mülheim"). */
    label: string | null;
    kind: 'appointment' | 'plan' | 'event';
    official: boolean;
    start_at: string;
};

/** Which plan on your day a trip is for (`date` is its Cologne day). */
export type TripPlan = { date: string; target_id: string };

export type ActiveTrip = {
    journey: Journey;
    origin: TripPlace | null;
    destination: TripPlace;
    target: TripTarget | null;
    started_at: string | null;
    /** The city stop names carry ("Köln Neumarkt"), to show them as the signs do. */
    city?: string | null;
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
    // A trip is running and this one would replace it: a question, not an error.
    const [conflict, setConflict] = useState<TripConflict | null>(null);

    const post = useCallback(
        async (url: string, body?: Record<string, unknown>) => {
            if (busyRef.current) {
                return false;
            }

            busyRef.current = true;
            setBusy(true);
            setError(null);
            setConflict(null);

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
                if (err instanceof TripConflictError) {
                    setConflict(err.trip);

                    return false;
                }

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
            {
                replace = true,
                plan = null,
            }: { replace?: boolean; plan?: TripPlan | null } = {},
        ) =>
            post('/api/trip/start', {
                journey,
                destination,
                origin,
                replace,
                ...(plan ? { plan } : {}),
            }),
        [post],
    );

    const end = useCallback(() => post('/api/trip/end'), [post]);

    const clearConflict = useCallback(() => setConflict(null), []);

    return {
        activeTrip: activeTrip ?? null,
        start,
        end,
        busy,
        error,
        conflict,
        clearConflict,
    };
}
