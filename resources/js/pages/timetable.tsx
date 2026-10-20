import {
    Deferred,
    Head,
    Link,
    router,
    usePage,
    usePoll,
} from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { SavedPlace } from '@/components/departures/journey-planner';
import { LegacyJourney } from '@/components/departures/legacy-journey';
import { travelDestinationParams } from '@/components/departures/travel-request';
import { NearbyView } from '@/components/travel/nearby/nearby-view';
import type { FavouriteStop, Station } from '@/components/travel/nearby/types';
import { NextTripCard } from '@/components/travel/next-trip-card';
import {
    activeTripHref,
    useTripLeadsBack,
} from '@/components/travel/on-your-way-bar';
import { TravelIcon } from '@/components/travel/travel-icon';
import {
    isGoing,
    tripOf,
    useStartTrip,
} from '@/components/travel/use-start-trip';
import {
    locateErrorText,
    useTravelLocation,
} from '@/components/travel/use-travel-location';
import AppLayout from '@/layouts/app-layout';
import type { DayItem, DayProjection } from '@/types/day';
import '../../css/travel.css';
import '../../css/travel-page.css';

/**
 * Travel (departures-next/): Nearby, the closest station by boarding area,
 * opens first (owner, 9 Oct 2026). On desktop Plan a trip's panel sits
 * beside it; on phones "Where to?" opens it. `?stop=` shows another station
 * (Today's old `?view=timetable&stop=` too).
 */

type View = 'stop' | 'journey';

type PageProps = {
    station?: Station | null;
    stop?: string | null;
    favouriteStops?: FavouriteStop[];
    savedPlaces?: SavedPlace[];
    day?: DayProjection | null;
};

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

/** A trip handed over (Today, Events, Composer) or `?view=journey` opens Plan a trip. */
const wantsJourney = (url: string) => {
    const params = new URLSearchParams(url.split('?')[1] ?? '');

    return (
        params.get('view') === 'journey' ||
        params.get('view') === 'route' ||
        params.has('to_lat') ||
        params.has('to_name')
    );
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

/** Today's places that have a place to go to, still ahead. */
function dayPlaces(day: DayProjection | null | undefined): DayItem[] {
    return (day?.items ?? []).filter(
        (item) =>
            item.state === 'upcoming' &&
            !item.completed &&
            item.location?.routable === true,
    );
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

export default function Timetable() {
    const page = usePage<PageProps>();
    const { station, day, favouriteStops = [], savedPlaces = [] } = page.props;
    const [view, setView] = useState<View>(() =>
        wantsJourney(page.url) ? 'journey' : 'stop',
    );
    const desktop = useDesktop();
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
    useTripLeadsBack(going);

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

    const planTo = (place: {
        name: string;
        lat: number | null;
        lng: number | null;
        arriveBy?: string | null;
    }) => {
        if (place.lat == null || place.lng == null) {
            return;
        }

        router.visit(
            `/timetable?${new URLSearchParams(
                travelDestinationParams({
                    name: place.name,
                    lat: place.lat,
                    lng: place.lng,
                    arriveBy: place.arriveBy ?? undefined,
                }),
            )}`,
            { preserveScroll: false },
        );
    };

    // Go to one of today's places, there by its start.
    const planToItem = (item: DayItem) =>
        planTo({
            name: item.location?.label ?? item.title,
            lat: item.location?.lat ?? null,
            lng: item.location?.lng ?? null,
            arriveBy: item.start_at,
        });

    const startTrip = () => {
        const next = trip ? tripOf(trip) : null;

        if (next) {
            void starter.begin(next.journey, next.destination, next.origin);
        }
    };

    // The planner's day and clock, in Cologne's time.
    const todayLabel = new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Europe/Berlin',
    }).format(now);
    const home = savedPlaces.find((p) => p.category === 'home');
    const work = savedPlaces.find((p) => p.category === 'work');
    const places = dayPlaces(today);
    const target = trip
        ? today?.items.find((item) => item.id === trip.target.id)
        : undefined;

    return (
        <AppLayout>
            <Head title="Travel" />
            <h1 className="sr-only">Travel</h1>
            <div
                className="travel-page"
                data-view={view}
                data-plan-stage="rest"
            >
                <div className="travel-phone-bar">
                    <button
                        type="button"
                        className="where-to"
                        onClick={() => setView('journey')}
                    >
                        <span data-icon="search">
                            <TravelIcon name="search" />
                        </span>
                        Where to?
                    </button>
                </div>

                <div id="journey-view" hidden={view === 'journey' || !desktop}>
                    <div className="plan-panel-head">
                        <span className="plan-panel-title">
                            <strong>Plan a trip</strong>
                            <small className="plan-panel-sample">
                                {todayLabel}
                            </small>
                        </span>
                    </div>
                    <div className="journey-intro-grid">
                        <section
                            className="journey-desk"
                            aria-label="Plan a journey"
                        >
                            <form
                                id="journey-form"
                                noValidate
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    setView('journey');
                                }}
                            >
                                <div className="journey-fields">
                                    <div className="field to-field">
                                        <span
                                            className="field-symbol"
                                            data-icon="pin"
                                            aria-hidden="true"
                                        >
                                            <TravelIcon name="pin" />
                                        </span>
                                        <label htmlFor="travel-to">To</label>
                                        <input
                                            id="travel-to"
                                            placeholder="Where to?"
                                            autoComplete="off"
                                            readOnly
                                            onFocus={() => setView('journey')}
                                            onClick={() => setView('journey')}
                                        />
                                    </div>
                                </div>
                            </form>
                        </section>
                        <div className="journey-results">
                            <section
                                id="journey-start"
                                className="journey-start"
                                aria-labelledby="favourites-title"
                            >
                                <h2
                                    className="plan-subhead"
                                    id="favourites-title"
                                >
                                    Favourites
                                </h2>
                                <div
                                    id="favourite-stops"
                                    className="favourite-stops"
                                >
                                    {favouriteStops.map((stop) => (
                                        <button
                                            key={stop.name}
                                            type="button"
                                            onClick={() => pickStop(stop.name)}
                                        >
                                            <span className="favourite-mark">
                                                <TravelIcon name="star" />
                                            </span>
                                            <span className="favourite-text">
                                                <strong>{stop.name}</strong>
                                                <small>
                                                    Stop
                                                    {shown?.kind ===
                                                        'closest' &&
                                                    shown.name === stop.name
                                                        ? ' · nearest you'
                                                        : ''}
                                                </small>
                                            </span>
                                        </button>
                                    ))}
                                </div>
                                <div
                                    id="favourite-places"
                                    className="saved-places"
                                >
                                    {[
                                        ['home', 'Home', home],
                                        ['work', 'Work', work],
                                    ].map(([kind, label, place]) =>
                                        place && typeof place === 'object' ? (
                                            <button
                                                key={kind as string}
                                                type="button"
                                                onClick={() => planTo(place)}
                                            >
                                                <TravelIcon
                                                    name={
                                                        kind === 'home'
                                                            ? 'home'
                                                            : 'briefcase'
                                                    }
                                                />
                                                <span>
                                                    <strong>
                                                        {label as string}
                                                    </strong>
                                                    <small>{place.name}</small>
                                                </span>
                                            </button>
                                        ) : (
                                            <Link
                                                key={kind as string}
                                                href="/profile"
                                                className="is-empty"
                                                as="button"
                                            >
                                                <TravelIcon
                                                    name={
                                                        kind === 'home'
                                                            ? 'home'
                                                            : 'briefcase'
                                                    }
                                                />
                                                <span>
                                                    <strong>
                                                        {label as string}
                                                    </strong>
                                                    <small>
                                                        Add an address
                                                    </small>
                                                </span>
                                            </Link>
                                        ),
                                    )}
                                </div>
                                {places.length ? (
                                    <>
                                        <div className="section-heading">
                                            <h2 id="places-title">
                                                Today’s places
                                            </h2>
                                            <span className="section-note">
                                                From My day
                                            </span>
                                        </div>
                                        <div
                                            id="day-places"
                                            className="day-places"
                                        >
                                            {places.map((item) => (
                                                <button
                                                    key={item.id}
                                                    type="button"
                                                    aria-pressed={false}
                                                    onClick={() =>
                                                        planToItem(item)
                                                    }
                                                >
                                                    <time
                                                        dateTime={
                                                            item.start_at ??
                                                            undefined
                                                        }
                                                    >
                                                        {item.start_time}
                                                    </time>
                                                    <span>
                                                        <strong>
                                                            {item.title}
                                                        </strong>
                                                        <small>
                                                            {
                                                                item.location
                                                                    ?.label
                                                            }
                                                        </small>
                                                    </span>
                                                    <span className="day-place-pick">
                                                        Go here
                                                    </span>
                                                    <TravelIcon name="chevron" />
                                                </button>
                                            ))}
                                        </div>
                                    </>
                                ) : null}
                            </section>
                        </div>
                    </div>
                </div>

                {view === 'journey' ? (
                    <section id="legacy-journey" aria-label="Plan a trip">
                        <LegacyJourney onClose={() => setView('stop')} />
                    </section>
                ) : (
                    <section id="departures" aria-label="Nearby">
                        {/* The day arrives deferred; while it reloads, the last one stays. */}
                        {trip ? (
                            <>
                                <NextTripCard
                                    trip={trip}
                                    id="nearby-trip"
                                    going={going}
                                    onStart={startTrip}
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
                                        timer: <TravelIcon name="timer" />,
                                    }}
                                />
                                {starter.error ? (
                                    <p
                                        className="nearby-stepnote"
                                        role="status"
                                    >
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
                )}
            </div>
        </AppLayout>
    );
}
