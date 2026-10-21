/* Get there (the mock's events-next get-there.js, and wall.js getThere()):
   the way to an event, planned the way Travel plans a trip and shown with
   Travel's own pieces (what it checked, the options and their Journey
   details; owner, 18 Oct 2026: trips look like Travel everywhere). From
   where you'd set off (the From you chose, or the place of the appointment
   your day ends with before it), by the start, leaving now, or the way
   back after it ends; by tram and bus, on foot or by bike. Each way's chip
   says how long it takes; the map draws the way picked. Loaded when the
   page opens, with the map. */

import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import type { EventOccurrence } from '@/components/events/types';
import { TravelChip } from '@/components/travel/labels';
import { findPlace } from '@/components/travel/plan/plan-fields';
import {
    berlinNow,
    checksFor,
    rankOptions,
} from '@/components/travel/plan/plan-model';
import type {
    PlanAnswer,
    PlanOption,
    PlanTarget,
} from '@/components/travel/plan/plan-model';
import { PlanChecks, PlanOptions } from '@/components/travel/plan/plan-results';
import { TravelIcon } from '@/components/travel/travel-icon';
import { Icon } from '@/components/ui/icon';
import type { DayProjection } from '@/types/day';
import { GetThereMap } from './get-there-map';
import './get-there.css';

export type WayWhen = 'arrive' | 'now' | 'back';
export type WayMode = 'transit' | 'walk' | 'bike';

/** Where you'd set off, and why there (a note under From). */
export type WayStart = {
    name: string;
    lat: number;
    lng: number;
    note: string | null;
};

type Place = { name: string; lat: number; lng: number };

type Status =
    | { kind: 'idle' }
    | { kind: 'loading' }
    | { kind: 'done'; answer: PlanAnswer }
    | { kind: 'error'; message: string };

const MODES: { id: WayMode; name: string; icon: 'train' | 'walk' | 'bike' }[] =
    [
        { id: 'transit', name: 'Transit', icon: 'train' },
        { id: 'walk', name: 'Walk', icon: 'walk' },
        { id: 'bike', name: 'Bike', icon: 'bike' },
    ];

const clockOf = (iso: string) =>
    new Date(iso).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Europe/Berlin',
    });

// Short, so the row fits a phone: "25 min", "1 h 29".
const short = (n: number) =>
    n < 60
        ? `${n} min`
        : `${Math.floor(n / 60)} h${n % 60 ? ` ${n % 60}` : ''}`;
const spoken = (n: number) =>
    n < 60
        ? `${n} min`
        : `${Math.floor(n / 60)} h${n % 60 ? ` ${n % 60} min` : ''}`;

function headers() {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN':
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '',
    };
}

export default function GetThere({
    occurrence: o,
    title,
    date,
    start,
    when,
    onWhen,
    initialMode,
    day,
}: {
    occurrence: EventOccurrence;
    title: string;
    date: string;
    start: WayStart | null;
    when: WayWhen;
    onWhen: (when: WayWhen) => void;
    initialMode: WayMode;
    /** The day shown, as Travel reads it (loaded with this page). */
    day: DayProjection | null | undefined;
}) {
    const venue: Place | null =
        o.venue.lat !== null && o.venue.lng !== null
            ? {
                  name: o.venue.name ?? 'The venue',
                  lat: o.venue.lat,
                  lng: o.venue.lng,
              }
            : null;
    const startTime = clockOf(o.occurrence_start);
    const endTime = o.occurrence_end ? clockOf(o.occurrence_end) : null;
    const back = when === 'back';

    // From: the start, or what you typed once it's found.
    const [text, setText] = useState(start?.name ?? '');
    const [place, setPlace] = useState<Place | null>(start);
    const [finding, setFinding] = useState(false);
    const [mode, setMode] = useState<WayMode>(initialMode);
    const [status, setStatus] = useState<Status>({ kind: 'idle' });
    // Try again asks the same plan once more.
    const [attempt, setAttempt] = useState(0);
    const [picked, setPicked] = useState<string | null>(null);
    const [expanded, setExpanded] = useState<Set<string>>(new Set());
    const [menu, setMenu] = useState(false);
    const bar = useRef<HTMLDivElement>(null);
    const timeButton = useRef<HTMLButtonElement>(null);
    // The note says why you'd start there, while you still do.
    const noted =
        !!start?.note &&
        text === start.name &&
        place?.lat === start.lat &&
        place?.lng === start.lng;

    // "My day" in what was checked: the day shown, asked for once.
    useEffect(() => {
        if (day === undefined) {
            router.reload({ only: ['day'] });
        }
    }, [day]);

    // One request per start and time: every way (tram and bus, on foot, by
    // bike) comes back in the same answer.
    useEffect(() => {
        if (!place || !venue || (back && !o.occurrence_end)) {
            setStatus({ kind: 'idle' });

            return;
        }

        const there = back ? place : venue;
        const from = back ? venue : place;
        const body: Record<string, string | number> = {
            to_name: there.name,
            to_lat: there.lat,
            to_lng: there.lng,
            from_name: from.name,
            from_lat: from.lat,
            from_lng: from.lng,
        };

        if (when === 'arrive') {
            body.depart_at = o.occurrence_start;
            body.arrive_by = '1';
        } else if (back) {
            body.depart_at = o.occurrence_end!;
        }

        const controller = new AbortController();
        setStatus({ kind: 'loading' });
        setPicked(null);
        setExpanded(new Set());

        // In the body: where you go and start never sit in a URL.
        fetch('/api/journey/plan', {
            method: 'POST',
            headers: headers(),
            credentials: 'same-origin',
            body: JSON.stringify(body),
            signal: controller.signal,
        })
            .then(async (response) => {
                const data = (await response
                    .json()
                    .catch(() => null)) as PlanAnswer | null;

                setStatus(
                    response.ok && data
                        ? { kind: 'done', answer: data }
                        : {
                              kind: 'error',
                              message:
                                  response.status === 429
                                      ? 'Too many plans in a row. Try again in a minute.'
                                      : 'Routes aren’t available right now. Try again in a moment.',
                          },
                );
            })
            .catch((failure: Error) => {
                if (failure.name !== 'AbortError') {
                    setStatus({
                        kind: 'error',
                        message: 'The connection was lost. Try again.',
                    });
                }
            });

        return () => controller.abort();
        // The venue and times are the occurrence's; a new one remounts this.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [place, when, attempt]);

    const answer = status.kind === 'done' ? status.answer : null;
    const options = useMemo<PlanOption[]>(
        () =>
            (answer?.journeys ?? []).map((journey, index) => ({
                id: `${index}-${journey.depart_at}`,
                journey,
                lifts: answer?.conditions?.lifts.by_journey[index] ?? null,
            })),
        [answer],
    );
    // As the mock orders them: the earliest there first, and best.
    const ways = useMemo(
        () => ({
            transit: rankOptions(
                options.filter((x) => x.journey.mode === 'transit'),
                'arrival',
                [],
            ).slice(0, 3),
            walk: options.filter((x) => x.journey.mode === 'walk').slice(0, 1),
            bike: options.filter((x) => x.journey.mode === 'bike').slice(0, 1),
        }),
        [options],
    );
    const shown = ways[mode];
    const chosen = shown.find((x) => x.id === picked) ?? shown[0] ?? null;
    // The event is what the way leads to: by its start, with its margin.
    const target: PlanTarget | null = back
        ? null
        : {
              id: `event:${o.id}`,
              title,
              kind: 'event',
              start_at: o.occurrence_start,
              start_time: startTime,
              official: false,
          };

    // Each way's chip: how long it takes, for the time chosen.
    const minutesOf = (m: WayMode) => ways[m][0]?.journey.duration_min ?? null;

    function commit(event?: FormEvent) {
        event?.preventDefault();
        const typed = text.trim();

        if (!typed) {
            setPlace(null);

            return;
        }

        if (start && typed === start.name) {
            setPlace(start);

            return;
        }

        if (place && typed === place.name) {
            return;
        }

        setFinding(true);
        findPlace(typed, 'origin').then((hit) => {
            setFinding(false);
            setPlace(hit ? { name: typed, lat: hit.lat, lng: hit.lng } : null);
        });
    }

    // The time's menu floats over the page; a choice, Escape or a tap
    // outside closes it.
    useEffect(() => {
        if (!menu) {
            return;
        }

        const outside = (event: PointerEvent) => {
            if (!bar.current?.contains(event.target as Node)) {
                setMenu(false);
            }
        };
        document.addEventListener('pointerdown', outside, true);

        return () => document.removeEventListener('pointerdown', outside, true);
    }, [menu]);

    const dateLabel = new Date(`${date}T12:00:00Z`).toLocaleDateString(
        'en-GB',
        { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' },
    );
    const whens: [WayWhen, string, string, string][] = [
        [
            'arrive',
            `Arrive by ${startTime}`,
            'When it starts',
            `By ${startTime}`,
        ],
        ['now', 'Leave now', `It’s ${berlinNow().time} now`, 'Now'],
        ...(endTime
            ? ([
                  [
                      'back',
                      'Way back',
                      `After it ends at ${endTime}`,
                      'Way back',
                  ],
              ] as [WayWhen, string, string, string][])
            : []),
    ];
    const chosenWhen = whens.find(([w]) => w === when) ?? whens[0];

    return (
        <form className="place-journey" onSubmit={commit}>
            <div className={`place-route${back ? 'is-back' : ''}`}>
                <div className="event-route-rail" aria-hidden="true">
                    <span className="route-dot" />
                    <span className="event-route-connector" />
                    <Icon name="pin" variant="line" />
                </div>
                <div className="event-route-fields">
                    <label className="route-field">
                        {back ? 'Back to' : 'From'}
                        <input
                            name="from"
                            placeholder="Address, place or stop"
                            maxLength={120}
                            required
                            autoComplete="off"
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            onBlur={() => commit()}
                        />
                        {noted && start ? (
                            <small className="route-field-note">
                                {start.note}
                            </small>
                        ) : null}
                    </label>
                    <div className="place-journey-destination">
                        <span>
                            <small>{back ? 'From' : 'To'}</small>
                            <strong>{venue?.name ?? o.venue.name}</strong>
                        </span>
                    </div>
                </div>
            </div>
            <div className="gt-bar" ref={bar}>
                <div className="gt-bar-row">
                    <button
                        ref={timeButton}
                        type="button"
                        className="gt-time"
                        aria-haspopup="menu"
                        aria-expanded={menu}
                        aria-controls={`gt-when-${o.id}`}
                        aria-label={`When: ${chosenWhen[1]}, ${dateLabel}`}
                        onClick={() => setMenu((open) => !open)}
                    >
                        <span className="gt-pill">
                            <Icon name="clock" variant="line" />
                            <span>{chosenWhen[3]}</span>
                            <Icon name="down" variant="line" />
                        </span>
                    </button>
                    <fieldset className="gt-modes">
                        <legend>Travel by</legend>
                        {MODES.map((m) => {
                            // Unknown (not in the answer), it shows its name.
                            const n = answer ? minutesOf(m.id) : null;
                            const label = n ? short(n) : '';

                            return (
                                <label
                                    key={m.id}
                                    className={label ? 'is-timed' : undefined}
                                >
                                    <input
                                        type="radio"
                                        name="mode"
                                        value={m.id}
                                        checked={mode === m.id}
                                        onChange={() => setMode(m.id)}
                                        aria-label={
                                            n
                                                ? `${m.name}, ${spoken(n)}`
                                                : m.name
                                        }
                                    />
                                    <span>
                                        <Icon name={m.icon} variant="line" />
                                        <span
                                            className="event-mode-name"
                                            aria-hidden="true"
                                        >
                                            {m.name}
                                        </span>
                                        {label ? (
                                            <span className="gt-mins">
                                                {label}
                                            </span>
                                        ) : null}
                                    </span>
                                </label>
                            );
                        })}
                    </fieldset>
                </div>
                <div
                    className="gt-menu"
                    id={`gt-when-${o.id}`}
                    role="menu"
                    aria-label="When"
                    hidden={!menu}
                    onKeyDown={(event) => {
                        const items = [
                            ...event.currentTarget.querySelectorAll<HTMLButtonElement>(
                                'button',
                            ),
                        ];

                        if (event.key === 'Escape') {
                            event.preventDefault();
                            event.stopPropagation();
                            setMenu(false);
                            timeButton.current?.focus();
                        } else if (
                            event.key === 'ArrowDown' ||
                            event.key === 'ArrowUp'
                        ) {
                            event.preventDefault();
                            const at = items.indexOf(
                                document.activeElement as HTMLButtonElement,
                            );
                            items[
                                (at +
                                    (event.key === 'ArrowDown' ? 1 : -1) +
                                    items.length) %
                                    items.length
                            ]?.focus();
                        }
                    }}
                >
                    <small>{dateLabel}</small>
                    {whens.map(([value, name, sub]) => (
                        <button
                            key={value}
                            type="button"
                            role="menuitemradio"
                            aria-checked={when === value}
                            onClick={() => {
                                onWhen(value);
                                setMenu(false);
                                timeButton.current?.focus();
                            }}
                        >
                            <span>
                                <strong>{name}</strong>
                                <small>{sub}</small>
                            </span>
                            <Icon name="check" variant="line" />
                        </button>
                    ))}
                </div>
            </div>
            {venue ? (
                <GetThereMap
                    venue={venue}
                    venueName={venue.name}
                    start={place}
                    journey={chosen?.journey ?? null}
                />
            ) : null}
            <div className="gt">
                <Results
                    status={status}
                    place={place}
                    text={text}
                    finding={finding}
                    back={back}
                    mode={mode}
                    shown={shown}
                    chosen={chosen}
                    target={target}
                    day={day}
                    venue={venue?.name ?? o.venue.name ?? 'the venue'}
                    onPick={setPicked}
                    expanded={expanded}
                    onToggle={(id) => {
                        setPicked(id);
                        setExpanded((open) => {
                            const next = new Set(open);

                            if (next.has(id)) {
                                next.delete(id);
                            } else {
                                next.add(id);
                            }

                            return next;
                        });
                    }}
                    onRetry={() => setAttempt((n) => n + 1)}
                    onWalk={() => setMode('walk')}
                />
            </div>
        </form>
    );
}

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
            className={`gt-empty${loading ? 'is-loading' : ''}`}
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

function Results({
    status,
    place,
    text,
    finding,
    back,
    mode,
    shown,
    chosen,
    target,
    day,
    venue,
    onPick,
    expanded,
    onToggle,
    onRetry,
    onWalk,
}: {
    status: Status;
    place: Place | null;
    text: string;
    finding: boolean;
    back: boolean;
    mode: WayMode;
    shown: PlanOption[];
    chosen: PlanOption | null;
    target: PlanTarget | null;
    day: DayProjection | null | undefined;
    venue: string;
    onPick: (id: string) => void;
    expanded: Set<string>;
    onToggle: (id: string) => void;
    onRetry: () => void;
    onWalk: () => void;
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
                text="Type an address, a place or a stop above."
            />
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
                    destination={back ? place.name : venue}
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
