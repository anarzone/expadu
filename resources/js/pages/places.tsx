import { Head, Deferred, usePage } from '@inertiajs/react';
import {
    IconBike,
    IconBus,
    IconCheck,
    IconChevronDown,
    IconFilter,
    IconList,
    IconMap,
    IconMapPin,
    IconSortDescending,
    IconWalk,
    IconWorld,
} from '@tabler/icons-react';
import type { Icon as TablerIcon } from '@tabler/icons-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { categoryEmoji } from '@/components/places/category-illustration';
import { ContentCard } from '@/components/places/content-card';
import type { CardChip } from '@/components/places/content-card';
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

const PLACE_MODES: ReadonlyArray<{
    id: TransportMode;
    label: string;
    Icon: TablerIcon;
}> = [
    { id: 'walk', label: 'Walk', Icon: IconWalk },
    { id: 'transit', label: 'Transit', Icon: IconBus },
    { id: 'bike', label: 'Bike', Icon: IconBike },
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

function placeMetaMain(place: Place): string {
    const cat =
        place.fine_label ??
        CATEGORIES.find((c) => c.id === place.category)?.label ??
        'Place';

    // The park is the venue — a more meaningful address than the Stadtteil
    // when the facility sits inside one.
    return [cat, place.park ?? place.veedel].filter(Boolean).join(' · ');
}

function placeDistance(place: Place): string | null {
    return place.distance_min != null ? `${place.distance_min} min away` : null;
}

/**
 * Client-side reorder of the loaded results for the Sort control. "Nearest"
 * leans on distance, "Open now" floats open venues up (stable, so the server's
 * ranking holds within each group), "Popular" keeps the server ranking as-is.
 * A real, paginated sort is a server concern for the data pass.
 */
function sortPlaces(
    list: Place[],
    sortBy: 'Nearest' | 'Open now' | 'Popular',
): Place[] {
    const copy = [...list];

    if (sortBy === 'Nearest') {
        copy.sort(
            (a, b) =>
                (a.distance_min ?? Infinity) - (b.distance_min ?? Infinity),
        );
    } else if (sortBy === 'Open now') {
        copy.sort(
            (a, b) => Number(b.open_now === true) - Number(a.open_now === true),
        );
    }

    return copy;
}

function placeMeta(place: Place): string {
    return [placeMetaMain(place), placeDistance(place)]
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

    // v4 filter toolbar — the area picker, From popover and Sort menu are
    // pop-overs opened from the one toolbar line (replaces the photo-rail).
    const [areaPickOpen, setAreaPickOpen] = useState(false);
    const [fromPickOpen, setFromPickOpen] = useState(false);
    const [sortOpen, setSortOpen] = useState(false);
    const [sortBy, setSortBy] = useState<'Nearest' | 'Open now' | 'Popular'>(
        'Nearest',
    );

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
    // "Near me" was requested but we have no location fix yet.
    const [needsLocation, setNeedsLocation] = useState(false);

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

            // "Near me" → a radius around your location; else Stadtteil beats
            // Bezirk; neither means all of Cologne.
            if (b === 'near') {
                params.set('near', '1');
            } else if (v) {
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
                    setNeedsLocation(json.needs_location === true);
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

        // 'near' is a transient mode, not a shareable area — keep it out of the URL.
        if (bezirk && bezirk !== 'all' && bezirk !== 'near') {
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

    // "Near me" — browse a radius around your location. If we have no fix yet,
    // get one and confirm it first, then switch so the radius has an origin.
    function goNearMe() {
        setVeedel(null);

        const known =
            userLoc !== null ||
            (origin !== null &&
                (origin.source === 'live' ||
                    origin.source === 'confirmed' ||
                    origin.source === 'ping'));

        if (known) {
            setBezirk('near');

            return;
        }

        if (!('geolocation' in navigator)) {
            setLocateError("This browser can't share your location.");

            return;
        }

        setLocating(true);
        setLocateError(null);
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                const { latitude: lat, longitude: lng } = pos.coords;
                setUserLoc({ lat, lng });

                fetch('/api/location/confirm', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF(),
                    },
                    body: JSON.stringify({ lat, lng }),
                })
                    .then(() => setBezirk('near'))
                    .catch(() => setBezirk('near'))
                    .finally(() => setLocating(false));
            },
            (err) => {
                setLocating(false);
                setLocateError(
                    err.code === err.PERMISSION_DENIED
                        ? 'Location is blocked — allow it to see what’s near you.'
                        : "Couldn't get your location — try again in a moment.",
                );
            },
            { enableHighAccuracy: false, maximumAge: 120_000, timeout: 8000 },
        );
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
        bezirk === 'near'
            ? 'Near you'
            : (veedel ??
              (bezirk === 'all' ? 'All Cologne' : `Bezirk ${bezirk}`));
    const railOptions = bezirke ?? [];
    const chipOptions =
        bezirk !== 'all' ? (veedelsByBezirk?.[bezirk] ?? []) : [];

    // From pill — the resolved origin (live/confirmed/area) shown compactly,
    // plus the current travel-mode glyph.
    const originKnown = origin !== null && origin.source !== 'none';
    const fromLabel = locating
        ? 'Locating…'
        : !origin || origin.source === 'none'
          ? 'set location'
          : origin.source === 'area'
            ? (origin.label ?? 'this area')
            : (origin.label ?? 'You');
    const FromModeIcon = (
        PLACE_MODES.find((m) => m.id === mode) ?? PLACE_MODES[0]
    ).Icon;

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
                        <h1 className="font-display text-[30px] font-medium tracking-[-0.02em]">
                            Places
                        </h1>
                        <p className="mt-[3px] text-[13.5px] text-text-2">
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
                                className={`flex shrink-0 cursor-pointer items-center gap-1.5 rounded-full border px-3.5 py-2 text-[13px] transition-all ${
                                    on
                                        ? 'border-primary bg-primary-soft font-semibold text-primary'
                                        : 'border-border bg-card font-medium text-text-2 hover:border-primary hover:text-primary'
                                }`}
                            >
                                {c.emoji} {c.label}
                            </button>
                        );
                    })}
                </div>

                {/* v4 filter toolbar — area picker (where) · From + Sort (measure) */}
                <div className="relative z-20 mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    {/* Searching in {area} ▾ */}
                    <div className="relative">
                        <button
                            onClick={() => {
                                setAreaPickOpen((o) => !o);
                                setFromPickOpen(false);
                                setSortOpen(false);
                            }}
                            aria-expanded={areaPickOpen}
                            className="flex w-full items-center gap-2 rounded-full border border-border bg-card px-[15px] py-[9px] text-[13.5px] font-semibold text-foreground shadow-card transition-colors hover:border-primary sm:w-auto"
                        >
                            <IconFilter size={15} stroke={ICON_STROKE} />
                            <span className="font-medium text-text-2">
                                Searching in
                            </span>
                            {areaLabel}
                            <IconChevronDown
                                size={13}
                                stroke={2}
                                className="ml-auto sm:ml-0"
                            />
                        </button>

                        {areaPickOpen && (
                            <>
                                <div
                                    className="fixed inset-0 z-30"
                                    onClick={() => setAreaPickOpen(false)}
                                />
                                <div className="absolute top-12 left-0 z-40 w-[min(392px,calc(100vw-2rem))] rounded-[16px] border border-border bg-card p-4 shadow-[0_14px_40px_rgba(33,29,21,0.16)]">
                                    <div className="mb-[11px] font-mono text-[10.5px] tracking-[0.1em] text-text-3 uppercase">
                                        Search area
                                    </div>

                                    {/* Globals — All Cologne / Near me */}
                                    <div className="mb-[13px] flex gap-2 border-b border-border pb-[13px]">
                                        <button
                                            onClick={() => {
                                                pickBezirk('all');
                                                setAreaPickOpen(false);
                                            }}
                                            className={`inline-flex items-center gap-[7px] rounded-full border px-[15px] py-[9px] text-[13px] font-semibold transition-colors ${
                                                bezirk === 'all'
                                                    ? 'border-primary bg-primary text-white shadow-[0_2px_9px_rgba(255,57,2,0.3)]'
                                                    : 'border-border bg-surface-2 text-foreground hover:border-primary'
                                            }`}
                                        >
                                            <IconWorld
                                                size={14}
                                                stroke={ICON_STROKE}
                                            />
                                            All Cologne
                                        </button>
                                        <button
                                            onClick={() => {
                                                goNearMe();
                                                setAreaPickOpen(false);
                                            }}
                                            className={`inline-flex items-center gap-[7px] rounded-full border px-[15px] py-[9px] text-[13px] font-semibold transition-colors ${
                                                bezirk === 'near'
                                                    ? 'border-primary bg-primary text-white shadow-[0_2px_9px_rgba(255,57,2,0.3)]'
                                                    : 'border-border bg-surface-2 text-foreground hover:border-primary'
                                            }`}
                                        >
                                            <IconMapPin
                                                size={14}
                                                stroke={ICON_STROKE}
                                            />
                                            Near me
                                        </button>
                                    </div>

                                    {/* District chips */}
                                    <Deferred
                                        data="bezirke"
                                        fallback={
                                            <div className="flex gap-[7px]">
                                                {[1, 2, 3, 4, 5].map((i) => (
                                                    <div
                                                        key={i}
                                                        className="h-8 w-20 animate-pulse rounded-full bg-secondary"
                                                    />
                                                ))}
                                            </div>
                                        }
                                    >
                                        <div className="flex flex-wrap gap-[7px]">
                                            {railOptions.map((b) => {
                                                const on = bezirk === b.name;

                                                return (
                                                    <button
                                                        key={b.name}
                                                        onClick={() =>
                                                            pickBezirk(b.name)
                                                        }
                                                        className={`rounded-full border px-[13px] py-[7px] text-[12.5px] transition-colors ${
                                                            on
                                                                ? 'border-primary bg-primary-soft font-semibold text-primary'
                                                                : 'border-border bg-card font-medium text-text-2 hover:border-primary'
                                                        }`}
                                                    >
                                                        {b.name}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </Deferred>

                                    {/* Veedel sub-drill */}
                                    {chipOptions.length > 0 && (
                                        <div className="mt-[14px] border-t border-dashed border-border pt-[14px]">
                                            <div className="mb-[11px] font-mono text-[10.5px] tracking-[0.1em] text-text-3 uppercase">
                                                ↳ Neighbourhoods in {bezirk}
                                            </div>
                                            <div className="flex flex-wrap gap-[7px]">
                                                {[null, ...chipOptions].map(
                                                    (name) => {
                                                        const on =
                                                            veedel === name;

                                                        return (
                                                            <button
                                                                key={
                                                                    name ??
                                                                    'all'
                                                                }
                                                                data-veedel-chip={
                                                                    name ?? ''
                                                                }
                                                                onClick={() => {
                                                                    setVeedel(
                                                                        name,
                                                                    );
                                                                    setAreaPickOpen(
                                                                        false,
                                                                    );
                                                                }}
                                                                className={`rounded-full border px-[13px] py-[7px] text-[12.5px] transition-colors ${
                                                                    on
                                                                        ? 'border-primary bg-primary-soft font-semibold text-primary'
                                                                        : 'border-border bg-card font-medium text-text-2 hover:border-primary'
                                                                }`}
                                                            >
                                                                {name ??
                                                                    `All of ${bezirk}`}
                                                            </button>
                                                        );
                                                    },
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </>
                        )}
                    </div>

                    {/* From pill + Sort pill */}
                    <div className="flex flex-wrap items-center gap-2.5">
                        {/* From — cyan, "cyan locates" */}
                        <div className="relative">
                            <button
                                onClick={() => {
                                    setFromPickOpen((o) => !o);
                                    setAreaPickOpen(false);
                                    setSortOpen(false);
                                }}
                                className="inline-flex items-center gap-[7px] rounded-full border border-cyan-bd bg-card px-[14px] py-[9px] text-[13px] font-semibold text-cyan-h transition-colors hover:border-cyan"
                            >
                                <IconMapPin size={14} stroke={ICON_STROKE} />
                                <span className="font-medium text-[#7fb6c4]">
                                    from
                                </span>
                                {fromLabel}
                                <span className="text-[#9ccada]">·</span>
                                <FromModeIcon size={13} stroke={ICON_STROKE} />
                                <IconChevronDown size={12} stroke={2} />
                            </button>

                            {fromPickOpen && (
                                <>
                                    <div
                                        className="fixed inset-0 z-30"
                                        onClick={() => setFromPickOpen(false)}
                                    />
                                    <div className="absolute top-12 left-0 z-40 w-[min(260px,calc(100vw-2rem))] rounded-[16px] border border-cyan-bd bg-card p-[15px] shadow-[0_14px_40px_rgba(33,29,21,0.16)]">
                                        <div className="mb-2.5 font-mono text-[10px] tracking-[0.1em] text-text-3 uppercase">
                                            Measure distances from
                                        </div>
                                        <button
                                            onClick={() => {
                                                locate(true);
                                                setFromPickOpen(false);
                                            }}
                                            className={`inline-flex items-center gap-1.5 rounded-full border px-[11px] py-[7px] text-[12px] font-semibold transition-colors ${
                                                originKnown
                                                    ? 'border-cyan bg-cyan-soft text-cyan-h'
                                                    : 'border-border bg-card text-text-2 hover:border-cyan-bd hover:text-cyan-h'
                                            }`}
                                        >
                                            <IconMapPin
                                                size={13}
                                                stroke={ICON_STROKE}
                                            />
                                            {locating
                                                ? 'Locating…'
                                                : 'My location'}
                                        </button>

                                        <div className="my-[13px] h-px bg-border" />

                                        <div className="mb-2.5 font-mono text-[10px] tracking-[0.1em] text-text-3 uppercase">
                                            Travelling by
                                        </div>
                                        <div className="flex gap-1 rounded-full border border-border bg-surface-2 p-[3px]">
                                            {PLACE_MODES.map(
                                                ({ id, label, Icon }) => {
                                                    const on = mode === id;

                                                    return (
                                                        <button
                                                            key={id}
                                                            onClick={() =>
                                                                persistMode(id)
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
                                    setAreaPickOpen(false);
                                    setFromPickOpen(false);
                                }}
                                className="inline-flex items-center gap-[7px] rounded-full border border-border bg-card px-[14px] py-[9px] text-[13px] font-semibold text-foreground shadow-card transition-colors hover:border-primary"
                            >
                                <IconSortDescending
                                    size={13}
                                    stroke={ICON_STROKE}
                                />
                                {sortBy}
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
                                                'Nearest',
                                                'Open now',
                                                'Popular',
                                            ] as const
                                        ).map((opt) => {
                                            const on = sortBy === opt;

                                            return (
                                                <button
                                                    key={opt}
                                                    onClick={() => {
                                                        setSortBy(opt);
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

                {/* Result count */}
                {status === 'ok' && view === 'list' && (
                    <div className="mb-3 font-mono text-[11px] tracking-[0.1em] text-text-2 uppercase">
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
                ) : needsLocation ? (
                    <div className="rounded-2xl border border-border bg-card p-8 text-center">
                        <p className="text-sm text-muted-foreground">
                            Turn on location to see what’s near you.
                        </p>
                        <button
                            onClick={goNearMe}
                            className="mt-3 rounded-[9px] bg-primary px-4 py-2 text-[13px] font-semibold text-white"
                        >
                            Use my location
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
                            {sortPlaces(places, sortBy)
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
                                        meta={placeMetaMain(place)}
                                        distance={placeDistance(place)}
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
