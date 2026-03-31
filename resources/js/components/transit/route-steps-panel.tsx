type Step = {
    instruction: string;
    distance_km: number;
    time_sec: number;
    type: string;
    emoji: string;
};

const MODE_LABELS: Record<string, { emoji: string; label: string }> = {
    pedestrian: { emoji: '🚶', label: 'Walk' },
    bicycle: { emoji: '🚲', label: 'Bike' },
    auto: { emoji: '🚗', label: 'Drive' },
    bike: { emoji: '🚲', label: 'Bike' },
    walk: { emoji: '🚶', label: 'Walk' },
    drive: { emoji: '🚗', label: 'Drive' },
};

function formatDist(km: number): string {
    return km < 1 ? `${Math.round(km * 1000)}m` : `${km.toFixed(1)} km`;
}

function formatTime(sec: number): string {
    if (sec < 60) return `${sec}s`;
    const min = Math.round(sec / 60);
    return `${min} min`;
}

export function RouteStepsPanel({
    mode,
    durationMin,
    distanceKm,
    steps,
    mapsUrl,
}: {
    mode: string;
    durationMin: number;
    distanceKm: number;
    steps: Step[];
    mapsUrl?: { google: string; apple: string };
}) {
    const modeInfo = MODE_LABELS[mode] ?? { emoji: '📍', label: 'Route' };

    return (
        <div>
            {/* Summary header */}
            <div className="mb-3 flex items-center justify-between rounded-[14px] border border-[#E2DFD6] bg-white p-4">
                <div className="flex items-center gap-2">
                    <span style={{ fontSize: 20 }}>{modeInfo.emoji}</span>
                    <span style={{ fontSize: 15, fontWeight: 600 }}>{modeInfo.label}</span>
                </div>
                <div className="flex items-center gap-3">
                    <span style={{ fontFamily: "'Geist Mono', monospace", fontSize: 22, fontWeight: 500, color: '#1A4CD4' }}>
                        {durationMin}
                    </span>
                    <span style={{ fontSize: 11, color: '#AAA89F' }}>min</span>
                    <span style={{ fontSize: 13, color: '#6B6860' }}>·</span>
                    <span style={{ fontSize: 13, color: '#6B6860' }}>{distanceKm} km</span>
                </div>
            </div>

            {/* Steps list */}
            {steps.length > 0 && (
                <div className="mb-3 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
                    <div className="border-b border-[#E2DFD6] bg-[#EFEDE7] px-4 py-2.5">
                        <span style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F' }}>
                            Directions · {steps.length} steps
                        </span>
                    </div>
                    {steps.map((step, i) => (
                        <div
                            key={i}
                            className="flex items-start gap-3"
                            style={{
                                padding: '12px 16px',
                                borderBottom: i < steps.length - 1 ? '1px solid #E2DFD6' : 'none',
                            }}
                        >
                            <span style={{ fontSize: 16, flexShrink: 0, marginTop: 1, width: 24, textAlign: 'center' }}>
                                {step.emoji}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div style={{ fontSize: 13, fontWeight: 500, lineHeight: 1.4 }}>{step.instruction}</div>
                                {step.distance_km > 0 && (
                                    <div style={{ fontSize: 11, color: '#AAA89F', marginTop: 2, fontFamily: "'Geist Mono', monospace" }}>
                                        {formatDist(step.distance_km)} · {formatTime(step.time_sec)}
                                    </div>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Maps buttons */}
            {mapsUrl && (
                <div className="flex gap-2">
                    <a
                        href={mapsUrl.google}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex flex-1 items-center justify-center gap-2 rounded-[9px] bg-[#1A4CD4] px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#1541B8]"
                        style={{ textDecoration: 'none' }}
                    >
                        Google Maps
                    </a>
                    <a
                        href={mapsUrl.apple}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex flex-1 items-center justify-center gap-2 rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 text-sm font-semibold text-[#18170F] transition-colors hover:bg-[#E2DFD6]"
                        style={{ textDecoration: 'none' }}
                    >
                        Apple Maps
                    </a>
                </div>
            )}
        </div>
    );
}
