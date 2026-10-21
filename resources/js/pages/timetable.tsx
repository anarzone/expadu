import { Deferred, Head, router, usePage, usePoll } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useShellHeaderAside } from '@/components/shell/use-shell-header-aside';
import { MyDay } from '@/components/travel/day/my-day';
import type { DayWeather } from '@/components/travel/day/my-day';
import { NearbyView } from '@/components/travel/nearby/nearby-view';
import type { FavouriteStop, Station } from '@/components/travel/nearby/types';
import { NextTripCard } from '@/components/travel/next-trip-card';
import {
    activeTripHref,
    useTripLeadsBack,
} from '@/components/travel/on-your-way-bar';
import { PlanPanel } from '@/components/travel/plan/plan-panel';
import type {
    HandoffEvent,
    HandoffPlace,
    PlanStage,
    SavedPlace,
    ShownWay,
} from '@/components/travel/plan/plan-panel';
import { TravelIcon } from '@/components/travel/travel-icon';
import type { TripFor } from '@/components/travel/trip/trip-model';
import { TripPage } from '@/components/travel/trip/trip-page';
import {
    isGoing,
    tripOf,
    useStartTrip,
} from '@/components/travel/use-start-trip';
import {
    locateErrorText,
    useTravelLocation,
} from '@/components/travel/use-travel-location';
import type { ActiveTrip } from '@/hooks/use-active-trip';
import AppLayout from '@/layouts/app-layout';
import { usePageContext } from '@/lib/page-context';
import { cn } from '@/lib/utils';
import type { DayItem, DayProjection } from '@/types/day';
import type { Journey } from '@/types/transit';
import '../../css/travel.css';
import '../../css/travel-page.css';

/**
 * Travel (departures-next/): Nearby, the closest station by boarding area,
 * opens first (owner, 9 Oct 2026). On desktop Plan a trip's panel sits
 * beside it; on phones "Where to?" opens it. `?stop=` shows another station
 * (Today's old `?view=timetable&stop=` too). `?view=route` is the trip
 * page; `?view=day` is My day (phones' Nearby | My day switch).
 */

type View = 'stop' | 'journey' | 'route' | 'day';

type PageProps = {
    station?: Station | null;
    stop?: string | null;
    favouriteStops?: FavouriteStop[];
    savedPlaces?: SavedPlace[];
    day?: DayProjection | null;
    dayWeather?: DayWeather | null;
    handoffEvent?: HandoffEvent | null;
    handoffPlace?: HandoffPlace | null;
};

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

/** A trip handed over (Today, Events, Composer) or `?view=journey` opens Plan a trip. */
const viewOf = (url: string): View => {
    const params = new URLSearchParams(url.split('?')[1] ?? '');

    if (params.get('view') === 'route' || params.get('journey_open') === '1') {
        return 'route';
    }

    if (params.get('view') === 'day') {
        return 'day';
    }

    return params.get('view') === 'journey' ||
        params.has('to_lat') ||
        params.has('to_name') ||
        params.has('to')
        ? 'journey'
        : 'stop';
};

/** Plan a trip's panel sits beside Nearby from 1000px (hub.css). */
function useDesktop(): boolean {
    const [desktop, setDesktop] = useState(
        () =>
            typeof window !== 'undefined' &&
            window.matchMedia('(min-width: 1000px)').matches,
    );

    useEffect(() => {
        const query = window.matchMedia('(min-width: 1000px)');
        const update = () => setDesktop(query.matches);
        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, []);

    return desktop;
}

/** The clock, to the minute. */
function useMinute(): Date {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 30_000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}

/**
 * The next trip moves on its own (the time to leave, a start, midnight) and
 * with live times: re-read it then, or two minutes after the last answer,
 * while the page is in view; back in view, a missed re-read happens at once.
 */
function useDayRefresh(day: DayProjection | null | undefined) {
    const refreshAt = day?.refresh_at;
    const answeredAt = day?.now;

    useEffect(() => {
        if (answeredAt === undefined) {
            return;
        }

        const at = refreshAt ? Date.parse(refreshAt) : NaN;
        const wait = Number.isFinite(at)
            ? Math.min(Math.max(at - Date.now(), 15_000), 120_000)
            : 120_000;
        const due = Date.now() + wait;
        const reload = () => router.reload({ only: ['day'] });
        const timer = window.setTimeout(() => {
            if (!document.hidden) {
                reload();
            }
        }, wait);
        const onVisible = () => {
            if (!document.hidden && Date.now() >= due) {
                reload();
            }
        };
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [refreshAt, answeredAt]);
}

/** The 140 ms rule: no placeholder for a load that's nearly instant. */
function Waiting({ label }: { label: string }) {
    const [show, setShow] = useState(false);

    useEffect(() => {
        const timer = window.setTimeout(() => setShow(true), 140);

        return () => window.clearTimeout(timer);
    }, []);

    return show ? (
        <p className="nearby-count" role="status">
            {label}
        </p>
    ) : null;
}

/** The plan a trip you're on is for, as it was stored when it started. */
function tripForActive(trip: ActiveTrip): TripFor {
    const target = trip.target;

    return {
        place: target?.title ?? trip.destination.name,
        label: target?.label ?? null,
        start: target
            ? new Intl.DateTimeFormat('en-GB', {
                  hour: '2-digit',
                  minute: '2-digit',
                  timeZone: 'Europe/Berlin',
              }).format(new Date(target.start_at))
            : null,
        startAt: target?.start_at ?? null,
        official: target?.official ?? false,
        kind: target?.kind ?? null,
    };
}

/** The same way: it leaves and arrives at the same times. */
const sameWay = (a: Journey, b: Journey) =>
    a.depart_at === b.depart_at && a.arrive_at === b.arrive_at;

export default function Timetable() {
    const page = usePage<PageProps>();
    const {
        station,
        day,
        dayWeather,
        favouriteStops = [],
        savedPlaces = [],
    } = page.props;
    const [view, setView] = useState<View>(() => viewOf(page.url));
    const [planStage, setPlanStage] = useState<PlanStage>({
        stage: 'rest',
        hasDest: false,
        results: false,
        big: false,
    });
    const onPlanStage = useCallback(
        (next: PlanStage) =>
            setPlanStage((last) =>
                last.stage === next.stage &&
                last.hasDest === next.hasDest &&
                last.results === next.results &&
                last.big === next.big
                    ? last
                    : next,
            ),
        [],
    );
    const desktop = useDesktop();
    // Composer's tray: "About: Travel", or the journey Plan a trip has.
    const [about, setAbout] = useState<string | null>(null);
    usePageContext({ page: 'travel', label: about ?? 'Travel' });
    // A phone's Plan a trip is a focused screen: the shell's header row
    // steps aside (the section capsule stays), as the mock does.
    useShellHeaderAside(view === 'journey');
    const now = useMinute();
    const [favouriteBusy, setFavouriteBusy] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);

    // A partial reload briefly reports a deferred prop as missing: keep
    // drawing the last station so the strips update in place.
    const lastStation = useRef<Station | null | undefined>(station);

    if (station !== undefined) {
        lastStation.current = station;
    }

    const shown = station ?? lastStation.current;
    const lastDay = useRef<DayProjection | null | undefined>(day);

    if (day !== undefined) {
        lastDay.current = day;
    }

    const today = day ?? lastDay.current;

    const location = useTravelLocation({
        onAnchored: () => {
            // A picked stop stays picked; otherwise Nearby re-roots here.
            if (!new URLSearchParams(window.location.search).has('stop')) {
                router.reload({ only: ['station', 'day'] });
            } else {
                router.reload({ only: ['day'] });
            }
        },
    });

    // Live times move: re-read the station every 30 s (the server's cache).
    usePoll(30_000, { only: ['station'], async: true });
    useDayRefresh(today);

    const trip = today?.next_trip ?? null;
    const starter = useStartTrip();
    const going = !!trip && isGoing(trip, starter.activeTrip);
    // The trip page: the way Plan a trip showed (before you start), else
    // the trip you're on.
    const [shownWay, setShownWay] = useState<ShownWay | null>(null);
    const active = starter.activeTrip;
    const before =
        shownWay && !(active && sameWay(active.journey, shownWay.journey))
            ? shownWay
            : null;
    const tripOpen = view === 'route' && (!!before || !!active);
    useTripLeadsBack(going || tripOpen);

    const pickStop = (name: string | null) => {
        const params = new URLSearchParams();

        if (name) {
            params.set('stop', name);
        }

        router.visit(`/timetable${params.size ? `?${params}` : ''}`, {
            only: ['station', 'stop'],
            preserveState: true,
            preserveScroll: true,
        });
    };

    // "Neumarkt ›" in a way's details: that stop's timetable in Nearby, and
    // "Back to your journey" returns to the plan as it was, where it was
    // (departures-next app.js: openBoardingStop, returnFromTimetable).
    const [boardReturn, setBoardReturn] = useState<{
        url: string;
        top: number;
    } | null>(null);
    const openBoardingStop = (name: string) => {
        setBoardReturn({
            url: `${window.location.pathname}${window.location.search}`,
            top: window.scrollY,
        });
        setView('stop');

        if (!desktop) {
            window.scrollTo({ top: 0, behavior: 'instant' });
        }

        pickStop(name);
    };
    const returnToJourney = () => {
        const back = boardReturn;
        setBoardReturn(null);
        setView('journey');

        if (!back) {
            return;
        }

        router.replace({
            url: back.url,
            preserveState: true,
            preserveScroll: true,
        });
        window.requestAnimationFrame(() =>
            window.scrollTo({ top: back.top, behavior: 'instant' }),
        );
    };

    const toggleFavourite = async (name: string, on: boolean) => {
        setFavouriteBusy(true);

        try {
            const response = await fetch('/api/travel/favourite-stops', {
                method: on ? 'POST' : 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ name }),
            });

            if (!response.ok) {
                const data = (await response.json().catch(() => null)) as {
                    errors?: { name?: string[] };
                } | null;
                setNotice(
                    data?.errors?.name?.[0] ??
                        'Your favourites couldn’t be saved. Try again.',
                );

                return;
            }

            setNotice(null);
            router.reload({ only: ['favouriteStops'] });
        } finally {
            setFavouriteBusy(false);
        }
    };

    // "Plan the way" / "Compare them in Plan a trip" for the next thing:
    // Plan a trip opens on its options, there by its start.
    const planToItem = (item: DayItem) => {
        if (item.location?.lat == null || item.location?.lng == null) {
            return;
        }

        // By the plan's id: its place comes from your day, never the URL.
        router.visit(
            `/timetable?${new URLSearchParams({ view: 'journey', activity: item.id, compare: '1' })}`,
            { preserveScroll: false },
        );
    };

    // "I'm on my way" opens the trip page, started (departures-next).
    const startTrip = async () => {
        const next = trip ? tripOf(trip) : null;

        if (
            next &&
            (await starter.begin(
                next.journey,
                next.destination,
                next.origin,
                next.plan,
            ))
        ) {
            setShownWay(null);
            setView('route');
        }
    };

    const closeTrip = () => {
        if (before) {
            setShownWay(null);
            setView('journey');

            return;
        }

        setView('stop');
        router.replace({
            url: '/timetable',
            preserveState: true,
            preserveScroll: true,
        });
    };

    // Nearby | My day: the address follows, so a reload keeps the view.
    const showView = (next: 'stop' | 'day') => {
        setBoardReturn(null);
        setView(next);
        router.replace({
            url: next === 'day' ? '/timetable?view=day' : '/timetable',
            preserveState: true,
            preserveScroll: true,
        });
        window.scrollTo({ top: 0, behavior: 'instant' });
    };
    // Plan a trip from My day: by the plans' ids, never their places.
    const planFrom = (params: Record<string, string>) =>
        router.visit(
            `/timetable?${new URLSearchParams({ view: 'journey', ...params, compare: '1' })}`,
            { preserveScroll: false },
        );

    // The planner's day and clock, in Cologne's time.
    const todayLabel = new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Europe/Berlin',
    }).format(now);
    const target = trip
        ? today?.items.find((item) => item.id === trip.target.id)
        : undefined;
    // Your next trip: Nearby's card, and compact on My day's rail.
    const tripCard = (id: string, compact = false) =>
        trip ? (
            <NextTripCard
                trip={trip}
                id={id}
                compact={compact}
                going={going}
                onStart={() => void startTrip()}
                confirm={starter.confirm}
                starting={starter.busy}
                tripHref={
                    starter.activeTrip
                        ? activeTripHref(starter.activeTrip)
                        : undefined
                }
                onPlan={
                    target?.location?.routable
                        ? () => planToItem(target)
                        : undefined
                }
                onLocate={location.locate}
                icons={{
                    clock: <TravelIcon name="clock" />,
                    unsure: <TravelIcon name="info" />,
                    offline: <TravelIcon name="offline" />,
                    timer: <TravelIcon name="timer" />,
                }}
            />
        ) : null;

    return (
        <AppLayout>
            <Head title="Travel" />
            <h1 className="sr-only">Travel</h1>
            <div
                className={cn(
                    'travel-page',
                    tripOpen && 'trip-open',
                    planStage.hasDest && 'plan-has-dest',
                    planStage.results && 'has-results',
                    planStage.big && 'plan-big',
                )}
                data-view={view}
                data-plan-stage={planStage.stage}
            >
                <div className="travel-phone-bar">
                    <button
                        type="button"
                        className="where-to"
                        onClick={() => {
                            setBoardReturn(null);
                            setView('journey');
                        }}
                    >
                        <span data-icon="search">
                            <TravelIcon name="search" />
                        </span>
                        Where to?
                    </button>
                    <div
                        className="travel-switch"
                        role="group"
                        aria-label="Travel view"
                    >
                        <button
                            type="button"
                            aria-pressed={view !== 'day'}
                            onClick={() => showView('stop')}
                        >
                            Nearby
                        </button>
                        <button
                            type="button"
                            aria-pressed={view === 'day'}
                            onClick={() => showView('day')}
                        >
                            My day
                        </button>
                    </div>
                </div>

                <PlanPanel
                    url={page.url}
                    handoffEvent={page.props.handoffEvent ?? null}
                    handoffPlace={page.props.handoffPlace ?? null}
                    day={today}
                    favouriteStops={favouriteStops}
                    savedPlaces={savedPlaces}
                    nearName={shown?.kind === 'closest' ? shown.name : null}
                    clock={todayLabel}
                    desktop={desktop}
                    hidden={
                        view === 'route' ||
                        view === 'day' ||
                        (!desktop && view !== 'journey')
                    }
                    onStage={onPlanStage}
                    onFold={() => {
                        setView('stop');
                        router.replace({
                            url: '/timetable',
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                    onBoardStop={openBoardingStop}
                    onDay={() => showView('day')}
                    onAbout={setAbout}
                    onShowTheWay={(way) => {
                        setShownWay(way);
                        setView('route');
                    }}
                    onLocate={location.locate}
                    locating={location.locating}
                />

                {tripOpen ? (
                    <TripPage
                        journey={before ? before.journey : active!.journey}
                        trip={before ? before.trip : tripForActive(active!)}
                        city={today?.next_trip?.city ?? null}
                        running={!before}
                        desktop={desktop}
                        onClose={closeTrip}
                        onStart={() => {
                            if (before) {
                                void starter.begin(
                                    before.journey,
                                    before.destination,
                                    before.origin,
                                    before.plan,
                                );
                            }
                        }}
                        starting={starter.busy}
                        confirm={starter.confirm}
                        error={starter.error}
                        inPlan={before?.inPlan}
                        onAddToPlan={
                            before?.addToPlan
                                ? async () => {
                                      if (await before.addToPlan?.()) {
                                          setShownWay({
                                              ...before,
                                              inPlan: true,
                                          });
                                      }
                                  }
                                : undefined
                        }
                        onStop={async () => {
                            const ok = await starter.end();

                            if (ok) {
                                closeTrip();
                            }

                            return ok;
                        }}
                        onReplan={() => {
                            const target = before
                                ? before.plan?.target_id
                                : active?.target?.id;

                            if (target) {
                                router.visit(
                                    `/timetable?${new URLSearchParams({ view: 'journey', activity: target, compare: '1' })}`,
                                );
                            } else {
                                setShownWay(null);
                                setView('journey');
                            }
                        }}
                    />
                ) : null}
                <section
                    id="departures"
                    aria-label="Nearby"
                    hidden={view === 'day' || (!desktop && view === 'journey')}
                >
                    {boardReturn ? (
                        <button
                            type="button"
                            className="quiet-button board-return"
                            onClick={returnToJourney}
                        >
                            <span data-icon="back">
                                <TravelIcon name="back" />
                            </span>
                            Back to your journey
                        </button>
                    ) : null}
                    {/* The day arrives deferred; while it reloads, the last one stays. */}
                    {trip ? (
                        <>
                            {tripCard('nearby-trip')}
                            {starter.error ? (
                                <p className="nearby-stepnote" role="status">
                                    {starter.error}
                                </p>
                            ) : null}
                        </>
                    ) : null}
                    <Deferred
                        data="station"
                        fallback={
                            <Waiting label="Finding the closest station…" />
                        }
                    >
                        {shown ? (
                            <NearbyView
                                station={shown}
                                favourites={favouriteStops}
                                onPickStop={pickStop}
                                onToggleFavourite={toggleFavourite}
                                favouriteBusy={favouriteBusy}
                            />
                        ) : (
                            <div className="nearby-empty">
                                <strong>Where are you?</strong>
                                <span>
                                    Nearby shows the station closest to you.
                                    Share your location, or pick a stop.
                                </span>
                                <button
                                    type="button"
                                    className="quiet-button"
                                    onClick={location.locate}
                                    disabled={location.locating}
                                >
                                    <TravelIcon name="locate" />
                                    {location.locating
                                        ? 'Locating…'
                                        : 'Use my location'}
                                </button>
                            </div>
                        )}
                    </Deferred>
                    {location.error ? (
                        <p className="nearby-stepnote" role="status">
                            {locateErrorText[location.error]}
                        </p>
                    ) : null}
                    {notice ? (
                        <p className="nearby-stepnote" role="status">
                            {notice}
                        </p>
                    ) : null}
                </section>
                <section
                    id="day-view"
                    className="day-view"
                    aria-label="My day"
                    hidden={view !== 'day'}
                >
                    {view === 'day' ? (
                        today ? (
                            <MyDay
                                day={today}
                                weather={dayWeather}
                                trip={tripCard('day-trip', true)}
                                onPlan={(to, after) =>
                                    planFrom({
                                        activity: to.id,
                                        ...(after ? { after: after.id } : {}),
                                    })
                                }
                                onWayBack={(after, to) =>
                                    planFrom({ after: after.id, to })
                                }
                            />
                        ) : (
                            <Waiting label="Reading your day…" />
                        )
                    ) : null}
                </section>
            </div>
        </AppLayout>
    );
}
