import { Link } from '@inertiajs/react';

type SpotData = {
    id: number;
    name: string;
    category: string;
    address: string | null;
    wifi_speed: string | null;
    noise_level: string | null;
    time_limit_mins: number | null;
    rating: number | null;
    active_checkins_count: number;
};

const categoryEmoji: Record<string, string> = { cafe: '☕', coworking: '🏢', library: '📚', park: '🌳' };
const noiseLabel: Record<string, string> = { quiet: 'Quiet', moderate: 'Moderate', loud: 'Loud' };

export function SpotCard({ spot }: { spot: SpotData }) {
    const crowdPercent = Math.min(spot.active_checkins_count * 15, 100);
    return (
        <Link href={`/explore/${spot.id}`} prefetch className="flex items-center gap-3 rounded-xl border border-border bg-card p-3.5 transition-all hover:translate-x-0.5 hover:border-primary/25">
            <span className="text-2xl">{categoryEmoji[spot.category] || '📍'}</span>
            <div className="min-w-0 flex-1">
                <div className="text-sm font-semibold">{spot.name}</div>
                <div className="text-xs text-muted-foreground">{spot.address}</div>
                <div className="mt-1 flex flex-wrap gap-1">
                    {spot.wifi_speed && <span className="rounded-full bg-accent-soft px-1.5 py-0.5 text-[10px] font-medium text-primary">WiFi {spot.wifi_speed}</span>}
                    {spot.noise_level && <span className="rounded-full bg-secondary px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">{noiseLabel[spot.noise_level] || spot.noise_level}</span>}
                    {spot.time_limit_mins && <span className="rounded-full bg-secondary px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">{spot.time_limit_mins} min</span>}
                </div>
            </div>
            <div className="shrink-0 text-right">
                {spot.rating && <div className="text-xs font-medium">⭐ {spot.rating.toFixed(1)}</div>}
                <div className="mt-1 h-1.5 w-10 overflow-hidden rounded-full bg-secondary">
                    <div className={`h-full rounded-full ${crowdPercent > 70 ? 'bg-warn' : crowdPercent > 40 ? 'bg-yellow-400' : 'bg-success'}`} style={{ width: `${crowdPercent}%` }} />
                </div>
                <div className="mt-0.5 text-[9px] text-muted-foreground">{crowdPercent}% full</div>
            </div>
        </Link>
    );
}
