import { useEffect, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { lineLook } from '../line-look';
import type { LineLook } from '../line-look';
import { TravelIcon } from '../travel-icon';
import { minutesUntil } from './nearby-model';
import type { Station, StationDir, StationRun, TimeBasis } from './types';

/**
 * The lines map's picked line, its whole route (the Line route canvas,
 * Route-desk and Route-phone; owner's "build it", 23 Oct 2026): every stop
 * top to bottom the way the tram goes, from the timetable's most common trip
 * running today (App\Transit\LineRoute), so works show as they run. Each stop shows its next tram that way from its
 * own board, read as its row comes into view, never all at once; live times
 * black, timetable grey. A tap opens a stop: the times after, its changes,
 * its board. The station you're at is marked and open. A break in the route
 * is drawn only once a notice carries its stops as data (owner, 23 Oct).
 */

export type RouteStop = {
    name: string;
    city: string | null;
    /** A KVB stop with its own board. */
    board: boolean;
    here: boolean;
    /** The other trams that stop here. */
    changes: string[];
};

export type LineRouteData = {
    line: string;
    mode: 'tram' | 'rail';
    /** None when it doesn't run here today. */
    ways: { towards: string; minutes: number | null; stops: RouteStop[] }[];
    /** Its sections today that don't come through here, end to end. */
    elsewhere: { from: string; to: string }[];
};

/** The route of a line through the station, once picked. */
export function useLineRoute(
    station: Pick<Station, 'name' | 'lat' | 'lng'>,
    line: string | null,
): LineRouteData | null | undefined {
    const [route, setRoute] = useState<{
        key: string;
        data: LineRouteData | null;
    } | null>(null);
    const key =
        line && station.lat !== null && station.lng !== null
            ? `${station.name}|${line}`
            : '';

    useEffect(() => {
        if (!key || !line || station.lat === null || station.lng === null) {
            return;
        }

        const controller = new AbortController();
        const query = new URLSearchParams({
            stop: station.name,
            lat: String(station.lat),
            lng: String(station.lng),
            line,
        });

        fetch(`/api/travel/line-route?${query}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((body: { route: LineRouteData | null } | null) =>
                setRoute({ key, data: body?.route ?? null }),
            )
            .catch(() => {
                // Offline or aborted: the map's rows stay.
            });

        return () => controller.abort();
    }, [key, line, station.name, station.lat, station.lng]);

    if (!key) {
        return null;
    }

    // Undefined while it loads; null when the timetable has no route.
    return route?.key === key ? route.data : undefined;
}

/* ---------- Each stop's own board, a few at a time ---------- */

type StopRuns = {
    name: string;
    time_basis: TimeBasis;
    is_stale: boolean;
    dirs: StationDir[];
};

const runs = new Map<string, Promise<StopRuns | null>>();
const waiting: (() => void)[] = [];
let reading = 0;
const AT_ONCE = 4;

function nextRead() {
    if (reading >= AT_ONCE) {
        return;
    }

    const start = waiting.shift();

    if (start) {
        reading += 1;
        start();
    }
}

/** A line's runs at a stop, from its board; read once a minute at most. */
function stopRuns(stop: string, line: string): Promise<StopRuns | null> {
    const key = `${line}|${stop}|${Math.floor(Date.now() / 60_000)}`;
    const known = runs.get(key);

    if (known) {
        return known;
    }

    const read = new Promise<StopRuns | null>((resolve) => {
        waiting.push(() => {
            const query = new URLSearchParams({ stop, line });

            fetch(`/api/travel/line-stop?${query}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((body: { stop: StopRuns | null } | null) =>
                    resolve(body?.stop ?? null),
                )
                .catch(() => resolve(null))
                .finally(() => {
                    reading -= 1;
                    nextRead();
                });
        });
        nextRead();
    });
    // A failed read is tried again next time the stop comes into view.
    read.then((result) => result ?? runs.delete(key));
    runs.set(key, read);

    return read;
}

/** Names as boards and the timetable write them, compared loosely. */
const plain = (name: string) =>
    name
        .toLocaleLowerCase('de')
        .replace(/^köln[\s-]+/, '')
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .trim();
const sameStop = (a: string, b: string) => {
    const x = plain(a);
    const y = plain(b);

    return !!x && !!y && (x === y || x.includes(y) || y.includes(x));
};

/**
 * The stop's runs the way this list goes: those heading to, or by way of, a
 * stop further along it. A run that ends somewhere off the timetable's
 * route (a diversion, a short working, a through run) still goes this way
 * when it passes the stops ahead, and says where it ends.
 */
function thisWay(
    dirs: StationDir[],
    ahead: string[],
    away: string[],
): { dir: StationDir; run: StationRun } | null {
    const at = (name: string, names: string[]) =>
        names.some((n) => sameStop(name, n));
    const passes = (d: StationDir, names: string[]) =>
        at(d.dest, names) || d.via.some((v) => at(v, names));
    const ours = dirs.filter((d) => passes(d, ahead) && !at(d.dest, away));
    const pick = ours.length ? ours : dirs.filter((d) => !passes(d, away));
    const soonest = pick
        .flatMap((d) =>
            [d.next, ...d.later]
                .filter((run): run is StationRun => !!run)
                .map((run) => ({ dir: d, run })),
        )
        .sort((a, b) => Date.parse(a.run.at) - Date.parse(b.run.at));

    // The next one that runs; else the next one, cancelled.
    return soonest.find((s) => !s.run.cancelled) ?? soonest[0] ?? null;
}

const badgeOf = (line: string) => {
    const look = lineLook(line, 'tram');

    return (
        <span
            key={line}
            className="nearby-badge"
            style={
                { '--line': look.colour, '--ink': look.ink } as CSSProperties
            }
        >
            {line}
        </span>
    );
};

function StopRow({
    stop,
    line,
    first,
    last,
    ahead,
    away,
    towards,
    board,
    open,
    now,
    onToggle,
    onOpenBoard,
}: {
    stop: RouteStop;
    line: string;
    first: boolean;
    last: boolean;
    /** The stops after it, this way. */
    ahead: string[];
    /** Where runs the other way head: the stops before it, and the other way's. */
    away: string[];
    towards: string;
    /** The station you're at: its own board, already on screen. */
    board: StopRuns | null;
    open: boolean;
    now: number;
    onToggle: () => void;
    onOpenBoard: (name: string) => void;
}) {
    const box = useRef<HTMLDivElement>(null);
    const [read, setRead] = useState<StopRuns | null | undefined>(
        board ?? undefined,
    );
    const known = board ?? read;

    // Its board, once the row comes near the screen.
    useEffect(() => {
        if (board || !stop.board || read !== undefined || !box.current) {
            return;
        }

        let gone = false;
        const seen = new IntersectionObserver(
            ([entry]) => {
                if (!entry.isIntersecting) {
                    return;
                }

                seen.disconnect();
                void stopRuns(stop.name, line).then(
                    (result) => !gone && setRead(result),
                );
            },
            { rootMargin: '240px 0px' },
        );
        seen.observe(box.current);

        return () => {
            gone = true;
            seen.disconnect();
        };
    }, [board, stop.board, stop.name, line, read]);

    const next = known && !last ? thisWay(known.dirs, ahead, away) : null;
    const live =
        !!next && next.run.time_basis === 'realtime' && !known?.is_stale;
    const mins = next ? minutesUntil(next.run.at, now) : 0;
    const elsewhere = next && !sameStop(next.dir.dest, towards);
    const later = next
        ? [...(next.dir.next ? [next.dir.next] : []), ...next.dir.later].filter(
              (run) => Date.parse(run.at) > Date.parse(next.run.at),
          )
        : [];
    const loading = !last && stop.board && known === undefined;

    let time: ReactNode = '—';

    if (loading) {
        time = <span className="route-time is-waiting">…</span>;
    } else if (next?.run.cancelled) {
        time = (
            <span className="route-time is-cancelled">
                <s>{next.run.time}</s>
            </span>
        );
    } else if (next) {
        time = (
            <span className={cn('route-time', !live && 'is-timetable')}>
                {mins === 0
                    ? 'now'
                    : mins >= 60
                      ? next.run.time
                      : `${mins} min`}
            </span>
        );
    }

    const sub = stop.here
        ? elsewhere
            ? `You’re here · the next one goes to ${next.dir.dest}`
            : 'You’re here'
        : last
          ? 'End of the line'
          : next?.run.cancelled
            ? 'Cancelled'
            : elsewhere
              ? `Next one goes to ${next.dir.dest}`
              : !loading && stop.board && !next
                ? 'Not on its board right now'
                : !stop.board
                  ? 'No board of its own'
                  : '';

    return (
        <div
            ref={box}
            className={cn(
                'route-stop',
                stop.here && 'is-here',
                open && 'is-open',
            )}
        >
            <button
                type="button"
                className="route-stop-head"
                aria-expanded={open}
                onClick={onToggle}
            >
                <span className="route-rail" aria-hidden="true">
                    {first ? null : <span className="route-seg is-top" />}
                    {last ? null : <span className="route-seg is-bottom" />}
                    <span
                        className={cn(
                            'route-dot',
                            (first || last) && 'is-end',
                            stop.here && 'is-here',
                        )}
                    />
                </span>
                <span className="route-name">
                    <span className="route-name-line">
                        <b>{stop.name}</b>
                        {stop.changes.map(badgeOf)}
                    </span>
                    {sub ? (
                        <small className={cn(stop.here && 'is-here')}>
                            {sub}
                        </small>
                    ) : null}
                </span>
                <span className="route-when">
                    {next && !next.run.cancelled && next.run.delay >= 1 ? (
                        <span
                            className={cn(
                                'route-late',
                                next.run.delay >= 10 && 'is-long',
                            )}
                        >
                            {next.run.delay} min late
                        </span>
                    ) : null}
                    {time}
                </span>
                <span className="route-chev" aria-hidden="true">
                    <TravelIcon name="down" />
                </span>
            </button>
            {open ? (
                <div className="route-more">
                    <span className="route-rail" aria-hidden="true">
                        {last ? null : <span className="route-seg is-full" />}
                    </span>
                    <div className="route-more-in">
                        {later.length ? (
                            <span className="route-then">
                                Then{' '}
                                {later.slice(0, 4).map((run, index) => (
                                    <span key={run.id}>
                                        {index ? ' · ' : ''}
                                        {run.cancelled ? (
                                            <span className="tl-cancelled">
                                                <s>{run.time}</s> cancelled
                                            </span>
                                        ) : (
                                            run.time
                                        )}
                                    </span>
                                ))}
                            </span>
                        ) : null}
                        {stop.changes.length ? (
                            <span className="route-change">
                                Change here to {stop.changes.map(badgeOf)}
                            </span>
                        ) : null}
                        {stop.board && !stop.here ? (
                            <button
                                type="button"
                                className="text-action"
                                onClick={() => onOpenBoard(stop.name)}
                            >
                                Open {stop.name}’s board →
                            </button>
                        ) : null}
                    </div>
                </div>
            ) : null}
        </div>
    );
}

export function LineRoute({
    route,
    station,
    look,
    onClear,
    onOpenBoard,
}: {
    route: LineRouteData;
    station: Station;
    look: LineLook;
    onClear: () => void;
    onOpenBoard: (name: string) => void;
}) {
    // The way the next tram here goes first.
    const [way, setWay] = useState(() => {
        const dirs = station.lines.find((l) => l.line === route.line)?.dirs;
        const soonest = (dirs ?? [])
            .filter((d) => d.next)
            .sort((a, b) => Date.parse(a.next!.at) - Date.parse(b.next!.at))[0];
        const index = soonest
            ? route.ways.findIndex((w) => sameStop(w.towards, soonest.dest))
            : -1;

        return Math.max(0, index);
    });
    const [open, setOpen] = useState<string | null>(station.name);
    // Minutes count down as the times stay on screen.
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 30_000);

        return () => window.clearInterval(timer);
    }, []);
    const sections = route.elsewhere
        .map((e) => `${e.from} – ${e.to}`)
        .join(', ');

    if (!route.ways.length) {
        return (
            <section
                className="mapview-picked line-route"
                aria-label={`Line ${route.line} today`}
            >
                <div className="mapview-pickbar">
                    <button
                        type="button"
                        className="mapview-allback"
                        onClick={onClear}
                    >
                        <TravelIcon name="back" />
                        <span>All lines</span>
                    </button>
                    {badgeOf(route.line)}
                    <span className="route-ends">
                        <strong>
                            {sections
                                ? `Not at ${station.name} today`
                                : `No ${route.mode === 'rail' ? 'trains' : 'trams'} today`}
                        </strong>
                        <small>
                            {sections
                                ? `Runs ${sections} today`
                                : 'The timetable has none on this line today'}
                        </small>
                    </span>
                </div>
            </section>
        );
    }

    const stops = route.ways[way]?.stops ?? [];
    const here = stops.findIndex((s) => s.here);
    const herePosition = here < 0 ? null : stops[here];
    const hereBoard: StopRuns = {
        name: station.name,
        time_basis: station.time_basis,
        is_stale: station.is_stale,
        dirs: station.lines.find((l) => l.line === route.line)?.dirs ?? [],
    };
    const [a, b] = route.ways;
    // Both ways run the same stops, turned round: one route, both ends.
    const mirrored =
        !!a &&
        !!b &&
        a.stops.length === b.stops.length &&
        a.stops.every((s, i) =>
            sameStop(s.name, b.stops[b.stops.length - 1 - i].name),
        );
    const ends = mirrored
        ? `${a.stops[0].name} ↔ ${a.towards}`
        : `${stops[0]?.name ?? ''} → ${route.ways[way]?.towards ?? ''}`;
    const minutes = route.ways[way]?.minutes ?? null;
    const long =
        minutes === null
            ? ''
            : minutes >= 50 && minutes <= 70
              ? ' · about an hour end to end'
              : minutes > 70
                ? ` · about ${Math.floor(minutes / 60)} h ${Math.round((minutes % 60) / 5) * 5} min end to end`
                : ` · about ${Math.round(minutes / 5) * 5} min end to end`;
    // Where a run the other way heads from a stop: back up this list, and
    // along the other way from that stop on, when the other way calls there.
    const other = route.ways[way ? 0 : 1]?.stops ?? [];
    const awayOf = (i: number) => {
        const at = other.findIndex((s) => sameStop(s.name, stops[i].name));

        return [
            ...stops.slice(0, i).map((s) => s.name),
            ...(at < 0 ? [] : other.slice(at + 1).map((s) => s.name)),
        ];
    };
    // A town's name where the route comes into it.
    const towns: (string | null)[] = [];

    for (let i = 0, town: string | null = null; i < stops.length; i++) {
        const into = stops[i].city;
        towns.push(into && into !== town ? into : null);
        town = into ?? town;
    }

    return (
        <section
            className="mapview-picked line-route"
            aria-label={`Line ${route.line}, its whole route`}
            style={{ '--route': look.colour } as CSSProperties}
        >
            <div className="mapview-pickbar">
                <button
                    type="button"
                    className="mapview-allback"
                    onClick={onClear}
                >
                    <TravelIcon name="back" />
                    <span>All lines</span>
                </button>
                {badgeOf(route.line)}
                <span className="route-ends">
                    <strong>{ends}</strong>
                    <small>
                        {stops.length} stops{long}
                    </small>
                </span>
                {route.ways.length > 1 ? (
                    <div
                        className="route-ways"
                        role="group"
                        aria-label="Direction"
                    >
                        {route.ways.map((w, i) => (
                            <button
                                key={w.towards}
                                type="button"
                                aria-pressed={i === way}
                                onClick={() => setWay(i)}
                            >
                                To {w.towards} ↓
                            </button>
                        ))}
                    </div>
                ) : null}
            </div>
            <div className="route-stops">
                {stops.map((stop, i) => {
                    const divider = towns[i];

                    return (
                        <div key={`${way}|${stop.name}|${i}`}>
                            {divider ? (
                                <div className="route-city">
                                    <span
                                        className="route-rail"
                                        aria-hidden="true"
                                    >
                                        {i ? (
                                            <span className="route-seg is-full" />
                                        ) : null}
                                    </span>
                                    <span>{divider}</span>
                                </div>
                            ) : null}
                            <StopRow
                                stop={stop}
                                line={route.line}
                                first={i === 0}
                                last={i === stops.length - 1}
                                ahead={stops.slice(i + 1).map((s) => s.name)}
                                away={awayOf(i)}
                                towards={route.ways[way].towards}
                                board={stop.here ? hereBoard : null}
                                open={open === stop.name}
                                now={now}
                                onToggle={() =>
                                    setOpen(
                                        open === stop.name ? null : stop.name,
                                    )
                                }
                                onOpenBoard={onOpenBoard}
                            />
                        </div>
                    );
                })}
            </div>
            {herePosition ? null : (
                <p className="mapview-none">
                    {station.name} isn’t on this way’s timetable route.
                </p>
            )}
            {sections ? (
                <p className="mapview-none">
                    Line {route.line} also runs {sections} today.
                </p>
            ) : null}
        </section>
    );
}
