import {
    IconAlertTriangle,
    IconChevronDown,
    IconClock,
} from '@tabler/icons-react';
import { useEffect, useState } from 'react';
import { StationMap } from '@/components/departures/station-map';
import {
    departureRows,
    filterDepartures,
    visibleDepartureRows,
} from '@/components/departures/timetable-model';
import type {
    Departure,
    DepartureFilters,
} from '@/components/departures/timetable-model';
import { TravelChoice } from '@/components/departures/travel-choice';

export type { Departure } from '@/components/departures/timetable-model';
export type StopArea = {
    id: string | null;
    name: string;
    lat: number;
    lng: number;
    coordinate_kind: 'stop_area';
    boarding_confirmed: false;
};
export type Board = {
    stop_name: string;
    walk_min: number;
    source: string;
    fetched_at?: string | number | null;
    generated_at?: string;
    time_basis?: 'realtime' | 'scheduled' | 'stale' | 'unavailable';
    is_stale?: boolean;
    stop?: StopArea | null;
    stops?: StopArea[];
    scope?: 'stop' | 'nearby';
    departures: Departure[];
} | null;
export type Boards = { all: Board; tram: Board; bus: Board; rail: Board };

export function isLiveBoard(board: Board): boolean {
    return board?.time_basis === 'realtime' && !board.is_stale;
}

function timestamp(
    value: string | number | null | undefined,
    fallback: number,
): number {
    const time =
        typeof value === 'number' ? value * 1000 : Date.parse(value ?? '');

    return Number.isFinite(time) ? time : fallback;
}

export function TimetableBoard({
    board,
    mode = 'all',
    stale = false,
}: {
    board: NonNullable<Board>;
    mode?: DepartureFilters['mode'];
    stale?: boolean;
}) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const tick = () => setNow(Date.now());
        const interval = window.setInterval(tick, 15_000);
        document.addEventListener('visibilitychange', tick);

        return () => {
            window.clearInterval(interval);
            document.removeEventListener('visibilitychange', tick);
        };
    }, []);
    const [limit, setLimit] = useState(10);
    const [line, setLine] = useState('all');
    const [direction, setDirection] = useState('all');
    const [notices, setNotices] = useState(false);
    const [showMap, setShowMap] = useState(false);
    const stopAreas = (board.stops ?? (board.stop ? [board.stop] : [])).filter(
        (stop) => Number.isFinite(stop.lat) && Number.isFinite(stop.lng),
    );
    const [fallbackReceivedAt, setFallbackReceivedAt] = useState(() =>
        Date.now(),
    );
    useEffect(() => {
        if (board.generated_at || board.fetched_at) {
            return;
        }

        const timer = window.setTimeout(
            () => setFallbackReceivedAt(Date.now()),
            0,
        );

        return () => window.clearTimeout(timer);
    }, [board]);
    const receivedAt = timestamp(
        board.generated_at,
        timestamp(board.fetched_at, fallbackReceivedAt),
    );
    const observedAt = timestamp(board.fetched_at, receivedAt);
    const modeRows = filterDepartures(board.departures, {
        mode,
        line: 'all',
        direction: 'all',
        notices: false,
    });
    const lines = [
        ...new Set(modeRows.map((departure) => departure.line)),
    ].sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    const directions = [
        ...new Set(
            modeRows
                .filter(
                    (departure) => line === 'all' || departure.line === line,
                )
                .map((departure) => departure.destination)
                .filter(Boolean),
        ),
    ].sort();
    // Keep the person's filter during polling. An empty filtered result can be
    // reset explicitly instead of unexpectedly jumping to All.
    const filtered = filterDepartures(board.departures, {
        mode,
        line,
        direction,
        notices,
    });
    const rows = departureRows(filtered, receivedAt).filter(
        (row) =>
            !notices ||
            row.departure.cancelled ||
            row.departure.disrupted ||
            row.departure.delay > 0,
    );
    const visible = visibleDepartureRows(rows, limit);
    const outdated =
        stale ||
        board.is_stale ||
        board.time_basis === 'stale' ||
        (isLiveBoard(board) && now - observedAt > 90_000);
    const status = outdated
        ? 'Last known times'
        : isLiveBoard(board)
          ? 'Live times'
          : 'Scheduled';
    const changed = line !== 'all' || direction !== 'all' || notices;
    const reset = () => {
        setLine('all');
        setDirection('all');
        setNotices(false);
        setLimit(10);
    };

    return (
        <section aria-label={`Departures from ${board.stop_name}`}>
            <div
                className="mb-3 flex flex-wrap items-center gap-2"
                aria-label="Departure filters"
            >
                <TravelChoice
                    label="Filter line"
                    value={line}
                    options={[
                        { value: 'all', label: 'All lines' },
                        ...[
                            ...new Set([
                                ...lines,
                                ...(line === 'all' ? [] : [line]),
                            ]),
                        ].map((value) => ({ value, label: `Line ${value}` })),
                    ]}
                    onChange={(value) => {
                        setLine(value);
                        setDirection('all');
                        setLimit(10);
                    }}
                />
                <TravelChoice
                    label="Filter direction"
                    value={direction}
                    options={[
                        { value: 'all', label: 'All directions' },
                        ...[
                            ...new Set([
                                ...directions,
                                ...(direction === 'all' ? [] : [direction]),
                            ]),
                        ].map((value) => ({ value, label: value })),
                    ]}
                    onChange={(value) => {
                        setDirection(value);
                        setLimit(10);
                    }}
                />
                <TravelChoice
                    label="Filter service notices"
                    value={notices ? 'notices' : 'all'}
                    options={[
                        { value: 'all', label: 'All services' },
                        { value: 'notices', label: 'Service notices' },
                    ]}
                    onChange={(value) => {
                        setNotices(value === 'notices');
                        setLimit(10);
                    }}
                />
                {changed && (
                    <button
                        type="button"
                        onClick={reset}
                        className="min-h-11 rounded-lg px-2 text-sm font-semibold text-cyan-h focus-visible:outline-2 focus-visible:outline-cyan"
                    >
                        Reset
                    </button>
                )}
                {stopAreas.length > 0 && (
                    <button
                        type="button"
                        aria-pressed={showMap}
                        onClick={() => setShowMap((value) => !value)}
                        className={`ml-auto min-h-11 rounded-xl border border-border px-3 text-sm font-semibold text-cyan-h focus-visible:outline-2 focus-visible:outline-cyan ${showMap ? 'bg-cyan/15' : 'bg-card'}`}
                    >
                        {showMap ? 'Hide stop map' : 'Show stop map'}
                    </button>
                )}
            </div>
            <div
                className={
                    showMap
                        ? 'grid items-start gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(300px,0.8fr)]'
                        : ''
                }
            >
                {showMap && (
                    <aside className="lg:sticky lg:top-24 lg:order-2">
                        <StationMap
                            key={JSON.stringify(stopAreas)}
                            stops={stopAreas}
                        />
                    </aside>
                )}
                <div className="overflow-hidden rounded-2xl border border-border bg-card">
                    <div className="flex items-center justify-between gap-3 border-b border-border px-3.5 py-3">
                        <h2 className="min-w-0 text-base font-semibold">
                            {board.scope === 'nearby'
                                ? 'Nearby departures'
                                : board.stop_name}
                        </h2>
                        <span
                            className={`flex shrink-0 items-center gap-1.5 text-xs ${outdated ? 'text-muted-foreground' : 'text-cyan-h'}`}
                        >
                            <IconClock size={14} />
                            {status}
                        </span>
                    </div>
                    <div
                        className="flex gap-3 border-b border-border px-3.5 py-2 text-xs text-muted-foreground"
                        aria-hidden="true"
                    >
                        <span className="w-9">Line</span>
                        <span className="flex-1">Towards</span>
                        <span className="pr-6">Departs</span>
                    </div>
                    <div className="divide-y divide-border">
                        {visible.map(
                            ({
                                departure,
                                at,
                                key,
                                estimated,
                                boardingPoint,
                            }) => {
                                const date = at === null ? null : new Date(at);
                                const time = date?.toLocaleTimeString('en-GB', {
                                    hour: '2-digit',
                                    minute: '2-digit',
                                });
                                const nextDay =
                                    date &&
                                    date.toDateString() !==
                                        new Date(receivedAt).toDateString();
                                const notice = departure.cancelled
                                    ? 'Cancelled'
                                    : departure.delay > 0
                                      ? `+${departure.delay} min`
                                      : departure.disrupted
                                        ? 'Service notice'
                                        : null;

                                return (
                                    <details key={key} className="group">
                                        <summary className="timetable-row flex min-h-14 cursor-pointer list-none items-center gap-3 px-3.5 py-2 transition-colors outline-none group-open:bg-cyan/5 focus-visible:bg-cyan/10 active:bg-cyan/10 motion-reduce:transition-none [&::-webkit-details-marker]:hidden">
                                            <span
                                                className="flex min-h-7 min-w-9 shrink-0 items-center justify-center rounded-lg bg-cyan/10 px-1.5 text-sm font-bold text-foreground"
                                                style={{
                                                    borderBottom: `3px solid ${departure.color}`,
                                                }}
                                            >
                                                {departure.line}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-sm font-semibold">
                                                    {departure.destination ||
                                                        'Direction unavailable'}
                                                </span>
                                                {board.scope === 'nearby' && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {departure.stop_name ??
                                                            'Stop not supplied'}
                                                    </span>
                                                )}
                                                {notice && (
                                                    <span className="flex items-center gap-1 text-xs text-danger">
                                                        <IconAlertTriangle
                                                            size={12}
                                                        />
                                                        {notice}
                                                    </span>
                                                )}
                                            </span>
                                            <span
                                                className="timetable-time shrink-0 text-right tabular-nums"
                                                aria-label={
                                                    departure.cancelled
                                                        ? 'cancelled'
                                                        : `departs at ${time ?? 'unknown time'}`
                                                }
                                            >
                                                <span
                                                    className={`block text-base font-semibold ${departure.cancelled ? 'text-danger line-through' : ''}`}
                                                >
                                                    {departure.cancelled
                                                        ? '—'
                                                        : (time ?? '—')}
                                                </span>
                                                {nextDay && (
                                                    <span className="block text-[11px] text-muted-foreground">
                                                        {date.toLocaleDateString(
                                                            'en-GB',
                                                            {
                                                                day: 'numeric',
                                                                month: 'short',
                                                            },
                                                        )}
                                                    </span>
                                                )}
                                            </span>
                                            <IconChevronDown
                                                size={14}
                                                className="shrink-0 text-muted-foreground transition-transform group-open:rotate-180 motion-reduce:transition-none"
                                            />
                                        </summary>
                                        <div className="bg-cyan/5 px-3.5 pt-1 pb-3 pl-[66px] text-sm">
                                            <p>
                                                Line {departure.line} toward{' '}
                                                {departure.destination ||
                                                    'an unlisted destination'}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {outdated
                                                    ? 'Updates are delayed. Check the stop display before boarding.'
                                                    : estimated
                                                      ? 'Approximate time from the latest available countdown. Check the stop display.'
                                                      : 'Check the stop display before boarding. Times may change.'}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {boardingPoint?.platform
                                                    ? `Platform ${boardingPoint.platform}`
                                                    : (boardingPoint?.name ??
                                                      'Boarding point information is not available.')}
                                            </p>
                                        </div>
                                    </details>
                                );
                            },
                        )}
                        {!rows.length && (
                            <div className="px-4 py-6 text-sm text-muted-foreground">
                                <p>No departures match these filters.</p>
                                {changed && (
                                    <button
                                        type="button"
                                        onClick={reset}
                                        className="mt-2 min-h-11 font-semibold text-cyan-h"
                                    >
                                        Show all services
                                    </button>
                                )}
                            </div>
                        )}
                    </div>
                    {visible.length < rows.length && (
                        <button
                            type="button"
                            onClick={() => setLimit(limit + 10)}
                            className="min-h-11 w-full border-t border-border text-sm font-semibold text-cyan-h focus-visible:outline-2 focus-visible:outline-cyan"
                        >
                            More departures ({rows.length - visible.length})
                        </button>
                    )}
                    <p className="border-t border-border px-3.5 py-2 text-xs text-muted-foreground">
                        {outdated ? 'Last checked' : 'Updated'}{' '}
                        {new Date(observedAt).toLocaleTimeString('en-GB', {
                            hour: '2-digit',
                            minute: '2-digit',
                        })}{' '}
                        ·{' '}
                        {rows.some((row) => row.estimated)
                            ? 'Approximate times'
                            : 'Times may change'}
                    </p>
                </div>
            </div>
        </section>
    );
}
