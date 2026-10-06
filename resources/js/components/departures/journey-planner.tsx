import { usePage } from '@inertiajs/react';
import {
    IconAlertTriangle,
    IconArrowLeft,
    IconArrowsExchange,
    IconBolt,
    IconChevronDown,
    IconChevronRight,
    IconClock,
    IconBike,
    IconMap,
    IconRoute,
    IconPlayerPlayFilled,
    IconRun,
    IconTicket,
    IconWalk,
} from '@tabler/icons-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { DestinationSearch } from '@/components/departures/destination-search';
import type { Suggestion } from '@/components/departures/destination-search';
import {
    readJourneyPreview,
    writeJourneyPreview,
} from '@/components/departures/journey-preview';
import { PlaceGlyph } from '@/components/departures/place-glyph';
import { SaveJourneyButton } from '@/components/departures/saved-journeys';
import { TravelChoice } from '@/components/departures/travel-choice';
import { TravelHelp } from '@/components/departures/travel-help';
import {
    composerTravelHref,
    scheduledTravelDestination,
    travelRouteSignature,
} from '@/components/departures/travel-request';
import type { TravelDestination as Destination } from '@/components/departures/travel-request';
import { JourneyMap } from '@/components/journey/journey-map';
import {
    JourneySchedulePicker,
    scheduleDefault,
    toLocalDateTime,
} from '@/components/journey/journey-schedule-picker';
import type { JourneyPlanningMode } from '@/components/journey/journey-schedule-picker';
import type {
    FareAdvice,
    Journey,
    JourneyLeg,
    JourneyResponse,
} from '@/components/journey/take-me-there-sheet';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ICON_STROKE } from '@/constants/icons';
import { useActiveTrip } from '@/hooks/use-active-trip';
import type { TripPlace } from '@/hooks/use-active-trip';
import { useTripProgress } from '@/hooks/use-trip-progress';

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

function transferText(journey: Journey): string {
    if (journey.mode !== 'transit') {
        return 'Direct';
    }

    if (journey.transfers === 0) {
        return 'Direct';
    }

    return `${journey.transfers} change${journey.transfers > 1 ? 's' : ''}`;
}

/** The transit line sequence, e.g. "12>3" — identifies a distinct route. */
function routeSignature(journey: Journey): string {
    return travelRouteSignature(journey);
}

/**
 * Same route = same line sequence. Matched by lines (not exact departure time),
 * because the option list refreshes to the *next* departure of each route while
 * a trip you already boarded keeps its original — so timestamps drift apart.
 */
function sameRoute(a: Journey | null | undefined, b: Journey | null): boolean {
    if (!a || !b) {
        return false;
    }

    return routeSignature(a) === routeSignature(b);
}

/** Total walking (and cycling) minutes across a journey's legs. */
function walkMinutes(journey: Journey): number {
    return journey.legs
        .filter((l) => l.mode === 'walk' || l.mode === 'bike')
        .reduce((sum, l) => sum + l.duration_min, 0);
}

/** True when any line this journey rides has an active disruption. */
function isDisrupted(
    journey: Journey,
    disruptions: { lines: string[] }[],
): boolean {
    const lines = new Set(
        journey.legs
            .map((l) => l.line)
            .filter((l): l is string => l !== null && l !== ''),
    );

    return disruptions.some((d) => d.lines.some((line) => lines.has(line)));
}

function minBy<T>(items: T[], score: (item: T) => number): T {
    return items.reduce(
        (best, item) => (score(item) < score(best) ? item : best),
        items[0],
    );
}

/** A route plus the facts the list needs to sort, filter and badge it. */
type RouteItem = {
    journey: Journey;
    walkMin: number;
    disrupted: boolean;
};

/** Sort/filter chips over the option list — deterministic, no re-query. */
const FILTERS = [
    { key: 'best', label: 'Best' },
    { key: 'fastest', label: 'Fastest' },
    { key: 'fewest', label: 'Fewest changes' },
    { key: 'direct', label: 'Direct' },
    { key: 'leastwalk', label: 'Least walking' },
] as const;

type FilterKey = (typeof FILTERS)[number]['key'];

/** Transit modes offered as avoid/allow toggles, in display order. */
const MODE_ORDER = [
    { key: 'tram', label: 'Tram' },
    { key: 'subway', label: 'U-Bahn' },
    { key: 'rail', label: 'S-Bahn' },
    { key: 'bus', label: 'Bus' },
    { key: 'ferry', label: 'Ferry' },
] as const;

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
    label,
    walkMin,
    disrupted,
    highlight,
    onTrip,
    onStart,
}: {
    journey: Journey;
    label: string | null;
    walkMin: number;
    disrupted: boolean;
    highlight: boolean;
    onTrip: boolean;
    onStart: () => void;
}) {
    const overnight =
        journey.arrive_at.slice(0, 10) !== journey.depart_at.slice(0, 10);

    return (
        <button
            type="button"
            onClick={onStart}
            aria-label={`View ${journey.mode} route leaving ${journey.depart_time}, arriving ${journey.arrive_time}`}
            className={`w-full rounded-2xl border px-3.5 py-3 text-left transition-colors focus-visible:outline-2 focus-visible:outline-cyan active:bg-cyan/10 motion-reduce:transition-none ${onTrip ? 'border-success bg-success-soft' : highlight ? 'border-cyan/35 bg-cyan/5' : 'border-border bg-card'}`}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="min-w-0 text-base font-semibold tabular-nums">
                    {journey.depart_time}
                    <span className="px-1.5 text-muted-foreground">→</span>
                    {journey.arrive_time}
                    {overnight && (
                        <span className="ml-1 text-xs text-muted-foreground">
                            +1 day
                        </span>
                    )}
                </span>
                <span className="shrink-0 text-sm font-semibold">
                    {journey.duration_min}{' '}
                    <span className="font-normal text-muted-foreground">
                        min
                    </span>
                </span>
            </div>
            <div className="mt-2 flex items-center justify-between gap-2">
                <LegChips legs={journey.legs} />
                <IconChevronRight
                    size={16}
                    className="shrink-0 text-muted-foreground"
                />
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                {(onTrip || label) && (
                    <span
                        className={`font-semibold ${onTrip ? 'text-success' : 'text-cyan-h'}`}
                    >
                        {onTrip ? 'Your current trip' : label}
                    </span>
                )}
                <span>
                    {transferText(journey)}
                    {walkMin > 0 && ` · ${walkMin} min walk`}
                </span>
                {disrupted && (
                    <span className="inline-flex items-center gap-1 text-danger">
                        <IconAlertTriangle size={12} />
                        Service notice
                    </span>
                )}
            </div>
        </button>
    );
}

/**
 * The "leave now" option: a departure the walk-folded plan drops as unreachable,
 * shown anyway with the access walk it omits so the user can decide to jog for
 * it. Amber-flagged (a caution, not a recommendation) and always led with — it's
 * the soonest thing they might still make.
 */
function TightCard({
    journey,
    onStart,
}: {
    journey: Journey;
    onStart: () => void;
}) {
    const walk = journey.access_walk_min ?? 0;
    const stop = journey.access_stop_name ?? 'the stop';

    return (
        <button
            onClick={onStart}
            className="animate-fade-up w-full rounded-2xl border border-[#ffc24d8c] bg-[#ffc24d1a] p-4 text-left shadow-sm transition-colors hover:border-[#ffc24d] md:px-[18px] dark:border-[#ffc24d66] dark:bg-[#ffc24d14]"
        >
            <div className="mb-3 flex items-center gap-2.5">
                <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-[#ffc24d33] px-2.5 py-1 font-mono text-[10.5px] font-semibold tracking-[0.04em] text-[#9a6300] uppercase dark:text-[#f0b64a]">
                    <IconRun size={12} stroke={ICON_STROKE} />
                    Leave now
                </span>
                <span className="min-w-0 truncate text-[12.5px] text-text-3">
                    {walk} min walk to {stop} · tight
                </span>
                <span className="ml-auto shrink-0 font-sans text-[22px] leading-none font-semibold">
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
                    dep {journey.depart_time}
                </span>
            </div>
        </button>
    );
}

/** One-line fare summary for the trip (same across options — same OD). */
function FareLine({ fare }: { fare: FareAdvice }) {
    const price =
        fare.price_eur != null
            ? `€${fare.price_eur.toFixed(2)}${fare.estimated ? ' est.' : ''}`
            : null;

    return (
        <div className="mb-2.5 flex items-center gap-2 rounded-xl bg-secondary px-3.5 py-2.5 text-[13px] text-muted-foreground">
            <IconTicket size={15} stroke={ICON_STROKE} className="shrink-0" />
            <span className="min-w-0 truncate">{fare.label}</span>
            {price && (
                <span className="ml-auto shrink-0 font-semibold text-foreground">
                    {price}
                </span>
            )}
        </div>
    );
}

type StepKind = 'walk' | 'board' | 'ride' | 'alight' | 'arrive';

type Step = {
    kind: StepKind;
    title: string;
    sub: string;
    /** When this step is (scheduled to be) reached — drives the live cursor. */
    at: number;
    /** Where this step is — lets live GPS snap the cursor to it. */
    lat: number | null;
    lng: number | null;
    line?: string | null;
    color?: string | null;
    station?: boolean;
};

/**
 * The journey flattened station by station: walk legs, then per transit leg a
 * board step, every ridden station, and the get-off — each stamped with its
 * scheduled time and coordinates so the view can place a NOW cursor from the
 * schedule clock or a live GPS fix.
 */
function stationSteps(journey: Journey, destName: string): Step[] {
    const steps: Step[] = [];
    let lastTo: { lat: number; lng: number } | null = null;

    for (const leg of journey.legs) {
        lastTo = { lat: leg.to.lat, lng: leg.to.lng };

        if (!isTransitLeg(leg)) {
            const verb = leg.mode === 'bike' ? 'Cycle' : 'Walk';

            steps.push({
                kind: 'walk',
                title: `${verb} to ${leg.to.name || destName}`,
                sub: `${leg.duration_min} min${leg.mode === 'bike' ? ' by bike' : ' on foot'}`,
                at: Date.parse(leg.depart_at),
                lat: leg.to.lat,
                lng: leg.to.lng,
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
            lat: leg.from.lat,
            lng: leg.from.lng,
            line: leg.line,
            color: leg.color,
        });

        for (const stop of leg.intermediate_stops ?? []) {
            steps.push({
                kind: 'ride',
                title: stop.name,
                sub: '',
                at: Date.parse(stop.arrive_at),
                lat: stop.lat,
                lng: stop.lng,
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
            lat: leg.to.lat,
            lng: leg.to.lng,
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
        lat: lastTo?.lat ?? null,
        lng: lastTo?.lng ?? null,
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

type TimelineItem =
    | { type: 'step'; step: Step; i: number }
    | {
          type: 'rides';
          rides: { step: Step; i: number }[];
          minutes: number | null;
      };

/** Collapse a run of this many ridden stops (or more) into one toggle. */
const COLLAPSE_MIN_STOPS = 3;

/**
 * Fold long in-vehicle stretches into a single "N stops · X min" node so the
 * timeline stays scannable (Apple/Google Maps style); short rides stay inline.
 */
function groupTimeline(steps: Step[]): TimelineItem[] {
    const items: TimelineItem[] = [];

    for (let i = 0; i < steps.length; ) {
        if (steps[i].kind !== 'ride') {
            items.push({ type: 'step', step: steps[i], i });
            i++;
            continue;
        }

        const start = i;
        const rides: { step: Step; i: number }[] = [];

        while (i < steps.length && steps[i].kind === 'ride') {
            rides.push({ step: steps[i], i });
            i++;
        }

        if (rides.length < COLLAPSE_MIN_STOPS) {
            for (const r of rides) {
                items.push({ type: 'step', step: r.step, i: r.i });
            }

            continue;
        }

        const boardAt = steps[start - 1]?.at;
        const alightAt = steps[i]?.at;
        const minutes =
            boardAt !== undefined &&
            alightAt !== undefined &&
            Number.isFinite(boardAt) &&
            Number.isFinite(alightAt)
                ? Math.round((alightAt - boardAt) / 60000)
                : null;

        items.push({ type: 'rides', rides, minutes });
    }

    return items;
}

/** One node on the journey timeline — a stop, board, get-off, walk or arrival. */
function TimelineStep({
    step,
    done,
    current,
    last,
    accent,
}: {
    step: Step;
    done: boolean;
    current: boolean;
    last: boolean;
    accent: string;
}) {
    const time = hhmm(step.at);
    // Transit segments carry the line's colour (like Apple/Google); walking
    // segments stay neutral until travelled.
    const onVehicle = step.kind === 'board' || step.kind === 'ride';
    const lineColor = onVehicle || done ? accent : 'var(--color-border)';
    const dotBorder =
        done || current || onVehicle ? accent : 'var(--color-border)';

    return (
        <div className="flex gap-3.5">
            <div className="flex w-[22px] shrink-0 flex-col items-center">
                <span
                    className="size-[13px] shrink-0 rounded-full border-2"
                    style={{
                        borderColor: dotBorder,
                        background: done || current ? accent : 'transparent',
                    }}
                />
                {!last && (
                    <span
                        className="min-h-[18px] w-0.5 flex-1"
                        style={{ background: lineColor }}
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
                    {time && (
                        <span
                            className={`shrink-0 font-mono text-[11px] tabular-nums ${
                                done
                                    ? 'text-text-3'
                                    : current
                                      ? 'text-foreground'
                                      : 'text-muted-foreground'
                            }`}
                        >
                            {time}
                        </span>
                    )}
                </div>
                {step.sub && (
                    <div className="mt-0.5 text-xs text-text-3">{step.sub}</div>
                )}
            </div>
        </div>
    );
}

/**
 * A collapsed in-vehicle stretch: one "N stops · X min" node that expands to
 * the individual stops. Auto-expands while the live cursor is inside it, so you
 * always see where you are.
 */
function RidesGroup({
    rides,
    currentIdx,
    lastIndex,
    minutes,
}: {
    rides: { step: Step; i: number }[];
    currentIdx: number;
    lastIndex: number;
    minutes: number | null;
}) {
    const [open, setOpen] = useState(false);
    const accent = rides[0].step.color ?? 'var(--color-primary)';
    const firstI = rides[0].i;
    const lastI = rides[rides.length - 1].i;
    const containsCurrent = currentIdx >= firstI && currentIdx <= lastI;
    const expanded = open || containsCurrent;

    if (expanded) {
        return (
            <>
                {!containsCurrent && (
                    <button
                        type="button"
                        onClick={() => setOpen(false)}
                        className="flex w-full gap-3.5 text-left"
                    >
                        <div className="flex w-[22px] shrink-0 justify-center">
                            <span
                                className="w-0.5 self-stretch"
                                style={{ background: accent }}
                            />
                        </div>
                        <div className="flex items-center gap-1 py-1 text-[11px] font-medium text-text-3">
                            <IconChevronDown size={13} stroke={ICON_STROKE} />
                            Hide stops
                        </div>
                    </button>
                )}
                {rides.map((r) => (
                    <TimelineStep
                        key={r.i}
                        step={r.step}
                        done={r.i < currentIdx}
                        current={r.i === currentIdx}
                        last={r.i === lastIndex}
                        accent={accent}
                    />
                ))}
            </>
        );
    }

    return (
        <button
            type="button"
            onClick={() => setOpen(true)}
            className="flex w-full gap-3.5 text-left"
        >
            <div className="flex w-[22px] shrink-0 flex-col items-center">
                <span
                    className="size-[9px] shrink-0 rounded-full border-2"
                    style={{
                        borderColor: accent,
                        background: 'var(--color-card)',
                    }}
                />
                <span
                    className="min-h-[22px] w-0.5 flex-1"
                    style={{ background: accent }}
                />
            </div>
            <div className="min-w-0 flex-1 pb-2 text-[13px] text-muted-foreground">
                <span className="inline-flex items-center gap-1.5">
                    <IconChevronRight size={14} stroke={ICON_STROKE} />
                    <span className="font-medium">{rides.length} stops</span>
                    {minutes != null && (
                        <span className="text-text-3">· {minutes} min</span>
                    )}
                </span>
            </div>
        </button>
    );
}

/** Route preview and active guidance share the same stops, map and return context. */
function RouteDetail({
    journey,
    fromName,
    destName,
    active,
    hasActiveTrip,
    busy,
    onBack,
    onStart,
    onSwitch,
    onEnd,
    composerHref,
    onTimetable,
}: {
    journey: Journey;
    fromName: string;
    destName: string;
    /** True once this journey is the persisted, live trip (vs. a preview). */
    active: boolean;
    /** A trip is live — possibly a different route (drives Switch vs Start). */
    hasActiveTrip: boolean;
    busy: boolean;
    onBack: () => void;
    onStart: () => void;
    onSwitch: () => void;
    onEnd: () => void;
    composerHref: string;
    onTimetable?: (point: { name: string; lat: number; lng: number }) => void;
}) {
    const steps = useMemo(
        () => stationSteps(journey, destName),
        [journey, destName],
    );
    // Live position: GPS-driven for a started trip (foreground), else the
    // schedule clock. Only a real, active trip watches GPS — previews don't.
    const progress = useTripProgress(steps, active);
    const currentIdx = active ? progress.currentIdx : -1;
    const live = active && progress.live;
    const items = groupTimeline(steps);
    const [detailView, setDetailView] = useState<'steps' | 'map'>('steps');
    const first = journey.legs[0]?.from;
    const last = journey.legs.at(-1)?.to;

    // Buzz once when the exit is the next stop (only under a real GPS fix).
    const getOffNext = steps[currentIdx + 1]?.kind === 'alight';
    const alertedRef = useRef(-1);

    useEffect(() => {
        if (live && getOffNext && alertedRef.current !== currentIdx) {
            alertedRef.current = currentIdx;
            navigator.vibrate?.(300);
        }

        if (!getOffNext) {
            alertedRef.current = -1;
        }
    }, [live, getOffNext, currentIdx]);

    const banner = !active
        ? {
              title: `${journey.depart_time} – ${journey.arrive_time}`,
              sub: `${journey.duration_min} min · ${transferText(journey)}`,
          }
        : !live
          ? {
                title:
                    currentIdx < 0
                        ? `Leave by ${journey.depart_time}`
                        : 'Follow your itinerary',
                sub: 'Using timetable times · location unconfirmed',
            }
          : bannerFor(steps, currentIdx, destName);
    const cursorColor = getOffNext
        ? '#ffc24d'
        : ((currentIdx >= 0 ? steps[currentIdx]?.color : steps[0]?.color) ??
          'var(--color-primary)');
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
                    className="flex size-11 shrink-0 items-center justify-center rounded-full border border-border bg-card text-muted-foreground transition-colors hover:text-foreground"
                >
                    <IconArrowLeft size={17} stroke={ICON_STROKE} />
                </button>
                <span className="min-w-0 flex-1 truncate text-base font-semibold">
                    {destName}
                </span>
                <div
                    className="flex shrink-0 rounded-xl bg-secondary p-1"
                    role="group"
                    aria-label="Journey detail view"
                >
                    {(['steps', 'map'] as const).map((view) => (
                        <button
                            key={view}
                            type="button"
                            aria-pressed={detailView === view}
                            onClick={() => setDetailView(view)}
                            className={`flex min-h-11 items-center gap-1.5 rounded-lg px-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-cyan ${detailView === view ? 'bg-card text-cyan-h shadow-sm' : 'text-muted-foreground'}`}
                        >
                            {view === 'map' ? (
                                <IconMap size={17} />
                            ) : (
                                <IconRoute size={17} />
                            )}
                            {view === 'map' ? 'Map' : 'Steps'}
                        </button>
                    ))}
                </div>
            </div>

            <div
                className={`grid gap-5 ${detailView === 'map' ? 'lg:grid-cols-[minmax(320px,0.8fr)_minmax(0,1.2fr)]' : 'max-w-3xl'}`}
            >
                <div className="min-w-0">
                    {/* Preview remains a static itinerary until Start journey. */}
                    <div className="relative mb-2 overflow-hidden rounded-2xl border border-cyan/15 bg-cyan/10 px-4 py-4 text-foreground">
                        <div className="flex items-center gap-3">
                            <span
                                className={`size-2.5 shrink-0 rounded-full ${live ? 'motion-safe:animate-pulse' : ''}`}
                                style={{
                                    background: cursorColor,
                                    boxShadow:
                                        '0 0 0 4px rgba(255,255,255,.16)',
                                }}
                            />
                            <div className="min-w-0 flex-1">
                                <div className="font-mono text-[10px] tracking-[0.14em] uppercase opacity-60">
                                    {!active
                                        ? 'Route preview'
                                        : beforeStart
                                          ? `Leave by ${journey.depart_time}`
                                          : getOffNext
                                            ? 'Get off next'
                                            : live
                                              ? 'Live GPS · next'
                                              : active
                                                ? 'Scheduled · next'
                                                : 'Route preview'}
                                </div>
                                <div className="mt-1 truncate font-sans text-[21px] leading-tight font-semibold tracking-tight">
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
                                <div className="mt-0.5 font-sans text-xl font-semibold">
                                    {journey.arrive_time}
                                </div>
                            </div>
                        </div>
                        <div className="mt-3.5 h-1 overflow-hidden rounded bg-cyan/15">
                            <div
                                className="h-full rounded transition-[width] duration-500"
                                style={{
                                    width: `${pct}%`,
                                    background: cursorColor,
                                }}
                            />
                        </div>
                    </div>

                    <p className="mb-3 px-1 text-xs text-muted-foreground">
                        {live
                            ? 'Location guidance is active. Check signs and service updates as you travel.'
                            : 'Scheduled itinerary. Position and service changes are not confirmed here.'}
                    </p>
                    {/* Station-by-station timeline */}
                    <div className="rounded-2xl border border-border bg-card px-[18px] py-2 shadow-sm">
                        {items.map((item) =>
                            item.type === 'step' ? (
                                <div key={item.i}>
                                    <TimelineStep
                                        key={item.i}
                                        step={item.step}
                                        done={item.i < currentIdx}
                                        current={item.i === currentIdx}
                                        last={item.i === steps.length - 1}
                                        accent={
                                            item.step.color ??
                                            'var(--color-primary)'
                                        }
                                    />
                                    {item.step.kind === 'board' &&
                                        item.step.lat != null &&
                                        item.step.lng != null &&
                                        onTimetable && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    onTimetable({
                                                        name:
                                                            journey.legs.find(
                                                                (leg) =>
                                                                    isTransitLeg(
                                                                        leg,
                                                                    ) &&
                                                                    Date.parse(
                                                                        leg.depart_at,
                                                                    ) ===
                                                                        item
                                                                            .step
                                                                            .at,
                                                            )?.from.name ??
                                                            fromName,
                                                        lat: item.step.lat!,
                                                        lng: item.step.lng!,
                                                    })
                                                }
                                                className="mb-3 ml-9 inline-flex min-h-11 items-center gap-1.5 rounded-lg px-2 text-sm font-semibold text-cyan-h focus-visible:outline-2 focus-visible:outline-cyan"
                                            >
                                                <IconClock size={16} />
                                                Current stop timetable
                                                <IconChevronRight size={15} />
                                            </button>
                                        )}
                                </div>
                            ) : (
                                <RidesGroup
                                    key={`rides-${item.rides[0].i}`}
                                    rides={item.rides}
                                    currentIdx={currentIdx}
                                    lastIndex={steps.length - 1}
                                    minutes={item.minutes}
                                />
                            ),
                        )}
                    </div>

                    {active ? (
                        <button
                            onClick={onEnd}
                            disabled={busy}
                            className="mt-3.5 w-full rounded-[13px] border border-border bg-card py-3 text-sm font-semibold text-muted-foreground transition-colors hover:border-danger hover:text-danger disabled:opacity-60"
                        >
                            {busy ? 'Ending…' : 'End journey'}
                        </button>
                    ) : hasActiveTrip ? (
                        <div className="mt-3.5">
                            <button
                                onClick={onSwitch}
                                disabled={busy}
                                className="flex w-full items-center justify-center gap-2 rounded-[13px] border border-foreground bg-card py-3.5 text-sm font-semibold text-foreground transition-colors hover:bg-secondary disabled:opacity-60"
                            >
                                <IconArrowsExchange
                                    size={17}
                                    stroke={ICON_STROKE}
                                />
                                Switch to this route
                            </button>
                            <p className="mt-2 text-center text-xs text-text-3">
                                Replaces your current trip
                            </p>
                        </div>
                    ) : (
                        <button
                            onClick={onStart}
                            disabled={busy}
                            className="mt-3.5 flex w-full items-center justify-center gap-2 rounded-[13px] bg-primary py-3.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-primary/90 disabled:opacity-60"
                        >
                            <IconPlayerPlayFilled size={17} />
                            {busy ? 'Starting…' : 'Start journey'}
                        </button>
                    )}
                    <TravelHelp href={composerHref} />
                </div>
                {detailView === 'map' && last && (
                    <div className="order-first min-w-0 lg:sticky lg:top-24 lg:order-last lg:self-start">
                        <JourneyMap
                            key={`${journey.depart_at}-${journey.arrive_at}`}
                            legs={journey.legs}
                            origin={first ?? null}
                            destination={{ ...last, name: destName }}
                            position={progress.position}
                            className="h-[360px] lg:h-[min(620px,70dvh)]"
                        />
                        <p className="mt-2 text-xs text-muted-foreground">
                            {active
                                ? progress.position
                                    ? 'Your foreground device location is shown. Transit lines show the planned route, not live vehicle positions.'
                                    : 'Your location is unconfirmed. The planned route and steps remain available.'
                                : 'Route preview. Start your journey to follow your location while the app is open.'}
                        </p>
                    </div>
                )}
            </div>
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
 * Guard against accidentally abandoning a live trip: picking a different route
 * while one is running asks for an explicit confirm before it replaces it.
 */
function SwitchTripDialog({
    open,
    busy,
    error,
    fromDest,
    toDest,
    onCancel,
    onConfirm,
}: {
    open: boolean;
    busy: boolean;
    error?: string | null;
    fromDest: string;
    toDest: string;
    onCancel: () => void;
    onConfirm: () => void;
}) {
    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) {
                    onCancel();
                }
            }}
        >
            <DialogContent className="gap-3 rounded-2xl border-border bg-card sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle className="font-sans text-xl">
                        Switch your trip?
                    </DialogTitle>
                    <DialogDescription className="text-[13.5px] leading-relaxed">
                        {fromDest && fromDest !== toDest ? (
                            <>
                                This ends your current trip to{' '}
                                <span className="font-semibold text-foreground">
                                    {fromDest}
                                </span>{' '}
                                and starts this route to{' '}
                                <span className="font-semibold text-foreground">
                                    {toDest}
                                </span>
                                .
                            </>
                        ) : (
                            <>
                                This replaces the route on your current trip to{' '}
                                <span className="font-semibold text-foreground">
                                    {toDest}
                                </span>
                                .
                            </>
                        )}
                    </DialogDescription>
                </DialogHeader>
                {error && (
                    <p role="alert" className="text-sm text-danger">
                        {error}
                    </p>
                )}
                <DialogFooter className="mt-1 gap-2">
                    <button
                        type="button"
                        onClick={onCancel}
                        disabled={busy}
                        className="rounded-full border border-border bg-card px-5 py-2.5 text-sm font-semibold text-muted-foreground transition-colors hover:text-foreground disabled:opacity-60"
                    >
                        Keep current
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={busy}
                        className="rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary/90 disabled:opacity-60"
                    >
                        {busy ? 'Switching…' : 'Switch trip'}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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
    initialSelected,
    restoreDetail = false,
    onDetailChange,
    onRequestChange,
    onPlan,
    onClose,
    onTimetable,
}: {
    destination: Destination;
    savedPlaces: SavedPlace[];
    /** The persisted trip's journey, so a live trip reopens straight to detail. */
    initialSelected?: Journey | null;
    restoreDetail?: boolean;
    /** Report whether the picked-route detail is open, so the page can keep it in the URL. */
    onDetailChange?: (open: boolean) => void;
    onRequestChange?: (destination: Destination) => void;
    onPlan: (target: Destination | { query: string }) => void;
    onClose: () => void;
    onTimetable?: (point: { name: string; lat: number; lng: number }) => void;
}) {
    const userId = usePage().props.auth.user.id;
    const { activeTrip, start, end, busy, error: tripError } = useActiveTrip();
    const [data, setData] = useState<JourneyResponse | null>(null);
    const [error, setError] = useState(false);
    const [needsOrigin, setNeedsOrigin] = useState(false);
    const [selected, setSelected] = useState<Journey | null>(
        () =>
            initialSelected ??
            (restoreDetail && typeof window !== 'undefined'
                ? readJourneyPreview(window.sessionStorage, userId, destination)
                : null),
    );
    const [filter, setFilter] = useState<FilterKey>('best');
    // Open the "switch trip" confirm before replacing a running trip.
    const [switchOpen, setSwitchOpen] = useState(false);
    // Plan for later: 'now' | 'depart' (leave at) | 'arrive' (be there by).
    const [timeMode, setTimeMode] = useState<JourneyPlanningMode>(
        destination.arriveBy
            ? 'arrive'
            : destination.departAt
              ? 'later'
              : 'now',
    );
    const [scheduledAt, setScheduledAt] = useState(() =>
        scheduleDefault(destination.arriveBy ?? destination.departAt),
    );
    const [routeMode, setRouteMode] = useState<
        'all' | 'transit' | 'bike' | 'walk'
    >(destination.preferredMode ?? 'all');
    const [retry, setRetry] = useState(0);
    const [settledRequest, setSettledRequest] = useState('');
    const [editing, setEditing] = useState(false);
    const [unresolvedInputs, setUnresolvedInputs] = useState({
        origin: false,
        destination: false,
    });
    const unresolved = unresolvedInputs.origin || unresolvedInputs.destination;
    // Client-side mode filter — hide options that ride an excluded mode.
    const [excludedModes, setExcludedModes] = useState<Set<string>>(new Set());
    // "Show more options" — server also routes via interchanges for extra
    // combinations (slower, so an explicit opt-in with a loading state).
    const [showMore, setShowMore] = useState(false);
    const [moreLoading, setMoreLoading] = useState(false);

    // Mirror the route-detail view up so the page keeps it in the URL — a
    // picked route means the live journey detail is showing (refresh-safe).
    useEffect(() => {
        onDetailChange?.(selected !== null);
    }, [selected, onDetailChange]);

    const timeQuery = timeMode === 'now' ? '' : toLocalDateTime(scheduledAt);
    const arriveBy = timeMode === 'arrive';
    const requestKey = JSON.stringify([
        destination.lat,
        destination.lng,
        destination.name,
        destination.fromLat,
        destination.fromLng,
        destination.fromName,
        timeQuery,
        arriveBy,
        showMore,
        retry,
    ]);
    const loading = settledRequest !== requestKey;
    const currentDestination = useMemo(
        () => ({
            ...scheduledTravelDestination(
                destination,
                timeMode === 'later' ? 'depart' : timeMode,
                timeQuery,
            ),
            preferredMode: routeMode === 'all' ? undefined : routeMode,
        }),
        [destination, timeMode, timeQuery, routeMode],
    );
    const handoffDestination =
        !loading &&
        !error &&
        data?.from &&
        Number.isFinite(data.from.lat) &&
        Number.isFinite(data.from.lng)
            ? {
                  ...currentDestination,
                  fromLat: currentDestination.fromLat ?? data.from.lat,
                  fromLng: currentDestination.fromLng ?? data.from.lng,
                  fromName: currentDestination.fromName ?? data.from.name,
              }
            : currentDestination;
    useEffect(() => {
        writeJourneyPreview(
            sessionStorage,
            userId,
            currentDestination,
            selected,
        );
    }, [userId, currentDestination, selected]);
    const requestHref = composerTravelHref(handoffDestination, {
        schedule: timeMode === 'later' ? 'depart' : timeMode,
        requestedDate: timeQuery.slice(0, 10) || undefined,
        requestedTime: timeQuery.slice(11, 16) || undefined,
    });

    useEffect(() => {
        // Keep schedule in the page URL without remounting the current route list.
        if (
            JSON.stringify(currentDestination) !== JSON.stringify(destination)
        ) {
            onRequestChange?.(currentDestination);
        }
    }, [currentDestination, destination, onRequestChange]);

    // The planner is re-mounted per destination (keyed in the page), so state
    // starts fresh on each new destination — the effect fetches, and re-fetches
    // when the depart/arrive time changes (a server-side re-plan).
    useEffect(() => {
        let cancelled = false;
        // Abort the previous request when the query changes (e.g. the user
        // nudges hour then minute) so an earlier, slower response can't land
        // after a later one and show stale/after-target results.
        const controller = new AbortController();

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

        if (timeQuery) {
            params.set('depart_at', timeQuery);

            if (arriveBy) {
                params.set('arrive_by', '1');
            }
        }

        if (showMore) {
            params.set('more', '1');
        }

        fetch(`/api/journey?${params}`, {
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (res) => {
                if (!res.ok) {
                    const problem = await res.json().catch(() => ({}));

                    throw new Error(
                        problem.code === 'location_required'
                            ? 'location_required'
                            : 'routes_unavailable',
                    );
                }

                return res.json();
            })
            .then((json: JourneyResponse) => {
                if (!cancelled) {
                    setData(json);
                    setSettledRequest(requestKey);
                    setError(false);
                    setMoreLoading(false);
                }
            })
            .catch((err: unknown) => {
                // An aborted request is expected on re-query — not an error.
                if (!cancelled && (err as Error)?.name !== 'AbortError') {
                    setError(true);
                    const missingOrigin =
                        (err as Error)?.message === 'location_required';
                    setNeedsOrigin(missingOrigin);

                    if (missingOrigin) {
                        setEditing(true);
                    }

                    setSettledRequest(requestKey);
                    setMoreLoading(false);
                }
            });

        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [
        destination.lat,
        destination.lng,
        destination.name,
        destination.fromLat,
        destination.fromLng,
        destination.fromName,
        timeQuery,
        arriveBy,
        showMore,
        retry,
        requestKey,
    ]);

    // The engine hands us a Pareto set (arrival time × transfers × walking).
    // We label those trade-offs deterministically — one balanced "Best" plus
    // the extremes that beat it on a dimension — and lead with Best, like the
    // best transit apps, instead of a departure-ordered soup of "Alternatives".
    // The full labelled list, best-first — no cap, so every Pareto trade-off
    // (direct-with-a-longer-walk vs. an-extra-change-with-less) is available;
    // the filter chips sort/subset it below.
    const items = useMemo<RouteItem[]>(() => {
        // The "leave now" option is handled on its own (below) — keep it out of
        // the ranked, deduped, filterable pool so it always shows first as-is.
        const list = (data?.journeys ?? []).filter(
            (j) => !j.tight && (routeMode === 'all' || j.mode === routeMode),
        );
        const disruptions = data?.disruptions ?? [];
        // One card per distinct line-combination so the list reads as different
        // routes, not the same one repeated every ten minutes (MOTIS returns
        // several departures of each). Keep the departure that best fits the
        // search: soonest for leave-now/depart-at, but the LATEST for "arrive
        // by" (it arrives closest to the requested time).
        const bySignature = new Map<string, Journey>();

        for (const j of list.filter((x) => x.mode === 'transit')) {
            const s = routeSignature(j);
            const prev = bySignature.get(s);
            const better =
                !prev ||
                (arriveBy
                    ? Date.parse(j.depart_at) > Date.parse(prev.depart_at)
                    : Date.parse(j.depart_at) < Date.parse(prev.depart_at));

            if (better) {
                bySignature.set(s, j);
            }
        }

        const transit = [...bySignature.values()];
        const direct = list
            .filter((j) => j.mode !== 'transit')
            .sort((a, b) => a.duration_min - b.duration_min)
            .slice(0, 2);

        const toItem = (j: Journey): RouteItem => ({
            journey: j,
            walkMin:
                j.mode === 'transit'
                    ? walkMinutes(j)
                    : j.mode === 'bike'
                      ? 0
                      : j.duration_min,
            disrupted:
                j.mode === 'transit' ? isDisrupted(j, disruptions) : false,
        });

        if (transit.length === 0) {
            return direct.map(toItem);
        }

        const arriveMs = (j: Journey) => Date.parse(j.arrive_at);
        // Anchor on the target-appropriate end: for "arrive by" the LATEST
        // arrival (closest to the requested time, least dead time), otherwise
        // the earliest (soonest there). Distance from that anchor, penalised for
        // each change and for walking — so Best only trades time for a
        // materially simpler trip.
        const anchor = arriveBy
            ? Math.max(...transit.map(arriveMs))
            : Math.min(...transit.map(arriveMs));
        const score = (j: Journey) =>
            Math.abs(arriveMs(j) - anchor) / 60000 +
            j.transfers * 5 +
            walkMinutes(j) * 1.2;

        const best = minBy(transit, score);
        const rest = transit
            .filter((j) => j !== best)
            .sort((a, b) =>
                arriveBy
                    ? arriveMs(b) - arriveMs(a)
                    : arriveMs(a) - arriveMs(b),
            );

        return [best, ...rest, ...direct].map(toItem);
    }, [data, arriveBy, routeMode]);

    // The single "leave now" option (if the backend flagged one), kept out of the
    // ranked list so filters and dedup never touch it — always led with, as-is.
    const tight = useMemo(
        () => (data?.journeys ?? []).find((j) => j.tight) ?? null,
        [data],
    );

    // Transit modes present in the current results — drives the toggles.
    const availableModes = useMemo(() => {
        const present = new Set<string>();
        (data?.journeys ?? []).forEach((j) =>
            j.legs.forEach((l) => {
                if (l.mode !== 'walk' && l.mode !== 'bike') {
                    present.add(l.mode);
                }
            }),
        );

        return MODE_ORDER.filter((m) => present.has(m.key));
    }, [data]);

    // Mode toggles hide options riding an excluded mode; the chip then
    // re-sorts/subsets what's left. Sort filters rank every option (so a fast
    // bike can top "Fastest"); Best keeps best-first; Direct is the no-change
    // subset.
    const visible = useMemo<RouteItem[]>(() => {
        const arr = (it: RouteItem) => Date.parse(it.journey.arrive_at);
        const pool =
            excludedModes.size === 0
                ? items
                : items.filter(
                      (it) =>
                          !it.journey.legs.some((l) =>
                              excludedModes.has(l.mode),
                          ),
                  );

        if (filter === 'fastest') {
            return [...pool].sort(
                (a, b) =>
                    a.journey.duration_min - b.journey.duration_min ||
                    arr(a) - arr(b),
            );
        }

        if (filter === 'fewest') {
            return [...pool].sort(
                (a, b) =>
                    a.journey.transfers - b.journey.transfers ||
                    arr(a) - arr(b),
            );
        }

        if (filter === 'leastwalk') {
            return [...pool].sort(
                (a, b) => a.walkMin - b.walkMin || arr(a) - arr(b),
            );
        }

        if (filter === 'direct') {
            return pool
                .filter((it) => it.journey.transfers === 0)
                .sort(
                    (a, b) => a.journey.duration_min - b.journey.duration_min,
                );
        }

        return pool;
    }, [items, filter, excludedModes]);

    const fromName =
        destination.fromName ?? data?.from.name ?? 'Your starting point';

    // Reset the origin to the live current location. Null (not absent) so the
    // page clears any board-level origin instead of re-applying it — shared by
    // the cyan origin dot and the "Current location" suggestion row.
    const resetOriginToLocation = () =>
        onPlan({
            ...currentDestination,
            previousId: undefined,
            previousEnd: undefined,
            fromLat: null,
            fromLng: null,
            fromName: null,
        });

    // A live trip counts as "this route" only when it runs to the same place
    // (the planner's current destination) and rides the same line sequence.
    const tripHere =
        !!activeTrip &&
        Math.abs(activeTrip.destination.lat - destination.lat) < 1e-4 &&
        Math.abs(activeTrip.destination.lng - destination.lng) < 1e-4;
    const isTripRoute = (j: Journey): boolean =>
        tripHere && sameRoute(activeTrip?.journey, j);

    // Route detail (a route was picked). Starting persists it as the live trip;
    // ending clears the session and returns to the board. Picking a different
    // route while a trip runs asks to confirm before replacing it.
    if (selected) {
        const isActive = isTripRoute(selected);
        const hasActiveTrip = !!activeTrip;

        const startJourney = () => {
            const dest: TripPlace = {
                name: destination.name,
                lat: destination.lat,
                lng: destination.lng,
                emoji: destination.emoji ?? null,
            };
            const origin: TripPlace | null = data?.from
                ? {
                      name: data.from.name,
                      lat: data.from.lat,
                      lng: data.from.lng,
                  }
                : null;

            return start(selected, dest, origin);
        };

        return (
            <>
                {tripError && !switchOpen && (
                    <p
                        role="alert"
                        className="mb-3 rounded-xl bg-danger-soft p-3 text-sm text-danger"
                    >
                        {tripError}
                    </p>
                )}
                <RouteDetail
                    journey={selected}
                    onTimetable={onTimetable}
                    fromName={fromName}
                    destName={destination.name}
                    active={isActive}
                    hasActiveTrip={hasActiveTrip}
                    busy={busy}
                    onBack={() => setSelected(null)}
                    onStart={startJourney}
                    onSwitch={() => setSwitchOpen(true)}
                    onEnd={() =>
                        end().then((saved) => {
                            if (saved) {
                                onClose();
                            }
                        })
                    }
                    composerHref={composerTravelHref(
                        handoffDestination,
                        {
                            schedule:
                                timeMode === 'later' ? 'depart' : timeMode,
                            requestedDate: timeQuery.slice(0, 10) || undefined,
                            requestedTime: timeQuery.slice(11, 16) || undefined,
                        },
                        selected,
                    )}
                />
                <SwitchTripDialog
                    open={switchOpen}
                    busy={busy}
                    error={tripError}
                    fromDest={activeTrip?.destination.name ?? ''}
                    toDest={destination.name}
                    onCancel={() => setSwitchOpen(false)}
                    onConfirm={() => {
                        startJourney().then((saved) => {
                            if (saved) {
                                setSwitchOpen(false);
                            }
                        });
                    }}
                />
            </>
        );
    }

    return (
        <div
            className="grid gap-5 lg:grid-cols-[minmax(320px,0.8fr)_minmax(0,1.2fr)] lg:gap-8"
            data-travel-workspace
        >
            <section className="min-w-0 lg:sticky lg:top-24 lg:self-start">
                <div className="mb-3 flex items-center gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Choose another trip"
                        className="grid size-11 shrink-0 place-items-center rounded-xl bg-card text-muted-foreground focus-visible:outline-2 focus-visible:outline-cyan"
                    >
                        <IconArrowLeft size={18} stroke={ICON_STROKE} />
                    </button>
                    <button
                        type="button"
                        onClick={() => setEditing(!editing)}
                        aria-expanded={editing}
                        className="min-w-0 flex-1 rounded-xl px-2 py-1 text-left focus-visible:outline-2 focus-visible:outline-cyan"
                    >
                        <span className="block truncate text-xs text-muted-foreground">
                            From {fromName}
                        </span>
                        <span className="flex items-center gap-2 text-sm font-semibold">
                            <span className="truncate">
                                To {destination.name}
                            </span>
                            <IconChevronDown size={16} className="shrink-0" />
                        </span>
                    </button>
                </div>
                {destination.activityId && (
                    <p className="mb-3 flex items-start gap-2 rounded-xl bg-cyan/10 px-3 py-2 text-sm text-cyan-h">
                        <IconClock size={16} className="mt-0.5 shrink-0" />
                        <span>
                            For {destination.backLabel ?? 'your activity'}
                            {destination.planDate
                                ? ` · ${new Date(destination.planDate + 'T12:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}`
                                : ''}
                        </span>
                    </p>
                )}

                {/* Expand the connected fields only while changing the trip. */}
                <div
                    className={`${editing ? '' : 'hidden lg:block'} mb-[18px] rounded-2xl border border-border bg-card p-2 shadow-sm`}
                >
                    <div className="flex items-center gap-3 px-3.5 py-3">
                        <button
                            type="button"
                            onClick={resetOriginToLocation}
                            aria-label="Use my current location"
                            title="Use my current location"
                            className="-m-2 shrink-0 rounded-full p-2 transition hover:bg-cyan/10"
                        >
                            <span className="block size-2.5 rounded-full border-[3px] border-cyan" />
                        </button>
                        <DestinationSearch
                            key={`from-${fromName}`}
                            onUnresolvedChange={(origin) =>
                                setUnresolvedInputs((current) => ({
                                    ...current,
                                    origin,
                                }))
                            }
                            initial={fromName}
                            placeholder="From"
                            role="origin"
                            bias={
                                handoffDestination.fromLat != null &&
                                handoffDestination.fromLng != null
                                    ? {
                                          lat: handoffDestination.fromLat,
                                          lng: handoffDestination.fromLng,
                                      }
                                    : null
                            }
                            withCurrentLocation
                            onSelect={(s: Suggestion) => {
                                if (s.kind === 'current') {
                                    resetOriginToLocation();
                                } else {
                                    onPlan({
                                        ...currentDestination,
                                        previousId: undefined,
                                        previousEnd: undefined,
                                        fromLat: s.lat,
                                        fromLng: s.lng,
                                        fromName: s.name,
                                    });
                                }
                            }}
                        />
                    </div>
                    <div className="mx-3.5 ml-7 h-px bg-border" />
                    <div className="flex items-center gap-3 px-3.5 py-3">
                        <span className="size-2.5 shrink-0 rotate-45 rounded-[50%_50%_50%_0] bg-primary" />
                        <DestinationSearch
                            initial={destination.name}
                            onUnresolvedChange={(destination) =>
                                setUnresolvedInputs((current) => ({
                                    ...current,
                                    destination,
                                }))
                            }
                            placeholder="Where to?"
                            role="destination"
                            bias={
                                handoffDestination.fromLat != null &&
                                handoffDestination.fromLng != null
                                    ? {
                                          lat: handoffDestination.fromLat,
                                          lng: handoffDestination.fromLng,
                                      }
                                    : null
                            }
                            onSelect={(s: Suggestion) =>
                                onPlan({
                                    ...currentDestination,
                                    activityId: undefined,
                                    planDate: undefined,
                                    backLabel: undefined,
                                    destinationId: undefined,
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
                {editing && savedPlaces.length > 0 && (
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
                                            ...currentDestination,
                                            activityId: undefined,
                                            planDate: undefined,
                                            backLabel: undefined,
                                            destinationId: `saved:${place.id}`,
                                            name: place.name,
                                            emoji: place.emoji ?? undefined,
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
                                    <PlaceGlyph
                                        emoji={place.emoji}
                                        category={place.category}
                                        size={16}
                                    />
                                    {place.name}
                                </button>
                            ))}
                        </div>
                    </>
                )}

                <JourneySchedulePicker
                    mode={timeMode}
                    scheduledAt={scheduledAt}
                    onModeChange={setTimeMode}
                    onScheduleChange={setScheduledAt}
                />
                <div
                    className="mb-3 grid grid-cols-4 gap-1"
                    role="group"
                    aria-label="Travel mode"
                >
                    {(
                        [
                            ['all', 'All', IconRoute],
                            ['transit', 'Transit', IconRoute],
                            ['bike', 'Cycle', IconBike],
                            ['walk', 'Walk', IconWalk],
                        ] as const
                    ).map(([key, label, Icon]) => (
                        <button
                            key={key}
                            type="button"
                            aria-pressed={routeMode === key}
                            onClick={() => setRouteMode(key)}
                            className={`inline-flex min-h-11 min-w-0 items-center justify-center gap-1 rounded-xl px-1 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-cyan ${routeMode === key ? 'bg-cyan/15 text-cyan-h' : 'bg-card text-muted-foreground'}`}
                        >
                            <Icon
                                size={17}
                                stroke={ICON_STROKE}
                                className="hidden shrink-0 min-[380px]:block"
                            />
                            {label}
                        </button>
                    ))}
                </div>
                <TravelHelp href={requestHref} />
            </section>
            <section className="min-w-0 lg:border-l lg:border-border lg:pl-8">
                <div className="mb-3 flex items-center justify-between gap-3">
                    <div className="min-w-0">
                        <h2 className="truncate text-base font-semibold sm:text-lg">
                            Routes to {destination.name}
                        </h2>
                        <p
                            className="text-xs text-muted-foreground"
                            role="status"
                        >
                            {loading
                                ? 'Checking routes…'
                                : error
                                  ? 'Unavailable'
                                  : 'Check before leaving'}
                        </p>
                    </div>
                    <SaveJourneyButton
                        compact
                        disabled={loading || unresolved}
                        destination={{
                            ...handoffDestination,
                            preferredMode:
                                routeMode === 'all' ? undefined : routeMode,
                        }}
                    />
                </div>

                {unresolved && (
                    <p
                        role="status"
                        className="rounded-xl bg-cyan/10 px-4 py-4 text-sm text-cyan-h"
                    >
                        Choose a matching{' '}
                        {unresolvedInputs.origin
                            ? 'starting point'
                            : 'destination'}{' '}
                        from the suggestions to update your routes.
                    </p>
                )}
                <div hidden={unresolved}>
                    {loading && (
                        <div className="flex flex-col gap-2.5">
                            {[1, 2].map((i) => (
                                <div
                                    key={i}
                                    className="h-[98px] rounded-2xl bg-secondary motion-safe:animate-pulse"
                                />
                            ))}
                        </div>
                    )}

                    {error && !loading && (
                        <div className="rounded-[9px] bg-danger-soft p-4 text-center text-sm text-danger">
                            <p>
                                {needsOrigin
                                    ? 'Choose a starting point above to find this route. Your destination and arrival time are kept.'
                                    : 'Routes couldn’t load. Your destination and time are still here.'}
                            </p>
                            <button
                                type="button"
                                onClick={() => setRetry((value) => value + 1)}
                                className="mt-2 min-h-11 rounded-xl bg-card px-4 font-semibold text-foreground"
                            >
                                Try again
                            </button>
                        </div>
                    )}

                    {!loading &&
                        !error &&
                        data?.source === 'degraded' &&
                        data.degraded && (
                            <DegradedNotice data={data.degraded} />
                        )}

                    {!loading &&
                        !error &&
                        data &&
                        data.source !== 'degraded' &&
                        items.length === 0 && (
                            <div className="flex items-center justify-center gap-2 rounded-2xl bg-secondary px-4 py-4 text-center text-sm text-muted-foreground">
                                <IconWalk
                                    size={16}
                                    stroke={ICON_STROKE}
                                    className="shrink-0"
                                />
                                No route was returned for these choices. Try
                                another time or mode.
                            </div>
                        )}

                    {!loading &&
                        !error &&
                        data &&
                        data.source !== 'degraded' &&
                        items.length > 0 && (
                            <>
                                {data.ticket && <FareLine fare={data.ticket} />}

                                {tight &&
                                    (routeMode === 'all' ||
                                        routeMode === 'transit') && (
                                        <div className="mb-2.5 flex items-center gap-2">
                                            <div className="min-w-0 flex-1">
                                                <TightCard
                                                    journey={tight}
                                                    onStart={() =>
                                                        setSelected(tight)
                                                    }
                                                />
                                            </div>
                                            <IconChevronRight
                                                size={18}
                                                stroke={ICON_STROKE}
                                                className="shrink-0 text-text-3"
                                            />
                                        </div>
                                    )}

                                <div className="mb-3 flex items-start gap-2">
                                    <TravelChoice
                                        label="Sort routes"
                                        value={filter}
                                        options={FILTERS.map((item) => ({
                                            value: item.key,
                                            label: item.label,
                                        }))}
                                        onChange={(value) =>
                                            setFilter(value as FilterKey)
                                        }
                                    />
                                    {(routeMode === 'all' ||
                                        routeMode === 'transit') &&
                                        availableModes.length > 1 && (
                                            <details className="min-w-0 flex-1 rounded-xl bg-card">
                                                <summary className="flex min-h-11 cursor-pointer list-none items-center justify-center gap-1.5 px-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-cyan [&::-webkit-details-marker]:hidden">
                                                    Transport
                                                    {excludedModes.size > 0 && (
                                                        <span className="text-cyan-h">
                                                            ·{' '}
                                                            {excludedModes.size}{' '}
                                                            off
                                                        </span>
                                                    )}
                                                    <IconChevronDown
                                                        size={14}
                                                    />
                                                </summary>
                                                <div className="flex flex-wrap gap-1 p-2">
                                                    {availableModes.map(
                                                        (mode) => {
                                                            const enabled =
                                                                !excludedModes.has(
                                                                    mode.key,
                                                                );

                                                            return (
                                                                <button
                                                                    type="button"
                                                                    key={
                                                                        mode.key
                                                                    }
                                                                    aria-pressed={
                                                                        enabled
                                                                    }
                                                                    onClick={() =>
                                                                        setExcludedModes(
                                                                            (
                                                                                previous,
                                                                            ) => {
                                                                                const next =
                                                                                    new Set(
                                                                                        previous,
                                                                                    );

                                                                                if (
                                                                                    next.has(
                                                                                        mode.key,
                                                                                    )
                                                                                ) {
                                                                                    next.delete(
                                                                                        mode.key,
                                                                                    );
                                                                                } else {
                                                                                    next.add(
                                                                                        mode.key,
                                                                                    );
                                                                                }

                                                                                return next;
                                                                            },
                                                                        )
                                                                    }
                                                                    className={`min-h-11 rounded-lg px-3 text-sm focus-visible:outline-2 focus-visible:outline-cyan ${enabled ? 'bg-cyan/10 text-cyan-h' : 'text-muted-foreground line-through'}`}
                                                                >
                                                                    {mode.label}
                                                                </button>
                                                            );
                                                        },
                                                    )}
                                                </div>
                                            </details>
                                        )}
                                </div>

                                {visible.length === 0 ? (
                                    <div className="rounded-2xl bg-secondary px-4 py-4 text-center text-sm text-muted-foreground">
                                        <p>No route matches these choices.</p>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setFilter('best');
                                                setExcludedModes(new Set());
                                            }}
                                            className="mt-1 min-h-11 font-semibold text-cyan-h"
                                        >
                                            Reset route preferences
                                        </button>
                                    </div>
                                ) : (
                                    <div className="flex flex-col gap-2">
                                        {visible.map((it, i) => {
                                            const isTop = i === 0;
                                            const onTrip = isTripRoute(
                                                it.journey,
                                            );
                                            const label =
                                                it.journey.mode !== 'transit'
                                                    ? it.journey.mode === 'bike'
                                                        ? 'By bike'
                                                        : 'On foot'
                                                    : isTop
                                                      ? (FILTERS.find(
                                                            (f) =>
                                                                f.key ===
                                                                filter,
                                                        )?.label ?? null)
                                                      : null;

                                            return (
                                                <div
                                                    key={`${it.journey.depart_at}-${it.journey.arrive_at}-${i}`}
                                                    className="flex items-center gap-2"
                                                >
                                                    <div className="min-w-0 flex-1">
                                                        <RouteCard
                                                            journey={it.journey}
                                                            label={label}
                                                            walkMin={it.walkMin}
                                                            disrupted={
                                                                it.disrupted
                                                            }
                                                            highlight={isTop}
                                                            onTrip={onTrip}
                                                            onStart={() =>
                                                                // Reopen the real live trip
                                                                // (boarded times), not the
                                                                // list's next departure.
                                                                setSelected(
                                                                    onTrip &&
                                                                        activeTrip
                                                                        ? activeTrip.journey
                                                                        : it.journey,
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}

                                <button
                                    onClick={() => {
                                        setMoreLoading(true);
                                        setShowMore((v) => !v);
                                    }}
                                    disabled={moreLoading}
                                    className="mt-3 w-full cursor-pointer rounded-[13px] border border-border bg-card py-3 text-[13px] font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary disabled:opacity-60"
                                >
                                    {moreLoading
                                        ? 'Finding more…'
                                        : showMore
                                          ? 'Show fewer options'
                                          : 'Show more options'}
                                </button>
                            </>
                        )}
                </div>
            </section>
        </div>
    );
}
