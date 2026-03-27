import { usePage } from '@inertiajs/react';

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
    title: string;
    badge: string;
};

export function RightPanel() {
    const { weather, forecast, todayEvents } = usePage<{ weather?: WeatherData; forecast?: ForecastData; todayEvents?: TodayEvent[] }>().props;

    return (
        <aside className="hidden w-[390px] shrink-0 overflow-y-auto p-5 lg:block" style={{ scrollbarWidth: 'none' }}>
            <WeatherWidget weather={weather} forecast={forecast} />
            <RhineWidget />
            <DisruptionsWidget />
            <TodayEventsWidget events={todayEvents} />
            <div className="pt-4 text-center text-[11px] text-muted-foreground">
                Updated <span>just now</span>
            </div>
        </aside>
    );
}

function WeatherWidget({ weather, forecast }: { weather?: WeatherData; forecast?: ForecastData }) {
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
                    DWD
                </div>
            </div>
            <div className="flex items-start justify-between px-4 py-4">
                <div>
                    <div className="font-display text-[44px] font-light leading-none tracking-tight">
                        {temp}<sup className="text-lg align-super">°</sup>
                    </div>
                    <div className="mt-1 text-xs text-muted-foreground">{condition}</div>
                    <div className="text-[11px] text-muted-foreground">
                        {rainStarts ? `Rain from ${rainStarts}` : 'No rain expected'}
                    </div>
                </div>
                <div className="text-[44px] opacity-85">{emoji}</div>
            </div>
            <div className="border-t border-border">
                {feelsLike !== temp && (
                    <WeatherRow emoji="🌡️" label="Feels like" value={`${feelsLike}°`} variant={feelsLike < 0 ? 'caution' : 'good'} />
                )}
                <WeatherRow emoji="🌬️" label="Wind" value={`${wind} km/h${gust && gust > wind ? ` (gusts ${gust})` : ''}`} variant={wind > 25 ? 'caution' : 'good'} />
                <WeatherRow emoji="💧" label="Humidity" value={`${humidity}%`} />
                <WeatherRow emoji="🚲" label="Bike score" value={bikeScore} variant="good" />
                <WeatherRow emoji="🌧️" label="Rain arrives" value={rainLabel} variant={rainVariant} />
            </div>
        </div>
    );
}

function WeatherRow({ emoji, label, value, variant }: { emoji: string; label: string; value: string; variant?: 'good' | 'caution' }) {
    const valueColor = variant === 'good' ? 'text-success' : variant === 'caution' ? 'text-warn' : 'text-foreground';
    return (
        <div className="flex items-center justify-between border-b border-border px-4 py-2.5 text-xs last:border-b-0">
            <span className="flex items-center gap-[7px] text-muted-foreground">
                <span className="text-sm">{emoji}</span>
                {label}
            </span>
            <span className={`font-mono font-medium ${valueColor}`}>{value}</span>
        </div>
    );
}

function RhineWidget() {
    return (
        <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                <span className="text-[13px] font-bold">Rhine Level</span>
                <span className="rounded-full bg-secondary px-2 py-0.5 text-[10px] font-semibold text-muted-foreground">
                    Coming soon
                </span>
            </div>
            <div className="flex items-center justify-between px-4 py-3.5">
                <div className="flex items-center gap-2.5">
                    <span className="text-xl">🌊</span>
                    <div>
                        <div className="text-[13px] font-semibold text-muted-foreground">Cologne Gauge</div>
                        <div className="text-[11px] text-muted-foreground">Real-time data with VRS API</div>
                    </div>
                </div>
            </div>
        </div>
    );
}

function DisruptionsWidget() {
    return (
        <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                <span className="text-[13px] font-bold">Live Disruptions</span>
                <span className="rounded-full bg-secondary px-2 py-0.5 text-[10px] font-semibold text-muted-foreground">
                    Awaiting VRS API
                </span>
            </div>
            <div className="px-4 py-4 text-center text-xs text-muted-foreground">
                Real-time disruption alerts will appear here once VRS API access is approved.
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
                <div className="px-4 py-4 text-center text-xs text-muted-foreground">No events today</div>
            ) : (
                events.map((e, i) => (
                    <div key={i} className="flex items-center gap-2.5 border-b border-border px-4 py-3 last:border-b-0">
                        <span className="w-10 shrink-0 font-mono text-xs font-semibold text-muted-foreground">{e.time}</span>
                        <span className="min-w-0 flex-1 truncate text-xs font-medium">{e.title}</span>
                        <span className={`shrink-0 rounded-full px-2 py-0.5 text-[9px] font-bold uppercase ${badgeColor(e.badge)}`}>
                            {e.badge}
                        </span>
                    </div>
                ))
            )}
        </div>
    );
}
