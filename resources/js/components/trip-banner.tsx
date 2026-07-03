import { router } from '@inertiajs/react';
import { IconChevronRight } from '@tabler/icons-react';
import { ICON_STROKE } from '@/constants/icons';
import { useActiveTrip } from '@/hooks/use-active-trip';

/**
 * The app-wide "trip in progress" bar. Mounted in the layout so a live trip
 * stays visible on every screen until it's ended; tapping it returns to
 * Departures, which reopens the journey's live timeline.
 */
export function TripBanner() {
    const { activeTrip } = useActiveTrip();

    if (!activeTrip) {
        return null;
    }

    const dest = activeTrip.destination.name;
    const arrive = activeTrip.journey.arrive_time;

    return (
        <button
            type="button"
            onClick={() => router.visit('/timetable')}
            className="group flex w-full items-center gap-3 border-b border-black/10 bg-foreground px-4 py-2.5 text-left text-background md:px-6 dark:border-border dark:bg-secondary dark:text-foreground"
        >
            <span className="relative flex size-2.5 shrink-0">
                <span className="absolute inline-flex size-full animate-ping rounded-full bg-cyan opacity-60" />
                <span className="relative inline-flex size-2.5 rounded-full bg-cyan" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="font-mono text-[9.5px] tracking-[0.14em] uppercase opacity-60">
                    Trip in progress
                </div>
                <div className="truncate text-[13.5px] font-semibold">
                    On your way to {dest}
                </div>
            </div>
            <span className="shrink-0 text-right">
                <div className="font-mono text-[9.5px] tracking-[0.08em] uppercase opacity-60">
                    Arrive
                </div>
                <div className="font-display text-sm font-semibold">
                    {arrive}
                </div>
            </span>
            <IconChevronRight
                size={18}
                stroke={ICON_STROKE}
                className="shrink-0 opacity-70 transition-transform group-hover:translate-x-0.5"
            />
        </button>
    );
}
