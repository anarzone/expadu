import { router, usePage } from '@inertiajs/react';
import {
    IconArrowRight,
    IconBulb,
    IconClock,
    IconConfetti,
    IconRepeat,
} from '@tabler/icons-react';
import { useState } from 'react';
import { RemindMeButton } from '@/components/events/remind-me-button';
import { eventIllustrationKey } from '@/components/events/types';
import type { EventOccurrence } from '@/components/events/types';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { CategoryIllustration } from '@/components/places/category-illustration';
import { ICON_STROKE } from '@/constants/icons';

type WeatherData = {
    available?: boolean;
    temperature: number;
    feels_like?: number;
    emoji: string;
    condition: string;
    wind_speed: number;
    wind_gust?: number;
    humidity: number;
    precipitation: number;
};

type HourlyEntry = {
    hour: string;
    precip: number;
};

type ForecastData = {
    rain_starts: string | null;
    bike_score: string;
    rain_summary: string;
    hourly: HourlyEntry[];
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
    const { weather, forecast, featuredEvent, rhineLevel, activeDisruptions } =
        usePage<{
            weather?: WeatherData;
            forecast?: ForecastData;
            featuredEvent?: EventOccurrence | null;
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
            <FeaturedEventWidget event={featuredEvent} />
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
    // Weather loads async and can be unavailable — never show a misleading 0°.
    if (!weather || weather.available === false) {
        return (
            <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
                <div className="flex items-center justify-between border-b border-border px-4 py-3">
                    <span className="text-[13px] font-bold">
                        Weather · Cologne
                    </span>
                </div>
                <div className="px-4 py-6 text-center text-[13px] text-muted-foreground">
                    🌥️ Weather unavailable right now — check back shortly.
                </div>
            </div>
        );
    }

    const temp = weather?.temperature ?? 0;
    const feelsLike = weather?.feels_like ?? temp;
    const emoji = weather?.emoji ?? '⛅';
    const condition = weather?.condition ?? 'Partly cloudy';
    const wind = weather?.wind_speed ?? 0;
    const gust = weather?.wind_gust ?? 0;
    const humidity = weather?.humidity ?? 0;
    const rainStarts = forecast?.rain_starts;
    const bikeScore = forecast?.bike_score ?? 'Good';

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
                        {forecast?.rain_summary ??
                            (rainStarts
                                ? `Rain from ${rainStarts}`
                                : 'No rain expected')}
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
                <RainTimeline
                    hourly={forecast?.hourly ?? []}
                    summary={
                        forecast?.rain_summary ??
                        (rainStarts
                            ? `Rain from ${rainStarts}`
                            : 'No rain expected')
                    }
                    isRaining={!!rainStarts}
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

function RainTimeline({
    hourly,
    summary,
    isRaining,
}: {
    hourly: HourlyEntry[];
    summary: string;
    isRaining: boolean;
}) {
    const maxPrecip = Math.max(0.5, ...hourly.map((h) => h.precip));
    const hasRain = hourly.some((h) => h.precip > 0.1);

    return (
        <div className="border-b border-border px-4 py-2.5 last:border-b-0">
            <div className="mb-2 flex items-center justify-between text-xs">
                <span className="flex items-center gap-[7px] text-muted-foreground">
                    <span className="text-sm">🌧️</span>
                    Rain
                </span>
                <span
                    className={`font-mono font-medium ${isRaining ? 'text-warn' : 'text-success'}`}
                >
                    {summary}
                </span>
            </div>
            {hourly.length > 0 && (
                <div className="flex items-end gap-[3px]">
                    {hourly.map((h, i) => {
                        const height = hasRain
                            ? Math.max(
                                  2,
                                  Math.round((h.precip / maxPrecip) * 24),
                              )
                            : 2;
                        const isWet = h.precip > 0.1;

                        return (
                            <div
                                key={i}
                                className="flex flex-1 flex-col items-center gap-1"
                            >
                                <div
                                    className={`w-full rounded-sm transition-all ${
                                        isWet ? 'bg-primary' : 'bg-border'
                                    }`}
                                    style={{ height }}
                                />
                                <span className="text-[8px] text-muted-foreground">
                                    {h.hour.slice(0, 2)}
                                </span>
                            </div>
                        );
                    })}
                </div>
            )}
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

/** Shared card shell — the header is identical across all three states. */
function FeaturedEventShell({ children }: { children: React.ReactNode }) {
    return (
        <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
            <div className="border-b border-border px-4 py-3">
                <span className="text-[13px] font-bold">Today's pick</span>
            </div>
            {children}
        </div>
    );
}

/**
 * The single "don't miss" event for today, as a rich product card. Draws
 * from the same curated source as the Events page (see FeaturedEvent.php),
 * so the right panel and the feed never disagree. `undefined` = the
 * deferred prop is still loading; `null` = nothing curated today.
 */
function FeaturedEventWidget({ event }: { event?: EventOccurrence | null }) {
    const [reminded, setReminded] = useState(false);
    const [destination, setDestination] = useState<Destination | null>(null);

    if (event === undefined) {
        return (
            <FeaturedEventShell>
                <div className="animate-pulse">
                    <div className="h-[150px] bg-secondary" />
                    <div className="space-y-2.5 px-4 py-4">
                        <div className="h-4 w-3/4 rounded bg-secondary" />
                        <div className="h-3 w-1/2 rounded bg-secondary" />
                        <div className="h-3 w-full rounded bg-secondary" />
                    </div>
                </div>
            </FeaturedEventShell>
        );
    }

    if (event === null) {
        return (
            <FeaturedEventShell>
                <div className="flex flex-col items-center gap-2 px-5 py-8 text-center">
                    <IconConfetti
                        size={28}
                        stroke={ICON_STROKE}
                        className="text-muted-foreground/60"
                    />
                    <span className="text-[13px] font-semibold">
                        Quiet day in Cologne
                    </span>
                    <button
                        onClick={() => router.visit('/events')}
                        className="mt-1 flex cursor-pointer items-center gap-1 text-[12px] font-semibold text-primary hover:opacity-75"
                    >
                        Browse all events <IconArrowRight size={14} />
                    </button>
                </div>
            </FeaturedEventShell>
        );
    }

    const start = new Date(event.occurrence_start);
    const weekday = start
        .toLocaleDateString('en-US', { weekday: 'short' })
        .toUpperCase();
    const dayNum = start.getDate();
    const isFree =
        !event.price_text || event.price_text.toLowerCase() === 'free';
    const hasCoords = event.venue.lat != null && event.venue.lng != null;
    const chips = (event.chips ?? []).filter((c) => c.toLowerCase() !== 'free');

    function takeMeThere() {
        if (event.venue.lat == null || event.venue.lng == null) {
            return;
        }

        setDestination({
            name: event.venue.name ?? event.title,
            emoji: event.emoji,
            lat: event.venue.lat,
            lng: event.venue.lng,
            arriveBy: event.occurrence_start,
        });
    }

    return (
        <FeaturedEventShell>
            <div
                role="button"
                tabIndex={0}
                onClick={() => router.visit('/events')}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        router.visit('/events');
                    }
                }}
                className="block w-full cursor-pointer text-left"
            >
                <div className="relative h-[150px] w-full overflow-hidden">
                    {event.photo_url ? (
                        <>
                            <img
                                src={event.photo_url}
                                alt=""
                                className="h-full w-full object-cover"
                            />
                            <div className="absolute inset-0 bg-gradient-to-t from-black/45 to-transparent" />
                            {event.photo_attribution && (
                                <span className="absolute right-2 bottom-1.5 text-[10px] text-white/80">
                                    ©{' '}
                                    {event.photo_attribution
                                        .split('·')[0]
                                        .trim()}
                                </span>
                            )}
                        </>
                    ) : (
                        <CategoryIllustration
                            coarse={eventIllustrationKey(event.category)}
                            iconSize={46}
                            className="h-full w-full"
                        />
                    )}
                    <div className="absolute top-3 left-3 rounded-[10px] bg-card px-2 py-1 text-center leading-none shadow-sm">
                        <div className="text-[10px] font-semibold tracking-wide text-danger">
                            {weekday}
                        </div>
                        <div className="text-[17px] font-semibold text-foreground">
                            {dayNum}
                        </div>
                    </div>
                    <span
                        className={`absolute top-3 right-3 rounded-full px-2.5 py-1 text-[11px] font-semibold shadow-sm ${
                            isFree
                                ? 'bg-success-soft text-success'
                                : 'bg-card text-foreground'
                        }`}
                    >
                        {isFree ? 'Free' : event.price_text}
                    </span>
                </div>

                <div className="px-4 pt-3.5 pb-1">
                    <div className="font-display text-[16px] leading-tight font-semibold text-foreground">
                        {event.title}
                    </div>
                    <div className="mt-1.5 flex items-center gap-1.5 text-[12px] text-muted-foreground">
                        <IconClock size={14} stroke={ICON_STROKE} />
                        <span className="min-w-0 truncate">{event.meta}</span>
                    </div>
                    {event.summary && (
                        <p className="mt-2 line-clamp-2 text-[12px] leading-snug text-muted-foreground">
                            {event.summary}
                        </p>
                    )}
                    {event.tip && (
                        <div className="mt-2.5 flex items-start gap-1.5 rounded-[10px] bg-success-soft px-2.5 py-2">
                            <IconBulb
                                size={15}
                                stroke={ICON_STROKE}
                                className="mt-0.5 shrink-0 text-success"
                            />
                            <span className="text-[11px] leading-snug text-success">
                                {event.tip}
                            </span>
                        </div>
                    )}
                    {(chips.length > 0 || event.recurrence_text) && (
                        <div className="mt-2.5 flex flex-wrap gap-1.5">
                            {event.recurrence_text && (
                                <span className="flex items-center gap-1 rounded-full bg-secondary px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                                    <IconRepeat size={11} />{' '}
                                    {event.recurrence_text}
                                </span>
                            )}
                            {chips.slice(0, 2).map((c) => (
                                <span
                                    key={c}
                                    className="rounded-full bg-secondary px-2 py-0.5 text-[10px] font-medium text-muted-foreground"
                                >
                                    {c}
                                </span>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <div className="mt-3 flex items-center justify-between gap-2 border-t border-border px-3 py-2.5">
                <RemindMeButton
                    eventId={event.id}
                    occurrenceStart={event.occurrence_start}
                    reminded={reminded}
                    onChange={setReminded}
                />
                <button
                    onClick={takeMeThere}
                    disabled={!hasCoords}
                    className="flex min-h-9 cursor-pointer items-center gap-1 rounded-[9px] bg-primary px-3 py-1.5 text-[13px] font-semibold text-white transition-opacity hover:opacity-90 disabled:cursor-default disabled:opacity-40"
                >
                    Take me there{' '}
                    <IconArrowRight size={15} stroke={ICON_STROKE} />
                </button>
            </div>

            {destination && (
                <TakeMeThereSheet
                    destination={destination}
                    onClose={() => setDestination(null)}
                />
            )}
        </FeaturedEventShell>
    );
}
