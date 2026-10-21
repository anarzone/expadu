import { Deferred, Head, usePage } from '@inertiajs/react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
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
import { PHONE_ORDER, useSteadyLead } from '@/components/today/priority';
import type { Block } from '@/components/today/priority';
import { SavedForLater } from '@/components/today/saved';
import type { TodaySavedData } from '@/components/today/saved';
import { useDayRefresh } from '@/components/today/use-day-refresh';
import { TodayWelcome } from '@/components/today/welcome';
import { YourDay } from '@/components/today/your-day';
import type { FavouriteStop, Station } from '@/components/travel/nearby/types';
import { LoadingLines, useDelayedLoading } from '@/components/ui/loading';
import { usePhone } from '@/hooks/use-phone';
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
    const phone = usePhone();
    const lead = useSteadyLead(
        day,
        !!welcome,
        // Whether there are ideas left; unknown until discovery loads.
        discovery === undefined
            ? undefined
            : !!discovery &&
                  (discovery.events.length > 0 || !!discovery.around),
    );

    useDayRefresh(day);

    // Paperwork's next step, or with no record yet its way in ("Settling
    // in?"). City help comes first where the essentials lead and on phones
    // (the mock's arrange()).
    const cityFirst = phone || lead === 'essentials';
    const personal = paperwork?.focus ? (
        <div className="practical-personal">
            <PaperworkPanel focus={paperwork.focus} />
        </div>
    ) : (
        (welcome || paperwork !== undefined) && <SettlingIn />
    );
    const blocks: Record<Block, ReactNode> = {
        agenda: (
            <Deferred data="day" fallback={<DayLoading />}>
                {day ? (
                    <YourDay day={day} paperwork={paperwork?.processes} />
                ) : (
                    <DayLoading />
                )}
            </Deferred>
        ),
        essentials: (
            <div className="practical-surface">
                {!cityFirst && personal}
                <CityHelp
                    weather={weather}
                    forecast={forecast}
                    station={station}
                    stop={stop}
                    favourites={favouriteStops ?? []}
                    planned={planned}
                />
                {cityFirst && personal}
            </div>
        ),
        around: discovery?.around && (
            <div id="around-plan-slot">
                <AroundPlan around={discovery.around} />
            </div>
        ),
        // A first visit isn't a free day (the mock's freeDay), so it keeps
        // "A little Cologne".
        discovery: discovery && (
            <Discovery
                discovery={discovery}
                free={lead === 'discovery'}
                weather={weather}
            />
        ),
        composer: continuation && (
            <div id="personal-continuity-slot">
                <PickUp continuation={continuation} />
            </div>
        ),
        saved: saved && <SavedForLater saved={saved} />,
        upcoming: comingUp && <ComingUp comingUp={comingUp} />,
        support: (
            <div className="continue-support">
                <CityLinks />
            </div>
        ),
    };
    const place = (keys: Block[]) =>
        keys.map((key) => <Fragment key={key}>{blocks[key]}</Fragment>);

    return (
        <AppLayout>
            <Head title="Today" />
            <h1 className="sr-only">Today, {firstName}</h1>
            {/* The welcome and a deadline sit on the page, above the day's
                surface (the mock's main). */}
            {welcome && <TodayWelcome />}
            <DeadlineStrip strip={paperwork?.strip ?? null} />
            <div className="today-workspace">
                {/* Phones read one column in priority order; wider screens
                    keep the day on the left and what's next on the right.
                    The elements themselves move, so reading, keyboard and
                    visual order agree. */}
                <div className="daily-canvas">
                    {phone ? (
                        place(PHONE_ORDER[lead])
                    ) : (
                        <>
                            <div className="day-area">
                                {place([
                                    ...(lead === 'essentials'
                                        ? (['essentials'] as const)
                                        : []),
                                    'agenda',
                                    'around',
                                    'saved',
                                    'upcoming',
                                    'composer',
                                ])}
                            </div>
                            <div className="next-area">
                                {place(
                                    lead === 'discovery'
                                        ? ['discovery', 'essentials', 'support']
                                        : lead === 'essentials'
                                          ? ['discovery', 'support']
                                          : [
                                                'essentials',
                                                'discovery',
                                                'support',
                                            ],
                                )}
                            </div>
                        </>
                    )}
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
