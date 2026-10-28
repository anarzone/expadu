import { Head, router, usePage, usePoll } from '@inertiajs/react';
import { Fragment, useEffect, useMemo, useRef } from 'react';
import type { ReactNode } from 'react';
import { CityHelp, CityLinks } from '@/components/today/city-help';
import type { Forecast, Weather } from '@/components/today/city-help';
import { ComingUp } from '@/components/today/coming-up';
import type { ComingUpData } from '@/components/today/coming-up';
import {
    AroundPlan,
    Discovery,
    MoreEvents,
} from '@/components/today/discovery';
import type { TodayDiscovery } from '@/components/today/discovery';
import { NeedsStrip } from '@/components/today/needs';
import type { TodayNeeds } from '@/components/today/needs';
import { PaperworkPanel, SettlingIn } from '@/components/today/paperwork';
import type { TodayPaperwork } from '@/components/today/paperwork';
import { PickUp } from '@/components/today/pick-up';
import type { TodayContinuationData } from '@/components/today/pick-up';
import {
    COLUMNS,
    PHONE_ORDER,
    useSteadyLead,
} from '@/components/today/priority';
import type { Block } from '@/components/today/priority';
import { SavedForLater } from '@/components/today/saved';
import type { TodaySavedData } from '@/components/today/saved';
import { useDayRefresh } from '@/components/today/use-day-refresh';
import { TodayWelcome } from '@/components/today/welcome';
import { YourDay } from '@/components/today/your-day';
import type { FavouriteStop, Station } from '@/components/travel/nearby/types';
import { WhatsOn } from '@/components/whats-on/whats-on';
import type { WhatsOnData } from '@/components/whats-on/whats-on';
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
        stationWarming,
        stationReason,
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
        whatsOn,
    } = usePage<{
        // All in the first response (HomeFeedController: pages load whole).
        day: DayProjection;
        weather: Weather;
        forecast: Forecast;
        station: Station | null;
        /** The stop whose board is read after this response (no station yet). */
        stationWarming: string | null;
        /** Why the board shows this stop ("For Tandem Language Café", "Near Home"); null when picked. */
        stationReason: string | null;
        stop: string | null;
        favouriteStops: FavouriteStop[];
        learnedStops: string[];
        paperwork: TodayPaperwork;
        discovery: TodayDiscovery;
        comingUp: ComingUpData;
        saved: TodaySavedData;
        continuation: TodayContinuationData;
        welcome: boolean;
        /** The engine's tier-1 and tier-2 items for today (Needs you). */
        needsYou: TodayNeeds;
        /** What's on, while its panel is open (?whats_on=1); null otherwise. */
        whatsOn: WhatsOnData | null;
    }>().props;
    const firstName = auth.user.name.trim().split(/\s+/)[0];
    const phone = usePhone();
    // The columns beside the departures: the events under them fill up to these.
    const dayColumn = useRef<HTMLDivElement>(null);
    const ideasColumn = useRef<HTMLDivElement>(null);
    const beside = useMemo(() => [dayColumn, ideasColumn], []);
    const lead = useSteadyLead(
        day,
        welcome,
        // Whether there are ideas left.
        !!discovery && (discovery.events.length > 0 || !!discovery.around),
    );

    useDayRefresh(day);
    // Check departures' times move: re-read the station every 30 s, as
    // Travel does (the server's cache).
    // None to read when there's nothing to anchor one (the server sends null).
    const { start: startPoll, stop: stopPoll } = usePoll(
        30_000,
        { only: ['station', 'stationWarming', 'stationReason'], async: true },
        { autoStart: false },
    );
    const noStation = station === null && !stationWarming;

    useEffect(() => {
        if (noStation) {
            return;
        }

        startPoll();

        return stopPoll;
    }, [noStation, startPoll, stopPoll]);

    // A board being read for the first time is ready in a moment: look once
    // soon, in place, rather than wait for the poll.
    useEffect(() => {
        if (!stationWarming) {
            return;
        }

        const soon = window.setTimeout(
            () =>
                router.reload({
                    only: ['station', 'stationWarming', 'stationReason'],
                    async: true,
                }),
            2500,
        );

        return () => window.clearTimeout(soon);
    }, [stationWarming]);

    // Paperwork's next step, or with no record yet its way in ("Settling
    // in?"). Where it goes follows the lead (priority.ts COLUMNS).
    const blocks: Record<Block, ReactNode> = {
        agenda: (
            <YourDay
                day={day}
                paperwork={paperwork?.processes}
                offer={!welcome}
            />
        ),
        paperwork: (
            <div className="today-paperwork">
                {paperwork?.focus ? (
                    <div className="practical-personal">
                        <PaperworkPanel focus={paperwork.focus} />
                    </div>
                ) : (
                    <SettlingIn />
                )}
            </div>
        ),
        city: (
            <div className="practical-surface">
                <CityHelp
                    weather={weather}
                    forecast={forecast}
                    station={station}
                    warming={stationWarming}
                    reason={stationReason}
                    stop={stop}
                    favourites={favouriteStops}
                    learned={learnedStops}
                />
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
        // The room left under the departures, from 1000px: more of today's
        // events, as many as fit beside the other columns.
        more: !phone && discovery && (
            <MoreEvents discovery={discovery} beside={beside} />
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
    const columns = COLUMNS[lead];

    return (
        <AppLayout>
            <Head title="Today" />
            <h1 className="sr-only">Today, {firstName}</h1>
            {/* The welcome and a deadline sit on the page, above the day's
                surface (the mock's main). */}
            {welcome && <TodayWelcome />}
            <NeedsStrip needs={needsYou} paperwork={paperwork?.strip ?? null} />
            <div className="today-workspace">
                {/* Phones read one column in priority order; wider screens
                    keep the day on the left, the ideas in the middle and the
                    city's help on the right (priority.ts COLUMNS).
                    The elements themselves move, so reading, keyboard and
                    visual order agree. */}
                <div className="daily-canvas">
                    {phone ? (
                        place(PHONE_ORDER[lead])
                    ) : (
                        <>
                            <div className="day-area" ref={dayColumn}>
                                {place(columns.day)}
                            </div>
                            <div className="next-area">
                                <div className="ideas-area" ref={ideasColumn}>
                                    {place(columns.ideas)}
                                </div>
                                <div className="city-area">
                                    {place(columns.city)}
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>
            {whatsOn && <WhatsOn data={whatsOn} todayPlan={day} />}
        </AppLayout>
    );
}
