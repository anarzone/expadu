import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { TravelChip } from '@/components/travel/labels';
import { lineLook, vehicleOf } from '@/components/travel/line-look';
import { stopName } from '@/components/travel/stop-name';
import { TravelIcon } from '@/components/travel/travel-icon';
import { csrfHeaders } from '@/lib/csrf';
import { bureaucracy } from '@/routes';
import type { DayItem, DayProjection, NextTrip } from '@/types/day';
import type { Journey } from '@/types/transit';

/** The day's forecast by the hour (the deferred `dayWeather` prop). */
export type DayWeather = {
    available: boolean;
    hours: {
        at: string;
        icon: string | null;
        condition: string | null;
        temperature: number | null;
        precip_probability: number | null;
    }[];
};

type Hour = DayWeather['hours'][number];

type MyDayProps = {
    day: DayProjection;
    weather: DayWeather | null | undefined;
    /** Your next trip: Nearby's card, compact (the appointment comes next). */
    trip: ReactNode;
    /** Opens Plan a trip to a plan, from the one before it when there is one. */
    onPlan: (to: DayItem, after: DayItem | null) => void;
    /** Opens Plan a trip from a plan's place to Home, or back to where you are now. */
    onWayBack: (after: DayItem, to: 'home' | 'here') => void;
};

const TIMEZONE = 'Europe/Berlin';

/** The forecast's own words, as the mock's sky names where they agree. */
const SKY: Record<string, string> = {
    'clear-day': 'Clear',
    'clear-night': 'Clear',
    'partly-cloudy-day': 'Partly cloudy',
    'partly-cloudy-night': 'Partly cloudy',
    cloudy: 'Cloudy',
    fog: 'Mist',
    rain: 'Rain',
};

const skyOf = (hour: Hour) =>
    (hour.icon ? SKY[hour.icon] : undefined) ?? hour.condition ?? '';

/** Likely rain: the mock's "wet" (a 50% chance or more). */
const wet = (hour: Hour | undefined) => (hour?.precip_probability ?? 0) >= 50;

const clockOf = (iso: string) =>
    new Intl.DateTimeFormat('en-GB', {
        timeZone: TIMEZONE,
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));

const hourKey = (iso: string) => clockOf(iso).slice(0, 2);

const dateLabel = (date: string) =>
    new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    }).format(new Date(`${date}T12:00:00Z`));

const routable = (item: DayItem) =>
    item.location?.lat != null && item.location.lng != null;

const placeOf = (item: DayItem) => item.location?.label ?? item.title;

/** The device clock, each 30 s: "Now" moves on while the page stays open. */
function useNow(): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 30_000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}

/** Of two plans that overlap, the one you're going to (this device only). */
function usePicks(date: string) {
    const key = `travel.my-day.picks:${date}`;
    const [picks, setPicks] = useState<Record<string, string>>(() => {
        try {
            return JSON.parse(window.localStorage.getItem(key) ?? '{}');
        } catch {
            return {};
        }
    });
    const pick = (pair: string, id: string) =>
        setPicks((current) => {
            const next = { ...current, [pair]: id };

            try {
                window.localStorage.setItem(key, JSON.stringify(next));
            } catch {
                // The choice still shows until the page closes.
            }

            return next;
        });

    return [picks, pick] as const;
}

type Way =
    | { state: 'loading' }
    /** The planner gave ways, but none there by the start. */
    | { state: 'none' }
    /** The planner didn't answer: not checked, never a guess. */
    | { state: 'unchecked' }
    | {
          state: 'found';
          depart: string;
          arrive: string;
          lines: string[];
          live: boolean;
      };

function jsonHeaders() {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...csrfHeaders(),
    };
}

/**
 * Of the ways there by a plan's start, the one Leave now would take
 * (App\Day\NextTrip, owner decision 9 Oct 2026): the latest leave that keeps
 * the margin, the earliest arrival breaking a tie; else the one with the
 * most room. So My day and Leave now never name different ways for a plan.
 */
function wayFor(
    journeys: Journey[],
    start: string,
    margin: number,
): Journey | null {
    const by = new Date(start).getTime();
    const spare = (j: Journey) =>
        Math.floor((by - new Date(j.arrive_at).getTime()) / 60_000);
    const at = (iso: string) => new Date(iso).getTime();
    const fitting = journeys
        .filter((j) => spare(j) >= margin)
        .sort(
            (a, b) =>
                at(b.depart_at) - at(a.depart_at) ||
                at(a.arrive_at) - at(b.arrive_at),
        );

    if (fitting.length) {
        return fitting[0];
    }

    const tight = journeys
        .filter((j) => spare(j) >= 0)
        .sort(
            (a, b) => spare(b) - spare(a) || at(b.depart_at) - at(a.depart_at),
        );

    return tight[0] ?? null;
}

/**
 * The way to a later plan from the one before it (wayFor), asked once in
 * the body (never the URL). A way you added to your plan is that one.
 */
function useWayTo(to: DayItem | null, from: DayItem | null): Way | null {
    const [way, setWay] = useState<{ key: string; way: Way } | null>(null);
    const key = to && from ? `${from.id}>${to.id}` : null;

    useEffect(() => {
        if (!to || !from || !key || to.planned || !to.start_at) {
            return;
        }

        const controller = new AbortController();
        const body = {
            to_name: placeOf(to),
            to_lat: to.location!.lat,
            to_lng: to.location!.lng,
            from_name: placeOf(from),
            from_lat: from.location!.lat,
            from_lng: from.location!.lng,
            depart_at: to.start_at,
            arrive_by: '1',
        };
        const margin = to.official ? 10 : 5;
        setWay({ key, way: { state: 'loading' } });

        void fetch('/api/journey/plan', {
            method: 'POST',
            headers: jsonHeaders(),
            credentials: 'same-origin',
            body: JSON.stringify(body),
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data: { journeys?: Journey[] } | null) => {
                if (!data?.journeys?.length) {
                    setWay({ key, way: { state: 'unchecked' } });

                    return;
                }

                const best = wayFor(data.journeys, to.start_at!, margin);
                setWay({
                    key,
                    way: best
                        ? {
                              state: 'found',
                              depart: best.depart_time,
                              arrive: best.arrive_time,
                              lines: best.legs
                                  .filter((leg) => leg.line)
                                  .map((leg) => leg.line!),
                              live: best.legs.some((leg) => leg.realtime),
                          }
                        : { state: 'none' },
                });
            })
            .catch((failure: Error) => {
                if (failure.name !== 'AbortError') {
                    setWay({ key, way: { state: 'unchecked' } });
                }
            });

        return () => controller.abort();
        // Asked again only for another pair of plans.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    if (to?.planned) {
        return {
            state: 'found',
            depart: to.planned.depart_time,
            arrive: to.planned.arrive_time,
            lines: to.planned.ride?.line ? [to.planned.ride.line] : [],
            live: false,
        };
    }

    return way && way.key === key ? way.way : null;
}

/**
 * My day (phones, `?view=day`; the mock's renderDay): the rest of today as
 * a river. Now and where you are, your next trip, then each plan in order:
 * an appointment with What to bring, free hours with the forecast, the way
 * to a later plan, two plans that overlap asking which one, and Getting
 * home. All of it from your day (App\Day\DayProjection) and the forecast;
 * nothing is guessed, and what isn't known says so.
 */
export function MyDay({ day, weather, trip, onPlan, onWayBack }: MyDayProps) {
    const now = useNow();
    const [picks, pick] = usePicks(day.date);
    const hours = useMemo(() => {
        const byHour = new Map<string, Hour>();

        for (const hour of weather?.available ? weather.hours : []) {
            byHour.set(hourKey(hour.at), hour);
        }

        return byHour;
    }, [weather]);

    const nextTrip = day.next_trip;
    const ahead = day.items
        .filter(
            (item) =>
                item.start_at &&
                !item.completed &&
                item.available &&
                item.state !== 'elapsed',
        )
        .sort((a, b) => a.start_at!.localeCompare(b.start_at!));
    // Two plans that overlap: the server's link says so.
    const overlaps = new Map<string, DayItem>();

    for (const link of day.links) {
        if (link.reason === 'overlap') {
            const second = ahead.find((item) => item.id === link.to_id);

            if (second) {
                overlaps.set(link.from_id, second);
            }
        }
    }

    const skipped = new Set<string>();

    for (const [firstId, second] of overlaps) {
        const chosen = picks[`${firstId}|${second.id}`];

        if (chosen) {
            skipped.add(chosen === firstId ? second.id : firstId);
        }
    }

    const going = ahead.filter((item) => !skipped.has(item.id));
    const before = (item: DayItem) => {
        const index = going.findIndex((entry) => entry.id === item.id);

        return index > 0 ? going[index - 1] : null;
    };
    // The way to the first later plan that isn't your next trip.
    const later = going.find(
        (item, index) =>
            index > 0 &&
            item.id !== nextTrip?.target.id &&
            routable(item) &&
            routable(going[index - 1]) &&
            !overlaps.has(going[index - 1].id),
    );
    const way = useWayTo(later ?? null, later ? before(later) : null);
    const last = going.at(-1) ?? null;
    const here =
        nextTrip?.from && nextTrip.from.source !== 'plan'
            ? nextTrip.from.label
            : null;
    const current = hours.get(hourKey(new Date(now).toISOString()));
    const until = current
        ? [...hours.values()].find(
              (hour) =>
                  new Date(hour.at).getTime() > now &&
                  skyOf(hour) !== skyOf(current),
          )
        : undefined;
    const ride = nextTrip?.journey?.legs.find((leg) => leg.line);
    // Where your next trip gets off, beside its plan's place.
    const alight = nextTrip?.journey?.legs.filter((leg) => leg.line).at(-1);
    const look = ride ? lineLook(ride.line, vehicleOf(ride.mode)) : null;

    const freeHours = (from: DayItem, to: DayItem) => {
        if (!from.end_at || !to.start_at) {
            return null;
        }

        const start = new Date(from.end_at).getTime();
        const end = new Date(to.start_at).getTime();
        const free = Math.floor((end - start) / 3_600_000);

        if (free < 2) {
            return null;
        }

        const rows: Hour[] = [];

        for (const hour of hours.values()) {
            const at = new Date(hour.at).getTime();

            if (at > start && at <= end && rows.length < 4) {
                rows.push(hour);
            }
        }

        return (
            <li className="is-free" key={`free-${to.id}`}>
                <time>{from.end_time}</time>
                <span className="day-rail is-free" />
                <div>
                    <strong className="day-free">{`${free} hours free`}</strong>
                    <small>
                        {rows.length
                            ? 'Weather along the way'
                            : 'No forecast for these hours yet'}
                    </small>
                    {rows.length ? (
                        <ul className="day-hours">
                            {rows.map((hour) => (
                                <li key={hour.at}>
                                    <time>{`${hourKey(hour.at)}:00`}</time>
                                    <TravelIcon
                                        name={wet(hour) ? 'cloud' : 'sun'}
                                    />
                                    <span>
                                        {`${skyOf(hour)}, ${Math.round(hour.temperature ?? 0)}°${hour.precip_probability ? ` · ${hour.precip_probability}% rain` : ''}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </div>
            </li>
        );
    };

    const wayTo = (item: DayItem) => {
        const from = before(item);
        const text =
            way?.state === 'found'
                ? `Leave ${way.depart} → there ${way.arrive} · from ${placeOf(from!)}${way.lines.length ? `, line ${way.lines.join(', then ')}` : ''} · ${way.live ? 'live' : 'timetable'}`
                : way?.state === 'loading'
                  ? 'Finding the way…'
                  : way?.state === 'none'
                    ? `No way gets there by ${item.start_time}.`
                    : 'Not checked: route times aren’t available right now.';

        return (
            <li className="is-later" key={`way-${item.id}`}>
                <time>{way?.state === 'found' ? way.depart : 'later'}</time>
                <span className="day-rail is-dashed" />
                <div>
                    <section className="day-open">
                        <strong>{`Getting to ${placeOf(item)}`}</strong>
                        <small>{text}</small>
                        <button
                            type="button"
                            className="quiet-button"
                            onClick={() => onPlan(item, from)}
                        >
                            Plan the way
                        </button>
                    </section>
                </div>
            </li>
        );
    };

    const rows: ReactNode[] = [];

    ahead.forEach((item, index) => {
        const prev = index > 0 ? ahead[index - 1] : null;
        const isSkipped = skipped.has(item.id);

        if (item.id === nextTrip?.target.id && trip) {
            rows.push(
                <li
                    className="is-trip"
                    key="trip"
                    style={
                        look
                            ? ({ '--trip-line': look.colour } as CSSProperties)
                            : undefined
                    }
                >
                    <time>{nextTrip.leave_time ?? ''}</time>
                    <span className="day-rail is-ride" />
                    <div>{trip}</div>
                </li>,
            );
        }

        const from = before(item);

        if (from && !isSkipped && !overlaps.has(from.id)) {
            const free = freeHours(from, item);

            if (free) {
                rows.push(free);
            }
        }

        if (item.id === later?.id) {
            rows.push(wayTo(item));
        }

        const hour = item.start_at
            ? hours.get(hourKey(item.start_at))
            : undefined;
        const until = item.end_time ? ` · until ${item.end_time}` : '';
        const stop =
            item.id === nextTrip?.target.id && alight
                ? ` · ${stopName(alight.to.name, nextTrip.city)}`
                : '';

        rows.push(
            item.kind === 'appointment' ? (
                <li key={item.id}>
                    <time>{item.start_time}</time>
                    <span className="day-rail">
                        <i className="day-dot is-plan" />
                    </span>
                    <div>
                        <section className="day-plan">
                            <strong>{item.title}</strong>
                            <span>{`${placeOf(item)}${stop}${until}`}</span>
                            {item.process_key ? (
                                <Link
                                    className="day-paper"
                                    href={bureaucracy({
                                        query: {
                                            view: 'paperwork',
                                            process: item.process_key,
                                        },
                                    })}
                                >
                                    <TravelIcon name="document" />
                                    What to bring
                                </Link>
                            ) : null}
                        </section>
                    </div>
                </li>
            ) : (
                <li key={item.id}>
                    <time>{item.start_time}</time>
                    <span className="day-rail is-event">
                        <i className="day-dot is-event" />
                    </span>
                    <div>
                        <section
                            className={
                                isSkipped ? 'day-event is-skipped' : 'day-event'
                            }
                            aria-label={item.title}
                        >
                            <strong>{item.title}</strong>
                            <span>{`${placeOf(item)}${stop}${until}`}</span>
                            {wet(hour) ? (
                                <TravelChip
                                    kind="attention"
                                    icon={<TravelIcon name="cloud" />}
                                    size="sm"
                                >
                                    {`${skyOf(hour!)} likely · forecast`}
                                </TravelChip>
                            ) : null}
                            {isSkipped ? (
                                <small>Skipping this one</small>
                            ) : null}
                        </section>
                    </div>
                </li>
            ),
        );

        const second = overlaps.get(item.id);

        if (second && prev?.id !== second.id) {
            const pair = `${item.id}|${second.id}`;

            rows.push(
                <li className="is-choice" key={`choice-${pair}`}>
                    <time />
                    <span className="day-rail is-event" />
                    <div>
                        <div className="day-overlap">
                            <strong>
                                <TravelChip
                                    kind="attention"
                                    icon={<TravelIcon name="alert" />}
                                >
                                    Overlap
                                </TravelChip>
                                {` These overlap from ${second.start_time} to ${item.end_time}. Which one?`}
                            </strong>
                            <div role="group" aria-label="Choose a plan">
                                {[item, second].map((choice) => (
                                    <button
                                        key={choice.id}
                                        type="button"
                                        aria-pressed={picks[pair] === choice.id}
                                        onClick={() => pick(pair, choice.id)}
                                    >
                                        {choice.title}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                </li>,
            );
        }
    });

    return (
        <>
            <div className="day-head">
                <span>
                    <strong>Today</strong>
                    <small>{dateLabel(day.date)}</small>
                </span>
                {current ? (
                    <span className="day-now-weather">
                        <TravelIcon name={wet(current) ? 'cloud' : 'sun'} />
                        {`${Math.round(current.temperature ?? 0)}° · ${skyOf(current).toLowerCase()}${until ? ` until about ${hourKey(until.at)}:00` : ''}`}
                    </span>
                ) : null}
            </div>
            <ol className="day-river">
                <li className="is-now">
                    <time>{clockOf(new Date(now).toISOString())}</time>
                    <span className="day-rail">
                        <i className="day-dot is-now" />
                    </span>
                    <div>
                        <strong>
                            {nextTrip?.from
                                ? `Now, ${nextTrip.from.source === 'plan' ? 'at' : 'near'} ${nextTrip.from.label}`
                                : 'Now'}
                        </strong>
                        <small>
                            {nextTrip?.from
                                ? WHERE[nextTrip.from.source]
                                : 'Where you are isn’t known'}
                        </small>
                    </div>
                </li>
                {rows}
                {last ? (
                    <li className="is-home">
                        <time>{last.end_time ?? ''}</time>
                        <span className="day-rail">
                            <i className="day-dot is-home" />
                        </span>
                        <div>
                            <section className="day-open">
                                <strong>Getting home</strong>
                                <small>
                                    {day.home
                                        ? `The way back from ${placeOf(last)}.`
                                        : `Add your home and Expadu plans the way back from ${placeOf(last)}.`}
                                </small>
                                <div className="day-open-actions">
                                    {day.home ? (
                                        routable(last) ? (
                                            <button
                                                type="button"
                                                className="quiet-button"
                                                onClick={() =>
                                                    onWayBack(last, 'home')
                                                }
                                            >
                                                <TravelIcon name="home" />
                                                Plan the way home
                                            </button>
                                        ) : null
                                    ) : (
                                        <Link
                                            as="button"
                                            type="button"
                                            className="quiet-button"
                                            href={`/timetable?${new URLSearchParams({ view: 'journey', set: 'home' })}`}
                                        >
                                            <TravelIcon name="home" />
                                            Add home
                                        </Link>
                                    )}
                                    {here && routable(last) ? (
                                        <button
                                            type="button"
                                            className="quiet-button"
                                            onClick={() =>
                                                onWayBack(last, 'here')
                                            }
                                        >
                                            <TravelIcon name="route" />
                                            {`Back to ${here}`}
                                        </button>
                                    ) : null}
                                </div>
                            </section>
                        </div>
                    </li>
                ) : null}
            </ol>
        </>
    );
}

/** Where "Now" comes from. */
const WHERE: Record<NonNullable<NextTrip['from']>['source'], string> = {
    live: 'From your location',
    confirmed: 'Where you said you are',
    ping: 'Your last known location',
    area: 'Your area, roughly',
    plan: 'Until your plan ends',
};
