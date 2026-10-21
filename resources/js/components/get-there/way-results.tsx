/* The ways to somewhere, as Travel shows them: what Travel checked, the
   options with their Journey details, and honest states while there's no
   start, while it's looked for or loading, when it fails, and when walking
   beats every tram and bus. Events' Get there and the place sheet's show
   the same. */

import type { ReactNode } from 'react';
import { TravelChip } from '@/components/travel/labels';
import { checksFor } from '@/components/travel/plan/plan-model';
import type {
    PlanOption,
    PlanTarget,
} from '@/components/travel/plan/plan-model';
import { PlanChecks, PlanOptions } from '@/components/travel/plan/plan-results';
import { TravelIcon } from '@/components/travel/travel-icon';
import { cn } from '@/lib/utils';
import type { DayProjection } from '@/types/day';
import type { WayMode, WayPlace, WayStatus } from './ways';
import './way-results.css';

function Empty({
    chip,
    title,
    text,
    children,
    loading = false,
}: {
    chip: ReactNode;
    title: string;
    text: string;
    children?: ReactNode;
    loading?: boolean;
}) {
    return (
        <div
            className={cn('gt-empty', loading && 'is-loading')}
            role={loading ? 'status' : undefined}
        >
            {chip}
            <p>
                <strong>{title}</strong>
                <span>{text}</span>
            </p>
            {children}
        </div>
    );
}

export function WayResults({
    status,
    place,
    text,
    finding,
    back = false,
    mode,
    shown,
    chosen,
    target,
    day,
    destination,
    onPick,
    expanded,
    onToggle,
    onRetry,
    onWalk,
    starts = [],
    onStart,
    onLocate,
    unavailable = false,
}: {
    status: WayStatus;
    /** Where the way starts (or, on the way back, ends): null until found. */
    place: WayPlace | null;
    /** What's typed in From. */
    text: string;
    finding: boolean;
    back?: boolean;
    mode: WayMode;
    shown: PlanOption[];
    chosen: PlanOption | null;
    target: PlanTarget | null;
    day: DayProjection | null | undefined;
    /** The name of where the way goes. */
    destination: string;
    onPick: (id: string) => void;
    expanded: Set<string>;
    onToggle: (id: string) => void;
    onRetry: () => void;
    onWalk: () => void;
    /** Where a way can start: saved places and starred stops, real ones only. */
    starts?: WayPlace[];
    onStart?: (place: WayPlace) => void;
    /** Asks for where you are now. */
    onLocate?: () => void;
    /** Your location was asked for and isn't available. */
    unavailable?: boolean;
}) {
    const named = text.trim();

    if (finding) {
        return (
            <Empty
                loading
                chip={
                    <TravelChip
                        kind="unsure"
                        icon={<TravelIcon name="clock" />}
                    >
                        Finding
                    </TravelChip>
                }
                title={`Looking for ${named}`}
                text="Places, stops and addresses."
            />
        );
    }

    if (!place) {
        return (
            <Empty
                chip={
                    <TravelChip kind="unsure" icon={<TravelIcon name="info" />}>
                        {named ? 'Not found' : 'Not set'}
                    </TravelChip>
                }
                title={
                    named
                        ? `We couldn’t find ${named}`
                        : back
                          ? 'Where are you going back to?'
                          : 'Where do you start?'
                }
                text={
                    unavailable
                        ? 'Your location isn’t available. Pick a start or type one above.'
                        : onLocate || starts.length
                          ? 'Pick one, or type an address, a place or a stop above.'
                          : 'Type an address, a place or a stop above.'
                }
            >
                {onLocate || starts.length ? (
                    <div className="gt-starts">
                        {onLocate ? (
                            <button
                                type="button"
                                className="gt-start"
                                disabled={finding}
                                onClick={onLocate}
                            >
                                <TravelIcon name="locate" />
                                Your location
                            </button>
                        ) : null}
                        {starts.map((start) => (
                            <button
                                key={`${start.name}|${start.lat}|${start.lng}`}
                                type="button"
                                className="gt-start"
                                onClick={() => onStart?.(start)}
                            >
                                <TravelIcon name="pin" />
                                {back
                                    ? `Back to ${start.name}`
                                    : `From ${start.name}`}
                            </button>
                        ))}
                    </div>
                ) : null}
            </Empty>
        );
    }

    if (status.kind === 'loading' || status.kind === 'idle') {
        return (
            <Empty
                loading
                chip={
                    <TravelChip
                        kind="unsure"
                        icon={<TravelIcon name="clock" />}
                    >
                        Loading
                    </TravelChip>
                }
                title={`Finding the ways ${back ? 'to' : 'from'} ${place.name}`}
                text="From the timetable."
            />
        );
    }

    if (status.kind === 'error') {
        return (
            <Empty
                chip={
                    <TravelChip kind="unsure" icon={<TravelIcon name="info" />}>
                        Not loaded
                    </TravelChip>
                }
                title={status.message}
                text="Nothing was changed."
            >
                <div className="gt-starts">
                    <button
                        type="button"
                        className="gt-start"
                        onClick={onRetry}
                    >
                        Try again
                    </button>
                </div>
            </Empty>
        );
    }

    const { answer } = status;
    const live = answer.journeys.some((j) =>
        j.legs.some((leg) => leg.realtime),
    );
    const walk = answer.journeys.find((j) => j.mode === 'walk');

    return (
        <>
            <PlanChecks
                checks={checksFor(answer, chosen, {
                    target,
                    day,
                    stepfree: false,
                })}
            />
            {shown.length ? (
                <PlanOptions
                    options={shown}
                    answer={answer}
                    target={target}
                    destination={back ? place.name : destination}
                    selected={chosen?.id ?? null}
                    onSelect={onPick}
                    expanded={expanded}
                    onToggle={onToggle}
                />
            ) : mode === 'transit' ? (
                <Empty
                    chip={
                        <TravelChip
                            kind="calm"
                            icon={<TravelIcon name="walk" />}
                        >
                            Walk
                        </TravelChip>
                    }
                    title="Walking is quickest"
                    text={`No tram or bus beats the ${walk ? `${walk.duration_min}-minute ` : ''}walk ${back ? `back to ${place.name}` : `from ${place.name}`}.`}
                >
                    {walk ? (
                        <div className="gt-starts">
                            <button
                                type="button"
                                className="gt-start"
                                onClick={onWalk}
                            >
                                Show the walk
                            </button>
                        </div>
                    ) : null}
                </Empty>
            ) : (
                <p className="gt-none">
                    <TravelChip kind="unsure" icon={<TravelIcon name="info" />}>
                        Not found
                    </TravelChip>{' '}
                    {`The planner found no ${mode === 'bike' ? 'cycling' : 'walking'} way ${back ? `back to ${place.name}` : `from ${place.name}`}.`}
                </p>
            )}
            <p className="gt-source">
                <TravelIcon name="info" />
                <span>
                    {live
                        ? 'Live times where the feed has them, timetable times for the rest. Check departures before you go.'
                        : 'Scheduled times from the timetable. Not live: check departures before you go.'}
                </span>
            </p>
        </>
    );
}
