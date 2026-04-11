type WeekEvent = {
    id: number;
    title: string;
    emoji: string;
    starts_at: string;
    location_name: string | null;
    is_free: boolean;
};

export function ThisWeekSection({
    events,
    onEventClick,
}: {
    events: WeekEvent[];
    onEventClick?: (ev: WeekEvent) => void;
}) {
    if (events.length === 0) {
        return null;
    }

    return (
        <div className="section-pad border-b border-[#E2DFD6] dark:border-[#3A3930]">
            <div className="mb-3 flex items-baseline justify-between">
                <span style={{ fontSize: 16, fontWeight: 600 }}>This Week</span>
                <a
                    href="/events"
                    className="text-xs font-semibold text-[#1A4CD4]"
                    style={{ textDecoration: 'none' }}
                >
                    See all &rarr;
                </a>
            </div>
            <div className="flex flex-col gap-2">
                {events.map((ev) => (
                    <a
                        key={ev.id}
                        href={`/events/${ev.id}`}
                        onClick={() => onEventClick?.(ev)}
                        className="flex items-center gap-3 rounded-[14px] border border-[#E2DFD6] bg-white p-3 transition-all hover:border-[rgba(26,76,212,.2)] hover:shadow-sm dark:border-[#3A3930] dark:bg-[#1E1D15]"
                        style={{ textDecoration: 'none', color: 'inherit' }}
                    >
                        <span className="text-lg">{ev.emoji || '📅'}</span>
                        <div className="min-w-0 flex-1">
                            <div style={{ fontSize: 13, fontWeight: 600 }}>
                                {ev.title}
                            </div>
                            <div
                                className="text-[#6B6860] dark:text-[#AAA89F]"
                                style={{ fontSize: 12 }}
                            >
                                {new Date(ev.starts_at).toLocaleDateString(
                                    'en-GB',
                                    {
                                        weekday: 'short',
                                        day: 'numeric',
                                        month: 'short',
                                    },
                                )}
                                {ev.location_name && ` · ${ev.location_name}`}
                            </div>
                        </div>
                        {ev.is_free && (
                            <span className="shrink-0 rounded-full bg-[#D4F0E6] px-2 py-0.5 text-[9px] font-bold text-[#0A7C52] uppercase dark:bg-[#0A7C52]/20 dark:text-[#34D399]">
                                Free
                            </span>
                        )}
                    </a>
                ))}
            </div>
        </div>
    );
}
