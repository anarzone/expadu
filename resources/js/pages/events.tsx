import { Head, Deferred, usePage } from '@inertiajs/react';
import {
    IconBike,
    IconBus,
    IconCheck,
    IconChevronDown,
    IconMapPin,
    IconSortDescending,
    IconWalk,
} from '@tabler/icons-react';
import type { Icon as TablerIcon } from '@tabler/icons-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { EventRichDetail } from '@/components/events/event-rich-detail';
import { RemindMeButton } from '@/components/events/remind-me-button';
import type {
    EventOccurrence,
    EventReminderEntry,
} from '@/components/events/types';
import { eventIllustrationKey } from '@/components/events/types';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { CategoryIllustration } from '@/components/places/category-illustration';
import { ContentCard } from '@/components/places/content-card';
import type { CardChip } from '@/components/places/content-card';
import { PlaceRichDetail } from '@/components/places/place-rich-detail';
import type { Place } from '@/components/places/types';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { ICON_STROKE } from '@/constants/icons';
import { useIsMobile } from '@/hooks/use-mobile';
import AppLayout from '@/layouts/app-layout';

const WINDOWS: Array<{ id: string; label: string; emoji: string }> = [
    { id: 'today', label: 'Today', emoji: '⚡' },
    { id: 'tomorrow', label: 'Tomorrow', emoji: '🌅' },
    { id: 'weekend', label: 'Weekend', emoji: '🎉' },
    { id: 'week', label: 'Next week', emoji: '📅' },
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

type EventSort = 'Soonest' | 'Nearest' | 'Popular';

/**
 * Client reorder for the Sort control. "Soonest" is a real time sort (the
 * default the API already returns); "Nearest"/"Popular" wait on event distance
 * + popularity data, so they hold the server order for now.
 */
function sortEvents(
    list: EventOccurrence[],
    sortBy: EventSort,
): EventOccurrence[] {
    if (sortBy !== 'Soonest') {
        return list;
    }

    return [...list].sort(
        (a, b) =>
            new Date(a.occurrence_start).getTime() -
            new Date(b.occurrence_start).getTime(),
    );
}

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

export default function Events() {
    const { filters, veedelOptions } = usePage<{
        filters: {
            window: string;
            category: string | null;
            veedel: string | null;
            free: boolean;
            venue: string | null;
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
    const [evMode, setEvMode] = useState<EventMode>('walk');
    const [evSort, setEvSort] = useState<EventSort>('Soonest');
    const [fromOpen, setFromOpen] = useState(false);
    const [sortOpen, setSortOpen] = useState(false);

    const [occurrences, setOccurrences] = useState<EventOccurrence[]>([]);
    const [status, setStatus] = useState<'loading' | 'ok' | 'error'>('loading');
    const [reminders, setReminders] = useState<Set<string>>(new Set());

    const [expandedKey, setExpandedKey] = useState<string | null>(null);
    const [detail, setDetail] = useState<EventOccurrence | null>(null);
    const [placeDetail, setPlaceDetail] = useState<Place | null>(null);
    const [destination, setDestination] = useState<Destination | null>(null);

    const isMobile = useIsMobile();
    const reqRef = useRef(0);

    const fetchEvents = useCallback(
        (w: string, c: string | null, v: string | null, f: boolean) => {
            const token = ++reqRef.current;
            const params = new URLSearchParams({ window: w });

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

            fetch(`/api/events?${params}`, { credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error())))
                .then((json) => {
                    if (token !== reqRef.current) {
                        return;
                    }

                    setOccurrences(json.data ?? []);
                    setExpandedKey(null);
                    setStatus('ok');
                })
                .catch(() => {
                    if (token === reqRef.current) {
                        setStatus('error');
                    }
                });
        },
        [venueId],
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

        const qs = params.toString();
        // Preserve history.state — Inertia v2 stores its page snapshot
        // there; replacing it with {} breaks back/forward app-wide.
        globalThis.history.replaceState(
            globalThis.history.state,
            '',
            `/events${qs ? `?${qs}` : ''}`,
        );
    }, [window_, category, veedel, free, venueId, fetchEvents]);

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
        if (isMobile) {
            const key = occurrenceKey(occurrence);
            setExpandedKey((k) => (k === key ? null : key));
        } else {
            setDetail(occurrence);
        }
    }

    function takeMeThere(occurrence: EventOccurrence) {
        if (occurrence.venue.lat == null || occurrence.venue.lng == null) {
            return;
        }

        setDetail(null);
        setDestination({
            name: occurrence.venue.name ?? occurrence.title,
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
                setExpandedKey(null);
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
    const EvModeIcon = (
        EVENT_MODES.find((m) => m.id === evMode) ?? EVENT_MODES[0]
    ).Icon;

    const detailBody = (occurrence: EventOccurrence) => (
        <EventRichDetail
            occurrence={occurrence}
            onNavigate={() => takeMeThere(occurrence)}
            onOpenPlace={openPlace}
        />
    );

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

                {/* Time rail — the opener is when, not where */}
                <div
                    className="mb-4 flex gap-2.5 overflow-x-auto pb-1"
                    style={{ scrollbarWidth: 'none' }}
                >
                    {WINDOWS.map((w) => (
                        <button
                            key={w.id}
                            onClick={() => setWindow(w.id)}
                            aria-pressed={window_ === w.id}
                            className={`flex shrink-0 cursor-pointer items-center gap-1.5 rounded-[12px] border px-[17px] py-2.5 text-[14px] transition-all ${
                                window_ === w.id
                                    ? 'border-primary bg-primary font-semibold text-white shadow-[0_2px_9px_rgba(255,57,2,0.26)]'
                                    : 'border-border bg-card font-medium text-text-2 hover:border-primary'
                            }`}
                        >
                            {w.emoji} {w.label}
                        </button>
                    ))}
                </div>

                {/* Category chips */}
                <div
                    className="mb-4 flex gap-2 overflow-x-auto pb-1"
                    style={{ scrollbarWidth: 'none' }}
                >
                    {CATEGORIES.map((c) => {
                        const on = category === c.id;

                        return (
                            <button
                                key={c.id}
                                onClick={() => setCategory(on ? null : c.id)}
                                aria-pressed={on}
                                className={`flex shrink-0 cursor-pointer items-center gap-1.5 rounded-full border px-[15px] py-2 text-[13px] transition-all ${
                                    on
                                        ? 'border-primary bg-primary-soft font-semibold text-primary'
                                        : 'border-border bg-card font-medium text-text-2 hover:border-primary hover:text-primary'
                                }`}
                            >
                                {c.emoji} {c.label}
                            </button>
                        );
                    })}
                    {venueId && (
                        <button
                            onClick={() => setVenueId(null)}
                            title="Showing one venue — tap to clear"
                            className="shrink-0 cursor-pointer rounded-full border border-primary bg-primary px-3 py-1.5 text-[13px] font-medium text-white"
                        >
                            📍 this venue ✕
                        </button>
                    )}
                </div>

                {/* v4 controls row — Free only (left) · From + Sort (right) */}
                <div className="relative z-20 mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <button
                        onClick={() => setFree((f) => !f)}
                        aria-pressed={free}
                        className={`flex shrink-0 cursor-pointer items-center gap-1.5 self-start rounded-full border px-[15px] py-2 text-[13px] transition-all ${
                            free
                                ? 'border-success bg-success-soft font-semibold text-success'
                                : 'border-border bg-card font-medium text-text-2 hover:border-success hover:text-success'
                        }`}
                    >
                        <IconCheck size={14} stroke={ICON_STROKE} />
                        Free only
                    </button>

                    <div className="flex flex-wrap items-center gap-2.5">
                        {/* From — cyan ("cyan locates") */}
                        <div className="relative">
                            <button
                                onClick={() => {
                                    setFromOpen((o) => !o);
                                    setSortOpen(false);
                                }}
                                className="inline-flex items-center gap-[7px] rounded-full border border-cyan-bd bg-card px-[14px] py-[9px] text-[13px] font-semibold text-cyan-h transition-colors hover:border-cyan"
                            >
                                <IconMapPin size={14} stroke={ICON_STROKE} />
                                <span className="font-medium text-[#7fb6c4]">
                                    from
                                </span>
                                You
                                <span className="text-[#9ccada]">·</span>
                                <EvModeIcon size={13} stroke={ICON_STROKE} />
                                <IconChevronDown size={12} stroke={2} />
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
                                        <span className="inline-flex items-center gap-1.5 rounded-full border border-cyan bg-cyan-soft px-[11px] py-[7px] text-[12px] font-semibold text-cyan-h">
                                            <IconMapPin
                                                size={13}
                                                stroke={ICON_STROKE}
                                            />
                                            My location
                                        </span>

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
                                    </div>
                                </>
                            )}
                        </div>

                        {/* Sort */}
                        <div className="relative">
                            <button
                                onClick={() => {
                                    setSortOpen((o) => !o);
                                    setFromOpen(false);
                                }}
                                className="inline-flex items-center gap-[7px] rounded-full border border-border bg-card px-[14px] py-[9px] text-[13px] font-semibold text-foreground shadow-card transition-colors hover:border-primary"
                            >
                                <IconSortDescending
                                    size={13}
                                    stroke={ICON_STROKE}
                                />
                                {evSort}
                                <IconChevronDown size={12} stroke={2} />
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
                                                'Popular',
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
                                                            stroke={2.2}
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

                {/* Veedel chips — secondary filter */}
                <Deferred data="veedelOptions" fallback={null}>
                    {railOptions.length > 0 ? (
                        <div
                            className="mb-4 flex gap-2 overflow-x-auto pb-1"
                            style={{ scrollbarWidth: 'none' }}
                        >
                            {railOptions.map((name) => (
                                <button
                                    key={name}
                                    onClick={() =>
                                        setVeedel(veedel === name ? null : name)
                                    }
                                    aria-pressed={veedel === name}
                                    className={`shrink-0 cursor-pointer rounded-full border px-[15px] py-2 text-[13px] transition-all ${
                                        veedel === name
                                            ? 'border-primary bg-primary font-semibold text-white'
                                            : 'border-border bg-card font-medium text-text-2 hover:border-primary hover:text-primary'
                                    }`}
                                >
                                    {name}
                                </button>
                            ))}
                        </div>
                    ) : (
                        <span />
                    )}
                </Deferred>

                {/* Result count */}
                {status === 'ok' && (
                    <div className="mb-3 font-mono text-[11px] tracking-[0.1em] text-text-2 uppercase">
                        {occurrences.length}{' '}
                        {occurrences.length === 1 ? 'event' : 'events'} ·{' '}
                        {windowLabel}
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
                            Quiet {windowLabel.toLowerCase()} —{' '}
                            {window_ === 'weekend' || window_ === 'week'
                                ? 'try the whole week.'
                                : 'the weekend looks better.'}
                        </p>
                        <button
                            onClick={() => {
                                setCategory(null);
                                setFree(false);
                                setVeedel(null);
                                setVenueId(null);

                                if (window_ === 'weekend') {
                                    setWindow('week');
                                } else if (window_ !== 'week') {
                                    setWindow('weekend');
                                } else if (
                                    !category &&
                                    !free &&
                                    !veedel &&
                                    !venueId
                                ) {
                                    // week + no filters: nothing would change —
                                    // make the button an explicit retry
                                    fetchEvents('week', null, null, false);
                                }
                            }}
                            className="mt-3 rounded-[9px] border border-border px-4 py-2 text-[13px] font-semibold text-primary"
                        >
                            {window_ === 'weekend'
                                ? 'See the whole week'
                                : window_ === 'week'
                                  ? 'Clear filters & retry'
                                  : 'Jump to the weekend'}
                        </button>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {sortEvents(occurrences, evSort).map((occurrence) => {
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
                                    meta={occurrence.meta}
                                    photoUrl={occurrence.photo_url}
                                    live={startsWithinFourHours(occurrence)}
                                    chips={eventChips(occurrence)}
                                    excerpt={
                                        occurrence.tip
                                            ? null
                                            : occurrence.summary
                                    }
                                    tip={occurrence.tip}
                                    expanded={isMobile && expandedKey === key}
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
                                >
                                    {isMobile ? (
                                        <div className="border-t border-border px-4 py-4">
                                            {detailBody(occurrence)}
                                        </div>
                                    ) : undefined}
                                </ContentCard>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* Desktop detail — traditional centered modal */}
            <Dialog
                open={!isMobile && detail !== null}
                onOpenChange={(open) => !open && setDetail(null)}
            >
                {detail && (
                    <DialogContent
                        aria-describedby={undefined}
                        className="gap-0 overflow-hidden p-0 sm:max-w-md"
                    >
                        <DialogTitle className="sr-only">
                            {detail.title}
                        </DialogTitle>
                        {detail.photo_url ? (
                            <div className="relative">
                                <img
                                    src={detail.photo_url}
                                    alt={detail.title}
                                    className="h-32 w-full object-cover"
                                />
                                {detail.photo_attribution && (
                                    <span className="absolute right-1.5 bottom-1.5 max-w-[85%] truncate rounded bg-black/55 px-1.5 py-0.5 text-[9px] text-white/85">
                                        {detail.photo_attribution}
                                    </span>
                                )}
                            </div>
                        ) : (
                            <CategoryIllustration
                                coarse={eventIllustrationKey(detail.category)}
                                className="h-24 w-full"
                                iconSize={34}
                            />
                        )}
                        <div className="max-h-[72vh] overflow-y-auto p-5">
                            <div className="mb-3 flex items-start gap-2.5">
                                <span className="text-2xl leading-tight">
                                    {detail.emoji}
                                </span>
                                <div className="min-w-0">
                                    <h2 className="font-display text-xl leading-tight font-medium">
                                        {detail.title}
                                    </h2>
                                    <div className="mt-0.5 text-[13px] text-muted-foreground">
                                        {detail.meta}
                                    </div>
                                </div>
                            </div>
                            {detailBody(detail)}
                        </div>
                    </DialogContent>
                )}
            </Dialog>

            {/* The linked place — same rich detail as the Places page */}
            {placeDetail && !isMobile && (
                <Dialog
                    open
                    onOpenChange={(open) => !open && setPlaceDetail(null)}
                >
                    <DialogContent
                        aria-describedby={undefined}
                        className="gap-0 overflow-hidden p-0 sm:max-w-md"
                    >
                        <DialogTitle className="sr-only">
                            {placeDetail.name}
                        </DialogTitle>
                        <div className="max-h-[80vh] overflow-y-auto p-5">
                            <PlaceRichDetail
                                place={placeDetail}
                                meta={placeMeta(placeDetail)}
                                onNavigate={(target) => {
                                    setPlaceDetail(null);
                                    setDestination(target);
                                }}
                            />
                        </div>
                    </DialogContent>
                </Dialog>
            )}
            {placeDetail && isMobile && (
                <BottomSheet open onClose={() => setPlaceDetail(null)}>
                    <div className="pb-24">
                        <PlaceRichDetail
                            place={placeDetail}
                            meta={placeMeta(placeDetail)}
                            onNavigate={(target) => {
                                setPlaceDetail(null);
                                setDestination(target);
                            }}
                        />
                    </div>
                </BottomSheet>
            )}

            {destination && (
                <TakeMeThereSheet
                    destination={destination}
                    onClose={() => setDestination(null)}
                />
            )}
        </AppLayout>
    );
}
