import type { GeoJSONSource, Map as MaplibreMap, Marker } from 'maplibre-gl';
import { useEffect, useRef, useState } from 'react';
import { lineLook, vehicleOf } from '@/components/travel/line-look';
import { lineIcons } from '@/components/ui/icon-paths';
import { loadBasemap } from '@/lib/basemap';
import type { Basemap } from '@/lib/basemap';
import { decodePolyline } from '@/lib/polyline';
import type { Journey } from '@/types/transit';

/* Get there's map (the mock's wall.js getThere() and get-there.js paint()):
   the venue, where you'd set off, and the chosen way between them, each
   ride in its line's colour and the walks dotted. A picture of the way,
   not a map to move around. */

type Point = { lat: number; lng: number };

const WALK = '#6b6457';

function wayOf(journey: Journey | null) {
    return {
        type: 'FeatureCollection' as const,
        features: (journey?.legs ?? [])
            .filter((leg) => leg.polyline)
            .map((leg) => {
                const walk = leg.mode === 'walk' || leg.mode === 'bike';

                return {
                    type: 'Feature' as const,
                    properties: {
                        walk: walk ? 1 : 0,
                        colour: walk
                            ? leg.mode === 'bike'
                                ? '#1d4d8f'
                                : WALK
                            : lineLook(leg.line, vehicleOf(leg.mode)).colour,
                    },
                    geometry: {
                        type: 'LineString' as const,
                        coordinates: decodePolyline(leg.polyline!),
                    },
                };
            }),
    };
}

export function GetThereMap({
    venue,
    venueName,
    start,
    journey,
}: {
    venue: Point;
    venueName: string;
    start: (Point & { name: string }) | null;
    journey: Journey | null;
}) {
    const box = useRef<HTMLDivElement>(null);
    const map = useRef<MaplibreMap | null>(null);
    const basemap = useRef<Basemap | null>(null);
    const startMark = useRef<Marker | null>(null);
    const [state, setState] = useState<'loading' | 'ready' | 'failed'>(
        'loading',
    );

    useEffect(() => {
        let gone = false;

        loadBasemap()
            .then((bm) => {
                if (gone || !box.current) {
                    return;
                }

                basemap.current = bm;
                const m = new bm.maplibregl.Map({
                    container: box.current,
                    style: bm.style(false),
                    center: [venue.lng, venue.lat],
                    zoom: 14.5,
                    interactive: false,
                    attributionControl: false,
                    fadeDuration: 0,
                });
                map.current = m;
                const pin = document.createElement('span');
                pin.className = 'event-way-pin is-venue';
                // The line set's pin, as the Icon component draws it (static
                // artwork, never user input).
                pin.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${lineIcons.pin}</svg>`;
                new bm.maplibregl.Marker({ element: pin, anchor: 'bottom' })
                    .setLngLat([venue.lng, venue.lat])
                    .addTo(m);
                m.once('load', () => !gone && setState('ready'));
                m.on('error', () => {
                    if (!gone && !m.loaded()) {
                        setState('failed');
                    }
                });
            })
            .catch(() => !gone && setState('failed'));

        return () => {
            gone = true;
            map.current?.remove();
            map.current = null;
        };
    }, [venue.lat, venue.lng]);

    // The way, the start's mark and the view follow the chosen option.
    useEffect(() => {
        const m = map.current;
        const bm = basemap.current;

        if (state !== 'ready' || !m || !bm) {
            return;
        }

        const data = wayOf(journey);
        const source = m.getSource('gt-way') as GeoJSONSource | undefined;

        if (source) {
            source.setData(data);
        } else {
            m.addSource('gt-way', { type: 'geojson', data });
            const round = {
                'line-cap': 'round',
                'line-join': 'round',
            } as const;
            m.addLayer({
                id: 'gt-way-casing',
                type: 'line',
                source: 'gt-way',
                filter: ['==', ['get', 'walk'], 0],
                layout: round,
                paint: { 'line-color': '#ffffff', 'line-width': 7 },
            });
            m.addLayer({
                id: 'gt-way-ride',
                type: 'line',
                source: 'gt-way',
                filter: ['==', ['get', 'walk'], 0],
                layout: round,
                paint: { 'line-color': ['get', 'colour'], 'line-width': 4.5 },
            });
            m.addLayer({
                id: 'gt-way-walk',
                type: 'line',
                source: 'gt-way',
                filter: ['==', ['get', 'walk'], 1],
                layout: round,
                paint: {
                    'line-color': ['get', 'colour'],
                    'line-width': 3,
                    'line-dasharray': [0.1, 1.8],
                },
            });
        }

        startMark.current?.remove();
        startMark.current = null;
        const points = data.features.flatMap((f) => f.geometry.coordinates);

        if (start) {
            const dot = document.createElement('span');
            dot.className = 'event-way-pin is-start';
            startMark.current = new bm.maplibregl.Marker({ element: dot })
                .setLngLat([start.lng, start.lat])
                .addTo(m);
            points.push([start.lng, start.lat]);
        }

        points.push([venue.lng, venue.lat]);

        if (points.length > 1) {
            const lngs = points.map((p) => p[0]);
            const lats = points.map((p) => p[1]);
            m.fitBounds(
                [
                    [Math.min(...lngs), Math.min(...lats)],
                    [Math.max(...lngs), Math.max(...lats)],
                ],
                { padding: 34, maxZoom: 15, duration: 0 },
            );
        } else {
            m.jumpTo({ center: [venue.lng, venue.lat], zoom: 14.5 });
        }
    }, [state, journey, start, venue.lat, venue.lng]);

    return (
        <div
            className={`event-way-map${state === 'ready' ? 'is-ready' : ''}${state === 'failed' ? 'is-failed' : ''}`}
            role="img"
            aria-label={
                start
                    ? `Map of ${venueName} and ${start.name}`
                    : `Map of ${venueName}`
            }
        >
            <div ref={box} className="event-way-map-canvas" />
            <span className="event-way-map-note">
                {state === 'failed'
                    ? 'The map couldn’t load right now.'
                    : 'Loading the map'}
            </span>
            <small className="event-way-map-credit">
                OpenFreeMap © OpenMapTiles © OpenStreetMap
            </small>
        </div>
    );
}
