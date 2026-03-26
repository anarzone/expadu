import { useEffect, useRef } from 'react';
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';

type SpotPin = {
    id: number;
    name: string;
    category: string;
    lat: number;
    lng: number;
};

const categoryEmoji: Record<string, string> = {
    cafe: '☕',
    coworking: '🏢',
    library: '📚',
    park: '🌳',
};

export function MapView({
    spots,
    selectedId,
    onSelectSpot,
}: {
    spots: SpotPin[];
    selectedId: number | null;
    onSelectSpot: (id: number) => void;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const markersRef = useRef<maplibregl.Marker[]>([]);

    // Initialize map
    useEffect(() => {
        if (!containerRef.current || mapRef.current) return;

        const map = new maplibregl.Map({
            container: containerRef.current,
            style: 'https://tiles.openfreemap.org/styles/liberty',
            center: [6.9603, 50.9375], // Cologne
            zoom: 13,
            attributionControl: false,
        });

        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'bottom-right');
        map.addControl(
            new maplibregl.GeolocateControl({
                positionOptions: { enableHighAccuracy: true },
                trackUserLocation: false,
            }),
            'bottom-right',
        );

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

        // Remove old markers
        markersRef.current.forEach((m) => m.remove());
        markersRef.current = [];

        spots.forEach((spot) => {
            if (!spot.lat || !spot.lng) return;

            const emoji = categoryEmoji[spot.category] || '📍';
            const isSelected = spot.id === selectedId;

            // Create marker element
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
    }, [spots, selectedId, onSelectSpot]);

    return (
        <div ref={containerRef} className="size-full" />
    );
}
