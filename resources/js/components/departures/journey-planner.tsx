import {
    IconArrowLeft,
    IconArrowsExchange,
    IconBolt,
    IconChevronRight,
    IconWalk,
} from '@tabler/icons-react';
import { useEffect, useMemo, useState } from 'react';
import { DestinationSearch } from '@/components/departures/destination-search';
import type { Suggestion } from '@/components/departures/destination-search';
import type {
    Destination,
    Journey,
    JourneyLeg,
    JourneyResponse,
} from '@/components/journey/take-me-there-sheet';
import { ICON_STROKE } from '@/constants/icons';

/** A saved place (Home / Work / pin) offered as a one-tap destination. */
export type SavedPlace = {
    id: number;
    name: string;
    category: string;
    emoji: string | null;
    lat: number;
    lng: number;
};

function isTransitLeg(leg: JourneyLeg): boolean {
    return leg.mode !== 'walk' && leg.mode !== 'bike';
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

function transferText(journey: Journey): string {
    if (journey.mode !== 'transit') {
        return 'Direct';
    }

    if (journey.transfers === 0) {
        return 'Direct';
    }

    return `${journey.transfers} change${journey.transfers > 1 ? 's' : ''}`;
}

function kindLabel(
    journey: Journey,
    fastestTransit: Journey | undefined,
): string {
    if (journey.mode === 'bike') {
        return 'By bike';
    }

    if (journey.mode === 'walk') {
        return 'On foot';
    }

    if (journey === fastestTransit) {
        return 'Fastest';
    }

    if (fastestTransit && journey.transfers < fastestTransit.transfers) {
        return 'Fewest changes';
    }

    return 'Alternative';
}

/** A row of compact leg chips — walk minutes, line badges, change markers. */
function LegChips({ legs }: { legs: JourneyLeg[] }) {
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {legs.map((leg, i) => {
                if (!isTransitLeg(leg)) {
                    return (
                        <span
                            key={i}
                            className="inline-flex items-center gap-1 font-mono text-[11.5px] text-muted-foreground"
                        >
                            <IconWalk size={14} stroke={ICON_STROKE} />
                            {leg.duration_min}′
                        </span>
                    );
                }

                const prevTransit = i > 0 && isTransitLeg(legs[i - 1]);

                return (
                    <span key={i} className="inline-flex items-center gap-1.5">
                        {prevTransit && (
                            <IconArrowsExchange
                                size={14}
                                stroke={ICON_STROKE}
                                className="text-text-3"
                            />
                        )}
                        <span
                            className="inline-flex h-6 min-w-6 items-center justify-center rounded-md px-1.5 font-mono text-xs font-bold text-white"
                            style={{
                                background: leg.color ?? 'var(--color-primary)',
                            }}
                        >
                            {leg.line ?? '?'}
                        </span>
                    </span>
                );
            })}
        </div>
    );
}

function RouteCard({
    journey,
    fastestTransit,
    onStart,
}: {
    journey: Journey;
    fastestTransit: Journey | undefined;
    onStart: () => void;
}) {
    return (
        <button
            onClick={onStart}
            className="animate-fade-up w-full rounded-2xl border border-border bg-card p-4 text-left shadow-sm transition-colors hover:border-primary md:px-[18px]"
        >
            <div className="mb-3 flex items-center gap-2.5">
                <span className="rounded-full bg-primary-soft px-2.5 py-1 font-mono text-[10.5px] font-semibold tracking-[0.04em] text-primary uppercase">
                    {kindLabel(journey, fastestTransit)}
                </span>
                <span className="text-[12.5px] text-text-3">
                    {transferText(journey)}
                </span>
                <span className="ml-auto font-display text-[22px] leading-none font-semibold">
                    {journey.duration_min}
                    <span className="font-mono text-[13px] text-text-3">
                        {' '}
                        min
                    </span>
                </span>
            </div>
            <div className="flex items-center gap-2">
                <LegChips legs={journey.legs} />
                <span className="ml-auto shrink-0 font-mono text-[12.5px] text-muted-foreground">
                    {journey.depart_time} → {journey.arrive_time}
                </span>
            </div>
        </button>
    );
}

type StepKind = 'walk' | 'board' | 'ride' | 'alight' | 'arrive';

type Step = {
    kind: StepKind;
    title: string;
    sub: string;
    /** When this step is (scheduled to be) reached — drives the live cursor. */
    at: number;
    line?: string | null;
    color?: string | null;
    station?: boolean;
};

/**
 * The journey flattened station by station: walk legs, then per transit leg a
 * board step, every ridden station, and the get-off — each stamped with its
 * scheduled time so the view can place a live NOW cursor without GPS.
 */
function stationSteps(journey: Journey, destName: string): Step[] {
    const steps: Step[] = [];

    for (const leg of journey.legs) {
        if (!isTransitLeg(leg)) {
            const verb = leg.mode === 'bike' ? 'Cycle' : 'Walk';

            steps.push({
                kind: 'walk',
                title: `${verb} to ${leg.to.name || destName}`,
                sub: `${leg.duration_min} min${leg.mode === 'bike' ? ' by bike' : ' on foot'}`,
                at: Date.parse(leg.depart_at),
            });

            continue;
        }

        steps.push({
            kind: 'board',
            title: `Board Line ${leg.line ?? ''}`,
            sub: leg.headsign
                ? `towards ${leg.headsign}`
                : `from ${leg.from.name}`,
            at: Date.parse(leg.depart_at),
            line: leg.line,
            color: leg.color,
        });

        for (const stop of leg.intermediate_stops ?? []) {
            steps.push({
                kind: 'ride',
                title: stop.name,
                sub: '',
                at: Date.parse(stop.arrive_at),
                line: leg.line,
                color: leg.color,
                station: true,
            });
        }

        steps.push({
            kind: 'alight',
            title: `Get off · ${leg.to.name}`,
            sub: 'your stop',
            at: Date.parse(leg.arrive_at),
            line: leg.line,
            color: leg.color,
            station: true,
        });
    }

    steps.push({
        kind: 'arrive',
        title: 'You’ve arrived',
        sub: destName,
        at: Date.parse(journey.arrive_at),
    });

    return steps;
}

/** The banner headline + subline for the upcoming step (mirrors the v4 mock). */
function bannerFor(
    steps: Step[],
    currentIdx: number,
    destName: string,
): { title: string; sub: string } {
    if (steps.length > 0 && currentIdx >= steps.length - 1) {
        return { title: 'You’ve arrived 🎉', sub: destName };
    }

    const next = steps[currentIdx + 1];

    if (!next) {
        return { title: 'Ready when you are', sub: '' };
    }

    switch (next.kind) {
        case 'alight':
            return {
                title: `Get off next · ${next.title.replace('Get off · ', '')}`,
                sub: 'Prepare to exit',
            };
        case 'ride': {
            const toExit =
                steps
                    .slice(currentIdx + 1)
                    .findIndex((s) => s.kind === 'alight') + 1;

            return {
                title: `Next stop · ${next.title}`,
                sub:
                    toExit > 0
                        ? `${toExit} stop${toExit === 1 ? '' : 's'} to your exit`
                        : '',
            };
        }
        case 'arrive':
            return { title: 'Almost there', sub: 'Final steps' };
        default:
            return { title: next.title, sub: next.sub };
    }
}

/** A step's scheduled time as HH:MM in Cologne time (matches the leg times). */
function hhmm(at: number): string {
    if (!Number.isFinite(at)) {
        return '';
    }

    return new Date(at).toLocaleTimeString('de-DE', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Europe/Berlin',
    });
}

/**
 * The chosen route as a live journey: a dark "Live · next" banner with a
 * progress bar, and a station-by-station timeline whose NOW cursor advances on
 * the schedule clock (no GPS feed yet — scheduled times, ticked live).
 */
function RouteDetail({
    journey,
    fromName,
    destName,
    onBack,
    onEnd,
}: {
    journey: Journey;
    fromName: string;
    destName: string;
    onBack: () => void;
    onEnd: () => void;
}) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), 20_000);

        return () => clearInterval(id);
    }, []);

    const steps = stationSteps(journey, destName);
    // The last step whose scheduled time has passed; -1 before departure.
    let currentIdx = -1;

    for (let i = 0; i < steps.length; i++) {
        if (steps[i].at <= now) {
            currentIdx = i;
        }
    }

    const banner = bannerFor(steps, currentIdx, destName);
    const cursorColor =
        (currentIdx >= 0 ? steps[currentIdx]?.color : steps[0]?.color) ??
        'var(--color-primary)';
    const pct =
        steps.length > 1 && currentIdx > 0
            ? Math.round((currentIdx / (steps.length - 1)) * 100)
            : 0;
    const beforeStart = currentIdx < 0;

    return (
        <div>
            <div className="mb-4 flex items-center gap-3">
                <button
                    onClick={onBack}
                    aria-label="Back to routes"
                    className="flex size-9 shrink-0 items-center justify-center rounded-full border border-border bg-card text-muted-foreground transition-colors hover:text-foreground"
                >
                    <IconArrowLeft size={17} stroke={ICON_STROKE} />
                </button>
                <span className="min-w-0 truncate font-display text-lg font-medium">
                    {fromName} → {destName}
                </span>
            </div>

            {/* Live NEXT banner */}
            <div
                className="relative mb-2 overflow-hidden rounded-2xl bg-foreground px-5 py-[18px] text-background"
                style={{ boxShadow: '0 10px 30px rgba(20,16,8,.28)' }}
            >
                <div className="flex items-center gap-3">
                    <span
                        className="size-2.5 shrink-0 animate-pulse rounded-full"
                        style={{
                            background: cursorColor,
                            boxShadow: '0 0 0 4px rgba(255,255,255,.16)',
                        }}
                    />
                    <div className="min-w-0 flex-1">
                        <div className="font-mono text-[10px] tracking-[0.14em] uppercase opacity-60">
                            {beforeStart
                                ? `Leave by ${journey.depart_time}`
                                : 'Live · next'}
                        </div>
                        <div className="mt-1 truncate font-display text-[21px] leading-tight font-semibold tracking-tight">
                            {banner.title}
                        </div>
                        {banner.sub && (
                            <div className="mt-1 truncate text-[13px] opacity-70">
                                {banner.sub}
                            </div>
                        )}
                    </div>
                    <div className="shrink-0 text-right">
                        <div className="font-mono text-[10px] tracking-[0.08em] uppercase opacity-60">
                            Arrive
                        </div>
                        <div className="mt-0.5 font-display text-xl font-semibold">
                            {journey.arrive_time}
                        </div>
                    </div>
                </div>
                <div className="mt-3.5 h-1 overflow-hidden rounded bg-white/20">
                    <div
                        className="h-full rounded transition-[width] duration-500"
                        style={{ width: `${pct}%`, background: cursorColor }}
                    />
                </div>
            </div>

            {/* Station-by-station timeline */}
            <div className="rounded-2xl border border-border bg-card px-[18px] py-2 shadow-sm">
                {steps.map((step, i) => {
                    const last = i === steps.length - 1;
                    const done = i < currentIdx;
                    const current = i === currentIdx;
                    const accent = step.color ?? 'var(--color-primary)';

                    return (
                        <div key={i} className="flex gap-3.5">
                            <div className="flex w-[22px] shrink-0 flex-col items-center">
                                <span
                                    className={`size-[13px] shrink-0 rounded-full border-2 ${current ? 'animate-pulse' : ''}`}
                                    style={{
                                        borderColor:
                                            done || current
                                                ? accent
                                                : 'var(--color-border)',
                                        background:
                                            done || current
                                                ? accent
                                                : 'transparent',
                                    }}
                                />
                                {!last && (
                                    <span
                                        className="min-h-[18px] w-0.5 flex-1"
                                        style={{
                                            background: done
                                                ? accent
                                                : 'var(--color-border)',
                                        }}
                                    />
                                )}
                            </div>
                            <div className="min-w-0 flex-1 pb-2">
                                <div className="flex items-center gap-2">
                                    {step.kind === 'board' && step.line && (
                                        <span
                                            className="inline-flex h-[22px] min-w-6 items-center justify-center rounded-md px-1.5 font-mono text-[11px] font-bold text-white"
                                            style={{ background: accent }}
                                        >
                                            {step.line}
                                        </span>
                                    )}
                                    <span
                                        className={`min-w-0 flex-1 truncate text-sm leading-snug ${
                                            done
                                                ? 'text-text-3'
                                                : current
                                                  ? 'font-semibold'
                                                  : 'text-muted-foreground'
                                        }`}
                                    >
                                        {step.title}
                                    </span>
                                    {current && (
                                        <span className="inline-flex shrink-0 items-center gap-1.5 font-mono text-[10px] font-semibold text-success">
                                            <span className="size-1.5 animate-pulse rounded-full bg-success" />
                                            NOW
                                        </span>
                                    )}
                                    {hhmm(step.at) && (
                                        <span
                                            className={`shrink-0 font-mono text-[11px] tabular-nums ${
                                                done
                                                    ? 'text-text-3'
                                                    : current
                                                      ? 'text-foreground'
                                                      : 'text-muted-foreground'
                                            }`}
                                        >
                                            {hhmm(step.at)}
                                        </span>
                                    )}
                                </div>
                                {step.sub && (
                                    <div className="mt-0.5 text-xs text-text-3">
                                        {step.sub}
                                    </div>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>

            <button
                onClick={onEnd}
                className="mt-3.5 w-full rounded-[13px] border border-border bg-card py-3 text-sm font-semibold text-muted-foreground transition-colors hover:border-danger hover:text-danger"
            >
                End journey
            </button>
        </div>
    );
}

function DegradedNotice({
    data,
}: {
    data: NonNullable<JourneyResponse['degraded']>;
}) {
    return (
        <div>
            <div className="mb-2 flex items-start gap-2 rounded-[9px] bg-secondary px-3 py-2.5 text-[13px] text-muted-foreground">
                <IconBolt
                    size={15}
                    stroke={ICON_STROKE}
                    className="mt-px shrink-0"
                />
                <span>
                    Live routing is unavailable right now — next departures from{' '}
                    {data.nearest_stop
                        ? `${data.nearest_stop.name}${data.nearest_stop.walk_min ? ` (${data.nearest_stop.walk_min} min walk)` : ''}`
                        : 'stops near you'}
                    .
                </span>
            </div>
            <div className="mb-3 overflow-hidden rounded-2xl border border-border">
                {(data.departures ?? []).map((dep, i) => (
                    <div
                        key={`${dep.line}-${i}`}
                        className="flex items-center gap-3 border-b border-border px-3.5 py-2.5 last:border-b-0"
                    >
                        <span className="flex h-6 min-w-6 items-center justify-center rounded-md bg-primary px-1.5 font-mono text-xs font-bold text-white">
                            {dep.line}
                        </span>
                        <span className="min-w-0 flex-1 truncate text-sm">
                            {dep.direction}
                        </span>
                        <span className="shrink-0 font-mono text-xs text-muted-foreground">
                            {(dep.departures ?? [])
                                .slice(0, 2)
                                .map((m) => `${m}'`)
                                .join(' · ')}
                        </span>
                    </div>
                ))}
            </div>
            <div className="flex gap-2">
                <a
                    href={data.deep_links.google}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex-1 rounded-[9px] border border-border bg-card py-2.5 text-center text-[13px] font-semibold text-primary transition-colors hover:bg-secondary"
                >
                    Open in Google Maps
                </a>
                <a
                    href={data.deep_links.kvb}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex-1 rounded-[9px] border border-border bg-card py-2.5 text-center text-[13px] font-semibold text-primary transition-colors hover:bg-secondary"
                >
                    Open in KVB
                </a>
            </div>
        </div>
    );
}

/**
 * The inline journey planner that replaces the board once a destination is
 * chosen: an editable From → To with saved shortcuts, live route options from
 * /api/journey, and a per-route leg-by-leg detail.
 */
export function JourneyPlanner({
    destination,
    savedPlaces,
    onPlan,
    onClose,
}: {
    destination: Destination;
    savedPlaces: SavedPlace[];
    onPlan: (target: Destination | { query: string }) => void;
    onClose: () => void;
}) {
    const [data, setData] = useState<JourneyResponse | null>(null);
    const [error, setError] = useState(false);
    const [selected, setSelected] = useState<number | null>(null);

    // The planner is re-mounted per destination (keyed in the page), so state
    // starts fresh on each new destination — the effect only fetches.
    useEffect(() => {
        let cancelled = false;

        const params = new URLSearchParams({
            to_lat: String(destination.lat),
            to_lng: String(destination.lng),
            to_name: destination.name,
        });

        if (destination.fromLat != null && destination.fromLng != null) {
            params.set('from_lat', String(destination.fromLat));
            params.set('from_lng', String(destination.fromLng));

            if (destination.fromName) {
                params.set('from_name', destination.fromName);
            }
        }

        fetch(`/api/journey?${params}`, { credentials: 'same-origin' })
            .then((res) => res.json())
            .then((json: JourneyResponse) => {
                if (!cancelled) {
                    setData(json);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setError(true);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [
        destination.lat,
        destination.lng,
        destination.name,
        destination.fromLat,
        destination.fromLng,
        destination.fromName,
    ]);

    const journeys = useMemo(() => {
        const list = data?.journeys ?? [];
        // Transit alternatives in departure order (the engine already prunes
        // absurd ones) plus the fastest direct mode for the trade-off glance.
        const transit = list
            .filter((j) => j.mode === 'transit')
            .sort((a, b) => Date.parse(a.depart_at) - Date.parse(b.depart_at))
            .slice(0, 4);
        const direct = list
            .filter((j) => j.mode !== 'transit')
            .sort((a, b) => a.duration_min - b.duration_min)
            .slice(0, 1);

        return [...transit, ...direct];
    }, [data]);

    const fastestTransit = journeys
        .filter((j) => j.mode === 'transit')
        .reduce<
            Journey | undefined
        >((best, j) => (best === undefined || j.duration_min < best.duration_min ? j : best), undefined);
    const fromName = data?.from.name ?? 'Your location';

    // Route detail (a route was picked)
    if (selected != null && journeys[selected]) {
        return (
            <RouteDetail
                journey={journeys[selected]}
                fromName={fromName}
                destName={destination.name}
                onBack={() => setSelected(null)}
                onEnd={onClose}
            />
        );
    }

    return (
        <div>
            <div className="mb-[18px] flex items-center gap-3">
                <button
                    onClick={onClose}
                    aria-label="Back to departures"
                    className="flex size-9 shrink-0 items-center justify-center rounded-full border border-border bg-card text-muted-foreground transition-colors hover:text-foreground"
                >
                    <IconArrowLeft size={17} stroke={ICON_STROKE} />
                </button>
                <span className="font-display text-xl font-medium">
                    Plan your journey
                </span>
            </div>

            {/* From → To — both rows searchable (stations, streets, saved places) */}
            <div className="mb-[18px] rounded-2xl border border-border bg-card p-2 shadow-sm">
                <div className="flex items-center gap-3 px-3.5 py-3">
                    <span className="size-2.5 shrink-0 rounded-full border-[3px] border-cyan" />
                    <DestinationSearch
                        key={`from-${fromName}`}
                        initial={fromName}
                        placeholder="From"
                        role="origin"
                        withCurrentLocation
                        onSelect={(s: Suggestion) =>
                            onPlan(
                                s.kind === 'current'
                                    ? {
                                          // Explicit reset — null (not absent)
                                          // so the page clears any board-level
                                          // origin instead of re-applying it.
                                          name: destination.name,
                                          emoji: destination.emoji,
                                          lat: destination.lat,
                                          lng: destination.lng,
                                          fromLat: null,
                                          fromLng: null,
                                          fromName: null,
                                      }
                                    : {
                                          ...destination,
                                          fromLat: s.lat,
                                          fromLng: s.lng,
                                          fromName: s.name,
                                      },
                            )
                        }
                    />
                </div>
                <div className="mx-3.5 ml-7 h-px bg-border" />
                <div className="flex items-center gap-3 px-3.5 py-3">
                    <span className="size-2.5 shrink-0 rotate-45 rounded-[50%_50%_50%_0] bg-primary" />
                    <DestinationSearch
                        initial={destination.name}
                        placeholder="Where to?"
                        role="destination"
                        onSelect={(s: Suggestion) =>
                            onPlan({
                                name: s.name,
                                emoji: s.emoji ?? undefined,
                                lat: s.lat,
                                lng: s.lng,
                                // keep the chosen origin when re-targeting
                                fromLat: destination.fromLat,
                                fromLng: destination.fromLng,
                                fromName: destination.fromName,
                            })
                        }
                        onSubmitFree={(query) => onPlan({ query })}
                    />
                </div>
            </div>

            {/* Saved & nearby */}
            {savedPlaces.length > 0 && (
                <>
                    <div className="mb-2.5 font-mono text-[11px] tracking-[0.1em] text-text-3 uppercase">
                        Saved &amp; nearby
                    </div>
                    <div className="mb-5 flex flex-wrap gap-2">
                        {savedPlaces.map((place) => (
                            <button
                                key={place.id}
                                onClick={() =>
                                    onPlan({
                                        name: place.name,
                                        emoji: placeEmoji(place),
                                        lat: place.lat,
                                        lng: place.lng,
                                    })
                                }
                                className={`inline-flex items-center gap-1.5 rounded-full border px-3.5 py-2 text-[13px] font-semibold transition-colors ${
                                    place.name === destination.name
                                        ? 'border-primary bg-primary-soft text-primary'
                                        : 'border-border bg-card text-foreground hover:border-primary'
                                }`}
                            >
                                <span>{placeEmoji(place)}</span>
                                {place.name}
                            </button>
                        ))}
                    </div>
                </>
            )}

            {/* Routes */}
            <div className="mb-2.5 font-mono text-[11px] tracking-[0.1em] text-text-3 uppercase">
                Routes to {destination.name}
            </div>

            {!data && !error && (
                <div className="flex flex-col gap-2.5">
                    {[1, 2].map((i) => (
                        <div
                            key={i}
                            className="h-[86px] animate-pulse rounded-2xl bg-secondary"
                        />
                    ))}
                </div>
            )}

            {error && (
                <div className="rounded-[9px] bg-danger-soft p-4 text-center text-sm text-danger">
                    Could not load routes. Try again in a moment.
                </div>
            )}

            {data?.source === 'degraded' && data.degraded && (
                <DegradedNotice data={data.degraded} />
            )}

            {data && data.source !== 'degraded' && journeys.length === 0 && (
                <div className="flex items-center justify-center gap-2 rounded-2xl bg-secondary px-4 py-4 text-center text-sm text-muted-foreground">
                    <IconWalk
                        size={16}
                        stroke={ICON_STROKE}
                        className="shrink-0"
                    />
                    No transit route found — it may be close enough to walk.
                </div>
            )}

            {data && data.source !== 'degraded' && journeys.length > 0 && (
                <div className="flex flex-col gap-3">
                    {journeys.map((journey, i) => (
                        <div key={i} className="flex items-center gap-2">
                            <div className="min-w-0 flex-1">
                                <RouteCard
                                    journey={journey}
                                    fastestTransit={fastestTransit}
                                    onStart={() => setSelected(i)}
                                />
                            </div>
                            <IconChevronRight
                                size={18}
                                stroke={ICON_STROKE}
                                className="shrink-0 text-text-3"
                            />
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
