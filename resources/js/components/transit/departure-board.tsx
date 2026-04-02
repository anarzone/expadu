import { useEffect, useState } from 'react';

/** Format departure minutes: 0 → "now", 1-60 → "5", >60 → "05:13" */
export function formatDepartureTime(mins: number): {
    display: string;
    isClockTime: boolean;
} {
    if (mins === 0) return { display: 'now', isClockTime: false };
    if (mins <= 60) return { display: String(mins), isClockTime: false };
    const d = new Date(Date.now() + mins * 60_000);
    return {
        display: d.toLocaleTimeString('de-DE', {
            hour: '2-digit',
            minute: '2-digit',
        }),
        isClockTime: true,
    };
}

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

/** Live digital clock that ticks every second */
export function useClock(): string {
    const [time, setTime] = useState(() =>
        new Date().toLocaleTimeString('de-DE', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        }),
    );
    useEffect(() => {
        const id = setInterval(
            () =>
                setTime(
                    new Date().toLocaleTimeString('de-DE', {
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                    }),
                ),
            1000,
        );
        return () => clearInterval(id);
    }, []);
    return time;
}

export function DepartureBoard({
    data,
    showMore,
    onOpenRoute,
    isLive,
}: {
    data: BoardData;
    showMore: boolean;
    onOpenRoute?: (key: string) => void;
    isLive?: boolean;
}) {
    const visibleServices = data.services.filter((s) => showMore || !s.hidden);
    const sorted = sortServices(visibleServices);
    const clock = useClock();

    const pills = visibleServices;

    return (
        <div className="overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]">
            {/* Header */}
            <div className="flex items-center justify-between border-b border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 dark:border-[#3A3930] dark:bg-[#2A2920]">
                <div className="flex items-center gap-2">
                    <span className="text-[13px] font-bold dark:text-[#F5F4F0]">
                        {data.icon} {data.stop}
                    </span>
                    <span className="rounded-md bg-[#DDDBD4] px-2 py-[2px] font-mono text-xs font-semibold text-[#6B6860] dark:bg-[#3A3930] dark:text-[#AAA89F]">
                        {clock}
                    </span>
                    {isLive && (
                        <span className="flex items-center gap-1 rounded-full bg-[#D4F0E6] px-[7px] py-[2px] text-[10px] font-bold text-[#0A7C52] dark:bg-[#0A7C52]/20">
                            <span className="inline-block size-[5px] animate-pulse rounded-full bg-[#0A7C52]" />
                            Live
                        </span>
                    )}
                </div>
                <div className="flex gap-[5px]">
                    {pills.map((s) => (
                        <span
                            key={`${s.line}_${s.direction}`}
                            className="rounded-full px-2 py-[2px] font-mono text-[11px] font-bold"
                            style={{ background: s.bg, color: s.color }}
                        >
                            {s.line}
                        </span>
                    ))}
                </div>
            </div>

            {/* Rows */}
            {sorted.map((s, i) => (
                <ServiceRow
                    key={`${s.line}_${s.direction}`}
                    service={s}
                    onOpenRoute={onOpenRoute}
                    isLast={i === sorted.length - 1}
                />
            ))}
        </div>
    );
}

function ServiceRow({
    service: s,
    onOpenRoute,
    isLast,
}: {
    service: DepartureService;
    onOpenRoute?: (key: string) => void;
    isLast?: boolean;
}) {
    let statusTag: React.ReactNode;
    let nextClass: string;
    let nextDisplay: string | number;

    if (s.cancelled) {
        statusTag = (
            <span className="rounded-full bg-[#FDE8E6] px-[7px] py-[2px] text-[9px] font-bold tracking-wider text-[#C4271A] uppercase dark:bg-[#C4271A]/20">
                Cancelled
            </span>
        );
        nextClass = 'cancelled';
        nextDisplay = '—';
    } else if (s.delay > 0) {
        statusTag = (
            <span className="rounded-full bg-[#FDF0D4] px-[7px] py-[2px] text-[9px] font-bold tracking-wider text-[#C47D0E] uppercase dark:bg-[#C47D0E]/20">
                +{s.delay} min delay
            </span>
        );
        nextClass = 'delayed';
        nextDisplay = formatDepartureTime(s.departures[0]).display;
    } else if (s.extra) {
        statusTag = (
            <span className="rounded-full bg-[#EBF0FD] px-[7px] py-[2px] text-[9px] font-bold tracking-wider text-[#1A4CD4] uppercase dark:bg-[#1A4CD4]/20">
                Extra service
            </span>
        );
        nextClass = 'on-time';
        nextDisplay = formatDepartureTime(s.departures[0]).display;
    } else {
        statusTag = (
            <span className="rounded-full bg-[#D4F0E6] px-[7px] py-[2px] text-[9px] font-bold tracking-wider text-[#0A7C52] uppercase dark:bg-[#0A7C52]/20">
                On time
            </span>
        );
        nextClass = 'on-time';
        nextDisplay = formatDepartureTime(s.departures[0]).display;
    }

    const following = s.departures.slice(1);
    const badgeFontSize = s.line.length > 1 ? 11 : 13;

    return (
        <div
            onClick={() => s.routeKey && onOpenRoute?.(s.routeKey)}
            className={`flex items-center gap-3 transition-colors hover:bg-[#EFEDE7] dark:hover:bg-[#2A2920] ${!isLast ? 'border-b border-[#E2DFD6] dark:border-[#3A3930]' : ''} ${s.savedDest ? 'border-l-[3px] border-l-[#1A4CD4] pl-[13px]' : ''}`}
            style={{
                padding: s.savedDest ? '12px 16px 12px 13px' : '12px 16px',
                cursor: s.routeKey ? 'pointer' : 'default',
                opacity: s.cancelled ? 0.7 : 1,
            }}
        >
            {/* Line badge */}
            <div
                className="flex shrink-0 items-center justify-center rounded-lg font-mono font-bold"
                style={{
                    width: 34,
                    height: 34,
                    background: s.bg,
                    color: s.color,
                    fontSize: badgeFontSize,
                }}
            >
                {s.line}
            </div>

            {/* Info */}
            <div className="min-w-0 flex-1">
                <div className="mb-[2px] text-[13px] font-semibold dark:text-[#F5F4F0]">
                    {s.direction}
                    {s.savedDest && (
                        <span className="ml-[5px] rounded-full bg-[#EBF0FD] px-[6px] py-[1px] text-[10px] font-bold text-[#1A4CD4] dark:bg-[#1A4CD4]/20">
                            Your route
                        </span>
                    )}
                </div>
                <div className="text-[11px] text-[#6B6860] dark:text-[#AAA89F]">
                    {s.via}
                </div>
                <div className="mt-[2px] text-[11px]">{statusTag}</div>
            </div>

            {/* Times */}
            <div className="shrink-0 text-right">
                {s.cancelled ? (
                    <div className="font-mono text-[22px] leading-none font-medium text-[#C4271A]">
                        —
                    </div>
                ) : (
                    <>
                        <div
                            className="font-mono text-[22px] leading-none font-medium"
                            style={{
                                color:
                                    nextClass === 'on-time'
                                        ? '#0A7C52'
                                        : nextClass === 'delayed'
                                          ? '#C47D0E'
                                          : undefined,
                            }}
                        >
                            {nextDisplay}
                        </div>
                        {!formatDepartureTime(s.departures[0]).isClockTime &&
                            nextDisplay !== 'now' && (
                                <div className="mt-[1px] text-[10px] text-[#AAA89F]">
                                    min
                                </div>
                            )}
                    </>
                )}
                {following.length > 0 && (
                    <div className="mt-[3px] font-mono text-[11px] text-[#AAA89F] dark:text-[#6B6860]">
                        then{' '}
                        {following
                            .map((m) => {
                                const f = formatDepartureTime(m);
                                return f.isClockTime ? f.display : `${m}`;
                            })
                            .join(', ')}
                        {!formatDepartureTime(following[0]).isClockTime &&
                            ' min'}
                    </div>
                )}
            </div>
        </div>
    );
}
