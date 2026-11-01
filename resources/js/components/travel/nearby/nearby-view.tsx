import {
    Fragment,
    Suspense,
    lazy,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { ComponentProps, CSSProperties } from 'react';
import { cn } from '@/lib/utils';
import { TravelBanner, TravelChip } from '../labels';
import { LineBadge } from '../line-badge';
import { lineLook, vehicleWord } from '../line-look';
import { LineRow } from '../line-row';
import { StripTime } from '../line-strip';
import { TravelIcon } from '../travel-icon';
import { useLinesMap } from './lines-map-data';
import { filterDefaults, longWait, nearbyView, whenOf } from './nearby-model';
import type { NearbyFilters, ShownDir, ShownLine } from './nearby-model';
import { LiftLine, MajorHeadline, NoticeLine, rowNoticesOf } from './notices';
import { StopPicker } from './stop-picker';
import { closestNow, closestWords } from './types';
import type { FavouriteStop, Station } from './types';
import '../../../../css/travel.css';
import '../../../../css/travel-page.css';

// The lines map and a line's route: their code loads when Map is first wanted.
const LazyStationMap = lazy(() =>
    import('./station-map').then((module) => ({ default: module.StationMap })),
);

function StationMap(props: ComponentProps<typeof LazyStationMap>) {
    return (
        <Suspense fallback={null}>
            <LazyStationMap {...props} />
        </Suspense>
    );
}

/**
 * Travel's Nearby (departures-next/nearby-ui.js): the station by boarding
 * area, a strip per line with where it goes both ways and when, a line
 * opened in place, filters that float under their chips, the stop picker
 * and ★. Times are labelled by where they come from: live, the timetable,
 * or the last ones we had.
 */

const PINS_KEY = 'expadu.travel.pinned-lines.v1';

function readPins(): string[] {
    try {
        const pins = JSON.parse(localStorage.getItem(PINS_KEY) ?? '[]');

        return Array.isArray(pins) ? pins.map(String) : [];
    } catch {
        return [];
    }
}

function writePins(pins: string[]) {
    try {
        localStorage.setItem(PINS_KEY, JSON.stringify(pins));
    } catch {
        // Pins last for this page if storage is unavailable.
    }
}

const toggleIn = <T,>(list: T[], value: T) =>
    list.includes(value) ? list.filter((v) => v !== value) : [...list, value];

/** A clock that ticks each 30 s and when the tab comes back. */
function useClock(): number {
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

const clockOf = (iso: string | null) =>
    iso
        ? new Intl.DateTimeFormat('en-GB', {
              hour: '2-digit',
              minute: '2-digit',
              timeZone: 'Europe/Berlin',
          }).format(new Date(iso))
        : '';

type Panel = '' | 'type' | 'lines' | 'via' | 'when';

type NearbyViewProps = {
    station: Station;
    favourites: FavouriteStop[];
    onPickStop: (name: string | null) => void;
    onToggleFavourite: (name: string, on: boolean) => void;
    favouriteBusy?: boolean;
    /** The person's saved step-free need: Step-free starts on. */
    stepFreeNeed?: boolean;
};

export function NearbyView({
    station,
    favourites,
    onPickStop,
    onToggleFavourite,
    favouriteBusy = false,
    stepFreeNeed = false,
}: NearbyViewProps) {
    const now = useClock();
    // Their own need starts Step-free on; Clear filters still clears it.
    const [filters, setFilters] = useState<NearbyFilters>(() => ({
        ...filterDefaults(),
        stepfree: stepFreeNeed,
    }));
    const [panel, setPanel] = useState<Panel>('');
    const [stopsOpen, setStopsOpen] = useState(false);
    const [picked, setPicked] = useState('');
    // A picked line that left the board closes.
    const openLine = station.lines.some((l) => l.line === picked) ? picked : '';
    const [pinned, setPinned] = useState<string[]>(readPins);
    const root = useRef<HTMLDivElement>(null);

    const v = useMemo(
        () => nearbyView(station, filters, { pinned, now, cancelled: true }),
        [station, filters, pinned, now],
    );
    // List | Map: the map's data and code are read once Map is wanted
    // (pointed at, focused or tapped), not with every board.
    const [view, setView] = useState<'list' | 'map'>('list');
    const [mapWanted, setMapWanted] = useState(false);
    const linesMap = useLinesMap(station, mapWanted || view === 'map');
    const mapOpen = view === 'map' && !!linesMap;
    const wantMap = useCallback(() => {
        setMapWanted(true);
        void import('./station-map');
    }, []);
    const closeMap = useCallback(() => setView('list'), []);
    // A line's whole route under the map: the screen's one headline is that
    // line's own (owner, 23 Oct 2026).
    const [routeLine, setRouteLine] = useState<string | null>(null);
    const headlined =
        routeLine && mapOpen
            ? {
                  ...station,
                  notices: station.notices.filter((n) =>
                      n.lines.includes(routeLine),
                  ),
                  lines: station.lines.filter((l) => l.line === routeLine),
              }
            : station;
    const board = useMemo(
        () =>
            nearbyView(station, filterDefaults(), {
                pinned,
                now,
                cancelled: true,
            }).areas.flatMap((area) => area.cards),
        [station, pinned, now],
    );
    const stale = station.is_stale || station.time_basis === 'stale';
    // Late at night: the next ones after these are a long way off.
    const wait = stale ? null : longWait(v);
    const waitWord =
        wait?.mode === 'bus'
            ? 'buses'
            : wait?.mode === 'tram'
              ? 'trams'
              : 'departures';
    const runs = station.lines.flatMap((l) =>
        l.dirs.flatMap((d) => (d.next ? [d.next] : [])),
    );
    const someLive = runs.some((r) => r.time_basis === 'realtime');
    const someTimetable = runs.some((r) => r.time_basis === 'scheduled');
    const favourite = favourites.some((f) => f.name === station.name);

    // Dropdowns float over the page; a tap outside or Escape closes them.
    useEffect(() => {
        if (!panel && !stopsOpen) {
            return;
        }

        const close = (event: Event) => {
            if (
                event instanceof KeyboardEvent
                    ? event.key === 'Escape'
                    : !root.current
                          ?.querySelector(
                              '.nearby-panel, .nearby-stops, .nearby-chips, .nearby-stop',
                          )
                          ?.contains(event.target as Node) &&
                      !(event.target as Element).closest?.(
                          '.nearby-panel, .nearby-stops, .nearby-chip, .nearby-stop',
                      )
            ) {
                setPanel('');
                setStopsOpen(false);
            }
        };

        document.addEventListener('pointerdown', close);
        document.addEventListener('keydown', close);

        return () => {
            document.removeEventListener('pointerdown', close);
            document.removeEventListener('keydown', close);
        };
    }, [panel, stopsOpen]);

    const lifts = station.lifts;
    const liftOut = lifts.known && lifts.out.length > 0;

    const timesLabel = stale ? (
        <TravelChip kind="unsure" icon={<TravelIcon name="offline" />}>
            Not live · last times {clockOf(station.observed_at)}
        </TravelChip>
    ) : station.time_basis === 'unavailable' ? (
        <TravelChip kind="unsure" icon={<TravelIcon name="offline" />}>
            Times unavailable right now
        </TravelChip>
    ) : someLive ? (
        <TravelChip kind="calm" icon={<TravelIcon name="clock" />}>
            {someTimetable ? 'Live · timetable times in grey' : 'Live times'}
        </TravelChip>
    ) : (
        <TravelChip kind="unsure" icon={<TravelIcon name="offline" />}>
            Scheduled · not live
        </TravelChip>
    );

    const chip = (
        key: string,
        label: string,
        active: boolean,
        caret: boolean,
        onClick: () => void,
    ) => (
        <button
            key={key}
            type="button"
            className="nearby-chip"
            aria-pressed={active}
            aria-expanded={caret ? panel === key : undefined}
            onClick={onClick}
        >
            <span>
                {label}
                {caret ? <TravelIcon name="down" /> : null}
            </span>
        </button>
    );

    const togglePanel = (key: Panel) => {
        setStopsOpen(false);
        setPanel(panel === key ? '' : key);
    };

    const chips = [
        v.modes.length > 1 &&
            chip(
                'type',
                filters.type === 'all'
                    ? 'All types'
                    : filters.type === 'bus'
                      ? 'Buses'
                      : 'Trams',
                filters.type !== 'all',
                true,
                () => togglePanel('type'),
            ),
        v.lineChoices.length > 1 &&
            chip(
                'lines',
                filters.lines.length
                    ? `Lines · ${filters.lines.length}`
                    : 'Lines',
                filters.lines.length > 0,
                true,
                () => togglePanel('lines'),
            ),
        v.viaChoices.length > 1 &&
            chip(
                'via',
                filters.via.length
                    ? `Via · ${filters.via.length}`
                    : 'Leaving via',
                filters.via.length > 0,
                true,
                () => togglePanel('via'),
            ),
        chip('when', `Next ${filters.win} min`, filters.win !== 30, true, () =>
            togglePanel('when'),
        ),
        chip('delayed', 'Delayed', filters.delayed, false, () =>
            setFilters({ ...filters, delayed: !filters.delayed }),
        ),
    ].filter(Boolean);
    const stepChip = chip(
        'stepfree',
        'Step-free',
        filters.stepfree,
        false,
        () => setFilters({ ...filters, stepfree: !filters.stepfree }),
    );
    // When a lift is out, Step-free leads the row so it's seen.
    const chipRow = liftOut ? [stepChip, ...chips] : [...chips, stepChip];

    const option = (
        key: string,
        label: string,
        on: boolean,
        onClick: () => void,
        badges?: React.ReactNode,
    ) => (
        <button
            key={key}
            type="button"
            className="nearby-option"
            aria-pressed={on}
            onClick={onClick}
        >
            {badges}
            <strong>{label}</strong>
        </button>
    );

    const panelBody = (() => {
        switch (panel) {
            case 'type':
                return {
                    title: 'Type',
                    hint: 'Trams and buses at this station',
                    body: (
                        [
                            ['all', 'All types'],
                            ['tram', 'Trams'],
                            ['bus', 'Buses'],
                        ] as const
                    ).map(([id, label]) =>
                        option(id, label, filters.type === id, () =>
                            setFilters({ ...filters, type: id }),
                        ),
                    ),
                };
            case 'lines':
                return {
                    title: 'Lines',
                    hint: 'Pick one or more',
                    body: v.lineChoices.map((l) =>
                        option(
                            l.line,
                            vehicleWord(
                                l.mode === 'bus'
                                    ? 'bus'
                                    : l.mode === 'rail'
                                      ? 'rail'
                                      : 'tram',
                            ),
                            filters.lines.includes(l.line),
                            () =>
                                setFilters({
                                    ...filters,
                                    lines: toggleIn(filters.lines, l.line),
                                }),
                            <span
                                className={cn(
                                    'nearby-badge',
                                    l.look.bus && 'is-bus',
                                )}
                                style={
                                    {
                                        '--line': l.look.colour,
                                        '--ink': l.look.ink,
                                    } as CSSProperties
                                }
                            >
                                {l.line}
                            </span>,
                        ),
                    ),
                };
            case 'via':
                return {
                    title: 'Leaving via',
                    hint: `The first stop after ${station.name}`,
                    body: v.viaChoices.map((c) =>
                        option(
                            c.name,
                            c.name,
                            filters.via.includes(c.name),
                            () =>
                                setFilters({
                                    ...filters,
                                    via: toggleIn(filters.via, c.name),
                                }),
                            <span className="nearby-badges">
                                {c.lines.map((line) => {
                                    const known = v.lineChoices.find(
                                        (l) => l.line === line,
                                    );

                                    return (
                                        <span
                                            key={line}
                                            className={cn(
                                                'nearby-badge',
                                                known?.look.bus && 'is-bus',
                                            )}
                                            style={
                                                {
                                                    '--line':
                                                        known?.look.colour,
                                                    '--ink': known?.look.ink,
                                                } as CSSProperties
                                            }
                                        >
                                            {line}
                                        </span>
                                    );
                                })}
                            </span>,
                        ),
                    ),
                };
            case 'when':
                return {
                    title: 'Leaving in',
                    hint: 'Minutes from now',
                    body: ([10, 30, 60] as const).map((w) =>
                        option(
                            String(w),
                            `Next ${w} min`,
                            filters.win === w,
                            () => setFilters({ ...filters, win: w }),
                        ),
                    ),
                };
            default:
                return null;
        }
    })();

    const delayTag = (dir: ShownDir) => {
        if (stale || dir.cancelled || !dir.delayMin) {
            return null;
        }

        // Everyday lateness stays small; 10+ min in the stronger yellow.
        return (
            <TravelChip
                kind="attention"
                size="sm"
                className={cn('nearby-late', dir.delayMin >= 10 && 'is-strong')}
            >
                {`${dir.delayMin}\u00a0min late`}
            </TravelChip>
        );
    };

    // Paused times are the last ones read: their clocks. A timetable time
    // leaving now shows its clock, quietly: "Now" is for a live departure.
    // Only what the feed says: its cancelled runs, by their timetabled
    // clock, struck through, then "Cancelled" in red (no pill).
    const cancelTag = (dir: ShownDir) =>
        dir.cancelledClocks.length ? (
            <span className="tl-cancelled">
                {dir.cancelled ? null : (
                    <>
                        <s>{dir.cancelledClocks.join(' · ')}</s>{' '}
                    </>
                )}
                Cancelled
            </span>
        ) : null;

    // Each line row's one minor notice (the major ones are the headline's),
    // on the first of its lines' rows; a run's own matched notice first.
    const rowNotices = rowNoticesOf(
        station,
        v.areas.flatMap((area) =>
            area.cards.map((l) => ({
                line: l.line,
                notices: l.shown.flatMap((d) =>
                    [d.next, ...d.later].map((run) => run?.notice),
                ),
            })),
        ),
    );

    const time = (dir: ShownDir) => {
        if (dir.cancelled) {
            return (
                <span className="nearby-time is-cancelled">
                    <strong>
                        <s>{dir.clock}</s>
                    </strong>
                </span>
            );
        }

        const paused = stale || !dir.live;
        const when = whenOf(dir);

        return (
            <StripTime
                when={
                    stale || (paused && when.big === 'now')
                        ? { big: dir.clock, unit: '' }
                        : when
                }
                paused={paused}
            />
        );
    };

    // A long wait: each way's next one after, under its own time (the
    // departure rows canvas: a band per way, not one bar across the card).
    const peek = (dir: ShownDir) => {
        const next = wait ? dir.later.find((run) => !run.cancelled) : null;

        return next ? (
            <span className="nearby-peek">
                <TravelIcon name="moon" />
                Then {next.time}
            </span>
        ) : null;
    };

    // The runs after the next one, a cancelled one marked; in a long wait
    // the one under the time is said already, so "After that".
    const thenLine = (dir: ShownDir) => {
        const runs = wait
            ? dir.later.filter(
                  (run) => run !== dir.later.find((r) => !r.cancelled),
              )
            : dir.later;

        return runs.length ? (
            <span className="line-row-then">
                {wait ? 'After that' : 'Then'}{' '}
                {runs.map((run, index) => (
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
        ) : null;
    };

    const row = (l: ShownLine) => {
        const [first, second, ...also] = l.shown;
        const kind =
            l.mode === 'bus' ? 'bus' : l.mode === 'rail' ? 'rail' : 'tram';
        const end = (d: ShownDir | undefined) =>
            d
                ? {
                      dest: d.dest,
                      via: d.via,
                      when: (
                          <>
                              {time(d)}
                              {delayTag(d)}
                              {cancelTag(d)}
                              {peek(d)}
                          </>
                      ),
                  }
                : null;
        const label = `${vehicleWord(kind)} ${l.line}: ${l.shown
            .map((d) => {
                const t = whenOf(d);

                if (d.cancelled) {
                    return `${d.dest} ${d.clock} cancelled`;
                }

                const gone = d.cancelledClocks.length
                    ? ` (${d.cancelledClocks.join(', ')} cancelled)`
                    : '';

                return `${d.dest} ${t.big}${t.unit ? ` ${t.unit}` : ''}${d.live ? '' : ' (timetable)'}${gone}`;
            })
            .join(', ')}`;
        // What opens under a way: its next one (only when stacked), the
        // runs after, and what the front of the tram says.
        const way = (d: ShownDir | undefined) => {
            if (!d) {
                return null;
            }

            const t = whenOf(d);
            // Said as the strip says it: a paused board's clock, else minutes.
            const shown =
                stale || (!d.live && t.big === 'now')
                    ? d.clock
                    : `${t.big}${t.unit ? ` ${t.unit}` : ''}`;

            return (
                <>
                    <strong className="line-row-lead">
                        → {d.dest}{' '}
                        <span>
                            {d.cancelled ? `${d.clock} cancelled` : shown}
                        </span>
                    </strong>
                    {thenLine(d)}
                    <span className="line-row-front">
                        On the front{' '}
                        <LineBadge line={l.line} look={l.look} kind={kind} />
                        <strong>{d.dest}</strong>
                    </span>
                </>
            );
        };
        const pin = pinned.includes(l.line);
        const towards = first?.dest ?? l.dirs[0]?.dest ?? '';

        return (
            <LineRow
                look={l.look}
                badge={
                    <>
                        <LineBadge line={l.line} look={l.look} kind={kind} />
                        {l.pinned ? (
                            <span className="nearby-pinned" aria-label="Pinned">
                                <TravelIcon name="star" />
                            </span>
                        ) : null}
                    </>
                }
                first={end(first)}
                second={end(second)}
                empty={`Other way: ${l.dirs.length > 1 ? 'filtered out' : 'not in the feed'}`}
                label={label}
                open={openLine === l.line}
                onToggle={() => setPicked(openLine === l.line ? '' : l.line)}
                more={{
                    first: (
                        <>
                            {way(first)}
                            {/* A third way (18 to Bonn Hbf): with the first. */}
                            {also.map((d) => (
                                <span key={d.id} className="line-row-also">
                                    <span>
                                        Also <strong>{d.dest} →</strong>{' '}
                                        <strong className="line-row-clock">
                                            {d.cancelled
                                                ? `${d.clock} cancelled`
                                                : d.clock}
                                        </strong>
                                        {d.via.length
                                            ? `, via ${d.via.join(' · ')}`
                                            : ''}
                                    </span>
                                    {thenLine(d)}
                                </span>
                            ))}
                        </>
                    ),
                    second: way(second),
                    foot: (
                        <>
                            <button
                                type="button"
                                className="nearby-pin"
                                aria-pressed={pin}
                                onClick={() => {
                                    const next = toggleIn(pinned, l.line);
                                    setPinned(next);
                                    writePins(next);
                                }}
                            >
                                <TravelIcon name="star" />
                                {pin ? 'Pinned' : `Pin line ${l.line}`}
                            </button>
                            {towards ? (
                                <small>
                                    “<span lang="de">Richtung</span> {towards}”
                                    on the front means towards {towards}.
                                </small>
                            ) : null}
                            {l.shown.some((d) => !d.later_recorded) ? (
                                <small>
                                    Later departures aren’t in the feed yet.
                                </small>
                            ) : null}
                        </>
                    ),
                }}
            />
        );
    };

    // KVB's own boarding areas; a line KVB doesn't list isn't one of them.
    const areaCount = station.areas.filter((a) => a.places.length).length;
    const areasText = `${areaCount} boarding area${areaCount === 1 ? '' : 's'}`;
    // Near where you last were, the label says that (and when) on its one line.
    const how = closestWords(station);
    const placesKind = !how
        ? areasText
        : closestNow(station)
          ? `${how} · ${areasText}`
          : how;

    const stepNote = !filters.stepfree
        ? null
        : !lifts.known
          ? 'Lift status isn’t known right now, so Step-free hides nothing yet.'
          : liftOut
            ? `Out of order here: ${lifts.out.map((lift) => lift.label).join('; ')}. Which platforms they serve isn’t recorded, so nothing is hidden.`
            : 'All lifts here are working.';

    return (
        <div className="nearby" data-view={mapOpen ? 'map' : 'list'} ref={root}>
            <MajorHeadline station={headlined} />
            {station.unknown_stop ? (
                <div className="nearby-banner is-labelled">
                    <TravelBanner
                        kind="unsure"
                        icon={<TravelIcon name="pin" />}
                        title={`“${station.unknown_stop}” isn’t a KVB stop`}
                    >
                        Showing the closest station instead.
                    </TravelBanner>
                </div>
            ) : wait ? (
                <div className="nearby-banner is-labelled">
                    <TravelBanner
                        kind="attention"
                        icon={<TravelIcon name="moon" />}
                        title={`Last ${waitWord} for a while`}
                    >
                        {`After these, the next ${waitWord} come ${wait.low === wait.high ? wait.low : `${wait.low}–${wait.high}`}\u00a0min later.`}
                    </TravelBanner>
                </div>
            ) : null}
            <div className="nearby-bar">
                {/* Only the name and its chevron change the stop: the row
                    beside it and the labels under it do nothing. */}
                <div className="nearby-stop">
                    <button
                        type="button"
                        className="nearby-stop-name"
                        aria-expanded={stopsOpen}
                        aria-label={`Change stop, now ${station.name}`}
                        onClick={() => {
                            setPanel('');
                            setStopsOpen(!stopsOpen);
                        }}
                    >
                        {station.name}
                        <TravelIcon name="down" />
                    </button>
                    <span className="tl-chips">
                        <TravelChip
                            kind="calm"
                            icon={<TravelIcon name="pin" />}
                        >
                            {placesKind}
                        </TravelChip>
                        {timesLabel}
                        {liftOut ? (
                            <TravelChip
                                kind="conflict"
                                icon={<TravelIcon name="lift" />}
                            >
                                Lift out of order
                            </TravelChip>
                        ) : null}
                    </span>
                </div>
                <button
                    type="button"
                    className="nearby-star"
                    aria-pressed={favourite}
                    aria-label={
                        favourite
                            ? `${station.name} is in your favourites`
                            : `Add ${station.name} to favourites`
                    }
                    title={
                        favourite ? 'In your favourites' : 'Add to favourites'
                    }
                    disabled={favouriteBusy}
                    onClick={() => onToggleFavourite(station.name, !favourite)}
                >
                    <TravelIcon name="star" />
                </button>
                {linesMap !== null &&
                station.lat !== null &&
                station.lng !== null ? (
                    <div
                        className="nearby-views"
                        role="group"
                        aria-label="Show as"
                    >
                        <button
                            type="button"
                            aria-pressed={!mapOpen}
                            aria-label="List"
                            onClick={closeMap}
                        >
                            <TravelIcon name="list" />
                        </button>
                        <button
                            type="button"
                            aria-pressed={mapOpen}
                            aria-label="Map"
                            onPointerEnter={wantMap}
                            onFocus={wantMap}
                            onClick={() => {
                                wantMap();
                                setView('map');
                            }}
                        >
                            <TravelIcon name="map" />
                        </button>
                    </div>
                ) : null}
            </div>
            <div className="nearby-stops" hidden={!stopsOpen}>
                {stopsOpen ? (
                    <StopPicker
                        current={station}
                        favourites={favourites}
                        onPick={(name) => {
                            setStopsOpen(false);
                            onPickStop(name);
                        }}
                    />
                ) : null}
            </div>
            <div className="nearby-chips" role="group" aria-label="Filters">
                {chipRow}
            </div>
            <div className="nearby-panel" hidden={!panelBody}>
                {panelBody ? (
                    <>
                        <div className="nearby-panel-head">
                            <span>
                                <strong>{panelBody.title}</strong>
                                <small>{panelBody.hint}</small>
                            </span>
                            <button
                                type="button"
                                className="quiet-button"
                                onClick={() => setPanel('')}
                            >
                                Done
                            </button>
                        </div>
                        <div className="nearby-options">{panelBody.body}</div>
                    </>
                ) : null}
            </div>
            <p className="nearby-stepnote" hidden={!stepNote}>
                {stepNote}
            </p>
            <div className="nearby-count">
                <span role="status">
                    {v.shown === v.total
                        ? `All ${v.total} departures · soonest first`
                        : `Showing ${v.shown} of ${v.total} departures`}
                </span>
                {v.active ? (
                    <button
                        type="button"
                        className="text-action"
                        onClick={() => setFilters(filterDefaults())}
                    >
                        Clear filters
                    </button>
                ) : null}
            </div>
            <LiftLine station={station} />
            <div className="nearby-list">
                {v.areas.length ? (
                    v.areas.map((area, index) => (
                        <div key={area.id}>
                            {index ? (
                                <p className="nearby-change">
                                    <TravelIcon name="route" />
                                    <span>
                                        Change here to {area.lines.join(' · ')}.
                                        Their boarding points differ; walking
                                        times aren’t recorded.
                                    </span>
                                </p>
                            ) : null}
                            <section
                                className="nearby-area"
                                aria-label={`Area for lines ${area.lines.join(', ')}`}
                            >
                                <div className="nearby-area-head">
                                    <span className="nearby-badges">
                                        {area.lines.map((line) => {
                                            const look =
                                                v.lineChoices.find(
                                                    (l) => l.line === line,
                                                )?.look ??
                                                lineLook(
                                                    line,
                                                    area.mode === 'bus'
                                                        ? 'bus'
                                                        : 'tram',
                                                );

                                            return (
                                                <span
                                                    key={line}
                                                    className={cn(
                                                        'nearby-badge',
                                                        look.bus && 'is-bus',
                                                    )}
                                                    style={
                                                        {
                                                            '--line':
                                                                look.colour,
                                                            '--ink': look.ink,
                                                        } as CSSProperties
                                                    }
                                                >
                                                    {line}
                                                </span>
                                            );
                                        })}
                                    </span>
                                    {area.platform ? (
                                        <TravelChip
                                            kind="calm"
                                            icon={<TravelIcon name="pin" />}
                                        >
                                            {area.platform}
                                        </TravelChip>
                                    ) : (
                                        <TravelChip
                                            kind="unsure"
                                            icon={<TravelIcon name="pin" />}
                                        >
                                            Platform not known
                                        </TravelChip>
                                    )}
                                </div>
                                <div className="nearby-cards">
                                    {area.cards.map((l) => (
                                        <Fragment key={l.line}>
                                            {row(l)}
                                            {rowNotices.has(l.line) ? (
                                                <NoticeLine
                                                    notice={
                                                        rowNotices.get(l.line)!
                                                    }
                                                />
                                            ) : null}
                                        </Fragment>
                                    ))}
                                </div>
                            </section>
                        </div>
                    ))
                ) : (
                    <div className="nearby-empty">
                        <strong>
                            {v.total
                                ? 'Nothing matches these filters'
                                : `No departures from ${station.name} in the feed`}
                        </strong>
                        <span>
                            {v.total
                                ? 'A missing line may still run later.'
                                : 'The feed has nothing for the next two hours.'}
                        </span>
                        {v.active ? (
                            <button
                                type="button"
                                className="quiet-button"
                                onClick={() => setFilters(filterDefaults())}
                            >
                                Clear filters
                            </button>
                        ) : null}
                    </div>
                )}
            </div>
            {linesMap ? (
                <StationMap
                    key={station.name}
                    station={station}
                    data={linesMap}
                    open={mapOpen}
                    onClose={closeMap}
                    board={board}
                    renderLine={row}
                    onOpenBoard={(name) => {
                        closeMap();
                        onPickStop(name);
                    }}
                    onRouteLine={setRouteLine}
                />
            ) : null}
            {station.credits?.map((credit) => (
                <p key={credit.name} className="nearby-credit">
                    {credit.for}:{' '}
                    <a href={credit.url} target="_blank" rel="noreferrer">
                        {credit.name}
                    </a>
                    ,{' '}
                    <a
                        href={credit.licence_url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        {credit.licence}
                    </a>
                </p>
            ))}
        </div>
    );
}
