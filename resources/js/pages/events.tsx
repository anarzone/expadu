import { Head, router, usePage } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import { useEffect, useState } from 'react';
import EventOtherDayController from '@/actions/App/Http/Controllers/Api/EventOtherDayController';
import GeocodeController from '@/actions/App/Http/Controllers/Api/GeocodeController';
import TransportModeController from '@/actions/App/Http/Controllers/Api/TransportModeController';
import { api } from '@/components/composer/types';
import { occurrenceKey } from '@/components/events/event-presentation';
import type { EventOccurrence } from '@/components/events/types';
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
import { EventsStreet } from '@/components/events-street/events-street';
import type { StreetListing } from '@/components/events-street/events-street';
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
};

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

const distanceLabel = (value: number) =>
    value < 1 ? `${Math.round(value * 1000)} m` : `${value.toFixed(1)} km`;

/** Events: the day's listings as posters on a Cologne street (the mock's
    events-next), narrowed by the filters above it. */
export default function Events({ date, today, listings, filters }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
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
            url: eventsUrl(date, next),
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

    // The starting point: its name is in the URL, its place found here
    // (recognised places only; an unknown name shows no distances).
    const [from, setFrom] = useState<StartingPoint | null>(null);
    const [mode, setMode] = useState<TravelMode>(
        (auth.user.transport_mode as TravelMode | null) ?? 'transit',
    );

    useEffect(() => {
        if (!f.from || from?.name === f.from) {
            return;
        }

        const controller = new AbortController();
        api<{ name: string; lat: number; lng: number }[]>(
            GeocodeController.url({ query: { q: f.from } }),
            undefined,
            controller.signal,
        )
            .then((rows) =>
                setFrom({
                    name: f.from!,
                    point: rows[0]
                        ? { lat: rows[0].lat, lng: rows[0].lng }
                        : null,
                }),
            )
            .catch(() => setFrom({ name: f.from!, point: null }));

        return () => controller.abort();
    }, [f.from, from?.name]);

    const shownFrom = f.from ? (from?.name === f.from ? from : null) : null;

    const onFrom = (next: StartingPoint | null) => {
        setFrom(next);
        onFilters({
            from: next?.name ?? null,
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

    const poster = (
        item: StreetListing,
        where: 'wall' | 'column',
        style?: CSSProperties,
    ) => {
        const listing = item as Listing;

        return (
            <Poster
                event={listing.poster}
                today={today}
                size={where}
                style={style}
                distance={
                    listing.km !== null ? distanceLabel(listing.km) : undefined
                }
                saved={saved.keys.has(listing.key)}
                onSave={() => toggleSave(listing)}
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
                    />
                )}
            </section>
        </AppLayout>
    );
}
