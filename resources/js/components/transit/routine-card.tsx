export type RoutineCardData = {
    id: string;
    emoji: string;
    name: string;
    subtitle: string;
    badge: string;
    badgeBg: string;
    badgeColor: string;
    days: boolean[]; // [M, T, W, T, F, S, S]
    leaveBy: string;
};

const DAY_LABELS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

export function RoutineCard({ routine, onClick }: { routine: RoutineCardData; onClick?: () => void }) {
    return (
        <div
            onClick={onClick}
            className="cursor-pointer transition-all"
            style={{
                background: '#FFFFFF',
                border: '1px solid #E2DFD6',
                borderRadius: 14,
                padding: 16,
                marginBottom: 10,
            }}
        >
            {/* Top row */}
            <div className="mb-3 flex items-center gap-3">
                <span style={{ fontSize: 24, flexShrink: 0 }}>{routine.emoji}</span>
                <div className="min-w-0 flex-1">
                    <div style={{ fontSize: 15, fontWeight: 600, marginBottom: 2 }}>{routine.name}</div>
                    <div style={{ fontSize: 12, color: '#6B6860' }}>{routine.subtitle}</div>
                </div>
                <span
                    className="shrink-0"
                    style={{
                        fontSize: 11,
                        fontWeight: 600,
                        padding: '4px 10px',
                        borderRadius: 20,
                        background: routine.badgeBg,
                        color: routine.badgeColor,
                    }}
                >
                    {routine.badge}
                </span>
            </div>

            {/* Bottom row: day indicators + leave time */}
            <div className="flex items-center gap-2">
                <div className="flex gap-1">
                    {routine.days.map((active, i) => (
                        <div
                            key={i}
                            className="flex items-center justify-center"
                            style={{
                                width: 24,
                                height: 24,
                                borderRadius: '50%',
                                fontSize: 10,
                                fontWeight: 700,
                                background: active ? '#EBF0FD' : '#EFEDE7',
                                color: active ? '#1A4CD4' : '#AAA89F',
                            }}
                        >
                            {DAY_LABELS[i]}
                        </div>
                    ))}
                </div>
                <div
                    className="ml-auto"
                    style={{
                        fontSize: 12,
                        color: '#6B6860',
                        fontFamily: "'Geist Mono', monospace",
                    }}
                >
                    {routine.leaveBy}
                </div>
            </div>
        </div>
    );
}
