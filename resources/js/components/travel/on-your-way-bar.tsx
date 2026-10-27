import { Link } from '@inertiajs/react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import type { ActiveTrip } from '@/hooks/use-active-trip';
import { useActiveTrip } from '@/hooks/use-active-trip';
import { LineBadge } from './line-badge';
import { vehicleOf, vehicleWord } from './line-look';
import { stopName } from './stop-name';
import '../../../css/travel.css';

type Leg = ActiveTrip['journey']['legs'][number];

/**
 * Where the trip is by the clock: the part you're on (or about to start) and
 * its line. Times are the legs' own, live-adjusted when the feed is live.
 */
export function tripMoment(
    trip: ActiveTrip,
    now: number,
): { instruction: string; leg: Leg | null } {
    const legs = trip.journey.legs;
    const arrive = Date.parse(trip.journey.arrive_at);

    if (Number.isFinite(arrive) && now >= arrive) {
        return { instruction: 'Arrived', leg: null };
    }

    const current =
        legs.find((leg) => now < Date.parse(leg.arrive_at)) ??
        legs.at(-1) ??
        null;

    if (!current) {
        return {
            instruction: `Arriving ${trip.journey.arrive_time}`,
            leg: null,
        };
    }

    const transit = current.mode !== 'walk' && current.mode !== 'bike';
    const started = now >= Date.parse(current.depart_at);
    const line = current.line
        ? `${vehicleWord(vehicleOf(current.mode))} ${current.line}`
        : vehicleWord(vehicleOf(current.mode));

    if (!transit) {
        return {
            instruction: `${current.mode === 'bike' ? 'Ride' : 'Walk'} to ${stopName(current.to.name, trip.city)}`,
            leg:
                legs.find(
                    (leg) =>
                        leg.mode !== 'walk' &&
                        leg.mode !== 'bike' &&
                        Date.parse(leg.arrive_at) > now,
                ) ?? null,
        };
    }

    return {
        instruction: started
            ? `On ${line} to ${stopName(current.to.name, trip.city)}`
            : `${line} at ${current.depart_time} from ${stopName(current.from.name, trip.city)}`,
        leg: current,
    };
}

/**
 * Travel's trip page for the trip you're on. The trip is your account's
 * own (activeTrip), so the address carries nothing about it: no
 * destination and no starting point in links or history.
 */
export function activeTripHref(trip: ActiveTrip | null): string {
    return trip ? '/travel?view=route' : '/travel';
}

/*
 * A page's own trip card already leads back to the trip you've started
 * (Travel's next trip, Today's Leave now): the bar steps aside meanwhile.
 */
let cardsLeadingBack = 0;
const listeners = new Set<() => void>();
const notify = () => listeners.forEach((listener) => listener());

/** Call from a card that shows "Back to your trip" while it's mounted. */
export function useTripLeadsBack(active: boolean): void {
    useEffect(() => {
        if (!active) {
            return;
        }

        cardsLeadingBack += 1;
        notify();

        return () => {
            cardsLeadingBack -= 1;
            notify();
        };
    }, [active]);
}

function useCardLeadsBack(): boolean {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => cardsLeadingBack > 0,
        () => false,
    );
}

/** A clock that ticks each minute, for the trip's moment. */
function useMinuteClock(): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const tick = () => setNow(Date.now());
        const timer = window.setInterval(tick, 30_000);
        document.addEventListener('visibilitychange', tick);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', tick);
        };
    }, []);

    return now;
}

/**
 * On your way (connected/journey-session.js): on every page while you're on a
 * trip, Travel's card surface, the line's badge and Back to your trip ("Your
 * trip" on narrow phones). The shell mounts it once.
 */
export function OnYourWayBar() {
    const { activeTrip } = useActiveTrip();
    const covered = useCardLeadsBack();
    const now = useMinuteClock();

    if (!activeTrip || covered) {
        return null;
    }

    const { instruction, leg } = tripMoment(activeTrip, now);
    const kind = vehicleOf(leg?.mode);

    return (
        <Link
            href={activeTripHref(activeTrip)}
            className="journey-resume"
            aria-label={`Back to your trip to ${activeTrip.destination.name}: ${instruction}`}
        >
            {leg?.line ? (
                <LineBadge line={leg.line} kind={kind} />
            ) : (
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="6" cy="5" r="2" />
                    <circle cx="18" cy="19" r="2" />
                    <path d="M6 7v6a3 3 0 0 0 3 3h6a3 3 0 0 0 0-6H9" />
                </svg>
            )}
            <span>
                <strong>{activeTrip.destination.name}</strong>
                <small>On your way · {instruction}</small>
            </span>
            <span className="journey-resume-go">
                <span>Back to your trip</span>
                <span aria-hidden="true">Your trip</span>
            </span>
        </Link>
    );
}
