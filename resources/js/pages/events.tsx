import { Head, Deferred, usePage } from '@inertiajs/react';
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
    const [venueId] = useState<string | null>(filters.venue);

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
        globalThis.history.replaceState({}, '', `/events${qs ? `?${qs}` : ''}`);
    }, [window_, category, veedel, free, venueId, fetchEvents]);

    // Back/forward re-reads the URL
    useEffect(() => {
        function onPop() {
            const sp = new URLSearchParams(globalThis.location.search);
            setWindow(sp.get('window') ?? 'today');
            setCategory(sp.get('category'));
            setVeedel(sp.get('veedel'));
            setFree(sp.get('free') === '1');
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
                <div className="mb-5">
                    <h1 className="font-display text-[26px] font-medium tracking-tight">
                        Events
                    </h1>
                    <p className="mt-0.5 text-[13px] text-muted-foreground">
                        Show up alone, leave with a contact
                    </p>
                </div>

                {/* Time rail — the opener is when, not where */}
                <div
                    className="mb-3 flex gap-2 overflow-x-auto pb-1"
                    style={{ scrollbarWidth: 'none' }}
                >
                    {WINDOWS.map((w) => (
                        <button
                            key={w.id}
                            onClick={() => setWindow(w.id)}
                            aria-pressed={window_ === w.id}
                            className={`flex shrink-0 cursor-pointer items-center gap-1.5 rounded-2xl border px-4 py-2.5 text-[14px] font-semibold transition-all ${
                                window_ === w.id
                                    ? 'border-primary bg-primary text-white'
                                    : 'border-border bg-card text-foreground hover:border-primary'
                            }`}
                        >
                            {w.emoji} {w.label}
                        </button>
                    ))}
                </div>

                {/* Category chips + free toggle */}
                <div
                    className="mb-2 flex gap-2 overflow-x-auto pb-1"
                    style={{ scrollbarWidth: 'none' }}
                >
                    {CATEGORIES.map((c) => {
                        const on = category === c.id;

                        return (
                            <button
                                key={c.id}
                                onClick={() => setCategory(on ? null : c.id)}
                                aria-pressed={on}
                                className={`flex shrink-0 cursor-pointer items-center gap-1 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-all ${
                                    on
                                        ? 'border-primary bg-accent-soft text-primary'
                                        : 'border-border bg-card text-muted-foreground hover:border-primary hover:text-primary'
                                }`}
                            >
                                {c.emoji} {c.label}
                            </button>
                        );
                    })}
                    <button
                        onClick={() => setFree((f) => !f)}
                        aria-pressed={free}
                        className={`shrink-0 cursor-pointer rounded-full border px-3 py-1.5 text-[13px] font-medium transition-all ${
                            free
                                ? 'border-success bg-success-soft text-success'
                                : 'border-border bg-card text-muted-foreground hover:border-success hover:text-success'
                        }`}
                    >
                        free
                    </button>
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
                                    className={`shrink-0 cursor-pointer rounded-full border px-3 py-1.5 text-[13px] font-medium transition-all ${
                                        veedel === name
                                            ? 'border-primary bg-primary text-white'
                                            : 'border-border bg-card text-muted-foreground hover:border-primary hover:text-primary'
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
                    <div className="mb-3 font-mono text-[11px] tracking-[0.1em] text-muted-foreground uppercase">
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
                                setWindow(
                                    window_ === 'weekend' || window_ === 'week'
                                        ? 'week'
                                        : 'weekend',
                                );
                            }}
                            className="mt-3 rounded-[9px] border border-border px-4 py-2 text-[13px] font-semibold text-primary"
                        >
                            {window_ === 'weekend' || window_ === 'week'
                                ? 'See the whole week'
                                : 'Jump to the weekend'}
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
