import { usePage } from '@inertiajs/react';
import {
    IconAlertTriangle,
    IconArrowLeft,
    IconBike,
    IconBolt,
    IconBus,
    IconCircleCheck,
    IconMapPin,
    IconSailboat,
    IconTicket,
    IconTrain,
    IconWalk,
    IconX,
} from '@tabler/icons-react';
import type { IconProps } from '@tabler/icons-react';
import { useEffect, useState } from 'react';
import type { ComponentType } from 'react';
import TakeMeThereController from '@/actions/App/Http/Controllers/Api/TakeMeThereController';
import { JourneyMap } from '@/components/journey/journey-map';
import {
    JourneySchedulePicker,
    scheduleDefault,
    toLocalDateTime,
} from '@/components/journey/journey-schedule-picker';
import type { JourneyPlanningMode } from '@/components/journey/journey-schedule-picker';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { ICON_STROKE } from '@/constants/icons';
import { useActiveTrip } from '@/hooks/use-active-trip';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';

/** A station ridden through on a transit leg (name + scheduled arrival). */
export type IntermediateStop = {
    name: string;
    arrive_at: string;
    arrive_time: string;
    /** Stop coordinates — power live GPS map-matching during a trip. */
    lat: number | null;
    lng: number | null;
};

export type JourneyLeg = {
    mode: string;
    line: string | null;
    headsign: string | null;
    from: { name: string; lat: number; lng: number };
    to: { name: string; lat: number; lng: number };
    depart_at: string;
    arrive_at: string;
    depart_time: string;
    arrive_time: string;
    duration_min: number;
    stops: number | null;
    polyline: string | null;
    color: string | null;
    intermediate_stops: IntermediateStop[] | null;
};

export type Journey = {
    mode: 'transit' | 'bike' | 'walk';
    depart_at: string;
    arrive_at: string;
    depart_time: string;
    arrive_time: string;
    duration_min: number;
    transfers: number;
    legs: JourneyLeg[];
    /** A departure the walk-folded plan drops as unreachable, surfaced anyway so
     * the user can decide to jog for it. Present only on that one option. */
    tight?: boolean;
    /** Minutes on foot to the boarding stop — the walk this option omits. */
    access_walk_min?: number;
    /** Name of that boarding stop, for the "N-min walk to …" flag. */
    access_stop_name?: string;
};

export type JourneyResponse = {
    source: 'transitous' | 'trias' | 'degraded';
    journeys: Journey[];
    degraded: {
        departures: Array<{
            line: string;
            direction: string;
            departures: number[];
        }>;
        nearest_stop: { name: string; walk_min: number | null } | null;
        deep_links: { google: string; kvb: string };
    } | null;
    from: { name: string; lat: number; lng: number };
    to: { name: string };
    ticket: FareAdvice | null;
    disruptions: Array<{ title: string; severity: string; lines: string[] }>;
};

/** Journey-aware Rheinlandtarif advice — mirrors App\Transit\Dto\FareAdvice. */
export type FareAdvice = {
    covered_by_deutschlandticket: boolean;
    preisstufe: string | null;
    price_eur: number | null;
    estimated: boolean;
    eezy_cap_eur: number | null;
    deutschlandticket_eur: number;
    label: string;
    reason: string;
    how_to_buy: Array<{ label: string; url: string }>;
};

export type Destination = {
    name: string;
    /** Item title shown in the return action (an event can navigate to a venue). */
    backLabel?: string;
    emoji?: string;
    lat: number;
    lng: number;
    address?: string;
    /** ISO start time when navigating to a timed event. */
    arriveBy?: string;
    /** The origin the caller already measured from (e.g. the Places "From"),
     * so the journey starts there — not a re-resolved fallback. */
    fromLat?: number | null;
    fromLng?: number | null;
    fromName?: string | null;
    /** Mode used for the caller's card time, so the sheet opens on the same
     * route instead of silently switching the meaning of that number. */
    preferredMode?: JourneyMode | null;
};

const CSRF = () =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') || '';

const MODE_ICON: Record<string, ComponentType<IconProps>> = {
    walk: IconWalk,
    bike: IconBike,
    bus: IconBus,
    tram: IconTrain,
    subway: IconTrain,
    rail: IconTrain,
    ferry: IconSailboat,
};

/** Top-level journey modes, in the order the selector shows them. */
const MODE_ORDER = ['transit', 'bike', 'walk'] as const;
export type JourneyMode = (typeof MODE_ORDER)[number];

const MODE_META: Record<
    JourneyMode,
    { label: string; icon: ComponentType<IconProps> }
> = {
    transit: { label: 'Transit', icon: IconBus },
    bike: { label: 'Bike', icon: IconBike },
    walk: { label: 'Walk', icon: IconWalk },
};

function eur(value: number): string {
    return '€' + value.toFixed(2);
}

/** "44 min" under an hour, "1 h 5 min" above it. */
function formatDuration(min: number): string {
    if (min < 60) {
        return `${min} min`;
    }

    const hours = Math.floor(min / 60);
    const rest = min % 60;

    return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;
}

/**
 * Which mode to lead with. Transit is the default for a city app, but when a
 * direct option is dramatically faster — the cross-Rhine case, 107-min transit
 * vs a 41-min bike — we surface that instead so the sheet never opens on an
 * absurd route.
 */
function pickDefaultMode(
    byMode: Map<JourneyMode, Journey>,
    preferred?: JourneyMode | null,
): JourneyMode | null {
    // The user's chosen default mode leads when it's actually available.
    if (preferred && byMode.has(preferred)) {
        return preferred;
    }

    const transit = byMode.get('transit');
    const fastestDirect = [byMode.get('bike'), byMode.get('walk')]
        .filter((journey): journey is Journey => journey != null)
        .sort((a, b) => a.duration_min - b.duration_min)[0];

    if (transit) {
        if (
            fastestDirect &&
            transit.duration_min > fastestDirect.duration_min * 1.5
        ) {
            return fastestDirect.mode;
        }

        return 'transit';
    }

    return fastestDirect?.mode ?? null;
}

/** Straight-line km — only used to phrase the "no transit route" fallback. */
function haversineKm(
    a: { lat: number; lng: number },
    b: { lat: number; lng: number },
): number {
    const r = 6371;
    const dLat = ((b.lat - a.lat) * Math.PI) / 180;
    const dLng = ((b.lng - a.lng) * Math.PI) / 180;
    const s =
        Math.sin(dLat / 2) ** 2 +
        Math.cos((a.lat * Math.PI) / 180) *
            Math.cos((b.lat * Math.PI) / 180) *
            Math.sin(dLng / 2) ** 2;

    return r * 2 * Math.atan2(Math.sqrt(s), Math.sqrt(1 - s));
}

function LegRow({
    leg,
    originName,
    destinationName,
}: {
    leg: JourneyLeg;
    originName?: string;
    destinationName?: string;
}) {
    const isTransit = leg.mode !== 'walk' && leg.mode !== 'bike';
    const ModeIcon = MODE_ICON[leg.mode] ?? IconWalk;
    const verb = leg.mode === 'bike' ? 'Cycle' : 'Walk';
    // MOTIS names a journey's own endpoints "START"/"END" (blanked upstream),
    // so a direct walk/bike leg reaches here with no endpoint names. Fall back
    // to the journey's origin/destination so the step still reads "Walk to X
    // · from Y" instead of a bare "Walk · <time> departure".
    const toName = leg.to.name || destinationName || '';
    const fromName = leg.from.name || originName || '';

    return (
        <div className="relative flex items-center gap-3 py-2 pl-5">
            <span className="absolute top-0 bottom-0 left-[7px] w-px bg-border" />
            <span className="absolute left-[4px] size-[7px] rounded-full border border-border bg-card" />
            {isTransit && leg.line ? (
                <span className="flex h-6 min-w-6 shrink-0 items-center justify-center rounded-md bg-primary px-1.5 text-xs font-bold text-white">
                    {leg.line}
                </span>
            ) : (
                <span className="flex w-6 shrink-0 justify-center text-muted-foreground">
                    <ModeIcon size={18} stroke={ICON_STROKE} />
                </span>
            )}
            <span className="min-w-0 flex-1">
                <span className="block text-sm font-medium">
                    {isTransit
                        ? `${leg.line ?? ''}${leg.headsign ? ` toward ${leg.headsign}` : ''}`
                        : `${verb}${toName ? ` to ${toName}` : ''}`}
                </span>
                <span className="block text-xs text-muted-foreground">
                    {isTransit
                        ? `${leg.stops ? `${leg.stops} ${leg.stops === 1 ? 'stop' : 'stops'} · ` : ''}get off at ${leg.to.name}`
                        : fromName
                          ? `from ${fromName}`
                          : `${leg.depart_time} departure`}
                </span>
            </span>
            <span className="shrink-0 font-mono text-xs text-muted-foreground">
                {leg.duration_min} min
            </span>
        </div>
    );
}

/**
 * Journey-aware Rheinlandtarif advice (App\Transit\FareAdvisor): covered by
 * a held Deutschlandticket, or the per-trip single fare — with cross-zone
 * prices honestly flagged "estimated" and eezy as the "never more than" net.
 */
function FareCard({ fare }: { fare: FareAdvice }) {
    if (fare.covered_by_deutschlandticket) {
        return (
            <div className="mb-3 rounded-[14px] bg-success-soft px-4 py-3">
                <div className="flex items-center gap-2">
                    <IconCircleCheck
                        size={18}
                        stroke={ICON_STROKE}
                        className="shrink-0 text-success"
                    />
                    <span className="text-[15px] font-semibold text-success">
                        {fare.label}
                    </span>
                </div>
                <p className="mt-1 text-[13px] text-muted-foreground">
                    {fare.reason}
                </p>
            </div>
        );
    }

    return (
        <div className="mb-3 rounded-[14px] border border-border px-4 py-3">
            <div className="mb-2 flex items-center justify-between font-mono text-[10px] tracking-[0.1em] text-muted-foreground/70 uppercase">
                <span className="flex items-center gap-1.5">
                    <IconTicket size={12} stroke={ICON_STROKE} />
                    Your ticket
                </span>
                {fare.preisstufe && (
                    <span>
                        Preisstufe {fare.preisstufe}
                        {fare.estimated ? ' · est.' : ''}
                    </span>
                )}
            </div>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="text-[15px] font-semibold">
                        {fare.label}
                    </div>
                    {!fare.estimated && (
                        <div className="mt-0.5 text-[12.5px] text-muted-foreground">
                            Valid 90 min from validation
                        </div>
                    )}
                </div>
                {fare.price_eur != null && (
                    <div className="shrink-0 font-display text-2xl font-medium">
                        {fare.estimated && (
                            <span className="text-base text-muted-foreground">
                                ≈{' '}
                            </span>
                        )}
                        {eur(fare.price_eur)}
                    </div>
                )}
            </div>
            <p className="mt-2.5 border-t border-border pt-2.5 text-[12px] leading-relaxed text-muted-foreground">
                {fare.reason}
            </p>
            {fare.how_to_buy.length > 0 && (
                <div className="mt-2.5 flex flex-wrap gap-1.5">
                    {fare.how_to_buy.map((c) => (
                        <a
                            key={c.label}
                            href={c.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="rounded-full border border-border px-2.5 py-1 text-[12px] font-semibold text-primary transition-colors hover:bg-secondary"
                        >
                            {c.label}
                        </a>
                    ))}
                </div>
            )}
        </div>
    );
}

function RouteOption({
    journey,
    active,
    onSelect,
}: {
    journey: Journey;
    active: boolean;
    onSelect: () => void;
}) {
    const { label, icon: Icon } = MODE_META[journey.mode];
    const purpose =
        journey.mode === 'transit'
            ? 'Best fit'
            : journey.mode === 'bike'
              ? 'Fastest'
              : 'Simplest';
    const headline =
        journey.mode === 'transit'
            ? transitHeadline(journey)
            : journey.mode === 'bike'
              ? 'Bike direct'
              : 'Walk the whole way';
    const detail =
        journey.mode === 'transit'
            ? [
                  `${walkMinutes(journey)} min walk`,
                  journey.transfers === 0
                      ? 'No changes'
                      : `${journey.transfers} change${journey.transfers === 1 ? '' : 's'}`,
              ].join(' · ')
            : journey.mode === 'bike'
              ? 'Direct route · no timetable'
              : 'Direct route · outdoors';

    return (
        <button
            type="button"
            aria-label={`${purpose} ${label} ${formatDuration(journey.duration_min)}`}
            aria-pressed={active}
            onClick={onSelect}
            className={cn(
                'group relative grid w-full grid-cols-[52px_1fr_auto] items-center gap-4 overflow-hidden rounded-[18px] border bg-card px-4 py-4 text-left transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                active
                    ? 'border-primary shadow-[inset_5px_0_0_var(--primary)]'
                    : 'border-border hover:-translate-y-0.5 hover:border-foreground/35 hover:shadow-sm',
            )}
        >
            <span
                className={cn(
                    'grid size-12 place-items-center rounded-[14px] text-base font-bold',
                    journey.mode === 'transit'
                        ? 'bg-primary text-primary-foreground'
                        : journey.mode === 'bike'
                          ? 'bg-cyan text-white'
                          : 'bg-secondary text-muted-foreground',
                )}
            >
                {journey.mode === 'transit' ? (
                    firstTransitLine(journey) || (
                        <Icon size={21} stroke={ICON_STROKE} />
                    )
                ) : (
                    <Icon size={21} stroke={ICON_STROKE} />
                )}
            </span>
            <span className="min-w-0">
                <span className="mb-1 block font-mono text-[10px] font-bold tracking-[0.13em] text-primary uppercase">
                    {purpose}
                </span>
                <span className="block truncate text-base font-bold">
                    {headline}
                </span>
                <span className="mt-1 block truncate font-mono text-[11px] text-muted-foreground">
                    {detail}
                </span>
            </span>
            <span className="text-right">
                <span className="block font-mono text-lg font-bold">
                    {formatDuration(journey.duration_min)}
                </span>
                <span className="mt-1 block font-mono text-[10px] font-semibold text-muted-foreground">
                    {journey.mode === 'transit'
                        ? `leave ${journey.depart_time}`
                        : 'leave when ready'}
                </span>
            </span>
            {active && (
                <IconCircleCheck
                    size={22}
                    stroke={ICON_STROKE}
                    className="absolute top-2.5 right-2.5 text-primary"
                />
            )}
        </button>
    );
}

function firstTransitLine(journey: Journey): string | null {
    return (
        journey.legs.find(
            (leg) =>
                leg.mode !== 'walk' && leg.mode !== 'bike' && leg.line != null,
        )?.line ?? null
    );
}

function transitHeadline(journey: Journey): string {
    const leg = journey.legs.find(
        (candidate) =>
            candidate.mode !== 'walk' &&
            candidate.mode !== 'bike' &&
            candidate.line,
    );

    if (!leg) {
        return 'Public transport';
    }

    return `${leg.line} to ${leg.to.name || 'your stop'}`;
}

function walkMinutes(journey: Journey): number {
    return journey.legs
        .filter((leg) => leg.mode === 'walk')
        .reduce((total, leg) => total + leg.duration_min, 0);
}

export function TakeMeThereSheet({
    destination,
    onClose,
    onBack,
}: {
    destination: Destination;
    onClose: () => void;
    onBack?: () => void;
}) {
    const [data, setData] = useState<JourneyResponse | null>(null);
    const [error, setError] = useState<'location' | 'request' | null>(null);
    const [fromOverride, setFromOverride] = useState<{
        lat: number;
        lng: number;
    } | null>(null);
    const [confirming, setConfirming] = useState(false);
    const [mode, setMode] = useState<JourneyMode | null>(null);
    const [planningMode, setPlanningMode] = useState<JourneyPlanningMode>(
        destination.arriveBy ? 'arrive' : 'now',
    );
    const [scheduledAt, setScheduledAt] = useState(() =>
        scheduleDefault(destination.arriveBy),
    );
    const isMobile = useIsMobile();
    const { start, busy: startingTrip } = useActiveTrip();
    // The user's default transport mode pre-selects the sheet so it opens in the
    // mode the Places list / composer measured distances in.
    const userPreferred = usePage().props.auth.user
        .transport_mode as JourneyMode | null;
    const preferred = destination.preferredMode ?? userPreferred ?? null;

    useEffect(() => {
        let cancelled = false;
        const controller = new AbortController();
        const query: Record<string, string | number> = {
            to_lat: String(destination.lat),
            to_lng: String(destination.lng),
            to_name: destination.name,
        };

        if (fromOverride) {
            query.from_lat = String(fromOverride.lat);
            query.from_lng = String(fromOverride.lng);
        } else if (destination.fromLat != null && destination.fromLng != null) {
            // Start from the origin the caller already measured from, so the
            // sheet's times match the card's "X min away".
            query.from_lat = String(destination.fromLat);
            query.from_lng = String(destination.fromLng);

            if (destination.fromName) {
                query.from_name = destination.fromName;
            }
        }

        if (planningMode !== 'now') {
            query.depart_at = toLocalDateTime(scheduledAt);
            query.arrive_by = planningMode === 'arrive' ? 1 : 0;
        }

        fetch(TakeMeThereController.url({ query }), {
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (res) => {
                if (!res.ok) {
                    const payload = await res.json().catch(() => null);
                    const responseError = new Error(
                        `Journey request failed (${res.status})`,
                    ) as Error & { code?: string };
                    responseError.code = payload?.code;

                    throw responseError;
                }

                return res.json();
            })
            .then((json) => {
                if (!cancelled) {
                    setError(null);
                    setData(json);
                }
            })
            .catch((requestError: unknown) => {
                if (
                    !cancelled &&
                    (requestError as Error)?.name !== 'AbortError'
                ) {
                    setError(
                        (requestError as { code?: string })?.code ===
                            'location_required'
                            ? 'location'
                            : 'request',
                    );
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
        fromOverride,
        planningMode,
        scheduledAt,
    ]);

    // "I'm here →" — confirm the user's real position as the journey
    // origin (and as the app-wide location anchor), then replan from it.
    function confirmHere() {
        if (confirming || !navigator.geolocation) {
            return;
        }

        setError(null);
        setConfirming(true);

        navigator.geolocation.getCurrentPosition(
            (pos) => {
                const { latitude: lat, longitude: lng } = pos.coords;

                fetch('/api/location/confirm', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF(),
                    },
                    body: JSON.stringify({ lat, lng }),
                }).catch(() => {});

                setConfirming(false);
                setData(null); // back to the loading skeleton while replanning
                setError(null);
                setMode(null); // let the new plan re-pick its best default mode
                setFromOverride({ lat, lng });
            },
            () => setConfirming(false),
            { enableHighAccuracy: true, timeout: 8000 },
        );
    }

    // One journey per mode (the first/best of each the engine returned), then
    // the mode to show: an explicit user pick when still available, else the
    // smart default.
    const byMode = new Map<JourneyMode, Journey>();

    for (const candidate of data?.journeys ?? []) {
        if (!byMode.has(candidate.mode)) {
            byMode.set(candidate.mode, candidate);
        }
    }

    const availableModes = MODE_ORDER.flatMap((m) => {
        const journey = byMode.get(m);

        return journey ? [{ mode: m, journey }] : [];
    });
    const effectiveMode =
        mode && byMode.has(mode) ? mode : pickDefaultMode(byMode, preferred);
    const journey = effectiveMode ? (byMode.get(effectiveMode) ?? null) : null;
    const hasGeometry = (journey?.legs ?? []).some(
        (leg) => leg.polyline != null,
    );
    // Straight-line distance, only to phrase the "no transit route" fallback
    // honestly — a 5 km cross-Rhine place isn't "an easy walk".
    const straightKm = data?.from ? haversineKm(data.from, destination) : null;

    const title = journey
        ? planningMode === 'arrive'
            ? `Arrive by ${timeLabel(scheduledAt)}.`
            : planningMode === 'later'
              ? `Leave at ${timeLabel(scheduledAt)}.`
              : `Leave at ${journey.depart_time}.`
        : planningMode === 'arrive'
          ? `Arrive by ${timeLabel(scheduledAt)}.`
          : planningMode === 'later'
            ? `Leave at ${timeLabel(scheduledAt)}.`
            : 'Finding the best way.';
    const tripSummary = journey
        ? `${dayLabel(planningMode === 'now' ? new Date() : scheduledAt)} · ${destination.name} around ${journey.arrive_time}`
        : destination.name;

    async function startSelectedTrip(): Promise<void> {
        if (!journey || !data?.from) {
            return;
        }

        await start(
            journey,
            {
                name: destination.name,
                lat: destination.lat,
                lng: destination.lng,
                emoji: destination.emoji,
            },
            data.from,
        );
        onClose();
    }

    const planner = (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="px-5 pt-5 sm:px-7 sm:pt-7">
                <div className="mb-2 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="font-mono text-[11px] font-bold tracking-[0.15em] text-primary uppercase">
                            Trip options · live data
                        </div>
                        <h2 className="mt-2 font-display text-[clamp(2.2rem,4vw,3.6rem)] leading-[0.95] font-medium tracking-[-0.035em]">
                            {title}
                        </h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {tripSummary}
                        </p>
                    </div>
                    {data?.ticket?.covered_by_deutschlandticket && (
                        <div className="rounded-[13px] bg-success-soft px-4 py-3 font-mono text-xs font-bold tracking-[0.08em] text-success uppercase">
                            ✓ Your ticket covers it
                        </div>
                    )}
                </div>

                {data?.from && (
                    <div className="mb-5 flex items-center gap-2 text-xs text-muted-foreground">
                        <IconMapPin size={14} stroke={ICON_STROKE} />
                        <span>
                            From{' '}
                            <strong className="font-semibold text-foreground">
                                {fromOverride
                                    ? 'Your location'
                                    : data.from.name}
                            </strong>
                        </span>
                        {!fromOverride && (
                            <button
                                type="button"
                                onClick={confirmHere}
                                disabled={confirming}
                                className="font-semibold text-primary hover:underline disabled:opacity-60"
                            >
                                {confirming ? 'Locating…' : 'Use live location'}
                            </button>
                        )}
                    </div>
                )}

                <JourneySchedulePicker
                    mode={planningMode}
                    scheduledAt={scheduledAt}
                    onModeChange={(next) => {
                        setData(null);
                        setError(null);
                        setPlanningMode(next);
                        setMode(null);
                    }}
                    onScheduleChange={(next) => {
                        setData(null);
                        setError(null);
                        setScheduledAt(next);
                        setMode(null);
                    }}
                />
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto px-5 pb-5 sm:px-7">
                {!data && error === null && (
                    <div className="space-y-3">
                        {[1, 2, 3].map((item) => (
                            <div
                                key={item}
                                className="h-24 animate-pulse rounded-[18px] bg-secondary"
                            />
                        ))}
                    </div>
                )}

                {error === 'location' && (
                    <div className="rounded-[16px] border border-cyan/30 bg-cyan/10 p-5 text-sm">
                        <p>
                            We need your starting point before we can plan this
                            route.
                        </p>
                        <button
                            type="button"
                            onClick={confirmHere}
                            disabled={confirming}
                            className="mt-3 rounded-full bg-primary px-4 py-2 text-xs font-semibold text-white disabled:opacity-60"
                        >
                            {confirming ? 'Locating…' : 'Use my location'}
                        </button>
                    </div>
                )}

                {error === 'request' && (
                    <div className="rounded-[16px] bg-danger-soft p-5 text-sm text-danger">
                        Could not load the journey. Try again in a moment.
                    </div>
                )}

                {data &&
                    error === null &&
                    !journey &&
                    data.source !== 'degraded' &&
                    (straightKm != null && straightKm > 2 ? (
                        <div className="rounded-[16px] bg-secondary p-5 text-sm text-muted-foreground">
                            No usable route was returned. The destination is
                            about {Math.round(straightKm)} km away.
                        </div>
                    ) : (
                        <div className="rounded-[16px] bg-secondary p-5 text-sm text-muted-foreground">
                            It is close enough to walk or cycle; no transit is
                            needed.
                        </div>
                    ))}

                {journey && effectiveMode && (
                    <>
                        <div className="space-y-2.5">
                            {availableModes.map((option) => (
                                <RouteOption
                                    key={option.mode}
                                    journey={option.journey}
                                    active={effectiveMode === option.mode}
                                    onSelect={() => setMode(option.mode)}
                                />
                            ))}
                        </div>

                        <div className="mt-5 rounded-[18px] border border-border p-4">
                            <div className="mb-2 flex items-center justify-between">
                                <h3 className="text-sm font-bold">
                                    Route details
                                </h3>
                                <span className="font-mono text-[10px] font-semibold tracking-[0.1em] text-muted-foreground uppercase">
                                    Arrive {journey.arrive_time}
                                </span>
                            </div>
                            {journey.legs.map((leg, index) => (
                                <LegRow
                                    key={`${leg.mode}-${leg.depart_at}-${index}`}
                                    leg={leg}
                                    originName={
                                        index === 0
                                            ? ((fromOverride
                                                  ? 'your location'
                                                  : data?.from?.name) ??
                                              undefined)
                                            : undefined
                                    }
                                    destinationName={
                                        index === journey.legs.length - 1
                                            ? destination.name
                                            : undefined
                                    }
                                />
                            ))}
                        </div>

                        {isMobile && hasGeometry && (
                            <div className="mt-4">
                                <JourneyMap
                                    key={`${effectiveMode}-${journey.depart_at}`}
                                    legs={journey.legs}
                                    origin={data?.from ?? null}
                                    destination={{
                                        lat: destination.lat,
                                        lng: destination.lng,
                                        name: destination.name,
                                    }}
                                />
                            </div>
                        )}

                        {effectiveMode === 'transit' && data?.ticket && (
                            <div className="mt-4">
                                <FareCard fare={data.ticket} />
                            </div>
                        )}

                        {effectiveMode === 'transit' &&
                            (data?.disruptions ?? []).length > 0 && (
                                <div className="mt-3 flex items-start gap-2 rounded-[13px] bg-warn-soft px-3 py-2.5 text-[13px] text-warn">
                                    <IconAlertTriangle
                                        size={15}
                                        stroke={ICON_STROKE}
                                        className="mt-px shrink-0"
                                    />
                                    <span>{data!.disruptions[0].title}</span>
                                </div>
                            )}
                    </>
                )}

                {data?.source === 'degraded' && (
                    <div className="rounded-[16px] bg-secondary p-5 text-sm text-muted-foreground">
                        <div className="mb-3 flex items-start gap-2">
                            <IconBolt size={16} stroke={ICON_STROKE} />
                            Live routing is temporarily unavailable. Use the
                            fallback planner for this trip.
                        </div>
                        <div className="flex gap-2">
                            <a
                                href={data.degraded?.deep_links.google}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="rounded-full border border-border bg-card px-4 py-2 font-semibold text-primary"
                            >
                                Google Maps
                            </a>
                            <a
                                href={data.degraded?.deep_links.kvb}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="rounded-full border border-border bg-card px-4 py-2 font-semibold text-primary"
                            >
                                KVB
                            </a>
                        </div>
                    </div>
                )}
            </div>

            {journey && data && (
                <div className="grid gap-3 border-t border-border bg-card px-5 py-4 sm:grid-cols-[1fr_auto] sm:items-center sm:px-7">
                    <div className="text-sm text-muted-foreground">
                        {effectiveMode === 'transit' &&
                        data.ticket?.covered_by_deutschlandticket ? (
                            <>
                                <strong className="text-success">
                                    Deutschlandticket active.
                                </strong>{' '}
                                Live delays stay connected to this trip.
                            </>
                        ) : effectiveMode === 'transit' && data.ticket ? (
                            <>
                                <strong className="text-foreground">
                                    {data.ticket.label}
                                </strong>{' '}
                                {data.ticket.price_eur != null &&
                                    `· ${eur(data.ticket.price_eur)}`}
                            </>
                        ) : (
                            'Direct route — no public-transport ticket needed.'
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={startSelectedTrip}
                        disabled={startingTrip}
                        className="min-h-12 rounded-[14px] bg-primary px-6 text-sm font-bold text-primary-foreground shadow-[0_8px_24px_color-mix(in_srgb,var(--primary)_22%,transparent)] transition-transform hover:-translate-y-0.5 disabled:opacity-60"
                    >
                        {startingTrip
                            ? 'Saving trip…'
                            : planningMode === 'now'
                              ? `Start this trip · ${journey.depart_time}`
                              : `Keep this trip ready · ${journey.depart_time}`}
                    </button>
                </div>
            )}
        </div>
    );

    if (isMobile) {
        return (
            <BottomSheet open onClose={onClose}>
                <div data-journey-sheet>
                    <div className="mb-4 flex items-center justify-between">
                        <button
                            type="button"
                            aria-label={`Back to ${destination.backLabel ?? destination.name}`}
                            onClick={onBack ?? onClose}
                            className="inline-flex min-w-0 items-center gap-2 rounded-full px-1 py-2 text-sm font-semibold hover:text-primary"
                        >
                            <IconArrowLeft size={18} stroke={ICON_STROKE} />
                            <span className="truncate">
                                Back to{' '}
                                {destination.backLabel ?? destination.name}
                            </span>
                        </button>
                    </div>
                    {planner}
                </div>
            </BottomSheet>
        );
    }

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent
                aria-describedby={undefined}
                showClose={false}
                className="h-[min(860px,calc(100dvh-3rem))] grid-rows-[auto_minmax(0,1fr)] gap-0 overflow-hidden rounded-[26px] border-border bg-card p-0 sm:max-w-[1180px]"
                data-journey-sheet
            >
                <DialogTitle className="sr-only">
                    Journey to {destination.name}
                </DialogTitle>
                <header className="flex min-h-18 items-center justify-between gap-4 border-b border-border px-5 sm:px-7">
                    <button
                        type="button"
                        aria-label={`Back to ${destination.backLabel ?? destination.name}`}
                        onClick={onBack ?? onClose}
                        className="group inline-flex min-w-0 items-center gap-2 rounded-full px-2 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                    >
                        <IconArrowLeft
                            size={19}
                            stroke={ICON_STROKE}
                            className="shrink-0 text-muted-foreground transition-transform group-hover:-translate-x-0.5"
                        />
                        <span className="truncate">
                            Back to {destination.backLabel ?? destination.name}
                        </span>
                    </button>
                    <DialogClose asChild>
                        <button
                            type="button"
                            aria-label="Close journey planner"
                            className="grid size-11 shrink-0 place-items-center rounded-[14px] border border-border text-foreground transition-colors hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                        >
                            <IconX size={22} stroke={ICON_STROKE} />
                        </button>
                    </DialogClose>
                </header>
                <div className="grid min-h-0 flex-1 grid-cols-[1.04fr_.96fr]">
                    {planner}
                    <aside className="relative min-h-0 border-l border-border bg-secondary">
                        {journey && hasGeometry ? (
                            <JourneyMap
                                key={`${effectiveMode}-${journey.depart_at}-${planningMode}`}
                                legs={journey.legs}
                                origin={data?.from ?? null}
                                destination={{
                                    lat: destination.lat,
                                    lng: destination.lng,
                                    name: destination.name,
                                }}
                                className="h-full min-h-0 rounded-none border-0"
                            />
                        ) : (
                            <div className="grid h-full place-items-center px-8 text-center text-sm text-muted-foreground">
                                {error
                                    ? 'The map will return with the next available route.'
                                    : 'Drawing the live route…'}
                            </div>
                        )}
                    </aside>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function timeLabel(date: Date): string {
    return date.toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

function dayLabel(date: Date): string {
    const now = new Date();

    if (
        date.getFullYear() === now.getFullYear() &&
        date.getMonth() === now.getMonth() &&
        date.getDate() === now.getDate()
    ) {
        return 'Today';
    }

    return date.toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}
