import { useEffect, useState } from 'react';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { useIsMobile } from '@/hooks/use-mobile';

type JourneyLeg = {
    mode: string;
    line: string | null;
    headsign: string | null;
    from: { name: string };
    to: { name: string };
    depart_time: string;
    arrive_time: string;
    duration_min: number;
};

type Journey = {
    depart_time: string;
    arrive_time: string;
    duration_min: number;
    transfers: number;
    legs: JourneyLeg[];
};

type JourneyResponse = {
    source: 'transitous' | 'trias' | 'degraded';
    journeys: Journey[];
    degraded: {
        departures: Array<{
            line: string;
            direction: string;
            departures: number[];
        }>;
        deep_links: { google: string; kvb: string };
    } | null;
    from: { name: string };
    to: { name: string };
    ticket: { advice: string; label: string; reason: string };
    disruptions: Array<{ title: string; severity: string; lines: string[] }>;
};

export type Destination = {
    name: string;
    emoji?: string;
    lat: number;
    lng: number;
    address?: string;
};

const MODE_EMOJI: Record<string, string> = {
    walk: '🚶',
    bike: '🚲',
    bus: '🚌',
    tram: '🚊',
    subway: '🚇',
    rail: '🚆',
    ferry: '⛴️',
};

function LegRow({ leg }: { leg: JourneyLeg }) {
    const isTransit = leg.mode !== 'walk';

    return (
        <div className="relative flex items-center gap-3 py-2 pl-5">
            <span className="absolute top-0 bottom-0 left-[7px] w-px bg-border" />
            <span className="absolute left-[4px] size-[7px] rounded-full border border-border bg-card" />
            {isTransit && leg.line ? (
                <span className="flex h-6 min-w-6 shrink-0 items-center justify-center rounded-md bg-primary px-1.5 text-xs font-bold text-white">
                    {leg.line}
                </span>
            ) : (
                <span className="w-6 shrink-0 text-center text-base">
                    {MODE_EMOJI[leg.mode] ?? '🚶'}
                </span>
            )}
            <span className="min-w-0 flex-1">
                <span className="block text-sm font-medium">
                    {isTransit
                        ? `${leg.line ?? ''}${leg.headsign ? ` toward ${leg.headsign}` : ''}`
                        : `Walk${leg.to.name ? ` to ${leg.to.name}` : ''}`}
                </span>
                <span className="block text-xs text-muted-foreground">
                    {isTransit
                        ? `${leg.from.name} → ${leg.to.name}`
                        : leg.from.name || `${leg.depart_time} departure`}
                </span>
            </span>
            <span className="shrink-0 font-mono text-xs text-muted-foreground">
                {leg.duration_min} min
            </span>
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
    const isMobile = useIsMobile();

    useEffect(() => {
        let cancelled = false;
        const params = new URLSearchParams({
            to_lat: String(destination.lat),
            to_lng: String(destination.lng),
            to_name: destination.name,
        });

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
    }, [destination.lat, destination.lng, destination.name]);

    const journey = data?.journeys?.[0] ?? null;

    const body = (
        <div className="pb-4">
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

            {/* Loaded, but no transit route (usually because it's close enough to walk) */}
            {data && !error && !journey && data.source !== 'degraded' && (
                <div className="rounded-[9px] bg-secondary px-4 py-4 text-center text-sm text-muted-foreground">
                    🚶 It's close — easy to walk or cycle. No transit needed.
                </div>
            )}

            {/* Live journey */}
            {journey && (
                <>
                    <div className="mb-3">
                        <div className="font-display text-xl font-medium">
                            Leave by {journey.depart_time}
                        </div>
                        <div className="text-[13px] text-muted-foreground">
                            arrive {journey.arrive_time} ·{' '}
                            {journey.duration_min} min
                            {journey.transfers > 0 &&
                                ` · ${journey.transfers} transfer${journey.transfers > 1 ? 's' : ''}`}
                        </div>
                    </div>

                    {data?.ticket && (
                        <div
                            className="mb-3 inline-flex items-center gap-1.5 rounded-full bg-accent-soft px-3 py-1.5 text-[13px] font-medium text-primary"
                            title={data.ticket.reason}
                        >
                            🎫 {data.ticket.label}
                        </div>
                    )}

                    {(data?.disruptions ?? []).length > 0 && (
                        <div className="mb-3 rounded-[9px] bg-warn-soft px-3 py-2.5 text-[13px] text-warn">
                            ⚠️ {data!.disruptions[0].title}
                        </div>
                    )}

                    <div className="mb-1">
                        {journey.legs.map((leg, i) => (
                            <LegRow key={i} leg={leg} />
                        ))}
                    </div>
                </>
            )}

            {/* Degraded: nearest-stop departures + deep links */}
            {data?.source === 'degraded' && (
                <div>
                    <div className="mb-2 rounded-[9px] bg-secondary px-3 py-2.5 text-[13px] text-muted-foreground">
                        Live routing is unavailable right now — here are
                        departures near you.
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
            <DialogContent className="gap-0 p-0 sm:max-w-md">
                <DialogTitle className="sr-only">
                    {destination.name}
                </DialogTitle>
                <div className="max-h-[80vh] overflow-y-auto p-5">{body}</div>
            </DialogContent>
        </Dialog>
    );
}
