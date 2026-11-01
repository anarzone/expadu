import type { Map as MaplibreMap, Marker } from 'maplibre-gl';
import { useEffect, useRef, useState } from 'react';
import { loadBasemap } from '@/lib/basemap';
import type { Basemap } from '@/lib/basemap';
import { PlaceIcon } from './place-icon';
import { rowKey } from './place-model';
import type { AroundPlace } from './place-model';

/* Around here's map (the mock's around-here/app.js loadMap and
   drawMarkers, direction A): the point you're around, and each place in
   the list as a pin with its row's number in the kind's colour, at full
   strength. A tap on a pin points out its row; hovering a row rings its
   pin, and the open one is larger. */

type Point = { name: string; lat: number; lng: number };

export function AroundMap({
    point,
    places,
    selected,
    hovered,
    onSelect,
    flyTo,
}: {
    point: Point;
    places: AroundPlace[];
    /** Rows' keys (rowKey): a big park's parts share its id. */
    selected: string | null;
    hovered: string | null;
    onSelect: (key: string) => void;
    /** A place to bring to the middle (one just opened). */
    flyTo: { key: string; at: number } | null;
}) {
    const box = useRef<HTMLDivElement>(null);
    const map = useRef<MaplibreMap | null>(null);
    const basemap = useRef<Basemap | null>(null);
    const marks = useRef<
        { key: string; element: HTMLElement; marker: Marker }[]
    >([]);
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
                    center: [point.lng, point.lat],
                    zoom: 15,
                    attributionControl: { compact: true },
                    dragRotate: false,
                    pitchWithRotate: false,
                });
                map.current = m;
                m.on('error', () => {
                    if (!gone && !m.loaded()) {
                        setState('failed');
                    }
                });
                m.once('load', () => {
                    if (gone) {
                        return;
                    }

                    // The credit stays folded; it's also under the list.
                    box.current
                        ?.querySelector('.maplibregl-ctrl-attrib')
                        ?.classList.remove('maplibregl-compact-show');
                    const here = document.createElement('span');
                    here.className = 'around-here-marker';
                    here.title = point.name;
                    new bm.maplibregl.Marker({ element: here })
                        .setLngLat([point.lng, point.lat])
                        .addTo(m);
                    setState('ready');
                });
            })
            .catch(() => !gone && setState('failed'));

        return () => {
            gone = true;
            map.current?.remove();
            map.current = null;
        };
    }, [point.lat, point.lng, point.name]);

    // The places, framed with the point.
    useEffect(() => {
        const m = map.current;
        const bm = basemap.current;

        if (state !== 'ready' || !m || !bm) {
            return;
        }

        marks.current.forEach(({ marker }) => marker.remove());
        marks.current = places.map((place, index) => {
            const element = document.createElement('button');
            element.type = 'button';
            element.className = 'around-marker';
            element.dataset.kind = place.icon;
            element.setAttribute('aria-label', `${index + 1}. ${place.name}`);
            // The row's number, in the kind's colour.
            element.textContent = String(index + 1);
            element.addEventListener('click', (event) => {
                event.stopPropagation();
                onSelect(rowKey(place));
            });
            const marker = new bm.maplibregl.Marker({ element })
                .setLngLat([place.lng, place.lat])
                .addTo(m);

            return { key: rowKey(place), element, marker };
        });
        const bounds = new bm.maplibregl.LngLatBounds();
        bounds.extend([point.lng, point.lat]);
        places.forEach((place) => bounds.extend([place.lng, place.lat]));
        m.fitBounds(bounds, { padding: 56, maxZoom: 16.5, duration: 0 });
        // onSelect is the page's setter; the markers follow the list.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [state, places, point.lat, point.lng]);

    useEffect(() => {
        marks.current.forEach(({ key, element }) => {
            element.classList.toggle('is-selected', key === selected);
            element.classList.toggle('is-hovered', key === hovered);
        });
    }, [selected, hovered, state, places]);

    useEffect(() => {
        const place = flyTo && places.find((p) => rowKey(p) === flyTo.key);

        if (!place || !map.current) {
            return;
        }

        map.current.easeTo({
            center: [place.lng, place.lat],
            duration: matchMedia('(prefers-reduced-motion: reduce)').matches
                ? 0
                : 400,
        });
    }, [flyTo, places]);

    return (
        <div className="around-map" aria-label="Map of these places">
            <div ref={box} className="around-map-canvas" />
            {state === 'failed' ? (
                <p className="around-map-fallback">
                    <PlaceIcon name="pin" />
                    <span>Map unavailable. The list still works.</span>
                </p>
            ) : null}
        </div>
    );
}
