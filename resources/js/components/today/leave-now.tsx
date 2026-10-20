import { router } from '@inertiajs/react';
import { routeTo } from '@/components/today/route-to';
import { NextTripCard } from '@/components/travel/next-trip-card';
import {
    activeTripHref,
    useTripLeadsBack,
} from '@/components/travel/on-your-way-bar';
import { TravelIcon } from '@/components/travel/travel-icon';
import {
    isGoing,
    tripOf,
    useStartTrip,
} from '@/components/travel/use-start-trip';
import { useTravelLocation } from '@/components/travel/use-travel-location';
import type { DayProjection, NextTrip } from '@/types/day';

/**
 * The next plan's Leave now (the mock's .leave-card): Travel's own
 * NextTripCard from the same server answer Travel shows, so it reads the
 * same on both pages. "I'm on my way" starts the trip in one tap; only a
 * trip already running asks Keep / Replace.
 */
export function LeaveNow({
    day,
    trip,
}: {
    day: DayProjection;
    trip: NextTrip;
}) {
    const starter = useStartTrip();
    const going = isGoing(trip, starter.activeTrip);
    const location = useTravelLocation({
        onAnchored: () => router.reload({ only: ['day'] }),
    });
    const target = day.items.find((item) => item.id === trip.target.id);
    const plan = target ? routeTo(target) : null;

    useTripLeadsBack(going);

    const start = () => {
        const next = tripOf(trip);

        if (next) {
            void starter.begin(next.journey, next.destination, next.origin);
        }
    };

    return (
        <>
            <NextTripCard
                trip={trip}
                id="today-trip"
                going={going}
                onStart={start}
                confirm={starter.confirm}
                starting={starter.busy}
                tripHref={
                    starter.activeTrip
                        ? activeTripHref(starter.activeTrip)
                        : undefined
                }
                onPlan={plan ? () => router.visit(plan) : undefined}
                onLocate={location.locate}
                icons={{
                    clock: <TravelIcon name="clock" />,
                    unsure: <TravelIcon name="info" />,
                    timer: <TravelIcon name="timer" />,
                }}
            />
            {starter.error && (
                <p className="leave-note" role="status">
                    {starter.error}
                </p>
            )}
        </>
    );
}
