import {
    IconBeer,
    IconBook,
    IconBread,
    IconBuilding,
    IconBurger,
    IconCoffee,
    IconMapPin,
    IconToolsKitchen2,
    IconTree,
} from '@tabler/icons-react';
import maplibregl from 'maplibre-gl';
import {
    createElement,
    forwardRef,
    useEffect,
    useImperativeHandle,
    useRef,
} from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { decodePolyline } from '@/utils/polyline';
import 'maplibre-gl/dist/maplibre-gl.css';

type SpotPin = {
    id: number;
    name: string;
    category: string;
    lat: number;
    lng: number;
};

type PersonalPlace = {
    id: number;
    emoji: string;
    name: string;
    address: string | null;
    lat: number | null;
    lng: number | null;
};

export type MapViewHandle = {
    flyTo: (lat: number, lng: number, zoom?: number) => void;
    addSearchPin: (lat: number, lng: number, label: string) => void;
    clearSearchPin: () => void;
    locateUser: () => void;
    drawRoute: (
        geometry: string,
        mode: string,
        origin: { lat: number; lng: number },
        destination: { lat: number; lng: number },
    ) => void;
    drawTransitRoute: (
        segments: Array<{
            type: string;
            geometry?: string;
            coordinates?: [number, number][];
            color?: string;
            line?: string;
        }>,
        origin: { lat: number; lng: number },
        destination: { lat: number; lng: number },
    ) => void;
    clearRoute: () => void;
};

type CategoryConfig = {
    icon: React.ComponentType<{
        size?: number;
        strokeWidth?: number;
        color?: string;
    }>;
    bg: string;
    color: string;
    selectedBg: string;
};

const CATEGORY_CONFIG: Record<string, CategoryConfig> = {
    cafe: {
        icon: IconCoffee,
        bg: '#FEF3C7',
        color: '#92400E',
        selectedBg: '#D97706',
    },
    coworking: {
        icon: IconBuilding,
        bg: '#DBEAFE',
        color: '#1E3A8A',
        selectedBg: '#2563EB',
    },
    library: {
        icon: IconBook,
        bg: '#D1FAE5',
        color: '#065F46',
        selectedBg: '#059669',
    },
    park: {
        icon: IconTree,
        bg: '#DCFCE7',
        color: '#166534',
        selectedBg: '#16A34A',
    },
    restaurant: {
        icon: IconToolsKitchen2,
        bg: '#FEE2E2',
        color: '#991B1B',
        selectedBg: '#DC2626',
    },
    fast_food: {
        icon: IconBurger,
        bg: '#FEF3C7',
        color: '#92400E',
        selectedBg: '#F59E0B',
    },
    bar: {
        icon: IconBeer,
        bg: '#EDE9FE',
        color: '#5B21B6',
        selectedBg: '#7C3AED',
    },
    bakery: {
        icon: IconBread,
        bg: '#FFF7ED',
        color: '#9A3412',
        selectedBg: '#EA580C',
    },
};

const DEFAULT_CATEGORY: CategoryConfig = {
    icon: IconMapPin,
    bg: '#F3E8FF',
    color: '#6B21A8',
    selectedBg: '#9333EA',
};

function makeIconSvg(
    IconCmp: React.ComponentType<{
        size?: number;
        strokeWidth?: number;
        color?: string;
    }>,
    color: string,
    size = 16,
): string {
    return renderToStaticMarkup(
        createElement(IconCmp, { size, strokeWidth: 2, color }),
    );
}

export type MapBounds = {
    sw_lat: number;
    sw_lng: number;
    ne_lat: number;
    ne_lng: number;
};

export const MapView = forwardRef<
    MapViewHandle,
    {
        spots: SpotPin[];
        personalPlaces?: PersonalPlace[];
        selectedId: number | null;
        onSelectSpot: (id: number) => void;
        onMapTap?: (lat: number, lng: number) => void;
        onBoundsChange?: (bounds: MapBounds) => void;
        onSearchPinClick?: (lat: number, lng: number, label: string) => void;
        hideMarkers?: boolean;
    }
>(function MapView(
    {
        spots,
        personalPlaces = [],
        selectedId,
        onSelectSpot,
        onMapTap,
        onBoundsChange,
        onSearchPinClick,
        hideMarkers = false,
    },
    ref,
) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const markersRef = useRef<maplibregl.Marker[]>([]);
    const placeMarkersRef = useRef<maplibregl.Marker[]>([]);
    const searchPinRef = useRef<maplibregl.Marker | null>(null);
    const geolocateRef = useRef<maplibregl.GeolocateControl | null>(null);
    const routeMarkersRef = useRef<maplibregl.Marker[]>([]);
    const lastFlyToId = useRef<number | null>(null);

    // Keep callbacks fresh via refs to avoid recreating the map
    const onMapTapRef = useRef(onMapTap);
    const onBoundsChangeRef = useRef(onBoundsChange);
    const onSearchPinClickRef = useRef(onSearchPinClick);
    useEffect(() => {
        onMapTapRef.current = onMapTap;
        onBoundsChangeRef.current = onBoundsChange;
        onSearchPinClickRef.current = onSearchPinClick;
    }, [onMapTap, onSearchPinClick]);

    useImperativeHandle(ref, () => ({
        flyTo(lat: number, lng: number, zoom = 15) {
            mapRef.current?.flyTo({ center: [lng, lat], zoom, duration: 1200 });
        },
        addSearchPin(lat: number, lng: number, label: string) {
            searchPinRef.current?.remove();
            const map = mapRef.current;

            if (!map) {
                return;
            }

            const el = document.createElement('div');
            el.style.cssText = `
                display: flex; align-items: center; gap: 4px;
                padding: 6px 12px; border-radius: 20px;
                font-size: 13px; font-weight: 600; color: white;
                background: #C4271A;
                box-shadow: 0 2px 12px rgba(196,39,26,0.4);
                white-space: nowrap; cursor: pointer;
            `;
            el.innerHTML = `<span style="font-size:14px">📍</span><span>${label}</span>`;
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                onSearchPinClickRef.current?.(lat, lng, label);
            });

            searchPinRef.current = new maplibregl.Marker({
                element: el,
                anchor: 'bottom',
            })
                .setLngLat([lng, lat])
                .addTo(map);

            map.flyTo({ center: [lng, lat], zoom: 16, duration: 1200 });
        },
        clearSearchPin() {
            searchPinRef.current?.remove();
            searchPinRef.current = null;
        },
        locateUser() {
            geolocateRef.current?.trigger();
        },
        drawRoute(
            geometry: string,
            mode: string,
            origin: { lat: number; lng: number },
            destination: { lat: number; lng: number },
        ) {
            const map = mapRef.current;

            if (!map || !map.isStyleLoaded()) {
                return;
            }

            // Clear previous route
            this.clearRoute();

            const coordinates = geometry ? decodePolyline(geometry, 6) : [];

            // Fall back to straight line if no geometry (e.g. transit)
            if (coordinates.length === 0) {
                coordinates.push(
                    [origin.lng, origin.lat],
                    [destination.lng, destination.lat],
                );
            }

            const isWalk = mode === 'walk' || mode === 'pedestrian';
            const isDrive = mode === 'drive' || mode === 'auto';
            const color = isDrive ? '#6B6860' : '#1A4CD4';

            // Add route source + layers
            map.addSource('explore-route', {
                type: 'geojson',
                data: {
                    type: 'Feature',
                    properties: {},
                    geometry: { type: 'LineString', coordinates },
                },
            });

            map.addLayer({
                id: 'explore-route-bg',
                type: 'line',
                source: 'explore-route',
                layout: { 'line-join': 'round', 'line-cap': 'round' },
                paint: {
                    'line-color': color,
                    'line-width': 7,
                    'line-opacity': 0.2,
                },
            });

            map.addLayer({
                id: 'explore-route-line',
                type: 'line',
                source: 'explore-route',
                layout: { 'line-join': 'round', 'line-cap': 'round' },
                paint: {
                    'line-color': color,
                    'line-width': 4,
                    ...(isWalk ? { 'line-dasharray': [2, 4] } : {}),
                },
            });

            // Origin marker (green)
            const originEl = document.createElement('div');
            originEl.style.cssText =
                'display:flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#0A7C52;box-shadow:0 2px 8px rgba(0,0,0,0.25);font-size:14px;line-height:1;';
            originEl.textContent = '\uD83D\uDCCD';
            const originMarker = new maplibregl.Marker({
                element: originEl,
                anchor: 'center',
            })
                .setLngLat([origin.lng, origin.lat])
                .addTo(map);

            // Destination marker (red)
            const destEl = document.createElement('div');
            destEl.style.cssText =
                'display:flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#C4271A;box-shadow:0 2px 8px rgba(0,0,0,0.25);font-size:14px;line-height:1;';
            destEl.textContent = '\uD83C\uDFC1';
            const destMarker = new maplibregl.Marker({
                element: destEl,
                anchor: 'center',
            })
                .setLngLat([destination.lng, destination.lat])
                .addTo(map);

            routeMarkersRef.current = [originMarker, destMarker];

            // Fit bounds to route
            const bounds = new maplibregl.LngLatBounds();

            for (const coord of coordinates) {
                bounds.extend(coord as [number, number]);
            }

            (map as any)._skipBounds?.(true);
            map.fitBounds(bounds, {
                padding: { top: 60, bottom: 200, left: 60, right: 60 },
                maxZoom: 16,
                duration: 800,
            });
        },
        drawTransitRoute(
            segments: Array<{
                type: string;
                geometry?: string;
                coordinates?: [number, number][];
                color?: string;
                line?: string;
            }>,
            origin: { lat: number; lng: number },
            destination: { lat: number; lng: number },
        ) {
            const map = mapRef.current;

            if (!map || !map.isStyleLoaded()) {
                return;
            }

            this.clearRoute();

            const allBoundsCoords: [number, number][] = [
                [origin.lng, origin.lat],
                [destination.lng, destination.lat],
            ];

            segments.forEach((seg, i) => {
                const srcId = `transit-seg-${i}`;
                let coords: [number, number][] = [];

                if (seg.type === 'walk' && seg.geometry) {
                    coords = decodePolyline(seg.geometry, 6);
                } else if (seg.coordinates && seg.coordinates.length >= 2) {
                    coords = seg.coordinates;
                }

                if (coords.length < 2) {
                    return;
                }

                allBoundsCoords.push(...coords);

                map.addSource(srcId, {
                    type: 'geojson',
                    data: {
                        type: 'Feature',
                        properties: {},
                        geometry: { type: 'LineString', coordinates: coords },
                    },
                });

                const isWalk = seg.type === 'walk';
                const color =
                    seg.type === 'transit'
                        ? (seg.color ?? '#1A4CD4')
                        : '#1A4CD4';

                map.addLayer({
                    id: `${srcId}-bg`,
                    type: 'line',
                    source: srcId,
                    layout: { 'line-join': 'round', 'line-cap': 'round' },
                    paint: {
                        'line-color': color,
                        'line-width': isWalk ? 5 : 7,
                        'line-opacity': 0.2,
                    },
                });
                map.addLayer({
                    id: `${srcId}-line`,
                    type: 'line',
                    source: srcId,
                    layout: { 'line-join': 'round', 'line-cap': 'round' },
                    paint: {
                        'line-color': color,
                        'line-width': isWalk ? 3 : 5,
                        ...(isWalk ? { 'line-dasharray': [2, 4] } : {}),
                    },
                });

                // Transit stop markers (boarding + alighting)
                if (seg.type === 'transit' && coords.length >= 2) {
                    const boardEl = document.createElement('div');
                    boardEl.style.cssText = `display:flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:${color};box-shadow:0 2px 6px rgba(0,0,0,0.3);font-size:9px;font-weight:700;color:white;font-family:'Geist Mono',monospace;`;
                    boardEl.textContent = seg.line ?? '🚋';
                    routeMarkersRef.current.push(
                        new maplibregl.Marker({
                            element: boardEl,
                            anchor: 'center',
                        })
                            .setLngLat(coords[0])
                            .addTo(map),
                    );

                    const alightEl = document.createElement('div');
                    alightEl.style.cssText = boardEl.style.cssText;
                    alightEl.textContent = seg.line ?? '🚋';
                    routeMarkersRef.current.push(
                        new maplibregl.Marker({
                            element: alightEl,
                            anchor: 'center',
                        })
                            .setLngLat(coords[coords.length - 1])
                            .addTo(map),
                    );
                }
            });

            // Origin + destination markers
            const originEl = document.createElement('div');
            originEl.style.cssText =
                'display:flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#0A7C52;box-shadow:0 2px 8px rgba(0,0,0,0.25);font-size:14px;line-height:1;';
            originEl.textContent = '\uD83D\uDCCD';
            routeMarkersRef.current.push(
                new maplibregl.Marker({ element: originEl, anchor: 'center' })
                    .setLngLat([origin.lng, origin.lat])
                    .addTo(map),
            );

            const destEl = document.createElement('div');
            destEl.style.cssText =
                'display:flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#C4271A;box-shadow:0 2px 8px rgba(0,0,0,0.25);font-size:14px;line-height:1;';
            destEl.textContent = '\uD83C\uDFC1';
            routeMarkersRef.current.push(
                new maplibregl.Marker({ element: destEl, anchor: 'center' })
                    .setLngLat([destination.lng, destination.lat])
                    .addTo(map),
            );

            // Fit bounds
            const bounds = new maplibregl.LngLatBounds();

            for (const c of allBoundsCoords) {
                bounds.extend(c);
            }

            (map as any)._skipBounds?.(true);
            map.fitBounds(bounds, {
                padding: { top: 60, bottom: 200, left: 60, right: 60 },
                maxZoom: 15,
                duration: 800,
            });
        },
        clearRoute() {
            const map = mapRef.current;

            if (!map || !map.isStyleLoaded()) {
                return;
            }

            routeMarkersRef.current.forEach((m) => m.remove());
            routeMarkersRef.current = [];

            try {
                // Single route layers
                if (map.getLayer('explore-route-line')) {
                    map.removeLayer('explore-route-line');
                }

                if (map.getLayer('explore-route-bg')) {
                    map.removeLayer('explore-route-bg');
                }

                if (map.getSource('explore-route')) {
                    map.removeSource('explore-route');
                }

                // Multi-segment transit layers
                for (let i = 0; i < 10; i++) {
                    const id = `transit-seg-${i}`;

                    if (map.getLayer(`${id}-line`)) {
                        map.removeLayer(`${id}-line`);
                    }

                    if (map.getLayer(`${id}-bg`)) {
                        map.removeLayer(`${id}-bg`);
                    }

                    if (map.getSource(id)) {
                        map.removeSource(id);
                    }
                }
            } catch {
                /* layers may not exist */
            }
        },
    }));

    // Initialize map — fetch style first to patch missing projection field (MapLibre v5 bug)
    useEffect(() => {
        if (!containerRef.current || mapRef.current) {
            return;
        }

        let cancelled = false;

        fetch('https://tiles.openfreemap.org/styles/bright')
            .then((r) => r.json())
            .then((style) => {
                if (cancelled || !containerRef.current) {
                    return;
                }

                if (!style.projection) {
                    style.projection = { type: 'mercator' };
                }

                const map = new maplibregl.Map({
                    container: containerRef.current!,
                    style,
                    center: [6.9603, 50.9375],
                    zoom: 13,
                    attributionControl: false,
                });

                // Suppress missing sprite image warnings from OpenFreeMap style
                map.on('error', () => {});
                map.on('styleimagemissing', () => {});

                map.addControl(
                    new maplibregl.NavigationControl({ showCompass: false }),
                    'bottom-right',
                );

                const geolocate = new maplibregl.GeolocateControl({
                    positionOptions: { enableHighAccuracy: true },
                    trackUserLocation: true,
                    showUserHeading: false,
                });
                map.addControl(geolocate, 'bottom-right');
                geolocateRef.current = geolocate;

                // Resize markers on zoom — emoji circle at low zoom, pill with name at high zoom
                function updateMarkerSize() {
                    const zoom = map.getZoom();
                    const size =
                        zoom >= 14 ? '32px' : zoom >= 12 ? '26px' : '22px';
                    const iconScale =
                        zoom >= 14 ? '1' : zoom >= 12 ? '0.85' : '0.7';

                    document
                        .querySelectorAll('.spot-marker-pill')
                        .forEach((el) => {
                            const htmlEl = el as HTMLElement;
                            const iconEl = htmlEl.querySelector(
                                '.spot-emoji',
                            ) as HTMLElement;

                            // Only resize if not in hover-expanded state
                            if (parseFloat(htmlEl.style.maxWidth) <= 32) {
                                htmlEl.style.maxWidth = size;
                                htmlEl.style.height = size;
                            }

                            if (iconEl) {
                                iconEl.style.transform = `scale(${iconScale})`;
                            }
                        });
                }
                map.on('zoomend', updateMarkerSize);

                // Auto-trigger geolocation on map load
                map.on('load', () => {
                    geolocate.trigger();
                });

                // Tap-to-discover: click on empty map area
                map.on('click', (e) => {
                    const target = e.originalEvent.target as HTMLElement;

                    if (target.closest('.maplibregl-marker')) {
                        return;
                    }

                    onMapTapRef.current?.(e.lngLat.lat, e.lngLat.lng);
                });

                // Emit bounds on pan/zoom so parent can fetch viewport spots
                // Skip when movement is from spot selection (programmatic flyTo)
                let boundsTimer: ReturnType<typeof setTimeout> | null = null;
                let skipNextBoundsChange = false;
                (map as any)._skipBounds = (skip: boolean) => {
                    skipNextBoundsChange = skip;
                };

                map.on('moveend', () => {
                    if (skipNextBoundsChange) {
                        skipNextBoundsChange = false;

                        return;
                    }

                    if (boundsTimer) {
                        clearTimeout(boundsTimer);
                    }

                    boundsTimer = setTimeout(() => {
                        const bounds = map.getBounds();
                        onBoundsChangeRef.current?.({
                            sw_lat: bounds.getSouth(),
                            sw_lng: bounds.getWest(),
                            ne_lat: bounds.getNorth(),
                            ne_lng: bounds.getEast(),
                        });
                    }, 500); // debounce 500ms to reduce API calls
                });

                mapRef.current = map;

                // Automatically call map.resize() whenever the container
                // changes size (panel expand/collapse, mobile viewport height
                // shift). Without this, MapLibre's WebGL canvas keeps its old
                // pixel dimensions and tiles appear to slide or be offset.
                const ro = new ResizeObserver(() => map.resize());
                ro.observe(containerRef.current!);
                (map as any)._ro = ro;
            });

        return () => {
            cancelled = true;

            if (mapRef.current) {
                (mapRef.current as any)._ro?.disconnect();
                mapRef.current.remove();
                mapRef.current = null;
            }
        };
    }, []);

    // Update spot markers when spots change (hide in navigation mode)
    useEffect(() => {
        const map = mapRef.current;

        if (!map) {
            return;
        }

        markersRef.current.forEach((m) => m.remove());
        markersRef.current = [];

        if (hideMarkers) {
            return;
        } // navigation mode — no spot pins

        spots.forEach((spot) => {
            if (!spot.lat || !spot.lng) {
                return;
            }

            const cfg = CATEGORY_CONFIG[spot.category] ?? DEFAULT_CATEGORY;
            const isSelected = spot.id === selectedId;
            const bg = isSelected ? cfg.selectedBg : cfg.bg;
            const iconColor = isSelected ? '#ffffff' : cfg.color;
            const svgContent = makeIconSvg(cfg.icon, iconColor);

            const el = document.createElement('div');
            el.className = 'maplibregl-marker spot-marker-pill';
            el.style.cssText = `
                display: flex; align-items: center; justify-content: center; gap: 0px;
                height: 32px; border-radius: 20px; overflow: hidden;
                max-width: 32px; padding: 0 8px;
                font-size: 12px; font-weight: 600; color: ${cfg.color};
                background: ${bg};
                box-shadow: 0 1px 6px rgba(0,0,0,0.12), 0 0 0 1.5px ${isSelected ? cfg.selectedBg : cfg.color}33;
                cursor: pointer; white-space: nowrap;
                transition: max-width 0.22s cubic-bezier(.4,0,.2,1), background 0.2s, box-shadow 0.2s;
                ${isSelected ? `transform: scale(1.15); box-shadow: 0 2px 10px ${cfg.selectedBg}66, 0 0 0 2px ${cfg.selectedBg};` : ''}
            `;
            el.innerHTML = `
                <span class="spot-emoji" style="display:flex;align-items:center;flex-shrink:0;line-height:1">${svgContent}</span>
                <span class="spot-label" style="
                    max-width:0; overflow:hidden; opacity:0; white-space:nowrap;
                    font-size:11px; font-weight:600; text-overflow:ellipsis; color:${cfg.color};
                    transition: max-width 0.22s cubic-bezier(.4,0,.2,1), opacity 0.15s ease, margin-left 0.22s;
                    margin-left:0;
                ">${spot.name}</span>
            `;
            el.addEventListener('mouseenter', () => {
                el.style.maxWidth = '160px';
                const label = el.querySelector('.spot-label') as HTMLElement;

                if (label) {
                    label.style.maxWidth = '120px';
                    label.style.opacity = '1';
                    label.style.marginLeft = '5px';
                }
            });
            el.addEventListener('mouseleave', () => {
                el.style.maxWidth = '32px';
                const label = el.querySelector('.spot-label') as HTMLElement;

                if (label) {
                    label.style.maxWidth = '0';
                    label.style.opacity = '0';
                    label.style.marginLeft = '0';
                }
            });
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                onSelectSpot(spot.id);
            });

            const marker = new maplibregl.Marker({
                element: el,
                anchor: 'bottom',
            })
                .setLngLat([spot.lng, spot.lat])
                .addTo(map);

            markersRef.current.push(marker);
        });

        // Pan to selected spot — only on NEW selection, not on spot list refresh
        if (selectedId && selectedId !== lastFlyToId.current) {
            const selected = spots.find((s) => s.id === selectedId);

            if (selected) {
                lastFlyToId.current = selectedId;
                (map as any)._skipBounds?.(true);
                map.flyTo({
                    center: [selected.lng, selected.lat],
                    duration: 600,
                });
            }
        }

        if (!selectedId) {
            lastFlyToId.current = null;
        }
    }, [spots, selectedId, onSelectSpot, hideMarkers]);

    // Update personal place markers (hide in navigation mode)
    useEffect(() => {
        const map = mapRef.current;

        if (!map) {
            return;
        }

        placeMarkersRef.current.forEach((m) => m.remove());
        placeMarkersRef.current = [];

        if (hideMarkers) {
            return;
        }

        personalPlaces.forEach((place) => {
            if (!place.lat || !place.lng) {
                return;
            }

            const el = document.createElement('div');
            el.className = 'maplibregl-marker';
            el.style.cssText = `
                display: flex; align-items: center; gap: 4px;
                padding: 5px 10px; border-radius: 20px;
                font-size: 12px; font-weight: 600; color: #78600A;
                background: #FFF3C4;
                border: 2px solid #F5C518;
                box-shadow: 0 2px 8px rgba(245,197,24,0.35);
                cursor: pointer; white-space: nowrap;
            `;
            el.innerHTML = `<span style="font-size:14px">${place.emoji || '⭐'}</span><span style="max-width:80px;overflow:hidden;text-overflow:ellipsis">${place.name}</span>`;

            // Popup on click
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                // Remove any existing personal place popup
                document
                    .querySelectorAll('.personal-place-popup')
                    .forEach((p) => p.remove());

                const popup = document.createElement('div');
                popup.className = 'personal-place-popup';
                popup.style.cssText = `
                    position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%);
                    background: white; border: 1px solid #E2DFD6; border-radius: 10px;
                    padding: 10px 14px; min-width: 160px; max-width: 220px;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.12); z-index: 100;
                    font-family: system-ui, -apple-system, sans-serif;
                `;
                popup.innerHTML = `
                    <div style="font-size:13px;font-weight:600;color:#18170F;margin-bottom:2px">${place.emoji || '⭐'} ${place.name}</div>
                    ${place.address ? `<div style="font-size:11px;color:#6B6860">${place.address}</div>` : ''}
                `;
                el.style.position = 'relative';
                el.appendChild(popup);

                // Close popup on outside click
                const closePopup = (evt: MouseEvent) => {
                    if (!popup.contains(evt.target as Node)) {
                        popup.remove();
                        document.removeEventListener('click', closePopup);
                    }
                };
                setTimeout(
                    () => document.addEventListener('click', closePopup),
                    0,
                );
            });

            const marker = new maplibregl.Marker({
                element: el,
                anchor: 'bottom',
            })
                .setLngLat([place.lng, place.lat])
                .addTo(map);

            placeMarkersRef.current.push(marker);
        });
    }, [personalPlaces]);

    return <div ref={containerRef} className="size-full" />;
});
