import { Deferred, Head, usePage } from '@inertiajs/react';
import { CityHelp, CityLinks } from '@/components/today/city-help';
import type { Forecast, Weather } from '@/components/today/city-help';
import { DeadlineStrip, PaperworkPanel } from '@/components/today/paperwork';
import type { TodayPaperwork } from '@/components/today/paperwork';
import { useDayRefresh } from '@/components/today/use-day-refresh';
import { YourDay } from '@/components/today/your-day';
import type { FavouriteStop, Station } from '@/components/travel/nearby/types';
import { LoadingLines, useDelayedLoading } from '@/components/ui/loading';
import AppLayout from '@/layouts/app-layout';
import type { DayProjection } from '@/types/day';
import '../../css/today.css';

/**
 * Today (the mock's today-next/brief/): one canvas, Your day on the left and
 * what's next on the right; phones read it as one column in priority
 * order. No title row: the section menu says where you are.
 */
export default function Today() {
    const {
        auth,
        day,
        weather,
        forecast,
        station,
        stop,
        favouriteStops,
        paperwork,
    } = usePage<{
        day?: DayProjection;
        weather?: Weather;
        forecast?: Forecast;
        station?: Station | null;
        stop: string | null;
        favouriteStops?: FavouriteStop[];
        paperwork?: TodayPaperwork;
    }>().props;
    const planned = !!day?.items.some(
        (item) => !item.completed && item.state !== 'elapsed',
    );
    const firstName = auth.user.name.trim().split(/\s+/)[0];

    useDayRefresh(day);

    return (
        <AppLayout>
            <Head title="Today" />
            <h1 className="sr-only">Today, {firstName}</h1>
            <div className="today-workspace">
                <DeadlineStrip strip={paperwork?.strip ?? null} />
                <div className="daily-canvas">
                    <div className="day-area">
                        <Deferred data="day" fallback={<DayLoading />}>
                            {day ? (
                                <YourDay
                                    day={day}
                                    paperwork={paperwork?.processes}
                                />
                            ) : (
                                <DayLoading />
                            )}
                        </Deferred>
                    </div>
                    <div className="next-area">
                        <div className="practical-surface">
                            {paperwork?.focus && (
                                <div className="practical-personal">
                                    <PaperworkPanel focus={paperwork.focus} />
                                </div>
                            )}
                            <CityHelp
                                weather={weather}
                                forecast={forecast}
                                station={station}
                                stop={stop}
                                favourites={favouriteStops ?? []}
                                planned={planned}
                            />
                        </div>
                        <div className="continue-support">
                            <CityLinks />
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

/** Your day's place while it loads: nothing for 140ms, then quiet lines. */
function DayLoading() {
    const late = useDelayedLoading(true);

    return (
        <section className="day-panel" aria-busy="true">
            <div className="day-heading">
                <h2>Your day</h2>
            </div>
            {late && <LoadingLines lines={3} label="Loading your day" />}
        </section>
    );
}
