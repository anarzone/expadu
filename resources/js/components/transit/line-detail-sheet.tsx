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
    '1': '#E8914A',
    '3': '#7C3AED',
    '4': '#C4271A',
    '5': '#B8562A',
    '7': '#0A7C52',
    '9': '#1A4CD4',
    '12': '#0A7C52',
    '13': '#C47D0E',
    '15': '#1A4CD4',
    '16': '#0A7C52',
    '17': '#C4271A',
    '18': '#1A4CD4',
    S11: '#C4271A',
    S12: '#C4271A',
    S13: '#C4271A',
    S19: '#C4271A',
    RE1: '#7C3AED',
    RE5: '#E8914A',
    RE6: '#0A7C52',
    RE7: '#1A4CD4',
    RE8: '#C4271A',
    RE9: '#7C3AED',
    RB24: '#C47D0E',
    RB25: '#0A7C52',
};

export function LineDetailSheet({
    departure,
    disruptions,
    onClose: _onClose,
}: {
    departure: LineDeparture;
    disruptions: Disruption[];
    onClose: () => void;
}) {
    const bg = WARM_COLORS[departure.line] ?? departure.color;
    const lineDisruptions = disruptions.filter(
        (d) =>
            d.affected_lines.includes(departure.line) ||
            d.affected_lines.includes(String(departure.line)),
    );
    const isCancelled =
        departure.disrupted && departure.disruption_severity === 'critical';

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
                    <div
                        style={{
                            fontFamily: "'Fraunces', serif",
                            fontSize: 20,
                            fontWeight: 500,
                        }}
                    >
                        {departure.direction}
                    </div>
                    <div className="mt-0.5 text-[13px] text-muted-foreground">
                        {departure.type === 'rail'
                            ? 'S-Bahn / Regional'
                            : departure.type === 'bus'
                              ? 'Bus'
                              : 'Tram'}{' '}
                        Line {departure.line} · from {departure.stop_name}
                    </div>
                </div>
            </div>

            {/* Upcoming departures */}
            <div className="mb-3 rounded-[14px] border border-border bg-card p-4">
                <div className="mb-3 text-[11px] font-bold tracking-[0.08em] text-muted-foreground uppercase">
                    Upcoming departures
                </div>
                {isCancelled ? (
                    <div className="rounded-[9px] bg-danger-soft py-3 text-center text-sm font-semibold text-danger">
                        Service cancelled
                    </div>
                ) : (
                    <div className="flex gap-2">
                        {departure.departures.slice(0, 5).map((min, i) => (
                            <div
                                key={i}
                                className={`flex-1 rounded-[9px] border py-2 text-center ${
                                    i === 0
                                        ? 'border-primary/20 bg-primary/10'
                                        : 'border-border bg-card'
                                }`}
                            >
                                <div
                                    className={`font-mono text-lg font-medium ${
                                        i === 0
                                            ? 'text-primary'
                                            : 'text-foreground'
                                    }`}
                                >
                                    {min}
                                </div>
                                <div className="text-[10px] text-muted-foreground">
                                    min
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Line info */}
            <div className="mb-3 rounded-[14px] border border-border bg-card p-4">
                <div className="flex items-center gap-3">
                    <div
                        className="flex size-9 shrink-0 items-center justify-center rounded-lg text-white"
                        style={{
                            background: bg,
                            fontFamily: "'Geist Mono', monospace",
                            fontSize: departure.line.length > 2 ? 10 : 13,
                            fontWeight: 700,
                        }}
                    >
                        {departure.line}
                    </div>
                    <div className="flex-1">
                        <div className="text-sm font-semibold">
                            Line {departure.line} → {departure.direction}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {departure.stop_name} · {departure.walk_min} min
                            walk · Static timetable
                        </div>
                    </div>
                    {!departure.disrupted && (
                        <span className="rounded-full bg-success-soft px-[7px] py-0.5 text-[9px] font-bold tracking-[0.05em] text-success uppercase">
                            On time
                        </span>
                    )}
                </div>
            </div>

            {/* Disruptions */}
            {lineDisruptions.length > 0 && (
                <div className="mb-3">
                    <div className="mb-2 text-[11px] font-bold tracking-[0.08em] text-muted-foreground uppercase">
                        Active disruptions
                    </div>
                    <div className="flex flex-col gap-2">
                        {lineDisruptions.map((d) => (
                            <div
                                key={d.id}
                                className={`rounded-[14px] border p-4 ${
                                    d.severity === 'critical'
                                        ? 'border-danger/15 bg-danger-soft'
                                        : d.severity === 'major'
                                          ? 'border-warn/20 bg-warn-soft'
                                          : 'border-border bg-surface-2'
                                }`}
                            >
                                <div className="flex items-start gap-2">
                                    <span className="mt-0.5 shrink-0 text-base">
                                        {d.severity === 'critical'
                                            ? '🚫'
                                            : d.severity === 'major'
                                              ? '⚠️'
                                              : '🔧'}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="mb-0.5 text-[13px] font-semibold">
                                            {d.title}
                                        </div>
                                        {d.description && (
                                            <div className="text-xs leading-relaxed text-muted-foreground">
                                                {d.description.length > 200
                                                    ? d.description.slice(
                                                          0,
                                                          200,
                                                      ) + '...'
                                                    : d.description}
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
                <div className="mb-3 rounded-[14px] border border-success/20 bg-success-soft p-4 text-center">
                    <span className="text-sm">✅</span>
                    <div className="mt-1 text-[13px] font-semibold text-success">
                        No disruptions
                    </div>
                    <div className="mt-0.5 text-xs text-muted-foreground">
                        This line is running normally
                    </div>
                </div>
            )}
        </div>
    );
}
