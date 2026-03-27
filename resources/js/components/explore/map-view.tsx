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

export const MapView = forwardRef<MapViewHandle, {
    spots: SpotPin[];
    selectedId: number | null;
    onSelectSpot: (id: number) => void;
}>(function MapView({ spots, selectedId, onSelectSpot }, ref) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const markersRef = useRef<maplibregl.Marker[]>([]);
    const searchPinRef = useRef<maplibregl.Marker | null>(null);
    const userMarkerRef = useRef<maplibregl.Marker | null>(null);
    const geolocateRef = useRef<maplibregl.GeolocateControl | null>(null);

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

        // Auto-trigger geolocation on map load
        map.on('load', () => {
            geolocate.trigger();
        });

        mapRef.current = map;

        return () => {
            map.remove();
            mapRef.current = null;
        };
    }, []);

    // Update markers when spots change
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
            el.style.cssText = `
                display: flex; align-items: center; gap: 4px;
                padding: 4px 8px; border-radius: 20px;
                font-size: 12px; font-weight: 600; color: white;
                background: ${isSelected ? '#1A4CD4' : 'rgba(24,23,15,0.8)'};
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                cursor: pointer; white-space: nowrap;
                transition: background 0.2s, transform 0.2s;
                transform: ${isSelected ? 'scale(1.1)' : 'scale(1)'};
            `;
            el.innerHTML = `<span style="font-size:14px">${emoji}</span><span style="max-width:80px;overflow:hidden;text-overflow:ellipsis">${spot.name}</span>`;
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

    return <div ref={containerRef} className="size-full" />;
});
