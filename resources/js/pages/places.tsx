import { Head, Deferred, usePage } from '@inertiajs/react';
import { IconChevronDown, IconList, IconMap } from '@tabler/icons-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { categoryEmoji } from '@/components/places/category-illustration';
import { ContentCard } from '@/components/places/content-card';
import type { CardChip } from '@/components/places/content-card';
import { FromControl } from '@/components/places/from-control';
import type {
    PlacesOrigin,
    TransportMode,
} from '@/components/places/from-control';
import { PlaceDetail } from '@/components/places/place-detail';
import { PlaceDetailModal } from '@/components/places/place-detail-modal';
import { FeedbackToast } from '@/components/places/place-feedback-menu';
import { PlacesMap } from '@/components/places/places-map';
import type { Place, VeedelOption } from '@/components/places/types';
import { ICON_STROKE } from '@/constants/icons';
import { useFeedback } from '@/hooks/use-feedback';
import { useIsMobile } from '@/hooks/use-mobile';
import AppLayout from '@/layouts/app-layout';

const CSRF = () =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') || '';

const CATEGORIES: Array<{ id: string; label: string; emoji: string }> = [
    { id: 'park', label: 'Parks', emoji: '🌳' },
    { id: 'culture', label: 'Culture', emoji: '🏛️' },
    { id: 'pitch', label: 'Pitches', emoji: '⚽' },
    { id: 'court', label: 'Courts', emoji: '🏀' },
    { id: 'swimming', label: 'Swimming', emoji: '🏊' },
    { id: 'playground', label: 'Playgrounds', emoji: '🛝' },
    { id: 'dog_park', label: 'Dog parks', emoji: '🐕' },
];

function placeChips(place: Place): CardChip[] {
    // Venue cards lead with what you can do there — that IS the card.
    if (place.activities.length > 0) {
        return place.activities.map((activity) => ({
            label: `${activity.emoji} ${activity.label}`,
            tone: 'feature' as const,
        }));
    }

    const chips: CardChip[] = [];

    if (place.open_now === true) {
        chips.push({ label: 'open now', tone: 'open' });
    } else if (place.open_now === false) {
        chips.push({ label: 'closed', tone: 'closed' });
    }

    if (place.price_text) {
        chips.push({ label: place.price_text, tone: 'price' });
    }

    for (const f of place.feature_chips.slice(0, 2)) {
        chips.push({ label: f, tone: 'feature' });
    }

    if (place.cluster_size > 1) {
        chips.push({ label: `×${place.cluster_size} here`, tone: 'feature' });
    }

    return chips;
}

function placeMeta(place: Place): string {
    const cat =
        place.fine_label ??
        CATEGORIES.find((c) => c.id === place.category)?.label ??
        'Place';

    return [
        cat,
        // The park is the venue — a more meaningful address than the
        // Stadtteil when the facility sits inside one.
        place.park ?? place.veedel,
        place.distance_min != null ? `${place.distance_min} min away` : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

function placeEmoji(place: Place): string {
    return place.emoji ?? categoryEmoji(place.category);
}

export default function Places() {
    const { auth, homeVeedel, homeBezirk, filters, bezirke, veedelsByBezirk } =
        usePage<{
            auth: { user: { transport_mode: TransportMode | null } };
            homeVeedel: string | null;
            homeBezirk: string | null;
            filters: {
                bezirk: string;
                veedel: string | null;
                category: string | null;
            };
            bezirke?: VeedelOption[];
            veedelsByBezirk?: Record<string, string[]>;
        }>().props;

    const [bezirk, setBezirk] = useState(filters.bezirk ?? 'all');
    const [veedel, setVeedel] = useState<string | null>(filters.veedel);
    const [category, setCategory] = useState<string | null>(filters.category);
    const [view, setView] = useState<'list' | 'map'>('list');

    const [places, setPlaces] = useState<Place[]>([]);
    const [total, setTotal] = useState(0);
    const [page, setPage] = useState(1);
    const [hasMore, setHasMore] = useState(false);
    const [nearbyIncluded, setNearbyIncluded] = useState(false);
    const [status, setStatus] = useState<'loading' | 'ok' | 'error'>('loading');

    const [expandedId, setExpandedId] = useState<number | null>(null);
    const [detail, setDetail] = useState<Place | null>(null);
    const [richPlace, setRichPlace] = useState<Place | null>(null);
    // Breadcrumb of places hopped through via nearby chips ("← back")
    const [hopStack, setHopStack] = useState<Place[]>([]);
    const [destination, setDestination] = useState<Destination | null>(null);
    const { stateFor, ratingFor, setFeedback, toast } = useFeedback();

    const isMobile = useIsMobile();
    const reqRef = useRef(0);
    const busyRef = useRef(false);

    // A live GPS fix for the map: drives the "you are here" dot and, via the
    // confirmed-location anchor, the "min away" distances on every card.
    const [userLoc, setUserLoc] = useState<{ lat: number; lng: number } | null>(
        null,
    );
    const [locating, setLocating] = useState(false);
    const [flyTo, setFlyTo] = useState(0);
    const [locateError, setLocateError] = useState<string | null>(null);
    const autoPingedRef = useRef(false);

    // The resolved distance origin (from the API) + the chosen travel mode,
    // surfaced together in the From control.
    const [origin, setOrigin] = useState<PlacesOrigin | null>(null);
    const [mode, setMode] = useState<TransportMode | null>(
        auth.user.transport_mode ?? null,
    );

    // Auto-dismiss the locate error after a few seconds.
    useEffect(() => {
        if (!locateError) {
            return;
        }

        const t = setTimeout(() => setLocateError(null), 4500);

        return () => clearTimeout(t);
    }, [locateError]);

    const fetchPage = useCallback(
        (
            b: string,
            v: string | null,
            c: string | null,
            p: number,
            append: boolean,
        ) => {
            const token = ++reqRef.current;
            busyRef.current = true;
            const params = new URLSearchParams({ page: String(p) });

            // Stadtteil beats Bezirk; neither means all of Cologne.
            if (v) {
                params.set('veedel', v);
            } else if (b && b !== 'all') {
                params.set('bezirk', b);
            }

            if (c) {
                params.set('category', c);
            }

            // All state writes happen async (in .then/.catch) so the
            // filter-change effect never sets state synchronously. Stale
            // cards stay visible until the new page resolves.
            fetch(`/api/places?${params}`, { credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error())))
                .then((json) => {
                    if (token !== reqRef.current) {
                        return; // a newer request superseded this one
                    }

                    busyRef.current = false;
                    const data: Place[] = json.data ?? [];
                    setPlaces((prev) => (append ? [...prev, ...data] : data));
                    setTotal(json.meta?.total ?? data.length);
                    setHasMore(
                        (json.meta?.current_page ?? p) <
                            (json.meta?.last_page ?? p),
                    );
                    setNearbyIncluded(json.nearby_included === true);
                    setOrigin(json.origin ?? null);
                    setPage(p);

                    if (!append) {
                        setExpandedId(null);
                    }

                    setStatus('ok');
                })
                .catch(() => {
                    if (token === reqRef.current) {
                        busyRef.current = false;
                        setStatus('error');
                    }
                });
        },
        [],
    );

    // Ping the device for a fresh fix. `fly` recenters the map (explicit tap);
    // the silent auto-locate leaves the viewport alone. The fix persists as the
    // 2h "I'm here" anchor (POST body, never the URL — GPS stays out of query
    // strings), then we refetch so distances recompute from it.
    const locate = useCallback(
        (fly: boolean) => {
            if (!('geolocation' in navigator)) {
                if (fly) {
                    setLocateError("This browser can't share your location.");
                }

                return;
            }

            setLocating(true);
            setLocateError(null);
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    const { latitude: lat, longitude: lng } = pos.coords;
                    setUserLoc({ lat, lng });

                    if (fly) {
                        setFlyTo((n) => n + 1);
                    }

                    fetch('/api/location/confirm', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': CSRF(),
                        },
                        body: JSON.stringify({ lat, lng }),
                    })
                        .then(() =>
                            fetchPage(bezirk, veedel, category, 1, false),
                        )
                        .catch(() => {})
                        .finally(() => setLocating(false));
                },
                (err) => {
                    setLocating(false);

                    // A denied/blocked prompt is the common case — say so on an
                    // explicit tap instead of failing silently (which reads as
                    // "broken"). The silent auto-ping stays quiet.
                    if (fly) {
                        setLocateError(
                            err.code === err.PERMISSION_DENIED
                                ? 'Location is blocked — allow it in your browser to see distances from where you are.'
                                : "Couldn't get your location — try again in a moment.",
                        );
                    }
                },
                {
                    enableHighAccuracy: false,
                    maximumAge: 120_000,
                    timeout: 8000,
                },
            );
        },
        [bezirk, veedel, category, fetchPage],
    );

    // Persist the chosen transport mode, then refetch so distances recompute in
    // that mode (the server reads users.transport_mode).
    const persistMode = useCallback(
        (next: TransportMode) => {
            setMode(next);
            fetch('/api/preferences/transport-mode', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF(),
                },
                body: JSON.stringify({ mode: next }),
            })
                .then(() => fetchPage(bezirk, veedel, category, 1, false))
                .catch(() => {});
        },
        [bezirk, veedel, category, fetchPage],
    );

    // On opening the map, ping silently only if permission was already granted —
    // never a cold prompt on a browse screen. The "Locate me" button is the
    // explicit path. Runs once per page visit.
    useEffect(() => {
        if (view !== 'map' || userLoc || autoPingedRef.current) {
            return;
        }

        // No Permissions API (older browsers) → no silent ping; the button
        // still works.
        if (!navigator.permissions?.query) {
            return;
        }

        navigator.permissions
            .query({ name: 'geolocation' as PermissionName })
            .then((res) => {
                if (res.state === 'granted') {
                    autoPingedRef.current = true;
                    locate(false);
                }
            })
            .catch(() => {});
    }, [view, userLoc, locate]);

    // Fetch on filter change + sync URL (shareable, back-safe)
    useEffect(() => {
        fetchPage(bezirk, veedel, category, 1, false);

        const params = new URLSearchParams();

        if (bezirk && bezirk !== 'all') {
            params.set('bezirk', bezirk);
        }

        if (veedel) {
            params.set('veedel', veedel);
        }

        if (category) {
            params.set('category', category);
        }

        const qs = params.toString();
        // Preserve history.state — Inertia v2 stores its page snapshot
        // there; replacing it with {} breaks back/forward app-wide.
        window.history.replaceState(
            window.history.state,
            '',
            `/explore${qs ? `?${qs}` : ''}`,
        );
    }, [bezirk, veedel, category, fetchPage]);

    // Back/forward button re-reads the URL
    useEffect(() => {
        function onPop() {
            // Also fires when leaving the page — don't touch state then
            if (window.location.pathname !== '/explore') {
                return;
            }

            const sp = new URLSearchParams(window.location.search);
            const hasGeo = sp.has('veedel') || sp.has('bezirk');
            setBezirk(
                sp.get('bezirk') ?? (hasGeo ? 'all' : (homeBezirk ?? 'all')),
            );
            setVeedel(sp.get('veedel') ?? (hasGeo ? null : homeVeedel));
            setCategory(sp.get('category'));
        }
        window.addEventListener('popstate', onPop);

        return () => window.removeEventListener('popstate', onPop);
    }, [homeVeedel, homeBezirk]);

    function showMore() {
        // A double-click would skip a page: the optimistic page bump plus
        // the token guard discarded the in-flight response. One at a time.
        if (busyRef.current) {
            return;
        }

        fetchPage(bezirk, veedel, category, page + 1, true);
    }

    function pickBezirk(name: string) {
        setBezirk(name);
        setVeedel(null);
    }

    function openPlace(place: Place) {
        if (isMobile) {
            setExpandedId((id) => (id === place.id ? null : place.id));
        } else {
            setHopStack([]);
            setDetail(place);
        }
    }

    // Nearby-chip hop: remember where we came from so "← back" works.
    function hopTo(next: Place) {
        const current = isMobile ? richPlace : detail;

        if (current) {
            setHopStack((stack) => [...stack, current]);
        }

        (isMobile ? setRichPlace : setDetail)(next);
    }

    function hopBack() {
        const previous = hopStack.at(-1);

        if (!previous) {
            return;
        }

        setHopStack((stack) => stack.slice(0, -1));
        (isMobile ? setRichPlace : setDetail)(previous);
    }

    function takeMeThere(place: Place) {
        setDestination({
            name: place.name,
            emoji: placeEmoji(place),
            lat: place.lat,
            lng: place.lng,
        });
    }

    const areaLabel =
        veedel ?? (bezirk === 'all' ? 'All Cologne' : `Bezirk ${bezirk}`);
    const railOptions = bezirke ?? [];
    const chipOptions =
        bezirk !== 'all' ? (veedelsByBezirk?.[bezirk] ?? []) : [];

    // Keep the active chip visible when it's picked elsewhere (URL,
    // back button).
    useEffect(() => {
        document
            .querySelector(`[data-veedel-chip="${CSS.escape(veedel ?? '')}"]`)
            ?.scrollIntoView({ inline: 'nearest', block: 'nearest' });
    }, [veedel, chipOptions.length]);

    const takeMeThereButton = (place: Place) => (
        <button
            onClick={(e) => {
                e.stopPropagation();
                takeMeThere(place);
            }}
            className="rounded-[9px] bg-accent-soft px-3 py-1.5 text-[13px] font-semibold text-primary transition-colors hover:bg-primary hover:text-white"
        >
            → Take me there
        </button>
    );

    return (
        <AppLayout fullWidth>
            <Head title="Places" />
            <div className="mx-auto h-full w-full max-w-[1120px] overflow-y-auto px-4 pt-6 pb-24 md:px-8">
                {/* Header */}
                <div className="mb-5 flex items-start justify-between gap-3">
                    <div>
                        <h1 className="font-display text-[26px] font-medium tracking-tight">
                            Places
                        </h1>
                        <p className="mt-0.5 text-[13px] text-muted-foreground">
                            Parks, museums & places worth knowing
                        </p>
                    </div>
                    <button
                        onClick={() =>
                            setView((v) => (v === 'map' ? 'list' : 'map'))
                        }
                        aria-pressed={view === 'map'}
                        className={`flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors ${
                            view === 'map'
                                ? 'border-primary bg-accent-soft text-primary'
                                : 'border-border text-muted-foreground hover:border-primary hover:text-primary'
                        }`}
                    >
                        {view === 'map' ? (
                            <IconList size={15} stroke={ICON_STROKE} />
                        ) : (
                            <IconMap size={15} stroke={ICON_STROKE} />
                        )}{' '}
                        {view === 'map' ? 'List view' : 'Map view'}
                    </button>
                </div>

                {/* Bezirk browse rail — Cologne's 9 city districts */}
                <Deferred
                    data="bezirke"
                    fallback={
                        <div className="mb-4 flex gap-3 overflow-hidden">
                            {[1, 2, 3, 4].map((i) => (
                                <div
                                    key={i}
                                    className="h-[118px] w-[165px] shrink-0 animate-pulse rounded-2xl bg-secondary md:w-[180px]"
                                />
                            ))}
                        </div>
                    }
                >
                    <div
                        className="mb-4 flex gap-3 overflow-x-auto pb-1"
                        style={{ scrollbarWidth: 'none' }}
                    >
                        <ContentCard
                            variant="veedel"
                            coarse="veedel"
                            seed={null}
                            title="All Cologne"
                            active={bezirk === 'all'}
                            onActivate={() => pickBezirk('all')}
                        />
                        {railOptions.map((b) => (
                            <ContentCard
                                key={b.name}
                                variant="veedel"
                                coarse="veedel"
                                title={b.name}
                                count={b.count}
                                photoUrl={b.photo_url}
                                active={bezirk === b.name}
                                onActivate={() => pickBezirk(b.name)}
                            />
                        ))}
                    </div>
                </Deferred>

                {/* Veedel chips — the Stadtteile of the selected Bezirk */}
                {chipOptions.length > 0 && (
                    <div
                        className="mb-2 flex gap-2 overflow-x-auto pb-1"
                        style={{ scrollbarWidth: 'none' }}
                    >
                        {[null, ...chipOptions].map((name) => {
                            const on = veedel === name;

                            return (
                                <button
                                    key={name ?? 'all'}
                                    data-veedel-chip={name ?? ''}
                                    onClick={() => setVeedel(name)}
                                    aria-pressed={on}
                                    className={`shrink-0 cursor-pointer rounded-full border px-3 py-1.5 text-[13px] font-medium transition-all ${
                                        on
                                            ? 'border-primary bg-primary text-white'
                                            : 'border-border bg-card text-muted-foreground hover:border-primary hover:text-primary'
                                    }`}
                                >
                                    {name ?? `All of ${bezirk}`}
                                </button>
                            );
                        })}
                    </div>
                )}

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
                </div>

                {/* From control — where distances are measured from + how */}
                <FromControl
                    origin={origin}
                    mode={mode}
                    locating={locating}
                    onLocate={() => locate(true)}
                    onMode={persistMode}
                />

                {/* Result count */}
                {status === 'ok' && view === 'list' && (
                    <div className="mb-3 font-mono text-[11px] tracking-[0.1em] text-muted-foreground uppercase">
                        {total} {total === 1 ? 'place' : 'places'} · {areaLabel}
                        {veedel !== null && nearbyIncluded && ' & nearby'}
                    </div>
                )}

                {/* Map view — the current results as pins on our basemap */}
                {view === 'map' ? (
                    <PlacesMap
                        places={places}
                        emojiFor={placeEmoji}
                        metaFor={placeMeta}
                        onOpen={openPlace}
                        onTakeMeThere={takeMeThere}
                        userLocation={userLoc}
                        onLocate={() => locate(true)}
                        locating={locating}
                        flyToToken={flyTo}
                    />
                ) : status === 'loading' ? (
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
                            Couldn't load places.
                        </p>
                        <button
                            onClick={() =>
                                fetchPage(bezirk, veedel, category, 1, false)
                            }
                            className="mt-3 rounded-[9px] bg-primary px-4 py-2 text-[13px] font-semibold text-white"
                        >
                            Retry
                        </button>
                    </div>
                ) : places.length === 0 ? (
                    <div className="rounded-2xl border border-border bg-card p-8 text-center">
                        <p className="text-sm text-muted-foreground">
                            No{' '}
                            {category
                                ? CATEGORIES.find(
                                      (c) => c.id === category,
                                  )?.label.toLowerCase()
                                : 'places'}{' '}
                            in {areaLabel} yet — try nearby.
                        </p>
                        <button
                            onClick={() => {
                                pickBezirk('all');
                                setCategory(null);
                            }}
                            className="mt-3 rounded-[9px] border border-border px-4 py-2 text-[13px] font-semibold text-primary"
                        >
                            Show all of Cologne
                        </button>
                    </div>
                ) : (
                    <>
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {places
                                .filter(
                                    (place) =>
                                        (stateFor(place.id) ??
                                            place.feedback_state) !==
                                        'not_interested',
                                )
                                .map((place) => (
                                    <ContentCard
                                        key={place.id}
                                        variant="place"
                                        coarse={place.category}
                                        title={place.name}
                                        emoji={placeEmoji(place)}
                                        meta={placeMeta(place)}
                                        chips={placeChips(place)}
                                        tip={
                                            place.tip_is_generic
                                                ? null
                                                : place.tip
                                        }
                                        photoUrl={place.photo_url}
                                        feedback={{
                                            state:
                                                stateFor(place.id) ??
                                                place.feedback_state,
                                            onAction: (action, rating) =>
                                                setFeedback(
                                                    place.id,
                                                    action,
                                                    rating,
                                                ),
                                        }}
                                        expanded={
                                            isMobile && expandedId === place.id
                                        }
                                        onActivate={() => openPlace(place)}
                                        action={takeMeThereButton(place)}
                                    >
                                        {isMobile ? (
                                            <div className="border-t border-border px-4 py-4">
                                                <PlaceDetail place={place} />
                                                <button
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setHopStack([]);
                                                        setRichPlace(place);
                                                    }}
                                                    className="mt-3 block min-h-11 w-full rounded-[9px] border border-border py-2.5 text-center text-[13px] font-semibold text-primary transition-colors hover:border-primary"
                                                >
                                                    Details — map, facts &
                                                    nearby
                                                </button>
                                            </div>
                                        ) : undefined}
                                    </ContentCard>
                                ))}
                        </div>

                        {hasMore && (
                            <div className="mt-5 flex justify-center">
                                <button
                                    onClick={showMore}
                                    className="flex items-center gap-1.5 rounded-full border border-border bg-card px-5 py-2.5 text-[13px] font-semibold text-foreground transition-colors hover:border-primary hover:text-primary"
                                >
                                    Show more
                                    <IconChevronDown
                                        size={15}
                                        stroke={ICON_STROKE}
                                    />
                                </button>
                            </div>
                        )}
                    </>
                )}
            </div>

            {/* Desktop detail — shared modal (photo/illustration hero) */}
            {!isMobile && detail && (
                <PlaceDetailModal
                    place={detail}
                    isMobile={false}
                    meta={placeMeta(detail)}
                    feedback={{
                        state: stateFor(detail.id) ?? detail.feedback_state,
                        rating: ratingFor(detail.id) ?? detail.feedback_rating,
                        onAction: (action, rating) =>
                            setFeedback(detail.id, action, rating),
                    }}
                    onClose={() => {
                        setDetail(null);
                        setHopStack([]);
                    }}
                    onNavigate={(target) => {
                        setDetail(null);
                        setHopStack([]);
                        setDestination(target);
                    }}
                    onOpenPlace={hopTo}
                    onBack={hopStack.length > 0 ? hopBack : undefined}
                    backLabel={hopStack.at(-1)?.name}
                />
            )}

            {/* Mobile full detail — shared modal renders a bottom sheet */}
            {isMobile && richPlace && (
                <PlaceDetailModal
                    place={richPlace}
                    isMobile
                    meta={placeMeta(richPlace)}
                    feedback={{
                        state:
                            stateFor(richPlace.id) ?? richPlace.feedback_state,
                        rating:
                            ratingFor(richPlace.id) ??
                            richPlace.feedback_rating,
                        onAction: (action, rating) =>
                            setFeedback(richPlace.id, action, rating),
                    }}
                    onClose={() => {
                        setRichPlace(null);
                        setHopStack([]);
                    }}
                    onNavigate={(target) => {
                        setRichPlace(null);
                        setHopStack([]);
                        setDestination(target);
                    }}
                    onOpenPlace={hopTo}
                    onBack={hopStack.length > 0 ? hopBack : undefined}
                    backLabel={hopStack.at(-1)?.name}
                />
            )}

            {destination && (
                <TakeMeThereSheet
                    destination={destination}
                    onClose={() => setDestination(null)}
                />
            )}

            <FeedbackToast message={toast} />
            <FeedbackToast message={locateError} />
        </AppLayout>
    );
}
