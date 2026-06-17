import { IconMapPin, IconX } from '@tabler/icons-react';
import type { LngLatBoundsLike, Map as MaplibreMap } from 'maplibre-gl';
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
}: {
    places: Place[];
    emojiFor: (place: Place) => string;
    metaFor: (place: Place) => string;
    onOpen: (place: Place) => void;
    onTakeMeThere: (place: Place) => void;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<MaplibreMap | null>(null);
    const basemapRef = useRef<Basemap | null>(null);
    const pinElsRef = useRef<Map<number, HTMLElement>>(new Map());
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

        if (places.length > 0) {
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
                        stroke={1.6}
                        className="text-muted-foreground"
                    />
                    <p className="text-sm text-muted-foreground">
                        Couldn't load the map.
                    </p>
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
        </div>
    );
}

/** Build a teardrop pin element (rotated square + counter-rotated emoji). */
function makePin(emoji: string, label: string): HTMLElement {
    const el = document.createElement('div');
    el.setAttribute('role', 'button');
    el.setAttribute('tabindex', '0');
    el.setAttribute('aria-label', label);
    el.innerHTML = `<span>${emoji}</span>`;
    stylePin(el, false);

    return el;
}

/** Apply default or selected styling to a pin (themed via CSS vars). */
function stylePin(el: HTMLElement, isSelected: boolean): void {
    const size = isSelected ? 40 : 34;
    el.style.cssText = [
        `width:${size}px`,
        `height:${size}px`,
        'border-radius:100px 100px 100px 2px',
        'transform:rotate(45deg)',
        `background:${isSelected ? 'var(--accent)' : 'var(--card)'}`,
        'border:1.5px solid var(--accent)',
        'box-shadow:0 2px 6px rgba(24,23,15,.18)',
        'cursor:pointer',
        'display:flex',
        'align-items:center',
        'justify-content:center',
        `z-index:${isSelected ? 3 : 1}`,
        'transition:transform .12s, background .12s, width .12s, height .12s',
    ].join(';');

    const span = el.firstElementChild as HTMLElement | null;

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
