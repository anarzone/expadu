import { usePage } from '@inertiajs/react';
import { useState } from 'react';

type WeatherData = {
    temperature: number;
    feels_like?: number;
    emoji: string;
    condition: string;
    wind_speed: number;
    wind_gust?: number;
    humidity: number;
    precipitation: number;
};

type ForecastData = {
    rain_starts: string | null;
    bike_score: string;
};

type TodayEvent = {
    time: string;
    emoji: string;
    title: string;
    location: string;
    badge: string;
    badgeType: 'free' | 'category';
};

type RhineData = {
    level_cm: number;
    trend: 'rising' | 'falling' | 'stable';
    status: 'normal' | 'low' | 'high' | 'warning';
    timestamp: string;
};

type DisruptionItem = {
    title: string;
    severity: string;
    lines: string[];
};

export function RightPanel() {
    const { weather, forecast, todayEvents, rhineLevel, activeDisruptions } =
        usePage<{
            weather?: WeatherData;
            forecast?: ForecastData;
            todayEvents?: TodayEvent[];
            rhineLevel?: RhineData | null;
            activeDisruptions?: DisruptionItem[];
        }>().props;

    return (
        <aside
            className="hidden w-[390px] shrink-0 overflow-y-auto p-5 lg:block"
            style={{ scrollbarWidth: 'none' }}
        >
            <WeatherWidget weather={weather} forecast={forecast} />
            <RhineWidget data={rhineLevel} />
            <DisruptionsWidget disruptions={activeDisruptions} />
            <TodayEventsWidget events={todayEvents} />
            <div className="pt-4 text-center text-[11px] text-muted-foreground">
                Updated <span>just now</span>
            </div>
        </aside>
    );
}

function WeatherWidget({
    weather,
    forecast,
}: {
    weather?: WeatherData;
    forecast?: ForecastData;
}) {
    const temp = weather?.temperature ?? 0;
    const feelsLike = weather?.feels_like ?? temp;
    const emoji = weather?.emoji ?? '⛅';
    const condition = weather?.condition ?? 'Partly cloudy';
    const wind = weather?.wind_speed ?? 0;
    const gust = weather?.wind_gust ?? 0;
    const humidity = weather?.humidity ?? 0;
    const rainStarts = forecast?.rain_starts;
    const bikeScore = forecast?.bike_score ?? 'Good';
    const rainLabel = rainStarts ?? 'None today';
    const rainVariant: 'good' | 'caution' = rainStarts ? 'caution' : 'good';

    return (
        <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                <span className="text-[13px] font-bold">Weather · Cologne</span>
                <div className="flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-[10px] font-semibold text-success">
                    <span className="inline-block size-[5px] animate-pulse rounded-full bg-success" />
                    Live
                </div>
            </div>
            <div className="flex items-start justify-between px-4 py-4">
                <div>
                    <div className="font-display text-[44px] leading-none font-light tracking-tight">
                        {temp}
                        <sup className="align-super text-lg">°</sup>
                    </div>
                    <div className="mt-1 text-xs text-muted-foreground">
                        {condition}
                    </div>
                    <div className="text-[11px] text-muted-foreground">
                        {rainStarts
                            ? `Rain from ${rainStarts}`
                            : (weather?.precipitation ?? 0) > 0 ||
                                condition === 'Rain'
                              ? 'Rain clearing later'
                              : 'No rain expected'}
                    </div>
                </div>
                <div className="text-[44px] opacity-85">{emoji}</div>
            </div>
            <div className="border-t border-border">
                {feelsLike !== temp && (
                    <WeatherRow
                        emoji="🌡️"
                        label="Feels like"
                        value={`${feelsLike}°`}
                        variant={feelsLike < 0 ? 'caution' : 'good'}
                    />
                )}
                <WeatherRow
                    emoji="🌬️"
                    label="Wind"
                    value={`${wind} km/h${gust && gust > wind ? ` (gusts ${gust})` : ''}`}
                    variant={wind > 25 ? 'caution' : 'good'}
                />
                <WeatherRow
                    emoji="💧"
                    label="Humidity"
                    value={`${humidity}%`}
                />
                <WeatherRow
                    emoji="🚲"
                    label="Bike score"
                    value={bikeScore}
                    variant="good"
                />
                <WeatherRow
                    emoji="🌧️"
                    label="Rain arrives"
                    value={rainLabel}
                    variant={rainVariant}
                />
            </div>
        </div>
    );
}

function WeatherRow({
    emoji,
    label,
    value,
    variant,
}: {
    emoji: string;
    label: string;
    value: string;
    variant?: 'good' | 'caution';
}) {
    const valueColor =
        variant === 'good'
            ? 'text-success'
            : variant === 'caution'
              ? 'text-warn'
              : 'text-foreground';

    return (
        <div className="flex items-center justify-between border-b border-border px-4 py-2.5 text-xs last:border-b-0">
            <span className="flex items-center gap-[7px] text-muted-foreground">
                <span className="text-sm">{emoji}</span>
                {label}
            </span>
            <span className={`font-mono font-medium ${valueColor}`}>
                {value}
            </span>
        </div>
    );
}

function RhineWidget({ data }: { data?: RhineData | null }) {
    const meters = data ? (data.level_cm / 100).toFixed(2) : null;
    const statusLabel =
        data?.status === 'warning' || data?.status === 'high'
            ? 'Rheinufer paths may flood'
            : data?.status === 'low'
              ? 'Low water level'
              : 'Rheinufer paths open';
    const valueColor =
        data?.status === 'warning' || data?.status === 'high'
            ? 'text-warn'
            : 'text-success';

    return (
        <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                <span className="text-[13px] font-bold">Rhine Level</span>
                {data ? (
                    <div className="flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-[10px] font-semibold text-success">
                        <span className="inline-block size-[5px] animate-pulse rounded-full bg-success" />
                        Live
                    </div>
                ) : (
                    <span className="rounded-full bg-secondary px-2 py-0.5 text-[10px] font-semibold text-muted-foreground">
                        Unavailable
                    </span>
                )}
            </div>
            <div className="flex items-center justify-between px-4 py-3.5">
                <div className="flex items-center gap-2.5">
                    <span className="text-xl">🌊</span>
                    <div>
                        <div className="text-[13px] font-semibold">
                            Cologne Gauge
                        </div>
                        <div className="text-[11px] text-muted-foreground">
                            {data
                                ? statusLabel
                                : 'Data temporarily unavailable'}
                        </div>
                    </div>
                </div>
                {meters && (
                    <div className="shrink-0 text-right">
                        <div
                            className={`font-mono text-[22px] leading-none font-medium ${valueColor}`}
                        >
                            {meters}
                        </div>
                        <div className="text-[10px] text-muted-foreground">
                            metres
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

function DisruptionsWidget({
    disruptions,
}: {
    disruptions?: DisruptionItem[];
}) {
    const items = disruptions ?? [];
    const severityEmoji = (s: string) =>
        s === 'critical' ? '🚫' : s === 'major' ? '⚠️' : '🔧';
    const severityColor = (s: string) =>
        s === 'critical'
            ? 'text-danger'
            : s === 'major'
              ? 'text-warn'
              : 'text-muted-foreground';

    return (
        <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                <span className="text-[13px] font-bold">Live Disruptions</span>
                {items.length > 0 ? (
                    <span className="rounded-full bg-warn-soft px-2 py-0.5 text-[10px] font-semibold text-warn">
                        {items.length} active
                    </span>
                ) : (
                    <span className="rounded-full bg-success-soft px-2 py-0.5 text-[10px] font-semibold text-success">
                        All clear
                    </span>
                )}
            </div>
            {items.length === 0 ? (
                <div className="flex items-center gap-2.5 px-4 py-3.5">
                    <span className="text-lg">✅</span>
                    <span className="text-xs text-muted-foreground">
                        No disruptions on KVB network
                    </span>
                </div>
            ) : (
                items.slice(0, 4).map((d, i) => {
                    // Shorten title: remove date prefix like "29.03. - 10.04.2026: "
                    const shortTitle = d.title
                        .replace(
                            /^\d{2}\.\d{2}\.?\s*[-–]\s*\d{2}\.\d{2}\.\d{4}:\s*/i,
                            '',
                        )
                        .replace(/^(Linie|Bus)\s+\d+:\s*/i, '');
                    const lineLabel =
                        d.lines.length > 0 ? d.lines.join(', ') : null;

                    return (
                        <ExpandableRow key={i}>
                            <span className="shrink-0 text-sm">
                                {severityEmoji(d.severity)}
                            </span>
                            <div className="event-text min-w-0 flex-1 text-xs font-medium">
                                {lineLabel && (
                                    <span
                                        className={`font-semibold ${severityColor(d.severity)}`}
                                    >
                                        {lineLabel}{' '}
                                    </span>
                                )}
                                {shortTitle || d.title}
                            </div>
                            <span
                                className={`shrink-0 text-[10px] font-semibold ${severityColor(d.severity)}`}
                            >
                                {d.severity}
                            </span>
                        </ExpandableRow>
                    );
                })
            )}
        </div>
    );
}

/** Row that expands truncated text on click/hover */
function ExpandableRow({ children }: { children: React.ReactNode }) {
    const [expanded, setExpanded] = useState(false);

    return (
        <div
            onClick={() => setExpanded(!expanded)}
            className="flex cursor-pointer items-center gap-2.5 border-b border-border px-4 py-2.5 transition-colors last:border-b-0 hover:bg-secondary/50"
            style={{ overflow: 'hidden' }}
        >
            <style>{`.expandable-row-collapsed .event-text, .expandable-row-collapsed .disrupt-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }`}</style>
            <div
                className={`flex w-full items-center gap-2.5 ${expanded ? '' : 'expandable-row-collapsed'}`}
            >
                {children}
            </div>
        </div>
    );
}

function TodayEventsWidget({ events }: { events?: TodayEvent[] }) {
    const badgeColor = (badge: string) => {
        switch (badge) {
            case 'Open':
                return 'bg-success-soft text-success';
            case 'Paid':
                return 'bg-warn-soft text-warn';
            default:
                return 'bg-success-soft text-success';
        }
    };

    return (
        <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
            <div className="border-b border-border px-4 py-3">
                <span className="text-[13px] font-bold">Today in Cologne</span>
            </div>
            {!events || events.length === 0 ? (
                <div className="px-4 py-4 text-center text-xs text-muted-foreground">
                    No events today
                </div>
            ) : (
                events.map((e, i) => (
                    <ExpandableRow key={i}>
                        <span className="w-10 shrink-0 font-mono text-xs font-semibold text-muted-foreground">
                            {e.time}
                        </span>
                        <span className="event-text min-w-0 flex-1 text-xs font-medium">
                            {e.title}
                        </span>
                        <span
                            className={`shrink-0 rounded-full px-2 py-0.5 text-[9px] font-bold uppercase ${badgeColor(e.badge)}`}
                        >
                            {e.badge}
                        </span>
                    </ExpandableRow>
                ))
            )}
        </div>
    );
}
