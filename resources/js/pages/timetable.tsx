import { Deferred, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';

type Departure = {
    line: string;
    destination: string;
    type: string;
    color: string;
    minutes: number[];
    delay: number;
    cancelled: boolean;
    disrupted: boolean;
};

type Board = {
    stop_name: string;
    walk_min: number;
    source: string;
    departures: Departure[];
} | null;

type Boards = { all: Board; tram: Board; bus: Board };

type Mode = 'all' | 'tram' | 'bus';

const TABS: { key: Mode; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'tram', label: '🚊 Tram' },
    { key: 'bus', label: '🚌 Bus' },
];

const VISIBLE = 5;

function DepartureRow({ dep }: { dep: Departure }) {
    const first = dep.minutes[0] ?? null;
    const soon = first !== null && first <= 3;
    const following = dep.minutes.slice(1, 4);

    return (
        <div className="flex items-center gap-3.5 border-b border-border px-4 py-3 last:border-b-0">
            <span
                className="flex h-[30px] min-w-[38px] shrink-0 items-center justify-center rounded-[8px] px-2 text-sm font-bold text-white"
                style={{ background: dep.color }}
            >
                {dep.line}
            </span>
            <div className="min-w-0 flex-1">
                <div className="truncate text-[14.5px] font-semibold">
                    {dep.destination || 'Cologne'}
                </div>
            </div>
            <div className="shrink-0 text-right">
                {dep.cancelled ? (
                    <div className="font-mono text-[13px] font-semibold text-danger">
                        cancelled
                    </div>
                ) : (
                    <div
                        className={`font-mono text-base font-semibold ${first !== null && first <= 0 ? 'text-success' : soon ? 'text-warn' : 'text-foreground'}`}
                    >
                        {first === null
                            ? '–'
                            : first <= 0
                              ? 'now'
                              : `${first} min`}
                    </div>
                )}
                <div className="mt-0.5 font-mono text-[11px] whitespace-nowrap text-muted-foreground/70">
                    {dep.delay > 0 && (
                        <span className="text-danger">+{dep.delay} late</span>
                    )}
                    {dep.delay > 0 && following.length > 0 && ' · '}
                    {following.length > 0 && `then ${following.join(', ')} min`}
                </div>
            </div>
        </div>
    );
}

function BoardView({ board, mode }: { board: Board; mode: Mode }) {
    const [expanded, setExpanded] = useState(false);

    if (!board || board.departures.length === 0) {
        const what =
            mode === 'tram' ? 'trams' : mode === 'bus' ? 'buses' : 'departures';

        return (
            <div className="rounded-[14px] border border-border bg-card p-6 text-center text-sm text-muted-foreground">
                No live {what} for your nearest stop right now.
            </div>
        );
    }

    const visible = expanded
        ? board.departures
        : board.departures.slice(0, VISIBLE);
    const hidden = board.departures.length - VISIBLE;
    const live = board.source === 'trias_rt';

    return (
        <div>
            <div className="mb-3 flex items-center gap-2.5">
                <h2 className="font-display text-[18px] font-medium tracking-tight">
                    {board.stop_name}
                </h2>
                <span className="text-xs text-muted-foreground">
                    · {board.walk_min} min walk
                </span>
                <span className="ml-auto inline-flex items-center gap-1.5 text-[11px] font-semibold text-success">
                    <span
                        className={`size-[7px] rounded-full bg-success ${live ? 'animate-pulse' : ''}`}
                    />
                    {live ? 'LIVE' : 'SCHEDULED'}
                </span>
            </div>
            <div className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                <div
                    className={
                        expanded && hidden > 0
                            ? 'max-h-[330px] overflow-y-auto'
                            : ''
                    }
                >
                    {visible.map((dep, i) => (
                        <DepartureRow
                            key={`${dep.line}-${dep.destination}-${i}`}
                            dep={dep}
                        />
                    ))}
                </div>
                {hidden > 0 && (
                    <button
                        onClick={() => setExpanded((e) => !e)}
                        className="w-full cursor-pointer border-t border-border py-3 text-[12.5px] font-semibold text-primary"
                    >
                        {expanded
                            ? 'Show fewer ▴'
                            : `Show ${hidden} more line${hidden === 1 ? '' : 's'} ▾`}
                    </button>
                )}
            </div>
        </div>
    );
}

export default function Timetable() {
    const { boards } = usePage<{ boards?: Boards }>().props;
    const [mode, setMode] = useState<Mode>('all');

    return (
        <AppLayout>
            <Head title="Departures" />
            <div className="mx-auto w-full max-w-[600px] px-4 pt-6 pb-24 md:px-6">
                <h1 className="font-display text-[26px] font-medium tracking-tight">
                    Departures near you
                </h1>
                <p className="mt-1 mb-5 text-sm text-muted-foreground">
                    Live KVB times · the tab picks the mode and its nearest stop
                </p>

                <div className="mb-5 flex gap-2">
                    {TABS.map((tab) => (
                        <button
                            key={tab.key}
                            onClick={() => setMode(tab.key)}
                            className={`cursor-pointer rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors ${
                                mode === tab.key
                                    ? 'border-foreground bg-foreground text-background'
                                    : 'border-border bg-card text-muted-foreground hover:border-primary'
                            }`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                <Deferred
                    data="boards"
                    fallback={
                        <div>
                            <div className="mb-3 h-5 w-44 animate-pulse rounded bg-secondary" />
                            <div className="flex flex-col gap-px overflow-hidden rounded-[14px] border border-border">
                                {[1, 2, 3, 4, 5].map((i) => (
                                    <div
                                        key={i}
                                        className="h-[58px] animate-pulse bg-secondary"
                                    />
                                ))}
                            </div>
                        </div>
                    }
                >
                    <BoardView board={boards?.[mode] ?? null} mode={mode} />
                </Deferred>
            </div>
        </AppLayout>
    );
}
