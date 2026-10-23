import type { Map as MaplibreMap } from 'maplibre-gl';
import { useEffect, useState } from 'react';
import { loadBasemap } from '@/lib/basemap';

/*
 * A place card's map preview (the B cards, owner 23 Oct 2026): the place's own
 * spot on the app's OpenFreeMap map, about z17. One hidden map draws them all,
 * one after another, and each becomes an image the card keeps, so eight cards
 * never hold eight WebGL maps. It starts once the answer is on screen and never
 * holds it up; until a preview is ready (or offline) the card shows its kind's
 * tint with the pin.
 */

const WIDTH = 344;
const HEIGHT = 208;
const ZOOM = 17;
/** A preview that takes longer than this is left as the tinted band. */
const PATIENCE = 8000;

const done = new Map<string, string>();
const waiting = new Map<string, Promise<string>>();
let map: Promise<MaplibreMap> | null = null;
let queue: Promise<unknown> = Promise.resolve();

function hiddenMap(): Promise<MaplibreMap> {
    map ??= loadBasemap().then(
        (basemap) =>
            new Promise<MaplibreMap>((resolve, reject) => {
                const box = document.createElement('div');
                box.setAttribute('aria-hidden', 'true');
                Object.assign(box.style, {
                    position: 'fixed',
                    left: '-10000px',
                    top: '0',
                    width: `${WIDTH}px`,
                    height: `${HEIGHT}px`,
                    opacity: '0',
                    pointerEvents: 'none',
                });
                document.body.append(box);
                const created = new basemap.maplibregl.Map({
                    container: box,
                    style: basemap.style(false),
                    center: [6.957, 50.938],
                    zoom: ZOOM,
                    interactive: false,
                    attributionControl: false,
                    canvasContextAttributes: { preserveDrawingBuffer: true },
                });
                created.once('load', () => resolve(created));
                created.once('error', () => reject(new Error('map')));
            }),
    );

    return map;
}

/** The place's spot, drawn once and kept for as long as the page is open. */
function draw(key: string, lat: number, lng: number): Promise<string> {
    const ready = done.get(key);

    if (ready) {
        return Promise.resolve(ready);
    }

    const pending = waiting.get(key);

    if (pending) {
        return pending;
    }

    const next = queue
        .catch(() => undefined)
        .then(async () => {
            const m = await hiddenMap();
            m.jumpTo({ center: [lng, lat], zoom: ZOOM });

            await new Promise<void>((resolve, reject) => {
                const timer = window.setTimeout(
                    () => reject(new Error('slow')),
                    PATIENCE,
                );
                m.once('idle', () => {
                    window.clearTimeout(timer);
                    resolve();
                });
                m.triggerRepaint();
            });

            const image = m.getCanvas().toDataURL('image/webp', 0.82);
            done.set(key, image);

            return image;
        })
        .finally(() => waiting.delete(key));
    queue = next;
    waiting.set(key, next);

    return next;
}

/**
 * A card's preview image, once it's near the screen; null until then, and when
 * the place has no point or the map can't be drawn (the tinted band stays).
 */
export function usePlacePreview(
    target: { current: Element | null },
    key: string,
    point: { lat: number; lng: number } | null,
): string | null {
    const [image, setImage] = useState<string | null>(
        () => done.get(key) ?? null,
    );
    const lat = point?.lat;
    const lng = point?.lng;

    useEffect(() => {
        const element = target.current;

        if (lat === undefined || lng === undefined || !element || image) {
            return;
        }

        let live = true;
        const observer = new IntersectionObserver(
            (entries) => {
                if (!entries.some((entry) => entry.isIntersecting)) {
                    return;
                }

                observer.disconnect();
                // After the answer has painted: the map waits for an idle moment.
                const start = () =>
                    void draw(key, lat, lng)
                        .then((url) => live && setImage(url))
                        .catch(() => undefined);

                if (typeof window.requestIdleCallback === 'function') {
                    window.requestIdleCallback(start, { timeout: 1500 });
                } else {
                    setTimeout(start, 200);
                }
            },
            { rootMargin: '200px' },
        );
        observer.observe(element);

        return () => {
            live = false;
            observer.disconnect();
        };
    }, [target, key, lat, lng, image]);

    return image;
}
