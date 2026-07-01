import { Deferred, Head, usePage } from '@inertiajs/react';
import {
    IconAlertTriangle,
    IconArrowRight,
    IconBan,
} from '@tabler/icons-react';
import { useEffect, useState } from 'react';
import { JourneyPlanner } from '@/components/departures/journey-planner';
import type { SavedPlace } from '@/components/departures/journey-planner';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { FeedbackToast } from '@/components/places/place-feedback-menu';
import { ICON_STROKE } from '@/constants/icons';
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
    /** GTFS travel direction (0/1) — groups the board into lanes. */
    direction: number | null;
    /** The next stops ridden after this one ("via …"). */
    via: string[];
};

/** A city/transit disruption from the shared middleware prop. */
type DisruptionItem = {
    title: string;
    severity?: string;
};

type Board = {
    stop_name: string;
    walk_min: number;
    source: string;
    departures: Departure[];
} | null;

type Boards = { all: Board; tram: Board; bus: Board };

type Mode = 'all' | 'tram' | 'bus';

type GeoResult = {
    name: string;
    address?: string | null;
    lat: number;
    lng: number;
};

const TABS: { key: Mode; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'tram', label: '🚊 Tram' },
    { key: 'bus', label: '🚌 Bus' },
];

const VISIBLE = 6;

/**
 * KVB line colours are tuned for a light card; a few read too dark on the board's
 * near-black surface, so lift them a touch (mirrors the prototype BOARD_TINT).
 */
const BOARD_TINT: Record<string, string> = {
    '#d2151a': '#e23b2e',
    '#f39200': '#ff9e1f',
    '#009640': '#1eb35e',
    '#95288a': '#b455c0',
    '#0099d8': '#27a8de',
    '#e3001b': '#e23b2e',
    '#5a287d': '#9a5bc0',
    '#0067b3': '#2f8fd6',
    '#f8ac00': '#ffc24d',
};

function boardTint(color: string): string {
    return BOARD_TINT[color.toLowerCase()] ?? color;
}

/**
 * The next three minutes as board chips. Only the soonest escalates
 * lead → soon (≤3 min) → now (≤0); the following two stay muted.
 */
function toChips(minutes: number[]): { label: string; cls: string }[] {
    return minutes.slice(0, 3).map((m, i) => {
        const state = i > 0 ? '' : m <= 0 ? ' now' : m <= 3 ? ' soon' : ' lead';

        return { label: m <= 0 ? 'NOW' : String(m), cls: `dchip${state}` };
    });
}

type Alt = {
    problemText: string;
    altText: string;
    altColor: string;
    altLine: string;
};

/**
 * When a line is cancelled or running late, surface a calmer alternative — the
 * soonest on-time line heading a different way. Purely derived from the board.
 */
function altSuggestion(deps: Departure[]): Alt | null {
    const problem = deps.find((d) => d.cancelled || d.delay > 0);

    if (!problem) {
        return null;
    }

    const alt = deps.find(
        (d) => !d.cancelled && d.delay === 0 && d.line !== problem.line,
    );

    if (!alt) {
        return null;
    }

    const next = alt.minutes[0];

    return {
        problemText: problem.cancelled
            ? `Line ${problem.line} → ${problem.destination || 'its terminus'} is cancelled`
            : `Line ${problem.line} is running +${problem.delay} min late`,
        altText: `Line ${alt.line} → ${alt.destination || 'its terminus'} is a calmer bet${
            next != null ? ` — leaves in ${next} min` : ''
        }`,
        altColor: alt.color,
        altLine: alt.line,
    };
}

function placeEmoji(place: SavedPlace): string {
    if (place.emoji) {
        return place.emoji;
    }

    if (place.category === 'home') {
        return '🏠';
    }

    if (place.category === 'work') {
        return '💼';
    }

    return '📍';
}

function savedToDestination(place: SavedPlace): Destination {
    return {
        name: place.name,
        emoji: placeEmoji(place),
        lat: place.lat,
        lng: place.lng,
    };
}

/** A ticking wall clock (HH:MM:SS) — the board's "live" heartbeat. */
function LiveClock() {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const id = setInterval(() => setNow(new Date()), 1000);

        return () => clearInterval(id);
    }, []);

    return (
        <>
            {now.toLocaleTimeString('de-DE', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
            })}
        </>
    );
}

function BoardRow({ dep }: { dep: Departure }) {
    const chips = toChips(dep.minutes);
    const dim = dep.cancelled ? 0.5 : 1;

    return (
        <div className="flex items-center gap-4 border-b border-[var(--bd-line)] px-4 py-3 last:border-b-0 md:gap-[15px] md:px-[18px]">
            <span
                className="flex h-8 min-w-[42px] shrink-0 items-center justify-center rounded-lg px-2 font-mono text-sm font-bold text-white"
                style={{ background: boardTint(dep.color), opacity: dim }}
            >
                {dep.line}
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span
                        className="truncate text-[15px] font-semibold text-[var(--bd-ink)]"
                        style={{ opacity: dim }}
                    >
                        {dep.destination || 'Cologne'}
                    </span>
                    {dep.delay > 0 && !dep.cancelled && (
                        <span className="shrink-0 rounded-[5px] bg-[#ffc24d] px-1.5 py-0.5 font-mono text-[10px] font-semibold text-[#0e1116]">
                            +{dep.delay}
                        </span>
                    )}
                    {dep.cancelled && (
                        <span className="shrink-0 rounded-[5px] bg-[#ff6b54] px-1.5 py-0.5 font-mono text-[10px] font-semibold text-white">
                            cancelled
                        </span>
                    )}
                </div>
                {dep.via.length > 0 && (
                    <div className="mt-[3px] truncate font-mono text-[11px] text-[var(--bd-sub)]">
                        via {dep.via.join(' · ')}
                    </div>
                )}
            </div>
            {dep.cancelled ? (
                <span className="shrink-0 font-mono text-xs text-[#ff6b54]">
                    no service
                </span>
            ) : (
                <div className="flex shrink-0 items-center gap-1.5">
                    {chips.map((chip, i) => (
                        <span key={i} className={chip.cls}>
                            {chip.label}
                        </span>
                    ))}
                    <span className="ml-px font-mono text-[11px] text-[var(--bd-sub)]">
                        min
                    </span>
                </div>
            )}
        </div>
    );
}

/** "Toward {top destinations}" for a direction lane. */
function laneLabel(rows: Departure[]): string {
    const dests = [
        ...new Set(rows.map((d) => d.destination).filter(Boolean)),
    ].slice(0, 2);

    return dests.length > 0 ? `Toward ${dests.join(' / ')}` : 'Departures';
}

function LaneHeader({
    direction,
    label,
}: {
    direction: number;
    label: string;
}) {
    return (
        <div className="flex items-center gap-2.5 border-y border-[var(--bd-line)] bg-[var(--bd-panel)] px-4 py-[11px] md:px-[18px]">
            <IconArrowRight
                size={14}
                stroke={2.2}
                className={`shrink-0 ${direction === 1 ? 'rotate-180' : ''}`}
                style={{ color: direction === 0 ? '#3ddc97' : '#ffc24d' }}
            />
            <span className="truncate font-mono text-[11px] tracking-[0.1em] text-[var(--bd-ink)] uppercase">
                {label}
            </span>
        </div>
    );
}

function DepartureBoard({ board }: { board: NonNullable<Board> }) {
    const [expanded, setExpanded] = useState(false);
    const platform =
        board.departures[0]?.type === 'bus' ? 'KVB Bus' : 'KVB Stadtbahn';

    // Direction lanes (v4): only when GTFS matched both travel directions —
    // otherwise the board stays a flat list. Rows without a matched direction
    // ride along at the bottom of the grouped board.
    const dir0 = board.departures.filter((d) => d.direction === 0);
    const dir1 = board.departures.filter((d) => d.direction === 1);
    const ungrouped = board.departures.filter((d) => d.direction == null);
    const grouped = dir0.length > 0 && dir1.length > 0;

    const laneCap = Math.ceil(VISIBLE / 2);
    // How many rows the collapsed board shows — the toggle renders whenever
    // expanding would reveal more (and stays visible to collapse again).
    const collapsedShown = grouped
        ? Math.min(dir0.length, laneCap) + Math.min(dir1.length, laneCap)
        : Math.min(board.departures.length, VISIBLE);
    const hidden = board.departures.length - collapsedShown;

    const lanes: Array<{ direction: number; rows: Departure[] }> = grouped
        ? [
              { direction: 0, rows: expanded ? dir0 : dir0.slice(0, laneCap) },
              { direction: 1, rows: expanded ? dir1 : dir1.slice(0, laneCap) },
          ]
        : [];

    return (
        <div
            className="board overflow-hidden rounded-2xl border border-[var(--bd-line)] bg-[var(--bd-bg)]"
            style={{ boxShadow: '0 12px 34px rgba(20,16,8,.22)' }}
        >
            <div className="flex items-center justify-between border-b border-[var(--bd-line)] bg-[var(--bd-panel)] px-[18px] py-[13px]">
                <span className="truncate font-mono text-[11px] tracking-[0.12em] text-[var(--bd-sub)] uppercase">
                    {platform} · {board.stop_name}
                </span>
                <span className="flex shrink-0 items-center gap-1.5 font-mono text-[11px] text-[#3ddc97]">
                    <span className="size-1.5 animate-pulse rounded-full bg-[#3ddc97]" />
                    LIVE <LiveClock />
                </span>
            </div>
            {grouped ? (
                <div>
                    {lanes.map((lane) => (
                        <div key={lane.direction}>
                            <LaneHeader
                                direction={lane.direction}
                                label={laneLabel(
                                    lane.direction === 0 ? dir0 : dir1,
                                )}
                            />
                            {lane.rows.map((dep, i) => (
                                <BoardRow
                                    key={`${dep.line}-${dep.destination}-${i}`}
                                    dep={dep}
                                />
                            ))}
                        </div>
                    ))}
                    {expanded &&
                        ungrouped.map((dep, i) => (
                            <BoardRow
                                key={`u-${dep.line}-${dep.destination}-${i}`}
                                dep={dep}
                            />
                        ))}
                </div>
            ) : (
                <div>
                    {(expanded
                        ? board.departures
                        : board.departures.slice(0, VISIBLE)
                    ).map((dep, i) => (
                        <BoardRow
                            key={`${dep.line}-${dep.destination}-${i}`}
                            dep={dep}
                        />
                    ))}
                </div>
            )}
            {hidden > 0 && (
                <button
                    onClick={() => setExpanded((e) => !e)}
                    className="w-full cursor-pointer py-[11px] text-center font-mono text-[11px] tracking-[0.06em] text-[var(--bd-sub)] uppercase"
                >
                    {expanded ? 'Show fewer ▴' : 'Show more ▾'}
                </button>
            )}
        </div>
    );
}

function BoardSkeleton() {
    return (
        <div className="overflow-hidden rounded-2xl border border-border">
            <div className="h-11 animate-pulse bg-secondary" />
            {[1, 2, 3, 4, 5].map((i) => (
                <div
                    key={i}
                    className="h-[58px] animate-pulse border-t border-border bg-secondary"
                />
            ))}
        </div>
    );
}

function EmptyBoard({ mode }: { mode: Mode }) {
    const what =
        mode === 'tram' ? 'trams' : mode === 'bus' ? 'buses' : 'departures';

    return (
        <div className="rounded-2xl border border-border bg-card p-6 text-center text-sm text-muted-foreground">
            No live {what} for your nearest stop right now.
        </div>
    );
}

/** Known service problems under the board — from the shared disruptions prop. */
function PlannedDisruptions({
    disruptions,
}: {
    disruptions: DisruptionItem[];
}) {
    if (disruptions.length === 0) {
        return null;
    }

    return (
        <div className="mt-4">
            <div className="mb-2.5 font-mono text-[11px] tracking-[0.1em] text-text-3 uppercase">
                Planned disruptions
            </div>
            {disruptions.map((d, i) => (
                <div
                    key={i}
                    className="flex items-center gap-2.5 border-b border-border py-[9px]"
                >
                    <IconBan
                        size={15}
                        stroke={ICON_STROKE}
                        className="shrink-0 text-danger"
                    />
                    <span className="min-w-0 flex-1 text-[13px] text-muted-foreground">
                        {d.title}
                    </span>
                </div>
            ))}
        </div>
    );
}

function AltCard({ alt }: { alt: Alt }) {
    return (
        <div className="mt-4 flex items-center gap-3.5 rounded-[14px] border border-l-[3px] border-warn-soft border-l-warn bg-warn-soft px-4 py-3.5">
            <IconAlertTriangle
                size={20}
                stroke={ICON_STROKE}
                className="shrink-0 text-warn"
            />
            <div className="min-w-0 flex-1">
                <div className="text-[13.5px] font-semibold">
                    {alt.problemText}
                </div>
                <div className="mt-0.5 text-[13px] text-muted-foreground">
                    {alt.altText}
                </div>
            </div>
            <span
                className="flex h-[30px] min-w-[38px] shrink-0 items-center justify-center rounded-lg px-2 font-mono text-[13px] font-bold text-white"
                style={{ background: alt.altColor }}
            >
                {alt.altLine}
            </span>
        </div>
    );
}

/**
 * The "Where to?" card: the resolved origin, a typeable destination (Enter or
 * "Plan trip" opens the journey), and one-tap saved destinations.
 */
function JourneyEntryCard({
    stop,
    savedPlaces,
    busy,
    onPlan,
}: {
    stop: string;
    savedPlaces: SavedPlace[];
    busy: boolean;
    onPlan: (target: Destination | { query: string }) => void;
}) {
    const [text, setText] = useState('');
    const launch = savedPlaces.slice(0, 3);

    function submit() {
        const query = text.trim();

        if (query) {
            onPlan({ query });
        }
    }

    return (
        <div className="mb-[18px] rounded-2xl border border-border bg-card p-4 shadow-sm">
            <div className="flex items-center gap-3">
                <span className="size-2.5 shrink-0 rounded-full border-[3px] border-cyan" />
                <span className="flex-1 text-sm font-semibold">
                    You · {stop}
                </span>
            </div>
            <div className="my-2 ml-1 h-px bg-border" />
            <div className="flex items-center gap-3">
                <span className="size-2.5 shrink-0 rotate-45 rounded-[50%_50%_50%_0] bg-primary" />
                <input
                    type="text"
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            submit();
                        }
                    }}
                    placeholder="Where to?"
                    className="min-w-0 flex-1 border-none bg-transparent text-sm font-medium text-foreground outline-none placeholder:text-text-3"
                />
                <button
                    onClick={submit}
                    disabled={busy}
                    className="shrink-0 rounded-full bg-primary-soft px-3 py-1.5 font-mono text-[10.5px] font-semibold tracking-[0.06em] text-primary uppercase disabled:opacity-60"
                >
                    {busy ? 'Planning…' : 'Plan trip →'}
                </button>
            </div>
            {launch.length > 0 && (
                <div className="mt-3 flex gap-1.5 overflow-x-auto pl-[22px]">
                    {launch.map((place) => (
                        <button
                            key={place.id}
                            onClick={() => onPlan(savedToDestination(place))}
                            className="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-border bg-secondary px-2.5 py-1.5 text-[12.5px] font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary"
                        >
                            <span>{placeEmoji(place)}</span>
                            {place.name}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function Timetable() {
    const {
        boards,
        savedPlaces = [],
        activeDisruptions = [],
    } = usePage<{
        boards?: Boards;
        savedPlaces?: SavedPlace[];
        activeDisruptions?: DisruptionItem[];
    }>().props;
    const [mode, setMode] = useState<Mode>('all');
    const [destination, setDestination] = useState<Destination | null>(null);
    const [planning, setPlanning] = useState(false);
    const [toast, setToast] = useState<string | null>(null);

    function flash(message: string) {
        setToast(message);
        window.setTimeout(() => setToast(null), 2600);
    }

    // Saved destinations arrive with coordinates; free text is geocoded to the
    // best hit before the journey sheet opens.
    async function planTo(target: Destination | { query: string }) {
        if ('lat' in target) {
            setDestination(target);

            return;
        }

        setPlanning(true);

        try {
            const res = await fetch(
                `/api/geocode?q=${encodeURIComponent(target.query)}`,
                { credentials: 'same-origin' },
            );
            const results = (await res.json()) as GeoResult[];

            if (results.length > 0) {
                const hit = results[0];

                setDestination({
                    name: hit.name,
                    address: hit.address ?? undefined,
                    lat: hit.lat,
                    lng: hit.lng,
                });
            } else {
                flash(`No match for "${target.query}"`);
            }
        } catch {
            flash('Could not search right now — try again in a moment.');
        } finally {
            setPlanning(false);
        }
    }

    const board = boards?.[mode] ?? null;
    const alt = board ? altSuggestion(board.departures) : null;
    const stop = board?.stop_name ?? 'your stop';
    const walk = board?.walk_min ?? null;

    return (
        <AppLayout>
            <Head title="Departures" />
            <div className="mx-auto w-full max-w-[680px] px-4 pt-6 pb-24 md:px-6">
                {destination ? (
                    <JourneyPlanner
                        key={`${destination.lat},${destination.lng},${destination.name}`}
                        destination={destination}
                        savedPlaces={savedPlaces}
                        onPlan={planTo}
                        onClose={() => setDestination(null)}
                    />
                ) : (
                    <>
                        <div className="mb-[18px] flex items-end justify-between gap-3.5">
                            <div className="min-w-0">
                                <h1 className="font-display text-3xl font-medium tracking-tight">
                                    Departures
                                </h1>
                                <p className="mt-1 text-[13.5px] text-muted-foreground">
                                    {board ? (
                                        <>
                                            Live from{' '}
                                            <strong className="font-semibold text-foreground">
                                                {stop}
                                            </strong>
                                            {walk != null && (
                                                <>
                                                    {' · '}
                                                    <span className="font-semibold text-cyan-h">
                                                        {walk} min walk from you
                                                    </span>
                                                </>
                                            )}
                                        </>
                                    ) : (
                                        'Finding your nearest stop…'
                                    )}
                                </p>
                            </div>
                            <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-success-soft px-3 py-1.5 font-mono text-xs font-semibold text-success">
                                <span className="size-[7px] animate-pulse rounded-full bg-success" />
                                <LiveClock />
                            </span>
                        </div>

                        <JourneyEntryCard
                            stop={stop}
                            savedPlaces={savedPlaces}
                            busy={planning}
                            onPlan={planTo}
                        />

                        <div className="mb-[18px] flex gap-2">
                            {TABS.map((tab) => (
                                <button
                                    key={tab.key}
                                    onClick={() => setMode(tab.key)}
                                    className={`cursor-pointer rounded-full border px-4 py-2 text-[13px] font-semibold transition-colors ${
                                        mode === tab.key
                                            ? 'border-foreground bg-foreground text-background'
                                            : 'border-border bg-card text-muted-foreground hover:border-primary'
                                    }`}
                                >
                                    {tab.label}
                                </button>
                            ))}
                        </div>

                        <Deferred data="boards" fallback={<BoardSkeleton />}>
                            <>
                                {board && board.departures.length > 0 ? (
                                    <DepartureBoard board={board} />
                                ) : (
                                    <EmptyBoard mode={mode} />
                                )}
                                {alt && <AltCard alt={alt} />}
                                <PlannedDisruptions
                                    disruptions={activeDisruptions}
                                />
                            </>
                        </Deferred>
                    </>
                )}
            </div>

            <FeedbackToast message={toast} />
        </AppLayout>
    );
}
