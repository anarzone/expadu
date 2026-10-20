import { travelDestinationParams } from '@/components/departures/travel-request';
import { timetable } from '@/routes';
import type { DayItem } from '@/types/day';

/**
 * Travel's Plan a trip to one of today's plans, there by its start ("For
 * ‹plan›"), as Travel's own links ask for it. Null when the plan has no
 * place a route can reach.
 */
export function routeTo(item: DayItem): string | null {
    const place = item.location;

    if (
        !place?.routable ||
        !place.label ||
        place.lat == null ||
        place.lng == null
    ) {
        return null;
    }

    return `${timetable.url()}?${new URLSearchParams(
        travelDestinationParams({
            name: place.label,
            lat: place.lat,
            lng: place.lng,
            arriveBy: item.start_at ?? undefined,
            backLabel: item.title,
        }),
    )}`;
}
