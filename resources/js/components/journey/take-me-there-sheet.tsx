import { usePage } from '@inertiajs/react';
import {
    IconAlertTriangle,
    IconBike,
    IconBolt,
    IconBus,
    IconCircleCheck,
    IconClock,
    IconList,
    IconMap,
    IconMapPin,
    IconSailboat,
    IconTicket,
    IconTrain,
    IconWalk,
} from '@tabler/icons-react';
import type { IconProps } from '@tabler/icons-react';
import { useEffect, useState } from 'react';
import type { ComponentType } from 'react';
import { JourneyMap } from '@/components/journey/journey-map';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { ICON_STROKE } from '@/constants/icons';
import { useIsMobile } from '@/hooks/use-mobile';

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
type JourneyMode = (typeof MODE_ORDER)[number];

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

function ViewToggle({
    view,
    onChange,
}: {
    view: 'list' | 'map';
    onChange: (next: 'list' | 'map') => void;
}) {
    return (
        <div className="mb-3 inline-flex gap-0.5 rounded-full bg-secondary p-0.5">
            {(['list', 'map'] as const).map((value) => {
                const Icon = value === 'list' ? IconList : IconMap;
                const active = view === value;

                return (
                    <button
                        key={value}
                        type="button"
                        onClick={() => onChange(value)}
                        aria-pressed={active}
                        className={`flex items-center gap-1.5 rounded-full px-4 py-1.5 text-[12.5px] font-semibold capitalize transition-colors ${
                            active
                                ? 'bg-card text-foreground shadow-sm'
                                : 'text-muted-foreground'
                        }`}
                    >
                        <Icon size={14} stroke={ICON_STROKE} />
                        {value}
                    </button>
                );
            })}
        </div>
    );
}

/**
 * Transit / Bike / Walk segments, each showing that mode's travel time so the
 * trade-off is visible at a glance. Only rendered when more than one mode came
 * back; the active segment is the one whose journey is on screen.
 */
function ModeSelector({
    modes,
    active,
    onChange,
}: {
    modes: Array<{ mode: JourneyMode; journey: Journey }>;
    active: JourneyMode;
    onChange: (next: JourneyMode) => void;
}) {
    return (
        <div className="mb-4 flex gap-1.5">
            {modes.map(({ mode, journey }) => {
                const { label, icon: Icon } = MODE_META[mode];
                const isActive = mode === active;

                return (
                    <button
                        key={mode}
                        type="button"
                        onClick={() => onChange(mode)}
                        aria-pressed={isActive}
                        className={`flex flex-1 flex-col items-center gap-0.5 rounded-[12px] border py-2 transition-colors ${
                            isActive
                                ? 'border-primary bg-accent-soft text-primary'
                                : 'border-border text-muted-foreground hover:bg-secondary'
                        }`}
                    >
                        <Icon size={18} stroke={ICON_STROKE} />
                        <span className="text-[11px] font-semibold">
                            {label}
                        </span>
                        <span className="font-mono text-[12px] font-medium text-foreground">
                            {formatDuration(journey.duration_min)}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

export function TakeMeThereSheet({
    destination,
    onClose,
}: {
    destination: Destination;
    onClose: () => void;
}) {
    const [data, setData] = useState<JourneyResponse | null>(null);
    const [error, setError] = useState(false);
    const [fromOverride, setFromOverride] = useState<{
        lat: number;
        lng: number;
    } | null>(null);
    const [confirming, setConfirming] = useState(false);
    const [view, setView] = useState<'list' | 'map'>('list');
    const [mode, setMode] = useState<JourneyMode | null>(null);
    const isMobile = useIsMobile();
    // The user's default transport mode pre-selects the sheet so it opens in the
    // mode the Places list / composer measured distances in.
    const preferred = (usePage().props.auth.user.transport_mode ??
        null) as JourneyMode | null;

    useEffect(() => {
        let cancelled = false;
        const params = new URLSearchParams({
            to_lat: String(destination.lat),
            to_lng: String(destination.lng),
            to_name: destination.name,
        });

        if (fromOverride) {
            params.set('from_lat', String(fromOverride.lat));
            params.set('from_lng', String(fromOverride.lng));
        } else if (destination.fromLat != null && destination.fromLng != null) {
            // Start from the origin the caller already measured from, so the
            // sheet's times match the card's "X min away".
            params.set('from_lat', String(destination.fromLat));
            params.set('from_lng', String(destination.fromLng));

            if (destination.fromName) {
                params.set('from_name', destination.fromName);
            }
        }

        fetch(`/api/journey?${params}`, { credentials: 'same-origin' })
            .then((res) => res.json())
            .then((json) => {
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
        fromOverride,
    ]);

    // "I'm here →" — confirm the user's real position as the journey
    // origin (and as the app-wide location anchor), then replan from it.
    function confirmHere() {
        if (confirming || !navigator.geolocation) {
            return;
        }

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
                setError(false);
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

    const body = (
        <div className="pb-4" data-journey-sheet>
            {/* Destination header */}
            <div className="mb-4 flex items-center gap-3">
                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-2xl">
                    {destination.emoji ?? '📍'}
                </div>
                <div className="min-w-0">
                    <div className="font-display text-lg font-medium">
                        {destination.name}
                    </div>
                    {destination.address && (
                        <div className="truncate text-[13px] text-muted-foreground">
                            {destination.address}
                        </div>
                    )}
                </div>
            </div>

            {/* Timed destination — what the journey must beat */}
            {destination.arriveBy && (
                <div className="mb-3 flex items-center gap-1.5 rounded-[9px] bg-warn-soft px-3 py-2 text-[13px] font-medium text-warn">
                    <IconClock
                        size={15}
                        stroke={ICON_STROKE}
                        className="shrink-0"
                    />
                    Starts at{' '}
                    {new Date(destination.arriveBy).toLocaleTimeString(
                        'en-GB',
                        { hour: '2-digit', minute: '2-digit' },
                    )}
                </div>
            )}

            {/* Origin chip — confirm "I'm here" to replan from your real position */}
            {data?.from && (
                <div className="mb-4 flex items-center justify-between gap-2 rounded-full border border-border bg-card py-1.5 pr-1.5 pl-3.5">
                    <span className="flex items-center gap-1.5 truncate text-[13px] text-muted-foreground">
                        <IconMapPin
                            size={14}
                            stroke={ICON_STROKE}
                            className="shrink-0"
                        />
                        Starting from{' '}
                        <b className="font-semibold text-foreground">
                            {fromOverride ? 'Your location' : data.from.name}
                        </b>
                    </span>
                    {!fromOverride && (
                        <button
                            onClick={confirmHere}
                            disabled={confirming}
                            className="shrink-0 rounded-full bg-primary px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-60"
                        >
                            {confirming ? 'Locating…' : "I'm here →"}
                        </button>
                    )}
                </div>
            )}

            {/* Loading */}
            {!data && !error && (
                <div className="flex flex-col gap-2.5">
                    <div className="h-12 w-2/3 animate-pulse rounded-lg bg-secondary" />
                    {[1, 2, 3].map((i) => (
                        <div
                            key={i}
                            className="h-10 animate-pulse rounded-lg bg-secondary"
                        />
                    ))}
                </div>
            )}

            {error && (
                <div className="rounded-[9px] bg-danger-soft p-4 text-center text-sm text-danger">
                    Could not load the journey. Try again in a moment.
                </div>
            )}

            {/* Loaded, no transit route: walkable when genuinely close, else an
                honest "no direct transit" with the real distance + maps handoff. */}
            {data &&
                !error &&
                !journey &&
                data.source !== 'degraded' &&
                (straightKm != null && straightKm > 2 ? (
                    <div className="rounded-[9px] bg-secondary px-4 py-3.5 text-sm text-muted-foreground">
                        <div className="flex items-start gap-2">
                            <IconBolt
                                size={16}
                                stroke={ICON_STROKE}
                                className="mt-px shrink-0"
                            />
                            <span>
                                No transit or cycling route found — it's about{' '}
                                {Math.round(straightKm)} km away, past easy
                                reach.
                            </span>
                        </div>
                        <a
                            href={`https://www.google.com/maps/dir/?api=1&origin=${data.from!.lat},${data.from!.lng}&destination=${destination.lat},${destination.lng}&travelmode=transit`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="mt-2.5 inline-block rounded-[9px] border border-border bg-card px-3 py-2 text-[13px] font-semibold text-primary transition-colors hover:bg-secondary"
                        >
                            Open in Google Maps
                        </a>
                    </div>
                ) : (
                    <div className="flex items-center justify-center gap-2 rounded-[9px] bg-secondary px-4 py-4 text-center text-sm text-muted-foreground">
                        <IconWalk
                            size={16}
                            stroke={ICON_STROKE}
                            className="shrink-0"
                        />
                        It's close — easy to walk or cycle. No transit needed.
                    </div>
                ))}

            {/* Live journey */}
            {journey && effectiveMode && (
                <>
                    {availableModes.length > 1 && (
                        <ModeSelector
                            modes={availableModes}
                            active={effectiveMode}
                            onChange={setMode}
                        />
                    )}

                    <div className="mb-3">
                        {effectiveMode === 'transit' ? (
                            <>
                                <div className="font-display text-xl font-medium">
                                    Leave by {journey.depart_time}
                                </div>
                                <div className="mt-0.5 font-mono text-[11px] font-medium tracking-[0.08em] text-muted-foreground uppercase">
                                    arrive {journey.arrive_time} ·{' '}
                                    {journey.duration_min} min
                                    {journey.transfers > 0 &&
                                        ` · ${journey.transfers} transfer${journey.transfers > 1 ? 's' : ''}`}
                                </div>
                            </>
                        ) : (
                            <>
                                <div className="font-display text-xl font-medium">
                                    {formatDuration(journey.duration_min)}{' '}
                                    {effectiveMode === 'bike'
                                        ? 'by bike'
                                        : 'on foot'}
                                </div>
                                <div className="mt-0.5 font-mono text-[11px] font-medium tracking-[0.08em] text-muted-foreground uppercase">
                                    direct route · leave when you like
                                </div>
                            </>
                        )}
                    </div>

                    {hasGeometry && (
                        <ViewToggle view={view} onChange={setView} />
                    )}

                    {view === 'map' && hasGeometry ? (
                        <div className="mb-3">
                            <JourneyMap
                                key={`${effectiveMode},${destination.lat},${destination.lng},${journey.depart_time}`}
                                legs={journey.legs}
                                origin={data?.from ?? null}
                                destination={{
                                    lat: destination.lat,
                                    lng: destination.lng,
                                    name: destination.name,
                                }}
                            />
                        </div>
                    ) : (
                        <div className="mb-3">
                            {journey.legs.map((leg, i) => (
                                <LegRow
                                    key={i}
                                    leg={leg}
                                    originName={
                                        i === 0
                                            ? ((fromOverride
                                                  ? 'your location'
                                                  : data?.from?.name) ??
                                              undefined)
                                            : undefined
                                    }
                                    destinationName={
                                        i === journey.legs.length - 1
                                            ? destination.name
                                            : undefined
                                    }
                                />
                            ))}
                        </div>
                    )}

                    {effectiveMode === 'transit' && data?.ticket && (
                        <FareCard fare={data.ticket} />
                    )}

                    {effectiveMode === 'transit' &&
                        (data?.disruptions ?? []).length > 0 && (
                            <div className="mt-3 flex items-start gap-2 rounded-[9px] bg-warn-soft px-3 py-2.5 text-[13px] text-warn">
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

            {/* Degraded: nearest-stop departures + deep links */}
            {data?.source === 'degraded' && (
                <div>
                    <div className="mb-2 flex items-start gap-2 rounded-[9px] bg-secondary px-3 py-2.5 text-[13px] text-muted-foreground">
                        <IconBolt
                            size={15}
                            stroke={ICON_STROKE}
                            className="mt-px shrink-0"
                        />
                        <span>
                            Live routing unavailable — next departures from{' '}
                            {data.degraded?.nearest_stop
                                ? `your nearest stop, ${data.degraded.nearest_stop.name}${
                                      data.degraded.nearest_stop.walk_min
                                          ? ` (${data.degraded.nearest_stop.walk_min} min walk)`
                                          : ''
                                  }`
                                : 'stops near you'}
                            .
                        </span>
                    </div>
                    <div className="mb-3 overflow-hidden rounded-[14px] border border-border">
                        {(data.degraded?.departures ?? []).map((dep, i) => (
                            <div
                                key={`${dep.line}-${i}`}
                                className="flex items-center gap-3 border-b border-border px-3.5 py-2.5 last:border-b-0"
                            >
                                <span className="flex h-6 min-w-6 items-center justify-center rounded-md bg-primary px-1.5 text-xs font-bold text-white">
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
                            href={data.degraded?.deep_links.google}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex-1 rounded-[9px] border border-border bg-card py-2.5 text-center text-[13px] font-semibold text-primary transition-colors hover:bg-secondary"
                        >
                            Open in Google Maps
                        </a>
                        <a
                            href={data.degraded?.deep_links.kvb}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex-1 rounded-[9px] border border-border bg-card py-2.5 text-center text-[13px] font-semibold text-primary transition-colors hover:bg-secondary"
                        >
                            Open in KVB
                        </a>
                    </div>
                </div>
            )}
        </div>
    );

    // Mobile: bottom sheet. Desktop: traditional centered modal
    // (close via overlay click or the X), per the app-wide modal rule.
    if (isMobile) {
        return (
            <BottomSheet open onClose={onClose}>
                {body}
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
                className="gap-0 p-0 sm:max-w-md"
            >
                <DialogTitle className="sr-only">
                    {destination.name}
                </DialogTitle>
                <div className="max-h-[80vh] overflow-y-auto p-5">{body}</div>
            </DialogContent>
        </Dialog>
    );
}
