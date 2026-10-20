import type { Map as MapLibreMap } from 'maplibre-gl';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { StopArea } from '@/components/departures/timetable-board';
import { loadBasemap } from '@/lib/basemap';

/** Provider stop-area coordinates are context, never a claimed boarding platform. */
export function StationMap({ stops }: { stops: StopArea[] }) {
    const identity = JSON.stringify(stops);
    const areas = useMemo(() => JSON.parse(identity) as StopArea[], [identity]);
    const container = useRef<HTMLDivElement>(null);
    const mapRef = useRef<MapLibreMap | null>(null);
    const [status, setStatus] = useState<'loading' | 'ready' | 'error'>(
        'loading',
    );

    useEffect(() => {
        let cancelled = false;
        let resize: ResizeObserver | undefined;
        let theme: MutationObserver | undefined;
        loadBasemap()
            .then(({ maplibregl, style }) => {
                if (cancelled || !container.current || !areas.length) {
                    return;
                }

                const first = areas[0];
                const dark = () =>
                    document.documentElement.classList.contains('dark');
                const map = new maplibregl.Map({
                    container: container.current,
                    style: style(dark()),
                    center: [first.lng, first.lat],
                    zoom: 15,
                    attributionControl: { compact: true },
                });
                mapRef.current = map;
                map.addControl(
                    new maplibregl.NavigationControl({ showCompass: false }),
                    'top-right',
                );
                let loaded = false;
                map.on('error', () => {
                    if (!cancelled && !loaded) {
                        setStatus('error');
                    }
                });
                map.on('load', () => {
                    if (cancelled) {
                        return;
                    }

                    loaded = true;
                    const bounds = new maplibregl.LngLatBounds();
                    areas.forEach((stop, index) => {
                        const marker = document.createElement('button');
                        marker.type = 'button';
                        marker.textContent = String(index + 1);
                        marker.className =
                            'grid size-11 place-items-center rounded-full border-2 border-white bg-cyan-h text-sm font-bold text-white shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan';
                        marker.setAttribute(
                            'aria-label',
                            `Show stop area ${stop.name}`,
                        );
                        const label = document.createElement('div');
                        label.textContent = `${stop.name} · stop area`;
                        new maplibregl.Marker({ element: marker })
                            .setLngLat([stop.lng, stop.lat])
                            .setPopup(
                                new maplibregl.Popup({
                                    offset: 24,
                                }).setDOMContent(label),
                            )
                            .addTo(map);
                        bounds.extend([stop.lng, stop.lat]);
                    });
                    map.fitBounds(bounds, {
                        padding: 64,
                        maxZoom: 15,
                        duration: 0,
                    });
                    setStatus('ready');
                });
                resize = new ResizeObserver(() => map.resize());
                resize.observe(container.current);
                theme = new MutationObserver(() => map.setStyle(style(dark())));
                theme.observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['class'],
                });
            })
            .catch(() => {
                if (!cancelled) {
                    setStatus('error');
                }
            });

        return () => {
            cancelled = true;
            resize?.disconnect();
            theme?.disconnect();
            mapRef.current?.remove();
            mapRef.current = null;
        };
    }, [areas]);

    return (
        <section
            aria-label="Stop area map"
            className="overflow-hidden rounded-2xl border border-border bg-card"
        >
            <div className="relative h-64 md:h-80 [&_.maplibregl-ctrl-group_button]:size-11">
                <div ref={container} className="size-full" />
                {status !== 'ready' && (
                    <div
                        role="status"
                        className="absolute inset-0 grid place-items-center bg-secondary p-5 text-center text-sm text-muted-foreground"
                    >
                        {status === 'loading'
                            ? 'Loading stop map…'
                            : 'The map couldn’t load. Your stops and departures are still available below.'}
                    </div>
                )}
            </div>
            <div className="p-3">
                <h3 className="text-sm font-semibold">Stop areas</h3>
                <p className="mt-1 text-xs text-muted-foreground">
                    Check the signs for your boarding point. These markers do
                    not identify platforms or live vehicles.
                </p>
                <ol className="mt-2 space-y-1">
                    {stops.map((stop, index) => (
                        <li
                            key={
                                stop.id ??
                                `${stop.name}:${stop.lat}:${stop.lng}`
                            }
                            className="flex items-center gap-2 text-sm"
                        >
                            <span className="grid size-6 shrink-0 place-items-center rounded-full bg-cyan/15 text-xs font-semibold text-cyan-h">
                                {index + 1}
                            </span>
                            {stop.name}
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}
