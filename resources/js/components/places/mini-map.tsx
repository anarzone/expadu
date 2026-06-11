import { useEffect, useRef, useState } from 'react';

/**
 * Static mini-map snippet for the place detail — answers "where exactly
 * is it?" without a full map view. Non-interactive; the whole snippet is
 * a tap target (wired by the parent, usually take-me-there).
 *
 * MapLibre is loaded lazily so the Places bundle stays slim; until (or
 * if ever) it fails, a quiet tinted placeholder keeps the layout stable.
 */
export function MiniMap({
    lat,
    lng,
    className = '',
    onActivate,
}: {
    lat: number;
    lng: number;
    className?: string;
    onActivate?: () => void;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let map: { remove: () => void } | null = null;
        let cancelled = false;

        Promise.all([
            import('maplibre-gl'),
            // The stylesheet ships with the chunk; required for canvas sizing
            import('maplibre-gl/dist/maplibre-gl.css'),
        ])
            .then(([maplibre]) => {
                if (cancelled || !containerRef.current) {
                    return;
                }

                map = new maplibre.Map({
                    container: containerRef.current,
                    style: {
                        version: 8,
                        sources: {
                            osm: {
                                type: 'raster',
                                tiles: [
                                    'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                                ],
                                tileSize: 256,
                                attribution: '© OpenStreetMap',
                            },
                        },
                        layers: [{ id: 'osm', type: 'raster', source: 'osm' }],
                    },
                    center: [lng, lat],
                    zoom: 15,
                    interactive: false,
                    attributionControl: false,
                });
            })
            .catch(() => {
                if (!cancelled) {
                    setFailed(true);
                }
            });

        return () => {
            cancelled = true;
            map?.remove();
        };
    }, [lat, lng]);

    return (
        <div
            role={onActivate ? 'button' : undefined}
            tabIndex={onActivate ? 0 : undefined}
            onClick={onActivate}
            onKeyDown={(e) => {
                if (onActivate && (e.key === 'Enter' || e.key === ' ')) {
                    e.preventDefault();
                    onActivate();
                }
            }}
            className={`relative overflow-hidden rounded-[9px] border border-border bg-secondary ${
                onActivate ? 'cursor-pointer' : ''
            } ${className}`}
            aria-label="Show on map"
        >
            {/* Explicit size — MapLibre's own CSS forces position:relative
                on the container, so absolute-inset sizing collapses to 0. */}
            {!failed && <div ref={containerRef} className="h-full w-full" />}

            {/* Centre pin overlay — cheaper and crisper than a map marker */}
            <span className="pointer-events-none absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-[90%] text-2xl drop-shadow-md">
                📍
            </span>

            {onActivate && (
                <span className="pointer-events-none absolute right-2 bottom-2 rounded-full border border-border bg-card px-2 py-1 font-mono text-[10px] tracking-[0.08em] text-muted-foreground uppercase">
                    tap to navigate
                </span>
            )}
        </div>
    );
}
