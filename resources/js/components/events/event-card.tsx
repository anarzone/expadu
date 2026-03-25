import { Link } from '@inertiajs/react';

type EventData = {
    id: number; title: string; emoji: string | null; category: string | null;
    starts_at: string; location_name: string | null; is_free: boolean;
    price: string | null; attendees_count: number; max_attendees: number | null;
};

const categoryColors: Record<string, string> = {
    language: 'bg-accent-soft text-primary',
    social: 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300',
    culture: 'bg-warn-soft text-warn',
    sports: 'bg-success-soft text-success',
    food: 'bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300',
};

export function EventCard({ event }: { event: EventData }) {
    const date = new Date(event.starts_at);
    const day = date.getDate();
    const month = date.toLocaleString('en', { month: 'short' }).toUpperCase();
    const time = date.toLocaleTimeString('en', { hour: '2-digit', minute: '2-digit', hour12: false });

    return (
        <Link href={`/events/${event.id}`} prefetch className="flex items-center gap-3 rounded-xl border border-border bg-card p-3.5 transition-all hover:translate-x-0.5 hover:border-primary/20">
            <div className="flex min-w-[42px] flex-col items-center rounded-lg bg-secondary px-2 py-1.5 text-center">
                <span className="font-mono text-lg font-medium leading-none">{day}</span>
                <span className="text-[9px] font-semibold uppercase tracking-wider text-muted-foreground">{month}</span>
            </div>
            <div className="min-w-0 flex-1">
                <div className="text-sm font-semibold">{event.emoji} {event.title}</div>
                <div className="flex items-center gap-1 text-xs text-muted-foreground">
                    {event.location_name}
                    <span className="text-muted-foreground/40">·</span>
                    {time}
                </div>
            </div>
            <div className="flex shrink-0 flex-col items-end gap-1">
                {event.category && (
                    <span className={`rounded-full px-2 py-0.5 text-[9px] font-bold uppercase ${categoryColors[event.category] || 'bg-secondary text-muted-foreground'}`}>
                        {event.category}
                    </span>
                )}
                <span className="text-[10px] text-muted-foreground">
                    {event.is_free ? 'Free' : `€${event.price}`}
                    {event.max_attendees && ` · ${event.attendees_count}/${event.max_attendees}`}
                </span>
            </div>
        </Link>
    );
}
