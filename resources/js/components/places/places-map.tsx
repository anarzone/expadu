import { IconCurrentLocation, IconMapPin, IconX } from '@tabler/icons-react';
import type { LngLatBoundsLike, Map as MaplibreMap, Marker } from 'maplibre-gl';
import { useEffect, useRef, useState } from 'react';
import { ICON_STROKE } from '@/constants/icons';
import { COLOGNE_CENTER, loadBasemap } from '@/lib/basemap';
import type { Basemap } from '@/lib/basemap';
import type { Place } from './types';

/**
 * The Places "map view" — the current result set as accent pins on the
 * self-hosted Protomaps vector basemap (see {@link loadBasemap}). Tapping a
 * pin floats a card with a "Take me there" entry point; the basemap follows
 * the app's light/dark class.
 */
export function PlacesMap({
    places,
    emojiFor,
    metaFor,
    onOpen,
    onTakeMeThere,
    userLocation = null,
    onLocate,
    locating = false,
    flyToToken = 0,
    pickMode = false,
    onMapPick,
    onCancelPick,
}: {
    places: Place[];
    emojiFor: (place: Place) => string;
    metaFor: (place: Place) => string;
    onOpen: (place: Place) => void;
    onTakeMeThere: (place: Place) => void;
    userLocation?: { lat: number; lng: number } | null;
    onLocate?: () => void;
    locating?: boolean;
    flyToToken?: number;
    pickMode?: boolean;
    onMapPick?: (lat: number, lng: number) => void;
    onCancelPick?: () => void;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<MaplibreMap | null>(null);
    const basemapRef = useRef<Basemap | null>(null);
    const pinElsRef = useRef<Map<number, HTMLElement>>(new Map());
    const userMarkerRef = useRef<Marker | null>(null);
    // Set when an explicit "locate me" flies to the user, so the very next
    // pin rebuild (the distance refetch) doesn't yank the viewport back to a
    // fit-all of the places and undo the fly.
    const focusingRef = useRef(false);
    // Latest pick-mode state + handler, read by the map's click listener (which
    // is registered once on boot) so it never needs to re-subscribe.
    const pickRef = useRef<{
        mode: boolean;
        onPick?: (lat: number, lng: number) => void;
    }>({ mode: false });
    pickRef.current = { mode: pickMode, onPick: onMapPick };
    const [status, setStatus] = useState<'loading' | 'ready' | 'error'>(
        'loading',
    );
    const [selectedId, setSelectedId] = useState<number | null>(null);

    // Derived, not synced — a selection that's no longer in the visible
    // results simply resolves to null on the next render.
    const selected =
        selectedId != null
            ? (places.find((p) => p.id === selectedId) ?? null)
            : null;

    // Boot the map once. MapLibre, the pmtiles protocol and the theme load
    // lazily; the basemap then tracks the app's .dark class.
    useEffect(() => {
        let cancelled = false;
        let observer: MutationObserver | null = null;
        let resizeObserver: ResizeObserver | null = null;

        loadBasemap()
            .then((bm) => {
                if (cancelled || !containerRef.current) {
                    return;
                }

                basemapRef.current = bm;
                const dark =
                    document.documentElement.classList.contains('dark');
                const map = new bm.maplibregl.Map({
                    container: containerRef.current,
                    style: bm.style(dark),
                    center: COLOGNE_CENTER,
                    zoom: 11.2,
                    attributionControl: { compact: true },
                });
                map.addControl(
                    new bm.maplibregl.NavigationControl({ showCompass: false }),
                    'top-right',
                );
                mapRef.current = map;
                map.on('load', () => {
                    if (!cancelled) {
                        setStatus('ready');
                    }
                });

                // Pick-a-start-point: a tap on the map (not a pin) reports its
                // coordinates while pick mode is active. Pin taps stop their own
                // propagation, so they keep selecting the place.
                map.on('click', (e) => {
                    const { mode, onPick } = pickRef.current;

                    if (mode && onPick) {
                        onPick(e.lngLat.lat, e.lngLat.lng);
                    }
                });

                // Re-theme in place when the app toggles dark mode (the DOM
                // markers persist — they aren't part of the style).
                observer = new MutationObserver(() => {
                    map.setStyle(
                        bm.style(
                            document.documentElement.classList.contains('dark'),
                        ),
                    );
                });
                observer.observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['class'],
                });

                // The container can mount at 0-height behind the view toggle.
                resizeObserver = new ResizeObserver(() => map.resize());
                resizeObserver.observe(containerRef.current);
            })
            .catch(() => {
                if (!cancelled) {
                    setStatus('error');
                }
            });

        return () => {
            cancelled = true;
            observer?.disconnect();
            resizeObserver?.disconnect();
            // map.remove() tears down the markers' DOM with the container.
            mapRef.current?.remove();
            mapRef.current = null;
        };
    }, []);

    // (Re)build pins whenever the result set changes.
    useEffect(() => {
        const map = mapRef.current;
        const bm = basemapRef.current;

        if (!map || !bm || status !== 'ready') {
            return;
        }

        const pinEls = pinElsRef.current;
        const markers = places.map((place) => {
            const el = makePin(emojiFor(place), place.name);
            const onPick = () => setSelectedId(place.id);
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                onPick();
            });
            el.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onPick();
                }
            });
            pinEls.set(place.id, el);

            return new bm.maplibregl.Marker({ element: el })
                .setLngLat([place.lng, place.lat])
                .addTo(map);
        });

        if (places.length > 0 && focusingRef.current) {
            // Consume the flag once: keep the just-flown view, skip this fit.
            focusingRef.current = false;
        } else if (places.length > 0) {
            const bounds = new bm.maplibregl.LngLatBounds();
            places.forEach((p) => bounds.extend([p.lng, p.lat]));
            map.fitBounds(bounds as LngLatBoundsLike, {
                padding: 64,
                maxZoom: 14.5,
                duration: 0,
            });
        }

        return () => {
            markers.forEach((m) => m.remove());
            pinEls.clear();
        };
    }, [places, status, emojiFor]);

    // Reflect the selected pin and pan it clear of the card. Runs after the
    // pin-build effect, so it also re-applies selection across a rebuild.
    useEffect(() => {
        for (const [id, el] of pinElsRef.current) {
            stylePin(el, id === selectedId);
        }

        const sel =
            selectedId != null
                ? (places.find((p) => p.id === selectedId) ?? null)
                : null;

        if (sel) {
            mapRef.current?.easeTo({
                center: [sel.lng, sel.lat],
                offset: [0, -70],
                duration: 420,
            });
        }
    }, [selectedId, places, status]);

    // The "you are here" dot — its own persistent marker, untouched by the
    // pin rebuild above. Follows the latest fix; removed when there's none.
    useEffect(() => {
        const map = mapRef.current;
        const bm = basemapRef.current;

        if (!map || !bm || status !== 'ready') {
            return;
        }

        if (!userLocation) {
            userMarkerRef.current?.remove();
            userMarkerRef.current = null;

            return;
        }

        if (userMarkerRef.current) {
            userMarkerRef.current.setLngLat([
                userLocation.lng,
                userLocation.lat,
            ]);
        } else {
            userMarkerRef.current = new bm.maplibregl.Marker({
                element: makeUserDot(),
            })
                .setLngLat([userLocation.lng, userLocation.lat])
                .addTo(map);
        }
    }, [userLocation, status]);

    // Fly to the user only on an explicit "locate me" (the token bumps); a
    // silent auto-locate updates the dot without yanking the viewport.
    useEffect(() => {
        if (!flyToToken || !userLocation) {
            return;
        }

        // The distance refetch that follows would otherwise re-fit to all
        // places; flag it so the pin rebuild keeps this view instead.
        focusingRef.current = true;
        mapRef.current?.easeTo({
            center: [userLocation.lng, userLocation.lat],
            zoom: 13.8,
            duration: 600,
        });
        // Triggered by the token alone — userLocation is read fresh but must
        // not itself re-fire the fly (that's the silent-locate path).
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flyToToken]);

    // A crosshair cursor signals "tap to set your start point" while picking.
    useEffect(() => {
        const map = mapRef.current;

        if (!map || status !== 'ready') {
            return;
        }

        map.getCanvas().style.cursor = pickMode ? 'crosshair' : '';
    }, [pickMode, status]);

    return (
        <div className="relative h-[68vh] min-h-[440px] overflow-hidden rounded-2xl border border-border bg-secondary">
            <div ref={containerRef} className="h-full w-full" />

            {status === 'loading' && (
                <div className="absolute inset-0 animate-pulse bg-secondary" />
            )}

            {status === 'error' && (
                <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-card text-center">
                    <IconMapPin
                        size={28}
                        stroke={ICON_STROKE}
                        className="text-muted-foreground"
                    />
                    <p className="text-sm text-muted-foreground">
                        Couldn't load the map.
                    </p>
                </div>
            )}

            {pickMode && status === 'ready' && (
                <div className="absolute top-3 right-3 left-3 z-10 flex items-center justify-between gap-2 rounded-[12px] border border-cyan-bd bg-card px-3.5 py-2.5 shadow-lg">
                    <span className="flex items-center gap-2 text-[13px] font-medium text-cyan-h">
                        <IconMapPin size={16} stroke={ICON_STROKE} />
                        Tap the map to set your start point
                    </span>
                    {onCancelPick && (
                        <button
                            type="button"
                            onClick={onCancelPick}
                            className="shrink-0 rounded-full border border-border px-2.5 py-1 text-[12px] font-medium text-muted-foreground transition-colors hover:text-foreground"
                        >
                            Cancel
                        </button>
                    )}
                </div>
            )}

            {selected && (
                <div className="absolute right-3 bottom-3 left-3 z-10 flex animate-in items-center gap-3 rounded-[14px] border border-border bg-card p-3.5 shadow-lg duration-200 fade-in-0 slide-in-from-bottom-2 sm:left-auto sm:w-[380px]">
                    <div className="grid size-[46px] shrink-0 place-items-center rounded-xl bg-accent-soft text-[22px] text-primary">
                        {emojiFor(selected)}
                    </div>
                    <button
                        type="button"
                        onClick={() => onOpen(selected)}
                        className="min-w-0 flex-1 text-left"
                    >
                        <div className="truncate font-display text-[17px] font-medium tracking-tight">
                            {selected.name}
                        </div>
                        <div className="truncate text-xs text-muted-foreground">
                            {metaFor(selected)}
                        </div>
                    </button>
                    <button
                        type="button"
                        onClick={() => onTakeMeThere(selected)}
                        className="shrink-0 rounded-[9px] bg-accent-soft px-3 py-2 text-[13px] font-semibold text-primary transition-colors hover:bg-primary hover:text-white"
                    >
                        → Take me there
                    </button>
                    <button
                        type="button"
                        aria-label="Close"
                        onClick={() => setSelectedId(null)}
                        className="grid size-[30px] shrink-0 place-items-center self-start rounded-full border border-border text-muted-foreground hover:text-foreground"
                    >
                        <IconX size={15} stroke={ICON_STROKE} />
                    </button>
                </div>
            )}

            {/* Locate me — hidden while a place card is up (it sits there). */}
            {onLocate && status === 'ready' && !selected && (
                <button
                    type="button"
                    onClick={onLocate}
                    disabled={locating}
                    aria-label="Show my location"
                    className="absolute bottom-3 left-3 z-10 grid size-11 place-items-center rounded-full border border-border bg-card text-primary shadow-lg transition-colors hover:bg-accent-soft disabled:opacity-60"
                >
                    <IconCurrentLocation
                        size={20}
                        stroke={ICON_STROKE}
                        className={locating ? 'animate-pulse' : ''}
                    />
                </button>
            )}
        </div>
    );
}

/** The "you are here" dot — accent core, white ring, soft halo. */
function makeUserDot(): HTMLElement {
    const el = document.createElement('div');
    el.setAttribute('aria-label', 'Your location');
    el.style.cssText = [
        'width:16px',
        'height:16px',
        'border-radius:50%',
        'background:var(--accent, #1A4CD4)',
        'border:3px solid #fff',
        'box-shadow:0 0 0 4px rgba(26,76,212,.25), 0 1px 4px rgba(24,23,15,.35)',
    ].join(';');

    return el;
}

/**
 * Build a teardrop pin. MapLibre positions a marker by rewriting the element's
 * own `transform` on every frame, so the element it owns — the OUTER wrapper
 * here — must carry no transform and no transform transition; otherwise each
 * pan frame animates and the pin visibly slides/lags behind the map. The
 * rotated teardrop and its counter-rotated emoji live on an inner child that
 * MapLibre never touches.
 */
function makePin(emoji: string, label: string): HTMLElement {
    const el = document.createElement('div');
    el.setAttribute('role', 'button');
    el.setAttribute('tabindex', '0');
    el.setAttribute('aria-label', label);
    el.style.cssText = 'cursor:pointer;will-change:transform';

    const teardrop = document.createElement('div');
    teardrop.innerHTML = `<span>${emoji}</span>`;
    el.appendChild(teardrop);

    stylePin(el, false);

    return el;
}

/**
 * Style a pin's inner teardrop (themed via CSS vars). Nothing here touches the
 * outer element's transform — that is MapLibre's, for positioning — so only the
 * selected pin's stacking is set on the outer element.
 */
function stylePin(el: HTMLElement, isSelected: boolean): void {
    const teardrop = el.firstElementChild as HTMLElement | null;

    if (!teardrop) {
        return;
    }

    const size = isSelected ? 40 : 34;
    teardrop.style.cssText = [
        `width:${size}px`,
        `height:${size}px`,
        'border-radius:100px 100px 100px 2px',
        'transform:rotate(45deg)',
        `background:${isSelected ? 'var(--accent)' : 'var(--card)'}`,
        'border:1.5px solid var(--accent)',
        'box-shadow:0 2px 6px rgba(24,23,15,.18)',
        'display:flex',
        'align-items:center',
        'justify-content:center',
        // Only the selection change animates — never a positional transform
        // (that lives on the MapLibre-owned outer element; transitioning it is
        // what made pins slide while panning).
        'transition:background .12s, width .12s, height .12s',
    ].join(';');

    el.style.zIndex = isSelected ? '3' : '1';

    const span = teardrop.firstElementChild as HTMLElement | null;

    if (span) {
        span.style.cssText = [
            'transform:rotate(-45deg)',
            `font-size:${isSelected ? 17 : 15}px`,
            'line-height:1',
            isSelected ? 'filter:grayscale(1) brightness(3)' : '',
        ]
            .filter(Boolean)
            .join(';');
    }
}
