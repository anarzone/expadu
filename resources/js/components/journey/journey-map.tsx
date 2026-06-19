import type {
    GeoJSONSource,
    LngLatBoundsLike,
    Map as MaplibreMap,
} from 'maplibre-gl';
import { useEffect, useRef, useState } from 'react';
import { loadBasemap } from '@/lib/basemap';
import type { Basemap } from '@/lib/basemap';

type Leg = {
    mode: string;
    line: string | null;
    polyline: string | null;
};

type Point = { lat: number; lng: number; name?: string };

/**
 * The "v2" half of take-me-there: the planned journey drawn on the self-hosted
 * vector basemap. MOTIS returns an encoded `polyline` per leg (precision 7);
 * we decode it client-side and draw walk legs dashed-grey, transit legs in
 * their mode colour — no extra request, the geometry already ships with the
 * journey response.
 */
export function JourneyMap({
    legs,
    origin,
    destination,
}: {
    legs: Leg[];
    origin: Point | null;
    destination: Point;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<MaplibreMap | null>(null);
    const geojsonRef = useRef<RouteGeoJson>({
        type: 'FeatureCollection',
        features: [],
    });
    const [status, setStatus] = useState<'loading' | 'ready' | 'error'>(
        'loading',
    );

    useEffect(() => {
        let cancelled = false;
        let observer: MutationObserver | null = null;
        let resizeObserver: ResizeObserver | null = null;

        loadBasemap()
            .then((bm) => {
                if (cancelled || !containerRef.current) {
                    return;
                }

                const dark = isDark();
                geojsonRef.current = buildGeoJson(legs, dark);

                const map = new bm.maplibregl.Map({
                    container: containerRef.current,
                    style: bm.style(dark),
                    center: [destination.lng, destination.lat],
                    zoom: 12,
                    attributionControl: { compact: true },
                });
                mapRef.current = map;

                // Style layers are wiped by setStyle, so (re)apply on every
                // style load — DOM markers below persist on their own.
                const applyRoute = () => applyJourney(map, geojsonRef.current);
                map.on('style.load', applyRoute);

                map.on('load', () => {
                    if (cancelled) {
                        return;
                    }

                    addEndpointMarkers(bm, map, origin, destination);
                    fitToJourney(
                        bm,
                        map,
                        geojsonRef.current,
                        origin,
                        destination,
                    );
                    setStatus('ready');
                });

                // Follow the app's dark class: rebuild the geometry colours and
                // re-theme the basemap; applyRoute re-runs via style.load.
                observer = new MutationObserver(() => {
                    geojsonRef.current = buildGeoJson(legs, isDark());
                    map.setStyle(bm.style(isDark()));
                });
                observer.observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['class'],
                });

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
            mapRef.current?.remove();
            mapRef.current = null;
        };
        // Built once per journey; legs/origin/destination are stable for a
        // given destination + plan.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className="relative h-[230px] overflow-hidden rounded-[14px] border border-border bg-secondary">
            <div ref={containerRef} className="h-full w-full" />

            {status === 'loading' && (
                <div className="absolute inset-0 animate-pulse bg-secondary" />
            )}

            {status === 'error' && (
                <div className="absolute inset-0 grid place-items-center bg-card px-4 text-center text-[13px] text-muted-foreground">
                    Map unavailable — the step-by-step route is in the List tab.
                </div>
            )}

            {status === 'ready' && (
                <div className="pointer-events-none absolute bottom-2.5 left-2.5 flex gap-2.5 rounded-full border border-border bg-card/90 px-2.5 py-1 text-[11px] text-muted-foreground backdrop-blur">
                    <Key color="var(--map-walk, #AAA89F)" label="Walk" />
                    <Key color="#D8232A" label="Tram" />
                    <Key color="#6B4FBB" label="Bus" />
                    <Key color="#0A7C52" label="Rail" />
                </div>
            )}
        </div>
    );
}

function Key({ color, label }: { color: string; label: string }) {
    return (
        <span className="flex items-center gap-1">
            <i
                className="inline-block h-[3px] w-3 rounded"
                style={{ background: color }}
            />
            {label}
        </span>
    );
}

function isDark(): boolean {
    return document.documentElement.classList.contains('dark');
}

const MODE_COLOR: Record<string, { light: string; dark: string }> = {
    walk: { light: '#AAA89F', dark: '#6B6860' },
    bike: { light: '#AAA89F', dark: '#6B6860' },
    tram: { light: '#D8232A', dark: '#F0726C' },
    subway: { light: '#0A7C52', dark: '#34D399' },
    rail: { light: '#0A7C52', dark: '#34D399' },
    bus: { light: '#6B4FBB', dark: '#A892F0' },
    ferry: { light: '#1A4CD4', dark: '#5B8AF5' },
};

type RouteGeoJson = {
    type: 'FeatureCollection';
    features: Array<{
        type: 'Feature';
        properties: { mode: string; color: string };
        geometry: { type: 'LineString'; coordinates: [number, number][] };
    }>;
};

function buildGeoJson(legs: Leg[], dark: boolean): RouteGeoJson {
    return {
        type: 'FeatureCollection',
        features: legs
            .filter((leg) => leg.polyline)
            .map((leg) => {
                const palette = MODE_COLOR[leg.mode] ?? MODE_COLOR.walk;

                return {
                    type: 'Feature' as const,
                    properties: {
                        mode: leg.mode,
                        color: dark ? palette.dark : palette.light,
                    },
                    geometry: {
                        type: 'LineString' as const,
                        coordinates: decodePolyline(leg.polyline as string),
                    },
                };
            }),
    };
}

/** Add (or refresh) the route source + walk/transit line layers. */
function applyJourney(map: MaplibreMap, data: RouteGeoJson): void {
    const existing = map.getSource('journey') as GeoJSONSource | undefined;

    if (existing) {
        existing.setData(data);

        return;
    }

    map.addSource('journey', { type: 'geojson', data });

    // Transit legs: solid, mode-coloured, drawn under the dashed walk legs.
    map.addLayer({
        id: 'journey-transit',
        type: 'line',
        source: 'journey',
        filter: ['!', ['in', ['get', 'mode'], ['literal', ['walk', 'bike']]]],
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': ['get', 'color'], 'line-width': 4.5 },
    });

    map.addLayer({
        id: 'journey-walk',
        type: 'line',
        source: 'journey',
        filter: ['in', ['get', 'mode'], ['literal', ['walk', 'bike']]],
        layout: { 'line-cap': 'round' },
        paint: {
            'line-color': ['get', 'color'],
            'line-width': 3,
            'line-dasharray': [1.5, 2.5],
        },
    });
}

function addEndpointMarkers(
    bm: Basemap,
    map: MaplibreMap,
    origin: Point | null,
    destination: Point,
): void {
    if (origin) {
        const start = document.createElement('div');
        start.style.cssText = dotStyle('var(--success, #0A7C52)');
        new bm.maplibregl.Marker({ element: start })
            .setLngLat([origin.lng, origin.lat])
            .addTo(map);
    }

    const end = document.createElement('div');
    end.style.cssText = dotStyle('var(--accent, #1A4CD4)', 13);
    new bm.maplibregl.Marker({ element: end })
        .setLngLat([destination.lng, destination.lat])
        .addTo(map);
}

function dotStyle(bg: string, size = 11): string {
    return [
        `width:${size}px`,
        `height:${size}px`,
        'border-radius:100px',
        `background:${bg}`,
        'border:2.5px solid var(--card, #fff)',
        'box-shadow:0 1px 4px rgba(24,23,15,.25)',
    ].join(';');
}

function fitToJourney(
    bm: Basemap,
    map: MaplibreMap,
    data: RouteGeoJson,
    origin: Point | null,
    destination: Point,
): void {
    const bounds = new bm.maplibregl.LngLatBounds();
    let any = false;

    for (const feature of data.features) {
        for (const coord of feature.geometry.coordinates) {
            bounds.extend(coord);
            any = true;
        }
    }

    if (origin) {
        bounds.extend([origin.lng, origin.lat]);
        any = true;
    }

    bounds.extend([destination.lng, destination.lat]);
    any = true;

    if (any) {
        map.fitBounds(bounds as LngLatBoundsLike, {
            padding: { top: 40, bottom: 48, left: 40, right: 40 },
            maxZoom: 15,
            duration: 0,
        });
    }
}

/**
 * Decode a MOTIS/OTP encoded polyline (precision 7) into `[lng, lat]` pairs
 * for MapLibre. Standard Google-polyline algorithm; precision verified against
 * live MOTIS output (first vertex lands on the journey origin).
 */
function decodePolyline(str: string): [number, number][] {
    const coords: [number, number][] = [];
    const factor = 1e7;
    let index = 0;
    let lat = 0;
    let lng = 0;

    while (index < str.length) {
        let result = 0;
        let shift = 0;
        let byte: number;

        do {
            byte = str.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20);

        lat += result & 1 ? ~(result >> 1) : result >> 1;

        result = 0;
        shift = 0;

        do {
            byte = str.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20);

        lng += result & 1 ? ~(result >> 1) : result >> 1;

        coords.push([lng / factor, lat / factor]);
    }

    return coords;
}
