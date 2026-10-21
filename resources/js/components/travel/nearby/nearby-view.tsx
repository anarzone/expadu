import { useEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import { cn } from '@/lib/utils';
import { CardStrip, TravelBanner, TravelChip } from '../labels';
import { LineBadge } from '../line-badge';
import { lineLook, vehicleWord } from '../line-look';
import { LineStrip, StripTime } from '../line-strip';
import { TravelIcon } from '../travel-icon';
import { filterDefaults, longWait, nearbyView, whenOf } from './nearby-model';
import type { NearbyFilters, ShownDir, ShownLine } from './nearby-model';
import { StopPicker } from './stop-picker';
import { noticeWords } from './types';
import type { FavouriteStop, Station } from './types';
import '../../../../css/travel.css';
import '../../../../css/travel-page.css';

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
    const [picked, setOpenLine] = useState('');
    // A picked line that left the board closes.
    const openLine = station.lines.some((l) => l.line === picked) ? picked : '';
    const [pinned, setPinned] = useState<string[]>(readPins);
    const root = useRef<HTMLDivElement>(null);

    const v = useMemo(
        () => nearbyView(station, filters, { pinned, now }),
        [station, filters, pinned, now],
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
    // Paused: how long since the last reading, in whole clock minutes (it
    // says "from 22:19", so 22:25 is 6 minutes).
    const pausedFor = station.observed_at
        ? Math.floor(now / 60_000) -
          Math.floor(Date.parse(station.observed_at) / 60_000)
        : 0;
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

    const delayTag = (dir: ShownDir, long = false) => {
        // A long wait: the open line says when the next one after comes.
        if (wait) {
            return long && dir.laterClocks.length ? (
                <TravelChip
                    kind="attention"
                    icon={<TravelIcon name="moon" />}
                    size="sm"
                >
                    {`Then ${dir.laterClocks[0]}`}
                </TravelChip>
            ) : null;
        }

        if (stale || !dir.delayMin) {
            return null;
        }

        const now = whenOf(dir).big === 'now';
        const text = `${now && !long ? 'Running ' : ''}${dir.delayMin}\u00a0min late`;

        return (
            <TravelChip
                kind="attention"
                icon={<TravelIcon name="clock" />}
                size="sm"
            >
                {text}
            </TravelChip>
        );
    };

    // Paused times are the last ones read: their clocks. A timetable time
    // leaving now shows its clock, quietly: "Now" is for a live departure.
    const time = (dir: ShownDir) => {
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

    const card = (l: ShownLine) => {
        if (openLine === l.line) {
            return detail(l);
        }

        const [first, second] = l.shown;
        const end = (d: ShownDir | undefined) =>
            d
                ? {
                      dest: d.dest,
                      via: d.via,
                      when: (
                          <>
                              {time(d)}
                              {delayTag(d)}
                          </>
                      ),
                  }
                : null;
        const kind =
            l.mode === 'bus' ? 'bus' : l.mode === 'rail' ? 'rail' : 'tram';
        const label = `${vehicleWord(kind)} ${l.line}: ${l.shown
            .map((d) => {
                const t = whenOf(d);

                return `${d.dest} ${t.big}${t.unit ? ` ${t.unit}` : ''}${d.live ? '' : ' (timetable)'}`;
            })
            .join(', ')}`;

        return (
            <LineStrip
                key={l.line}
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
                extra={
                    // A long wait is about the whole card: along its foot.
                    wait && l.shown.some((d) => d.laterClocks.length) ? (
                        <CardStrip
                            kind="attention"
                            icon={<TravelIcon name="moon" />}
                        >
                            {`Then ${l.shown
                                .flatMap((d) => d.laterClocks.slice(0, 1))
                                .join(' · ')}`}
                        </CardStrip>
                    ) : undefined
                }
                aria-pressed={false}
                aria-label={label}
                onClick={() => setOpenLine(l.line)}
            />
        );
    };

    const boardsWith = (line: string) => {
        const area = station.areas.find((a) => a.lines.includes(line));
        const rest = area ? area.lines.filter((x) => x !== line) : [];

        return rest.length
            ? `${station.name} · boards with ${rest.join(', ')}`
            : station.name;
    };

    const detail = (l: ShownLine) => {
        const kind =
            l.mode === 'bus' ? 'bus' : l.mode === 'rail' ? 'rail' : 'tram';
        const pin = pinned.includes(l.line);
        const first = l.dirs[0]?.dest ?? '';
        const noLater = l.shown.some((d) => !d.later_recorded);

        return (
            <section
                key={l.line}
                className="nearby-line is-inline"
                aria-label={`${vehicleWord(kind)} ${l.line}`}
                style={
                    {
                        '--line': l.look.colour,
                        '--ink': l.look.ink,
                        '--tint': l.look.tint,
                    } as CSSProperties
                }
            >
                <div className="nearby-line-head">
                    <LineBadge
                        line={l.line}
                        look={l.look}
                        kind={kind}
                        size="big"
                    />
                    <span>
                        <strong>
                            {vehicleWord(kind)} {l.line}
                        </strong>
                        <small>{boardsWith(l.line)}</small>
                    </span>
                    <button
                        type="button"
                        className="nearby-line-close"
                        aria-label={`Close line ${l.line}`}
                        onClick={() => setOpenLine('')}
                    >
                        <TravelIcon name="close" />
                    </button>
                </div>
                {l.shown.map((d) => {
                    const t = whenOf(d);

                    return (
                        <div key={d.id} className="nearby-detail-dir">
                            <span>
                                <strong>→ {d.dest}</strong>
                                {d.via.length ? (
                                    <small>via {d.via.join(', ')}</small>
                                ) : null}
                                {d.laterClocks.length ? (
                                    <small>
                                        Then {d.laterClocks.join(' · ')}
                                    </small>
                                ) : null}
                            </span>
                            <span className="nearby-detail-time">
                                <span
                                    className={cn(
                                        'nearby-time is-large',
                                        (stale || !d.live) && 'is-paused',
                                    )}
                                >
                                    <strong>{t.big}</strong>
                                    {t.unit ? <small>{t.unit}</small> : null}
                                </span>
                                {delayTag(d, true)}
                            </span>
                        </div>
                    );
                })}
                {noLater ? (
                    <p className="nearby-later">
                        Later departures aren’t in the feed yet.
                    </p>
                ) : null}
                <p className="nearby-front">
                    <small>
                        {kind === 'bus'
                            ? 'On the front of the bus'
                            : 'On the front of the tram'}
                    </small>
                    <strong>
                        {l.line} {first}
                    </strong>{' '}
                    · <span lang="de">Richtung</span> {first} means “towards{' '}
                    {first}”.
                </p>
                <div className="nearby-line-actions">
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
                </div>
            </section>
        );
    };

    // KVB's own boarding areas; a line KVB doesn't list isn't one of them.
    const areaCount = station.areas.filter((a) => a.places.length).length;
    const areasText = `${areaCount} boarding area${areaCount === 1 ? '' : 's'}`;
    const placesKind =
        station.kind === 'closest' ? `Closest · ${areasText}` : areasText;

    const stepNote = !filters.stepfree
        ? null
        : !lifts.known
          ? 'Lift status isn’t known right now, so Step-free hides nothing yet.'
          : liftOut
            ? `Out of order here: ${lifts.out.map((lift) => lift.label).join('; ')}. Which platforms they serve isn’t recorded, so nothing is hidden.`
            : 'All lifts here are working.';

    return (
        <div className="nearby" data-view="list" ref={root}>
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
            ) : stale ? (
                <div className="nearby-banner is-labelled">
                    <TravelBanner
                        kind="unsure"
                        icon={<TravelIcon name="offline" />}
                        title="Live times paused"
                    >
                        {pausedFor >= 1
                            ? `No update for ${pausedFor} minute${pausedFor === 1 ? '' : 's'}, so these are the last times we had, from ${clockOf(station.observed_at)}.`
                            : `These are the last times we had, from ${clockOf(station.observed_at)}.`}
                    </TravelBanner>
                </div>
            ) : liftOut ? (
                <div className="nearby-banner is-labelled">
                    <TravelBanner
                        kind="conflict"
                        icon={<TravelIcon name="lift" />}
                        title={`A lift at ${station.name} is out of order`}
                    >
                        {lifts.out.map((lift) => lift.label).join('; ')}
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
            {station.notices.slice(0, 2).map((notice) => {
                const { headline, text } = noticeWords(notice);
                // Every notice is KVB's; its feed's own name ("kvb-betriebslage") isn't one to show.
                const lines = `Line${notice.lines.length === 1 ? '' : 's'} ${notice.lines.join(', ')}${notice.source?.startsWith('kvb') ? ' · KVB' : ''}`;

                return (
                    <div key={notice.id} className="nearby-banner is-labelled">
                        <TravelBanner
                            kind={
                                notice.severity === 'critical' ||
                                notice.severity === 'major'
                                    ? 'conflict'
                                    : 'attention'
                            }
                            icon={<TravelIcon name="alert" />}
                            title={headline}
                        >
                            {text ? `${text} · ${lines}` : lines}
                        </TravelBanner>
                    </div>
                );
            })}
            <div className="nearby-bar">
                <button
                    type="button"
                    className="nearby-stop"
                    aria-expanded={stopsOpen}
                    aria-label={`Change stop, now ${station.name}`}
                    onClick={() => {
                        setPanel('');
                        setStopsOpen(!stopsOpen);
                    }}
                >
                    <span className="nearby-stop-name">
                        {station.name}
                        <TravelIcon name="down" />
                    </span>
                    <span className="tl-chips">
                        <TravelChip
                            kind="calm"
                            icon={<TravelIcon name="pin" />}
                        >
                            {placesKind}
                        </TravelChip>
                        {timesLabel}
                    </span>
                </button>
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
                                    {area.cards.map(card)}
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
        </div>
    );
}
