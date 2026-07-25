import { Head, Deferred, usePage } from '@inertiajs/react';
import {
    IconBike,
    IconBolt,
    IconBus,
    IconCalendarEvent,
    IconCalendarWeek,
    IconCheck,
    IconChevronDown,
    IconFilter,
    IconMapPin,
    IconSortDescending,
    IconSunrise,
    IconTicket,
    IconWalk,
    IconX,
} from '@tabler/icons-react';
import type { Icon as TablerIcon } from '@tabler/icons-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { index as eventsIndex } from '@/actions/App/Http/Controllers/Api/EventsController';
import LocationConfirmController from '@/actions/App/Http/Controllers/Api/LocationConfirmController';
import { EventRichDetail } from '@/components/events/event-rich-detail';
import { RemindMeButton } from '@/components/events/remind-me-button';
import type {
    EventOccurrence,
    EventReminderEntry,
} from '@/components/events/types';
import { eventIllustrationKey } from '@/components/events/types';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { ContentCard } from '@/components/places/content-card';
import type { CardChip } from '@/components/places/content-card';
import { PlaceDetailModal } from '@/components/places/place-detail-modal';
import type { Place } from '@/components/places/types';
import { ICON_STROKE } from '@/constants/icons';
import { MAX_LOCATION_ACCURACY_M } from '@/hooks/use-geolocation';
import { useIsMobile } from '@/hooks/use-mobile';
import AppLayout from '@/layouts/app-layout';

const WINDOWS: Array<{ id: string; label: string; Icon: TablerIcon }> = [
    { id: 'today', label: 'Today', Icon: IconBolt },
    { id: 'tomorrow', label: 'Tomorrow', Icon: IconSunrise },
    { id: 'weekend', label: 'Weekend', Icon: IconCalendarEvent },
    { id: 'week', label: 'Next 7 days', Icon: IconCalendarWeek },
];

const CATEGORIES: Array<{ id: string; label: string; emoji: string }> = [
    { id: 'language_exchange', label: 'Language exchange', emoji: '🗣️' },
    { id: 'stammtisch', label: 'Stammtisch', emoji: '🍻' },
    { id: 'intl_meetup', label: 'International', emoji: '🌍' },
    { id: 'sports', label: 'Sports', emoji: '⚽' },
    { id: 'culture', label: 'Culture', emoji: '🎭' },
    { id: 'party', label: 'Party', emoji: '🎉' },
];

type EventMode = 'walk' | 'transit' | 'bike';

const EVENT_MODES: ReadonlyArray<{
    id: EventMode;
    label: string;
    Icon: TablerIcon;
}> = [
    { id: 'walk', label: 'Walk', Icon: IconWalk },
    { id: 'transit', label: 'Transit', Icon: IconBus },
    { id: 'bike', label: 'Bike', Icon: IconBike },
];

type EventSort = 'Soonest' | 'Nearest' | 'Recommended';

type EventsOrigin = {
    source: string;
    label: string | null;
};

function occurrenceKey(o: {
    event_id?: number;
    id?: number;
    occurrence_start: string;
}): string {
    return `${o.event_id ?? o.id}|${new Date(o.occurrence_start).getTime()}`;
}

function eventChips(occurrence: EventOccurrence): CardChip[] {
    const chips: CardChip[] = occurrence.chips.slice(0, 3).map((chip) => ({
        label: chip,
        tone: chip === 'free' ? ('price' as const) : ('feature' as const),
    }));

    // Paid events wear their price like places do
    if (
        occurrence.price_text &&
        occurrence.price_text.toLowerCase() !== 'free'
    ) {
        chips.push({ label: occurrence.price_text, tone: 'price' });
    }

    return chips.slice(0, 4);
}

function startsWithinFourHours(occurrence: EventOccurrence): boolean {
    const diff = new Date(occurrence.occurrence_start).getTime() - Date.now();

    return diff > 0 && diff <= 4 * 3600 * 1000;
}

function dateBadge(occurrence: EventOccurrence): {
    top: string;
    bottom: string;
} {
    const start = new Date(occurrence.occurrence_start);

    return {
        top: start.toLocaleDateString('en-GB', { weekday: 'short' }),
        bottom: String(start.getDate()),
    };
}

function placeMeta(place: Place): string {
    return [
        place.fine_label ?? 'Place',
        place.park ?? place.veedel,
        place.distance_min != null ? `${place.distance_min} min away` : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

function eventMeta(occurrence: EventOccurrence, mode: EventMode): string {
    const proximity =
        occurrence.travel_min != null
            ? `${occurrence.travel_min} min ${mode === 'walk' ? 'walk' : 'bike'}`
            : occurrence.distance_km != null
              ? `${occurrence.distance_km.toFixed(1)} km away`
              : null;

    return [occurrence.meta, proximity].filter(Boolean).join(' · ');
}

export default function Events() {
    const { filters, veedelOptions } = usePage<{
        filters: {
            window: string;
            category: string | null;
            veedel: string | null;
            free: boolean;
            venue: string | null;
            sort: 'soonest' | 'nearest' | 'recommended';
            mode: EventMode;
        };
        veedelOptions?: string[];
    }>().props;

    const [window_, setWindow] = useState(filters.window);
    const [category, setCategory] = useState<string | null>(filters.category);
    const [veedel, setVeedel] = useState<string | null>(filters.veedel);
    const [free, setFree] = useState<boolean>(filters.free);
    // Venue deep-link from a place's events strip — dismissible chip
    const [venueId, setVenueId] = useState<string | null>(filters.venue);

    // v4 controls row — From popover + Sort menu (the distance recompute behind
    // From, and Nearest/Popular sort, light up once event distances land).
    const [evMode, setEvMode] = useState<EventMode>(filters.mode);
    const [evSort, setEvSort] = useState<EventSort>(
        `${filters.sort[0].toUpperCase()}${filters.sort.slice(1)}` as EventSort,
    );
    const [fromOpen, setFromOpen] = useState(false);
    const [sortOpen, setSortOpen] = useState(false);
    const [filterOpen, setFilterOpen] = useState(false);

    const [occurrences, setOccurrences] = useState<EventOccurrence[]>([]);
    const [status, setStatus] = useState<'loading' | 'ok' | 'error'>('loading');
    const [origin, setOrigin] = useState<EventsOrigin | null>(null);
    const [needsLocation, setNeedsLocation] = useState(false);
    const [usingLiveOrigin, setUsingLiveOrigin] = useState(false);
    const [locationStatus, setLocationStatus] = useState<
        'idle' | 'locating' | 'error'
    >('idle');
    const [reminders, setReminders] = useState<Set<string>>(new Set());

    const [detail, setDetail] = useState<EventOccurrence | null>(null);
    const [placeDetail, setPlaceDetail] = useState<Place | null>(null);
    const [destination, setDestination] = useState<Destination | null>(null);

    const isMobile = useIsMobile();
    const reqRef = useRef(0);

    const fetchEvents = useCallback(
        (w: string, c: string | null, v: string | null, f: boolean) => {
            const token = ++reqRef.current;
            const params = new URLSearchParams({ window: w });
            params.set('sort', evSort.toLowerCase());
            params.set('mode', evMode);

            if (c) {
                params.set('category', c);
            }

            if (v) {
                params.set('veedel', v);
            }

            if (f) {
                params.set('free', '1');
            }

            if (venueId) {
                params.set('venue', venueId);
            }

            fetch(eventsIndex.url({ query: Object.fromEntries(params) }), {
                credentials: 'same-origin',
            })
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error())))
                .then((json) => {
                    if (token !== reqRef.current) {
                        return;
                    }

                    setOccurrences(json.data ?? []);
                    setOrigin(json.origin ?? null);
                    setNeedsLocation(json.needs_location === true);
                    setStatus('ok');
                })
                .catch(() => {
                    if (token === reqRef.current) {
                        setStatus('error');
                    }
                });
        },
        [venueId, evSort, evMode],
    );

    // Fetch on filter change + keep the URL shareable
    useEffect(() => {
        fetchEvents(window_, category, veedel, free);

        const params = new URLSearchParams();

        if (window_ !== 'today') {
            params.set('window', window_);
        }

        if (category) {
            params.set('category', category);
        }

        if (veedel) {
            params.set('veedel', veedel);
        }

        if (free) {
            params.set('free', '1');
        }

        if (venueId) {
            params.set('venue', venueId);
        }

        if (evSort !== 'Soonest') {
            params.set('sort', evSort.toLowerCase());
        }

        if (evMode !== 'walk') {
            params.set('mode', evMode);
        }

        const qs = params.toString();
        // Preserve history.state — Inertia v2 stores its page snapshot
        // there; replacing it with {} breaks back/forward app-wide.
        globalThis.history.replaceState(
            globalThis.history.state,
            '',
            `/events${qs ? `?${qs}` : ''}`,
        );
    }, [
        window_,
        category,
        veedel,
        free,
        venueId,
        evSort,
        evMode,
        usingLiveOrigin,
        fetchEvents,
    ]);

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

                const next = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                };

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
                            body: JSON.stringify(next),
                        },
                    );

                    if (!response.ok) {
                        throw new Error('Location confirmation failed');
                    }

                    setUsingLiveOrigin(true);
                    setLocationStatus('idle');
                    setNeedsLocation(false);
                } catch {
                    setLocationStatus('error');
                }
            },
            () => setLocationStatus('error'),
            { enableHighAccuracy: true, timeout: 10_000, maximumAge: 30_000 },
        );
    }

    // Back/forward re-reads the URL
    useEffect(() => {
        function onPop() {
            // Also fires when leaving the page — don't touch state then
            if (globalThis.location.pathname !== '/events') {
                return;
            }

            const sp = new URLSearchParams(globalThis.location.search);
            setWindow(sp.get('window') ?? 'today');
            setCategory(sp.get('category'));
            setVeedel(sp.get('veedel'));
            setFree(sp.get('free') === '1');
            setVenueId(sp.get('venue'));
            const sort = sp.get('sort');
            setEvSort(
                sort === 'nearest'
                    ? 'Nearest'
                    : sort === 'recommended'
                      ? 'Recommended'
                      : 'Soonest',
            );
            const mode = sp.get('mode');
            setEvMode(mode === 'bike' || mode === 'transit' ? mode : 'walk');
        }
        globalThis.addEventListener('popstate', onPop);

        return () => globalThis.removeEventListener('popstate', onPop);
    }, []);

    // The user's pending reminders → filled-bell state
    useEffect(() => {
        fetch('/api/reminders', { credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error())))
            .then((json) => {
                setReminders(
                    new Set(
                        (json.data ?? []).map((r: EventReminderEntry) =>
                            occurrenceKey(r),
                        ),
                    ),
                );
            })
            .catch(() => {});
    }, []);

    function openOccurrence(occurrence: EventOccurrence) {
        setDetail(occurrence);
    }

    function takeMeThere(occurrence: EventOccurrence) {
        if (occurrence.venue.lat == null || occurrence.venue.lng == null) {
            return;
        }

        setDestination({
            name: occurrence.venue.name ?? occurrence.title,
            backLabel: occurrence.title,
            emoji: occurrence.emoji,
            lat: occurrence.venue.lat,
            lng: occurrence.venue.lng,
            arriveBy: occurrence.occurrence_start,
        });
    }

    function openPlace(placeId: number) {
        fetch(`/api/places/${placeId}`, { credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error())))
            .then((json) => {
                setDetail(null);
                setPlaceDetail(json.data);
            })
            .catch(() => {});
    }

    function setReminded(occurrence: EventOccurrence, reminded: boolean) {
        setReminders((prev) => {
            const next = new Set(prev);
            const key = occurrenceKey(occurrence);

            if (reminded) {
                next.add(key);
            } else {
                next.delete(key);
            }

            return next;
        });
    }

    const windowLabel = WINDOWS.find((w) => w.id === window_)?.label ?? 'Today';
    const railOptions = veedelOptions ?? [];
    const activeFilterCount =
        Number(category !== null) +
        Number(veedel !== null) +
        Number(free) +
        Number(venueId !== null);
    const emptyMessage =
        activeFilterCount > 0
            ? 'No events match these filters.'
            : window_ === 'today'
              ? "Today's events have finished — see what starts tomorrow."
              : window_ === 'tomorrow'
                ? 'Nothing is scheduled tomorrow — the weekend has more options.'
                : window_ === 'weekend'
                  ? 'No events are scheduled this weekend.'
                  : 'No events are scheduled in the next 7 days.';
    const emptyActionLabel =
        activeFilterCount > 0
            ? 'Clear filters'
            : window_ === 'today'
              ? 'See tomorrow'
              : window_ === 'tomorrow'
                ? 'See the weekend'
                : window_ === 'weekend'
                  ? 'See the next 7 days'
                  : 'Try again';
    const EvModeIcon = (
        EVENT_MODES.find((m) => m.id === evMode) ?? EVENT_MODES[0]
    ).Icon;

    return (
        <AppLayout fullWidth>
            <Head title="Events" />
            <div className="mx-auto h-full w-full max-w-[1120px] overflow-y-auto px-4 pt-6 pb-24 md:px-8">
                {/* Header */}
                <div className="mb-[22px]">
                    <h1 className="font-display text-[30px] font-medium tracking-[-0.02em]">
                        Events
                    </h1>
                    <p className="mt-[3px] text-[13.5px] text-text-2">
                        Show up alone, leave with a contact
                    </p>
                </div>

                {/* Time rail — styled like the Places category rail */}
                <div
                    className="mb-4 flex gap-2 overflow-x-auto pb-1"
                    style={{ scrollbarWidth: 'none' }}
                >
                    {WINDOWS.map(({ id, label, Icon }) => {
                        const on = window_ === id;

                        return (
                            <button
                                key={id}
                                onClick={() => setWindow(id)}
                                aria-pressed={on}
                                className={`flex shrink-0 cursor-pointer items-center gap-1.5 rounded-full border px-3.5 py-2 text-[13px] transition-all ${
                                    on
                                        ? 'border-primary bg-primary-soft font-semibold text-primary'
                                        : 'border-border bg-card font-medium text-text-2 hover:border-primary hover:text-primary'
                                }`}
                            >
                                <Icon size={15} stroke={ICON_STROKE} />
                                {label}
                            </button>
                        );
                    })}
                </div>

                {/* Places-style toolbar — filters (left) · From + Sort (right) */}
                <div className="relative z-20 mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="relative">
                        <button
                            onClick={() => {
                                setFilterOpen((open) => !open);
                                setFromOpen(false);
                                setSortOpen(false);
                            }}
                            aria-expanded={filterOpen}
                            className="flex w-full items-center gap-2 rounded-full border border-border bg-card px-[15px] py-[9px] text-[13.5px] font-semibold text-foreground shadow-card transition-colors hover:border-primary sm:w-auto"
                        >
                            <IconFilter size={15} stroke={ICON_STROKE} />
                            <span className="font-medium text-text-2">
                                Events in
                            </span>
                            {veedel ?? 'All Cologne'}
                            {activeFilterCount > 0 && (
                                <span className="flex size-5 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-white">
                                    {activeFilterCount}
                                </span>
                            )}
                            <IconChevronDown
                                size={13}
                                stroke={ICON_STROKE}
                                className="ml-auto sm:ml-0"
                            />
                        </button>

                        {filterOpen && (
                            <>
                                <div
                                    className="fixed inset-0 z-30"
                                    onClick={() => setFilterOpen(false)}
                                />
                                <div className="absolute top-12 left-0 z-40 w-[min(420px,calc(100vw-2rem))] rounded-[16px] border border-border bg-card p-4 shadow-[0_14px_40px_rgba(33,29,21,0.16)]">
                                    <div className="mb-[11px] font-mono text-[10.5px] tracking-[0.1em] text-text-3 uppercase">
                                        What kind of event?
                                    </div>
                                    <div className="flex flex-wrap gap-[7px]">
                                        {CATEGORIES.map((item) => {
                                            const on = category === item.id;

                                            return (
                                                <button
                                                    key={item.id}
                                                    onClick={() =>
                                                        setCategory(
                                                            on ? null : item.id,
                                                        )
                                                    }
                                                    aria-pressed={on}
                                                    className={`rounded-full border px-[13px] py-[7px] text-[12.5px] transition-colors ${
                                                        on
                                                            ? 'border-primary bg-primary-soft font-semibold text-primary'
                                                            : 'border-border bg-card font-medium text-text-2 hover:border-primary'
                                                    }`}
                                                >
                                                    {item.emoji} {item.label}
                                                </button>
                                            );
                                        })}
                                        <button
                                            onClick={() =>
                                                setFree((value) => !value)
                                            }
                                            aria-pressed={free}
                                            className={`inline-flex items-center gap-1.5 rounded-full border px-[13px] py-[7px] text-[12.5px] transition-colors ${
                                                free
                                                    ? 'border-success bg-success-soft font-semibold text-success'
                                                    : 'border-border bg-card font-medium text-text-2 hover:border-success hover:text-success'
                                            }`}
                                        >
                                            <IconTicket
                                                size={14}
                                                stroke={ICON_STROKE}
                                            />
                                            Free only
                                        </button>
                                    </div>

                                    {venueId && (
                                        <button
                                            onClick={() => setVenueId(null)}
                                            title="Clear venue filter"
                                            className="mt-2.5 inline-flex items-center gap-1.5 rounded-full border border-primary bg-primary-soft px-[13px] py-[7px] text-[12.5px] font-semibold text-primary"
                                        >
                                            <IconMapPin
                                                size={14}
                                                stroke={ICON_STROKE}
                                            />
                                            This venue
                                            <IconX
                                                size={13}
                                                stroke={ICON_STROKE}
                                            />
                                        </button>
                                    )}

                                    <div className="my-[14px] h-px bg-border" />
                                    <div className="mb-[11px] font-mono text-[10.5px] tracking-[0.1em] text-text-3 uppercase">
                                        Where?
                                    </div>
                                    <div className="flex flex-wrap gap-[7px]">
                                        <button
                                            onClick={() => setVeedel(null)}
                                            aria-pressed={veedel === null}
                                            className={`rounded-full border px-[13px] py-[7px] text-[12.5px] transition-colors ${
                                                veedel === null
                                                    ? 'border-primary bg-primary-soft font-semibold text-primary'
                                                    : 'border-border bg-card font-medium text-text-2 hover:border-primary'
                                            }`}
                                        >
                                            All Cologne
                                        </button>
                                        <Deferred
                                            data="veedelOptions"
                                            fallback={
                                                <div className="h-8 w-24 animate-pulse rounded-full bg-secondary" />
                                            }
                                        >
                                            {railOptions.map((name) => (
                                                <button
                                                    key={name}
                                                    onClick={() =>
                                                        setVeedel(
                                                            veedel === name
                                                                ? null
                                                                : name,
                                                        )
                                                    }
                                                    aria-pressed={
                                                        veedel === name
                                                    }
                                                    className={`rounded-full border px-[13px] py-[7px] text-[12.5px] transition-colors ${
                                                        veedel === name
                                                            ? 'border-primary bg-primary-soft font-semibold text-primary'
                                                            : 'border-border bg-card font-medium text-text-2 hover:border-primary'
                                                    }`}
                                                >
                                                    {name}
                                                </button>
                                            ))}
                                        </Deferred>
                                    </div>

                                    {activeFilterCount > 0 && (
                                        <button
                                            onClick={() => {
                                                setCategory(null);
                                                setVeedel(null);
                                                setFree(false);
                                                setVenueId(null);
                                            }}
                                            className="mt-4 inline-flex items-center gap-1.5 text-[12px] font-semibold text-primary"
                                        >
                                            <IconX
                                                size={14}
                                                stroke={ICON_STROKE}
                                            />
                                            Clear filters
                                        </button>
                                    )}
                                </div>
                            </>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2.5">
                        {/* From — cyan ("cyan locates") */}
                        <div className="relative">
                            <button
                                onClick={() => {
                                    setFromOpen((o) => !o);
                                    setFilterOpen(false);
                                    setSortOpen(false);
                                }}
                                className="inline-flex items-center gap-[7px] rounded-full border border-cyan-bd bg-card px-[14px] py-[9px] text-[13px] font-semibold text-cyan-h transition-colors hover:border-cyan"
                            >
                                <IconMapPin size={14} stroke={ICON_STROKE} />
                                <span className="font-medium text-[#7fb6c4]">
                                    from
                                </span>
                                {origin?.label ??
                                    (needsLocation ? 'Set location' : 'You')}
                                <span className="text-[#9ccada]">·</span>
                                <EvModeIcon size={13} stroke={ICON_STROKE} />
                                <IconChevronDown
                                    size={12}
                                    stroke={ICON_STROKE}
                                />
                            </button>

                            {fromOpen && (
                                <>
                                    <div
                                        className="fixed inset-0 z-30"
                                        onClick={() => setFromOpen(false)}
                                    />
                                    <div className="absolute top-12 right-0 z-40 w-[260px] rounded-[16px] border border-cyan-bd bg-card p-[15px] shadow-[0_14px_40px_rgba(33,29,21,0.16)]">
                                        <div className="mb-2.5 font-mono text-[10px] tracking-[0.1em] text-text-3 uppercase">
                                            Measure distances from
                                        </div>
                                        <button
                                            type="button"
                                            onClick={locateMe}
                                            disabled={
                                                locationStatus === 'locating'
                                            }
                                            className="inline-flex items-center gap-1.5 rounded-full border border-cyan bg-cyan-soft px-[11px] py-[7px] text-[12px] font-semibold text-cyan-h disabled:opacity-60"
                                        >
                                            <IconMapPin
                                                size={13}
                                                stroke={ICON_STROKE}
                                            />
                                            {locationStatus === 'locating'
                                                ? 'Finding you…'
                                                : usingLiveOrigin
                                                  ? 'Refresh my location'
                                                  : 'Use my live location'}
                                        </button>
                                        {origin?.label && !usingLiveOrigin && (
                                            <p className="mt-2 text-[11px] text-text-2">
                                                Currently measuring from{' '}
                                                {origin.label}.
                                            </p>
                                        )}
                                        {locationStatus === 'error' && (
                                            <p className="mt-2 text-[11px] text-primary">
                                                Location is unavailable. Allow
                                                location access and try again.
                                            </p>
                                        )}

                                        <div className="my-[13px] h-px bg-border" />

                                        <div className="mb-2.5 font-mono text-[10px] tracking-[0.1em] text-text-3 uppercase">
                                            Travelling by
                                        </div>
                                        <div className="flex gap-1 rounded-full border border-border bg-surface-2 p-[3px]">
                                            {EVENT_MODES.map(
                                                ({ id, label, Icon }) => {
                                                    const on = evMode === id;

                                                    return (
                                                        <button
                                                            key={id}
                                                            onClick={() =>
                                                                setEvMode(id)
                                                            }
                                                            aria-pressed={on}
                                                            className={`flex flex-1 items-center justify-center gap-1.5 rounded-full px-[10px] py-2 text-[12px] transition-colors ${
                                                                on
                                                                    ? 'border border-cyan-bd bg-card font-semibold text-cyan-h shadow-card'
                                                                    : 'border border-transparent font-medium text-text-2'
                                                            }`}
                                                        >
                                                            <Icon
                                                                size={13}
                                                                stroke={
                                                                    ICON_STROKE
                                                                }
                                                            />
                                                            {label}
                                                        </button>
                                                    );
                                                },
                                            )}
                                        </div>
                                        {evMode === 'transit' && (
                                            <p className="mt-2 text-[11px] text-text-2">
                                                Open an event for a live transit
                                                route and travel time.
                                            </p>
                                        )}
                                    </div>
                                </>
                            )}
                        </div>

                        {/* Sort */}
                        <div className="relative">
                            <button
                                onClick={() => {
                                    setSortOpen((o) => !o);
                                    setFilterOpen(false);
                                    setFromOpen(false);
                                }}
                                className="inline-flex items-center gap-[7px] rounded-full border border-border bg-card px-[14px] py-[9px] text-[13px] font-semibold text-foreground shadow-card transition-colors hover:border-primary"
                            >
                                <IconSortDescending
                                    size={13}
                                    stroke={ICON_STROKE}
                                />
                                {evSort}
                                <IconChevronDown
                                    size={12}
                                    stroke={ICON_STROKE}
                                />
                            </button>

                            {sortOpen && (
                                <>
                                    <div
                                        className="fixed inset-0 z-30"
                                        onClick={() => setSortOpen(false)}
                                    />
                                    <div className="absolute top-12 right-0 z-40 min-w-[176px] rounded-[13px] border border-border bg-card p-1.5 shadow-[0_14px_40px_rgba(33,29,21,0.18)]">
                                        {(
                                            [
                                                'Soonest',
                                                'Nearest',
                                                'Recommended',
                                            ] as const
                                        ).map((opt) => {
                                            const on = evSort === opt;

                                            return (
                                                <button
                                                    key={opt}
                                                    onClick={() => {
                                                        setEvSort(opt);
                                                        setSortOpen(false);
                                                    }}
                                                    className={`flex w-full items-center justify-between gap-2.5 rounded-[9px] px-[11px] py-[9px] text-left text-[13px] transition-colors ${
                                                        on
                                                            ? 'bg-cyan-soft font-semibold text-cyan-h'
                                                            : 'font-medium text-foreground hover:bg-surface-2'
                                                    }`}
                                                >
                                                    {opt}
                                                    {on && (
                                                        <IconCheck
                                                            size={14}
                                                            stroke={ICON_STROKE}
                                                        />
                                                    )}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                </div>

                {/* Result count */}
                {status === 'ok' && (
                    <div className="mb-3 font-mono text-[11px] tracking-[0.1em] text-text-2 uppercase">
                        {occurrences.length}{' '}
                        {occurrences.length === 1 ? 'event' : 'events'} ·{' '}
                        {windowLabel}
                    </div>
                )}

                {status === 'ok' && needsLocation && (
                    <div className="mb-4 rounded-2xl border border-cyan-bd bg-cyan-soft p-4 text-sm text-cyan-h">
                        Set your location to see nearest events and travel
                        times.
                        <button
                            type="button"
                            onClick={locateMe}
                            className="ml-2 font-semibold underline underline-offset-2"
                        >
                            Use my location
                        </button>
                    </div>
                )}

                {status === 'loading' ? (
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {[1, 2, 3].map((i) => (
                            <div
                                key={i}
                                className="h-56 animate-pulse rounded-2xl bg-secondary"
                            />
                        ))}
                    </div>
                ) : status === 'error' ? (
                    <div className="rounded-2xl border border-border bg-card p-8 text-center">
                        <p className="text-sm text-muted-foreground">
                            Couldn't load events.
                        </p>
                        <button
                            onClick={() =>
                                fetchEvents(window_, category, veedel, free)
                            }
                            className="mt-3 rounded-[9px] bg-primary px-4 py-2 text-[13px] font-semibold text-white"
                        >
                            Retry
                        </button>
                    </div>
                ) : occurrences.length === 0 ? (
                    <div className="rounded-2xl border border-border bg-card p-8 text-center">
                        <p className="text-sm text-muted-foreground">
                            {emptyMessage}
                        </p>
                        <button
                            onClick={() => {
                                if (activeFilterCount > 0) {
                                    setCategory(null);
                                    setFree(false);
                                    setVeedel(null);
                                    setVenueId(null);
                                } else if (window_ === 'today') {
                                    setWindow('tomorrow');
                                } else if (window_ === 'tomorrow') {
                                    setWindow('weekend');
                                } else if (window_ === 'weekend') {
                                    setWindow('week');
                                } else {
                                    fetchEvents('week', null, null, false);
                                }
                            }}
                            className="mt-3 rounded-[9px] border border-border px-4 py-2 text-[13px] font-semibold text-primary"
                        >
                            {emptyActionLabel}
                        </button>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {occurrences.map((occurrence) => {
                            const key = occurrenceKey(occurrence);

                            return (
                                <ContentCard
                                    key={key}
                                    variant="place"
                                    coarse={eventIllustrationKey(
                                        occurrence.category,
                                    )}
                                    title={occurrence.title}
                                    emoji={occurrence.emoji}
                                    badge={dateBadge(occurrence)}
                                    meta={eventMeta(occurrence, evMode)}
                                    photoUrl={occurrence.photo_url}
                                    live={startsWithinFourHours(occurrence)}
                                    chips={eventChips(occurrence)}
                                    excerpt={
                                        occurrence.tip
                                            ? null
                                            : occurrence.summary
                                    }
                                    tip={occurrence.tip}
                                    onActivate={() =>
                                        openOccurrence(occurrence)
                                    }
                                    secondaryAction={
                                        <RemindMeButton
                                            eventId={occurrence.id}
                                            occurrenceStart={
                                                occurrence.occurrence_start
                                            }
                                            reminded={reminders.has(key)}
                                            onChange={(r) =>
                                                setReminded(occurrence, r)
                                            }
                                        />
                                    }
                                    action={
                                        <button
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                takeMeThere(occurrence);
                                            }}
                                            className="rounded-[9px] bg-accent-soft px-3 py-1.5 text-[13px] font-semibold text-primary transition-colors hover:bg-primary hover:text-white"
                                        >
                                            → Take me there
                                        </button>
                                    }
                                />
                            );
                        })}
                    </div>
                )}
            </div>

            {detail && !destination && (
                <EventRichDetail
                    occurrence={detail}
                    isMobile={isMobile}
                    breadcrumb={windowLabel}
                    onClose={() => setDetail(null)}
                    onNavigate={() => takeMeThere(detail)}
                    onOpenPlace={openPlace}
                />
            )}

            {/* The linked place — same rich detail as the Places page */}
            {placeDetail && !destination && (
                <PlaceDetailModal
                    place={placeDetail}
                    isMobile={isMobile}
                    meta={placeMeta(placeDetail)}
                    onClose={() => setPlaceDetail(null)}
                    onNavigate={(target) => {
                        setDestination({
                            ...target,
                            backLabel: placeDetail.name,
                        });
                    }}
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
