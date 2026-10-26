import {
    Fragment,
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { CardStrip, TravelBanner, TravelChip } from '../labels';
import { LineBadge } from '../line-badge';
import { lineLook, vehicleWord } from '../line-look';
import { LineStrip, StripTime } from '../line-strip';
import { TravelIcon } from '../travel-icon';
import { filterDefaults, longWait, nearbyView, whenOf } from './nearby-model';
import type { NearbyFilters, ShownDir, ShownLine } from './nearby-model';
import { LiftLine, MajorHeadline, NoticeLine, rowNoticesOf } from './notices';
import { StationMap, useLinesMap } from './station-map';
import { StopPicker } from './stop-picker';
import { closestNow, closestWords } from './types';
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

/** The app's open easing (Composer's tray). */
const OPEN = { duration: 260, easing: 'cubic-bezier(.16,1,.3,1)' };

/**
 * A line's place in its area: its strip, or its detail when opened. Opening
 * unfolds the detail, at its own size and clipped, from the strip's height to
 * its own, while the strip over it fades; closing folds it back and the strip
 * fades in over it before taking its place (`opening` and `closing` keep the
 * strip on top until `onOpened` / `onClosed`). Nothing is squeezed to fit.
 * With reduced motion it just swaps. Its own updates (new times) don't animate.
 */
function LineSlot({
    open,
    opening,
    closing,
    strip,
    detail,
    onOpened,
    onClosed,
}: {
    open: boolean;
    opening: boolean;
    closing: boolean;
    strip: ReactNode;
    detail: ReactNode;
    onOpened: () => void;
    onClosed: () => void;
}) {
    const box = useRef<HTMLDivElement>(null);
    const ghost = useRef<HTMLDivElement>(null);
    const height = useRef(0);
    // The strip's height when it opened: where closing goes back to.
    const shut = useRef(0);
    const was = useRef(open);
    // The change under way and the height it goes to.
    const running = useRef<{ anim: Animation; to: number } | null>(null);

    // Before it paints: from the height it had to the one it goes to.
    useLayoutEffect(() => {
        const el = box.current;

        if (!el || was.current === open) {
            return;
        }

        was.current = open;
        const from = height.current;
        const to = open ? el.offsetHeight : shut.current;

        if (open) {
            shut.current = from;
        }

        running.current?.anim.cancel();

        if (
            !from ||
            !to ||
            from === to ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            (open ? onOpened : onClosed)();

            return;
        }

        el.style.overflow = 'hidden';
        el.style.alignItems = 'start';
        // Closing holds the last frame until the strip takes its place.
        const anim = el.animate(
            [{ height: `${from}px` }, { height: `${to}px` }],
            { ...OPEN, fill: open ? 'none' : 'forwards' },
        );
        ghost.current?.animate(
            open
                ? [{ opacity: 1 }, { opacity: 0 }]
                : [{ opacity: 0 }, { opacity: 1 }],
            {
                duration: 140,
                delay: open ? 0 : OPEN.duration - 140,
                easing: 'ease-out',
                fill: 'both',
            },
        );
        running.current = { anim, to };
        const settle = () => {
            el.style.overflow = '';
            el.style.alignItems = '';
        };
        anim.onfinish = () => {
            if (open) {
                settle();
                running.current = null;
                onOpened();
            } else {
                onClosed();
            }
        };
        anim.oncancel = () => {
            settle();

            if (running.current?.anim === anim) {
                running.current = null;
            }
        };
    }, [open, onOpened, onClosed]);

    // The strip is back: let go of the closing's last frame.
    useLayoutEffect(() => {
        if (!open && !closing) {
            running.current?.anim.cancel();
        }
    }, [open, closing]);

    // Its height after every render, for the next change to start from (the
    // one it's going to while a change is under way, not the frame now).
    useLayoutEffect(() => {
        height.current = running.current?.to ?? box.current?.offsetHeight ?? 0;
    });

    const shown = open || closing;

    return (
        <div ref={box} className={cn('nearby-line-slot', shown && 'is-open')}>
            {shown ? detail : strip}
            {(open && opening) || closing ? (
                <div
                    ref={ghost}
                    className="nearby-line-ghost"
                    aria-hidden="true"
                    inert
                >
                    {strip}
                </div>
            ) : null}
        </div>
    );
}

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
    // The line unfolding into its detail, and the one folding back to its
    // strip (the strip shown over it until it has).
    const [opening, setOpening] = useState('');
    const [closing, setClosing] = useState('');
    const setOpenLine = (line: string) => {
        setClosing(openLine && openLine !== line ? openLine : '');
        setOpening(line);
        setPicked(line);
    };
    const opened = useCallback(
        (line: string) => setOpening((now) => (now === line ? '' : now)),
        [],
    );
    const closed = useCallback(
        (line: string) => setClosing((now) => (now === line ? '' : now)),
        [],
    );
    const [pinned, setPinned] = useState<string[]>(readPins);
    const root = useRef<HTMLDivElement>(null);

    const v = useMemo(
        () => nearbyView(station, filters, { pinned, now, cancelled: true }),
        [station, filters, pinned, now],
    );
    // List | Map: the map once the timetable knows the station.
    const linesMap = useLinesMap(station);
    const [view, setView] = useState<'list' | 'map'>('list');
    const mapOpen = view === 'map' && !!linesMap;
    const closeMap = useCallback(() => setView('list'), []);
    // A line tapped under the map opens in the list, once the list is back
    // (opened while hidden, its unfolding had nothing to measure).
    const openFromMap = (line: string) => {
        closeMap();
        requestAnimationFrame(() => {
            setOpenLine(line);
            requestAnimationFrame(() =>
                root.current
                    ?.querySelector('.nearby-line-slot.is-open')
                    ?.scrollIntoView({ block: 'nearest' }),
            );
        });
    };
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

    const strip = (
        l: ShownLine,
        onOpen: (line: string) => void = setOpenLine,
    ) => {
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
                              {cancelTag(d)}
                          </>
                      ),
                  }
                : null;
        const kind =
            l.mode === 'bus' ? 'bus' : l.mode === 'rail' ? 'rail' : 'tram';
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
                            {/* Each way's next one, soonest first: "Then 00:56 · 00:59". */}
                            {`Then ${l.shown
                                .flatMap((d) =>
                                    d.later
                                        .filter((run) => !run.cancelled)
                                        .slice(0, 1),
                                )
                                .sort(
                                    (a, b) =>
                                        Date.parse(a.at) - Date.parse(b.at),
                                )
                                .map((run) => run.time)
                                .join(' · ')}`}
                        </CardStrip>
                    ) : undefined
                }
                aria-pressed={false}
                aria-label={label}
                onClick={() => onOpen(l.line)}
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
                                        d.cancelled
                                            ? 'is-cancelled'
                                            : (stale || !d.live) && 'is-paused',
                                    )}
                                >
                                    {d.cancelled ? (
                                        <strong>
                                            <s>{d.clock}</s>
                                        </strong>
                                    ) : (
                                        <>
                                            <strong>{t.big}</strong>
                                            {t.unit ? (
                                                <small>{t.unit}</small>
                                            ) : null}
                                        </>
                                    )}
                                </span>
                                {delayTag(d, true)}
                                {cancelTag(d)}
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
            <MajorHeadline station={station} />
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
                {linesMap ? (
                    <>
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
                                onClick={() => setView('map')}
                            >
                                <TravelIcon name="map" />
                            </button>
                        </div>
                        <button
                            type="button"
                            className="nearby-open-map"
                            aria-label="Open the map"
                            title="Map"
                            onClick={() => setView('map')}
                        >
                            <TravelIcon name="map" />
                        </button>
                    </>
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
                                            <LineSlot
                                                open={openLine === l.line}
                                                opening={opening === l.line}
                                                closing={closing === l.line}
                                                strip={strip(l)}
                                                detail={
                                                    openLine === l.line ||
                                                    closing === l.line
                                                        ? detail(l)
                                                        : null
                                                }
                                                onOpened={() => opened(l.line)}
                                                onClosed={() => closed(l.line)}
                                            />
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
                    renderLine={(l) => strip(l, openFromMap)}
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
