export type RouteCardData = {
    id: string;
    badge: string;
    badgeMono?: boolean;
    name: string;
    detail: string;
    time: number;
    showTime?: boolean;
    statusColor: string; // '#4ADE80' ok, '#FCD34D' warn
    best?: boolean;
};

export function RouteCard({
    route,
    onClick,
}: {
    route: RouteCardData;
    onClick?: () => void;
}) {
    return (
        <div
            onClick={onClick}
            className="flex cursor-pointer items-center gap-3 transition-all"
            style={{
                background: route.best
                    ? 'rgba(255,255,255,.20)'
                    : 'rgba(255,255,255,.12)',
                borderRadius: 9,
                padding: '12px 14px',
                border: route.best
                    ? '2px solid rgba(255,255,255,.4)'
                    : '2px solid transparent',
            }}
        >
            {/* Badge */}
            <div
                className="flex shrink-0 items-center justify-center"
                style={{
                    width: 34,
                    height: 34,
                    borderRadius: 8,
                    background: 'rgba(255,255,255,.2)',
                    fontSize: route.badgeMono ? 13 : 16,
                    fontFamily: route.badgeMono
                        ? "'Geist Mono', monospace"
                        : undefined,
                    fontWeight: route.badgeMono ? 700 : undefined,
                }}
            >
                {route.badge}
            </div>

            {/* Info */}
            <div className="min-w-0 flex-1">
                <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 1 }}>
                    {route.name}
                    {route.best && (
                        <span
                            style={{
                                fontSize: 9,
                                fontWeight: 700,
                                background: 'rgba(255,255,255,.25)',
                                padding: '2px 7px',
                                borderRadius: 20,
                                marginLeft: 6,
                                textTransform: 'uppercase',
                                letterSpacing: '0.05em',
                            }}
                        >
                            ⭐ Best
                        </span>
                    )}
                </div>
                <div style={{ fontSize: 11, opacity: 0.75 }}>
                    {route.detail}
                </div>
            </div>

            {/* Time */}
            {route.showTime !== false && (
                <div className="shrink-0 text-right">
                    <div
                        style={{
                            fontFamily: "'Geist Mono', monospace",
                            fontSize: 22,
                            fontWeight: 500,
                            lineHeight: 1,
                        }}
                    >
                        {route.time}
                    </div>
                    <div style={{ fontSize: 10, opacity: 0.6, marginTop: 1 }}>
                        min
                    </div>
                </div>
            )}

            {/* Status dot */}
            <div
                className="shrink-0"
                style={{
                    width: 8,
                    height: 8,
                    borderRadius: '50%',
                    background: route.statusColor,
                }}
            />
        </div>
    );
}
