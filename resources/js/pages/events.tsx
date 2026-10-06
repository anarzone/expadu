import {
    Deferred,
    Head,
    Link,
    router,
    usePage,
    useRemember,
} from '@inertiajs/react';
import {
    IconBike,
    IconBookmark,
    IconBus,
    IconCalendarEvent,
    IconCheck,
    IconChevronDown,
    IconCurrentLocation,
    IconFilter,
    IconMapPin,
    IconSearch,
    IconSparkles,
    IconTicket,
    IconWalk,
    IconX,
} from '@tabler/icons-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { index as eventsIndex } from '@/actions/App/Http/Controllers/Api/EventsController';
import LocationConfirmController from '@/actions/App/Http/Controllers/Api/LocationConfirmController';
import { event as selectedEvent } from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { api } from '@/components/composer/types';
import { EventDiscoveryCard } from '@/components/events/event-discovery-card';
import { EventFeatured } from '@/components/events/event-featured';
import {
    canPlanEvent,
    eventDateLabel,
    eventSelectionQuery,
    occurrenceKey,
} from '@/components/events/event-presentation';
import { EventRichDetail } from '@/components/events/event-rich-detail';
import { RemindMeButton } from '@/components/events/remind-me-button';
import { SavedEventRow } from '@/components/events/saved-events';
import type {
    EventOccurrence,
    EventReminderEntry,
} from '@/components/events/types';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { PlaceDetailModal } from '@/components/places/place-detail-modal';
import type { Place } from '@/components/places/types';
import { MAX_LOCATION_ACCURACY_M } from '@/hooks/use-geolocation';
import { useIsMobile } from '@/hooks/use-mobile';
import { useSavedEvents } from '@/hooks/use-saved-events';
import AppLayout from '@/layouts/app-layout';
import { composer } from '@/routes';

type EventMode = 'walk' | 'transit' | 'bike';
type Filters = {
    window: string;
    date: string | null;
    category: string | null;
    veedel: string | null;
    free: boolean;
    venue: string | null;
    sort: 'soonest' | 'nearest' | 'recommended';
    mode: EventMode;
};
type EventsResponse = {
    data: EventOccurrence[];
    origin?: { source: string; label: string | null };
    needs_location?: boolean;
};
const WINDOWS = [
    { id: 'today', label: 'Today' },
    { id: 'tomorrow', label: 'Tomorrow' },
    { id: 'weekend', label: 'Weekend' },
    { id: 'week', label: 'Next 7 days' },
];
const CATEGORIES = [
    { id: 'language_exchange', label: 'Language exchange' },
    { id: 'stammtisch', label: 'Stammtisch' },
    { id: 'intl_meetup', label: 'International' },
    { id: 'sports', label: 'Sports' },
    { id: 'culture', label: 'Culture' },
    { id: 'party', label: 'Party' },
];
const MODES = [
    { id: 'walk' as const, label: 'Walk', Icon: IconWalk },
    { id: 'transit' as const, label: 'Transit', Icon: IconBus },
    { id: 'bike' as const, label: 'Bike', Icon: IconBike },
];
const controlClass =
    'flex min-h-11 items-center gap-2 rounded-xl border border-border bg-card px-3 text-[13px] font-medium transition-colors hover:border-cyan focus-visible:outline-2 focus-visible:outline-cyan motion-reduce:transition-none';

export default function Events() {
    const page = usePage<{
        filters: Filters;
        veedelOptions?: string[];
        savedOnly?: boolean;
    }>();
    const { filters: initial, veedelOptions, auth } = page.props;
    const [filters, setFilters] = useRemember(initial, 'event-filters');
    const [search, setSearch] = useRemember('', 'event-search');
    const [savedOnly, setSavedOnly] = useRemember(
        page.props.savedOnly ?? false,
        'event-saved-only',
    );
    const [expanded, setExpanded] = useState<'filters' | 'origin' | null>(null);
    const [occurrences, setOccurrences] = useState<EventOccurrence[]>([]);
    const [status, setStatus] = useState<'loading' | 'ok' | 'error'>('loading');
    const [featured, setFeatured] = useState<EventOccurrence[]>([]);
    const [featureStatus, setFeatureStatus] = useState<
        'loading' | 'ok' | 'error'
    >('loading');
    const [origin, setOrigin] = useState<EventsResponse['origin']>();
    const [needsLocation, setNeedsLocation] = useState(false);
    const [locationStatus, setLocationStatus] = useState<
        'idle' | 'locating' | 'error'
    >('idle');
    const [originVersion, setOriginVersion] = useState(0);
    const bookmarks = useSavedEvents(auth.user.id);
    const saved = bookmarks.keys;
    const notice = bookmarks.notice;
    const setNotice = bookmarks.setNotice;
    const [reminders, setReminders] = useState<Set<string>>(new Set());
    const [detail, setDetail] = useState<EventOccurrence | null>(null);
    const [selection, setSelection] = useState<EventOccurrence | null>(null);
    const [placeDetail, setPlaceDetail] = useState<Place | null>(null);
    const [placeError, setPlaceError] = useState('');
    const [destination, setDestination] = useState<Destination | null>(null);
    const request = useRef(0);
    const detailRequest = useRef<AbortController | null>(null);
    const dismissedEventLink = useRef<string | null>(null);
    const cancelDetailRequest = useCallback(() => {
        detailRequest.current?.abort();
        detailRequest.current = null;
    }, []);
    const isMobile = useIsMobile();

    const fetchEvents = useCallback(() => {
        const current = ++request.current;
        const controller = new AbortController();
        setStatus('loading');
        fetch(
            eventsIndex.url({
                query: {
                    ...filters,
                    date: filters.date ?? undefined,
                    free: filters.free ? '1' : undefined,
                    category: filters.category ?? undefined,
                    veedel: filters.veedel ?? undefined,
                    venue: filters.venue ?? undefined,
                },
            }),
            {
                credentials: 'same-origin',
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            },
        )
            .then((response) =>
                response.ok
                    ? (response.json() as Promise<EventsResponse>)
                    : Promise.reject(new Error()),
            )
            .then((json) => {
                if (current !== request.current || controller.signal.aborted) {
                    return;
                }

                setOccurrences(json.data ?? []);
                setOrigin(json.origin);
                setNeedsLocation(json.needs_location === true);
                setStatus('ok');
            })
            .catch(() => {
                if (current === request.current && !controller.signal.aborted) {
                    setStatus('error');
                }
            });

        return () => controller.abort();
    }, [filters]);

    const fetchFeatured = useCallback(() => {
        const controller = new AbortController();
        setFeatureStatus('loading');
        fetch(
            eventsIndex.url({ query: { window: 'week', sort: 'recommended' } }),
            {
                credentials: 'same-origin',
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            },
        )
            .then((response) =>
                response.ok
                    ? (response.json() as Promise<EventsResponse>)
                    : Promise.reject(new Error()),
            )
            .then((json) => {
                if (controller.signal.aborted) {
                    return;
                }

                setFeatured(
                    (json.data ?? [])
                        .filter((item) => canPlanEvent(item))
                        .slice(0, 3),
                );
                setFeatureStatus('ok');
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setFeatureStatus('error');
                }
            });

        return () => controller.abort();
    }, []);

    useEffect(() => {
        return fetchFeatured();
    }, [fetchFeatured]);
    useEffect(() => {
        return fetchEvents();
    }, [fetchEvents, originVersion]);
    useEffect(() => {
        const url = new URL(page.url, 'https://expadu.local');
        const linkedKey = `${url.searchParams.get('event')}|${url.searchParams.get('start')}`;

        // Deferred page responses may carry the original URL after a close.
        // Keep the user's dismissal authoritative for this page instance.
        if (linkedKey === dismissedEventLink.current) {
            url.searchParams.delete('event');
            url.searchParams.delete('start');
        }

        for (const [key, value] of Object.entries(filters)) {
            if (
                value == null ||
                value === false ||
                (key === 'window' && value === 'today') ||
                (key === 'mode' && value === 'walk') ||
                (key === 'sort' && value === 'soonest')
            ) {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(key, value === true ? '1' : value);
            }
        }

        if (savedOnly) {
            url.searchParams.set('saved', '1');
        } else {
            url.searchParams.delete('saved');
        }

        const nextUrl = `${url.pathname}${url.search}${url.hash}`;

        if (nextUrl !== page.url) {
            router.replace({
                url: nextUrl,
                preserveState: true,
                preserveScroll: true,
            });
        }
    }, [filters, savedOnly, page.url]);
    useEffect(() => {
        function onPop() {
            if (window.location.pathname !== '/events') {
                return;
            }

            const params = new URLSearchParams(window.location.search);
            const window_ = params.get('window');
            const sort = params.get('sort');
            const mode = params.get('mode');
            setSavedOnly(params.get('saved') === '1');
            setFilters({
                date: params.get('date'),
                window: WINDOWS.some((item) => item.id === window_)
                    ? window_!
                    : 'today',
                category: params.get('category'),
                veedel: params.get('veedel'),
                free: params.get('free') === '1',
                venue: params.get('venue'),
                sort:
                    sort === 'nearest' || sort === 'recommended'
                        ? sort
                        : 'soonest',
                mode: mode === 'bike' || mode === 'transit' ? mode : 'walk',
            });
        }
        window.addEventListener('popstate', onPop);

        return () => window.removeEventListener('popstate', onPop);
    }, [setFilters, setSavedOnly]);
    useEffect(() => {
        let active = true;
        fetch('/api/reminders', { credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error())))
            .then((json) => {
                if (active) {
                    setReminders(
                        new Set(
                            (json.data ?? []).map((item: EventReminderEntry) =>
                                occurrenceKey(item),
                            ),
                        ),
                    );
                }
            })
            .catch(() => {});

        return () => {
            active = false;
        };
    }, []);
    const detailParams = new URL(page.url, 'https://expadu.local').searchParams;
    const linkedEvent = detailParams.get('event');
    const linkedStart = detailParams.get('start');
    useEffect(() => {
        cancelDetailRequest();
        const id = linkedEvent;
        const start = linkedStart;

        if (!id || !start || `${id}|${start}` === dismissedEventLink.current) {
            return;
        }

        const controller = new AbortController();
        detailRequest.current = controller;
        api<EventOccurrence>(
            selectedEvent.url({ query: { id, start } }),
            undefined,
            controller.signal,
        )
            .then((item) => {
                if (
                    !controller.signal.aborted &&
                    detailRequest.current === controller
                ) {
                    setDetail(item);
                }
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setNotice(
                        'This event occurrence is no longer available. Your other saved events are unchanged.',
                    );
                }
            });

        return () => controller.abort();
    }, [linkedEvent, linkedStart, setNotice, cancelDetailRequest]);

    function changeFilter(next: Partial<Filters>) {
        setFilters((current) => ({
            ...current,
            ...next,
            ...(next.window ? { date: null } : {}),
        }));
    }
    function clearFilters() {
        changeFilter({
            category: null,
            veedel: null,
            free: false,
            venue: null,
        });
        setSearch('');
        setSavedOnly(false);
    }
    function saveEvent(item: EventOccurrence) {
        if (bookmarks.loading || bookmarks.error) {
            setNotice('Load your saved events before changing them.');
            void bookmarks.refresh();

            return;
        }

        void bookmarks.setSaved(item, !saved.has(occurrenceKey(item)));
    }
    function clearEventLink() {
        const url = new URL(page.url, 'https://expadu.local');

        if (!url.searchParams.has('event') && !url.searchParams.has('start')) {
            return;
        }

        dismissedEventLink.current = `${url.searchParams.get('event')}|${url.searchParams.get('start')}`;
        url.searchParams.delete('event');
        url.searchParams.delete('start');
        router.replace({
            url: `${url.pathname}${url.search}${url.hash}`,
            preserveState: true,
            preserveScroll: true,
        });
    }
    function closeDetail() {
        cancelDetailRequest();
        setDetail(null);
        clearEventLink();
    }
    function planEvent(item: EventOccurrence) {
        if (!canPlanEvent(item)) {
            return;
        }

        cancelDetailRequest();
        clearEventLink();
        setSelection(item);
        setDetail(item);
        setPlaceError('');
    }
    function openOccurrence(item: EventOccurrence) {
        cancelDetailRequest();
        clearEventLink();
        setDetail(item);
        setPlaceError('');
    }
    function takeMeThere(item: EventOccurrence) {
        if (item.venue.lat == null || item.venue.lng == null) {
            return;
        }

        setDestination({
            name: item.venue.name ?? item.title,
            backLabel: item.title,
            emoji: item.emoji,
            lat: item.venue.lat,
            lng: item.venue.lng,
            arriveBy: item.occurrence_start,
        });
    }
    function openPlace(id: number) {
        setPlaceError('');
        fetch(`/api/places/${id}`, { credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error())))
            .then((json) => {
                setDetail(null);
                setPlaceDetail(json.data);
            })
            .catch(() =>
                setPlaceError(
                    'The venue details could not be loaded. Try the venue link again.',
                ),
            );
    }
    function locateMe() {
        if (!navigator.geolocation) {
            setLocationStatus('error');

            return;
        }

        setLocationStatus('locating');
        navigator.geolocation.getCurrentPosition(
            async (position) => {
                if (position.coords.accuracy > MAX_LOCATION_ACCURACY_M) {
                    setLocationStatus('error');

                    return;
                }

                try {
                    const response = await fetch(
                        LocationConfirmController.url(),
                        {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN':
                                    document
                                        .querySelector(
                                            'meta[name="csrf-token"]',
                                        )
                                        ?.getAttribute('content') ?? '',
                            },
                            body: JSON.stringify({
                                lat: position.coords.latitude,
                                lng: position.coords.longitude,
                            }),
                        },
                    );

                    if (!response.ok) {
                        throw new Error();
                    }

                    setLocationStatus('idle');
                    setOriginVersion((value) => value + 1);
                } catch {
                    setLocationStatus('error');
                }
            },
            () => setLocationStatus('error'),
            { enableHighAccuracy: true, timeout: 10_000, maximumAge: 30_000 },
        );
    }

    const activeFilters =
        Number(!!filters.category) +
        Number(!!filters.veedel) +
        Number(filters.free) +
        Number(!!filters.venue);
    const savedOccurrences = bookmarks.entries.flatMap((entry) =>
        entry.event ? [entry.event] : [],
    );
    const unavailableSaved = savedOnly
        ? bookmarks.entries.filter(
              (entry) =>
                  entry.event === null &&
                  (!search.trim() ||
                      entry.title
                          .toLocaleLowerCase()
                          .includes(search.trim().toLocaleLowerCase())),
          )
        : [];
    const matching = (savedOnly ? savedOccurrences : occurrences).filter(
        (item) =>
            (!savedOnly ||
                ((!filters.category || item.category === filters.category) &&
                    (!filters.veedel || item.venue.veedel === filters.veedel) &&
                    (!filters.venue ||
                        String(item.venue_id) === filters.venue) &&
                    (!filters.free ||
                        item.chips.includes('free') ||
                        item.price_text?.toLowerCase() === 'free'))) &&
            (!search.trim() ||
                [
                    item.title,
                    item.venue.name,
                    item.venue.veedel,
                    item.category_label,
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLocaleLowerCase()
                    .includes(search.trim().toLocaleLowerCase())),
    );
    const windowLabel = savedOnly
        ? 'All saved dates'
        : filters.date
          ? eventDateLabel(`${filters.date}T12:00:00+01:00`)
          : (WINDOWS.find((item) => item.id === filters.window)?.label ??
            'Today');
    const chosenMode =
        MODES.find((item) => item.id === filters.mode) ?? MODES[0];
    const ModeIcon = chosenMode.Icon;
    const hasFilters = activeFilters > 0 || !!search.trim() || savedOnly;
    const nextWindow =
        filters.window === 'today'
            ? 'tomorrow'
            : filters.window === 'tomorrow'
              ? 'weekend'
              : 'week';

    return (
        <AppLayout fullWidth rightPanel={null}>
            <Head title="Events" />
            <div className="mx-auto h-full w-full max-w-[1360px] overflow-y-auto px-4 pt-4 pb-24 md:px-8 md:pt-6">
                <header className="mb-5 flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-[28px] leading-tight font-semibold tracking-[-1px] md:text-[34px]">
                            Good things to turn up for
                        </h1>
                        <p className="mt-1.5 text-[13px] text-text-2">
                            Find an event. Make it part of your day.
                        </p>
                    </div>
                    <span className="hidden size-11 shrink-0 items-center justify-center rounded-2xl bg-cyan-soft text-cyan-h sm:flex">
                        <IconCalendarEvent size={23} />
                    </span>
                </header>
                {featureStatus === 'loading' && (
                    <div
                        className="mb-6 h-64 animate-pulse rounded-[22px] bg-secondary motion-reduce:animate-none"
                        aria-label="Loading upcoming events"
                    />
                )}
                {featured.length > 0 && (
                    <EventFeatured
                        items={featured}
                        saved={saved}
                        onOpen={openOccurrence}
                        onPlan={planEvent}
                        onSave={saveEvent}
                    />
                )}
                {featureStatus === 'error' && (
                    <div className="mb-4 flex items-center justify-between gap-3 text-sm text-text-2">
                        <span>Upcoming highlights could not load.</span>
                        <button
                            className="min-h-11 font-semibold text-primary"
                            onClick={fetchFeatured}
                        >
                            Try again
                        </button>
                    </div>
                )}

                {selection && (
                    <div
                        className="mb-5 flex flex-wrap items-center gap-3 rounded-2xl border border-cyan-bd bg-cyan-soft p-3"
                        role="status"
                    >
                        <IconSparkles
                            className="shrink-0 text-cyan-h"
                            size={20}
                        />
                        <div className="min-w-0 flex-1">
                            <strong className="block text-sm">
                                Selected to review: {selection.title}
                            </strong>
                            <span className="text-xs text-text-2">
                                {eventDateLabel(selection.occurrence_start)}.
                                Not added to your saved plan yet.
                            </span>
                        </div>
                        <Link
                            href={composer({
                                query: eventSelectionQuery(selection),
                            })}
                            className="inline-flex min-h-11 items-center rounded-xl bg-primary px-4 text-sm font-semibold text-white"
                        >
                            Review plan
                        </Link>
                        <button
                            type="button"
                            onClick={() => setSelection(null)}
                            aria-label="Undo event selection"
                            className="flex size-11 items-center justify-center rounded-xl hover:bg-card"
                        >
                            <IconX size={18} />
                        </button>
                    </div>
                )}
                {notice && (
                    <div
                        role="status"
                        className="mb-3 flex items-center justify-between gap-3 rounded-xl bg-secondary px-3 text-xs text-text-2"
                    >
                        <span>{notice}</span>
                        <button
                            className="flex size-11 shrink-0 items-center justify-center"
                            onClick={() => setNotice('')}
                            aria-label="Dismiss save message"
                        >
                            <IconX size={16} />
                        </button>
                    </div>
                )}

                <section aria-label="Find events">
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold tracking-[-.35px]">
                            Find your next plan
                        </h2>
                        <span className="text-xs text-text-2">
                            {windowLabel}
                        </span>
                    </div>
                    <div
                        className="mb-3 flex gap-1 overflow-x-auto rounded-2xl bg-secondary p-1"
                        aria-label="Event dates"
                    >
                        {WINDOWS.map(({ id, label }) => (
                            <button
                                key={id}
                                type="button"
                                onClick={() => changeFilter({ window: id })}
                                aria-pressed={
                                    !filters.date && filters.window === id
                                }
                                className={`min-h-11 flex-1 shrink-0 rounded-xl px-3 text-[13px] whitespace-nowrap transition-colors motion-reduce:transition-none ${!filters.date && filters.window === id ? 'bg-card font-semibold text-cyan-h shadow-sm' : 'font-medium text-text-2 hover:bg-card/60'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <div className="mb-3 flex flex-wrap items-center gap-2">
                        <label className={controlClass}>
                            <IconCalendarEvent size={17} />
                            <span className="sr-only">Choose event date</span>
                            <input
                                type="date"
                                aria-label="Choose event date"
                                value={filters.date ?? ''}
                                onChange={(e) => {
                                    setSavedOnly(false);
                                    changeFilter({
                                        date: e.target.value || null,
                                    });
                                }}
                                className="min-h-11 min-w-0 bg-transparent text-sm focus:outline-none"
                            />
                        </label>
                        {bookmarks.legacyCount > 0 && (
                            <button
                                className="min-h-11 text-sm text-primary"
                                disabled={bookmarks.importing}
                                onClick={() => void bookmarks.importLegacy()}
                            >
                                {bookmarks.importing
                                    ? 'Moving saves…'
                                    : `Move ${bookmarks.legacyCount} device saves to your account`}
                            </button>
                        )}
                    </div>
                    <div className="mb-3 flex flex-wrap gap-2">
                        <label className="relative flex min-w-[160px] flex-1 items-center">
                            <IconSearch
                                size={18}
                                className="pointer-events-none absolute left-3 text-text-3"
                            />
                            <input
                                type="search"
                                aria-label="Search events"
                                placeholder="An event, place or interest"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                className="min-h-11 w-full rounded-xl border border-border bg-card pr-3 pl-10 text-sm focus:border-cyan focus:outline-none"
                            />
                        </label>
                        <button
                            type="button"
                            aria-expanded={expanded === 'filters'}
                            aria-controls="event-filter-options"
                            onClick={() =>
                                setExpanded(
                                    expanded === 'filters' ? null : 'filters',
                                )
                            }
                            className={controlClass}
                        >
                            <IconFilter size={17} />
                            Filters
                            {activeFilters > 0 && (
                                <span className="flex size-5 items-center justify-center rounded-full bg-cyan-soft text-[10px] text-cyan-h">
                                    {activeFilters}
                                </span>
                            )}
                            <IconChevronDown size={14} />
                        </button>
                        <button
                            type="button"
                            aria-pressed={savedOnly}
                            onClick={() => setSavedOnly(!savedOnly)}
                            className={`${controlClass} ${savedOnly ? 'border-cyan-bd bg-cyan-soft text-cyan-h' : ''}`}
                            title="Saved to your account"
                        >
                            <IconBookmark size={17} />
                            <span>Saved</span>
                        </button>
                    </div>
                    {expanded === 'filters' && (
                        <div
                            id="event-filter-options"
                            className="mb-3 rounded-2xl border border-border bg-card p-3"
                        >
                            <div className="flex flex-wrap gap-2">
                                {CATEGORIES.map((category) => (
                                    <button
                                        type="button"
                                        key={category.id}
                                        aria-pressed={
                                            filters.category === category.id
                                        }
                                        onClick={() =>
                                            changeFilter({
                                                category:
                                                    filters.category ===
                                                    category.id
                                                        ? null
                                                        : category.id,
                                            })
                                        }
                                        className={`${controlClass} ${filters.category === category.id ? 'border-cyan-bd bg-cyan-soft text-cyan-h' : ''}`}
                                    >
                                        {filters.category === category.id && (
                                            <IconCheck size={15} />
                                        )}
                                        {category.label}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    aria-pressed={filters.free}
                                    onClick={() =>
                                        changeFilter({ free: !filters.free })
                                    }
                                    className={`${controlClass} ${filters.free ? 'border-cyan-bd bg-cyan-soft text-cyan-h' : ''}`}
                                >
                                    <IconTicket size={17} />
                                    Free only
                                </button>
                            </div>
                            <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-border pt-3">
                                <IconMapPin size={17} className="text-cyan-h" />
                                <label
                                    htmlFor="event-area"
                                    className="text-sm text-text-2"
                                >
                                    Events in
                                </label>
                                <Deferred
                                    data="veedelOptions"
                                    fallback={
                                        <span className="text-xs text-text-2">
                                            Loading areas…
                                        </span>
                                    }
                                >
                                    <select
                                        id="event-area"
                                        value={filters.veedel ?? ''}
                                        onChange={(event) =>
                                            changeFilter({
                                                veedel:
                                                    event.target.value || null,
                                            })
                                        }
                                        className="min-h-11 max-w-full min-w-0 rounded-xl bg-secondary px-3 text-sm"
                                    >
                                        <option value="">All areas</option>
                                        {(veedelOptions ?? []).map((area) => (
                                            <option key={area}>{area}</option>
                                        ))}
                                    </select>
                                </Deferred>
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="ml-auto min-h-11 px-2 text-sm font-semibold text-primary"
                                >
                                    Reset
                                </button>
                            </div>
                            {filters.venue && (
                                <button
                                    type="button"
                                    className={`${controlClass} mt-2`}
                                    onClick={() =>
                                        changeFilter({ venue: null })
                                    }
                                >
                                    This venue
                                    <IconX size={14} />
                                    <span className="sr-only">
                                        Remove venue filter
                                    </span>
                                </button>
                            )}
                        </div>
                    )}
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <button
                            type="button"
                            className={`${controlClass} max-w-full min-w-0`}
                            onClick={() =>
                                setExpanded(
                                    expanded === 'origin' ? null : 'origin',
                                )
                            }
                            aria-expanded={expanded === 'origin'}
                            aria-controls="event-origin"
                        >
                            <ModeIcon
                                size={17}
                                className="shrink-0 text-cyan-h"
                            />
                            <span className="truncate">
                                From {origin?.label ?? 'your start'}
                            </span>
                            <IconChevronDown className="shrink-0" size={14} />
                        </button>
                        <label className="flex min-h-11 items-center gap-2 text-xs text-text-2">
                            <span>Sort</span>
                            <select
                                aria-label="Sort events"
                                value={filters.sort}
                                onChange={(event) =>
                                    changeFilter({
                                        sort: event.target
                                            .value as Filters['sort'],
                                    })
                                }
                                className="min-h-11 min-w-0 rounded-xl border border-border bg-card px-2 text-sm text-foreground"
                            >
                                <option value="soonest">Soonest</option>
                                <option value="nearest">Nearest</option>
                                <option value="recommended">Recommended</option>
                            </select>
                        </label>
                    </div>
                    {expanded === 'origin' && (
                        <div
                            id="event-origin"
                            className="mb-3 rounded-2xl border border-cyan-bd bg-cyan-soft p-3"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                {MODES.map(({ id, label, Icon }) => (
                                    <button
                                        type="button"
                                        key={id}
                                        aria-pressed={filters.mode === id}
                                        onClick={() =>
                                            changeFilter({ mode: id })
                                        }
                                        className={`${controlClass} ${filters.mode === id ? 'border-cyan bg-card text-cyan-h' : 'border-transparent bg-transparent'}`}
                                    >
                                        <Icon size={18} />
                                        {label}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    onClick={locateMe}
                                    disabled={locationStatus === 'locating'}
                                    className={`${controlClass} sm:ml-auto`}
                                >
                                    <IconCurrentLocation size={17} />
                                    {locationStatus === 'locating'
                                        ? 'Locating…'
                                        : 'Use my location'}
                                </button>
                            </div>
                            <p className="mt-2 text-xs leading-relaxed text-text-2">
                                {locationStatus === 'error'
                                    ? 'Your location could not be confirmed. You can still browse and choose a starting point in Directions.'
                                    : filters.mode === 'transit'
                                      ? 'Transit routes are checked in Directions.'
                                      : 'Travel estimates use this starting point. Check your full route before you go.'}
                            </p>
                        </div>
                    )}
                    {status === 'ok' &&
                        needsLocation &&
                        filters.sort === 'nearest' && (
                            <p className="mb-3 text-xs text-text-2">
                                Choose your starting point to sort by distance.
                                The events below keep their listed order.
                            </p>
                        )}
                    {savedOnly && (
                        <p className="mb-3 text-xs text-text-2">
                            Saved to your account · original event dates.
                        </p>
                    )}
                    {(savedOnly ? bookmarks.loading : status === 'loading') ? (
                        <div
                            className="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
                            aria-label="Loading events"
                        >
                            {[1, 2, 3].map((id) => (
                                <div
                                    key={id}
                                    className="h-64 animate-pulse rounded-[22px] bg-secondary motion-reduce:animate-none"
                                />
                            ))}
                        </div>
                    ) : (savedOnly ? !!bookmarks.error : status === 'error') ? (
                        <div className="rounded-2xl border border-border bg-card p-5">
                            <h3 className="font-semibold">
                                Events could not load
                            </h3>
                            <p className="mt-1 text-sm text-text-2">
                                Try again. Your saved choices are kept in your
                                account.
                            </p>
                            <button
                                type="button"
                                onClick={() =>
                                    savedOnly
                                        ? void bookmarks.refresh()
                                        : fetchEvents()
                                }
                                className="mt-3 min-h-11 rounded-xl bg-primary px-4 text-sm font-semibold text-white"
                            >
                                Try again
                            </button>
                        </div>
                    ) : matching.length === 0 &&
                      unavailableSaved.length === 0 ? (
                        <div className="rounded-2xl border border-border bg-card p-5">
                            <h3 className="font-semibold">
                                {hasFilters
                                    ? 'No events match these choices'
                                    : `No listings for ${windowLabel.toLowerCase()} yet`}
                            </h3>
                            <p className="mt-1 text-sm text-text-2">
                                {hasFilters
                                    ? 'Try another date or clear a filter.'
                                    : 'The programme only shows the events we have. Try another date.'}
                            </p>
                            <button
                                type="button"
                                onClick={() =>
                                    hasFilters
                                        ? clearFilters()
                                        : filters.window === 'week'
                                          ? fetchEvents()
                                          : changeFilter({ window: nextWindow })
                                }
                                className="mt-3 min-h-11 rounded-xl border border-border bg-card px-4 text-sm font-semibold text-primary"
                            >
                                {hasFilters
                                    ? 'Clear filters'
                                    : filters.window === 'week'
                                      ? 'Try again'
                                      : `See ${WINDOWS.find((item) => item.id === nextWindow)?.label.toLowerCase()}`}
                            </button>
                        </div>
                    ) : (
                        <>
                            <p
                                className="mb-3 text-xs text-text-2"
                                role="status"
                            >
                                {matching.length + unavailableSaved.length}{' '}
                                {matching.length + unavailableSaved.length === 1
                                    ? 'event'
                                    : 'events'}{' '}
                                · {windowLabel}
                            </p>
                            <div className="grid items-start gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {matching.map((item) => (
                                    <EventDiscoveryCard
                                        key={occurrenceKey(item)}
                                        occurrence={item}
                                        saved={saved.has(occurrenceKey(item))}
                                        onOpen={() => openOccurrence(item)}
                                        onPlan={() => planEvent(item)}
                                        onSave={() => saveEvent(item)}
                                    />
                                ))}
                            </div>
                            {unavailableSaved.length > 0 && (
                                <section
                                    aria-label="Unavailable saved events"
                                    className="mt-3 rounded-2xl border border-border bg-card p-4"
                                >
                                    <h3 className="font-semibold">
                                        No longer available
                                    </h3>
                                    <p className="mt-1 text-xs text-text-2">
                                        These saves keep their original date.
                                        You can remove them here.
                                    </p>
                                    <div className="divide-y divide-border">
                                        {unavailableSaved.map((entry) => (
                                            <SavedEventRow
                                                key={occurrenceKey(entry)}
                                                entry={entry}
                                                onRemove={() =>
                                                    void bookmarks.setSaved(
                                                        entry,
                                                        false,
                                                    )
                                                }
                                            />
                                        ))}
                                    </div>
                                </section>
                            )}
                        </>
                    )}
                </section>
            </div>
            {detail && !destination && (
                <EventRichDetail
                    occurrence={detail}
                    isMobile={isMobile}
                    breadcrumb={windowLabel}
                    onClose={closeDetail}
                    onNavigate={() => takeMeThere(detail)}
                    onOpenPlace={openPlace}
                    onPlan={() => planEvent(detail)}
                    selected={
                        selection != null &&
                        occurrenceKey(selection) === occurrenceKey(detail)
                    }
                    onUndoSelection={() => setSelection(null)}
                    error={placeError}
                    reminderAction={
                        <RemindMeButton
                            eventId={detail.id}
                            occurrenceStart={detail.occurrence_start}
                            reminded={reminders.has(occurrenceKey(detail))}
                            onChange={(on) =>
                                setReminders((existing) => {
                                    const next = new Set(existing);

                                    if (on) {
                                        next.add(occurrenceKey(detail));
                                    } else {
                                        next.delete(occurrenceKey(detail));
                                    }

                                    return next;
                                })
                            }
                        />
                    }
                />
            )}
            {placeDetail && !destination && (
                <PlaceDetailModal
                    place={placeDetail}
                    isMobile={isMobile}
                    meta={[
                        placeDetail.fine_label,
                        placeDetail.park ?? placeDetail.veedel,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    onClose={() => setPlaceDetail(null)}
                    onNavigate={(target) =>
                        setDestination({
                            ...target,
                            backLabel: placeDetail.name,
                        })
                    }
                />
            )}
            {destination && (
                <TakeMeThereSheet
                    destination={destination}
                    onBack={() => setDestination(null)}
                    onClose={() => {
                        setDestination(null);
                        setDetail(null);
                        setPlaceDetail(null);
                    }}
                />
            )}
        </AppLayout>
    );
}
