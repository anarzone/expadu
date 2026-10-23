import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { pushStep } from '@/lib/history-steps';
import { cn } from '@/lib/utils';
import { TravelChip } from '../labels';
import { lineLook, vehicleOf, vehicleWord } from '../line-look';
import { TravelIcon } from '../travel-icon';
import { layoutLinesMap, ROW_GAP, textWidth } from './lines-map-layout';
import type { LinesMapData } from './lines-map-layout';
import type { ShownLine } from './nearby-model';
import type { Station } from './types';
import './station-map.css';

/**
 * Nearby's Map (departures-next nearby-ui.js renderMap, transit-map.js):
 * "Lines and stops", the station's lines and their next stops drawn as a
 * transit diagram, not a street map. It takes the list's place and ✕ brings
 * the list back. The map stays on screen: a line chip or a group lights its
 * lines and lists the board's own rows under it; "← All lines", the All
 * lines chip or the group again clears the pick and goes back up to the map
 * (owner's pick, 23 Oct 2026: Departures canvas, Map-desk and Map-phone).
 * On phones the map pans sideways and the pick bar stays on top.
 */

/** The station's lines map, once the timetable knows the station. */
export function useLinesMap(
    station: Pick<Station, 'name' | 'lat' | 'lng'>,
): LinesMapData | null {
    const [map, setMap] = useState<{
        key: string;
        data: LinesMapData | null;
    } | null>(null);
    const key =
        station.lat !== null && station.lng !== null
            ? `${station.name}|${station.lat}|${station.lng}`
            : '';

    useEffect(() => {
        if (!key || station.lat === null || station.lng === null) {
            return;
        }

        const controller = new AbortController();
        const query = new URLSearchParams({
            stop: station.name,
            lat: String(station.lat),
            lng: String(station.lng),
        });

        fetch(`/api/travel/lines-map?${query}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((body: { map: LinesMapData | null } | null) =>
                setMap({ key, data: body?.map ?? null }),
            )
            .catch(() => {
                // Offline or aborted: no map, and no switch to it.
            });

        return () => controller.abort();
    }, [key, station.name, station.lat, station.lng]);

    const data = map?.key === key ? map.data : null;

    return data && (data.lines.length || data.buses.length) ? data : null;
}

const halo = {
    paintOrder: 'stroke',
    stroke: '#fbf6ec',
    strokeLinejoin: 'round',
} as const;

function LinesDiagram({
    data,
    lit,
    onPick,
}: {
    data: LinesMapData;
    lit: string[] | null;
    onPick: (line: string) => void;
}) {
    const map = useMemo(() => layoutLinesMap(data), [data]);
    const colour = (line: string) => {
        const mode = data.lines.find((l) => l.line === line)?.mode ?? 'tram';

        return lineLook(line, mode).colour;
    };
    const on = (lines: string[]) =>
        !lit || lines.some((line) => lit.includes(line));
    const d = (points: [number, number][]) =>
        `M${points.map(([x, y]) => `${Math.round(x)} ${Math.round(y)}`).join(' L')}`;
    const [x, y, w, h] = map.viewBox;
    const order = [...map.paths].sort(
        (a, b) => Number(on([a.line])) - Number(on([b.line])),
    );

    return (
        <svg
            className="tmap-diagram"
            viewBox={`${x} ${y} ${w} ${h}`}
            width={Math.round(w)}
            style={{ '--w': Math.round(w) } as CSSProperties}
            height={Math.round(h)}
            data-cx={-x / w}
            role="img"
            aria-label={`Lines and stops at ${data.station.name}`}
        >
            {order.map((p, i) => (
                <path
                    key={`casing-${p.line}-${i}`}
                    d={d(p.points)}
                    fill="none"
                    stroke="#fffdf8"
                    strokeWidth={10}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
            ))}
            {order.map((p, i) => (
                <path
                    key={`line-${p.line}-${i}`}
                    data-line={p.line}
                    d={d(p.points)}
                    fill="none"
                    stroke={colour(p.line)}
                    strokeWidth={6}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    opacity={on([p.line]) ? 1 : 0.16}
                    onClick={() => onPick(p.line)}
                />
            ))}
            {map.stations.map((s, i) => (
                <rect
                    key={`stop-${i}`}
                    x={-7}
                    y={-s.length / 2}
                    width={14}
                    height={s.length}
                    rx={7}
                    transform={`translate(${s.x} ${s.y}) rotate(${s.angle})`}
                    fill="#fff"
                    stroke="#211d15"
                    strokeWidth={2.5}
                    opacity={on(s.lines) ? 1 : 0.22}
                />
            ))}
            {map.names.map((n, i) => (
                <text
                    key={`name-${i}`}
                    x={n.x}
                    y={n.y}
                    textAnchor={n.anchor}
                    fontSize={15}
                    fontWeight={600}
                    fill="#211d15"
                    strokeWidth={5}
                    opacity={on(n.lines) ? 1 : 0.22}
                    {...halo}
                >
                    {n.rows.map((row, r) => (
                        <tspan key={r} x={n.x} dy={r ? 15 + ROW_GAP : 0}>
                            {row}
                        </tspan>
                    ))}
                </text>
            ))}
            {map.ends.map((e, i) => {
                const badge = 18 + 9 * e.line.length;
                const total =
                    badge +
                    6 +
                    Math.max(...e.rows.map((row) => textWidth(row, 14)));
                const left =
                    e.anchor === 'start'
                        ? e.x
                        : e.anchor === 'end'
                          ? e.x - total
                          : e.x - total / 2;

                return (
                    <g
                        key={`end-${i}`}
                        opacity={on([e.line]) ? 1 : 0.16}
                        onClick={() => onPick(e.line)}
                    >
                        <rect
                            x={left}
                            y={e.y - 15}
                            width={badge}
                            height={21}
                            rx={6}
                            fill={colour(e.line)}
                        />
                        <text
                            x={left + badge / 2}
                            y={e.y}
                            textAnchor="middle"
                            fontSize={12.5}
                            fontWeight={700}
                            fill={lineLook(e.line, 'tram').ink}
                        >
                            {e.line}
                        </text>
                        <text
                            x={left + badge + 6}
                            y={e.y}
                            fontSize={14}
                            fontWeight={600}
                            fill="#211d15"
                            strokeWidth={5}
                            {...halo}
                        >
                            {e.rows.map((row, r) => (
                                <tspan
                                    key={r}
                                    x={left + badge + 6}
                                    dy={r ? 14 + ROW_GAP : 0}
                                >
                                    {row}
                                </tspan>
                            ))}
                        </text>
                    </g>
                );
            })}
            <rect
                x={map.centre.x}
                y={map.centre.y}
                width={map.centre.w}
                height={map.centre.h}
                rx={16}
                fill="#fff"
                stroke="#211d15"
                strokeWidth={3.5}
            />
            <text
                x={map.title.x}
                y={map.title.y}
                textAnchor={map.title.anchor}
                fontSize={22}
                fontWeight={700}
                fill="#211d15"
                strokeWidth={6}
                {...halo}
            >
                {data.station.name}
            </text>
        </svg>
    );
}

type StationMapProps = {
    station: Station;
    data: LinesMapData;
    open: boolean;
    onClose: () => void;
    /** The board's lines (no filters), for the departures under the map. */
    board: ShownLine[];
    /** A line's row as the board draws it (LineRow), opening in place. */
    renderLine: (line: ShownLine) => ReactNode;
};

export function StationMap({
    station,
    data,
    open,
    onClose,
    board,
    renderLine,
}: StationMapProps) {
    // A line, or the lines boarding together, picked.
    const [pick, setPick] = useState<string[] | null>(null);
    const root = useRef<HTMLElement>(null);
    const frameRef = useRef<HTMLDivElement>(null);
    // Where a pick or its clearing takes the page: to the picked rows, or
    // back up to the map.
    const scrollTo = useRef<'rows' | 'map' | null>(null);
    // A pick is a step the browser's Back takes back (lib/history-steps).
    const pickStep = useRef<(() => void) | null>(null);

    const choose = (next: string[] | null) => {
        if (next && !pickStep.current) {
            pickStep.current = pushStep(() => {
                pickStep.current = null;
                scrollTo.current = 'map';
                setPick(null);
            });
        } else if (!next && pickStep.current) {
            const off = pickStep.current;
            pickStep.current = null;
            off();
        }

        scrollTo.current = next ? 'rows' : 'map';
        setPick(next);
    };
    const same = (a: string[], b: string[]) =>
        a.length === b.length && a.every((line) => b.includes(line));
    const drawn = data.lines.map((l) => l.line);
    const chips = [...drawn, ...data.buses];
    const lookOf = (line: string) =>
        board.find((l) => l.line === line)?.look ??
        lineLook(line, data.buses.includes(line) ? 'bus' : 'tram');
    // A line or a group picked again clears the pick.
    const pickLine = (line: string) =>
        choose(pick?.length === 1 && pick[0] === line ? null : [line]);
    const pickGroup = (lines: string[]) =>
        choose(pick && same(pick, lines) ? null : lines);
    // The board's areas, as Nearby groups them.
    const groups = station.areas.filter((a) =>
        a.lines.some((line) => chips.includes(line)),
    );
    const picked = pick ? board.filter((l) => pick.includes(l.line)) : [];
    const area = pick
        ? station.areas.find((a) =>
              pick.every((line) => a.lines.includes(line)),
          )
        : null;
    const count =
        picked.length === 1
            ? `${vehicleWord(vehicleOf(picked[0].mode))} ${picked[0].line} · soonest first`
            : `${picked.length} lines · soonest first`;

    // Opened: on phones the map shows the station, the rest a drag
    // sideways. Escape closes. However it closes, its pick goes with it.
    useEffect(() => {
        if (!open) {
            return;
        }

        const frame = frameRef.current;
        const svg = frame?.firstElementChild as SVGSVGElement | null;

        if (frame && svg && frame.scrollWidth > frame.clientWidth) {
            frame.scrollLeft =
                svg.clientWidth * Number(svg.dataset.cx) -
                frame.clientWidth / 2;
        }

        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };
        document.addEventListener('keydown', escape);

        return () => document.removeEventListener('keydown', escape);
    }, [open, onClose]);

    useEffect(() => {
        if (!open) {
            return;
        }

        return () => {
            const off = pickStep.current;
            pickStep.current = null;
            off?.();
            setPick(null);
        };
    }, [open]);

    // A pick brings its rows up; clearing it goes back up to the map.
    useEffect(() => {
        const to = scrollTo.current;
        scrollTo.current = null;

        if (!to || !open) {
            return;
        }

        const target =
            to === 'rows'
                ? root.current?.querySelector('.mapview-picked')
                : root.current
                      ?.closest('.nearby')
                      ?.querySelector('.nearby-bar');
        target?.scrollIntoView({
            block: 'start',
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                .matches
                ? 'instant'
                : 'smooth',
        });
    }, [pick, open]);

    const badge = (line: string) => {
        const look = lookOf(line);

        return (
            <span
                key={line}
                className={cn('nearby-badge', look.bus && 'is-bus')}
                style={
                    {
                        '--line': look.colour,
                        '--ink': look.ink,
                    } as CSSProperties
                }
            >
                {line}
            </span>
        );
    };
    const platform = (place: string | null | undefined) =>
        place ? (
            <TravelChip kind="calm" icon={<TravelIcon name="pin" />}>
                {place}
            </TravelChip>
        ) : (
            <TravelChip kind="unsure" icon={<TravelIcon name="pin" />}>
                Platform not known
            </TravelChip>
        );

    return (
        <section
            ref={root}
            className="nearby-mapview"
            aria-labelledby="nearby-map-title"
            inert={!open}
        >
            <div className="mapview-head">
                <span>
                    <strong id="nearby-map-title">Lines and stops</strong>
                    <small>
                        {station.name} · drawn from the stops each line passes,
                        not to scale
                    </small>
                </span>
                <button
                    type="button"
                    className="mapview-close"
                    aria-label="Close the map"
                    onClick={onClose}
                >
                    <TravelIcon name="close" />
                </button>
            </div>
            <div className="mapview-chips" role="group" aria-label="Lines">
                <button
                    type="button"
                    className="mapview-chip"
                    aria-pressed={!pick}
                    onClick={() => choose(null)}
                >
                    All lines
                </button>
                {chips.map((line) => (
                    <button
                        key={line}
                        type="button"
                        className="mapview-chip"
                        aria-pressed={pick?.length === 1 && pick[0] === line}
                        onClick={() => pickLine(line)}
                    >
                        <i
                            style={
                                {
                                    '--line': lookOf(line).colour,
                                } as CSSProperties
                            }
                            aria-hidden="true"
                        />
                        {line}
                    </button>
                ))}
            </div>
            {data.lines.length ? (
                <>
                    <div className="mapview-frame" ref={frameRef}>
                        <LinesDiagram
                            data={data}
                            lit={pick}
                            onPick={pickLine}
                        />
                    </div>
                    <p className="mapview-drag">
                        Drag the map sideways to see the rest.
                    </p>
                </>
            ) : null}
            {data.buses.length ? (
                <p className="mapview-buses">
                    <TravelIcon name="bus" />
                    <span>
                        {data.buses.length > 1 ? 'Buses' : 'Bus'}{' '}
                        {data.buses.join(' · ')} also stop here; they’re listed,
                        not drawn.
                    </span>
                </p>
            ) : null}
            <div className="mapview-groups">
                <h3>At {station.name}</h3>
                <div className="mapview-group-list">
                    {groups.map((a) => {
                        const on = !!pick && same(pick, a.lines);

                        return (
                            <button
                                key={a.id}
                                type="button"
                                className="mapview-group"
                                aria-pressed={on}
                                onClick={() => pickGroup(a.lines)}
                            >
                                <span className="nearby-badges">
                                    {a.lines.map(badge)}
                                </span>
                                <span>
                                    {a.platform ?? 'Platform not known'}
                                </span>
                                {on ? (
                                    <span
                                        className="mapview-tick"
                                        aria-hidden="true"
                                    >
                                        <TravelIcon name="check" />
                                    </span>
                                ) : null}
                            </button>
                        );
                    })}
                </div>
                {pick ? null : (
                    <p>Pick a line or a group to see its next departures.</p>
                )}
            </div>
            {pick ? (
                <section
                    key={pick.join(' ')}
                    className="mapview-picked"
                    aria-label={`Departures for ${pick.join(', ')}`}
                >
                    <div className="mapview-pickbar">
                        <button
                            type="button"
                            className="mapview-allback"
                            onClick={() => choose(null)}
                        >
                            <TravelIcon name="back" />
                            <span>All lines</span>
                        </button>
                        <span className="nearby-badges">{pick.map(badge)}</span>
                        {platform(area?.platform)}
                        {picked.length ? (
                            <span className="mapview-count">{count}</span>
                        ) : null}
                    </div>
                    {picked.length ? (
                        // The board's own rows, full width, one under
                        // another.
                        <div className="mapview-lines">
                            {picked.map((l) => (
                                <Fragment key={l.line}>
                                    {renderLine(l)}
                                </Fragment>
                            ))}
                        </div>
                    ) : (
                        <p className="mapview-none">
                            No departures on the board right now.
                        </p>
                    )}
                </section>
            ) : null}
        </section>
    );
}
