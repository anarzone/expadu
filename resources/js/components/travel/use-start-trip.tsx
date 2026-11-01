import { useCallback, useState } from 'react';
import type { TripPlace, TripPlan } from '@/hooks/use-active-trip';
import { useActiveTrip } from '@/hooks/use-active-trip';
import type { NextTrip } from '@/types/day';
import type { Journey } from '@/types/transit';
import '../../../css/travel.css';

type Pending = {
    journey: Journey;
    destination: TripPlace;
    origin: TripPlace | null;
    plan: TripPlan | null;
};

/**
 * "I'm on my way" with one tap (owner, 15 Oct 2026): starts the trip shown.
 * Only when another trip is still running does it ask "Replace the trip
 * you're on?" (Keep / Replace), as the trip page does; the server refuses a
 * silent replace (409), so a stale tab can't swap the trip by surprise.
 */
export function useStartTrip() {
    const { activeTrip, start, end, busy, error, conflict, clearConflict } =
        useActiveTrip();
    const [pending, setPending] = useState<Pending | null>(null);

    const begin = useCallback(
        async (
            journey: Journey,
            destination: TripPlace,
            origin: TripPlace | null,
            plan: TripPlan | null = null,
        ) => {
            const ok = await start(journey, destination, origin, {
                replace: false,
                plan,
            });

            if (!ok) {
                setPending({ journey, destination, origin, plan });
            }

            return ok;
        },
        [start],
    );

    const replace = useCallback(async () => {
        if (!pending) {
            return false;
        }

        const ok = await start(
            pending.journey,
            pending.destination,
            pending.origin,
            { replace: true, plan: pending.plan },
        );

        if (ok) {
            setPending(null);
            clearConflict();
        }

        return ok;
    }, [pending, start, clearConflict]);

    const keep = useCallback(() => {
        setPending(null);
        clearConflict();
    }, [clearConflict]);

    const confirm =
        conflict && pending ? (
            <div
                className="nav-confirm"
                role="group"
                aria-label="Replace the trip you’re on?"
            >
                <span>
                    Replace the trip you’re on?
                    {conflict.destination ? (
                        <small>
                            You’re on your way to {conflict.destination}
                            {conflict.arrive_time
                                ? `, there ${conflict.arrive_time}`
                                : ''}
                            .
                        </small>
                    ) : null}
                </span>
                <button type="button" onClick={keep} disabled={busy}>
                    Keep
                </button>
                <button
                    type="button"
                    className="nav-primary"
                    onClick={() => void replace()}
                    disabled={busy}
                >
                    Replace
                </button>
            </div>
        ) : null;

    return {
        activeTrip,
        begin,
        end,
        busy,
        // A conflict isn't an error: it's a question.
        error: conflict ? null : error,
        confirm,
    };
}

/** The trip a NextTrip answer leads to: its journey, where it ends, where it starts. */
export function tripOf(trip: NextTrip): Pending | null {
    const journey = trip.journey;
    const last = journey?.legs.at(-1);

    if (!journey || !last) {
        return null;
    }

    return {
        journey,
        destination: {
            name: trip.target.title,
            lat: last.to.lat,
            lng: last.to.lng,
        },
        origin: trip.from
            ? { name: trip.from.label, lat: trip.from.lat, lng: trip.from.lng }
            : null,
        // Its start is Cologne time ("2026-09-30T14:00:00+02:00"): the day is its first ten.
        plan: trip.target.start_at
            ? {
                  date: trip.target.start_at.slice(0, 10),
                  target_id: trip.target.id,
              }
            : null,
    };
}

/** True when the trip you're on is this one (same first departure and arrival). */
export function isGoing(
    trip: NextTrip,
    active: { journey: Journey } | null,
): boolean {
    return (
        !!active &&
        !!trip.journey &&
        active.journey.depart_at === trip.journey.depart_at &&
        active.journey.arrive_at === trip.journey.arrive_at
    );
}
