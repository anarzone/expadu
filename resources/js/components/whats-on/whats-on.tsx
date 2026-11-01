import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import EventOtherDayController from '@/actions/App/Http/Controllers/Api/EventOtherDayController';
import TransportModeController from '@/actions/App/Http/Controllers/Api/TransportModeController';
import { api } from '@/components/composer/types';
import { occurrenceKey } from '@/components/events/event-presentation';
import type { EventOccurrence } from '@/components/events/types';
import { EventDetails } from '@/components/events-details/event-details';
import type { EventFilters } from '@/components/events-filters/event-filters';
import {
    areaChoices,
    categoryChoices,
    matches,
    narrowed,
    noFilters,
    whatsOnUrl,
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
import { PastNote } from '@/components/events-filters/past-note';
import { HowWeRankLink } from '@/components/personalisation/how-we-rank';
import { posterEvent, posterInk } from '@/components/posters/poster-model';
import type { PosterEvent } from '@/components/posters/poster-model';
import { openComposerTray } from '@/components/shell/composer-tray';
import { Icon } from '@/components/ui/icon';
import { Sheet } from '@/components/ui/sheet';
import { showToast } from '@/components/ui/toast';
import { useSavedEvents } from '@/hooks/use-saved-events';
import { kmBetween } from '@/lib/geo';
import { isHidden, useHidden } from '@/lib/not-now';
import { useImpressions } from '@/lib/signals';
import { cn } from '@/lib/utils';
import type { Auth } from '@/types';
import type { DayProjection } from '@/types/day';
import '@/components/posters/poster-art.css';
import './whats-on.css';

type PlacePoint = { key: string; name: string; lat: number; lng: number };

/** What's on, as Today's server sends it while the panel is open (App\Home\WhatsOn). */
export type WhatsOnData = {
    /** The day shown, YYYY-MM-DD (Cologne). */
    date: string;
    today: string;
    filters: EventFilters;
    /** The listing whose details the URL opens. */
    event: Detail | null;
    starting_point: PlacePoint | null;
    saved_places: PlacePoint[];
    favourite_stops: PlacePoint[];
    appointment: {
        title: string;
        end: string;
        place: { name: string; lat: number; lng: number } | null;
    } | null;
    /** Another day's plan, for Get there (today's is Today's own). */
    day: DayProjection | null;
    catalogue: { categories: string[]; areas: string[] };
    /** The day's occurrences, earliest first, earlier hours included. */
    listings: EventOccurrence[];
    next_listed: string | null;
};

type Detail = { event: number; start: string };

type Listing = {
    key: string;
    poster: PosterEvent;
    occurrence: EventOccurrence;
    km: number | null;
};

const withDetail = (base: string, d: Detail | null) =>
    d ? `${base}&event=${d.event}&start=${encodeURIComponent(d.start)}` : base;

const asStartingPoint = (p: PlacePoint): StartingPoint => ({
    name: p.name,
    key: p.key,
    point: { lat: p.lat, lng: p.lng },
});

const longDate = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

const distanceLabel = (value: number) =>
    value < 1 ? `${Math.round(value * 1000)} m` : `${value.toFixed(1)} km`;

const minutesOf = (time: string) =>
    Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5));

/**
 * What's on: the day's listings in a panel over Today (owner, 27 Oct 2026: no Events page),
 * filtered as the Events page filtered them, grouped by when they start; a listing opens its
 * details in the same place, with the way back to the list.
 */
export function WhatsOn({
    data,
    todayPlan,
}: {
    data: WhatsOnData;
    /** Today's own day, for Get there and "In your plan" on today. */
    todayPlan: DayProjection;
}) {
    const { props: shared, url } = usePage<{ auth: Auth }>();
    const { auth } = shared;
    const { date, today, listings } = data;
    const query = new URLSearchParams(url.split('?')[1] ?? '');
    const detail: Detail | null =
        Number(query.get('event')) && query.get('start')
            ? { event: Number(query.get('event')), start: query.get('start')! }
            : null;
    const saved = useSavedEvents(auth.user.id);
    const shown = useImpressions('events', 'event');
    const hidden = useHidden();
    const f = { ...noFilters, ...data.filters };
    const [open, setOpen] = useState(true);
    const [view, setView] = useView();
    const [earlierOpen, setEarlierOpen] = useState(false);

    // The search follows typing at once; the URL catches up after a pause.
    const [search, setSearch] = useState({ base: f.q, text: f.q });
    const q = search.base === f.q ? search.text : f.q;
    const current = { ...f, q };

    const replace = (next: EventFilters) =>
        router.replace({
            url: withDetail(whatsOnUrl(date, next), detail),
            props: (props) => ({
                ...props,
                whatsOn: { ...(props.whatsOn as WhatsOnData), filters: next },
            }),
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
            whatsOnUrl(day, current),
            {},
            {
                only: ['whatsOn'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );

    // The starting point: the URL carries only a public point's name or a saved place's key;
    // a typed address stays in this state, out of the URL, history and server logs.
    const [chosen, setChosen] = useState<StartingPoint | null>(null);
    const [mode, setMode] = useState<TravelMode>(
        (auth.user.transport_mode as TravelMode | null) ?? 'transit',
    );
    const shownFrom =
        chosen && chosen.key === (f.from ?? null)
            ? chosen
            : f.from && data.starting_point?.key === f.from
              ? asStartingPoint(data.starting_point)
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

    // The day, and what passes the filters, in the chosen order, less what was put away with
    // Not now this week.
    const point = shownFrom?.point ?? null;
    const day: Listing[] = listings
        .filter((o) => !isHidden(hidden, 'event', o.id))
        .map((occurrence) => ({
            key: occurrenceKey(occurrence),
            poster: {
                ...posterEvent(occurrence),
                id: occurrenceKey(occurrence),
            },
            occurrence,
            km: point ? kmBetween(point, occurrence.venue) : null,
        }));
    const list = day
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
    const empty = listings.length === 0;
    const noMatch =
        !empty &&
        narrowed(current) &&
        list.length === 0 &&
        !(current.saved && saved.loading);

    // Where else the search or filters match (or, on an empty day, any listing).
    const [other, setOther] = useState<{ key: string; value: OtherDay }>({
        key: '',
        value: null,
    });
    const otherKey =
        empty || noMatch
            ? whatsOnUrl(date, { ...current, sort: 'soonest', from: null })
            : '';

    useEffect(() => {
        if (!otherKey) {
            return;
        }

        const controller = new AbortController();
        const ask: Record<string, string> = { date };

        if (!empty) {
            if (current.q.trim()) {
                ask.q = current.q.trim();
            }

            if (current.category) {
                ask.category = current.category;
            }

            if (current.area) {
                ask.area = current.area;
            }

            if (current.free) {
                ask.free = '1';
            }

            if (current.saved) {
                ask.saved = '1';
            }

            if (current.no_german) {
                ask.no_german = '1';
            }
        }

        api<{ date: string | null; count: number }>(
            EventOtherDayController.url({ query: ask }),
            undefined,
            controller.signal,
        )
            .then((value) => setOther({ key: otherKey, value }))
            .catch(() => setOther({ key: otherKey, value: null }));

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- The key carries the day and filters.
    }, [otherKey]);

    const otherDay = other.key === otherKey ? other.value : null;

    async function toggleSave(listing: Listing) {
        const isSaved = saved.keys.has(listing.key);
        const ok = await saved.setSaved(listing.occurrence, !isSaved);

        if (!ok && saved.error) {
            showToast(saved.error, { error: true });
        }
    }

    // Where each listing stands now, on today: over (its listed end has passed) or on.
    const now = useNowMinutes();
    const stateOf = (l: Listing): 'over' | 'now' | null => {
        const p = l.poster;

        if (date !== today || !p.start || !p.end) {
            return null;
        }

        const start = minutesOf(p.start);
        let end = minutesOf(p.end);

        if (end <= start) {
            end += 24 * 60;
        }

        return now >= end ? 'over' : now >= start ? 'now' : null;
    };

    // What's true of a listing for this person: their plan has it, or it's held in English.
    const planned = new Set(
        date === today
            ? todayPlan.items
                  .filter((item) => item.start_time)
                  .map(
                      (item) =>
                          `${item.title.trim().toLowerCase()}|${item.start_time}`,
                  )
            : [],
    );
    const whyOf = (l: Listing): { text: string; tone: string } | null =>
        planned.has(
            `${l.poster.title.trim().toLowerCase()}|${l.poster.start ?? ''}`,
        )
            ? { text: 'In your plan', tone: 'plan' }
            : current.no_german && l.occurrence.no_german
              ? { text: l.occurrence.no_german, tone: 'language' }
              : l.occurrence.language === 'en'
                ? { text: 'In English', tone: 'language' }
                : null;

    // Soonest first: the day's hours as the Wall had them, what's on now first and what's over
    // folded away. Another order is one list.
    const grouped = current.sort === 'soonest';
    const earlier = grouped ? list.filter((l) => stateOf(l) === 'over') : [];
    const groups: { label: string; now: boolean; items: Listing[] }[] = [];

    for (const l of list) {
        if (grouped && stateOf(l) === 'over') {
            continue;
        }

        // The grid is one run of posters (each says its time), so no hour leaves a row half empty.
        const label =
            !grouped || view === 'grid'
                ? ''
                : stateOf(l) === 'now'
                  ? 'On now'
                  : (l.poster.start ?? 'Time not listed');
        const group = groups.find((g) => g.label === label);

        if (group) {
            group.items.push(l);
        } else {
            groups.push({ label, now: label === 'On now', items: [l] });
        }
    }

    const showDetail = (d: Detail | null) => {
        const next = withDetail(whatsOnUrl(date, current), d);

        // Another day's details read that day's plan for Get there; today's is Today's own.
        if (d && date !== today) {
            router.get(
                next,
                {},
                {
                    only: ['whatsOn'],
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );

            return;
        }

        router.replace({
            url: next,
            preserveState: true,
            preserveScroll: true,
        });
    };
    const opened = detail
        ? (day.find(
              (l) =>
                  l.occurrence.id === detail.event &&
                  Date.parse(l.occurrence.occurrence_start) ===
                      Date.parse(detail.start),
          ) ?? null)
        : null;

    // Closing leaves Today as it was, at its own address.
    const close = () => {
        setOpen(false);
        window.setTimeout(
            () =>
                router.replace({
                    url: '/today',
                    props: (props) => ({ ...props, whatsOn: null }),
                    preserveState: true,
                    preserveScroll: true,
                }),
            220,
        );
    };

    const row = (l: Listing, position: number) => {
        const p = l.poster;
        const why = whyOf(l);
        const isSaved = saved.keys.has(l.key);
        const when = p.start
            ? p.end
                ? `${p.start}–${p.end}`
                : `${p.start} · end not listed`
            : 'Time not listed';
        const where = [p.venue, p.area].filter(Boolean).join(' · ');
        const open = () =>
            showDetail({
                event: l.occurrence.id,
                start: l.occurrence.occurrence_start,
            });

        return (
            <li
                key={l.key}
                ref={shown(l.occurrence.id, position)}
                className="wo-row"
            >
                <button
                    type="button"
                    className={cn('wo-thumb', `pp--${posterInk(p.start)}`)}
                    aria-label={`${p.title}, ${when}. Open details`}
                    onClick={open}
                >
                    <span>{p.start ?? '—'}</span>
                </button>
                <div className="wo-copy">
                    <button type="button" className="wo-title" onClick={open}>
                        <span lang={p.titleLang ?? undefined}>{p.title}</span>
                    </button>
                    {where && <span className="wo-where">{where}</span>}
                    <span className="wo-when">
                        {when}
                        {p.price ? ` · ${p.price}` : ''}
                        {l.km !== null ? ` · ${distanceLabel(l.km)}` : ''}
                        {why && (
                            <span className={cn('wo-why', `is-${why.tone}`)}>
                                {why.text}
                            </span>
                        )}
                    </span>
                </div>
                <button
                    type="button"
                    className={cn('wo-save', isSaved && 'is-saved')}
                    aria-label={
                        isSaved ? `Saved: ${p.title}` : `Save ${p.title}`
                    }
                    aria-pressed={isSaved}
                    onClick={() => toggleSave(l)}
                >
                    <Icon
                        name="bookmark"
                        variant={isSaved ? 'default' : 'line'}
                    />
                </button>
            </li>
        );
    };

    // The grid's tile: the listing as a small poster, in its own ink.
    const tile = (l: Listing, position: number) => {
        const p = l.poster;
        const why = whyOf(l);
        const isSaved = saved.keys.has(l.key);
        const when = p.start
            ? p.end
                ? `${p.start}–${p.end}`
                : `${p.start} · end not listed`
            : 'Time not listed';
        const ink = posterInk(p.start);

        return (
            <li
                key={l.key}
                ref={shown(l.occurrence.id, position)}
                className="wo-tile"
            >
                <button
                    type="button"
                    className={cn('wo-tile-poster', `pp--${ink}`)}
                    aria-label={`${p.title}, ${when}. Open details`}
                    onClick={() =>
                        showDetail({
                            event: l.occurrence.id,
                            start: l.occurrence.occurrence_start,
                        })
                    }
                >
                    <span
                        className={cn(
                            'wo-tile-stamp',
                            stateOf(l) === 'now' && 'is-now',
                        )}
                    >
                        {stateOf(l) === 'now'
                            ? 'On now'
                            : date === today
                              ? ink === 'day'
                                  ? 'Today'
                                  : 'Tonight'
                              : longDate(date)}
                    </span>
                    <span
                        className="wo-tile-title"
                        lang={p.titleLang ?? undefined}
                    >
                        {p.title}
                    </span>
                    <span className="wo-tile-time">{p.start ?? '—'}</span>
                </button>
                <div className="wo-tile-foot">
                    <div>
                        {p.venue && <span className="wo-where">{p.venue}</span>}
                        <span className="wo-when">
                            {[p.end ? `until ${p.end}` : null, p.price]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                        {why && (
                            <span className={cn('wo-why', `is-${why.tone}`)}>
                                {why.text}
                            </span>
                        )}
                    </div>
                    <button
                        type="button"
                        className={cn('wo-save', isSaved && 'is-saved')}
                        aria-label={
                            isSaved ? `Saved: ${p.title}` : `Save ${p.title}`
                        }
                        aria-pressed={isSaved}
                        onClick={() => toggleSave(l)}
                    >
                        <Icon
                            name="bookmark"
                            variant={isSaved ? 'default' : 'line'}
                        />
                    </button>
                </div>
            </li>
        );
    };

    let position = 0;

    return (
        <>
            <Sheet
                open={open}
                side
                onClose={close}
                className="whats-on"
                title="What’s on"
                description={`Cologne · ${
                    list.length === 1
                        ? '1 event'
                        : `${list.length || 'No'} events`
                } ${date === today ? 'today' : `on ${longDate(date)}`}`}
                footer={
                    <button
                        type="button"
                        className="wo-composer"
                        onClick={() => {
                            close();
                            openComposerTray();
                        }}
                    >
                        <Icon name="spark" />
                        <span className="wo-long">
                            Looking for something else?
                        </span>
                        <span className="wo-short">Something else?</span> Ask
                        Composer
                    </button>
                }
            >
                <div className="wo-filters">
                    <EventsFilterBar
                        date={date}
                        filters={current}
                        onFilters={onFilters}
                        onDate={onDate}
                        categories={categoryChoices(
                            data.catalogue.categories,
                            current.category,
                        )}
                        areas={areaChoices(data.catalogue.areas, current.area)}
                        from={shownFrom}
                        savedPlaces={data.saved_places.map(asStartingPoint)}
                        mode={mode}
                        onFrom={onFrom}
                        onMode={onMode}
                        trailing={
                            <div
                                className="wo-views"
                                role="group"
                                aria-label="Show as"
                            >
                                <button
                                    type="button"
                                    aria-label="List"
                                    aria-pressed={view === 'list'}
                                    onClick={() => setView('list')}
                                >
                                    <Icon name="list" variant="line" />
                                </button>
                                <button
                                    type="button"
                                    aria-label="Grid"
                                    aria-pressed={view === 'grid'}
                                    onClick={() => setView('grid')}
                                >
                                    <Icon name="grid" variant="line" />
                                </button>
                            </div>
                        }
                    />
                    <FromNote from={shownFrom} />
                </div>
                {date < today && list.length ? (
                    <PastNote
                        date={date}
                        nextListed={data.next_listed}
                        onDate={onDate}
                    />
                ) : null}
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
                                current.saved ||
                                current.no_german
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
                                          no_german: false,
                                      },
                            )
                        }
                    />
                ) : (
                    <div className="wo-list">
                        {earlier.length > 0 && (
                            <section className="wo-earlier">
                                <button
                                    type="button"
                                    aria-expanded={earlierOpen}
                                    onClick={() => setEarlierOpen((o) => !o)}
                                >
                                    Earlier today ·{' '}
                                    {earlier.length === 1
                                        ? '1 over'
                                        : `${earlier.length} over`}
                                    <Icon
                                        name={earlierOpen ? 'up' : 'down'}
                                        variant="line"
                                    />
                                </button>
                                {earlierOpen && (
                                    <ul>
                                        {earlier.map((l) => row(l, ++position))}
                                    </ul>
                                )}
                            </section>
                        )}
                        {groups.map((g) => (
                            <section
                                key={g.label || 'all'}
                                className="wo-group"
                            >
                                {g.label && (
                                    <h3
                                        className={cn(
                                            'wo-at',
                                            g.now && 'is-now',
                                        )}
                                    >
                                        {g.label}
                                    </h3>
                                )}
                                <ul
                                    className={cn(view === 'grid' && 'wo-grid')}
                                >
                                    {g.items.map((l) =>
                                        view === 'grid'
                                            ? tile(l, ++position)
                                            : row(l, ++position),
                                    )}
                                </ul>
                            </section>
                        ))}
                        <HowWeRankLink />
                    </div>
                )}
            </Sheet>
            <EventDetails
                listing={open ? opened : null}
                date={date}
                today={today}
                from={
                    shownFrom?.point
                        ? { name: shownFrom.name, ...shownFrom.point }
                        : null
                }
                appointment={data.appointment}
                mode={mode}
                day={date === today ? todayPlan : data.day}
                saved={opened ? saved.keys.has(opened.key) : false}
                onSave={() => opened && toggleSave(opened)}
                onClose={close}
                onBack={() => showDetail(null)}
                starts={[...data.saved_places, ...data.favourite_stops].map(
                    ({ name, lat, lng }) => ({ name, lat, lng }),
                )}
            />
        </>
    );
}

/** List or Grid, as this device last had it (a convenience: it falls back to List). */
function useView(): ['list' | 'grid', (view: 'list' | 'grid') => void] {
    const [view, setView] = useState<'list' | 'grid'>(() => {
        try {
            return window.localStorage.getItem('expadu-whats-on-view') ===
                'grid'
                ? 'grid'
                : 'list';
        } catch {
            return 'list';
        }
    });

    return [
        view,
        (next) => {
            setView(next);

            try {
                window.localStorage.setItem('expadu-whats-on-view', next);
            } catch {
                // Private windows may refuse; the choice holds until the panel closes.
            }
        },
    ];
}

/** The minute of the day in Cologne, moving on each minute. */
function useNowMinutes(): number {
    const read = () => {
        const [h, m] = new Date()
            .toLocaleTimeString('en-GB', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
                timeZone: 'Europe/Berlin',
            })
            .split(':')
            .map(Number);

        return h * 60 + m;
    };
    const [now, setNow] = useState(read);

    useEffect(() => {
        const timer = window.setInterval(() => setNow(read()), 60_000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}
