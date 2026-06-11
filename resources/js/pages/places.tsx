import { Head, Deferred, usePage } from '@inertiajs/react';
import { IconMap, IconChevronDown } from '@tabler/icons-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import {
    CategoryIllustration,
    categoryEmoji,
} from '@/components/places/category-illustration';
import { ContentCard } from '@/components/places/content-card';
import type { CardChip } from '@/components/places/content-card';
import { PlaceDetail } from '@/components/places/place-detail';
import type { Place, VeedelOption } from '@/components/places/types';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { ICON_STROKE } from '@/constants/icons';
import { useIsMobile } from '@/hooks/use-mobile';
import AppLayout from '@/layouts/app-layout';

const CATEGORIES: Array<{ id: string; label: string; emoji: string }> = [
    { id: 'park', label: 'Parks', emoji: '🌳' },
    { id: 'pitch', label: 'Pitches', emoji: '⚽' },
    { id: 'court', label: 'Courts', emoji: '🏀' },
    { id: 'swimming', label: 'Swimming', emoji: '🏊' },
    { id: 'playground', label: 'Playgrounds', emoji: '🛝' },
    { id: 'dog_park', label: 'Dog parks', emoji: '🐕' },
];

function placeChips(place: Place): CardChip[] {
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

    return chips;
}

function placeMeta(place: Place): string {
    const cat =
        place.fine_label ??
        CATEGORIES.find((c) => c.id === place.category)?.label ??
        'Place';

    return [
        cat,
        place.veedel,
        place.distance_min != null ? `${place.distance_min} min away` : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

function placeEmoji(place: Place): string {
    return place.emoji ?? categoryEmoji(place.category);
}

export default function Places() {
    const { homeVeedel, homeBezirk, filters, bezirke, veedelsByBezirk } =
        usePage<{
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
    const [destination, setDestination] = useState<Destination | null>(null);

    const isMobile = useIsMobile();
    const reqRef = useRef(0);

    const fetchPage = useCallback(
        (
            b: string,
            v: string | null,
            c: string | null,
            p: number,
            append: boolean,
        ) => {
            const token = ++reqRef.current;
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

                    const data: Place[] = json.data ?? [];
                    setPlaces((prev) => (append ? [...prev, ...data] : data));
                    setTotal(json.meta?.total ?? data.length);
                    setHasMore(
                        (json.meta?.current_page ?? p) <
                            (json.meta?.last_page ?? p),
                    );
                    setNearbyIncluded(json.nearby_included === true);
                    setPage(p);

                    if (!append) {
                        setExpandedId(null);
                    }

                    setStatus('ok');
                })
                .catch(() => {
                    if (token === reqRef.current) {
                        setStatus('error');
                    }
                });
        },
        [],
    );

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
        window.history.replaceState({}, '', `/explore${qs ? `?${qs}` : ''}`);
    }, [bezirk, veedel, category, fetchPage]);

    // Back/forward button re-reads the URL
    useEffect(() => {
        function onPop() {
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
        const next = page + 1;
        setPage(next);
        fetchPage(bezirk, veedel, category, next, true);
    }

    function pickBezirk(name: string) {
        setBezirk(name);
        setVeedel(null);
    }

    function openPlace(place: Place) {
        if (isMobile) {
            setExpandedId((id) => (id === place.id ? null : place.id));
        } else {
            setDetail(place);
        }
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
                            Parks, courts & places to be outside
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
                        <IconMap size={15} stroke={ICON_STROKE} /> Map view
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

                {/* Result count */}
                {status === 'ok' && view === 'list' && (
                    <div className="mb-3 font-mono text-[11px] tracking-[0.1em] text-muted-foreground uppercase">
                        {total} {total === 1 ? 'place' : 'places'} · {areaLabel}
                        {veedel !== null && nearbyIncluded && ' & nearby'}
                    </div>
                )}

                {/* Map view placeholder (out of scope) */}
                {view === 'map' ? (
                    <div className="flex h-[320px] flex-col items-center justify-center rounded-2xl border border-border bg-card text-center">
                        <IconMap
                            size={28}
                            stroke={1.6}
                            className="text-muted-foreground"
                        />
                        <p className="mt-3 text-sm text-muted-foreground">
                            Map view is coming soon.
                        </p>
                    </div>
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
                            {places.map((place) => (
                                <ContentCard
                                    key={place.id}
                                    variant="place"
                                    coarse={place.category}
                                    title={place.name}
                                    emoji={placeEmoji(place)}
                                    meta={placeMeta(place)}
                                    chips={placeChips(place)}
                                    tip={
                                        place.tip_is_generic ? null : place.tip
                                    }
                                    photoUrl={place.photo_url}
                                    expanded={
                                        isMobile && expandedId === place.id
                                    }
                                    onActivate={() => openPlace(place)}
                                    action={takeMeThereButton(place)}
                                >
                                    {isMobile ? (
                                        <div className="border-t border-border px-4 py-4">
                                            <PlaceDetail place={place} />
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

            {/* Desktop detail — traditional centered modal (close via overlay or X) */}
            <Dialog
                open={!isMobile && detail !== null}
                onOpenChange={(open) => !open && setDetail(null)}
            >
                {detail && (
                    <DialogContent className="gap-0 overflow-hidden p-0 sm:max-w-md">
                        <DialogTitle className="sr-only">
                            {detail.name}
                        </DialogTitle>
                        <CategoryIllustration
                            coarse={detail.category}
                            className="h-32 w-full"
                            iconSize={40}
                        />
                        <div className="p-5">
                            <div className="mb-3 flex items-start gap-2">
                                <span className="text-2xl leading-none">
                                    {placeEmoji(detail)}
                                </span>
                                <div className="min-w-0">
                                    <h2 className="font-display text-xl font-medium">
                                        {detail.name}
                                    </h2>
                                    <div className="text-[13px] text-muted-foreground">
                                        {placeMeta(detail)}
                                    </div>
                                </div>
                            </div>
                            <PlaceDetail place={detail} />
                            <button
                                onClick={() => {
                                    takeMeThere(detail);
                                    setDetail(null);
                                }}
                                className="mt-4 w-full rounded-[9px] bg-primary py-2.5 text-center text-[14px] font-semibold text-white transition-colors hover:bg-accent-hover"
                            >
                                → Take me there
                            </button>
                        </div>
                    </DialogContent>
                )}
            </Dialog>

            {destination && (
                <TakeMeThereSheet
                    destination={destination}
                    onClose={() => setDestination(null)}
                />
            )}
        </AppLayout>
    );
}
