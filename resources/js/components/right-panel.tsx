export function RightPanel() {
    return (
        <aside className="hidden w-[300px] shrink-0 overflow-y-auto p-5 lg:block" style={{ scrollbarWidth: 'none' }}>
            <WeatherWidget />
            <RhineWidget />
            <DisruptionsWidget />
            <TodayEventsWidget />
            <div className="pt-4 text-center text-[11px] text-muted-foreground">
                Updated <span>just now</span>
            </div>
        </aside>
    );
}

function WeatherWidget() {
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
                        11<sup className="text-lg align-super">°</sup>
                    </div>
                    <div className="mt-1 text-xs text-muted-foreground">Partly cloudy</div>
                    <div className="text-[11px] text-muted-foreground">Rain from 18:00</div>
                </div>
                <div className="text-[44px] opacity-85">⛅</div>
            </div>
            <div className="border-t border-border">
                <WeatherRow emoji="🌬️" label="Wind" value="14 km/h" variant="good" />
                <WeatherRow emoji="💧" label="Humidity" value="68%" />
                <WeatherRow emoji="🚲" label="Bike score" value="Good until 17:45" variant="good" />
                <WeatherRow emoji="🌧️" label="Rain arrives" value="18:00" variant="caution" />
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
                <div className="flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-[10px] font-semibold text-success">
                    <span className="inline-block size-[5px] animate-pulse rounded-full bg-success" />
                    Live
                </div>
            </div>
            <div className="flex items-center justify-between px-4 py-3.5">
                <div className="flex items-center gap-2.5">
                    <span className="text-xl">🌊</span>
                    <div>
                        <div className="text-[13px] font-semibold">Cologne Gauge</div>
                        <div className="text-[11px] text-muted-foreground">Rheinufer paths open</div>
                    </div>
                </div>
                <div className="text-right">
                    <div className="font-mono text-lg font-medium">3.42</div>
                    <div className="text-[10px] text-muted-foreground">metres</div>
                </div>
            </div>
        </div>
    );
}

function DisruptionsWidget() {
    const disruptions = [
        { emoji: '🚋', title: 'Line 1 — +8 min delay', subtitle: 'Match crowd near Müngersdorf', severity: 'Delay', color: 'bg-warn-soft text-warn' },
        { emoji: '🚧', title: 'Venloer Str. partial closure', subtitle: 'Market stalls · Until 20:00', severity: 'Minor', color: 'bg-success-soft text-success' },
        { emoji: '✅', title: 'All other lines on time', subtitle: 'Lines 3, 4, 7, 9, 12, 16, 18', severity: 'Clear', color: 'bg-success-soft text-success' },
    ];

    return (
        <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                <span className="text-[13px] font-bold">Live Disruptions</span>
                <div className="flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-[10px] font-semibold text-success">
                    <span className="inline-block size-[5px] animate-pulse rounded-full bg-success" />
                    KVB
                </div>
            </div>
            {disruptions.map((d, i) => (
                <div key={i} className="flex items-center gap-2.5 border-b border-border px-4 py-3 last:border-b-0">
                    <span className="shrink-0 text-base">{d.emoji}</span>
                    <div className="min-w-0 flex-1">
                        <div className="text-xs font-semibold">{d.title}</div>
                        <div className="text-[11px] text-muted-foreground">{d.subtitle}</div>
                    </div>
                    <span className={`shrink-0 rounded-full px-2 py-0.5 text-[9px] font-bold uppercase ${d.color}`}>
                        {d.severity}
                    </span>
                </div>
            ))}
        </div>
    );
}

function TodayEventsWidget() {
    const events = [
        { time: '19:00', title: 'Language Exchange · Café Schmitz', badge: 'Open', color: 'bg-success-soft text-success' },
        { time: '20:00', title: 'FC Köln vs. Dortmund', badge: 'Busy', color: 'bg-danger-soft text-danger' },
        { time: 'All day', title: 'Frühlingsmarkt · Neumarkt', badge: 'Busy', color: 'bg-warn-soft text-warn' },
    ];

    return (
        <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
            <div className="border-b border-border px-4 py-3">
                <span className="text-[13px] font-bold">Today in Cologne</span>
            </div>
            {events.map((e, i) => (
                <div key={i} className="flex items-center gap-2.5 border-b border-border px-4 py-3 last:border-b-0">
                    <span className="w-10 shrink-0 font-mono text-xs font-semibold text-muted-foreground">{e.time}</span>
                    <span className="min-w-0 flex-1 truncate text-xs font-medium">{e.title}</span>
                    <span className={`shrink-0 rounded-full px-2 py-0.5 text-[9px] font-bold uppercase ${e.color}`}>
                        {e.badge}
                    </span>
                </div>
            ))}
        </div>
    );
}
