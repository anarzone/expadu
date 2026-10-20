import type { CSSProperties, ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { JourneyMap } from '@/components/journey/journey-map';
import { useLocationSharingEnabled } from '@/hooks/use-geolocation';
import { useTripProgress } from '@/hooks/use-trip-progress';
import type { ProgressStep } from '@/hooks/use-trip-progress';
import { cn } from '@/lib/utils';
import type { Journey } from '@/types/transit';
import { LineBadge } from '../line-badge';
import { vehicleOf } from '../line-look';
import { TravelIcon } from '../travel-icon';
import { Way } from '../way';
import {
    lookOf,
    momentNow,
    momentsOf,
    tripSteps,
    wayRows,
    word,
} from './trip-model';
import type { Look, Now, TripFor } from './trip-model';

/**
 * The trip page (departures-next navigation-ui.js; owner, 15 Oct 2026): one
 * page before and after "I'm on my way". Before, it is the trip's first
 * moment with the button; once started, the same page moves on by itself,
 * by the clock or, with location on, when you're seen at the next stop.
 * Desktops keep it in Travel's column; phones give it the whole screen.
 * Where to stand and the drawn way are deferred (plan call #9): the map
 * card shows the way on the map.
 */

type TripPageProps = {
    journey: Journey;
    trip: TripFor;
    city: string | null | undefined;
    /** Started: the trip you're on. */
    running: boolean;
    desktop: boolean;
    /** The section that handed it over ("Back to Today"), else it closes. */
    backTo?: string | null;
    onClose: () => void;
    onStart: () => void;
    starting: boolean;
    /** "Replace the trip you're on?" while another trip runs. */
    confirm?: ReactNode;
    error?: string | null;
    inPlan?: boolean;
    onAddToPlan?: () => void;
    onStop: () => Promise<boolean> | void;
    /** Not right?: Plan a trip from where you are now. */
    onReplan: () => void;
};

const MINUTE = 60_000;

/** The clock, every 15 s: the ring counts down and moments come by themselves. */
function useNow(): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 15_000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}

function Ring({ now }: { now: Now }) {
    const circles = (arc: number) => (
        <svg viewBox="0 0 36 36">
            <circle cx="18" cy="18" r="16" pathLength={100} />
            <circle
                cx="18"
                cy="18"
                r="16"
                pathLength={100}
                strokeDasharray={`${arc} 100`}
            />
        </svg>
    );

    if (now.done || !now.ring) {
        return (
            <span className="nav-ring is-done" aria-hidden="true">
                {circles(100)}
                <TravelIcon name="check" />
            </span>
        );
    }

    return (
        <span className="nav-ring" aria-hidden="true">
            {circles(now.ring.arc)}
            <span>
                <strong>{now.ring.text}</strong>
                {now.ring.label ? <small>{now.ring.label}</small> : null}
            </span>
        </span>
    );
}

/** What to look for now: one row, the same size at every moment. */
function LookRow({ look }: { look: Look }) {
    const line = {
        '--line': look.look?.colour ?? 'var(--color-text)',
    } as CSSProperties;
    const tile =
        look.tile === 'badge' ? (
            <span className="nav-look-tile is-badge" style={line}>
                {look.line}
            </span>
        ) : look.tile === 'sign' ? (
            <span
                className="nav-look-tile is-sign"
                title={`${look.line} ${look.towards ?? ''}`.trim()}
            >
                <span style={line}>{look.line}</span>
                <b>{look.towards}</b>
            </span>
        ) : look.tile === 'quote' ? (
            <span className="nav-look-tile is-quote" aria-hidden="true">
                „“
            </span>
        ) : look.tile === 'exit' ? (
            <span className="nav-look-tile is-exit" lang="de">
                Ausgang
            </span>
        ) : (
            <span className="nav-look-tile">
                <TravelIcon name={look.tile} />
            </span>
        );

    return (
        <div className="nav-look">
            {tile}
            <span className="nav-look-text">
                <strong>{look.title}</strong>
                <small>{look.text}</small>
            </span>
        </div>
    );
}

export function TripPage({
    journey,
    trip,
    city,
    running,
    desktop,
    backTo,
    onClose,
    onStart,
    starting,
    confirm,
    error,
    inPlan,
    onAddToPlan,
    onStop,
    onReplan,
}: TripPageProps) {
    const dialog = useRef<HTMLDialogElement>(null);
    const now = useNow();
    const sharing = useLocationSharingEnabled();
    const [menu, setMenu] = useState(false);
    const [stopping, setStopping] = useState(false);

    const steps = useMemo(
        () => tripSteps(journey, trip, city),
        [journey, trip, city],
    );
    const moments = useMemo(() => momentsOf(steps), [steps]);
    // Each moment where it happens: at the stop, where you get off, there.
    const progressSteps = useMemo<ProgressStep[]>(
        () =>
            moments.map((m) => {
                const step = steps[m.step];
                const placed =
                    step.kind === 'transit' ||
                    step.kind === 'arrive' ||
                    ((step.kind === 'walk' || step.kind === 'bike') &&
                        m.step > 0);

                return {
                    at: m.at,
                    lat: placed ? step.lat : null,
                    lng: placed ? step.lng : null,
                };
            }),
        [moments, steps],
    );
    const progress = useTripProgress(progressSteps, running);
    const moment = running ? moments[Math.max(0, progress.currentIdx)] : null;
    const said = momentNow(steps, moment, trip, journey, now, city);
    const how =
        running && !sharing && !said.done
            ? 'Location is off: following the timetable'
            : said.how;
    const { rows, legs, current } = wayRows(
        steps,
        trip,
        city,
        moment ? moment.step : null,
    );
    const parts = steps.filter((s) => s.kind !== 'arrive').length;
    const arrived = moment !== null && steps[moment.step].kind === 'arrive';
    const stepIndex = moment?.step ?? 0;

    // Desktops keep the trip in Travel's column; phones give it the screen.
    useEffect(() => {
        const box = dialog.current;

        if (!box) {
            return;
        }

        if (box.open) {
            box.close();
        }

        if (desktop) {
            box.show();
        } else {
            box.showModal();
        }

        // The page itself takes focus, not its close button, so the button
        // doesn't open outlined (as the mock's trip that opens with a page).
        box.focus({ preventScroll: true });

        return () => box.close();
    }, [desktop]);

    // Said once the same page runs.
    const announce = running ? 'Trip started. It moves on by itself.' : '';

    const spare = trip.startAt
        ? Math.floor(
              (Date.parse(trip.startAt) - Date.parse(journey.arrive_at)) /
                  MINUTE,
          )
        : null;
    const day = new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'Europe/Berlin',
    }).format(new Date(journey.depart_at));
    const firstRide = journey.legs.find(
        (leg) => leg.mode !== 'walk' && leg.mode !== 'bike',
    );

    const stop = async () => {
        const ok = await onStop();

        if (ok !== false) {
            setStopping(false);
            setMenu(false);
        }
    };

    return (
        <dialog
            ref={dialog}
            id="journey-navigation"
            aria-labelledby="nav-title"
            tabIndex={-1}
            onCancel={(event) => {
                event.preventDefault();
                onClose();
            }}
        >
            <div className="nav-workspace">
                <header className="nav-topbar">
                    <button
                        type="button"
                        className="nav-icon-button"
                        aria-label={
                            backTo ? `Back to ${backTo}` : 'Close the trip'
                        }
                        title={backTo ? `Back to ${backTo}` : 'Close the trip'}
                        onClick={onClose}
                    >
                        <TravelIcon name={backTo ? 'back' : 'close'} />
                    </button>
                    <div>
                        <h1 id="nav-title">To {trip.place}</h1>
                        <small>
                            {`${day} · ${running ? `there ${journey.arrive_time}` : `leave ${journey.depart_time} · there ${journey.arrive_time}`}${spare !== null ? ` · ${spare} min spare` : ''}`}
                        </small>
                    </div>
                    {running ? (
                        <div className="nav-trip-progress">
                            <span
                                className="nav-bars"
                                role="progressbar"
                                aria-label="Trip progress"
                                aria-valuemin={0}
                                aria-valuemax={parts}
                                aria-valuenow={arrived ? parts : stepIndex}
                            >
                                {Array.from({ length: parts }, (_, index) => (
                                    <i
                                        key={index}
                                        className={cn(
                                            (arrived || index < stepIndex) &&
                                                'is-done',
                                            !arrived &&
                                                index === stepIndex &&
                                                'is-now',
                                        )}
                                    />
                                ))}
                            </span>
                            <span className="nav-count">
                                {arrived
                                    ? 'Arrived'
                                    : `${stepIndex + 1} of ${parts}`}
                            </span>
                        </div>
                    ) : null}
                </header>
                <section className="nav-panel" aria-label="Your trip">
                    <div className="nav-panel-content">
                        <div className="nav-summary">
                            <section
                                className={cn(
                                    'nav-step-card',
                                    `is-${said.tone}`,
                                )}
                                aria-label={running ? 'Now' : 'First'}
                            >
                                <div className="nav-step-lead">
                                    <Ring now={said} />
                                    <div>
                                        <h2>{said.title}</h2>
                                        <p className="moment-sub">
                                            {said.why ? (
                                                <b className="nav-why">
                                                    {said.why}
                                                </b>
                                            ) : null}
                                            {said.sub}
                                        </p>
                                    </div>
                                </div>
                                <LookRow look={said.look} />
                                {running ? (
                                    <>
                                        <div className="nav-follow">
                                            <span>
                                                <TravelIcon name="locate" />
                                                {how}
                                            </span>
                                            {said.done ? null : (
                                                <button
                                                    type="button"
                                                    aria-expanded={menu}
                                                    onClick={() =>
                                                        setMenu((v) => !v)
                                                    }
                                                >
                                                    Not right?
                                                    <TravelIcon name="down" />
                                                </button>
                                            )}
                                        </div>
                                        {menu ? (
                                            <div
                                                className="nav-not-right"
                                                role="menu"
                                                aria-label="Not right?"
                                            >
                                                {said.canMiss ? (
                                                    <button
                                                        type="button"
                                                        role="menuitem"
                                                        onClick={onReplan}
                                                    >
                                                        <TravelIcon name="clock" />
                                                        I missed the{' '}
                                                        {word(firstRide)}
                                                    </button>
                                                ) : null}
                                                <button
                                                    type="button"
                                                    role="menuitem"
                                                    onClick={onReplan}
                                                >
                                                    <TravelIcon name="pin" />
                                                    I’m somewhere else
                                                </button>
                                                <button
                                                    type="button"
                                                    role="menuitem"
                                                    data-nav="end"
                                                    onClick={() => {
                                                        setMenu(false);
                                                        setStopping(true);
                                                    }}
                                                >
                                                    <TravelIcon name="stop" />
                                                    Stop the trip
                                                </button>
                                            </div>
                                        ) : null}
                                    </>
                                ) : (
                                    <div className="nav-start">
                                        <button
                                            type="button"
                                            className="nav-primary"
                                            onClick={onStart}
                                            disabled={starting}
                                        >
                                            <TravelIcon name="play" />
                                            I’m on my way
                                        </button>
                                        <div>
                                            <span>
                                                It moves on by itself as you go
                                            </span>
                                            {inPlan ? (
                                                <span className="nav-in-plan">
                                                    <TravelIcon name="calendar" />
                                                    In your plan
                                                </span>
                                            ) : onAddToPlan ? (
                                                <button
                                                    type="button"
                                                    className="nav-text-button"
                                                    onClick={onAddToPlan}
                                                >
                                                    <TravelIcon name="bookmark" />
                                                    <span>Add to plan</span>
                                                </button>
                                            ) : null}
                                        </div>
                                    </div>
                                )}
                                {error ? (
                                    <p className="nav-notice" role="status">
                                        {error}
                                    </p>
                                ) : null}
                            </section>
                        </div>
                        <section
                            className="nav-mapcard"
                            aria-label="On the map"
                        >
                            <h3 className="nav-way-title">On the map</h3>
                            <div className="nav-mapcard-box">
                                <span className="nav-map-note">{said.map}</span>
                                <JourneyMap
                                    className="trip-map"
                                    legs={journey.legs}
                                    origin={journey.legs[0]?.from ?? null}
                                    destination={
                                        journey.legs.at(-1)?.to ?? {
                                            lat: 0,
                                            lng: 0,
                                        }
                                    }
                                    position={
                                        running && progress.position
                                            ? progress.position
                                            : null
                                    }
                                />
                            </div>
                        </section>
                        <div id="nav-expanded" className="nav-expanded">
                            <div className="nav-detail-scroll">
                                <h3 className="nav-way-title">Your way</h3>
                                <Way
                                    rows={rows.map((row, index) => {
                                        const leg = legs[index];

                                        return leg
                                            ? {
                                                  ...row,
                                                  badge: (
                                                      <LineBadge
                                                          line={leg.line ?? ''}
                                                          kind={vehicleOf(
                                                              leg.mode,
                                                          )}
                                                          look={lookOf(leg)}
                                                      />
                                                  ),
                                              }
                                            : row;
                                    })}
                                    current={current}
                                />
                            </div>
                        </div>
                        {stopping || confirm ? (
                            <footer className="nav-footer">
                                {stopping ? (
                                    <div
                                        className="nav-confirm"
                                        role="group"
                                        aria-label="Stop this trip?"
                                    >
                                        <span>Stop this trip?</span>
                                        <button
                                            type="button"
                                            onClick={() => setStopping(false)}
                                        >
                                            Keep
                                        </button>
                                        <button
                                            type="button"
                                            className="nav-primary"
                                            onClick={() => void stop()}
                                        >
                                            Stop
                                        </button>
                                    </div>
                                ) : (
                                    confirm
                                )}
                            </footer>
                        ) : null}
                    </div>
                </section>
                <p className="nav-announcement sr-only" aria-live="polite">
                    {announce}
                </p>
            </div>
        </dialog>
    );
}
