import { useEffect, useRef } from 'react';
import { decodePolyline } from '@/utils/polyline';
import 'maplibre-gl/dist/maplibre-gl.css';

type RoutePreviewMapProps = {
    origin: { lat: number; lng: number };
    destination: { lat: number; lng: number };
    mode: 'bike' | 'transit' | 'walk' | 'drive';
    /** Valhalla encoded polyline (precision 6). Falls back to straight line if not provided. */
    geometry?: string | null;
};

export function RoutePreviewMap({
    origin,
    destination,
    mode,
    geometry,
}: RoutePreviewMapProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<unknown>(null);

    useEffect(() => {
        if (typeof window === 'undefined') {
return;
}

        if (!containerRef.current) {
return;
}

        let cancelled = false;

        import('maplibre-gl').then((maplibregl) => {
            if (cancelled || !containerRef.current) {
return;
}

            if (mapRef.current) {
                (
                    mapRef.current as InstanceType<typeof maplibregl.Map>
                ).remove();
                mapRef.current = null;
            }

            // Fetch style first to patch missing projection field (MapLibre v5 bug with OpenFreeMap)
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
                        if (cancelled) {
return;
}

                        // Build coordinates: Valhalla polyline or straight line fallback
                        const coordinates: [number, number][] = geometry
                            ? decodePolyline(geometry, 6)
                            : [
                                  [origin.lng, origin.lat],
                                  [destination.lng, destination.lat],
                              ];

                        // Route line
                        map.addSource('route', {
                            type: 'geojson',
                            data: {
                                type: 'Feature',
                                properties: {},
                                geometry: { type: 'LineString', coordinates },
                            },
                        });

                        // Line style based on mode
                        const isWalk = mode === 'walk';
                        const color = mode === 'drive' ? '#6B6860' : '#1A4CD4';

                        map.addLayer({
                            id: 'route-line-bg',
                            type: 'line',
                            source: 'route',
                            layout: {
                                'line-join': 'round',
                                'line-cap': 'round',
                            },
                            paint: {
                                'line-color': color,
                                'line-width': 6,
                                'line-opacity': 0.2,
                            },
                        });

                        map.addLayer({
                            id: 'route-line',
                            type: 'line',
                            source: 'route',
                            layout: {
                                'line-join': 'round',
                                'line-cap': 'round',
                            },
                            paint: {
                                'line-color': color,
                                'line-width': 4,
                                ...(isWalk ? { 'line-dasharray': [2, 4] } : {}),
                            },
                        });

                        // Origin marker (green)
                        const originEl = document.createElement('div');
                        originEl.style.cssText =
                            'display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#0A7C52;box-shadow:0 2px 8px rgba(0,0,0,0.25);font-size:16px;line-height:1;';
                        originEl.textContent = '\uD83D\uDCCD';
                        new maplibregl.Marker({
                            element: originEl,
                            anchor: 'center',
                        })
                            .setLngLat([origin.lng, origin.lat])
                            .addTo(map);

                        // Destination marker (red)
                        const destEl = document.createElement('div');
                        destEl.style.cssText =
                            'display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#C4271A;box-shadow:0 2px 8px rgba(0,0,0,0.25);font-size:16px;line-height:1;';
                        destEl.textContent = '\uD83C\uDFC1';
                        new maplibregl.Marker({
                            element: destEl,
                            anchor: 'center',
                        })
                            .setLngLat([destination.lng, destination.lat])
                            .addTo(map);

                        // Fit bounds to the route geometry
                        const bounds = new maplibregl.LngLatBounds();

                        for (const coord of coordinates) {
                            bounds.extend(coord as [number, number]);
                        }

                        map.fitBounds(bounds, {
                            padding: {
                                top: 40,
                                bottom: 40,
                                left: 40,
                                right: 40,
                            },
                            maxZoom: 15,
                            duration: 500,
                        });
                    });
                });
        });

        return () => {
            cancelled = true;

            if (mapRef.current) {
                (mapRef.current as any).remove();
                mapRef.current = null;
            }
        };
    }, [
        origin.lat,
        origin.lng,
        destination.lat,
        destination.lng,
        mode,
        geometry,
    ]);

    return (
        <div
            ref={containerRef}
            style={{
                width: '100%',
                height: 220,
                borderRadius: 14,
                border: '1px solid #E2DFD6',
                overflow: 'hidden',
            }}
        />
    );
}
