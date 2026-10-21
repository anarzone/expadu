import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { sourceLabel } from '@/components/ui/state-notice';
import type { NextTrip, TripOption } from '@/types/day';
import type { Journey, JourneyLeg } from '@/types/transit';
import { TravelChip } from './labels';
import { lineLook, vehicleOf, vehicleWord } from './line-look';
import type { LineLook } from './line-look';
import { stopName } from './stop-name';
import { TravelIcon } from './travel-icon';
import {
    RideLabel,
    TripAction,
    TripCard,
    TripFact,
    TripNote,
    WayStrip,
} from './trip-card';
import type { TripRingState, TripTone, WayStripNode } from './trip-card';
import '../../../css/travel.css';

/**
 * Your next thing as one card (owner, 15 Oct 2026; Travel directions canvas,
 * option B): when to leave, the ride, when you're there and the time to
 * spare, and the one button. Travel's Nearby and My day and Today's plan for
 * a trip draw it from the same server answer (App\Day\NextTrip), so it reads
 * the same everywhere. Colour says how much it matters: calm while there's
 * time, attention when it's time to go, it's tight or it's late.
 */

const isRide = (leg: JourneyLeg) => leg.mode !== 'walk' && leg.mode !== 'bike';

/** Minutes from now to an instant, by the device clock, ticking each 30 s. */
function useMinutesUntil(at: string | null): number | null {
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

    if (!at) {
        return null;
    }

    const target = Date.parse(at);

    return Number.isFinite(target)
        ? Math.max(0, Math.floor((target - now) / 60_000))
        : null;
}

function rideLook(leg: JourneyLeg): LineLook {
    return lineLook(leg.line, vehicleOf(leg.mode));
}

/**
 * The way as one strip of stations: start, where you board, each change,
 * where you get off, there. A change is one stop at the next ride's time
 * (the mock's sample has one ride; a longer way keeps the strip readable).
 */
export function wayNodes(
    journey: Journey,
    city?: string | null,
): WayStripNode[] {
    const nodes: WayStripNode[] = [
        { kind: 'start', name: 'Start', time: journey.depart_time },
    ];
    let last: JourneyLeg | null = null;
    let walked = false;

    for (const leg of journey.legs) {
        if (!isRide(leg)) {
            walked = true;
            continue;
        }

        const look = rideLook(leg);

        if (last) {
            nodes.push({
                kind: 'stop',
                name: stopName(last.to.name, city),
                time: leg.depart_time,
                look,
            });
        } else {
            if (walked) {
                nodes.push({ kind: 'walk' });
            }

            nodes.push({
                kind: 'stop',
                name: stopName(leg.from.name, city),
                time: leg.depart_time,
                look,
            });
        }

        nodes.push({
            kind: 'ride',
            line: leg.line ?? vehicleWord(vehicleOf(leg.mode)),
            look,
        });
        last = leg;
        walked = false;
    }

    if (last) {
        nodes.push({
            kind: 'stop',
            name: stopName(last.to.name, city),
            time: last.arrive_time,
            look: rideLook(last),
        });
    }

    if (walked || !last) {
        nodes.push({ kind: 'walk' });
    }

    nodes.push({ kind: 'there', name: 'There', time: journey.arrive_time });

    return nodes;
}

function OtherWays({
    trip,
    onCompare,
}: {
    trip: NextTrip;
    onCompare?: () => void;
}) {
    const rows = trip.alternatives.map((option) => (
        <li key={option.leave_at + option.lines.join(',')}>
            <span>
                <strong>{optionTitle(option)}</strong>
                <small>{optionDetail(option, trip)}</small>
            </span>
        </li>
    ));

    return rows.length ? (
        <div className="trip-line-others">
            <h3>Other ways</h3>
            <ul>{rows}</ul>
            {onCompare ? (
                <button
                    type="button"
                    className="text-action"
                    onClick={onCompare}
                >
                    Compare them in Plan a trip
                    <TravelIcon name="arrow" />
                </button>
            ) : null}
        </div>
    ) : null;
}

function optionTitle(option: TripOption): string {
    if (option.ride) {
        const word = vehicleWord(vehicleOf(option.ride.mode));

        return `The ${option.ride.time} ${word.toLowerCase()} ${option.ride.line ?? ''}`.trim();
    }

    return option.mode === 'bike' ? 'By bike' : 'On foot';
}

function optionDetail(option: TripOption, trip: NextTrip): string {
    // The way you added to your plan, as the mock names it.
    const parts = [
        option.in_plan ? 'In your plan' : `Leave ${option.leave_time}`,
        `there ${option.arrive_time}`,
    ];

    if (option.status === 'cancelled') {
        return 'Cancelled';
    }

    parts.push(
        option.spare_min >= 0
            ? `${option.spare_min} min spare`
            : `${-option.spare_min} min late for ${trip.target.start_time}`,
    );

    if (option.ride && option.time_basis === 'scheduled' && !option.in_plan) {
        parts.push(sourceLabel({ source: 'scheduled' }));
    }

    return parts.join(' · ');
}

type NextTripCardProps = {
    trip: NextTrip;
    id?: string;
    /** My day's rail: the appointment comes next, so the card leaves out where it goes and the way. */
    compact?: boolean;
    /** The trip you've started is this one: one tap back to it, not started again. */
    going?: boolean;
    /** "I'm on my way": starts the trip shown. */
    onStart?: () => void;
    /** "Replace the trip you're on?": takes the button's place while it's asked. */
    confirm?: ReactNode;
    starting?: boolean;
    /** Where "Back to your trip" goes. */
    tripHref?: string;
    /** When there's no way yet: open Plan a trip for this place. */
    onPlan?: () => void;
    /** When where you are isn't known. */
    onLocate?: () => void;
    /** Clock and info icons for labels, from the app's icon set. */
    icons?: {
        clock?: ReactNode;
        unsure?: ReactNode;
        timer?: ReactNode;
        offline?: ReactNode;
    };
};

export function NextTripCard({
    trip,
    id = 'next-trip',
    compact = false,
    going = false,
    onStart,
    confirm,
    starting = false,
    tripHref,
    onPlan,
    onLocate,
    icons = {},
}: NextTripCardProps) {
    const [open, setOpen] = useState(false);
    const leaveIn = useMinutesUntil(trip.leave_at);
    const place = compact
        ? undefined
        : [trip.target.title, trip.target.start_time]
              .filter(Boolean)
              .join(' · ');

    if (trip.status === 'unavailable' || !trip.journey) {
        const why: Record<string, string> = {
            destination_unknown: 'Its place isn’t recorded',
            location_unknown: 'Where you are isn’t known',
            routing_unavailable: 'Routes aren’t available right now',
            no_route: 'No route found',
            no_connection_in_time: 'Nothing gets there in time',
            not_upcoming: 'Already started',
            no_time_between: 'No time between plans',
            already_there: 'You’re there',
        };
        const action =
            trip.reason === 'location_unknown' && onLocate ? (
                <TripAction icon="route" onClick={onLocate}>
                    Use my location
                </TripAction>
            ) : onPlan && trip.reason !== 'destination_unknown' ? (
                <TripAction icon="route" onClick={onPlan}>
                    Plan the way
                </TripAction>
            ) : null;

        return (
            <TripCard
                id={id}
                tone="calm"
                ring={{ kind: 'unplanned' }}
                title={trip.target.title}
                place={
                    compact ? undefined : (trip.target.start_time ?? undefined)
                }
                flags={
                    <TravelChip kind="unsure" icon={icons.unsure}>
                        {why[trip.reason] ?? 'No way yet'}
                    </TravelChip>
                }
                action={action}
            />
        );
    }

    const journey = trip.journey;
    const ride = journey.legs.find(isRide) ?? null;
    const minutes = leaveIn ?? trip.leave_in_min ?? 0;
    const late = trip.status === 'late';
    const tight = trip.status === 'tight';
    // The way it would have taken is gone (cancelled, or missed): the card
    // leads with the one to take instead.
    const gone = trip.gone?.ride ? trip.gone : null;
    // A lift out at one of the way's stations, as KVB files it: which
    // platform it serves isn't matched to the way. For someone who needs
    // step-free ways (their own saved need, owner, 21 Oct 2026) that's no
    // step-free way; for everyone else the chip says it and the card keeps
    // its title and facts.
    const liftStops = [
        ...new Set((trip.lifts ?? []).map((l) => stopName(l.stop, trip.city))),
    ];
    const many = liftStops.length > 1;
    const blocked =
        !gone && !!ride && !!trip.step_free_need && !!liftStops.length;
    const lift = !liftStops.length
        ? null
        : blocked
          ? `${many ? 'Lifts' : 'A lift'} at ${liftStops.join(' and ')} ${many ? 'are' : 'is'} out of order, and KVB doesn’t say which platform ${many ? 'they serve' : 'it serves'}: this way may not be step-free.`
          : `${many ? 'Lifts' : 'A lift'} at ${liftStops.join(' and ')} ${many ? 'are' : 'is'} out of order; KVB doesn’t say if ${many ? 'they’re' : 'it’s'} on your way.`;
    const tone: TripTone =
        gone?.kind === 'cancelled' || blocked
            ? 'conflict'
            : gone || minutes <= 0 || late || tight
              ? 'attention'
              : 'calm';
    const ring: TripRingState =
        gone || blocked
            ? { kind: 'alert' }
            : { kind: 'trip', leaveIn: minutes };
    const title =
        blocked && ride
            ? `No step-free way to ${vehicleWord(vehicleOf(ride.mode)).toLowerCase()}\u00a0${ride.line ?? ''}`.trim()
            : gone && ride
              ? `Take the ${ride.depart_time}`
              : minutes <= 0
                ? 'Leave now'
                : `Leave at ${trip.leave_time}`;
    const spare = trip.spare_min ?? 0;

    const facts = blocked
        ? [
              <TripFact
                  key="ride"
                  label={
                      <RideLabel
                          line={ride!.line ?? ''}
                          look={rideLook(ride!)}
                          word={vehicleWord(vehicleOf(ride!.mode))}
                      />
                  }
                  value={ride!.depart_time}
                  sub={stopName(ride!.from.name, trip.city)}
              />,
              <TripFact
                  key="stepfree"
                  label="Step-free"
                  value="No"
                  sub="Lift out of order"
                  tone="conflict"
              />,
          ]
        : [
              ride ? (
                  <TripFact
                      key="ride"
                      label={
                          <RideLabel
                              line={ride.line ?? ''}
                              look={rideLook(ride)}
                              word={vehicleWord(vehicleOf(ride.mode))}
                          />
                      }
                      value={ride.depart_time}
                      sub={stopName(ride.from.name, trip.city)}
                  />
              ) : (
                  <TripFact
                      key="walk"
                      label={journey.mode === 'bike' ? 'Bike' : 'Walk'}
                      value={trip.leave_time}
                      sub={trip.from?.label ?? ''}
                  />
              ),
              <TripFact
                  key="there"
                  label="There"
                  value={trip.arrive_time}
                  sub={
                      trip.target.location ??
                      (ride ? stopName(ride.to.name, trip.city) : '')
                  }
              />,
              late ? (
                  <TripFact
                      key="late"
                      label="Late"
                      value={
                          <>
                              {trip.late_by_min}
                              <span>&nbsp;min</span>
                          </>
                      }
                      sub={`after ${trip.target.start_time}`}
                      tone="attention"
                  />
              ) : (
                  <TripFact
                      key="spare"
                      label="Spare"
                      value={
                          <>
                              {spare}
                              <span>&nbsp;min</span>
                          </>
                      }
                      sub={`before ${trip.target.start_time}`}
                      tone={spare < trip.margin_min ? 'attention' : undefined}
                  />
              ),
          ];

    const skipped =
        trip.skipped?.ride && !gone && !blocked
            ? `Your ${trip.skipped.ride.time} has only ${trip.skipped.spare_min}\u00a0min spare${compact ? '' : ' · listed under Other ways'}`
            : null;
    // Why, with the way: how it's known.
    const goneNote =
        gone?.kind === 'cancelled'
            ? 'The live times mark it cancelled; on the stop display it says „Fällt aus“.'
            : gone?.kind === 'missed'
              ? `We noticed because your location didn’t change after ${gone.leave_time}. Indoors this can be wrong.`
              : null;

    // Timetable times only: said first, over the facts, so the ride tile
    // keeps its boarding stop (departures-next, 20 Oct 2026).
    const flags = [
        ride && trip.time_basis === 'scheduled' ? (
            <TravelChip
                key="basis"
                kind="unsure"
                icon={icons.offline ?? icons.unsure}
            >
                {sourceLabel({ source: 'scheduled' })}
            </TravelChip>
        ) : null,
        late ? (
            <TravelChip key="late" kind="attention" icon={icons.clock}>
                Nothing gets there by {trip.target.start_time}
            </TravelChip>
        ) : null,
        gone?.kind === 'cancelled' ? (
            <TravelChip
                key="gone"
                kind="conflict"
                icon={<TravelIcon name="alert" />}
            >
                {`${gone.ride!.time} cancelled`}
            </TravelChip>
        ) : gone?.kind === 'missed' ? (
            <TravelChip key="gone" kind="attention" icon={icons.clock}>
                {`Missed the ${gone.ride!.time}`}
            </TravelChip>
        ) : null,
        lift ? (
            <TravelChip
                key="lift"
                kind="conflict"
                icon={<TravelIcon name="lift" />}
            >
                Lift out of order
            </TravelChip>
        ) : null,
    ].filter(Boolean);

    const action = confirm ? (
        confirm
    ) : going ? (
        <TripAction icon="route" href={tripHref}>
            Back to your trip
        </TripAction>
    ) : blocked && onPlan ? (
        <TripAction icon="route" onClick={onPlan}>
            Plan another way
        </TripAction>
    ) : onStart ? (
        <TripAction onClick={onStart} disabled={starting}>
            I’m on my way
        </TripAction>
    ) : null;

    return (
        <TripCard
            id={id}
            tone={tone}
            ring={ring}
            title={title}
            place={place}
            flags={flags.length ? flags : undefined}
            facts={facts}
            note={skipped ? <TripNote>{skipped}</TripNote> : undefined}
            action={action}
            more={
                compact ? undefined : (
                    <>
                        <WayStrip nodes={wayNodes(journey, trip.city)} />
                        {[goneNote, lift].map((line) =>
                            line ? (
                                <p key={line} className="trip-line-note">
                                    {icons.unsure}
                                    <span>{line}</span>
                                </p>
                            ) : null,
                        )}
                        <OtherWays trip={trip} onCompare={onPlan} />
                    </>
                )
            }
            moreOpen={open}
            onToggleMore={
                compact ? undefined : () => setOpen((value) => !value)
            }
            moreLabel={`Show the way to ${trip.target.title} and other ways`}
        />
    );
}
