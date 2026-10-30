import { useEffect, useState } from 'react';
import type { LinesMapData } from './lines-map-layout';
import type { Station } from './types';

/**
 * The station's lines map, read only once it's wanted (the Map switch
 * pointed at, focused or tapped), not with every board: undefined until
 * then and while it loads, null when the timetable has none.
 */
export function useLinesMap(
    station: Pick<Station, 'name' | 'lat' | 'lng'>,
    wanted: boolean,
): LinesMapData | null | undefined {
    const [map, setMap] = useState<{
        key: string;
        data: LinesMapData | null;
    } | null>(null);
    const key =
        station.lat !== null && station.lng !== null
            ? `${station.name}|${station.lat}|${station.lng}`
            : '';

    useEffect(() => {
        if (
            !wanted ||
            !key ||
            map?.key === key ||
            station.lat === null ||
            station.lng === null
        ) {
            return;
        }

        const controller = new AbortController();
        const query = new URLSearchParams({
            stop: station.name,
            lat: String(station.lat),
            lng: String(station.lng),
        });

        fetch(`/api/travel/lines-map?${query}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((body: { map: LinesMapData | null } | null) =>
                setMap({ key, data: body?.map ?? null }),
            )
            .catch(() => {
                // Offline or aborted: no map this time.
            });

        return () => controller.abort();
    }, [wanted, key, map?.key, station.name, station.lat, station.lng]);

    if (!key || map?.key !== key) {
        return undefined;
    }

    const data = map.data;

    return data && (data.lines.length || data.buses.length) ? data : null;
}
