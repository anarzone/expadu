import { router, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
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

    const post = useCallback(
        async (url: string, body?: Record<string, unknown>) => {
            setBusy(true);

            try {
                await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF(),
                    },
                    body: body ? JSON.stringify(body) : undefined,
                });
                // Refresh only the shared trip prop (reload preserves scroll +
                // component state), so the banner appears/clears immediately.
                await new Promise<void>((resolve) =>
                    router.reload({
                        only: ['activeTrip'],
                        onFinish: () => resolve(),
                    }),
                );
            } finally {
                setBusy(false);
            }
        },
        [],
    );

    const start = useCallback(
        (journey: Journey, destination: TripPlace, origin: TripPlace | null) =>
            post('/api/trip/start', { journey, destination, origin }),
        [post],
    );

    const end = useCallback(() => post('/api/trip/end'), [post]);

    return { activeTrip: activeTrip ?? null, start, end, busy };
}
