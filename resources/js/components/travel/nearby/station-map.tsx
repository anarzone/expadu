import { useEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { TravelChip } from '../labels';
import { lineLook } from '../line-look';
import { TravelIcon } from '../travel-icon';
import { layoutLinesMap, ROW_GAP, textWidth } from './lines-map-layout';
import type { LinesMapData } from './lines-map-layout';
import type { ShownLine } from './nearby-model';
import type { Station } from './types';
import './station-map.css';

/**
 * Nearby's Map (departures-next nearby-ui.js renderMap, transit-map.js):
 * "Lines and stops", the station's lines and their next stops drawn as a
 * transit diagram, not a street map. On desktop it takes the list's place
 * and ✕ brings the list back; on phones it slides in as its own screen and
 * Back slides it away (owner, 15 Oct 2026). A line or a group picked fades
 * the rest, and its next departures from the board show under the map.
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

/** Desktop: the map takes the list's place rather than a screen of its own. */
const DESKTOP = '(min-width: 1000px)';

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
            height={Math.round(h)}
            data-cx={-x / w}
            data-cy={-y / h}
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
                    opacity={on([p.line]) ? 1 : 0.12}
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
                        opacity={on([e.line]) ? 1 : 0.12}
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
    /** A line's departures as Nearby draws them. */
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
    const frameRef = useRef<HTMLDivElement>(null);
    const drawn = data.lines.map((l) => l.line);
    const chips = [...drawn, ...data.buses];
    const lookOf = (line: string) =>
        board.find((l) => l.line === line)?.look ??
        lineLook(line, data.buses.includes(line) ? 'bus' : 'tram');
    const pickLine = (line: string) =>
        setPick((now) =>
            now?.length === 1 && now[0] === line ? null : [line],
        );
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

    // Phones: the screen opens on the station, the rest a drag away. Escape
    // closes.
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
            frame.scrollTop =
                svg.clientHeight * Number(svg.dataset.cy) -
                frame.clientHeight / 2;
        }

        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };
        document.addEventListener('keydown', escape);

        return () => document.removeEventListener('keydown', escape);
    }, [open, onClose]);

    // Phones: the map is a screen of its own, so the browser's Back closes
    // it too. Its history entry keeps Inertia's page, and its Back is
    // caught before Inertia's (which would load the page again).
    useEffect(() => {
        if (!open || window.matchMedia(DESKTOP).matches) {
            return;
        }

        window.history.pushState(
            { ...window.history.state, travelMap: true },
            '',
        );
        let popped = false;
        const back = (event: PopStateEvent) => {
            popped = true;
            event.stopImmediatePropagation();
            onClose();
        };
        window.addEventListener('popstate', back, { capture: true });

        return () => {
            window.removeEventListener('popstate', back, { capture: true });

            // Closed here (its own Back, Escape): take its entry off too,
            // and keep that Back from Inertia as well.
            if (!popped && window.history.state?.travelMap) {
                const swallow = (event: PopStateEvent) =>
                    event.stopImmediatePropagation();
                window.addEventListener('popstate', swallow, {
                    capture: true,
                    once: true,
                });
                window.history.back();
            }
        };
    }, [open, onClose]);

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

    return (
        <section
            className="nearby-mapview"
            aria-labelledby="nearby-map-title"
            inert={!open}
        >
            <div className="mapview-head">
                <button
                    type="button"
                    className="mapview-close is-back"
                    aria-label="Back"
                    onClick={onClose}
                >
                    <TravelIcon name="back" />
                </button>
                <span>
                    <strong id="nearby-map-title">
                        {pick ? 'Where it goes' : 'Lines and stops'}
                    </strong>
                    <small>
                        {station.name} · drawn from the stops each line passes,
                        not to scale
                    </small>
                </span>
                <button
                    type="button"
                    className="mapview-close is-close"
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
                    onClick={() => setPick(null)}
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
            <div className="mapview-body">
                {data.lines.length ? (
                    <div className="mapview-window">
                        <div className="mapview-frame" ref={frameRef}>
                            <LinesDiagram
                                data={data}
                                lit={pick}
                                onPick={pickLine}
                            />
                        </div>
                    </div>
                ) : null}
                {data.buses.length ? (
                    <p className="mapview-buses">
                        <TravelIcon name="bus" />
                        <span>
                            {data.buses.length > 1 ? 'Buses' : 'Bus'}{' '}
                            {data.buses.join(' · ')} also stop here; they’re
                            listed, not drawn.
                        </span>
                    </p>
                ) : null}
                {pick ? (
                    <section
                        className="mapview-stand"
                        aria-labelledby="mapview-stand-title"
                    >
                        <span className="mapview-stand-head">
                            <strong id="mapview-stand-title">
                                <span className="nearby-badges">
                                    {pick.map(badge)}
                                </span>
                                {area?.platform ? (
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
                            </strong>
                        </span>
                        {picked.length ? (
                            <div className="nearby-cards">
                                {picked.map((l) => (
                                    <div key={l.line}>{renderLine(l)}</div>
                                ))}
                            </div>
                        ) : (
                            <p className="mapview-none">
                                No departures on the board right now.
                            </p>
                        )}
                    </section>
                ) : (
                    <div className="mapview-groups">
                        <h3>At {station.name}</h3>
                        {groups.map((a) => (
                            <button
                                key={a.id}
                                type="button"
                                className="mapview-group"
                                onClick={() => setPick(a.lines)}
                            >
                                <span className="nearby-badges">
                                    {a.lines.map(badge)}
                                </span>
                                <span>
                                    {a.platform ?? 'Platform not known'}
                                </span>
                                <TravelIcon name="chevron" />
                            </button>
                        ))}
                        <p>
                            Pick a line or a group to see its next departures.
                            The map is drawn from the stops each line passes,
                            not to scale.
                        </p>
                    </div>
                )}
            </div>
        </section>
    );
}
