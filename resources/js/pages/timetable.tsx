import { Head, router, usePage, usePoll } from '@inertiajs/react';
import {
    IconAlertTriangle,
    IconArrowRight,
    IconBan,
    IconBus,
    IconHistory,
    IconTrain,
    IconTrainFilled,
} from '@tabler/icons-react';
import { useEffect, useRef, useState } from 'react';
import type { ComponentType, ReactNode } from 'react';
import { DestinationSearch } from '@/components/departures/destination-search';
import type { Suggestion } from '@/components/departures/destination-search';
import { TileField } from '@/components/departures/flip-digit';
import { JourneyPlanner } from '@/components/departures/journey-planner';
import type { SavedPlace } from '@/components/departures/journey-planner';
import { PlaceGlyph } from '@/components/departures/place-glyph';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { FeedbackToast } from '@/components/places/place-feedback-menu';
import { ICON_STROKE } from '@/constants/icons';
import type { ActiveTrip } from '@/hooks/use-active-trip';
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

type Boards = { all: Board; tram: Board; bus: Board; rail: Board };

type Mode = 'all' | 'tram' | 'bus' | 'rail';

type GeoResult = {
    name: string;
    address?: string | null;
    lat: number;
    lng: number;
};

/** A recent destination offered as a quick-launch pill. */
type RecentDestination = {
    name: string;
    area: string | null;
    lat: number;
    lng: number;
};

/** An explicitly chosen journey origin (null = current location). */
type Origin = { name: string; lat: number; lng: number } | null;

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

/** Great-circle distance between two points, in metres. */
function haversineM(
    lat1: number,
    lng1: number,
    lat2: number,
    lng2: number,
): number {
    const r = 6371000;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLng = ((lng2 - lng1) * Math.PI) / 180;
    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos((lat1 * Math.PI) / 180) *
            Math.cos((lat2 * Math.PI) / 180) *
            Math.sin(dLng / 2) ** 2;

    return r * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

type ModeTab = {
    key: Mode;
    label: string;
    Icon?: ComponentType<{
        size?: number;
        stroke?: number;
        className?: string;
    }>;
};

// Neutral mode icons: they inherit the tab's own text colour (light/dark safe).
// Tabler has one rail glyph, so Tram is the outline train and Train the filled
// one — distinct without relying on colour.
const MODE_TABS: ModeTab[] = [
    { key: 'all', label: 'All' },
    { key: 'tram', label: 'Tram', Icon: IconTrain },
    { key: 'bus', label: 'Bus', Icon: IconBus },
    { key: 'rail', label: 'Train', Icon: IconTrainFilled },
];

/**
 * Only offer a mode tab when that mode actually has departures near the user —
 * a bus-only stop shows no Tram/Train tab, an S-Bahn station gains a Train tab.
 * "All" is always present. Until the boards load, show just "All".
 */
function availableTabs(boards: Boards | undefined): ModeTab[] {
    return MODE_TABS.filter(
        (t) => t.key === 'all' || (boards?.[t.key]?.departures.length ?? 0) > 0,
    );
}

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

/** The line badge is a fixed-width column so the numbers line up; the terminus
 *  flexes to fill the row. */
const LINE_W = 3;

/** Glyph colour for a time by urgency — white upcoming, amber ≤3 min, green now. */
function timeInk(minutes: number): string {
    return minutes <= 0 ? '#46d17f' : minutes <= 3 ? '#ffbf3f' : '#eef2f7';
}

/** Lift a colour toward white until it's legible on the near-black board. */
function lift(hex: string, minLum = 130): string {
    const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());

    if (!m) {
        return '#e7ecf2';
    }

    const r = parseInt(m[1].slice(0, 2), 16);
    const g = parseInt(m[1].slice(2, 4), 16);
    const b = parseInt(m[1].slice(4, 6), 16);
    const lum = 0.2126 * r + 0.7152 * g + 0.0722 * b;

    if (lum >= minLum) {
        return `#${m[1].toLowerCase()}`;
    }

    const t = (minLum - lum) / (255 - lum);
    const hex2 = (c: number) =>
        Math.round(c + (255 - c) * t)
            .toString(16)
            .padStart(2, '0');

    return `#${hex2(r)}${hex2(g)}${hex2(b)}`;
}

/** The line's brand colour, lifted to stay readable as glyphs on the board. */
function lineInk(color: string): string {
    return lift(boardTint(color));
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

/** A ticking wall clock (HH:MM) — the board's "live" heartbeat. */
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
            })}
        </>
    );
}

/** A departure's clock time (HH:MM) = the board's reference moment + countdown. */
function clockAt(base: Date, minutes: number): string {
    const d = new Date(base.getTime());
    d.setSeconds(0, 0);
    d.setMinutes(d.getMinutes() + minutes);

    return d.toLocaleTimeString('de-DE', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** One upcoming departure — a line's row exploded down to a single time. */
type DepartureInstance = { dep: Departure; minute: number | null; key: string };

/**
 * A real airport/train board shows one row per departure, not per line. Explode
 * each line's upcoming minutes into an instance each, then sort soonest-first so
 * the lane reads as a single time-ordered list. Cancelled lines ride once at the
 * bottom.
 */
function explode(deps: Departure[]): DepartureInstance[] {
    const out: DepartureInstance[] = [];

    for (const dep of deps) {
        if (dep.cancelled) {
            out.push({
                dep,
                minute: null,
                key: `${dep.line}-${dep.destination}-x`,
            });

            continue;
        }

        for (const m of dep.minutes) {
            out.push({
                dep,
                minute: m,
                key: `${dep.line}-${dep.destination}-${m}`,
            });
        }
    }

    return out.sort((x, y) => {
        if (x.minute == null) {
            return 1;
        }

        if (y.minute == null) {
            return -1;
        }

        return x.minute - y.minute;
    });
}

function BoardRow({ dep, minute }: { dep: Departure; minute: number | null }) {
    // Absolute departure times are anchored to when the board loaded. (A long
    // open session drifts with cache age; the clean fix is a server-sent
    // generated-at stamp.)
    const [base] = useState(() => new Date());
    const cancelled = dep.cancelled;

    // Line + terminus on the left, this departure's clock time on the right —
    // NOW at the platform, --:-- when cancelled. One departure per row.
    const heroText = cancelled
        ? '--:--'
        : minute == null
          ? ''
          : minute <= 0
            ? 'NOW'
            : clockAt(base, minute);
    const heroTone = cancelled
        ? '#ff6b54'
        : minute == null
          ? '#69727f'
          : timeInk(minute);
    const colon = heroText.indexOf(':');

    return (
        <div className="board-row">
            <TileField
                text={dep.line}
                width={LINE_W}
                align="right"
                tone={lineInk(dep.color)}
                label={`Line ${dep.line}`}
            />
            <div className="board-dest">
                <TileField
                    text={(dep.destination || 'Cologne').toUpperCase()}
                    align="left"
                    tone="#eef2f7"
                    label={dep.destination || 'Cologne'}
                />
            </div>
            <span
                className="board-hero"
                aria-label={
                    cancelled
                        ? 'cancelled'
                        : heroText === 'NOW'
                          ? 'departing now'
                          : heroText
                            ? `departs at ${heroText}`
                            : 'no departures'
                }
            >
                {colon < 0 ? (
                    <TileField text={heroText} tone={heroTone} label="" />
                ) : (
                    <>
                        <TileField
                            text={heroText.slice(0, colon)}
                            tone={heroTone}
                            label=""
                        />
                        <span className="board-hero-colon">:</span>
                        <TileField
                            text={heroText.slice(colon + 1)}
                            tone={heroTone}
                            label=""
                        />
                    </>
                )}
            </span>
        </div>
    );
}

/** "Toward {top destinations}" for a direction lane. */
function laneLabel(rows: DepartureInstance[]): string {
    const dests = [
        ...new Set(rows.map((r) => r.dep.destination).filter(Boolean)),
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
    const kind = board.departures[0]?.type;
    const platform =
        kind === 'bus'
            ? 'KVB Bus'
            : kind === 'rail'
              ? 'S-Bahn / Regional'
              : 'KVB Stadtbahn';
    // Honest indicator: pulsing LIVE only when times come from a realtime
    // feed (TRIAS / GTFS-RT); schedule data says so instead of pretending.
    const live = board.source === 'trias_rt' || board.source === 'gtfs_rt';

    // Direction lanes (v4): only when GTFS matched both travel directions —
    // otherwise the board stays a flat list. Rows without a matched direction
    // ride along at the bottom of the grouped board.
    const dir0 = explode(board.departures.filter((d) => d.direction === 0));
    const dir1 = explode(board.departures.filter((d) => d.direction === 1));
    const ungrouped = explode(
        board.departures.filter((d) => d.direction == null),
    );
    const flat = explode(board.departures);
    const grouped = dir0.length > 0 && dir1.length > 0;

    const laneCap = Math.ceil(VISIBLE / 2);
    // How many departure rows the collapsed board shows — the toggle renders
    // whenever expanding reveals more (and stays visible to collapse again).
    const collapsedShown = grouped
        ? Math.min(dir0.length, laneCap) + Math.min(dir1.length, laneCap)
        : Math.min(flat.length, VISIBLE);
    const hidden = flat.length - collapsedShown;

    const lanes: Array<{ direction: number; rows: DepartureInstance[] }> =
        grouped
            ? [
                  {
                      direction: 0,
                      rows: expanded ? dir0 : dir0.slice(0, laneCap),
                  },
                  {
                      direction: 1,
                      rows: expanded ? dir1 : dir1.slice(0, laneCap),
                  },
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
                {live ? (
                    <span className="flex shrink-0 items-center gap-1.5 font-mono text-[11px] text-[#3ddc97]">
                        <span className="size-1.5 animate-pulse rounded-full bg-[#3ddc97]" />
                        LIVE <LiveClock />
                    </span>
                ) : (
                    <span className="flex shrink-0 items-center gap-1.5 font-mono text-[11px] text-[var(--bd-sub)]">
                        SCHEDULE <LiveClock />
                    </span>
                )}
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
                            {lane.rows.map((inst, i) => (
                                <BoardRow
                                    key={`${inst.key}-${i}`}
                                    dep={inst.dep}
                                    minute={inst.minute}
                                />
                            ))}
                        </div>
                    ))}
                    {expanded &&
                        ungrouped.map((inst, i) => (
                            <BoardRow
                                key={`u-${inst.key}-${i}`}
                                dep={inst.dep}
                                minute={inst.minute}
                            />
                        ))}
                </div>
            ) : (
                <div>
                    {(expanded ? flat : flat.slice(0, VISIBLE)).map(
                        (inst, i) => (
                            <BoardRow
                                key={`${inst.key}-${i}`}
                                dep={inst.dep}
                                minute={inst.minute}
                            />
                        ),
                    )}
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
        mode === 'tram'
            ? 'trams'
            : mode === 'bus'
              ? 'buses'
              : mode === 'rail'
                ? 'trains'
                : 'departures';

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
    recentDestinations,
    origin,
    onOriginChange,
    onUseCurrentLocation,
    locating,
    busy,
    onPlan,
}: {
    stop: string;
    savedPlaces: SavedPlace[];
    recentDestinations: RecentDestination[];
    origin: Origin;
    onOriginChange: (origin: Origin) => void;
    onUseCurrentLocation: () => void;
    locating: boolean;
    busy: boolean;
    onPlan: (target: Destination | { query: string }) => void;
}) {
    // Quick-launch pills: saved places first, recent destinations fill the
    // rest (deduplicated by name), four max.
    // Each pill carries its leading glyph (a saved place's emoji or category
    // icon, or the recent-history icon) plus the emoji to pass along when
    // planning (custom emoji only — a default place plans without one).
    const pills: Array<{
        key: string;
        glyph: ReactNode;
        planEmoji: string | undefined;
        name: string;
        lat: number;
        lng: number;
    }> = [];

    for (const place of savedPlaces) {
        pills.push({
            key: `s-${place.id}`,
            glyph: (
                <PlaceGlyph
                    emoji={place.emoji}
                    category={place.category}
                    size={14}
                />
            ),
            planEmoji: place.emoji ?? undefined,
            name: place.name,
            lat: place.lat,
            lng: place.lng,
        });
    }

    for (const recent of recentDestinations) {
        if (
            !pills.some(
                (p) => p.name.toLowerCase() === recent.name.toLowerCase(),
            )
        ) {
            pills.push({
                key: `r-${recent.name}`,
                glyph: (
                    <IconHistory
                        size={14}
                        stroke={ICON_STROKE}
                        className="shrink-0"
                    />
                ),
                planEmoji: undefined,
                name: recent.name,
                lat: recent.lat,
                lng: recent.lng,
            });
        }
    }

    return (
        <div className="mb-[18px] rounded-2xl border border-border bg-card p-4 shadow-sm">
            <div className="flex items-center gap-3">
                <button
                    type="button"
                    onClick={onUseCurrentLocation}
                    disabled={locating}
                    aria-label="Use my current location"
                    title="Use my current location"
                    className="-m-2 shrink-0 rounded-full p-2 transition hover:bg-cyan/10 disabled:opacity-50"
                >
                    <span
                        className={`block size-2.5 rounded-full border-[3px] border-cyan ${locating ? 'animate-pulse' : ''}`}
                    />
                </button>
                <DestinationSearch
                    key={locating ? 'locating' : (origin?.name ?? 'live')}
                    initial={locating ? 'Locating…' : (origin?.name ?? '')}
                    placeholder={`You · ${stop}`}
                    role="origin"
                    withCurrentLocation
                    onSelect={(s: Suggestion) => {
                        if (s.kind === 'current') {
                            onUseCurrentLocation();
                        } else {
                            onOriginChange({
                                name: s.name,
                                lat: s.lat,
                                lng: s.lng,
                            });
                        }
                    }}
                />
            </div>
            <div className="my-2 ml-1 h-px bg-border" />
            <div className="flex items-center gap-3">
                <span className="size-2.5 shrink-0 rotate-45 rounded-[50%_50%_50%_0] bg-primary" />
                <DestinationSearch
                    placeholder="Where to?"
                    role="destination"
                    onSelect={(s: Suggestion) =>
                        onPlan({
                            name: s.name,
                            emoji: s.emoji ?? undefined,
                            lat: s.lat,
                            lng: s.lng,
                        })
                    }
                    onSubmitFree={(query) => onPlan({ query })}
                    trailing={(submit) => (
                        <button
                            onClick={submit}
                            disabled={busy}
                            className="shrink-0 rounded-full bg-primary-soft px-3 py-1.5 font-mono text-[10.5px] font-semibold tracking-[0.06em] text-primary uppercase disabled:opacity-60"
                        >
                            {busy ? 'Planning…' : 'Plan trip →'}
                        </button>
                    )}
                />
            </div>
            {pills.length > 0 && (
                <div className="mt-3 flex gap-1.5 overflow-x-auto pl-[22px]">
                    {pills.slice(0, 4).map((pill) => (
                        <button
                            key={pill.key}
                            onClick={() =>
                                onPlan({
                                    name: pill.name,
                                    emoji: pill.planEmoji,
                                    lat: pill.lat,
                                    lng: pill.lng,
                                })
                            }
                            className="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-border bg-secondary px-2.5 py-1.5 text-[12.5px] font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary"
                        >
                            {pill.glyph}
                            {pill.name}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

/** The open plan encoded as URL query params (refresh-safe, shareable). */
function destinationToParams(d: Destination): Record<string, string> {
    const p: Record<string, string> = {
        to_name: d.name,
        to_lat: String(d.lat),
        to_lng: String(d.lng),
    };

    if (d.fromLat != null && d.fromLng != null) {
        p.from_lat = String(d.fromLat);
        p.from_lng = String(d.fromLng);

        if (d.fromName) {
            p.from_name = d.fromName;
        }
    }

    return p;
}

/** Restore an open plan from the URL on load — null when there's no plan. */
function parseDestinationFromUrl(url: string): Destination | null {
    const q = new URLSearchParams(url.split('?')[1] ?? '');
    const lat = q.get('to_lat');
    const lng = q.get('to_lng');
    const name = q.get('to_name');

    if (!lat || !lng || !name) {
        return null;
    }

    const dest: Destination = { name, lat: Number(lat), lng: Number(lng) };
    const fromLat = q.get('from_lat');
    const fromLng = q.get('from_lng');

    if (fromLat && fromLng) {
        dest.fromLat = Number(fromLat);
        dest.fromLng = Number(fromLng);
        dest.fromName = q.get('from_name') ?? undefined;
    }

    return dest;
}

/** Whether the URL marks the picked-route (live journey) detail as open. */
function routeDetailOpenFromUrl(url: string): boolean {
    return new URLSearchParams(url.split('?')[1] ?? '').get('view') === 'route';
}

/** True when this plan's destination is the one the live trip runs to. */
function destinationMatchesTrip(
    dest: Destination | null,
    trip: ActiveTrip | null,
): boolean {
    if (!dest || !trip) {
        return false;
    }

    return (
        Math.abs(dest.lat - trip.destination.lat) < 1e-4 &&
        Math.abs(dest.lng - trip.destination.lng) < 1e-4
    );
}

export default function Timetable() {
    const page = usePage<{
        boards?: Boards;
        savedPlaces?: SavedPlace[];
        recentDestinations?: RecentDestination[];
        activeDisruptions?: DisruptionItem[];
        activeTrip?: ActiveTrip | null;
    }>();
    const {
        boards,
        savedPlaces = [],
        recentDestinations = [],
        activeDisruptions = [],
        activeTrip = null,
    } = page.props;
    const [mode, setMode] = useState<Mode>('all');
    // The open plan and whether its route detail is showing both live in the
    // URL (refresh-safe, shareable) so a refresh restores the exact view
    // instead of jumping into the active trip.
    const [destination, setDestination] = useState<Destination | null>(() =>
        parseDestinationFromUrl(page.url),
    );
    const [routeDetailOpen, setRouteDetailOpen] = useState(() =>
        routeDetailOpenFromUrl(page.url),
    );
    const destSynced = useRef(false);

    // Reflect the open plan (and its route detail) into the URL (replace, no
    // server round-trip) so a refresh restores it; clearing it returns to the
    // plain board URL.
    useEffect(() => {
        if (!destSynced.current) {
            destSynced.current = true;

            return;
        }

        const params = destination
            ? new URLSearchParams(destinationToParams(destination))
            : new URLSearchParams();

        if (destination && routeDetailOpen) {
            params.set('view', 'route');
        }

        const search = params.toString();
        window.history.replaceState(
            window.history.state,
            '',
            `/timetable${search ? `?${search}` : ''}`,
        );
    }, [destination, routeDetailOpen]);
    // An explicitly chosen From (via the board's origin field); null = live location.
    const [origin, setOrigin] = useState<Origin>(null);
    const [planning, setPlanning] = useState(false);
    const [locating, setLocating] = useState(false);
    const [toast, setToast] = useState<string | null>(null);
    // Live-follow: on once the board is rooted at the device's real location.
    // followRef holds the position the board currently reflects; the board
    // re-roots when the user moves clear of it.
    const [following, setFollowing] = useState(false);
    const followRef = useRef<{ lat: number; lng: number } | null>(null);
    const lastFollowRef = useRef(0);

    // Keep the board genuinely live: re-fetch the deferred boards prop every
    // 30s (matches the server-side cache TTL). usePoll throttles in background
    // tabs.
    usePoll(30_000, { only: ['boards'], async: true });

    // During a partial reload Inertia momentarily reports the deferred prop as
    // undefined. Rendering through the last known value keeps the mounted
    // board in place, so a refresh UPDATES the rows (letting the split-flap
    // digits animate) instead of remounting them — and no skeleton flash.
    const lastBoardsRef = useRef<Boards | undefined>(undefined);

    if (boards !== undefined) {
        lastBoardsRef.current = boards;
    }

    const liveBoards = boards ?? lastBoardsRef.current;

    // Adaptive mode tabs: only offer modes that actually run near the user.
    const tabs = availableTabs(liveBoards);

    // If the selected mode drops out (the last tram left, or a poll re-rooted
    // the board at a bus-only stop), fall back to "All" so the view is never
    // stuck on an empty, tab-less mode.
    useEffect(() => {
        if (!liveBoards) {
            return;
        }

        const stillRunning =
            mode === 'all' || (liveBoards[mode]?.departures.length ?? 0) > 0;

        if (!stillRunning) {
            setMode('all');
        }
    }, [liveBoards, mode]);

    function flash(message: string) {
        setToast(message);
        window.setTimeout(() => setToast(null), 2600);
    }

    // Suggestions arrive with coordinates; raw text (Enter before the
    // dropdown loaded) resolves through the same suggest endpoint. An
    // explicitly chosen From rides along so the journey starts there.
    function withOrigin(dest: Destination): Destination {
        if (!origin || dest.fromLat != null) {
            return dest;
        }

        return {
            ...dest,
            fromLat: origin.lat,
            fromLng: origin.lng,
            fromName: origin.name,
        };
    }

    // Anchor the app to a point: persist it server-side (the board re-roots
    // from the confirmed location, valid 2h and beating inferred sources) and
    // mirror it in the From field. The endpoint reverse-geocodes the point;
    // returns the street name shown instead of "Current location".
    async function confirmLocation(lat: number, lng: number): Promise<string> {
        let name = 'Current location';

        try {
            const res = await fetch('/api/location/confirm', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ lat, lng }),
            });
            const data = (await res.json()) as { name?: string };

            if (data.name) {
                name = data.name;
            }
        } catch {
            // Best effort — the board keeps its last known anchor.
        }

        setOrigin({ name, lat, lng });
        router.reload({ only: ['boards'] });

        return name;
    }

    // Resolve the device's real GPS (browser permission prompt), anchor to it,
    // and start live-following so the board tracks the user as they move.
    // `then` re-plans an open journey from the fresh position.
    function requestDeviceLocation(
        then?: (coords: { lat: number; lng: number; name: string }) => void,
    ) {
        if (locating) {
            return;
        }

        if (!navigator.geolocation) {
            flash('Location isn’t available on this device.');

            return;
        }

        setLocating(true);

        navigator.geolocation.getCurrentPosition(
            async (pos) => {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                const name = await confirmLocation(lat, lng);

                setLocating(false);
                followRef.current = { lat, lng };
                lastFollowRef.current = Date.now();
                setFollowing(true);
                then?.({ lat, lng, name });
            },
            (err) => {
                setLocating(false);
                flash(
                    err.code === err.PERMISSION_DENIED
                        ? 'Location is blocked for this site. Allow it from the address-bar icon, then tap the dot again.'
                        : err.code === err.POSITION_UNAVAILABLE
                          ? 'Your device couldn’t find a location. On Mac, turn on System Settings → Privacy & Security → Location Services for your browser.'
                          : 'Locating took too long — please try again.',
                );
            },
            // Coarse (WiFi/IP) location is plenty to pick the nearest stop and
            // is far more reliable on desktop than GPS-grade high accuracy;
            // accept a recent cached fix so a retry is instant.
            { enableHighAccuracy: false, timeout: 15000, maximumAge: 300000 },
        );
    }

    // While following, re-root the board to the user's new nearest stop once
    // they move clear (>150 m) of the position it currently reflects. Runs only
    // with the board visible and the tab foregrounded; coarse GPS plus a 20s
    // floor keep it light. Picking an explicit From turns following off.
    useEffect(() => {
        if (!following || destination !== null || !navigator.geolocation) {
            return;
        }

        let watchId: number | null = null;

        const start = () => {
            if (watchId !== null || document.hidden) {
                return;
            }

            watchId = navigator.geolocation.watchPosition(
                (pos) => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    const from = followRef.current;
                    const now = Date.now();

                    if (
                        from &&
                        haversineM(from.lat, from.lng, lat, lng) < 150
                    ) {
                        return;
                    }

                    if (now - lastFollowRef.current < 20_000) {
                        return;
                    }

                    followRef.current = { lat, lng };
                    lastFollowRef.current = now;
                    void confirmLocation(lat, lng);
                },
                () => {
                    // Lost the fix — keep the last anchor.
                },
                {
                    enableHighAccuracy: false,
                    maximumAge: 30_000,
                    timeout: 20_000,
                },
            );
        };

        const stop = () => {
            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
        };

        const onVisibility = () => (document.hidden ? stop() : start());

        start();
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            stop();
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [following, destination]);

    async function planTo(target: Destination | { query: string }) {
        if ('lat' in target) {
            if (target.fromLat === null) {
                // Explicit "Current location" from the planner — get the real
                // device GPS, then replan this destination from it.
                requestDeviceLocation(({ lat, lng, name }) =>
                    setDestination({
                        ...target,
                        fromLat: lat,
                        fromLng: lng,
                        fromName: name,
                    }),
                );
            } else if (target.fromLat != null && target.fromLng != null) {
                // The planner chose an explicit origin — mirror it on the
                // board's From field so both stay coherent, and stop following
                // the device (the board is no longer rooted at "you").
                setFollowing(false);
                setOrigin({
                    name: target.fromName ?? 'Origin',
                    lat: target.fromLat,
                    lng: target.fromLng,
                });
                setDestination(target);
            } else {
                setDestination(withOrigin(target));
            }

            return;
        }

        setPlanning(true);

        try {
            const res = await fetch(
                `/api/journey/suggest?q=${encodeURIComponent(target.query)}`,
                { credentials: 'same-origin' },
            );
            const results = (await res.json()) as GeoResult[];

            if (results.length > 0) {
                const hit = results[0];

                setDestination(
                    withOrigin({
                        name: hit.name,
                        lat: hit.lat,
                        lng: hit.lng,
                    }),
                );
            } else {
                flash(`No match for "${target.query}"`);
            }
        } catch {
            flash('Could not search right now — try again in a moment.');
        } finally {
            setPlanning(false);
        }
    }

    const board = liveBoards?.[mode] ?? null;
    const alt = board ? altSuggestion(board.departures) : null;
    const stop = board?.stop_name ?? 'your stop';
    const walk = board?.walk_min ?? null;

    return (
        <AppLayout>
            <Head title="Departures" />
            <div className="mx-auto w-full max-w-[680px] px-4 pt-6 pb-24 md:px-6">
                {destination ? (
                    <JourneyPlanner
                        key={`${destination.lat},${destination.lng},${destination.name},${destination.fromLat ?? ''},${destination.fromLng ?? ''}`}
                        destination={destination}
                        savedPlaces={savedPlaces}
                        initialSelected={
                            routeDetailOpen &&
                            destinationMatchesTrip(destination, activeTrip)
                                ? (activeTrip?.journey ?? null)
                                : null
                        }
                        onDetailChange={setRouteDetailOpen}
                        onPlan={planTo}
                        onClose={() => {
                            setDestination(null);
                            setRouteDetailOpen(false);
                        }}
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
                            recentDestinations={recentDestinations}
                            origin={origin}
                            onOriginChange={(o) => {
                                // A hand-picked From means the board is no
                                // longer rooted at "you" — stop following.
                                setFollowing(false);
                                setOrigin(o);
                            }}
                            onUseCurrentLocation={requestDeviceLocation}
                            locating={locating}
                            busy={planning}
                            onPlan={planTo}
                        />

                        <div className="mb-[18px] flex gap-2">
                            {tabs.map((tab) => (
                                <button
                                    key={tab.key}
                                    onClick={() => setMode(tab.key)}
                                    className={`inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-4 py-2 text-[13px] font-semibold transition-colors ${
                                        mode === tab.key
                                            ? 'border-foreground bg-foreground text-background dark:border-border dark:bg-secondary dark:text-foreground'
                                            : 'border-border bg-card text-muted-foreground hover:border-primary'
                                    }`}
                                >
                                    {tab.Icon && (
                                        <tab.Icon
                                            size={16}
                                            stroke={ICON_STROKE}
                                            className="shrink-0"
                                        />
                                    )}
                                    {tab.label}
                                </button>
                            ))}
                        </div>

                        {liveBoards === undefined ? (
                            <BoardSkeleton />
                        ) : (
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
                        )}
                    </>
                )}
            </div>

            <FeedbackToast message={toast} />
        </AppLayout>
    );
}
