type RouteOption = {
    mode: string;
    emoji: string;
    time: number;
    distance_km: number | null;
    detail: string;
    best: boolean;
    disrupted?: boolean;
    recommendation_reason?: string;
    geometry?: string | null;
};

const MODE_MAP: Record<string, { label: string; costing: string }> = {
    bike: { label: 'Bike', costing: 'bicycle' },
    walk: { label: 'Walk', costing: 'pedestrian' },
    drive: { label: 'Drive', costing: 'auto' },
    transit: { label: 'Transit', costing: 'multimodal' },
};

export function ModePicker({
    options,
    activeMode,
    onSelectMode,
    onClose,
    loading,
    destinationName,
}: {
    options: RouteOption[];
    activeMode: string | null;
    onSelectMode: (mode: string) => void;
    onClose: () => void;
    loading?: boolean;
    destinationName?: string;
}) {
    return (
        <div
            className="absolute bottom-0 left-0 right-0 z-30"
            style={{ background: 'linear-gradient(transparent, rgba(255,255,255,0.95) 20%, white)', padding: '32px 16px 16px' }}
        >
            {/* Destination label + close */}
            <div className="mb-2.5 flex items-center justify-between px-1">
                <span style={{ fontSize: 13, fontWeight: 600, color: '#18170F' }}>
                    {destinationName ? `To ${destinationName}` : 'Route options'}
                </span>
                <button
                    onClick={onClose}
                    className="flex cursor-pointer items-center justify-center border-none bg-transparent transition-colors hover:text-[#C4271A]"
                    style={{ width: 28, height: 28, borderRadius: '50%', fontSize: 16, color: '#AAA89F', background: '#EFEDE7' }}
                >
                    ✕
                </button>
            </div>

            {/* Mode buttons */}
            <div className="flex gap-2">
                {options.map((opt) => {
                    const isActive = activeMode === opt.mode;
                    const info = MODE_MAP[opt.mode] ?? { label: opt.mode, costing: opt.mode };

                    return (
                        <button
                            key={opt.mode}
                            onClick={() => onSelectMode(opt.mode)}
                            disabled={loading}
                            className="flex flex-1 cursor-pointer flex-col items-center gap-0.5 transition-all"
                            style={{
                                padding: '10px 4px',
                                borderRadius: 12,
                                border: isActive ? '2px solid #1A4CD4' : '2px solid #E2DFD6',
                                background: isActive ? '#EBF0FD' : 'white',
                                opacity: loading && !isActive ? 0.5 : 1,
                                position: 'relative',
                            }}
                        >
                            {/* Best badge */}
                            {opt.best && (
                                <span style={{
                                    position: 'absolute', top: -6, right: -4,
                                    fontSize: 8, fontWeight: 700, padding: '1px 5px',
                                    borderRadius: 20, background: '#FEF9EC', color: '#C47D0E',
                                    textTransform: 'uppercase',
                                }}>Best</span>
                            )}
                            {/* Disrupted badge */}
                            {opt.disrupted && (
                                <span style={{
                                    position: 'absolute', top: -6, right: -4,
                                    fontSize: 10,
                                }}>⚠️</span>
                            )}
                            <span style={{ fontSize: 20 }}>{opt.emoji}</span>
                            <span style={{
                                fontFamily: "'Geist Mono', monospace",
                                fontSize: 16, fontWeight: 600,
                                color: isActive ? '#1A4CD4' : '#18170F',
                                lineHeight: 1,
                            }}>
                                {opt.time}<span style={{ fontSize: 9, color: '#AAA89F', marginLeft: 1 }}>m</span>
                            </span>
                            <span style={{ fontSize: 10, color: '#6B6860' }}>{info.label}</span>
                        </button>
                    );
                })}
            </div>

            {/* Recommendation reason */}
            {options.find((o) => o.best)?.recommendation_reason && (
                <div className="mt-2 px-1 text-center" style={{ fontSize: 11, color: '#0A7C52' }}>
                    {options.find((o) => o.best)?.recommendation_reason}
                </div>
            )}
        </div>
    );
}
