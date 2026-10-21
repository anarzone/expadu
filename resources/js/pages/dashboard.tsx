import { Deferred, Head, usePage } from '@inertiajs/react';
import { CityHelp, CityLinks } from '@/components/today/city-help';
import type { Forecast, Weather } from '@/components/today/city-help';
import { ComingUp } from '@/components/today/coming-up';
import type { ComingUpData } from '@/components/today/coming-up';
import { AroundPlan, Discovery } from '@/components/today/discovery';
import type { TodayDiscovery } from '@/components/today/discovery';
import {
    DeadlineStrip,
    PaperworkPanel,
    SettlingIn,
} from '@/components/today/paperwork';
import type { TodayPaperwork } from '@/components/today/paperwork';
import { PickUp } from '@/components/today/pick-up';
import type { TodayContinuationData } from '@/components/today/pick-up';
import { SavedForLater } from '@/components/today/saved';
import type { TodaySavedData } from '@/components/today/saved';
import { useDayRefresh } from '@/components/today/use-day-refresh';
import { TodayWelcome } from '@/components/today/welcome';
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
        discovery,
        comingUp,
        saved,
        continuation,
        welcome,
    } = usePage<{
        day?: DayProjection;
        weather?: Weather;
        forecast?: Forecast;
        station?: Station | null;
        stop: string | null;
        favouriteStops?: FavouriteStop[];
        paperwork?: TodayPaperwork;
        discovery?: TodayDiscovery;
        comingUp?: ComingUpData;
        saved?: TodaySavedData;
        continuation?: TodayContinuationData;
        welcome?: boolean;
    }>().props;
    const planned = !!day?.items.some(
        (item) => !item.completed && item.state !== 'elapsed',
    );
    const firstName = auth.user.name.trim().split(/\s+/)[0];

    useDayRefresh(day);

    // Paperwork's next step (or, with no record yet, the way in) and City help.
    const practical = (
        <div className="practical-surface">
            {welcome ? null : paperwork?.focus ? (
                <div className="practical-personal">
                    <PaperworkPanel focus={paperwork.focus} />
                </div>
            ) : (
                paperwork !== undefined && <SettlingIn />
            )}
            <CityHelp
                weather={weather}
                forecast={forecast}
                station={station}
                stop={stop}
                favourites={favouriteStops ?? []}
                planned={planned}
            />
            {welcome && <SettlingIn />}
        </div>
    );

    return (
        <AppLayout>
            <Head title="Today" />
            <h1 className="sr-only">Today, {firstName}</h1>
            <div className="today-workspace">
                {welcome && <TodayWelcome />}
                <DeadlineStrip strip={paperwork?.strip ?? null} />
                <div className="daily-canvas">
                    <div className="day-area">
                        {/* A first visit leads with the city's help (the mock's arrange()). */}
                        {welcome && practical}
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
                        {discovery?.around && (
                            <div id="around-plan-slot">
                                <AroundPlan around={discovery.around} />
                            </div>
                        )}
                        {saved && <SavedForLater saved={saved} />}
                        {comingUp && <ComingUp comingUp={comingUp} />}
                        {continuation && (
                            <div id="personal-continuity-slot">
                                <PickUp continuation={continuation} />
                            </div>
                        )}
                    </div>
                    <div className="next-area">
                        {/* A free day leads with ideas (the mock's arrange()). */}
                        {discovery?.free && (
                            <Discovery
                                discovery={discovery}
                                weather={weather}
                            />
                        )}
                        {!welcome && practical}
                        {discovery && !discovery.free && (
                            <Discovery
                                discovery={discovery}
                                weather={weather}
                            />
                        )}
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
