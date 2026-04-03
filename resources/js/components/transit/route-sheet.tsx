import { useEffect, useState, lazy, Suspense } from 'react';
import { RouteStepsPanel } from '@/components/transit/route-steps-panel';

const RoutePreviewMapLazy = lazy(() =>
    import('@/components/transit/route-preview-map').then((m) => ({
        default: m.RoutePreviewMap,
    })),
);

type RouteOption = {
    mode: string;
    emoji: string;
    time: number;
    distance_km: number | null;
    detail: string;
    best: boolean;
    line?: string;
    direction?: string;
    disrupted?: boolean;
    geometry?: string | null;
};

type RouteData = {
    from: { name: string; lat: number; lng: number };
    to: { name: string; lat: number; lng: number; address: string | null };
    distance_km: number;
    routing_source?: string;
    options: RouteOption[];
    maps_url: { google: string; apple: string };
};

type FullRoute = {
    from: { name: string; lat: number; lng: number };
    to: { name: string; lat: number; lng: number };
    mode: string;
    duration_min: number;
    distance_km: number;
    geometry: string;
    steps: Array<{
        instruction: string;
        distance_km: number;
        time_sec: number;
        type: string;
        emoji: string;
    }>;
};

const VALHALLA_COSTING: Record<string, string> = {
    bike: 'bicycle',
    walk: 'pedestrian',
    drive: 'auto',
};

export function RouteSheet({
    destination,
    onClose,
}: {
    destination: {
        name: string;
        emoji: string;
        lat: number;
        lng: number;
        address?: string;
    };
    onClose: () => void;
}) {
    const [data, setData] = useState<RouteData | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [selectedRoute, setSelectedRoute] = useState<FullRoute | null>(null);
    const [loadingRoute, setLoadingRoute] = useState(false);
    const [showSteps, setShowSteps] = useState(false);

    // Fetch route options on mount
    useEffect(() => {
        setLoading(true);
        setData(null);
        setError(null);
        setSelectedRoute(null);
        fetch(
            `/api/route-options?to_lat=${destination.lat}&to_lng=${destination.lng}&name=${encodeURIComponent(destination.name)}&address=${encodeURIComponent(destination.address ?? '')}`,
            {
                credentials: 'same-origin',
            },
        )
            .then((res) => res.json())
            .then((json) => {
                setData(json);
                setLoading(false);
            })
            .catch(() => {
                setError('Could not load route options');
                setLoading(false);
            });
    }, [destination.lat, destination.lng]);

    // Fetch full route with geometry + steps when user clicks an option
    function selectMode(opt: RouteOption) {
        setShowSteps(false);

        if (!data || opt.mode === 'transit') {
return;
} // transit has no Valhalla routing yet

        const costing = VALHALLA_COSTING[opt.mode];

        if (!costing) {
return;
}

        setLoadingRoute(true);
        fetch(
            `/api/route-options?to_lat=${destination.lat}&to_lng=${destination.lng}&name=${encodeURIComponent(destination.name)}&mode=${costing}`,
            {
                credentials: 'same-origin',
            },
        )
            .then((r) => r.json())
            .then((json: FullRoute) => {
                if (json.geometry) {
                    setSelectedRoute(json);
                }

                setLoadingRoute(false);
            })
            .catch(() => setLoadingRoute(false));
    }

    if (loading) {
        return (
            <div className="animate-pulse">
                <div className="mb-4 flex items-center gap-3">
                    <div className="size-12 rounded-xl bg-[#EFEDE7]" />
                    <div className="flex-1">
                        <div className="mb-2 h-5 w-2/3 rounded bg-[#EFEDE7]" />
                        <div className="h-3 w-1/2 rounded bg-[#EFEDE7]" />
                    </div>
                </div>
                <div className="flex flex-col gap-2">
                    {[1, 2, 3].map((i) => (
                        <div
                            key={i}
                            className="h-[72px] rounded-[14px] bg-[#EFEDE7]"
                        />
                    ))}
                </div>
                <div className="mt-4 flex gap-2">
                    <div className="h-12 flex-1 rounded-[9px] bg-[#EFEDE7]" />
                    <div className="h-12 flex-1 rounded-[9px] bg-[#EFEDE7]" />
                </div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="rounded-[9px] bg-[#FDE8E6] p-4 text-center text-sm text-[#C4271A]">
                {error}
            </div>
        );
    }

    // Full route detail view (after clicking an option)
    if (selectedRoute) {
        return (
            <div>
                <button
                    onClick={() => setSelectedRoute(null)}
                    className="mb-3 flex cursor-pointer items-center gap-1 border-none bg-transparent transition-colors hover:text-[#1A4CD4]"
                    style={{
                        fontSize: 13,
                        fontWeight: 600,
                        color: '#6B6860',
                        padding: 0,
                    }}
                >
                    ← Back to options
                </button>

                {/* Route map */}
                <div className="mb-3">
                    <Suspense
                        fallback={
                            <div className="h-[220px] animate-pulse rounded-[14px] bg-[#EFEDE7]" />
                        }
                    >
                        <RoutePreviewMapLazy
                            origin={{
                                lat: selectedRoute.from.lat,
                                lng: selectedRoute.from.lng,
                            }}
                            destination={{
                                lat: selectedRoute.to.lat,
                                lng: selectedRoute.to.lng,
                            }}
                            mode={
                                selectedRoute.mode === 'bicycle'
                                    ? 'bike'
                                    : selectedRoute.mode === 'pedestrian'
                                      ? 'walk'
                                      : selectedRoute.mode === 'auto'
                                        ? 'drive'
                                        : 'walk'
                            }
                            geometry={selectedRoute.geometry}
                        />
                    </Suspense>
                </div>

                {/* Summary + toggle */}
                <RouteStepsPanel
                    mode={selectedRoute.mode}
                    durationMin={selectedRoute.duration_min}
                    distanceKm={selectedRoute.distance_km}
                    steps={showSteps ? selectedRoute.steps : []}
                    mapsUrl={data?.maps_url}
                />

                {/* Steps toggle */}
                {selectedRoute.steps.length > 0 && (
                    <button
                        onClick={() => setShowSteps(!showSteps)}
                        className="mt-2 w-full cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-white py-2.5 text-center text-[13px] font-semibold transition-all hover:bg-[#EFEDE7]"
                        style={{ color: '#1A4CD4' }}
                    >
                        {showSteps
                            ? 'Hide directions'
                            : `Show directions (${selectedRoute.steps.length} steps)`}
                    </button>
                )}
            </div>
        );
    }

    return (
        <div>
            {/* Header */}
            <div className="mb-4 flex items-center gap-3">
                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-[#EBF0FD] text-2xl">
                    {destination.emoji}
                </div>
                <div className="min-w-0 flex-1">
                    <div
                        style={{
                            fontFamily: "'Fraunces', serif",
                            fontSize: 20,
                            fontWeight: 500,
                        }}
                    >
                        {destination.name}
                    </div>
                    <div
                        style={{ fontSize: 13, color: '#6B6860', marginTop: 2 }}
                    >
                        {destination.address ?? data?.to?.address ?? ''}
                        {data && ` · ${data.distance_km} km`}
                        {data?.routing_source === 'valhalla' && (
                            <span
                                style={{
                                    fontSize: 10,
                                    color: '#0A7C52',
                                    marginLeft: 4,
                                }}
                            >
                                real routes
                            </span>
                        )}
                    </div>
                </div>
            </div>

            {/* Route options */}
            {data && (
                <>
                    <div className="mb-4 flex flex-col gap-2">
                        {data.options.map((opt) => (
                            <div
                                key={opt.mode}
                                onClick={() => selectMode(opt)}
                                className={`flex items-center gap-3 rounded-[14px] border bg-white p-3.5 transition-all hover:border-[rgba(26,76,212,.25)] hover:shadow-sm ${opt.mode !== 'transit' ? 'cursor-pointer' : ''}`}
                                style={{
                                    borderColor: opt.best
                                        ? 'rgba(26,76,212,.3)'
                                        : '#E2DFD6',
                                    background: opt.best ? '#FAFBFF' : 'white',
                                }}
                            >
                                <div
                                    className="flex size-10 shrink-0 items-center justify-center rounded-lg text-lg"
                                    style={{ background: '#EFEDE7' }}
                                >
                                    {opt.emoji}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div
                                        className="flex items-center gap-2"
                                        style={{
                                            fontSize: 14,
                                            fontWeight: 600,
                                        }}
                                    >
                                        {opt.mode === 'transit' && opt.line
                                            ? `Line ${opt.line}`
                                            : opt.mode === 'bike'
                                              ? 'Bike'
                                              : opt.mode === 'drive'
                                                ? 'Drive'
                                                : 'Walk'}
                                        {opt.best && (
                                            <span className="rounded-full bg-[#FEF9EC] px-1.5 py-px text-[9px] font-bold text-[#C47D0E] uppercase">
                                                Best
                                            </span>
                                        )}
                                        {opt.disrupted && (
                                            <span className="rounded-full bg-[#FDE8E6] px-1.5 py-px text-[9px] font-bold text-[#C4271A] uppercase">
                                                Disrupted
                                            </span>
                                        )}
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 12,
                                            color: '#6B6860',
                                            marginTop: 1,
                                        }}
                                    >
                                        {opt.detail}
                                    </div>
                                </div>
                                <div className="shrink-0 text-right">
                                    <div
                                        style={{
                                            fontFamily:
                                                "'Geist Mono', monospace",
                                            fontSize: 22,
                                            fontWeight: 500,
                                            lineHeight: 1,
                                            color: opt.best
                                                ? '#1A4CD4'
                                                : '#18170F',
                                        }}
                                    >
                                        {opt.time}
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 10,
                                            color: '#AAA89F',
                                        }}
                                    >
                                        min
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>

                    {loadingRoute && (
                        <div className="mb-3 animate-pulse rounded-[14px] bg-[#EFEDE7] p-4 text-center text-sm text-[#6B6860]">
                            Loading route...
                        </div>
                    )}

                    {/* Fallback maps buttons */}
                    <div className="flex gap-2">
                        <a
                            href={data.maps_url.google}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex flex-1 items-center justify-center gap-2 rounded-[9px] bg-[#1A4CD4] px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#1541B8]"
                            style={{ textDecoration: 'none' }}
                        >
                            Google Maps
                        </a>
                        <a
                            href={data.maps_url.apple}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex flex-1 items-center justify-center gap-2 rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 text-sm font-semibold text-[#18170F] transition-colors hover:bg-[#E2DFD6]"
                            style={{ textDecoration: 'none' }}
                        >
                            Apple Maps
                        </a>
                    </div>
                </>
            )}
        </div>
    );
}
