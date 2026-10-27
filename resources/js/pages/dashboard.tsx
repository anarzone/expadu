import { Head, usePage, usePoll } from '@inertiajs/react';
import { Fragment, useEffect } from 'react';
import type { ReactNode } from 'react';
import { CityHelp, CityLinks } from '@/components/today/city-help';
import type { Forecast, Weather } from '@/components/today/city-help';
import { ComingUp } from '@/components/today/coming-up';
import type { ComingUpData } from '@/components/today/coming-up';
import { AroundPlan, Discovery } from '@/components/today/discovery';
import type { TodayDiscovery } from '@/components/today/discovery';
import { NeedsStrip } from '@/components/today/needs';
import type { TodayNeeds } from '@/components/today/needs';
import { PaperworkPanel, SettlingIn } from '@/components/today/paperwork';
import type { TodayPaperwork } from '@/components/today/paperwork';
import { PickUp } from '@/components/today/pick-up';
import type { TodayContinuationData } from '@/components/today/pick-up';
import { PHONE_ORDER, useSteadyLead } from '@/components/today/priority';
import type { Block } from '@/components/today/priority';
import { SavedForLater } from '@/components/today/saved';
import type { TodaySavedData } from '@/components/today/saved';
import { useDayLoad } from '@/components/today/use-day-load';
import { useDayRefresh } from '@/components/today/use-day-refresh';
import { TodayWelcome } from '@/components/today/welcome';
import { YourDay } from '@/components/today/your-day';
import type { FavouriteStop, Station } from '@/components/travel/nearby/types';
import { LoadingLines, useDelayedLoading } from '@/components/ui/loading';
import { usePhone } from '@/hooks/use-phone';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
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
        learnedStops,
        paperwork,
        discovery,
        comingUp,
        saved,
        continuation,
        welcome,
        needsYou,
    } = usePage<{
        day?: DayProjection;
        weather?: Weather;
        forecast?: Forecast;
        station?: Station | null;
        stop: string | null;
        favouriteStops?: FavouriteStop[];
        learnedStops?: string[];
        paperwork?: TodayPaperwork;
        discovery?: TodayDiscovery;
        comingUp?: ComingUpData;
        saved?: TodaySavedData;
        continuation?: TodayContinuationData;
        welcome?: boolean;
        /** The engine's tier-1 and tier-2 items for today (Needs you). */
        needsYou?: TodayNeeds;
    }>().props;
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
    // Check departures' times move: re-read the station every 30 s, as
    // Travel does (the server's cache).
    // None to read when there's nothing to anchor one (the server sends null).
    const { start: startPoll, stop: stopPoll } = usePoll(
        30_000,
        { only: ['station'], async: true },
        { autoStart: false },
    );
    const noStation = station === null;

    useEffect(() => {
        if (noStation) {
            return;
        }

        startPoll();

        return stopPoll;
    }, [noStation, startPoll, stopPoll]);
    const dayLoad = useDayLoad(day !== undefined);

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
        // Straight from the prop, not <Deferred>: that drops back to its
        // fallback whenever `day` is re-read, and stays there if the re-read
        // fails. A day on screen stays while it refreshes.
        agenda: day ? (
            <YourDay
                day={day}
                paperwork={paperwork?.processes}
                offer={!welcome}
            />
        ) : (
            <DayLoading failed={dayLoad.failed} onRetry={dayLoad.retry} />
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
                    learned={learnedStops ?? []}
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
            <NeedsStrip
                needs={needsYou}
                paperwork={
                    paperwork === undefined
                        ? undefined
                        : (paperwork?.strip ?? null)
                }
            />
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

/**
 * Your day's place while it loads: nothing for 140ms, then quiet lines. If
 * it can't load, it says so and offers to try again.
 */
function DayLoading({
    failed,
    onRetry,
}: {
    failed: boolean;
    onRetry: () => void;
}) {
    const late = useDelayedLoading(!failed);

    return (
        <section
            className={cn('day-panel', failed && 'has-no-plans')}
            aria-busy={!failed || undefined}
        >
            <div className="day-heading">
                <h2>Your day</h2>
            </div>
            {failed ? (
                <>
                    <div className="day-empty" role="status">
                        <strong>Your day didn’t load.</strong>
                    </div>
                    <div className="day-actions">
                        <button
                            type="button"
                            className="text-action"
                            onClick={onRetry}
                        >
                            Try again
                        </button>
                    </div>
                </>
            ) : (
                late && <LoadingLines lines={3} label="Loading your day" />
            )}
        </section>
    );
}
