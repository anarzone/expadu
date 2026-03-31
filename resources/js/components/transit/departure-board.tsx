import { useState } from 'react';

export type DepartureService = {
    line: string;
    direction: string;
    via: string;
    color: string;
    bg: string;
    delay: number;
    cancelled: boolean;
    extra: boolean;
    departures: number[];
    routeKey: string | null;
    savedDest: boolean;
    hidden?: boolean;
};

export type BoardData = {
    stop: string;
    icon: string;
    services: DepartureService[];
};

function sortServices(services: DepartureService[]) {
    return [...services].sort((a, b) => {
        if (a.cancelled && !b.cancelled) return 1;
        if (!a.cancelled && b.cancelled) return -1;
        if (a.savedDest && !b.savedDest) return -1;
        if (!a.savedDest && b.savedDest) return 1;
        const aNext = a.departures[0] ?? 999;
        const bNext = b.departures[0] ?? 999;
        return aNext - bNext;
    });
}

export function DepartureBoard({
    data,
    showMore,
    onOpenRoute,
}: {
    data: BoardData;
    showMore: boolean;
    onOpenRoute?: (key: string) => void;
}) {
    const visibleServices = data.services.filter((s) => showMore || !s.hidden);
    const sorted = sortServices(visibleServices);

    // Line pills for the header
    const pills = visibleServices;

    return (
        <div
            style={{
                background: '#FFFFFF',
                border: '1px solid #E2DFD6',
                borderRadius: 14,
                overflow: 'hidden',
            }}
        >
            {/* Header */}
            <div
                className="flex items-center justify-between"
                style={{
                    padding: '12px 16px',
                    borderBottom: '1px solid #E2DFD6',
                    background: '#EFEDE7',
                }}
            >
                <span style={{ fontSize: 13, fontWeight: 700 }}>
                    {data.icon} {data.stop}
                </span>
                <div className="flex gap-[5px]">
                    {pills.map((s) => (
                        <span
                            key={`${s.line}_${s.direction}`}
                            style={{
                                padding: '2px 8px',
                                borderRadius: 20,
                                fontSize: 11,
                                fontWeight: 700,
                                fontFamily: "'Geist Mono', monospace",
                                background: s.bg,
                                color: s.color,
                            }}
                        >
                            {s.line}
                        </span>
                    ))}
                </div>
            </div>

            {/* Rows */}
            {sorted.map((s, i) => (
                <ServiceRow key={`${s.line}_${s.direction}`} service={s} onOpenRoute={onOpenRoute} isLast={i === sorted.length - 1} />
            ))}
        </div>
    );
}

function ServiceRow({ service: s, onOpenRoute, isLast }: { service: DepartureService; onOpenRoute?: (key: string) => void; isLast?: boolean }) {
    // Status tag
    let statusTag: React.ReactNode;
    let nextClass: string;
    let nextDisplay: string | number;

    if (s.cancelled) {
        statusTag = (
            <span style={{ fontSize: 9, fontWeight: 700, padding: '2px 7px', borderRadius: 20, textTransform: 'uppercase', letterSpacing: '0.05em', background: '#FDE8E6', color: '#C4271A' }}>
                Cancelled
            </span>
        );
        nextClass = 'cancelled';
        nextDisplay = '—';
    } else if (s.delay > 0) {
        statusTag = (
            <span style={{ fontSize: 9, fontWeight: 700, padding: '2px 7px', borderRadius: 20, textTransform: 'uppercase', letterSpacing: '0.05em', background: '#FDF0D4', color: '#C47D0E' }}>
                +{s.delay} min delay
            </span>
        );
        nextClass = 'delayed';
        nextDisplay = s.departures[0];
    } else if (s.extra) {
        statusTag = (
            <span style={{ fontSize: 9, fontWeight: 700, padding: '2px 7px', borderRadius: 20, textTransform: 'uppercase', letterSpacing: '0.05em', background: '#EBF0FD', color: '#1A4CD4' }}>
                Extra service
            </span>
        );
        nextClass = 'on-time';
        nextDisplay = s.departures[0];
    } else {
        statusTag = (
            <span style={{ fontSize: 9, fontWeight: 700, padding: '2px 7px', borderRadius: 20, textTransform: 'uppercase', letterSpacing: '0.05em', background: '#D4F0E6', color: '#0A7C52' }}>
                On time
            </span>
        );
        nextClass = 'on-time';
        nextDisplay = s.departures[0];
    }

    const following = s.departures.slice(1);
    const badgeFontSize = s.line.length > 1 ? 11 : 13;

    const savedStyle: React.CSSProperties = s.savedDest ? { borderLeft: '3px solid #1A4CD4', paddingLeft: 13 } : {};

    return (
        <div
            onClick={() => s.routeKey && onOpenRoute?.(s.routeKey)}
            className="flex items-center gap-3 transition-colors hover:bg-[#EFEDE7]"
            style={{
                padding: '12px 16px',
                borderBottom: isLast ? 'none' : '1px solid #E2DFD6',
                cursor: s.routeKey ? 'pointer' : 'default',
                opacity: s.cancelled ? 0.7 : 1,
                ...savedStyle,
            }}
        >
            {/* Line badge */}
            <div
                className="flex shrink-0 items-center justify-center"
                style={{
                    width: 34,
                    height: 34,
                    borderRadius: 8,
                    background: s.bg,
                    color: s.color,
                    fontFamily: "'Geist Mono', monospace",
                    fontSize: badgeFontSize,
                    fontWeight: 700,
                }}
            >
                {s.line}
            </div>

            {/* Info */}
            <div className="min-w-0 flex-1">
                <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 2 }}>
                    {s.direction}
                    {s.savedDest && (
                        <span
                            style={{
                                fontSize: 10,
                                background: '#EBF0FD',
                                color: '#1A4CD4',
                                padding: '1px 6px',
                                borderRadius: 20,
                                fontWeight: 700,
                                marginLeft: 5,
                            }}
                        >
                            Your route
                        </span>
                    )}
                </div>
                <div style={{ fontSize: 11, color: '#6B6860' }}>{s.via}</div>
                <div style={{ fontSize: 11, marginTop: 2 }}>{statusTag}</div>
            </div>

            {/* Times */}
            <div className="shrink-0 text-right">
                {s.cancelled ? (
                    <div style={{ fontFamily: "'Geist Mono', monospace", fontSize: 22, fontWeight: 500, lineHeight: 1, color: '#C4271A' }}>—</div>
                ) : (
                    <>
                        <div
                            style={{
                                fontFamily: "'Geist Mono', monospace",
                                fontSize: 22,
                                fontWeight: 500,
                                lineHeight: 1,
                                color: nextClass === 'on-time' ? '#0A7C52' : nextClass === 'delayed' ? '#C47D0E' : '#18170F',
                            }}
                        >
                            {nextDisplay}
                        </div>
                        <div style={{ fontSize: 10, color: '#AAA89F', marginTop: 1 }}>min</div>
                    </>
                )}
                {following.length > 0 && (
                    <div style={{ fontSize: 11, color: '#AAA89F', fontFamily: "'Geist Mono', monospace", marginTop: 3 }}>
                        then {following.join(', ')} min
                    </div>
                )}
            </div>
        </div>
    );
}
