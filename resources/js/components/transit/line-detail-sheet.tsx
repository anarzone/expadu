type Disruption = {
    id: string;
    title: string;
    description: string;
    severity: string;
    type: string;
    affected_lines: string[];
};

type LineDeparture = {
    line: string;
    direction: string;
    color: string;
    type: string;
    departures: number[];
    stop_name: string;
    walk_min: number;
    disrupted: boolean;
    disruption_severity: string | null;
    towards_dest: boolean;
    dest_name: string | null;
};

// Warm color map matching the departure boards
const WARM_COLORS: Record<string, string> = {
    '1': '#E8914A', '3': '#7C3AED', '4': '#C4271A', '5': '#B8562A',
    '7': '#0A7C52', '9': '#1A4CD4', '12': '#0A7C52', '13': '#C47D0E',
    '15': '#1A4CD4', '16': '#0A7C52', '17': '#C4271A', '18': '#1A4CD4',
    'S11': '#C4271A', 'S12': '#C4271A', 'S13': '#C4271A', 'S19': '#C4271A',
    'RE1': '#7C3AED', 'RE5': '#E8914A', 'RE6': '#0A7C52', 'RE7': '#1A4CD4',
    'RE8': '#C4271A', 'RE9': '#7C3AED', 'RB24': '#C47D0E', 'RB25': '#0A7C52',
};

export function LineDetailSheet({
    departure,
    disruptions,
    onClose,
}: {
    departure: LineDeparture;
    disruptions: Disruption[];
    onClose: () => void;
}) {
    const bg = WARM_COLORS[departure.line] ?? departure.color;
    const lineDisruptions = disruptions.filter(
        (d) => d.affected_lines.includes(departure.line) || d.affected_lines.includes(String(departure.line)),
    );
    const isCancelled = departure.disrupted && departure.disruption_severity === 'critical';

    return (
        <div>
            {/* Header */}
            <div className="mb-4 flex items-center gap-3">
                <div
                    className="flex size-12 shrink-0 items-center justify-center rounded-xl text-white"
                    style={{
                        background: bg,
                        fontFamily: "'Geist Mono', monospace",
                        fontSize: departure.line.length > 2 ? 14 : 18,
                        fontWeight: 700,
                    }}
                >
                    {departure.line}
                </div>
                <div className="min-w-0 flex-1">
                    <div style={{ fontFamily: "'Fraunces', serif", fontSize: 20, fontWeight: 500 }}>
                        {departure.direction}
                    </div>
                    <div style={{ fontSize: 13, color: '#6B6860', marginTop: 2 }}>
                        {departure.type === 'rail' ? 'S-Bahn / Regional' : departure.type === 'bus' ? 'Bus' : 'Tram'} Line {departure.line} · from {departure.stop_name}
                    </div>
                </div>
            </div>

            {/* Upcoming departures */}
            <div className="mb-3 rounded-[14px] border border-[#E2DFD6] bg-white p-4">
                <div className="mb-3" style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F' }}>
                    Upcoming departures
                </div>
                {isCancelled ? (
                    <div className="rounded-[9px] bg-[#FDE8E6] py-3 text-center" style={{ fontSize: 14, fontWeight: 600, color: '#C4271A' }}>
                        Service cancelled
                    </div>
                ) : (
                    <div className="flex gap-2">
                        {departure.departures.slice(0, 5).map((min, i) => (
                            <div
                                key={i}
                                className="flex-1 rounded-[9px] border border-[#E2DFD6] py-2 text-center"
                                style={{ background: i === 0 ? '#EBF0FD' : 'white' }}
                            >
                                <div style={{ fontFamily: "'Geist Mono', monospace", fontSize: 18, fontWeight: 500, color: i === 0 ? '#1A4CD4' : '#18170F' }}>
                                    {min}
                                </div>
                                <div style={{ fontSize: 10, color: '#AAA89F' }}>min</div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Line info */}
            <div className="mb-3 rounded-[14px] border border-[#E2DFD6] bg-white p-4">
                <div className="flex items-center gap-3">
                    <div
                        className="flex size-9 shrink-0 items-center justify-center rounded-lg text-white"
                        style={{ background: bg, fontFamily: "'Geist Mono', monospace", fontSize: departure.line.length > 2 ? 10 : 13, fontWeight: 700 }}
                    >
                        {departure.line}
                    </div>
                    <div className="flex-1">
                        <div style={{ fontSize: 14, fontWeight: 600 }}>Line {departure.line} → {departure.direction}</div>
                        <div style={{ fontSize: 12, color: '#6B6860' }}>
                            {departure.stop_name} · {departure.walk_min} min walk · Static timetable
                        </div>
                    </div>
                    {!departure.disrupted && (
                        <span style={{ fontSize: 9, fontWeight: 700, padding: '2px 7px', borderRadius: 20, textTransform: 'uppercase', letterSpacing: '0.05em', background: '#D4F0E6', color: '#0A7C52' }}>
                            On time
                        </span>
                    )}
                </div>
            </div>

            {/* Disruptions */}
            {lineDisruptions.length > 0 && (
                <div className="mb-3">
                    <div className="mb-2" style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F' }}>
                        Active disruptions
                    </div>
                    <div className="flex flex-col gap-2">
                        {lineDisruptions.map((d) => (
                            <div
                                key={d.id}
                                className="rounded-[14px] border p-4"
                                style={{
                                    background: d.severity === 'critical' ? '#FDE8E6' : d.severity === 'major' ? '#FDF0D4' : '#EFEDE7',
                                    borderColor: d.severity === 'critical' ? 'rgba(196,39,26,0.15)' : d.severity === 'major' ? 'rgba(196,125,14,0.2)' : '#E2DFD6',
                                }}
                            >
                                <div className="flex items-start gap-2">
                                    <span style={{ fontSize: 16, flexShrink: 0, marginTop: 1 }}>
                                        {d.severity === 'critical' ? '🚫' : d.severity === 'major' ? '⚠️' : '🔧'}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 2 }}>{d.title}</div>
                                        {d.description && (
                                            <div style={{ fontSize: 12, color: '#6B6860', lineHeight: 1.5 }}>
                                                {d.description.length > 200 ? d.description.slice(0, 200) + '...' : d.description}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {lineDisruptions.length === 0 && !departure.disrupted && (
                <div className="mb-3 rounded-[14px] border border-[#D4F0E6] bg-[#F0FBF6] p-4 text-center">
                    <span style={{ fontSize: 14 }}>✅</span>
                    <div style={{ fontSize: 13, fontWeight: 600, color: '#0A7C52', marginTop: 4 }}>No disruptions</div>
                    <div style={{ fontSize: 12, color: '#6B6860', marginTop: 2 }}>This line is running normally</div>
                </div>
            )}

            {/* Google Maps link */}
            <a
                href={`https://www.google.com/maps/dir/?api=1&origin=${encodeURIComponent(departure.stop_name + ', Köln')}&travelmode=transit`}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center justify-center gap-2 rounded-[9px] bg-[#1A4CD4] px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#1541B8]"
                style={{ textDecoration: 'none' }}
            >
                🗺️ Open in Google Maps
            </a>
        </div>
    );
}
