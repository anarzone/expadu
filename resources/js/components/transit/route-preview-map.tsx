import { useEffect, useRef } from 'react';
import 'maplibre-gl/dist/maplibre-gl.css';

type RoutePreviewMapProps = {
    origin: { lat: number; lng: number };
    destination: { lat: number; lng: number };
    mode: 'bike' | 'transit' | 'walk';
};

export function RoutePreviewMap({ origin, destination, mode }: RoutePreviewMapProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<unknown>(null);

    useEffect(() => {
        if (typeof window === 'undefined') return;
        if (!containerRef.current) return;

        // Dynamic import to avoid SSR issues
        let cancelled = false;

        import('maplibre-gl').then((maplibregl) => {
            if (cancelled || !containerRef.current) return;

            // Clean up previous map
            if (mapRef.current) {
                (mapRef.current as InstanceType<typeof maplibregl.Map>).remove();
                mapRef.current = null;
            }

            const map = new maplibregl.Map({
                container: containerRef.current,
                style: 'https://tiles.openfreemap.org/styles/liberty',
                center: [
                    (origin.lng + destination.lng) / 2,
                    (origin.lat + destination.lat) / 2,
                ],
                zoom: 13,
                attributionControl: false,
                interactive: true,
            });

            mapRef.current = map;

            map.on('load', () => {
                if (cancelled) return;

                // Add route line
                map.addSource('route', {
                    type: 'geojson',
                    data: {
                        type: 'Feature',
                        properties: {},
                        geometry: {
                            type: 'LineString',
                            coordinates: [
                                [origin.lng, origin.lat],
                                [destination.lng, destination.lat],
                            ],
                        },
                    },
                });

                const dashArray = mode === 'walk' ? [2, 4] : [];

                map.addLayer({
                    id: 'route-line',
                    type: 'line',
                    source: 'route',
                    layout: {
                        'line-join': 'round',
                        'line-cap': 'round',
                    },
                    paint: {
                        'line-color': '#1A4CD4',
                        'line-width': 4,
                        ...(dashArray.length > 0 ? { 'line-dasharray': dashArray } : {}),
                    },
                });

                // Add origin marker
                const originEl = document.createElement('div');
                originEl.style.cssText = `
                    display: flex; align-items: center; justify-content: center;
                    width: 32px; height: 32px; border-radius: 50%;
                    background: #0A7C52; box-shadow: 0 2px 8px rgba(0,0,0,0.25);
                    font-size: 16px; line-height: 1;
                `;
                originEl.textContent = '\uD83D\uDCCD';

                new maplibregl.Marker({ element: originEl, anchor: 'center' })
                    .setLngLat([origin.lng, origin.lat])
                    .addTo(map);

                // Add destination marker
                const destEl = document.createElement('div');
                destEl.style.cssText = `
                    display: flex; align-items: center; justify-content: center;
                    width: 32px; height: 32px; border-radius: 50%;
                    background: #C4271A; box-shadow: 0 2px 8px rgba(0,0,0,0.25);
                    font-size: 16px; line-height: 1;
                `;
                destEl.textContent = '\uD83C\uDFC1';

                new maplibregl.Marker({ element: destEl, anchor: 'center' })
                    .setLngLat([destination.lng, destination.lat])
                    .addTo(map);

                // Fit bounds
                const bounds = new maplibregl.LngLatBounds(
                    [Math.min(origin.lng, destination.lng), Math.min(origin.lat, destination.lat)],
                    [Math.max(origin.lng, destination.lng), Math.max(origin.lat, destination.lat)],
                );

                map.fitBounds(bounds, {
                    padding: { top: 40, bottom: 40, left: 40, right: 40 },
                    maxZoom: 15,
                    duration: 500,
                });
            });
        });

        return () => {
            cancelled = true;
            if (mapRef.current) {
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                (mapRef.current as any).remove();
                mapRef.current = null;
            }
        };
    }, [origin.lat, origin.lng, destination.lat, destination.lng, mode]);

    return (
        <div
            ref={containerRef}
            style={{
                width: '100%',
                height: 200,
                borderRadius: 12,
                border: '1px solid #E2DFD6',
                overflow: 'hidden',
            }}
        />
    );
}
