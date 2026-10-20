import { Head, router, usePage } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import { useEffect, useState } from 'react';
import EventOtherDayController from '@/actions/App/Http/Controllers/Api/EventOtherDayController';
import TransportModeController from '@/actions/App/Http/Controllers/Api/TransportModeController';
import { api } from '@/components/composer/types';
import { occurrenceKey } from '@/components/events/event-presentation';
import type { EventOccurrence } from '@/components/events/types';
import { EventDetails } from '@/components/events-details/event-details';
import type { EventFilters } from '@/components/events-filters/event-filters';
import {
    dayAreas,
    dayCategories,
    eventsUrl,
    matches,
    narrowed,
    noFilters,
} from '@/components/events-filters/event-filters';
import { EmptyDay, NoMatch } from '@/components/events-filters/events-empty';
import type { OtherDay } from '@/components/events-filters/events-empty';
import {
    EventsFilterBar,
    FromNote,
} from '@/components/events-filters/events-filter-bar';
import type {
    StartingPoint,
    TravelMode,
} from '@/components/events-filters/events-filter-bar';
import {
    EventsStreet,
    useNowHour,
} from '@/components/events-street/events-street';
import type { StreetListing } from '@/components/events-street/events-street';
import { rainLikely } from '@/components/events-street/street-weather';
import type { DayForecast } from '@/components/events-street/street-weather';
import { Poster } from '@/components/posters/poster-art';
import { posterEvent, posterWeekday } from '@/components/posters/poster-model';
import { showToast } from '@/components/ui/toast';
import { useSavedEvents } from '@/hooks/use-saved-events';
import AppLayout from '@/layouts/app-layout';
import { usePageContext } from '@/lib/page-context';
import type { Auth } from '@/types';
import '@/components/events-street/events-page.css';

type Props = {
    /** The day shown, YYYY-MM-DD (Cologne). */
    date: string;
    today: string;
    /** The day's occurrences, earliest first, earlier hours included. */
    listings: EventOccurrence[];
    /** From the URL, so a deep link opens the same view. */
    filters: EventFilters;
    /** The day's forecast, deferred (WeatherService::getDayForecast). */
    weather?: DayForecast;
    /** The URL's starting point, when it names a public point or one of
        the person's saved places (EventStartingPoint). */
    starting_point: PlacePoint | null;
    /** The person's own Home and Work, when saved. */
    saved_places: PlacePoint[];
    /** Today's last recorded appointment, for the details' "For you". */
    appointment: { title: string; end: string } | null;
};

/** The open details, as the URL carries them (?event=&start=). */
type Detail = { event: number; start: string };

function detailIn(url: string): Detail | null {
    const query = new URLSearchParams(url.split('?')[1] ?? '');
    const event = Number(query.get('event'));
    const start = query.get('start');

    return event && start ? { event, start } : null;
}

const withDetail = (base: string, d: Detail | null) =>
    d ? `${base}&event=${d.event}&start=${encodeURIComponent(d.start)}` : base;

type PlacePoint = { key: string; name: string; lat: number; lng: number };

const asStartingPoint = (p: PlacePoint): StartingPoint => ({
    name: p.name,
    key: p.key,
    point: { lat: p.lat, lng: p.lng },
});

type Listing = StreetListing & {
    occurrence: EventOccurrence;
    km: number | null;
};

const longDate = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: 'UTC',
    });

/** Kilometres in a straight line. */
function km(
    a: { lat: number; lng: number },
    b: { lat: number | null; lng: number | null },
): number | null {
    if (b.lat === null || b.lng === null) {
        return null;
    }

    const rad = Math.PI / 180;
    const dLat = (b.lat - a.lat) * rad;
    const dLng = (b.lng - a.lng) * rad;
    const h =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;

    return 12742 * Math.asin(Math.sqrt(h));
}

/* What the listing or the venue's record says: the venue's recorded
   setting, or a listing that says it's outdoors or open-air. Never more. */
const outdoor = (o: EventOccurrence) =>
    o.venue.setting === 'outdoor' ||
    /\b(outdoors?|open[- ]air|im freien)\b/i.test(
        `${o.title_original ?? ''} ${o.title} ${o.summary ?? ''}`,
    );

/** The venue's recorded step-free access, in words; nothing when unknown. */
const stepFree = (value: string | null | undefined) =>
    value === 'yes'
        ? 'Wheelchair accessible'
        : value === 'limited'
          ? 'Partly wheelchair accessible'
          : value === 'no'
            ? 'Not wheelchair accessible'
            : undefined;

const distanceLabel = (value: number) =>
    value < 1 ? `${Math.round(value * 1000)} m` : `${value.toFixed(1)} km`;

/** Events: the day's listings as posters on a Cologne street (the mock's
    events-next), narrowed by the filters above it. */
export default function Events({
    date,
    today,
    listings,
    filters,
    weather,
    starting_point,
    saved_places,
    appointment,
}: Props) {
    const { props: shared, url } = usePage<{ auth: Auth }>();
    const { auth } = shared;
    // The details open over the street live in the URL, so a link (or a
    // reload) opens the same details.
    const detail = detailIn(url);
    const saved = useSavedEvents(auth.user.id);
    const f = { ...noFilters, ...filters };

    // The search follows typing at once; the URL catches up after a pause.
    // The typed text belongs to the URL value it started from, so Back,
    // Forward or Clear (a new URL value) take over from it.
    const [search, setSearch] = useState({ base: f.q, text: f.q });
    const q = search.base === f.q ? search.text : f.q;
    const current = { ...f, q };

    const replace = (next: EventFilters) =>
        router.replace({
            url: withDetail(eventsUrl(date, next), detail),
            props: (props) => ({ ...props, filters: next }),
            preserveState: true,
            preserveScroll: true,
        });

    useEffect(() => {
        if (q === f.q) {
            return;
        }

        const timer = window.setTimeout(() => replace({ ...f, q }), 350);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- Only the typed text schedules the URL update.
    }, [q]);

    const onFilters = (partial: Partial<EventFilters>) => {
        if ('q' in partial) {
            setSearch({ base: f.q, text: partial.q ?? '' });

            if (Object.keys(partial).length === 1) {
                return;
            }
        }

        replace({ ...current, ...partial });
    };

    const onDate = (day: string) =>
        router.get(
            eventsUrl(day, current),
            {},
            {
                preserveState: true,
                preserveScroll: true,
            },
        );

    // The starting point. The URL carries only a public point's name or a
    // saved place's key, resolved on the server; a typed address stays in
    // this state, out of the URL, history and server logs.
    const [chosen, setChosen] = useState<StartingPoint | null>(null);
    const [mode, setMode] = useState<TravelMode>(
        (auth.user.transport_mode as TravelMode | null) ?? 'transit',
    );
    const shownFrom =
        chosen && chosen.key === (f.from ?? null)
            ? chosen
            : f.from && starting_point?.key === f.from
              ? asStartingPoint(starting_point)
              : null;

    const onFrom = (next: StartingPoint | null) => {
        setChosen(next);
        onFilters({
            from: next?.key ?? null,
            sort:
                !next && current.sort === 'closest' ? 'soonest' : current.sort,
        });
    };

    const onMode = (next: TravelMode) => {
        setMode(next);
        api(TransportModeController.url(), { mode: next }).catch(() =>
            showToast('Your travel mode wasn’t saved. Please try again.', {
                error: true,
            }),
        );
    };

    // The day, and what passes the filters, in the chosen order.
    const point = shownFrom?.point ?? null;
    const day: Listing[] = listings.map((occurrence) => ({
        key: occurrenceKey(occurrence),
        poster: { ...posterEvent(occurrence), id: occurrenceKey(occurrence) },
        occurrence,
        km: point ? km(point, occurrence.venue) : null,
    }));
    const wall = day
        .filter((l) => matches(l.occurrence, current, l.key, saved.keys))
        .sort((a, b) => {
            if (current.sort === 'closest') {
                return (a.km ?? Infinity) - (b.km ?? Infinity);
            }

            if (current.sort === 'evening') {
                const ea = (a.poster.start ?? '') >= '17:00' ? 0 : 1;
                const eb = (b.poster.start ?? '') >= '17:00' ? 0 : 1;

                return ea - eb;
            }

            return 0;
        });
    const filtering = narrowed(current);
    const empty = listings.length === 0;
    const noMatch =
        !empty &&
        filtering &&
        wall.length === 0 &&
        !(current.saved && saved.loading);

    // Where else the search or filters match (or, on an empty day, any listing).
    const [other, setOther] = useState<{ key: string; value: OtherDay }>({
        key: '',
        value: null,
    });
    const otherKey =
        empty || noMatch
            ? eventsUrl(date, { ...current, sort: 'soonest', from: null })
            : '';

    useEffect(() => {
        if (!otherKey) {
            return;
        }

        const controller = new AbortController();
        const query: Record<string, string> = { date };

        if (!empty) {
            if (current.q.trim()) {
                query.q = current.q.trim();
            }

            if (current.category) {
                query.category = current.category;
            }

            if (current.area) {
                query.area = current.area;
            }

            if (current.free) {
                query.free = '1';
            }

            if (current.saved) {
                query.saved = '1';
            }
        }

        api<{ date: string | null; count: number }>(
            EventOtherDayController.url({ query }),
            undefined,
            controller.signal,
        )
            .then((value) => setOther({ key: otherKey, value }))
            .catch(() => setOther({ key: otherKey, value: null }));

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- The key carries the day and filters.
    }, [otherKey]);

    const otherDay = other.key === otherKey ? other.value : null;

    usePageContext(
        wall.length
            ? {
                  page: 'events',
                  label: `Events on ${posterWeekday(date)} · ${wall.length} listed`,
              }
            : null,
    );

    async function toggleSave(listing: Listing) {
        const isSaved = saved.keys.has(listing.key);
        const ok = await saved.setSaved(listing.occurrence, !isSaved);

        if (!ok && saved.error) {
            showToast(saved.error, { error: true });
        }
    }

    // Today's posters say where they stand now: on (until its listed end),
    // over, and rain likely at the start for an outdoor venue.
    const nowHour = useNowHour();
    const hours = (time: string) =>
        Number(time.slice(0, 2)) + Number(time.slice(3, 5)) / 60;
    const todayState = (listing: Listing) => {
        const p = listing.poster;

        if (date !== today || !p.start) {
            return { when: null, flags: null };
        }

        const start = hours(p.start);
        let end = p.end ? hours(p.end) : null;

        if (end !== null && end <= start) {
            end += 24;
        }

        const when =
            end === null
                ? null
                : nowHour >= end
                  ? ('over' as const)
                  : nowHour >= start
                    ? ('now' as const)
                    : null;
        const wet =
            when !== 'over' &&
            outdoor(listing.occurrence) &&
            rainLikely(weather, p.start);

        return {
            when,
            flags:
                when || wet ? (
                    <>
                        {when === 'now' ? (
                            <span className="pp-flag pp-flag--now">
                                <span aria-hidden="true" />
                                On now · until {p.end}
                            </span>
                        ) : when === 'over' ? (
                            <span className="pp-flag pp-flag--over">Over</span>
                        ) : null}
                        {wet ? (
                            <span className="pp-flag pp-flag--rain">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M12 3.5c-3.2 4.2-5.6 7.2-5.6 10.2a5.6 5.6 0 0 0 11.2 0c0-3-2.4-6-5.6-10.2Z" />
                                </svg>
                                Rain likely · forecast
                            </span>
                        ) : null}
                    </>
                ) : null,
        };
    };

    // A poster opens its details (the mock's "one step"); closing keeps
    // the street and its filters as they were.
    const showDetail = (d: Detail | null) =>
        router.replace({
            url: withDetail(eventsUrl(date, current), d),
            preserveState: true,
            preserveScroll: true,
        });
    const opened = detail
        ? (day.find(
              (l) =>
                  l.occurrence.id === detail.event &&
                  Date.parse(l.occurrence.occurrence_start) ===
                      Date.parse(detail.start),
          ) ?? null)
        : null;

    const poster = (
        item: StreetListing,
        where: 'wall' | 'column' | 'story',
        style?: CSSProperties,
    ) => {
        const listing = item as Listing;
        const state = todayState(listing);

        return (
            <Poster
                event={listing.poster}
                today={today}
                size={where === 'column' ? 'column' : 'wall'}
                style={style}
                distance={
                    listing.km !== null ? distanceLabel(listing.km) : undefined
                }
                access={stepFree(listing.occurrence.venue.wheelchair)}
                when={state.when}
                flags={state.flags}
                saved={saved.keys.has(listing.key)}
                onSave={() => toggleSave(listing)}
                // A story's poster has Add to plan: it opens the details, where
                // the plan is made (the mock's mxOpen(e, 'plan')).
                onPlan={
                    where === 'story'
                        ? () =>
                              showDetail({
                                  event: listing.occurrence.id,
                                  start: listing.occurrence.occurrence_start,
                              })
                        : undefined
                }
                onOpen={() =>
                    showDetail({
                        event: listing.occurrence.id,
                        start: listing.occurrence.occurrence_start,
                    })
                }
            />
        );
    };

    return (
        <AppLayout>
            <Head title="Events" />
            <h1 className="sr-only">Events on {longDate(date)}</h1>
            <EventsFilterBar
                date={date}
                filters={current}
                onFilters={onFilters}
                onDate={onDate}
                categories={dayCategories(listings, current.category)}
                areas={dayAreas(listings, current.area)}
                from={shownFrom}
                savedPlaces={saved_places.map(asStartingPoint)}
                mode={mode}
                onFrom={onFrom}
                onMode={onMode}
            />
            <FromNote from={shownFrom} />
            <section className="ev-board" aria-label="The day's events">
                <p className="sr-only" aria-live="polite">
                    {wall.length === 1
                        ? '1 event'
                        : `${wall.length || 'No'} events`}{' '}
                    on {longDate(date)}
                </p>
                {empty ? (
                    <EmptyDay
                        date={date}
                        today={today}
                        other={otherDay}
                        onDate={onDate}
                    />
                ) : noMatch ? (
                    <NoMatch
                        date={date}
                        today={today}
                        q={current.q.trim()}
                        narrowed={
                            !!(
                                current.category ||
                                current.area ||
                                current.free ||
                                current.saved
                            )
                        }
                        other={otherDay}
                        onDate={onDate}
                        onClear={(what) =>
                            onFilters(
                                what === 'search'
                                    ? { q: '' }
                                    : {
                                          q: '',
                                          category: null,
                                          area: null,
                                          free: false,
                                          saved: false,
                                      },
                            )
                        }
                    />
                ) : (
                    <EventsStreet
                        date={date}
                        today={today}
                        wall={wall}
                        column={day}
                        poster={poster}
                        forecast={weather}
                        waiting={!!opened}
                    />
                )}
            </section>
            <EventDetails
                listing={opened}
                date={date}
                today={today}
                from={shownFrom?.point ? shownFrom.name : null}
                appointment={appointment}
                saved={opened ? saved.keys.has(opened.key) : false}
                onSave={() => opened && toggleSave(opened)}
                onClose={() => showDetail(null)}
            />
        </AppLayout>
    );
}
