import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';
import maplibregl from 'maplibre-gl';
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
};

const categoryEmoji: Record<string, string> = {
    cafe: '☕',
    coworking: '🏢',
    library: '📚',
    park: '🌳',
};

export type MapBounds = { sw_lat: number; sw_lng: number; ne_lat: number; ne_lng: number };

export const MapView = forwardRef<MapViewHandle, {
    spots: SpotPin[];
    personalPlaces?: PersonalPlace[];
    selectedId: number | null;
    onSelectSpot: (id: number) => void;
    onMapTap?: (lat: number, lng: number) => void;
    onBoundsChange?: (bounds: MapBounds) => void;
}>(function MapView({ spots, personalPlaces = [], selectedId, onSelectSpot, onMapTap, onBoundsChange }, ref) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const markersRef = useRef<maplibregl.Marker[]>([]);
    const placeMarkersRef = useRef<maplibregl.Marker[]>([]);
    const searchPinRef = useRef<maplibregl.Marker | null>(null);
    const userMarkerRef = useRef<maplibregl.Marker | null>(null);
    const geolocateRef = useRef<maplibregl.GeolocateControl | null>(null);

    // Keep callbacks fresh via refs to avoid recreating the map
    const onMapTapRef = useRef(onMapTap);
    const onBoundsChangeRef = useRef(onBoundsChange);
    useEffect(() => {
        onMapTapRef.current = onMapTap;
        onBoundsChangeRef.current = onBoundsChange;
    }, [onMapTap]);

    useImperativeHandle(ref, () => ({
        flyTo(lat: number, lng: number, zoom = 15) {
            mapRef.current?.flyTo({ center: [lng, lat], zoom, duration: 1200 });
        },
        addSearchPin(lat: number, lng: number, label: string) {
            searchPinRef.current?.remove();
            const map = mapRef.current;
            if (!map) return;

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

            searchPinRef.current = new maplibregl.Marker({ element: el, anchor: 'bottom' })
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
    }));

    // Initialize map
    useEffect(() => {
        if (!containerRef.current || mapRef.current) return;

        const map = new maplibregl.Map({
            container: containerRef.current,
            style: 'https://tiles.openfreemap.org/styles/bright',
            center: [6.9603, 50.9375],
            zoom: 13,
            attributionControl: false,
        });

        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'bottom-right');

        const geolocate = new maplibregl.GeolocateControl({
            positionOptions: { enableHighAccuracy: true },
            trackUserLocation: true,
            showUserHeading: true,
        });
        map.addControl(geolocate, 'bottom-right');
        geolocateRef.current = geolocate;

        // Resize markers on zoom — change font-size and padding, NOT transform
        function updateMarkerSize() {
            const zoom = map.getZoom();
            // Zoom 10: tiny (emoji only), 13: normal, 16+: large
            const showLabels = zoom >= 13;
            const fontSize = zoom >= 14 ? 12 : zoom >= 12 ? 11 : 10;
            const emojiSize = zoom >= 14 ? 14 : zoom >= 12 ? 12 : 10;
            const pad = zoom >= 14 ? '4px 8px' : zoom >= 12 ? '3px 6px' : '2px 4px';

            document.querySelectorAll('.spot-marker-pill').forEach((el) => {
                const htmlEl = el as HTMLElement;
                htmlEl.style.fontSize = fontSize + 'px';
                htmlEl.style.padding = pad;
                const emojiEl = htmlEl.querySelector('.spot-emoji') as HTMLElement;
                if (emojiEl) emojiEl.style.fontSize = emojiSize + 'px';
                const label = htmlEl.querySelector('.spot-label') as HTMLElement;
                if (label) label.style.display = showLabels ? '' : 'none';
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
            if (target.closest('.maplibregl-marker')) return;
            onMapTapRef.current?.(e.lngLat.lat, e.lngLat.lng);
        });

        // Emit bounds on pan/zoom so parent can fetch viewport spots
        let boundsTimer: ReturnType<typeof setTimeout> | null = null;
        map.on('moveend', () => {
            if (boundsTimer) clearTimeout(boundsTimer);
            boundsTimer = setTimeout(() => {
                const bounds = map.getBounds();
                onBoundsChangeRef.current?.({
                    sw_lat: bounds.getSouth(),
                    sw_lng: bounds.getWest(),
                    ne_lat: bounds.getNorth(),
                    ne_lng: bounds.getEast(),
                });
            }, 300); // debounce 300ms
        });

        mapRef.current = map;

        return () => {
            map.remove();
            mapRef.current = null;
        };
    }, []);

    // Update spot markers when spots change
    useEffect(() => {
        const map = mapRef.current;
        if (!map) return;

        markersRef.current.forEach((m) => m.remove());
        markersRef.current = [];

        spots.forEach((spot) => {
            if (!spot.lat || !spot.lng) return;

            const emoji = categoryEmoji[spot.category] || '📍';
            const isSelected = spot.id === selectedId;

            const el = document.createElement('div');
            el.className = 'maplibregl-marker spot-marker-pill';
            el.style.cssText = `
                display: flex; align-items: center; gap: 4px;
                padding: 4px 8px; border-radius: 20px;
                font-size: 12px; font-weight: 600; color: white;
                background: ${isSelected ? '#1A4CD4' : 'rgba(24,23,15,0.8)'};
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                cursor: pointer; white-space: nowrap;
                transition: background 0.2s;
                transform: ${isSelected ? 'scale(1.1)' : 'scale(1)'};
            `;
            el.innerHTML = `<span class="spot-emoji" style="font-size:14px">${emoji}</span><span class="spot-label" style="max-width:80px;overflow:hidden;text-overflow:ellipsis">${spot.name}</span>`;
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                onSelectSpot(spot.id);
            });

            const marker = new maplibregl.Marker({ element: el, anchor: 'bottom' })
                .setLngLat([spot.lng, spot.lat])
                .addTo(map);

            markersRef.current.push(marker);
        });

        // Fly to selected spot
        if (selectedId) {
            const selected = spots.find((s) => s.id === selectedId);
            if (selected) {
                map.flyTo({ center: [selected.lng, selected.lat], zoom: 15, duration: 800 });
            }
        }
    }, [spots, selectedId, onSelectSpot]);

    // Update personal place markers
    useEffect(() => {
        const map = mapRef.current;
        if (!map) return;

        placeMarkersRef.current.forEach((m) => m.remove());
        placeMarkersRef.current = [];

        personalPlaces.forEach((place) => {
            if (!place.lat || !place.lng) return;

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
                document.querySelectorAll('.personal-place-popup').forEach((p) => p.remove());

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
                setTimeout(() => document.addEventListener('click', closePopup), 0);
            });

            const marker = new maplibregl.Marker({ element: el, anchor: 'bottom' })
                .setLngLat([place.lng, place.lat])
                .addTo(map);

            placeMarkersRef.current.push(marker);
        });
    }, [personalPlaces]);

    return <div ref={containerRef} className="size-full" />;
});
